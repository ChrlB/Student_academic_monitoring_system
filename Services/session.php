<?php

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

function startUserSession(array $userData): void{
    session_regenerate_id(true);
    $_SESSION['user'] = $userData;
    $_SESSION['logged_in_at'] = time();
}

function isLoggedIn(): bool{
    return isset($_SESSION['user']);
}

function terminateSession(): void{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

