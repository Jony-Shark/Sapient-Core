<?php

namespace Sapient\Core\Utils;

use Sapient\Contracts\Core\StateInterface;

class TemplateRenderer {
    /**
     * Renders a template by replacing {{ key }} or {{ key.subkey }} tags with values.
     * Supports default values using the syntax: {{ key || "default value" }}.
     *
     * @param string $template The template string with {{ key }} placeholders.
     * @param array|StateInterface $data The data source to resolve keys from.
     * @return string The rendered string.
     */
    public static function render(string $template, $data): string {
        return preg_replace_callback('/\{\{\s*([\w\.]+)(?:\s*\|\|\s*(.*?))?\s*\}\}/', function ($matches) use ($data) {
            $key = $matches[1];
            $default = (isset($matches[2]) && $matches[2] !== '') ? trim($matches[2], '"\'') : null;
            $val = self::resolveValue($data, $key);

            if ($val === null) {
                return $default !== null ? $default : ""; // Default to fallback or empty string if missing
            }

            if (is_array($val)) {
                return json_encode($val, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
            
            return (string)$val;
        }, $template);
    }

    /**
     * Resolves a key (including dot-notation) from a StateInterface or array.
     *
     * @param array|StateInterface $data
     * @param string $key
     * @return mixed
     */
    private static function resolveValue($data, string $key) {
        if ($data instanceof StateInterface) {
            if ($data->has($key)) {
                return $data->get($key);
            }
            if (str_contains($key, '.')) {
                $segments = explode('.', $key);
                $root = $data->get(array_shift($segments));
                return self::traverseSegments($root, $segments);
            }
        } elseif (is_array($data)) {
            if (array_key_exists($key, $data)) {
                return $data[$key];
            }
            if (str_contains($key, '.')) {
                return self::traverseSegments($data, explode('.', $key));
            }
        }

        return null;
    }

    /**
     * Traverses nested array segments and returns the resolved value or null.
     *
     * @param mixed $current
     * @param string[] $segments
     * @return mixed
     */
    private static function traverseSegments($current, array $segments) {
        foreach ($segments as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
            } else {
                return null;
            }
        }
        return $current;
    }
}
