<?php
declare(strict_types=1);

use Auth\Controllers\{AdminController, ApiController, AuthController, OAuthController};
use Auth\{Http, Session};

require __DIR__ . '/../src/bootstrap.php';

Http::headers();
set_exception_handler(static function (Throwable $e): void {
    error_log('ssb_auth: ' . $e);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'Something went wrong. Please try again later.';
});

$path = rtrim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/') ?: '/';
$method = Http::method();

if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path)) {
    return false;   // dev server: serve static assets
}

if ($method !== 'GET' && $method !== 'POST' && $method !== 'HEAD') {
    Http::render('error', ['title' => 'Not allowed', 'message' => 'Method not allowed.'], 405);
}

$exact = [
    '/'                    => [AuthController::class, 'home'],
    '/login'               => [AuthController::class, 'login'],
    '/logout'              => [AuthController::class, 'logout'],
    '/password'            => [AuthController::class, 'password'],
    '/apps'                => [AuthController::class, 'apps'],
    '/authorize'           => [OAuthController::class, 'authorize'],
    '/token'               => [OAuthController::class, 'token'],
    '/api/v1/users'                => [ApiController::class, 'create'],
    '/api/v1/users/password'       => [ApiController::class, 'changePassword'],
    '/api/v1/users/password-reset' => [ApiController::class, 'resetPassword'],
    '/admin'               => [AdminController::class, 'index'],
    '/admin/users'         => [AdminController::class, 'users'],
    '/admin/users/new'     => [AdminController::class, 'userNew'],
    '/admin/clients'       => [AdminController::class, 'clients'],
    '/admin/clients/new'   => [AdminController::class, 'clientNew'],
    '/admin/audit'         => [AdminController::class, 'audit'],
];
if (isset($exact[$path])) {
    [$cls, $fn] = $exact[$path];
    $cls::$fn();
}

$byId = [
    '#^/admin/users/(\d+)$#'          => 'userEdit',
    '#^/admin/users/(\d+)/reset$#'    => 'userReset',
    '#^/admin/clients/(\d+)$#'        => 'clientEdit',
    '#^/admin/clients/(\d+)/rotate$#' => 'clientRotate',
];
foreach ($byId as $re => $fn) {
    if (preg_match($re, $path, $m)) {
        AdminController::$fn((int)$m[1]);
    }
}

Http::render('error', ['title' => 'Not found', 'message' => 'That page does not exist.'], 404);
