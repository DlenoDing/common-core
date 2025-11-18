<?php

namespace Dleno\CommonCore\Tools\Http;

use Hyperf\Utils\Coroutine;

class HttpClient
{
    private static $defaultHeader = [
        'Host'            => '',
        'User-Agent'      => 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)',
        'Accept'          => '*',
        'Accept-Encoding' => 'gzip',
        'Content-Type'    => 'application/json; charset=utf-8',
    ];

    /**
     * get请求
     * @param string $url
     * @param string|array $data
     * @param array $header
     */
    public static function get(string $url, $data, array $header = [], $timeout = -1, $returnHeader = false)
    {
        if (Coroutine::inCoroutine()) {
            return self::coRequest($url, $data, 'GET', $header, $timeout, $returnHeader);
        } else {
            return self::curlRequest($url, $data, 'GET', $header, $timeout, $returnHeader);
        }
    }

    /**
     * post请求
     * @param string $url
     * @param string|array $data
     * @param array $header
     */
    public static function post(string $url, $data, array $header = [], $timeout = -1, $returnHeader = false)
    {
        if (Coroutine::inCoroutine()) {
            return self::coRequest($url, $data, 'POST', $header, $timeout, $returnHeader);
        } else {
            return self::curlRequest($url, $data, 'POST', $header, $timeout, $returnHeader);
        }
    }

    /**
     * put请求
     * @param string $url
     * @param string|array $data
     * @param array $header
     */
    public static function put(string $url, $data, array $header = [], $timeout = -1, $returnHeader = false)
    {
        if (Coroutine::inCoroutine()) {
            return self::coRequest($url, $data, 'PUT', $header, $timeout, $returnHeader);
        } else {
            return self::curlRequest($url, $data, 'PUT', $header, $timeout, $returnHeader);
        }
    }

    /**
     * PATCH请求
     * @param string $url
     * @param string|array $data
     * @param array $header
     */
    public static function patch(string $url, $data, array $header = [], $timeout = -1, $returnHeader = false)
    {
        if (Coroutine::inCoroutine()) {
            return self::coRequest($url, $data, 'PATCH', $header, $timeout, $returnHeader);
        } else {
            return self::curlRequest($url, $data, 'PATCH', $header, $timeout, $returnHeader);
        }
    }

    /**
     * DELETE请求
     * @param string $url
     * @param string|array $data
     * @param array $header
     */
    public static function delete(string $url, $data, array $header = [], $timeout = -1, $returnHeader = false)
    {
        if (Coroutine::inCoroutine()) {
            return self::coRequest($url, $data, 'DELETE', $header, $timeout, $returnHeader);
        } else {
            return self::curlRequest($url, $data, 'DELETE', $header, $timeout, $returnHeader);
        }
    }

    /**
     * coPost请求(兼容使用老包的方法)
     * @param string $url
     * @param string|array $data
     * @param array $header
     */
    public static function coPost(string $url, $data, array $header = [], $timeout = -1, $returnHeader = false)
    {
        return self::coRequest($url, $data, 'POST', $header, $timeout, $returnHeader);
    }

    /**
     * curlPost请求(兼容使用老包的方法)
     * @param string $url
     * @param string|array $data
     * @param array $header
     */
    public static function curlPost(string $url, $data, array $header = [], $timeout = -1, $returnHeader = false)
    {
        return self::curlRequest($url, $data, 'POST', $header, $timeout, $returnHeader);
    }

    /**
     * 协程客户端发送请求
     * @param string $url
     * @param string|array $data
     * @param array $header
     */
    public static function coRequest(
        string $url,
        $data,
        $method = 'POST',
        array $header = [],
        $timeout = -1,
        $returnHeader = false
    ) {
        $url    = parse_url($url);
        $ssl    = $url['scheme'] == 'https' ? true : false;
        $port   = $url['port'] ?? ($ssl ? 443 : 80);
        $client = new \Swoole\Coroutine\Http\Client($url['host'], intval($port), $ssl);
        $client->set(['timeout' => $timeout]);//-1为不超时
        $client->setHeaders(
            array_merge(
                self::$defaultHeader,
                [
                    'Host' => $url['host'],
                ],
                $header
            )
        );
        $path = ($url['path'] ?? '/') . (isset($url['query']) ? '?' . $url['query'] : '');
        if ($method == 'POST') {
            $client->post($path, $data);
        } elseif ($method == 'PUT') {
            $client->setMethod('PUT');
            $client->setData($data);
            $client->execute($path);
        } elseif ($method == 'DELETE') {
            $client->setMethod('DELETE');
            $client->setData($data);
            $client->execute($path);
        } elseif ($method == 'PATCH') {
            $client->setMethod('PATCH');
            $client->setData($data);
            $client->execute($path);
        } else {
            if ($data) {
                if (is_array($data) || is_object($data)) {
                    $data = http_build_query((array)$data);
                }
                if (isset($url['query'])) {
                    $path .= '&' . $data;
                } else {
                    $path .= '?' . $data;
                }
            }
            $client->get($path);
        }

        $body       = $client->getBody();
        $headers    = $client->getHeaders();
        $statusCode = $client->getStatusCode();

        $client->close();
        if (!$returnHeader) {
            return $body;
        } else {
            return [
                'statusCode' => $statusCode,
                'headers'    => $headers,
                'body'       => $body,
            ];
        }
    }

    /**
     * 原生curl发送请求
     * @param string $url
     * @param $data
     * @param string $method
     * @param array $header
     * @param int $timeout
     * @return bool|mixed|string
     */
    public static function curlRequest(
        string $url,
        $data,
        $method = 'POST',
        array $header = [],
        $timeout = -1,
        $returnHeader = false
    ) {
        if (is_array($data) || is_object($data)) {
            $data = http_build_query($data);
        }

        $paeseUrl = parse_url($url);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip,deflate');//文档解码
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
        curl_setopt($ch, CURLOPT_HEADER, $returnHeader ? true : false);
        //请求发送头
        $header = array_merge(
            self::$defaultHeader,
            [
                'Host' => $paeseUrl['host'],
            ],
            $header
        );
        array_walk(
            $header,
            function (&$val, $key) {
                $val = $key . ": " . $val;
            }
        );
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);

        //-----返回结果-----
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);//不直接输出，返回的内容作为变量储存；curl_exec($ch)获取输出

        if ($method == 'POST') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        } elseif ($method == 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        } elseif ($method == 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        } elseif ($method == 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PATCH");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        } else {
            if ($data) {
                if (isset($paeseUrl['query'])) {
                    $url .= '&' . $data;
                } else {
                    $url .= '?' . $data;
                }
            }
        }

        //----请求地址----
        curl_setopt($ch, CURLOPT_URL, $url);

        $result = curl_exec($ch);//执行请求，返回输出结果
        //$errno = curl_errno($ch);//错误号
        //$error = curl_error($ch);//错误消息
        //$info  = curl_getinfo($ch);//调用详情
        if (!$returnHeader) {
            curl_close($ch);
            return $result;
        } else {
            curl_close($ch);
            $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE); // 获取 HTTP 状态码
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headers    = substr($result, 0, $headerSize);
            $body       = substr($result, $headerSize);

            return [
                'statusCode' => $httpCode,
                'headers'    => $headers,
                'body'       => $body,
            ];
        }
    }
}
