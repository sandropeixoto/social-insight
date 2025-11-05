<?php

declare(strict_types=1);

// Determines if the application should run in debug mode.
if (!defined('APP_DEBUG')) {
    $envDebug = getenv('APP_DEBUG') ?: ($_ENV['APP_DEBUG'] ?? null);
    $queryDebug = null;

    if (PHP_SAPI !== 'cli') {
        $queryDebug = $_GET['debug'] ?? $_SERVER['HTTP_X_DEBUG'] ?? null;
    }

    $debug = null;

    if (is_string($queryDebug)) {
        $debug = filter_var($queryDebug, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    if ($debug === null && is_string($envDebug)) {
        $debug = filter_var($envDebug, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    define('APP_DEBUG', $debug ?? false);
}

if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
}

ini_set('log_errors', '1');

$defaultLogPath = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'php-error.log';

if (is_dir(__DIR__ . DIRECTORY_SEPARATOR . 'data')) {
    ini_set('error_log', $defaultLogPath);
} else {
    ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'social_insight-php-error.log');
}
