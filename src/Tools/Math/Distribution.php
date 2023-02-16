<?php

namespace Dleno\CommonCore\Tools\Math;

class Distribution
{
    /**
     * 根据数组的weight权重值返回对应元素
     * @param array $data 需要计算的数据，weight为权重值
     * @param int $enlarge 计算时放大倍数
     * @return mixed
     */
    public function countWeight(array $data, int $enlarge = 1)
    {
        $enlarge <= 0 && $enlarge = 1;
        $temp = [];
        foreach ($data as $v) {
            $v['weight'] = intval($v['weight'] ?? 0);
            if ($v['weight'] > 0) {
                $temp = array_merge($temp, array_fill(0, ($v['weight'] * $enlarge), $v));
            }
        }
        shuffle($temp);
        return $temp[array_rand($temp)];
    }

    /**
     * 按比例分配数据
     * @param $count
     * @param $data
     * @param string $retKey
     * @param string $ratioKey
     * @param string $disKey
     * @return int|false
     */
    public static function ratioDis($count, $data, $retKey = 'id', $ratioKey = 'ratio', $disKey = 'dis')
    {
        //计算对应总数
        $start     = 0;
        $dataCount = count($data);
        $dised     = 0;
        for ($i = 0; $i < $dataCount; $i++) {
            $isLast = ($i == $dataCount - 1) ? true : false;
            $max    = $isLast ? $count - $dised : intval($count * $data[$i][$ratioKey]);
            $dised  += $max;
            //已排满的去除
            if ($data[$i][$disKey] >= $max) {
                unset($data[$i]);
                $start += $max;
            } else {
                $data[$i]['_max_'] = $max;
            }
        }
        //算法分配
        $prevMax = $start;
        $rand    = mt_rand($start + 1, $count);
        foreach ($data as $val) {
            if ($rand > $prevMax && $rand <= $val['_max_'] + $prevMax) {
                return $val[$retKey];
            }
            $prevMax += $val['_max_'];
        }

        return false;
    }

    /**
     * 测试方法
     * @return array
     */
    public static function test()
    {
        $count = $scount = 1000;
        $data  = [
            [
                'id'    => 1,
                'ratio' => '0.02',
                'dis'   => 0,
            ],
            [
                'id'    => 2,
                'ratio' => '0.11',
                'dis'   => 0,
            ],
            [
                'id'    => 3,
                'ratio' => '0.19',
                'dis'   => 0,
            ],
            [
                'id'    => 4,
                'ratio' => '0.18',
                'dis'   => 0,
            ],
            [
                'id'    => 5,
                'ratio' => '0.5',
                'dis'   => 0,
            ],
        ];
        while ($scount-- > 0) {
            $id = self::ratioDis($count, $data, 'id');
            if ($id === false) {
                break;
            }
            $idx = array_search($id, array_column($data, 'id'));
            $data[$idx]['dis']++;
            $data[$idx]['list'][] = ['t' => $count - $scount];
        }

        var_dump($data);
        return $data;
    }

    public function test1(array $params)
    {
        $prizes = [
            ['code' => 'A', 'weight' => 1],
            ['code' => 'B', 'weight' => 1],
            ['code' => 'C', 'weight' => 2],
            ['code' => 'D', 'weight' => 0],
            ['code' => 'E', 'weight' => 1],
            ['code' => 'F', 'weight' => 2],
            ['code' => 'G', 'weight' => 1],
            ['code' => 'H', 'weight' => 4],
            ['code' => 'I', 'weight' => 1],
            ['code' => 'J', 'weight' => 1],
        ];

        $arr = [];
        for ($i = 0; $i < 15000; $i++) {
            $val               = $this->countWeight($prizes);
            $arr[$val['code']] = ($arr[$val['code']] ?? 0) + 1;
        }
        var_dump($arr);

        return [];
    }

}