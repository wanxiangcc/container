<?php
/**
 * Created by PhpStorm.
 * User: wx
 * Date: 2018-5-7
 * Time: 10:47
 */
require_once '../vendor/autoload.php';

$app = new Hi\Container\Container();
//$app->alias('alipay', Hi\ContainerTest\Pay\Impl\Alipay::class);
// Pay 为接口， Alipay 是 class Alipay
// 可以当做是Class PayBill 的服务别名
$app->bind('payBill', Hi\ContainerTest\Pay\PayBill::class);
$app->bind(Hi\ContainerTest\Pay\Pay::class, Hi\ContainerTest\Pay\Impl\Alipay::class);

// 通过字符解析，或得到了Class PayBill 的实例
$paybill = $app->make('payBill');
// 因为之前已经把Pay 接口绑定为了 Alipay，所以调用pay 方法的话会显示 'pay bill by alipay '
$paybill->pay(array('aa' => '222'));

$app->bind(Hi\ContainerTest\Pay\Pay::class, Hi\ContainerTest\Pay\Impl\Wechat::class);
$paybill = $app->make('payBill');
$paybill->pay(array('aa' => '222'));
