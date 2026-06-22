<?php

namespace Dleno\CommonCore\Tools\Http;

use Dleno\CommonCore\Tools\Logger;
use Hyperf\Coroutine\Coroutine;

class HttpClient
{
    //默认超时(秒)。安全兜底,避免慢/挂起后端把协程永久阻塞;
    //调用方仍可显式传 -1 表示"永不超时"(自担风险)。
    private const DEFAULT_TIMEOUT = 30;

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
    public static function get(string $url, $data, array $header = [], $timeout = self::DEFAULT_TIMEOUT)
    {
        if (Coroutine::inCoroutine()) {
            return self::coRequest($url, $data, 'GET', $header, $timeout);
        } else {
            return self::curlRequest($url, $data, 'GET', $header, $timeout);
        }
    }

    /**
     * post请求
     * @param string $url
     * @param string|array $data
     * @param array $header
     */
    public static function post(string $url, $data, array $header = [], $timeout = self::DEFAULT_TIMEOUT)
    {
        if (Coroutine::inCoroutine()) {
            return self::coRequest($url, $data, 'POST', $header, $timeout);
        } else {
            return self::curlRequest($url, $data, 'POST', $header, $timeout);
        }
    }

    /**
     * put请求
     * @param string $url
     * @param string|array $data
     * @param array $header
     */
    public static function put(string $url, $data, array $header = [], $timeout = self::DEFAULT_TIMEOUT)
    {
        if (Coroutine::inCoroutine()) {
            return self::coRequest($url, $data, 'PUT', $header, $timeout);
        } else {
            return self::curlRequest($url, $data, 'PUT', $header, $timeout);
        }
    }

    /**
     * PATCH请求
     * @param string $url
     * @param string|array $data
     * @param array $header
     */
    public static function patch(string $url, $data, array $header = [], $timeout = self::DEFAULT_TIMEOUT)
    {
        if (Coroutine::inCoroutine()) {
            return self::coRequest($url, $data, 'PATCH', $header, $timeout);
        } else {
            return self::curlRequest($url, $data, 'PATCH', $header, $timeout);
        }
    }

    /**
     * DELETE请求
     * @param string $url
     * @param string|array $data
     * @param array $header
     */
    public static function delete(string $url, $data, array $header = [], $timeout = self::DEFAULT_TIMEOUT)
    {
        if (Coroutine::inCoroutine()) {
            return self::coRequest($url, $data, 'DELETE', $header, $timeout);
        } else {
            return self::curlRequest($url, $data, 'DELETE', $header, $timeout);
        }
    }

    /**
     * 协程客户端发送请求。
     * 返回 ['statusCode','headers','body','errCode','errMsg']：
     *   - 成功 errCode=0；
     *   - 失败 statusCode 为 Swoole 负值(-1连接失败/-2超时/-3服务端重置/-4发送失败)，errCode/errMsg 为底层原因。
     * @param string $url
     * @param string|array $data
     * @param array $header
     * @param int $timeout 秒；-1 表示不超时
     */
    public static function coRequest(string $url, $data, $method = 'POST', array $header = [], $timeout = self::DEFAULT_TIMEOUT)
    {
        $parsed = parse_url($url);
        if (!is_array($parsed) || empty($parsed['host'])) {
            throw new \InvalidArgumentException("Invalid URL: {$url}");
        }
        $ssl    = ($parsed['scheme'] ?? '') === 'https';
        $port   = $parsed['port'] ?? ($ssl ? 443 : 80);
        $client = new \Swoole\Coroutine\Http\Client($parsed['host'], intval($port), $ssl);
        try {
            $client->set(['timeout' => $timeout]);//-1为不超时
            $client->setHeaders(
                array_merge(
                    self::$defaultHeader,
                    [
                        'Host' => $parsed['host'],
                    ],
                    $header
                )
            );

            $path = ($parsed['path'] ?? '/') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
            if ($method == 'POST') {
                $ok = $client->post($path, $data);
            } elseif (in_array($method, ['PUT', 'DELETE', 'PATCH'], true)) {
                $client->setMethod($method);
                $client->setData($data);
                $ok = $client->execute($path);
            } else {
                $query = self::buildQuery($data);
                if ($query !== '') {
                    $path .= (isset($parsed['query']) ? '&' : '?') . $query;
                }
                $ok = $client->get($path);
            }

            $httpCode = $client->getStatusCode();
            //失败:请求方法返回 false 或 statusCode<0(网络层失败) → 回带 errCode/errMsg 供上层重试/降级/告警
            if ($ok === false || $httpCode < 0) {
                Logger::stdoutLog()->warning(
                    "HttpClient coRequest failed [{$method} {$parsed['host']}]: status={$httpCode} errCode={$client->errCode} errMsg={$client->errMsg}"
                );
                return [
                    'statusCode' => $httpCode,
                    'headers'    => [],
                    'body'       => '',
                    'errCode'    => $client->errCode,
                    'errMsg'     => $client->errMsg,
                ];
            }
            return [
                'statusCode' => $httpCode,
                'headers'    => $client->getHeaders() ?: [],
                'body'       => $client->getBody(),
                'errCode'    => 0,
                'errMsg'     => '',
            ];
        } finally {
            //无论成功/失败/异常，保证释放底层连接，杜绝 fd 泄漏
            $client->close();
        }
    }

