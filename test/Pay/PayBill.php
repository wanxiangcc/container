<?php
/**
 * Created by PhpStorm.
 * User: wx
 * Date: 2018-5-7
 * Time: 14:39
 */

namespace Hi\ContainerTest\Pay;

use Hi\ContainerTest\Pay\Pay;

class PayBill
{
    private $payImpl;

    public function __construct(Pay $payImpl)
    {
        $this->payImpl = $payImpl;
    }

    public function __call($name, $arguments)
    {
        // $this->payImpl->$name($arguments);
        call_user_func_array(array($this->payImpl, $name), $arguments);
    }
}