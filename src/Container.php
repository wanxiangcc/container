<?php
/**
 * Created by PhpStorm.
 * User: wanxiang
 * Date: 2018/9/27
 * Time: 14:55
 */

namespace Hi\Container;

use ArrayAccess;
use Closure;
use LogicException;
use ReflectionClass;
use ReflectionException;
use ReflectionParameter;
use Hi\Interfaces\Container\Container as ContainerInterface;

class Container implements ArrayAccess, ContainerInterface
{
    /**
     * container 全局的instance
     *
     * @var static
     */
    protected static $instance;

    /**
     * 已经实例化resolve过的类.
     *
     * @var array
     */
    protected $resolved = [];

    /**
     * The container's bindings.
     * 用于装提供实例的回调函数，真正的容器还会装实例等其他内容，从而实现单例等高级功能
     * @var array
     */
    protected $bindings = [];

    /**
     * The container's method bindings.
     *
     * @var array
     */
    protected $methodBindings = [];

    /**
     * The container's shared instances.
     *
     * @var array
     */
    protected $instances = [];

    /**
     * The registered type aliases.
     *
     * @var array
     */
    protected $aliases = [];

    /**
     * The registered aliases keyed by the abstract name.
     *
     * @var array
     */
    protected $abstractAliases = [];

    /**
     * The extension closures for services.
     *
     * @var array
     */
    protected $extenders = [];

    /**
     * All of the registered tags.
     *
     * @var array
     */
    protected $tags = [];

    /**
     * 待构建堆栈
     *
     * @var array
     */
    protected $buildStack = [];

    /**
     * The parameter override stack.
     *
     * @var array
     */
    protected $with = [];

    /**
     * The contextual binding map.
     *
     * @var array
     */
    public $contextual = [];

    /**
     * All of the registered rebound callbacks.
     *
     * @var array
     */
    protected $reboundCallbacks = [];

    /**
     * All of the global resolving callbacks.
     *
     * @var array
     */
    protected $globalResolvingCallbacks = [];

    /**
     * All of the global after resolving callbacks.
     *
     * @var array
     */
    protected $globalAfterResolvingCallbacks = [];

    /**
     * All of the resolving callbacks by class type.
     *
     * @var array
     */
    protected $resolvingCallbacks = [];

    /**
     * All of the after resolving callbacks by class type.
     *
     * @var array
     */
    protected $afterResolvingCallbacks = [];

