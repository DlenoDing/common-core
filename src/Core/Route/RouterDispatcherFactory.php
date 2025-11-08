<?php

declare(strict_types=1);

namespace Dleno\CommonCore\Core\Route;

use Hyperf\Di\Exception\ConflictAnnotationException;
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
use Hyperf\HttpServer\PriorityMiddleware;
use Hyperf\HttpServer\Router\DispatcherFactory;
use Hyperf\Collection\Arr;
use Hyperf\Stringable\Str;
use ReflectionMethod;

use function Hyperf\Config\config;

class RouterDispatcherFactory extends DispatcherFactory
{
    protected function formatRoutePath($path)
    {
        $routePerfix = trim(config('app.route_prefix', ''), '/');
        if ($routePerfix) {
            $path = '/' . $routePerfix . $path;
        }
        $path .= trim(config('app.route_suffix'), '');
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
        if ($prefix[0] !== '/') {
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

        $autoMethods   = config('app.ac_allow_methods') ?? ['POST', 'GET', 'HEAD'];
        $defaultAction = '/index';
        foreach ($methods as $method) {
            $options    = $annotation->options;
            $path       = $this->parsePath($prefix, $method);
            $methodName = $method->getName();
            if (substr($methodName, 0, 2) === '__') {
                continue;
            }

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

            $router->addRoute($autoMethods, $path, [$className, $methodName], $options);

            if (Str::endsWith($path, $defaultAction)) {
                $path = Str::replaceLast($defaultAction, '', $path);
                $router->addRoute($autoMethods, $path, [$className, $methodName], $options);
            }
        }
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
