<?php
require_once __DIR__.'/../PageLogic/login_logic.php';
require_once __DIR__.'/../PageLogic/account_actions.php';   // changePassword(), updateProfile()
require_once __DIR__."/../Services/session.php";
require_once __DIR__."/../Services/imageHandler.php";
require_once __DIR__ . '/../Services/documentHandler.php';

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
        //     call_user_func($this->routes[$method][$path], $_REQUEST);
        // } else {
        //     http_response_code(404);
        //     echo "404 - Page Not Found";
        // }

        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $method = $_SERVER['REQUEST_METHOD'];

        if (BASE_PATH !== '' && strpos($path, BASE_PATH) === 0) {
            $path = substr($path, strlen(BASE_PATH));
        }
        if ($path === '' || $path === false) {
            $path = '/';
        }

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
    header('Location: '.BASE_PATH.'/login'); exit;
});
$router->get('/index.php', function(){
    header('Location: '.BASE_PATH.'/login'); exit;
});


$router->get('/login',  fn() => require __DIR__ . '/../View/login.php');

$router->get('/account',  fn() => require __DIR__ . '/../View/account.php');
$router->get('/attendance', fn() => require __DIR__ . '/../View/attendance.php');
$router->get('/class-sched',  fn() => require __DIR__ . '/../View/class_sched.php');
$router->post('/class-sched', fn() => require __DIR__ . '/../View/class_sched.php');
$router->get('/clearance',  fn() => require __DIR__ . '/../View/clearance.php');

// $router->post('/clearance',  fn($request) => require __DIR__ . '/../View/clearance.php');

$router->get('/dashboard', fn() => require __DIR__ . '/../View/dashboard.php');
$router->get('/enrollment',  fn() => require __DIR__ . '/../View/enrollment.php');

$router->get('/grades', fn() => require __DIR__ . '/../View/grades.php');
$router->post('/grades', fn() => require __DIR__ . '/../View/grades.php');
$router->get('/projects',  fn() => require __DIR__ . '/../View/projects.php');
$router->get('/syllabus',  fn() => require __DIR__ . '/../View/syllabus.php');

$router->get('/registration',  fn() => require __DIR__ . '/../View/registration.php');
$router->post('/registration',  fn($request) => require __DIR__ . '/../View/registration.php');

$router->post('/login', fn($request) => login($request));
$router->post('/logout', fn() => logout());    

// account popups (Change Password / Edit Profile). $_POST on purpose, not $_REQUEST.
$router->post('/change-password', fn() => changePassword($_POST));
$router->post('/edit-profile',    fn() => updateProfile($_POST));
 
$router->post('/upload-profile', fn() => uploadProfile());
$router->get('/get-profile', fn() => getProfilePic());

$router->post('/upload-document', fn($request) => uploadDocument($request));
$router->get('/download-document', fn() => downloadDocument());
$router->get('/view-document', fn() => require __DIR__ . '/../View/document.php');

$router->dispatch();