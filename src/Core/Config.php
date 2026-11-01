<?php

namespace Sapient\Core;

use Dotenv\Dotenv;

class Config {
    private static $loaded = false;
    private static $settings = []; // Store explicitly provided config

    /**
     * Load environment variables from .env file.
     * @param string $path Directory containing .env
     */
    public static function load(string $path) {
        if (self::$loaded) return;

        $dotenv = Dotenv::createImmutable($path);
        $dotenv->safeLoad(); // safeLoad doesn't throw if file missing (good for prod env vars)
        self::$loaded = true;
    }

    /**
     * Inject custom configuration array.
     * Useful for integrating with frameworks (like Laravel) without polluting $_ENV.
     * @param array $config Key-value pairs of config settings.
     */
    public static function setConfig(array $config) {
        self::$settings = array_merge(self::$settings, $config);
    }

    /**
     * Override a single configuration setting dynamically.
     * @param string $key
     * @param mixed $value
     */
    public static function set(string $key, $value) {
        self::$settings[$key] = $value;
    }

    /**
     * Get a configuration variable.
     * Priority: 1. Injected settings 2. $_ENV 3. $_SERVER 4. Default
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null) {
        if (array_key_exists($key, self::$settings)) {
            return self::$settings[$key];
        }
        return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }

    /**
     * Get a boolean configuration variable.
     * Handles 'true', 'false', '1', '0', 'yes', 'no'.
     */
    public static function getBool(string $key, bool $default = false): bool {
        $val = self::get($key);
        if ($val === null) return $default;
        
        return filter_var($val, FILTER_VALIDATE_BOOLEAN);
    }
}
