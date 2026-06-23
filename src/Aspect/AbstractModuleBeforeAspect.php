<?php

namespace Dleno\CommonCore\Aspect;

use Hyperf\Di\Aop\AbstractAspect;
use Hyperf\Di\Aop\ProceedingJoinPoint;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Context\Context;
use Dleno\CommonCore\Conf\GlobalConf;
use Dleno\CommonCore\Conf\RcodeConf;
use Dleno\CommonCore\Exception\Http\HttpException;
use Dleno\CommonCore\Tools\Check\CheckVal;
use Dleno\CommonCore\Tools\Crypt\OpenSslCrypt;
use Dleno\CommonCore\Tools\ApiServer;
use Dleno\CommonCore\Tools\Logger;
use Dleno\CommonCore\Tools\Server;
use Psr\Http\Message\ServerRequestInterface;

use function Hyperf\Config\config;

/**
 * 模块前置切面基类（框架通用）：封装与具体项目无关的「签名校验 + 数据解密 + 前置流程」。
 *
 * 该基类不耦合任何 App\ 下的类；项目侧通过实现下列抽象钩子接入：
 *   - isMatch()         ：当前请求是否由本切面处理（如：后台模块 / 非后台模块）
 *   - checkAuth()       ：登录态校验（后台登录 / API 端 token，按项目实现，二者通常不同）
 *   - signPrefix()/signKey()/signExpire() ：签名前缀 / 密钥 / 时间偏移（项目侧密钥与配置）
 *
 * 路由白名单值 / AES 密钥统一走 common-core 的 ApiServer，无需项目侧再提供。
 *
 * 注意：本类为抽象类，不加 #[Aspect]，仅供项目侧子类继承后再标注 #[Aspect]。
 */
abstract class AbstractModuleBeforeAspect extends AbstractAspect
{
    // 要切入的类，可以多个，亦可通过 :: 标识到具体的某个方法，通过 * 可以模糊匹配
    public array $classes = [];

    // 要切入的注解，具体切入的还是使用了这些注解的类，仅可切入类注解和类方法注解
    public array $annotations = [
        \Hyperf\HttpServer\Annotation\AutoController::class,
        \Hyperf\HttpServer\Annotation\Controller::class,
    ];

    /**
     * 当前请求是否由本切面处理（子类按模块判定）
     */
    abstract protected function isMatch(): bool;

    /**
     * 检查登录状态（子类各自实现：后台登录 / API 端 token，逻辑通常不同）
     * @param $whiteVal
     */
    abstract protected function checkAuth($whiteVal);

    /**
     * 签名前缀
     */
    abstract protected function signPrefix(): string;

    /**
     * 签名密钥
     */
    abstract protected function signKey(): string;

    /**
     * 签名允许的时间偏移量（秒）
     */
    abstract protected function signExpire(): int;

    public function process(ProceedingJoinPoint $proceedingJoinPoint)
    {
        // 在调用前进行某些处理
        if ($this->isMatch()) {//核心处理及校验
            //获取当前路由白名单值
            $whiteVal = ApiServer::getRouteVal();
            //验证接口签名
            $this->checkSign($whiteVal);
            //接口数据解密
            $this->dataDecryption($whiteVal);
            //验证登录
            $this->checkAuth($whiteVal);
        }
        $result = $proceedingJoinPoint->process();
        // 在调用后进行某些处理

        return $result;
    }

    /**
     * 接口数据解密
     */
    protected function dataDecryption($whiteVal)
    {
        //关闭加密功能
        if (!config('app.api_data_crypt')) {
            return;
        }
        //白名单
        if (CheckVal::checkInStatus(GlobalConf::WHITE_TYPE_ENCRYPT, $whiteVal)) {
            return;
        }
        $request     = get_inject_obj(RequestInterface::class);
        $postRawBody = $request->getBody()
                               ->getContents();
        //加密数据处理
        $isJson = CheckVal::isJson($postRawBody);
        if (!$isJson && !empty($postRawBody)) {
            //获取当前请求的aes key
            $aesKey = ApiServer::getClientAesKey();
            if (empty($aesKey)) {
                throw new HttpException('Bad Request', RcodeConf::ERROR_BAD);
            }
            //aes解密$rawBody
            $postRawBody = OpenSslCrypt::decrypt($postRawBody, $aesKey);
        }
        $post = $postRawBody ? json_to_array($postRawBody) : [];
        //!(非正式环境debug||白名单)
        if (!((!Server::isProd() && get_header_val('Client-Debug')) ||
              CheckVal::checkInStatus(GlobalConf::WHITE_TYPE_ENCRYPT, $whiteVal))) {
            //是明文||不是明文但不能解密（空忽略）
            if ($isJson || (!$isJson && !empty($postRawBody) && empty($post))) {
                throw new HttpException('Bad Request', RcodeConf::ERROR_BAD);
            }
        }
        //将解密后的参数赋值给request parsedBody
        $request = $request->withParsedBody($post);
        Context::set(ServerRequestInterface::class, $request);
    }

