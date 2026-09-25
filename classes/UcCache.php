<?php
/**
 * A file cache for the labels (six hours), independent of whether the
 * shop has CACHE_SYSTEM on. One file per key under the PrestaShop cache
 * directory, or the system temp directory outside PrestaShop.
 */
final class UcCache
{
    public const TTL = 6 * 3600;

    /** The value, or false when absent or expired (a cached null is stored as ''). */
    public static function get(string $key)
    {
        $file = self::file($key);
        if (! is_file($file)) {
            return false;
        }
        $raw = @file_get_contents($file);
        $entry = $raw === false ? null : json_decode($raw, true);
        if (! is_array($entry) || ! isset($entry['expires']) || $entry['expires'] <= time()) {
            @unlink($file);

            return false;
        }

        return $entry['value'];
    }

    public static function set(string $key, $value, int $ttl = self::TTL): void
    {
        $file = self::file($key);
        @file_put_contents($file, json_encode(['expires' => time() + $ttl, 'value' => $value]), LOCK_EX);
    }

    public static function forget(string $key): void
    {
        @unlink(self::file($key));
    }

    public static function clear(): void
    {
        foreach (glob(self::dir().'*.json') ?: [] as $file) {
            @unlink($file);
        }
    }

    private static function file(string $key): string
    {
        return self::dir().md5($key).'.json';
    }

    private static function dir(): string
    {
        $base = defined('_PS_CACHE_DIR_') ? _PS_CACHE_DIR_ : sys_get_temp_dir().'/';
        $dir = rtrim($base, '/').'/uskladjenostcijena/';
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir;
    }
}
