<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Middleware\Http;

use Dleno\CommonCore\Conf\GlobalConf;
use Dleno\CommonCore\Conf\RcodeConf;
use Dleno\CommonCore\Exception\Http\HttpException;
use Dleno\CommonCore\Tools\ApiServer;
use Dleno\CommonCore\Tools\Check\CheckVal;
use Dleno\CommonCore\Tools\Crypt\OpenSslCrypt;
use Dleno\CommonCore\Tools\Logger;
use Dleno\CommonCore\Tools\Server;
use FastRoute\Dispatcher;
use Hyperf\Context\Context;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Router\Dispatched;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function Hyperf\Config\config;

/**
 * 模块前置中间件基类（框架通用）：封装与具体项目无关的「未知路由 404 + 签名校验 + 数据解密 + 登录前置流程」。
 *
 * 作为 PSR-15 全局中间件，运行于「路由分发之后、控制器之前」
 *（Hyperf 在 Server::onRequest 中先 coreMiddleware->dispatch() 完成路由分发并写入 Dispatched，再跑中间件管线）：
 *   - 仅对已命中路由(FOUND)做签名/解密/鉴权，白名单经 Server::getRouteMca() 取权威路由；
 *   - 非 FOUND（NOT_FOUND / METHOD_NOT_ALLOWED）直接放行，交管线末端 CoreMiddleware->process() 返 404 / 405
 *     （故不会对未命中路由误跑签名、把 404 变成签名 403）；
 *   - 与 WS 端 WebSocketAuthMiddleware 对称，HTTP/WS 鉴权统一为中间件；
 *   - 应排在 InitMiddleware 之后（依赖其写入的时区/解析体/RPC 上下文等）。
 *
 * 本类不耦合任何 App\ 下的类，为【抽象基类】：只定义「流程引擎」(process/checkSign/dataDecryption) 与「钩子契约」(下列抽象方法)，
 * 不含钩子默认实现、不可实例化（参照包内 WsHookInterface=>AbstractWsHook 约定）。三层结构：
 *   - 钩子默认实现：包内 DefaultModuleBeforeMiddleware（checkAuth no-op、checkReplay 放行、sign* 读 config）；
 *   - ConfigProvider::autoMiddlewares() 注册本抽象类名（排在 InitMiddleware 后、同一 HTTP_INIT_MIDDLEWARE_ENABLE 开关），
 *     并默认绑定 本类 => DefaultModuleBeforeMiddleware；业务不接管时即用此默认：签名/解密仍按 config 开关执行，不报错；
 *   - 业务接管：写一个【继承 DefaultModuleBeforeMiddleware】的子类（只覆写需要的钩子、其余走默认），
 *     并在 app config/autoload/dependencies.php 覆盖绑定即生效：
 *         AbstractModuleBeforeMiddleware::class => App\Middleware\AppModuleBeforeMiddleware::class
 *     ⚠ 业务已写子类但漏了此绑定时，会静默回落到包内默认（鉴权不生效），接入后务必验证 checkAuth 确被调用。
 *
 * 钩子契约（抽象方法，默认实现均在 DefaultModuleBeforeMiddleware；每请求钩子带入 $request）：
 *   - checkAuth($request)：登录态校验（后台登录 / API 端 token；单类内可按 ApiServer::isAdminModule() 分流）。
 *       $request 为解密后的请求（parsedBody 已就绪）。【白名单与非正式环境 debug 的放行由本基类统一判定】：
 *       命中 TOKEN 白名单或非正式环境带 Client-Debug 时不会调用本钩子，子类无需再自行判断白名单。
 *   - checkReplay($request)：防重放。
 *   - signPrefix()/signKey()/signExpire()：签名前缀/密钥/时间偏移。
 *
 * 路由白名单值 / AES 密钥统一走 common-core 的 ApiServer，无需项目侧再提供。
 */
abstract class AbstractModuleBeforeMiddleware implements MiddlewareInterface
{
    /**
     * 检查登录状态（钩子契约，默认实现见 DefaultModuleBeforeMiddleware = no-op 放行）。
     * 业务覆写实现后台登录 / API 端 token 校验；单类内可按 ApiServer::isAdminModule() 分流 Admin/Api。
     * 校验不通过应自行 throw（如 HttpException(ERROR_TOKEN)）。
     *
     * $request 为解密后的请求（parsedBody 已就绪），可直接取参。
     * 注意：本钩子仅在「未命中 TOKEN 白名单 且 非（非正式环境 debug）」时才被基类调用，子类无需再判断白名单。
     */
    abstract protected function checkAuth(ServerRequestInterface $request);

    /**
     * 签名前缀（钩子契约，默认实现见 DefaultModuleBeforeMiddleware = 读 config('app.sign_prefix')）。
     */
    abstract protected function signPrefix(): string;

    /**
     * 签名密钥（钩子契约，默认实现见 DefaultModuleBeforeMiddleware = 读 config('app.sign_key')）。
     */
    abstract protected function signKey(): string;

    /**
     * 签名允许的时间偏移量（秒，钩子契约，默认实现见 DefaultModuleBeforeMiddleware = 读 config('app.sign_expire')）。
     */
    abstract protected function signExpire(): int;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        //路由分发已在中间件管线之前完成（Server::onRequest 先 coreMiddleware->dispatch() 写入 Dispatched，再跑中间件），
        //Dispatched 已就绪，直接读其状态判定。
        $dispatched = $request->getAttribute(Dispatched::class);
        $status     = $dispatched instanceof Dispatched ? $dispatched->status : null;

        //仅对已命中路由(FOUND)做签名/解密/鉴权；其余状态直接放行，交管线末端 CoreMiddleware->process() 处理：
        //  - NOT_FOUND → CoreMiddleware 抛 404；METHOD_NOT_ALLOWED → 405（与原只在控制器执行时生效的语义一致）；
        //  - 不对非 FOUND 跑签名：其 Dispatched.handler 为 null、取不到 mca，否则会误判白名单 → 错误的签名 403。
        if ($status === Dispatcher::FOUND) {
            //获取当前路由白名单值（Dispatched 已就绪，getRouteMca 取权威路由）
            $whiteVal = ApiServer::getRouteVal();
            //验证接口签名
            $this->checkSign($request, $whiteVal);
            //接口数据解密
            $this->dataDecryption($whiteVal);
            //验证登录：命中 TOKEN 白名单 / 非正式环境 debug 则跳过，否则交由 checkAuth（传入解密后的请求）
            if (!CheckVal::checkInStatus(GlobalConf::WHITE_TYPE_TOKEN, $whiteVal)
                && !(!Server::isProd() && get_header_val('Client-Debug'))) {
                $authReq = Context::get(ServerRequestInterface::class) ?? $request;
                $this->checkAuth($authReq);
            }
        }

        return $handler->handle($request);
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
    protected function checkSign(ServerRequestInterface $request, $whiteVal)
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
        if (!$this->checkReplay($request)) {
            throw new HttpException('Error Sign', RcodeConf::ERROR_SIGN);
        }
    }

    /**
     * 防重放校验钩子（钩子契约，默认实现见 DefaultModuleBeforeMiddleware = return true 放行）。
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
    abstract protected function checkReplay(ServerRequestInterface $request): bool;
}