    /**
     * 原生curl发送请求。
     * 返回 ['statusCode','headers','body','errCode','errMsg']：成功 errCode=0；失败 errCode 为 curl_errno、errMsg 为 curl_error。
     * @param string $url
     * @param $data
     * @param string $method
     * @param array $header
     * @param int $timeout 秒；<=0 表示不设超时
     * @return array
     */
    public static function curlRequest(string $url, $data, $method = 'POST', array $header = [], $timeout = self::DEFAULT_TIMEOUT)
    {
        if (is_array($data) || is_object($data)) {
            $data = http_build_query($data);
        }

        $parsedUrl = parse_url($url);
        if (!is_array($parsedUrl) || empty($parsedUrl['host'])) {
            throw new \InvalidArgumentException("Invalid URL: {$url}");
        }

        $ch = curl_init();
        try {
            curl_setopt($ch, CURLOPT_ENCODING, 'gzip,deflate');//文档解码
            //----连接设置----
            curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);//强制获取一个新的连接，替代缓存中的连接
            curl_setopt($ch, CURLOPT_FORBID_REUSE, true);//在完成交互以后强迫断开连接，不能重用。
            curl_setopt($ch, CURLOPT_AUTOREFERER, true); //自动设置Referer
            //默认使用IPV4模式
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

            //----设置超时----
            curl_setopt($ch, CURLOPT_NOSIGNAL, true);//禁用信号机制
            //从服务器接收缓冲完成前需要等待多长时间
            if ($timeout > 0) {
                curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);//秒
            }

            //----HTTPS----
            $ssl = ($parsedUrl['scheme'] ?? '') === 'https';
            if ($ssl) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            }

            //-----HEADER-----
            //头包含在输出中
            curl_setopt($ch, CURLOPT_HEADER, true);
            //请求发送头
            $header = array_merge(
                self::$defaultHeader,
                [
                    'Host' => $parsedUrl['host'],
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
                $query = self::buildQuery($data);
                if ($query !== '') {
                    if (isset($parsedUrl['query'])) {
                        $url .= '&' . $query;
                    } else {
                        $url .= '?' . $query;
                    }
                }
            }

            //----请求地址----
            curl_setopt($ch, CURLOPT_URL, $url);

            $result   = curl_exec($ch);//执行请求，返回输出结果
            $errno    = curl_errno($ch);
            $errmsg   = $errno ? curl_error($ch) : '';
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); // 获取 HTTP 状态码

            //失败:curl 报错(连接失败/超时/DNS 等) → 回带 errCode/errMsg 供上层重试/降级/告警
            if ($errno !== 0) {
                Logger::stdoutLog()->warning(
                    "HttpClient curlRequest failed [{$method} {$parsedUrl['host']}]: status={$httpCode} errno={$errno} err={$errmsg}"
                );
                return [
                    'statusCode' => $httpCode,
                    'headers'    => [],
                    'body'       => '',
                    'errCode'    => $errno,
                    'errMsg'     => $errmsg,
                ];
            }

            $headers = [];
            $body    = '';
            if (is_string($result)) {
                $headerSize   = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $headerString = substr($result, 0, $headerSize);
                $body         = substr($result, $headerSize);

                // 将头部字符串转为数组
                $headersArray = explode("\r\n", $headerString);

                foreach ($headersArray as $headerLine) {
                    if (strpos($headerLine, ':') !== false) {
                        list($key, $value) = explode(':', $headerLine, 2);
                        $headers[strtolower(trim($key))] = trim($value);
                    }
                }
            }

            return [
                'statusCode' => $httpCode,
                'headers'    => $headers,
                'body'       => $body,
                'errCode'    => 0,
                'errMsg'     => '',
            ];
        } finally {
            //无论成功/失败/异常，保证释放 curl 句柄
            curl_close($ch);
        }
    }

    private static function buildQuery($data): string
    {
        if (is_array($data) || is_object($data)) {
            return http_build_query((array)$data);
        }
        if ($data === null || $data === false || $data === '') {
            return '';
        }
        return (string)$data;
    }
}
