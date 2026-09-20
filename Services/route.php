<?php
class Router {
    private array $routes = [];

    public function get(string $path, callable $callback) {
        $this->routes['GET'][$path] = $callback;
    }

    public function post(string $path, callable $callback) {
        $this->routes['POST'][$path] = $callback;
    }

    public function dispatch() {
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $method = $_SERVER['REQUEST_METHOD'];

        if (isset($this->routes[$method][$path])) {
            call_user_func($this->routes[$method][$path]);
        } else {
            http_response_code(404);
            echo "404 - Page Not Found";
        }
    }
}

$router = new Router();

// Define routes
$router->get('/', function(){
    header('Location: /login');
    exit;
});

$router->get('/index.php', function(){
    header('Location: /login');
    exit;
});

$router->get('/login', function() {
    require __DIR__ . '/../View/login.php';
});

$router->get('/dashboard', function() {
    require __DIR__ . '/../View/dashboard.php';
});

// Run the router
$router->dispatch();