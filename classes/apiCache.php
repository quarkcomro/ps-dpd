<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class DpdGeopostApiCache
{
    const NAMESPACE_DIR = 'dpdgeopost_api';
    const KEY_VERSION = 'v1';

    /** Per-path TTL in seconds. Longest-prefix match wins. */
    private static $ttlMap = array(
        'location/country' => 86400,
        'location/street'  => 3600,
        'location/site'    => 3600,
        'location/office'  => 1800,
        'calculate'        => 300,
    );

    /** Paths that MUST NEVER be cached (write ops, stateful reads). Prefix match. */
    private static $bypassPrefixes = array(
        'shipment',
        'track',
        'pickup',
    );

    public static function isCacheable($path)
    {
        $path = (string) $path;
        if ($path === '') {
            return false;
        }
        if (!self::isEnabled()) {
            return false;
        }
        foreach (self::$bypassPrefixes as $prefix) {
            if (self::pathMatches($path, $prefix)) {
                return false;
            }
        }

        return self::getTtl($path) > 0;
    }

    /** Admin toggle: defaults to enabled when the setting has never been saved. */
    public static function isEnabled()
    {
        if (!class_exists('Configuration') || !class_exists('DpdGeopostConfiguration')) {
            return true;
        }
        $value = Configuration::get(DpdGeopostConfiguration::API_CACHE_ENABLED);
        if ($value === false || $value === null || $value === '') {
            return true;
        }

        return (int) $value === 1;
    }

    public static function getTtl($path)
    {
        $path = (string) $path;
        $bestLen = 0;
        $bestTtl = 0;
        foreach (self::$ttlMap as $prefix => $ttl) {
            if (self::pathMatches($path, $prefix) && strlen($prefix) > $bestLen) {
                $bestLen = strlen($prefix);
                $bestTtl = (int) $ttl;
            }
        }

        return $bestTtl;
    }

    public static function buildKey($path, array $payload, $shopId)
    {
        $stable = json_encode(self::normalize($payload), JSON_UNESCAPED_SLASHES);
        if ($stable === false) {
            $stable = '';
        }

        return self::KEY_VERSION . '_' . (int) $shopId . '_' . sha1((string) $path . '|' . $stable);
    }

    public static function stripCredentials(array $payload)
    {
        unset($payload['userName'], $payload['password']);

        return $payload;
    }

    public static function get($key)
    {
        $file = self::filePath($key);
        if (!is_file($file)) {
            return false;
        }

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return false;
        }

        $envelope = json_decode($raw, true);
        if (!is_array($envelope) || !array_key_exists('data', $envelope) || !isset($envelope['exp'])) {
            return false;
        }

        if ((int) $envelope['exp'] <= time()) {
            @unlink($file);

            return false;
        }

        return $envelope['data'];
    }

    public static function set($key, $data, $ttl)
    {
        $ttl = (int) $ttl;
        if ($ttl <= 0) {
            return false;
        }

        $dir = self::keyDir($key);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $envelope = array(
            'exp' => time() + $ttl,
            'data' => $data,
        );
        $json = json_encode($envelope, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        $file = self::filePath($key);
        $tmp = @tempnam($dir, 'dpd_');
        if ($tmp === false) {
            return false;
        }
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);

            return false;
        }
        @chmod($tmp, 0664);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);

            return false;
        }

        self::maybeGc($dir);

        return true;
    }

    public static function clear()
    {
        $root = self::rootDir();
        if (!is_dir($root)) {
            return 0;
        }

        $removed = 0;
        foreach ((array) @scandir($root) as $shard) {
            if ($shard === '.' || $shard === '..') {
                continue;
            }
            $shardPath = $root . DIRECTORY_SEPARATOR . $shard;
            if (!is_dir($shardPath)) {
                continue;
            }
            foreach ((array) @scandir($shardPath) as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $filePath = $shardPath . DIRECTORY_SEPARATOR . $entry;
                if (is_file($filePath) && @unlink($filePath)) {
                    $removed++;
                }
            }
            @rmdir($shardPath);
        }

        return $removed;
    }

    /** Return quick stats for the admin UI: entries count and total size in bytes. */
    public static function stats()
    {
        $entries = 0;
        $bytes = 0;
        $root = self::rootDir();
        if (!is_dir($root)) {
            return array('entries' => 0, 'bytes' => 0);
        }

        foreach ((array) @scandir($root) as $shard) {
            if ($shard === '.' || $shard === '..') {
                continue;
            }
            $shardPath = $root . DIRECTORY_SEPARATOR . $shard;
            if (!is_dir($shardPath)) {
                continue;
            }
            foreach ((array) @scandir($shardPath) as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $filePath = $shardPath . DIRECTORY_SEPARATOR . $entry;
                if (!is_file($filePath)) {
                    continue;
                }
                $entries++;
                $size = @filesize($filePath);
                if ($size !== false) {
                    $bytes += (int) $size;
                }
            }
        }

        return array('entries' => $entries, 'bytes' => $bytes);
    }

    private static function pathMatches($path, $prefix)
    {
        $path = trim((string) $path, '/');
        $prefix = trim((string) $prefix, '/');

        return $path === $prefix || strpos($path, $prefix . '/') === 0;
    }

    private static function maybeGc($dir)
    {
        if (mt_rand(1, 100) !== 1) {
            return;
        }
        foreach ((array) @scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $file = $dir . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($file)) {
                continue;
            }
            $raw = @file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            $envelope = json_decode($raw, true);
            if (!is_array($envelope) || !isset($envelope['exp']) || (int) $envelope['exp'] <= time()) {
                @unlink($file);
            }
        }
    }

    private static function normalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($value !== array() && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }
        foreach ($value as $k => $v) {
            $value[$k] = self::normalize($v);
        }

        return $value;
    }

    private static function rootDir()
    {
        return rtrim(_PS_CACHE_DIR_, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . self::NAMESPACE_DIR;
    }

    private static function keyDir($key)
    {
        $shard = substr((string) $key, -2);
        if ($shard === '') {
            $shard = '00';
        }

        return self::rootDir() . DIRECTORY_SEPARATOR . $shard;
    }

    private static function filePath($key)
    {
        return self::keyDir($key) . DIRECTORY_SEPARATOR . (string) $key . '.json';
    }
}
