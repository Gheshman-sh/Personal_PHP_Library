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

    public function get($route, $callback)
    {
        $this->addRoute('GET', $route, $callback);
    }

    public function post($route, $callback)
    {
        $this->addRoute('POST', $route, $callback);
    }

    public function put($route, $callback)
    {
        $this->addRoute('PUT', $route, $callback);
    }

    public function patch($route, $callback)
    {
        $this->addRoute('PATCH', $route, $callback);
    }

    public function delete($route, $callback)
    {
        $this->addRoute('DELETE', $route, $callback);
    }

    public function any($route, $callback)
    {
        $this->get($route, $callback);
        $this->post($route, $callback);
        $this->put($route, $callback);
        $this->patch($route, $callback);
        $this->delete($route, $callback);
    }

    public function use($middleware)
    {
        $this->middlewares[] = $middleware;
    }

    private function addRoute($method, $route, $callback)
    {
        $pattern = preg_replace('/\$([a-zA-Z0-9_]+)/', '(?<$1>[^\/]+)', $route);
        $this->routes[$method][$pattern] = $callback;
    }

    private function handleRequest($method, $route)
    {
        foreach ($this->middlewares as $middleware) {
            if (!$middleware()) {
                return;
            }
        }

        $matchedCallback = null;

        foreach ($this->routes[$method] as $registeredRoute => $callback) {
            $pattern = preg_replace('/\$([a-zA-Z0-9_]+)/', '(?<$1>[^\/]+)', $registeredRoute);
            $pattern = str_replace('/', '\/', $pattern);
            $pattern = "/^" . $pattern . "$/";

            if (preg_match($pattern, $route, $matches)) {
                $matchedCallback = $callback;
                foreach ($matches as $key => $value) {
                    if (!is_numeric($key)) {
                        $_GET[$key] = $value;
                    }
                }
                break;
            }
        }

        if ($matchedCallback) {
            if (is_callable($matchedCallback)) {
                call_user_func($matchedCallback);
            } else {
                include_once __DIR__ . "/$matchedCallback.php";
            }
        } else {
            http_response_code(404);
            redirect('/404');
        }
    }

    public function run()
    {
        $uri = $_SERVER['REQUEST_URI'];
        $method = $_SERVER['REQUEST_METHOD'];

        if ($_SERVER['REQUEST_METHOD'] == 'POST' && !is_csrf_valid()) {
            http_response_code(403);
            echo "Invalid CSRF token";
            return;
        }

        $this->handleRequest($method, $uri);
    }
}

function set_csrf()
{
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(50));
    }
    echo '<input type="hidden" name="csrf" value="' . $_SESSION['csrf'] . '">';
}

function is_csrf_valid()
{
    if (!isset($_SESSION['csrf']) || !isset($_POST['csrf'])) {
        return false;
    }
    if ($_SESSION['csrf'] !== $_POST['csrf']) {
        return false;
    }
    return true;
}

function render($view, $data = [])
{
    extract($data);

    ob_start();

    include_once dirname(__DIR__) . "/views/$view";

    $content = ob_get_clean();

    return $content;
}


function redirect($path)
{
    return header("Location: $path");
}

function authMiddleware()
{
    if (!isset($_SESSION['user'])) {
        http_response_code(401);
        echo "Unauthorized";
        return false;
    }
    return true;
}

function say($msg)
{
    echo "<script>console.log('$msg')</script>";
}