    /**
     * 验证接口签名
     */
    protected function checkSign($whiteVal)
    {
        //开关检查
        if (!config('app.api_check_sign')) {
            return;
        }
        //非正式环境debug
        if (!Server::isProd() && get_header_val('Client-Debug')) {
            return;
        }
        //白名单
        if (CheckVal::checkInStatus(GlobalConf::WHITE_TYPE_SIGN, $whiteVal)) {
            return;
        }
        //检查时间有效期
        $now    = time();
        $expire = $this->signExpire();
        $time = get_header_val('Client-Timestamp', 0);
        $time = strlen($time) > 10 ? substr($time, 0, 10) : $time;
        if ($time < $now - $expire || $time > $now + $expire) {
            Logger::systemLog('SIGN')
                  ->debug(
                      sprintf('Message::%s', "鉴权时间戳无效：[{$now}|{$time}]")
                  );
            throw new HttpException('Error Sign', RcodeConf::ERROR_SIGN);
        }

        //fail-closed:开启了签名校验却没配 signKey(如 SIGN_KEY 未注入,signKey() 返空)→
        //空密钥会让签名串少了密钥段、形同虚设/可被伪造;按服务端配置错误(500)直接拒绝,绝不用空密钥继续校验。
        $signKey = $this->signKey();
        if ($signKey === '') {
            Logger::systemLog('SIGN')
                  ->error('Message::签名校验已开启但 signKey 未配置(空),请检查 SIGN_KEY 注入');
            throw new HttpException('Sign Config Error', RcodeConf::ERROR_SERVER);
        }

        $postRawBody = get_inject_obj(RequestInterface::class)
            ->getBody()
            ->getContents();

        //验证签名
        $str  = $this->signPrefix() .
                get_header_val('Client-Device', '') .
                get_header_val('Client-Os', '') .
                get_header_val('Client-AppId', '') .
                get_header_val('Client-Version', '') .
                get_header_val('Client-Timestamp', '') .
                get_header_val('Client-Nonce', '') .
                $signKey .
                $postRawBody .
                get_header_val('Client-Token', '');
        $sign = md5($str);
        //hash_equals:定长时间比较，两串「长度相同且逐字节完全一致」才返回 true(语义等价于完全相等)，
        //同时规避按首个不同字节短路带来的计时侧信道；两参数须为字符串，故对 header 值显式转 string。
        if (!hash_equals($sign, (string) get_header_val('Client-Sign', ''))) {
            //安全:绝不记录 $str(含 signKey/Client-Token/原始 body)。仅留不可逆的 expected/provided 签名(md5)
            //与 body 摘要供排查;signKey 永不入日志。
            Logger::systemLog('SIGN')
                  ->debug(
                      sprintf(
                          'Message::%s',
                          "签名无效：[expected={$sign}|provided=" . get_header_val('Client-Sign', '')
                          . "|bodyMd5=" . md5($postRawBody) . "]"
                      )
                  );
            throw new HttpException('Error Sign', RcodeConf::ERROR_SIGN);
        }

        //签名校验通过后做防重放校验(钩子)。checkReplay() 返回 false 即判定为重放，由本处统一抛错;
        //返回 true(默认)放行。把"抛异常"收在框架侧，业务覆写只需返回判定结果，无需关心异常类型/错误码。
        if (!$this->checkReplay()) {
            throw new HttpException('Error Sign', RcodeConf::ERROR_SIGN);
        }
    }

    /**
     * 防重放校验钩子（默认 return true 放行，按需由业务子类覆写）。
     *
     * 返回值约定：true = 放行（非重放）；false = 判定为重放 → 由 checkSign() 统一抛 HttpException(ERROR_SIGN)。
     * 抛错收在框架侧，业务覆写只需返回判定结果，不必关心异常类型与错误码。
     *
     * 框架不内置防重放：Client-Nonce/Client-Sign 已参与签名、不可篡改，但「同一已签名请求在 signExpire 窗口内
     * 被原样重放」需业务自行拦截。典型做法：用 Client-Sign 作 key 写 Redis、SET NX 带 TTL，占位失败即返回 false。
     * 仅对非幂等接口（下单/转账/领取等）有意义，且每个签名请求多一次 Redis 往返，故框架默认放行，
     * 把取舍权与依赖留给业务，保持包轻量。
     *
     * 调用时机：checkSign() 内、签名校验通过之后（此时可安全信任 Client-Nonce/Client-Sign）。
     */
    protected function checkReplay(): bool
    {
        return true;
    }
}
