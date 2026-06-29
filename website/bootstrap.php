<?php

declare(strict_types=1);

if (!defined('WEBSITE_ROOT')) {
    define('WEBSITE_ROOT', __DIR__);
}

require_once WEBSITE_ROOT . '/src/helpers.php';

load_env(WEBSITE_ROOT . '/.env');

$config = require WEBSITE_ROOT . '/config/app.php';

assert_config_security($config);

date_default_timezone_set((string) ($config['timezone'] ?? 'America/Edmonton'));

spl_autoload_register(static function (string $class): void {
    $prefix = 'Starlink\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = WEBSITE_ROOT . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require $path;
    }
});

Starlink\Auth\Session::start($config);

(new Starlink\Auth\RememberMeService())->tryRestore();

send_security_headers();

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $csrfPath = '/' . trim($csrfPath, '/');
    if ($csrfPath !== '/') {
        $csrfPath = rtrim($csrfPath, '/');
    }

    if ($csrfPath !== '/webhooks/square') {
        csrf_validate_or_abort();
    }
}

return $config;
