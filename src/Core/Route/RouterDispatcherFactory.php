<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Core\Route;

use Hyperf\Di\ReflectionManager;
use Hyperf\HttpServer\Annotation\AutoController;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\DeleteMapping;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Annotation\Mapping;
use Hyperf\HttpServer\Annotation\PatchMapping;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Annotation\PutMapping;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\HttpServer\Router\DispatcherFactory;
use Hyperf\Collection\Arr;
use Hyperf\Stringable\Str;
use Dleno\CommonCore\Annotation\AllowMethods;
use ReflectionMethod;

use function Hyperf\Config\config;

class RouterDispatcherFactory extends DispatcherFactory
{
    protected function formatRoutePath($path)
    {
        $routePrefix = trim((string) config('app.route_prefix', ''), '/');
        if ($routePrefix) {
            $path = '/' . $routePrefix . $path;
        }
        //原 trim(config('app.route_suffix'), '') 有两问题:① 第二参空 mask 是 no-op(没起作用);
        //② 未设 route_suffix(共享库消费方常见)时 config 返 null → trim(null,...) 触发 deprecation。
        //改为 (string) + 默认 '':安全、行为等价(suffix 原样追加),无 deprecation。
        $path .= (string) config('app.route_suffix', '');
        return $path;
    }

    protected function getPrefix(string $className, string $prefix): string
    {
        if (!$prefix) {
            //自动路由时，保持命名一致（仅首字母小写）
            $handledNamespace = Str::replaceFirst('Controller', '', Str::after($className, '\\Controller\\'));
            $handledNamespace = str_replace('\\', '/', $handledNamespace);
            $handledNamespace = explode('/', trim($handledNamespace, '/'));
            array_walk(
                $handledNamespace,
                function (&$val) {
                    $val = lcfirst($val);
                }
            );
            $prefix = '/' . join('/', $handledNamespace);
        }
        //用 str_starts_with 代替 $prefix[0]:空串安全(无"Uninitialized string offset"警告)、同时处理空/无前导斜杠;
        //(实际上方 if(!$prefix) 块已保证 $prefix 至少为 '/'，此处仅为稳健。)
        if (!str_starts_with($prefix, '/')) {
            $prefix = '/' . $prefix;
        }

        return $prefix;
    }

    /**
     * Register route according to AutoController annotation.
     */
    protected function handleAutoController(
        string $className,
        AutoController $annotation,
        array $middlewares = [],
        array $methodMetadata = []
    ): void {
        $class   = ReflectionManager::reflectClass($className);
        $methods = $class->getMethods(ReflectionMethod::IS_PUBLIC);
        $prefix  = $this->getPrefix($className, $annotation->prefix);
        $router  = $this->getRouter($annotation->server);

        //类级默认请求方式(Hyperf AutoController(defaultMethods)，未设为 null)；逐方法在 resolveMethods 里按优先级解析。
        $classDefault  = $annotation->defaultMethods;
        $defaultAction = '/index';
        foreach ($methods as $method) {
            $options    = $annotation->options;
            $path       = $this->parsePath($prefix, $method);
            $methodName = $method->getName();
            if (substr($methodName, 0, 2) === '__') {
                continue;
            }

            //本方法允许的请求方式:方法 #[AllowMethods] → 类 defaultMethods → config → 默认；含 GET 自动补 HEAD。
            $routeMethods = $this->resolveMethods($method, $classDefault);

            $methodMiddlewares = $middlewares;
            // Handle method level middlewares.
            if (isset($methodMetadata[$methodName])) {
                $methodMiddlewares = array_merge(
                    $methodMiddlewares,
                    $this->handleMiddleware($methodMetadata[$methodName])
                );
            }

            // Rewrite by annotation @Middleware for Controller.
            $options['middleware'] = array_unique($methodMiddlewares);

            $path = $this->formatRoutePath($path);

            $router->addRoute($routeMethods, $path, [$className, $methodName], $options);

            if (Str::endsWith($path, $defaultAction)) {
                $path = Str::replaceLast($defaultAction, '', $path);
                $router->addRoute($routeMethods, $path, [$className, $methodName], $options);
            }
        }
    }

