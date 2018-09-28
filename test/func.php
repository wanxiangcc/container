<?php
/**
 * Created by PhpStorm.
 * User: wanxiang
 * Date: 2018/9/28
 * Time: 13:17
 */
require_once '../vendor/autoload.php';
$cls = new ReflectionClass(\Hi\Container\Container::class);
//print_r($cls->getMethods());
$arr = $cls->getMethods();
$arrP = $cls->getProperties();
$prop = [];
$method = [];
foreach ($arrP as $v){
    $prop[] = $v->getName();
}
foreach ($arr as $v){
    $method[] = $v->getName();
}
//print_r($method);

$clsi = new ReflectionClass(\Illuminate\Container\Container::class);
$arri = $clsi->getMethods();
$arrPi = $clsi->getProperties();
$methodi = [];
$propi = [];
foreach ($arri as $v){
    $methodi[] = $v->getName();
}
foreach ($arrPi as $v){
    $propi[] = $v->getName();
}
print_r(array_intersect($method,$methodi));
print_r(array_diff($method,$methodi));

print_r(array_intersect($prop,$propi));
print_r(array_diff($prop,$propi));
