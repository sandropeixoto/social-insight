<?php

if (!function_exists('loadDotEnv')) {
    function loadDotEnv(string $directory): void
    {
        static $loaded = false;

        if ($loaded) {
            return;
        }

        $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.env';

        if (!is_file($path) || !is_readable($path)) {
            $loaded = true;
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            $loaded = true;
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $delimiterPosition = strpos($line, '=');

            if ($delimiterPosition === false) {
                continue;
            }

            $key = trim(substr($line, 0, $delimiterPosition));
            $value = trim(substr($line, $delimiterPosition + 1));

            if ($key === '') {
                continue;
            }

            if ($value !== '') {
                if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
                    $value = substr($value, 1, -1);
                } elseif (str_starts_with($value, "'") && str_ends_with($value, "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
            }

            if (!array_key_exists($key, $_SERVER)) {
                $_SERVER[$key] = $value;
            }

            putenv($key . '=' . $value);
        }

        $loaded = true;
    }
}

if (!function_exists('env')) {
    function env(string $key, $default = null)
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        return $value;
    }
}

if (!function_exists('env_bool')) {
    function env_bool(string $key, bool $default = false): bool
    {
        $value = env($key);

        if ($value === null) {
            return $default;
        }

        $boolean = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $boolean ?? $default;
    }
}

loadDotEnv(__DIR__);

// Determines if the application should run in debug mode.
if (!defined('APP_DEBUG')) {
    $envDebug = env('APP_DEBUG');
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

$dataDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'data';
$defaultLogPath = $dataDirectory . DIRECTORY_SEPARATOR . 'php-error.log';

if (is_dir($dataDirectory)) {
    ini_set('error_log', $defaultLogPath);
} else {
    ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'social_insight-php-error.log');
}
