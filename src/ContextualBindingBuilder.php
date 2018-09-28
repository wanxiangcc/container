<?php
/**
 * Created by PhpStorm.
 * User: wanxiang
 * Date: 2018/9/28
 * Time: 10:36
 */

namespace Hi\Container;

use Hi\Interfaces\Container\ContextualBindingBuilder as ContextualBindingBuilderInterface;

class ContextualBindingBuilder implements ContextualBindingBuilderInterface
{
    /**
     * 容器
     * @var \Hi\Container\Container
     */
    protected $container;
    /*
     * 具体实例
     * @var string
     */
    protected $concrete;
    /*
     * 抽象目标
     * @var string
     */
    protected $needs;

    /**
     * 创建一个新的上下文绑定.
     *
     * @param  \Hi\Container\Container $container
     * @param  string $concrete
     * @return void
     */
    public function __construct(Container $container, $concrete)
    {
        $this->concrete = $concrete;
        $this->container = $container;
    }

    /**
     * 定义依赖于上下文的抽象目标
     * @param string $abstract
     * @return $this
     */
    public function needs($abstract)
    {
        $this->needs = $abstract;
        return $this;
    }

    /**
     * 定义上下文绑定的具体实现implementation
     * @param \Closure|string $implementation
     */
    public function give($implementation)
    {
        $this->container->addContextualBinding(
            $this->concrete, $this->needs, $implementation
        );
    }

}