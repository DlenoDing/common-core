<?php

namespace Dleno\CommonCore\Tools\Notice;

use Hyperf\Utils\Coroutine;
use Dleno\CommonCore\Tools\Http\HttpClient;

class DingDing
{
    /**
     * 发送钉钉消息
     * @param $content
     * @param null $channel
     * @param null $token
     */
    public static function send($content, $channel = null, $token = null)
    {
        $runEnv = config('app_env');
        if ($runEnv == 'local') {
            return;
        }
        $token = $token ?? config('app.dingding_notice_token');
        if (empty($token)) {
            return;
        }
        $channel = $channel ?? config('app_name');
        $title   = $channel . ":[{$runEnv}]";
        if (is_array($content)) {
            if (isset($content['Title'])) {
                $title = $content['Title'];
            }
            $text = [];
            foreach ($content as $k => $val) {
                if (strtolower($k) == 'message' || strtolower($k) == 'trace') {
                    $text[] = '#### <font color=#f00>' . $k . ' ::</font>';
                    if (is_array($val)) {
                        foreach ($val as $v) {
                            $text[] = '> ' . str_replace('#', '＃', $v) . "\n";
                        }
                    } else {
                        $text[] = '> ' . $val . "\n";
                    }
                } else {
                    if (is_null($val)) {
                        $text[] = '#### <font color=#f00>' . $k . '</font>';
                    } else {
                        $text[] = '#### <font color=#00f>' . $k . ' :: </font>' . $val;
                    }
                }
            }
            $text = join("\n", $text);
            $data = [
                'msgtype'  => 'markdown',
                'markdown' => [
                    'title' => $title,
                    'text'  => $text,
                ],
            ];
        } else {
            $data = [
                'msgtype' => 'text',
                'text'    => [
                    'content' => $channel . ":[{$runEnv}]" . "\n" . $content,
                ],
            ];
        }
        $data   = array_to_json($data);
        $url    = 'https://oapi.dingtalk.com/robot/send?access_token=' . $token;
        $header = [
            'Content-Type' => 'application/json; charset=utf-8',
        ];
        if (Coroutine::inCoroutine()) {
            Coroutine::create(
                function () use ($url, $data, $header) {
                    $ret = HttpClient::coPost($url, $data, $header);
                }
            );
        } else {
            $ret = HttpClient::curlPost($url, $data, $header);
        }
    }
}
