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
        // $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        // $method = $_SERVER['REQUEST_METHOD'];

        // if (isset($this->routes[$method][$path])) {
        //     call_user_func($this->routes[$method][$path]);
        // } else {
        //     http_response_code(404);
        //     echo "404 - Page Not Found";
        // }

        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $method = $_SERVER['REQUEST_METHOD'];

        if (isset($this->routes[$method][$path])) {
            call_user_func($this->routes[$method][$path], $_REQUEST);
        } else {
            http_response_code(404);
            echo "404 - Page Not Found";
        }
    }
}

$router = new Router();


$router->get('/', function(){
    header('Location: /student/login'); exit;
});
$router->get('/index.php', function(){
    header('Location: /student/login'); exit;
});


$router->get('/student/login',  fn() => require __DIR__ . '/../View/student/login.php');
$router->get('/student/dashboard', fn() => require __DIR__ . '/../View/student/dashboard.php');
$router->get('/student/class-sched',  fn() => require __DIR__ . '/../View/student/class_sched.php');
$router->get('/student/account',  fn() => require __DIR__ . '/../View/student/account.php');


// $router->get('/student/login',        function(){ require __DIR__ . '/../View/student/login.php';});
// $router->get('/dashboard',            function(){require_once __DIR__.'/auth.php'; navigateToDashboard();});
// $router->get('/student/class-sched',  function(){require_once __DIR__.'/auth.php'; require __DIR__ . '/../View/student/class_sched.php';});
// $router->get('/student/account',      function(){require_once __DIR__.'/auth.php'; require __DIR__ . '/../View/student/account.php';});

$router->get('/faculty/login',  fn() => require __DIR__ . '/../View/faculty/faculty_login.php');


$router->post('/login', fn($request) => login($request));        
$router->post('/faculty/login', fn($request) => login($request));


$router->post('/logout', fn() => logout());    
 

$router->dispatch();