<?php

namespace Phillarmonic\SyncopateBundle\Util;

/**
 * Utility class to help with debugging memory issues and type errors
 */
class DebugHelper
{
    /**
     * Enable detailed debug mode with stack traces for errors
     */
    private static bool $debugMode = false;

    /**
     * Logging callback
     */
    private static $logCallback = null;

    /**
     * Enable debug mode
     */
    public static function enableDebug(): void
    {
        self::$debugMode = true;
    }

    /**
     * Set a logging callback
     */
    public static function setLogCallback(callable $callback): void
    {
        self::$logCallback = $callback;
    }

    /**
     * Log a message
     */
    public static function log(string $message, ?array $context = null): void
    {
        if (self::$logCallback !== null) {
            call_user_func(self::$logCallback, $message, $context);
        } else {
            error_log($message);
        }
    }

    /**
     * Deep check for problematic data types in an array
     *
     * @param array $data The data to check
     * @param string $path The current path for reporting
     * @return array List of problematic paths and their issues
     */
    public static function checkArrayForProblematicTypes(array $data, string $path = 'root'): array
    {
        $issues = [];

        foreach ($data as $key => $value) {
            $currentPath = $path . '.' . $key;

            if (is_array($value)) {
                // Recursively check nested arrays
                $nestedIssues = self::checkArrayForProblematicTypes($value, $currentPath);
                $issues = array_merge($issues, $nestedIssues);
            } elseif (is_object($value)) {
                if (!method_exists($value, '__toString')) {
                    $issues[] = [
                        'path' => $currentPath,
                        'type' => get_class($value),
                        'issue' => 'Object without __toString method',
                    ];
                }

                // Check if it's serializable
                try {
                    json_encode($value);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $issues[] = [
                            'path' => $currentPath,
                            'type' => get_class($value),
                            'issue' => 'Not JSON serializable: ' . json_last_error_msg(),
                        ];
                    }
                } catch (\Throwable $e) {
                    $issues[] = [
                        'path' => $currentPath,
                        'type' => get_class($value),
                        'issue' => 'Exception during serialization: ' . $e->getMessage(),
                    ];
                }
            } elseif (is_resource($value)) {
                $issues[] = [
                    'path' => $currentPath,
                    'type' => get_resource_type($value),
                    'issue' => 'Resource type cannot be serialized',
                ];
            }
        }

        return $issues;
    }

    /**
     * Report memory usage
     */
    public static function getMemoryUsage(): array
    {
        return [
            'current' => self::formatBytes(memory_get_usage()),
            'peak' => self::formatBytes(memory_get_peak_usage()),
            'current_real' => self::formatBytes(memory_get_usage(true)),
            'peak_real' => self::formatBytes(memory_get_peak_usage(true)),
        ];
    }

    /**
     * Format bytes to human-readable format
     */
    public static function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Clean and sanitize an array for safe JSON encoding
     */
    public static function sanitizeForJson(mixed $data): mixed
    {
        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $key => $value) {
                // Convert non-string keys to strings
                if (!is_string($key)) {
                    $key = (string)$key;
                }
                $sanitized[$key] = self::sanitizeForJson($value);
            }
            return $sanitized;
        } elseif (is_object($data)) {
            // Handle objects appropriately
            if ($data instanceof \DateTime || $data instanceof \DateTimeInterface) {
                return $data->format(\DateTimeInterface::ATOM);
            } elseif (method_exists($data, 'toArray')) {
                return self::sanitizeForJson($data->toArray());
            } elseif (method_exists($data, '__toString')) {
                return (string)$data;
            } else {
                // Try to convert to array
                try {
                    $array = (array)$data;
                    return self::sanitizeForJson($array);
                } catch (\Throwable $e) {
                    // Fallback to class name if all else fails
                    return '[Object: ' . get_class($data) . ']';
                }
            }
        } elseif (is_resource($data)) {
            // Resources can't be encoded
            return '[Resource: ' . get_resource_type($data) . ']';
        }

        // Handle scalar values
        if (is_string($data) || is_int($data) || is_float($data) || is_bool($data) || is_null($data)) {
            return $data;
        }

        // Unknown type, convert to string for safety
        return '[Unknown type: ' . gettype($data) . ']';
    }

    /**
     * Try to encode data as JSON and report any errors
     */
    public static function tryJsonEncode(mixed $data): ?string
    {
        try {
            $json = json_encode($data, JSON_THROW_ON_ERROR);
            return $json;
        } catch (\JsonException $e) {
            // If encoding failed, sanitize and try again
            $sanitized = self::sanitizeForJson($data);
            try {
                return json_encode($sanitized, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e2) {
                self::log('Failed to JSON encode data even after sanitization: ' . $e2->getMessage());
                return null;
            }
        }
    }

    /**
     * Fix the specific array to string conversion issue
     *
     * This method attempts to fix exactly the issue seen in your error
     */
    public static function fixArrayToStringConversion(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // Recursively fix nested arrays
                $result[$key] = self::fixArrayToStringConversion($value);
            } elseif (is_object($value)) {
                // Handle objects that might be used in string context
                if (method_exists($value, '__toString')) {
                    $result[$key] = (string)$value;
                } elseif (method_exists($value, 'toArray')) {
                    $result[$key] = $value->toArray();
                } else {
                    $result[$key] = '[Object: ' . get_class($value) . ']';
                }
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}