<?php
/**
 * Created by PhpStorm.
 * User: wx
 * Date: 2018-5-7
 * Time: 14:38
 */

namespace Hi\ContainerTest\Pay\Impl;

use Hi\ContainerTest\Pay\Pay;

class Alipay implements Pay
{
    public function pay(...$args)
    {
        echo __CLASS__ . PHP_EOL;
        var_dump($args[0]);
    }
}