    /**
     * 解析某方法实际允许的 HTTP 请求方式。优先级(每级「为空」——null/空串/空数组/全空白——则继续回退)：
     *   ① 方法级 #[AllowMethods]  ② 类级 AutoController(defaultMethods)
     *   ③ config('app.default_allow_methods')  ④ ['POST','GET']
     * 解析结果含 GET 时自动补 HEAD(HEAD=无体 GET)。OPTIONS 预检由 InitMiddleware 全局处理，不在此注册。
     *
     * @param mixed $classDefault 类级 defaultMethods(数组/字符串/null)
     * @return string[]
     */
    protected function resolveMethods(ReflectionMethod $method, $classDefault): array
    {
        $candidates = [
            $this->methodAllowMethods($method),
            $classDefault,
            config('app.default_allow_methods'),
        ];
        $resolved = ['POST', 'GET'];
        foreach ($candidates as $cand) {
            $n = $this->normalizeMethods($cand);
            if ($n !== []) {
                $resolved = $n;
                break;
            }
        }
        if (in_array('GET', $resolved, true) && !in_array('HEAD', $resolved, true)) {
            $resolved[] = 'HEAD';
        }
        return $resolved;
    }

    /**
     * 读取方法上的 #[AllowMethods] 声明值(数组/字符串)；无则返回 null。
     * @return mixed
     */
    protected function methodAllowMethods(ReflectionMethod $method)
    {
        $attrs = $method->getAttributes(AllowMethods::class);
        return $attrs ? $attrs[0]->newInstance()->methods : null;
    }

    /**
     * 归一为「大写、去重、非空」的方法名数组；null/空串/空数组/全空白 → []；字符串支持逗号分隔。
     * @param mixed $val
     * @return string[]
     */
    protected function normalizeMethods($val): array
    {
        if (is_string($val)) {
            $val = trim($val) === '' ? [] : preg_split('/\s*,\s*/', trim($val), -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($val)) {
            return [];
        }
        $out = [];
        foreach ($val as $m) {
            $m = strtoupper(trim((string) $m));
            if ($m !== '') {
                $out[] = $m;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Register route according to Controller and XxxMapping annotations.
     * Including RequestMapping, GetMapping, PostMapping, PutMapping, PatchMapping, DeleteMapping.
     */
    protected function handleController(
        string $className,
        Controller $annotation,
        array $methodMetadata,
        array $middlewares = []
    ): void {
        if (!$methodMetadata) {
            return;
        }
        $prefix = $this->getPrefix($className, $annotation->prefix);
        $router = $this->getRouter($annotation->server);

        $mappingAnnotations = [
            RequestMapping::class,
            GetMapping::class,
            PostMapping::class,
            PutMapping::class,
            PatchMapping::class,
            DeleteMapping::class,
        ];

        foreach ($methodMetadata as $methodName => $values) {
            $options           = $annotation->options;
            $methodMiddlewares = $middlewares;
            // Handle method level middlewares.
            if (isset($values)) {
                $methodMiddlewares = array_merge($methodMiddlewares, $this->handleMiddleware($values));
            }

            // Rewrite by annotation @Middleware for Controller.
            $options['middleware'] = $methodMiddlewares;

            foreach ($mappingAnnotations as $mappingAnnotation) {
                /** @var Mapping $mapping */
                if ($mapping = $values[$mappingAnnotation] ?? null) {
                    if (! isset($mapping->methods) || ! isset($mapping->options)) {
                        continue;
                    }
                    $methodOptions = Arr::merge($options, $mapping->options);
                    // Rewrite by annotation @Middleware for method.
                    $methodOptions['middleware'] = $options['middleware'];

                    if (! isset($mapping->path)) {
                        $path = $prefix . '/' . Str::snake($methodName);
                    } elseif ($mapping->path === '') {
                        $path = $prefix;
                    } elseif ($mapping->path[0] !== '/') {
                        $path = rtrim($prefix, '/') . '/' . $mapping->path;
                    } else {
                        $path = $mapping->path;
                    }
                    $path = $this->formatRoutePath($path);

                    $router->addRoute($mapping->methods, $path, [$className, $methodName], $methodOptions);
                }
            }
        }
    }
}
