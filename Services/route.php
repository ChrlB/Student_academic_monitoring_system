<?php
require_once __DIR__.'/../PageLogic/login_logic.php';
require_once __DIR__."/../Services/session.php";

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


$router->get('/', function(){
    header('Location: /login'); exit;
});
$router->get('/index.php', function(){
    header('Location: /login'); exit;
});


$router->get('/login',  fn() => require __DIR__ . '/../View/login.php');
$router->get('/dashboard', fn() => require __DIR__ . '/../View/dashboard.php');
$router->get('/class-sched',  fn() => require __DIR__ . '/../View/class_sched.php');
$router->get('/account',  fn() => require __DIR__ . '/../View/account.php');

$router->post('/login', fn() => login());
$router->post('/logout', fn() => logout());


$router->dispatch();