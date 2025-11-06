<?php

class AutoPostCache {
    private static function key($key) {
        // Prefix and hash to avoid transient key length issues
        return 'aps_' . md5($key);
    }

    public static function get($key) {
        if (function_exists('get_transient')) {
            return get_transient(self::key($key));
        }
        return false;
    }

    public static function set($key, $value, $ttl = 60) {
        if (function_exists('set_transient')) {
            // Store serialized value
            return set_transient(self::key($key), $value, max(0, intval($ttl)));
        }
        return false;
    }

    public static function delete($key) {
        if (function_exists('delete_transient')) {
            return delete_transient(self::key($key));
        }
        return false;
    }

    public static function get_buster() {
        if (function_exists('get_option')) {
            $v = get_option('auto_post_sheet_cache_buster');
            if (!$v) {
                $v = 1;
                update_option('auto_post_sheet_cache_buster', $v);
            }
            return intval($v);
        }
        return 1;
    }

    public static function bump_buster() {
        if (function_exists('get_option')) {
            $v = intval(get_option('auto_post_sheet_cache_buster'));
            if ($v <= 0) $v = 1;
            update_option('auto_post_sheet_cache_buster', $v + 1);
        }
    }
}

