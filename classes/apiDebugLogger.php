<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class DpdGeopostApiDebugLogger
{
    const LOG_DIRECTORY = 'logs' . DIRECTORY_SEPARATOR . 'api';
    const LOG_PREFIX = 'api-';
    const LOG_SUFFIX = '.log';
    const DEFAULT_RETENTION_DAYS = 5;
    const MAX_RETENTION_DAYS = 365;

    /** Flag guarding against repeated prune attempts within a single request. */
    private static $prunedThisRequest = false;

    public static function isEnabled()
    {
        return (bool) Configuration::get(DpdGeopostConfiguration::DEBUG_MODE);
    }

    /** Returns the configured retention window. 0 = keep forever. */
    public static function getRetentionDays()
    {
        if (!class_exists('Configuration') || !class_exists('DpdGeopostConfiguration')) {
            return self::DEFAULT_RETENTION_DAYS;
        }
        $raw = Configuration::get(DpdGeopostConfiguration::DEBUG_LOG_RETENTION_DAYS);
        if ($raw === false || $raw === null || $raw === '') {
            return self::DEFAULT_RETENTION_DAYS;
        }
        $days = (int) $raw;
        if ($days < 0) {
            return 0;
        }
        if ($days > self::MAX_RETENTION_DAYS) {
            return self::MAX_RETENTION_DAYS;
        }

        return $days;
    }

    public static function write(array $entry)
    {
        if (!self::isEnabled()) {
            return false;
        }

        if (!self::ensureDirectory()) {
            return false;
        }

        $entry['logged_at'] = date('c');
        $json = json_encode(self::sanitize($entry), JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = json_encode(array(
                'logged_at' => date('c'),
                'error' => 'Unable to encode debug log entry.',
            ));
        }

        $result = file_put_contents(self::getFilePath(), $json . PHP_EOL, FILE_APPEND) !== false;
        self::maybePrune();

        return $result;
    }

    /** Delete log files older than the configured retention window. Returns the number removed. */
    public static function prune()
    {
        $days = self::getRetentionDays();
        if ($days <= 0) {
            return 0;
        }

        $directory = self::getDirectory();
        if (!is_dir($directory)) {
            return 0;
        }

        $cutoff = strtotime(sprintf('-%d days', $days));
        if ($cutoff === false) {
            return 0;
        }
        // Compare against midnight of the cutoff day so a "5 days" window keeps today
        // plus the previous 4 calendar dates (log filenames use date stamps, not timestamps).
        $cutoffDay = strtotime(date('Y-m-d', $cutoff));
        if ($cutoffDay === false) {
            return 0;
        }

        $pattern = $directory . DIRECTORY_SEPARATOR . self::LOG_PREFIX . '*' . self::LOG_SUFFIX;
        $filenameRegex = '/^' . preg_quote(self::LOG_PREFIX, '/') . '(\d{4}-\d{2}-\d{2})' . preg_quote(self::LOG_SUFFIX, '/') . '$/';

        $removed = 0;
        foreach ((array) @glob($pattern) as $filePath) {
            $name = basename((string) $filePath);
            if (!preg_match($filenameRegex, $name, $matches)) {
                continue;
            }
            $fileDay = strtotime($matches[1]);
            if ($fileDay === false) {
                continue;
            }
            if ($fileDay < $cutoffDay && @unlink($filePath)) {
                $removed++;
            }
        }

        return $removed;
    }

    /** Opportunistic wrapper: runs at most once per request, at most once per calendar day. */
    public static function maybePrune()
    {
        if (self::$prunedThisRequest) {
            return;
        }
        self::$prunedThisRequest = true;

        if (self::getRetentionDays() <= 0) {
            return;
        }

        if (!class_exists('Configuration') || !class_exists('DpdGeopostConfiguration')) {
            return;
        }

        $today = date('Y-m-d');
        $last = (string) Configuration::get(DpdGeopostConfiguration::DEBUG_LOG_LAST_PRUNED);
        if ($last === $today) {
            return;
        }

        self::prune();
        Configuration::updateValue(DpdGeopostConfiguration::DEBUG_LOG_LAST_PRUNED, $today);
    }

    public static function writeCacheEvent($operation, $method, array $payload, $cacheKey, array $context = array())
    {
        $payloadJson = json_encode(self::sanitize($payload), JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false) {
            $payloadJson = '';
        }

        return self::write(array(
            'event' => 'payload_cache_' . (string) $operation,
            'method' => (string) $method,
            'path' => 'cache/' . (string) $method,
            'origin' => array(
                'summary' => !empty($context['source']) ? (string) $context['source'] : '',
            ),
            'cache' => array(
                'operation' => (string) $operation,
                'type' => 'payload',
                'key' => (string) $cacheKey,
            ),
            'request' => array(
                'payload_raw' => $payload,
                'payload_json' => $payloadJson,
            ),
            'response' => array(
                'http_code' => 'CACHE',
                'cached' => true,
            ),
            'timing' => array(
                'response_time_ms' => 0,
            ),
            'meta' => $context,
        ));
    }

    public static function listAvailableDates()
    {
        $directory = self::getDirectory();
        if (!is_dir($directory)) {
            return array();
        }

        $dates = array();
        foreach (glob($directory . DIRECTORY_SEPARATOR . self::LOG_PREFIX . '*' . self::LOG_SUFFIX) as $filePath) {
            $fileName = basename($filePath);
            if (preg_match('/^' . preg_quote(self::LOG_PREFIX, '/') . '(\d{4}-\d{2}-\d{2})' . preg_quote(self::LOG_SUFFIX, '/') . '$/', $fileName, $matches)) {
                $dates[] = $matches[1];
            }
        }

        rsort($dates);

        return $dates;
    }

    public static function readByDate($date)
    {
        $normalizedDate = self::normalizeDate($date);
        if ($normalizedDate === null) {
            return '';
        }

        $filePath = self::getFilePath($normalizedDate);
        if (!is_file($filePath)) {
            return '';
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return '';
        }

        return $content;
    }

    private static function sanitize($value, $key = '')
    {
        if (is_array($value)) {
            $sanitized = array();
            foreach ($value as $childKey => $childValue) {
                $sanitized[$childKey] = self::sanitize($childValue, (string) $childKey);
            }

            return $sanitized;
        }

        if (is_object($value)) {
            return self::sanitize((array) $value, $key);
        }

        if (is_string($key) && in_array(strtolower($key), array('password', 'ws_password'), true)) {
            return '***';
        }

        return $value;
    }

    private static function ensureDirectory()
    {
        $directory = self::getDirectory();
        if (is_dir($directory)) {
            return true;
        }

        return @mkdir($directory, 0775, true);
    }

    private static function getDirectory()
    {
        return _DPDGEOPOST_MODULE_DIR_ . self::LOG_DIRECTORY;
    }

    private static function getFilePath($date = null)
    {
        $normalizedDate = self::normalizeDate($date);
        if ($normalizedDate === null) {
            $normalizedDate = date('Y-m-d');
        }

        return self::getDirectory() . DIRECTORY_SEPARATOR . self::LOG_PREFIX . $normalizedDate . self::LOG_SUFFIX;
    }

    private static function normalizeDate($date)
    {
        if ($date === null || $date === '') {
            return null;
        }

        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        return $date;
    }
}
