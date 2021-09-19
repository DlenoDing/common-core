<?php
namespace Dleno\CommonCore\Tools\Http;

use Hyperf\Utils\Coroutine;

class HttpClient
{
    private static $defaultHeader = [
        'Host' => '',
        'User-Agent' => 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)',
        'Accept' => '*',
        'Accept-Encoding' => 'gzip',
        'Content-Type' => 'application/json; charset=utf-8',
    ];

    /**
     * post请求
     * @param string $url
     * @param string|array $data
     * @param array $header
     */
    public static function post(string $url, $data, array $header = [], $timeout = -1)
    {
        if (Coroutine::inCoroutine()) {
            return self::coPost($url, $data, $header, $timeout);
        } else {
            return self::curlPost($url, $data, $header, $timeout);
        }
    }

    /**
     * 协程客户端发送post请求
     * @param string $url
     * @param string|array $data
     * @param array $header
     */
    public static function coPost(string $url, $data, array $header = [], $timeout = -1)
    {
        $url = parse_url($url);
        $ssl = $url['scheme'] == 'https' ? true : false;
        $client = new \Swoole\Coroutine\Http\Client($url['host'], $ssl ? 443 : 80, $ssl);
        $client->set(['timeout' => $timeout]);//-1为不超时
        $client->setHeaders(array_merge(self::$defaultHeader, [
            'Host' => $url['host'],
        ], $header));
        $client->post($url['path'] . (isset($url['query']) ? '?' . $url['query'] : ''), $data);
        $res = $client->body;
        $client->close();
        if (empty($res)) {
            return false;
        }

        return $res;
    }

    /**
     * 原生curl发送post请求
     * @param string $url
     * @param $data
     * @param array $header
     * @param int $timeout
     * @return bool|mixed|string
     */
    public static function curlPost(string $url, $data, array $header = [], $timeout = -1)
    {
        if (is_array($data) || is_object($data)) {
            $data = http_build_query($data);
        }

        $paeseUrl = parse_url($url);

        $ch = curl_init();

        //----连接设置----
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);//强制获取一个新的连接，替代缓存中的连接
        curl_setopt($ch, CURLOPT_FORBID_REUSE, true);//在完成交互以后强迫断开连接，不能重用。
        curl_setopt($ch, CURLOPT_AUTOREFERER, true); //自动设置Referer
        //默认使用IPV4模式
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

        //----设置超时----
        curl_setopt($ch, CURLOPT_NOSIGNAL, true);//支持毫秒级别超时设
        //从服务器接收缓冲完成前需要等待多长时间
        if ($timeout > 0) {
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeout);//毫秒
        }

        //----HTTPS----
        $ssl = $paeseUrl['scheme'] == 'https' ? true : false;
        if ($ssl) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }

        //-----HEADER-----
        //头包含在输出中
        curl_setopt($ch, CURLOPT_HEADER, false);
        //请求发送头
        $header = array_merge(self::$defaultHeader, [
            'Host' => $paeseUrl['host'],
        ], $header);
        array_walk($header, function (&$val, $key) {
            $val = $key . ": " . $val;
        });
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);

        //-----返回结果-----
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);//不直接输出，返回的内容作为变量储存；curl_exec($ch)获取输出

        //-----请求方式处理-----
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

        //----请求地址----
        curl_setopt($ch, CURLOPT_URL, $url);

        $result = curl_exec($ch);//执行请求，返回输出结果
        $errno  = curl_errno($ch);//错误号
        $error  = curl_error($ch);//错误消息
        $info   = curl_getinfo($ch);//调用详情

        //------关闭连接-----
        curl_close($ch);


        unset($errno, $error, $ch, $info, $params, $header, $method, $timeout, $userAgent, $content);

        return $result;
    }
}
