<?php

class Router
{
    private $routes = [
        'GET' => [],
        'POST' => [],
        'PUT' => [],
        'PATCH' => [],
        'DELETE' => []
    ];

    private $middlewares = [];
    private $staticPath;
    private $routeGroupPrefix = '';

    public function get($route, $callback, $middleware = [])
    {
        $this->addRoute('GET', $route, $callback, $middleware);
    }

    public function post($route, $callback, $middleware = [])
    {
        $this->addRoute('POST', $route, $callback, $middleware);
    }

    public function put($route, $callback, $middleware = [])
    {
        $this->addRoute('PUT', $route, $callback, $middleware);
    }

    public function patch($route, $callback, $middleware = [])
    {
        $this->addRoute('PATCH', $route, $callback, $middleware);
    }

    public function delete($route, $callback, $middleware = [])
    {
        $this->addRoute('DELETE', $route, $callback, $middleware);
    }

    public function useStatic($path)
    {
        $this->staticPath = $path;
    }

    public function middleware($middleware, $routes = [])
    {
        if (empty($routes)) {
            $this->middlewares['global'][] = $middleware;
        } else {
            foreach ($routes as $route) {
                $this->middlewares[$route][] = $middleware;
            }
        }
    }

    public function group($prefix, $callback)
    {
        $this->routeGroupPrefix = $prefix;
        call_user_func($callback);
        $this->routeGroupPrefix = '';
    }

    private function addRoute($method, $route, $callback, $middleware = [])
    {
        $route = $this->routeGroupPrefix . $route;
        $this->routes[$method][$route] = ['callback' => $callback, 'middleware' => $middleware];
    }

    public function run()
    {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $method = $_SERVER['REQUEST_METHOD'];

        if ($this->staticPath && $this->serveStatic($uri)) {
            return;
        }

        if (!$this->isValidMethod($method)) {
            http_response_code(405);
            echo '405 Method Not Allowed';
            return;
        }

        if ($method === 'POST' && !$this->is_csrf_valid()) {
            http_response_code(403);
            echo "Invalid CSRF token";
            return;
        }

        $this->handleRequest($method, $uri);
    }

    private function isValidMethod($method)
    {
        return isset($this->routes[$method]);
    }

    private function handleRequest($method, $uri)
    {
        $matchedCallback = null;
        $matchedMiddleware = [];

        foreach ($this->routes[$method] as $route => $data) {
            $routeParts = explode('/', ltrim($route, '/'));
            $requestParts = explode('/', ltrim($uri, '/'));

            if (count($routeParts) !== count($requestParts)) {
                continue;
            }

            $params = [];
            $isMatch = true;

            foreach ($routeParts as $index => $routePart) {
                if (strpos($routePart, '{') === 0 && strpos($routePart, '}') === strlen($routePart) - 1) {
                    $paramName = trim($routePart, '{}');
                    $params[$paramName] = $requestParts[$index];
                } elseif ($routePart !== $requestParts[$index]) {
                    $isMatch = false;
                    break;
                }
            }

            if ($isMatch) {
                $matchedCallback = $data['callback'];
                $matchedMiddleware = $data['middleware'];
                break;
            }
        }

        if (!$matchedCallback) {
            http_response_code(404);
            $this->executeCallback('404');
            return;
        }

        $this->executeMiddleware($matchedMiddleware, $params, function () use ($matchedCallback, $params) {
            $this->executeCallback($matchedCallback, $params);
        });
    }

    private function executeCallback($callback, $params = [])
    {
        if (is_callable($callback)) {
            call_user_func_array($callback, $params);
        } else {
            header("Location: /$callback");
            exit;
        }
    }

    private function serveStatic($uri)
    {
        $filePath = realpath($this->staticPath . $uri);

        if ($filePath && is_file($filePath) && strpos($filePath, realpath($this->staticPath)) === 0) {
            $mimeType = $this->getMimeType($filePath);
            header('Content-Type: ' . $mimeType);
            readfile($filePath);
            return true;
        }

        return false;
    }

    private function getMimeType($filePath)
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);

        $mimeTypes = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'html' => 'text/html'
        ];

        return isset($mimeTypes[$extension]) ? $mimeTypes[$extension] : 'application/octet-stream';
    }

    private function executeMiddleware($middleware, $params, $next)
    {
        if (empty($middleware)) {
            call_user_func($next);
            return;
        }

        $middlewareFunc = array_shift($middleware);

        $middlewareFunc($params, function () use ($middleware, $params, $next) {
            $this->executeMiddleware($middleware, $params, $next);
        });
    }

    private function is_csrf_valid()
    {
        if (!isset($_SESSION['csrf']) || !isset($_POST['csrf'])) {
            return false;
        }
        if ($_SESSION['csrf'] !== $_POST['csrf']) {
            return false;
        }
        return true;
    }
}