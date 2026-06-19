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
                $this->signKey() .
                $postRawBody .
                get_header_val('Client-Token', '');
        $sign = md5($str);
        if (get_header_val('Client-Sign', '') <> $sign) {
            Logger::systemLog('SIGN')
                  ->debug(
                      sprintf(
                          'Message::%s||SignStr::%s',
                          "签名无效：[{$sign}|" . get_header_val('Client-Sign', '') . "]",
                          $str
                      )
                  );
            throw new HttpException('Error Sign', RcodeConf::ERROR_SIGN);
        }
    }
}
