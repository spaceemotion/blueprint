<?php

namespace Blueprint\Generators;

use Blueprint\Contracts\Generator;
use Blueprint\Models\Controller;
use Blueprint\Tree;
use Illuminate\Support\Str;
use Illuminate\Contracts\Routing\UrlGenerator;

class RouteGenerator implements Generator
{
    /** @var \Illuminate\Contracts\Filesystem\Filesystem */
    private $files;

    /** @var string */
    private $rootNamespace;

    public function __construct($files)
    {
        $this->files = $files;

        $this->rootNamespace = $this->determineRootNamespace(app(UrlGenerator::class));
    }

    public function output(Tree $tree): array
    {
        if (empty($tree->controllers())) {
            return [];
        }

        $routes = ['api' => '', 'web' => ''];

        /** @var \Blueprint\Models\Controller $controller */
        foreach ($tree->controllers() as $controller) {
            $type = $controller->isApiResource() ? 'api' : 'web';
            $routes[$type] .= PHP_EOL.PHP_EOL.$this->buildRoutes($controller);
        }

        $paths = [];

        foreach (array_filter($routes) as $type => $definitions) {
            $path = 'routes/'.$type.'.php';
            $this->files->append($path, $definitions.PHP_EOL);
            $paths[] = $path;
        }

        return ['updated' => $paths];
    }

    public function types(): array
    {
        return ['routes'];
    }

    protected function buildRoutes(Controller $controller)
    {
        $routes = '';
        $methods = array_keys($controller->methods());

        $useTuples = $this->rootNamespace === null || $this->rootNamespace === '';

        $className = $useTuples
            ? $controller->fullyQualifiedClassName() . '::class'
            : '\'' . str_replace($this->rootNamespace . '\\', '', $controller->fullyQualifiedClassName()) . '\'';

        $slug = Str::kebab($controller->prefix());

        $resource_methods = array_intersect($methods, Controller::$resourceMethods);
        if (count($resource_methods)) {
            $routes .= $controller->isApiResource()
                ? sprintf("Route::apiResource('%s', %s)", $slug, $className)
                : sprintf("Route::resource('%s', %s)", $slug, $className);

            $missing_methods = $controller->isApiResource()
                ? array_diff(Controller::$apiResourceMethods, $resource_methods)
                : array_diff(Controller::$resourceMethods, $resource_methods);

            if (count($missing_methods)) {
                if (count($missing_methods) < 4) {
                    $routes .= sprintf("->except('%s')", implode("', '", $missing_methods));
                } else {
                    $routes .= sprintf("->only('%s')", implode("', '", $resource_methods));
                }
            }

            $routes .= ';'.PHP_EOL;
        }

        $methods = array_diff($methods, Controller::$resourceMethods);
        foreach ($methods as $method) {
            if ($useTuples) {
                $action = "[{$className}, '{$method}']";
            } else {
                $classNameNoQuotes = trim($className, '\'');
                $action = "'{$classNameNoQuotes}@{$method}'";
            }

            $routes .= sprintf("Route::get('%s/%s', %s);", $slug, Str::kebab($method), $action);
            $routes .= PHP_EOL;
        }

        return trim($routes);
    }

    protected function determineRootNamespace(UrlGenerator $urlGenerator): ?string
    {
        return (function () {
            // While there is a setter, there is no getter for the root namespace
            // This closure acts like a subclass so the value can be retrieved
            return $this->rootNamespace;
        })->call($urlGenerator);
    }
}
