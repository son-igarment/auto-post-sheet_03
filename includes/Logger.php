<?php

class AutoPostLogger {
    public static function log($message, $context = 'core', $level = 'info') {
        $prefix = sprintf('%s [%s] %s: ', date('Y-m-d H:i:s'), 'AutoPostSheet', strtoupper($level));
        $line = $prefix . '(' . $context . ') ' . $message;
        error_log($line);

        // Additionally write to uploads dir file for easier retrieval
        if (function_exists('wp_upload_dir')) {
            $upload = wp_upload_dir();
            $dir = trailingslashit($upload['basedir']) . 'auto-post-sheet';
            if (!file_exists($dir)) {
                wp_mkdir_p($dir);
            }
            $file = trailingslashit($dir) . 'auto-post.log';
            @file_put_contents($file, $line . PHP_EOL, FILE_APPEND);
        }
    }
}

