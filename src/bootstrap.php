<?php
declare(strict_types=1);

namespace Auth;

define('AUTH_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Auth\\')) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, 5));
    $file = AUTH_ROOT . '/src/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});

Config::load(AUTH_ROOT . '/config/config.php');
date_default_timezone_set('UTC');