    /**
     * 判断是否已经被绑定过（实例化过）
     * @param $abstract
     * @return bool
     */
    public function bound($abstract)
    {
        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]) || $this->isAlias($abstract);
    }

    public function alias($abstract, $alias)
    {
        $this->aliases[$alias] = $abstract;
        $this->abstractAliases[$abstract][] = $alias;
    }

    /**
     * Assign a set of tags to a given binding.
     *
     * @param  array|string $abstracts
     * @param  array|mixed ...$tags
     * @return void
     */
    public function tag($abstracts, $tags)
    {
        $tags = is_array($tags) ? $tags : array_slice(func_get_args(), 1);
        foreach ($tags as $tag) {
            if (!isset($this->tags[$tag])) {
                $this->tags[$tag] = [];
            }

            foreach ((array)$abstracts as $abstract) {
                $this->tags[$tag][] = $abstract;
            }
        }
    }

    /**
     * Resolve all of the bindings for a given tag.
     *
     * @param  string $tag
     * @return array
     */
    public function tagged($tag)
    {
        $results = [];
        if (isset($this->tags[$tag])) {
            foreach ($this->tags[$tag] as $abstract) {
                $results[] = $this->make($abstract);
            }
        }
        return $results;
    }

    /**
     * 绑定接口和生成相应实例的回调函数
     * @param $abstract 比如：抽象类
     * @param null $concrete 实例
     * @param bool $shared
     * @throws \Exception
     */
    public function bind($abstract, $concrete = null, $shared = false)
    {
        $this->dropStaleInstances($abstract);
        if (is_null($concrete)) {
            $concrete = $abstract;
        }
        // 如果提供的参数不是回调函数，则产生默认的回调函数
        if (!$concrete instanceof Closure) {
            $concrete = $this->getClosure($abstract, $concrete);
        }
        $this->bindings[$abstract] = compact('concrete', 'shared');
        // 检查是否解析过 或者 已经存在所要绑定对象对应的实例
        if ($this->resolved($abstract)) {
            $this->rebound($abstract);
        }
    }

    public function bindIf($abstract, $concrete = null, $shared = false)
    {
        if (!$this->bound($abstract)) {
            $this->bind($abstract, $concrete, $shared);
        }
    }

    /**
     * 注册一个单例
     * @param $abstract
     * @param null $concrete
     * @throws \Exception
     */
    public function singleton($abstract, $concrete = null)
    {
        $this->bind($abstract, $concrete, true);
    }

    /**
     * "Extend" an abstract type in the container.
     *
     * @param  string $abstract
     * @param  \Closure $closure
     * @return void
     *
     * @throws \InvalidArgumentException
     */
    public function extend($abstract, Closure $closure)
    {
        $abstract = $this->getAlias($abstract);
        if (isset($this->instances[$abstract])) {
            $this->instances[$abstract] = $closure($this->instances[$abstract], $this);
            $this->rebound($abstract);
        } else {
            $this->extenders[$abstract][] = $closure;
            if ($this->resolved($abstract)) {
                $this->rebound($abstract);
            }
        }
    }

    /**
     * 注册一个instace
     * @param $abstract
     * @param $instance
     */
    public function instance($abstract, $instance)
    {
        $this->removeAbstractAlias($abstract);
        $isBound = $this->bound($abstract);
        unset($this->aliases[$abstract]);
        $this->instances[$abstract] = $instance;
        if ($isBound) {
            $this->rebound($abstract);
        }
    }

    public function isAlias($name)
    {
        return isset($this->aliases[$name]);
    }

    protected function removeAbstractAlias($searched)
    {
        if (!isset($this->aliases[$searched])) {
            return;
        }
        foreach ($this->abstractAliases as $abstract => $aliases) {
            foreach ($aliases as $index => $alias) {
                if ($alias == $searched) {
                    unset($this->abstractAliases[$abstract][$index]);
                }
            }
        }
    }

    /**
     * 定义上下文绑定.
     *
     * @param  string $concrete
     * @return \Hi\Container\ContextualBindingBuilder
     */
    public function when($concrete)
    {
        return new ContextualBindingBuilder($this, $this->getAlias($concrete));
    }

    /**
     * 向容器中添加上下文绑定.
     *
     * @param  string $concrete
     * @param  string $abstract
     * @param  \Closure|string $implementation
     * @return void
     */
    public function addContextualBinding($concrete, $abstract, $implementation)
    {
        $this->contextual[$concrete][$this->getAlias($abstract)] = $implementation;
    }

    public function factory($abstract)
    {
        return function () use ($abstract) {
            return $this->make($abstract);
        };
    }

    /**
     * 从容器当中解析给定的$abstract
     * @param $abstract
     * @return mixed|object
     */
    public function make($abstract)
    {
        return $this->resolve($abstract);
    }

    public function call($callback, array $parameters = [], $defaultMethod = null)
    {
        // TODO
    }

    /**
     * 判断是否被实例化过
     * @param $abstract
     * @return bool
     * @throws \Exception
     */
    public function resolved($abstract)
    {
        if ($this->isAlias($abstract)) {
            $abstract = $this->getAlias($abstract);
        }
        return isset($this->resolved[$abstract]) || isset($this->instances[$abstract]);
    }

    public function isShared($abstract)
    {
        return isset($this->instances[$abstract]) || (isset($this->bindings[$abstract]['shared']) && $this->bindings[$abstract]['shared'] === true);
    }

    /**
     * Register a new resolving callback.
     *
     * @param  string $abstract
     * @param  \Closure|null $callback
     * @return void
     */
    public function resolving($abstract, Closure $callback = null)
    {
        if (is_string($abstract)) {
            $abstract = $this->getAlias($abstract);
        }
        if (is_null($callback) && $abstract instanceof Closure) {
            $this->globalResolvingCallbacks[] = $abstract;
        } else {
            $this->resolvingCallbacks[$abstract][] = $callback;
        }
    }

    /**
     * Register a new after resolving callback for all types.
     *
     * @param  string $abstract
     * @param  \Closure|null $callback
     * @return void
     */
    public function afterResolving($abstract, Closure $callback = null)
    {
        if (is_string($abstract)) {
            $abstract = $this->getAlias($abstract);
        }
        if ($abstract instanceof Closure && is_null($callback)) {
            $this->globalAfterResolvingCallbacks[] = $abstract;
        } else {
            $this->afterResolvingCallbacks[$abstract][] = $callback;
        }
    }

    public function makeWith($abstract, array $parameters)
    {
        return $this->resolve($abstract, $parameters);
    }

    protected function resolve($abstract, $parameters = [])
    {
        $abstract = $this->getAlias($abstract);
        // 判断实例化这个类是否需要其他一些有关联的类,如果$parameters非空或getContextualConcrete这个方法返回非空
        // 那么该变量就为true 这里所谓的关联并不是类本身的依赖 应该是逻辑上的关联
        $needsContextualBuild = !empty($parameters) || !is_null(
                $this->getContextualConcrete($abstract)
            );
        // 如果当前需要解析的type被定义为一个单例的话 先判断是否已经被实例化了 如果是那么直接返回这个实例
        // 在容器中已经被实例化的类会存储在instances数组中 这跟大部分框架中保存类实例的方式一样
        if (isset($this->instances[$abstract]) && !$needsContextualBuild) {
            return $this->instances[$abstract];
        }
        // 将parameters赋值给成员属性with 在实例化的时候会用到
        $this->with[] = $parameters;
        // 绑定的回调
        $concrete = $this->getConcrete($abstract);
        if ($this->isBuildable($concrete, $abstract)) {
            $object = $this->build($concrete);//调用build方法开始实例化这个
        } else {
            $object = $this->make($concrete);
        }
        // TODO 处理扩展 extend
        foreach ($this->getExtenders($abstract) as $extender) {
            $object = $extender($object, $this);
        }

        // 判断这个type是否是一个单例 如果在绑定的时候定义为单例的话 那么就将其保存在instances数组中
        // 后面其他地方再需要make它的时候直接从instances中取出即可  通过singleton方法绑定就属于单例
        if ($this->isShared($abstract) && !$needsContextualBuild) {
            $this->instances[$abstract] = $object;
        }
        $this->resolved[$abstract] = true;
        array_pop($this->with);
        return $object;
    }

    /**
     * 从$contextual(上下文)这个数组里获取已经实例化的对象
     *
     * @param  string $abstract
     * @return string|null
     */
    protected function getContextualConcrete($abstract)
    {
        if (!is_null($binding = $this->findInContextualBindings($abstract))) {
            return $binding;
        }
        if (empty($this->abstractAliases[$abstract])) {
            return;
        }
        foreach ($this->abstractAliases[$abstract] as $alias) {
            if (!is_null($binding = $this->findInContextualBindings($alias))) {
                return $binding;
            }
        }
    }

    /**
     * 在上下文绑定数组中查找给定抽象的具体绑定。
     *
     * @param  string $abstract
     * @return string|null
     */
    protected function findInContextualBindings($abstract)
    {
        if (isset($this->contextual[end($this->buildStack)][$abstract])) {
            return $this->contextual[end($this->buildStack)][$abstract];
        }
    }

    protected function hasParameterOverride($dependency)
    {
        return array_key_exists($dependency->name, $this->getLastParameterOverride());
    }

    protected function getLastParameterOverride()
    {
        return count($this->with) ? end($this->with) : [];
    }

    /**
     * 抛出未被实例化的异常
     * @param $concrete
     * @throws \Exception
     */
    protected function notInstantiable($concrete)
    {
        if (!empty($this->buildStack)) {
            $previous = implode(', ', $this->buildStack);
            $message = "Target [$concrete] is not instantiable while building [$previous].";
        } else {
            $message = "Target [$concrete] is not instantiable.";
        }
        throw new \Exception($message);
    }

    /**
     * 实例化对象
     * @param \Closure|string $concrete Closure or className
     * @return mixed|object
     * @throws \Exception
     * @throws \ReflectionException
     */
    public function build($concrete)
    {
        if ($concrete instanceof Closure) {
            // 匿名函数，闭包
            return $concrete($this, $this->getLastParameterOverride());
        }
        $reflector = new ReflectionClass($concrete);
        // isInstantiable 是否可实例化
        if (!$reflector->isInstantiable()) {
            $this->notInstantiable($concrete);
        }
        // 把待实例化的类名保存在buildStack数组当中 因为如果这个类有依赖的话 那么还需要实例化它全部的依赖
        $this->buildStack[] = $concrete;

        $constructor = $reflector->getConstructor();
        if (is_null($constructor)) {
            // 如果构造函数为空，将待构建堆栈buildStack中刚才插入那条数据删除
            array_pop($this->buildStack);
            return new $concrete;
        }
        $dependencies = $constructor->getParameters();
        // 获取依赖
        $instances = $this->resolveDependencies($dependencies);
        array_pop($this->buildStack);
        return $reflector->newInstanceArgs($instances);
    }

    /**
     * 依次解析这些依赖，如果依赖是对象的话，也就是去实例化这些类（从make开始）来获取对象
     * @param array $dependencies
     * @return array
     * @throws \Exception
     */
    protected function resolveDependencies(array $dependencies)
    {
        $results = [];
        /* @var $dependency \ReflectionParameter */
        foreach ($dependencies as $dependency) {
            // 判断这个依赖是否被重新定义过 也就是在resolve方法中执行的一句代码 $this->with[]=$parameters;
            // 判断的依据（也就是这个方法内部）就是这个with数组 在本例当中with中是两个空数组 因此判断为false
            if ($this->hasParameterOverride($dependency)) {
                $results[] = $this->getParameterOverride($dependency);
                continue;
            }
            $results[] = is_null($class = $dependency->getClass())
                ? $this->resolvePrimitive($dependency)
                : $this->resolveClass($dependency);
        }
        return $results;
    }

    /**
     * 默认生成实例的回调匿名函数（闭包）
     * @param $abstract 抽象的bind
     * @param $concrete 具体的class
     * @return Closure
     */
    protected function getClosure($abstract, $concrete)
    {
        return function ($c) use ($abstract, $concrete) {
            // $c 为当前Object
            $method = ($abstract == $concrete) ? 'build' : 'make';
            return $c->$method($concrete);
        };
    }

    /**
     * 是否可以build
     * @param $concrete
     * @param $abstract
     * @return bool
     */
    protected function isBuildable($concrete, $abstract)
    {
        return $concrete === $abstract || $concrete instanceof Closure;
    }

    /**
     * 获取绑定的回调函数(实例化对象)
     * @param $abstract
     * @return mixed
     */
    protected function getConcrete($abstract)
    {
        // 判断是否存在有关联关系的数据 如果有直接返回该数据
        if (!is_null($concrete = $this->getContextualConcrete($abstract))) {
            return $concrete;
        }
        // 如果上面没有被返回 那么就判断这个type在绑定到容器的时候有没有绑定一个concrete属性
        // 也就是一个回调 laravel的习惯是在绑定type的时候会提供一个Closure作为这个type实例化时的一些操作 比如最简单的就是 new xxx();
        if (!isset($this->bindings[$abstract])) {
            return $abstract;
        }
        return $this->bindings[$abstract]['concrete'];
    }

    /**
     * 实例化依赖对象
     * @param ReflectionParameter $parameter
     * @return object
     * @throws \Exception
     */
    protected function resolveClass(ReflectionParameter $parameter)
    {
        return $this->make($parameter->getClass()->name);
    }

    /**
     * 无法解析的依赖
     * @param ReflectionParameter $parameter
     * @return mixed|null|string
     * @throws \Exception
     */
    protected function resolvePrimitive(ReflectionParameter $parameter)
    {
        if (!is_null($concrete = $this->getContextualConcrete('$' . $parameter->name))) {
            return $concrete instanceof Closure ? $concrete($this) : $concrete;
        }
        // 检查是否有默认值
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }
        $this->unresolvablePrimitive($parameter);
    }

    /**
     * 如果不可解析，则抛出异常
     * @param ReflectionParameter $parameter
     * @throws \Exception
     */
    protected function unresolvablePrimitive(ReflectionParameter $parameter)
    {
        $message = "Unresolvable dependency resolving [$parameter] in class {$parameter->getDeclaringClass()->getName()}";
        throw new \Exception($message);
    }

    /**
     * 重新绑定 make 实例化
     * @param $abstract
     */
    protected function rebound($abstract)
    {
        $instance = $this->make($abstract);
        foreach ($this->getReboundCallbacks($abstract) as $callback) {
            call_user_func($callback, $this, $instance);
        }
    }

    protected function getReboundCallbacks($abstract)
    {
        if (isset($this->reboundCallbacks[$abstract])) {
            return $this->reboundCallbacks[$abstract];
        }

        return [];
    }

    /**
     * 递归获取alias
     * @param $abstract
     * @return mixed
     * @throws \Exception
     */
    public function getAlias($abstract)
    {
        if (!isset($this->aliases[$abstract])) {
            return $abstract;
        }
        if ($this->aliases[$abstract] === $abstract) {
            throw new LogicException("[{$abstract}] is aliased to itself.");
        }
        return $this->getAlias($this->aliases[$abstract]);
    }

    public function getBindings()
    {
        return $this->bindings;
    }

    /**
     * Get the extender callbacks for a given type.
     *
     * @param  string $abstract
     * @return array
     */
    protected function getExtenders($abstract)
    {
        $abstract = $this->getAlias($abstract);
        if (isset($this->extenders[$abstract])) {
            return $this->extenders[$abstract];
        }
        return [];
    }

    /**
     * Remove all of the extender callbacks for a given type.
     *
     * @param  string $abstract
     * @return void
     */
    public function forgetExtenders($abstract)
    {
        unset($this->extenders[$this->getAlias($abstract)]);
    }

    /**
     * 删除过时的instance.
     *
     * @param  string $abstract
     * @return void
     */
    protected function dropStaleInstances($abstract)
    {
        unset($this->instances[$abstract], $this->aliases[$abstract]);
    }

    public function forgetInstance($abstract)
    {
        unset($this->instances[$abstract]);
    }

    public function forgetInstances()
    {
        $this->instances = [];
    }

    public function flush()
    {
        $this->aliases = [];
        $this->resolved = [];
        $this->bindings = [];
        $this->instances = [];
        $this->abstractAliases = [];
    }

    public static function getInstance()
    {
        if (is_null(static::$instance)) {
            static::$instance = new static;
        }
        return static::$instance;
    }

    /**
     * Set the shared instance of the container.
     *
     * @param  \Hi\Interfaces\Container\Container|null $container
     * @return static
     */
    public static function setInstance(ContainerInterface $container = null)
    {
        return static::$instance = $container;
    }


    /**
     * Determine if a given offset exists.
     *
     * @param  string $key
     * @return bool
     */
    public function offsetExists($key)
    {
        return $this->bound($key);
    }

    /**
     * Get the value at a given offset.
     *
     * @param  string $key
     * @return mixed
     */
    public function offsetGet($key)
    {
        return $this->make($key);
    }

    /**
     * Set the value at a given offset.
     *
     * @param  string $key
     * @param  mixed $value
     * @return void
     */
    public function offsetSet($key, $value)
    {
        $this->bind($key, $value instanceof Closure ? $value : function () use ($value) {
            return $value;
        });
    }

    /**
     * Unset the value at a given offset.
     *
     * @param  string $key
     * @return void
     */
    public function offsetUnset($key)
    {
        unset($this->bindings[$key], $this->instances[$key], $this->resolved[$key]);
    }

    public function __get($key)
    {
        return $this[$key];
    }

    public function __set($key, $value)
    {
        $this[$key] = $value;
    }

}