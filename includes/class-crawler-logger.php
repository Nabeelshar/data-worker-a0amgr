<?php
/**
 * Crawler Logger
 */

if (!defined('ABSPATH')) {
    exit;
}

class Crawler_Logger {
    
    private static $log_dir = null;
    
    /**
     * Initialize logger
     */
    private static function init() {
        if (self::$log_dir === null) {
            self::$log_dir = FICTIONEER_CRAWLER_PATH . 'logs/';
            
            // Create logs directory if it doesn't exist
            if (!file_exists(self::$log_dir)) {
                wp_mkdir_p(self::$log_dir);
            }
        }
    }
    
    /**
     * Log info message
     * 
     * @param string $message Message
     * @param array $context Context data
     */
    public static function info($message, $context = array()) {
        self::log('info', $message, $context);
    }
    
    /**
     * Log warning message
     * 
     * @param string $message Message
     * @param array $context Context data
     */
    public static function warning($message, $context = array()) {
        self::log('warning', $message, $context);
    }
    
    /**
     * Log error message
     * 
     * @param string $message Message
     * @param array $context Context data
     */
    public static function error($message, $context = array()) {
        self::log('error', $message, $context);
    }
    
    /**
     * Log message to both database and file
     * 
     * @param string $level Log level
     * @param string $message Message
     * @param array $context Context data
     */
    private static function log($level, $message, $context = array()) {
        // Log to database
        self::log_to_database($level, $message, $context);
        
        // Log to file
        self::log_to_file($level, $message, $context);
    }
    
    /**
     * Log to database
     * 
     * @param string $level Log level
     * @param string $message Message
     * @param array $context Context data
     */
    private static function log_to_database($level, $message, $context = array()) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'crawler_logs';
        
        $queue_id = isset($context['queue_id']) ? $context['queue_id'] : null;
        
        $wpdb->insert(
            $table_name,
            array(
                'queue_id' => $queue_id,
                'level' => $level,
                'message' => $message,
                'context' => maybe_serialize($context)
            ),
            array('%d', '%s', '%s', '%s')
        );
    }
    
    /**
     * Log to file
     * 
     * @param string $level Log level
     * @param string $message Message
     * @param array $context Context data
     */
    private static function log_to_file($level, $message, $context = array()) {
        self::init();
        
        $date = current_time('Y-m-d');
        $time = current_time('Y-m-d H:i:s');
        
        // Main log file
        $log_file = self::$log_dir . 'crawler-' . $date . '.log';
        
        // Format log entry
        $log_entry = sprintf(
            "[%s] [%s] %s\n",
            $time,
            strtoupper($level),
            $message
        );
        
        // Add context if present
        if (!empty($context)) {
            $log_entry .= "Context: " . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        }
        
        $log_entry .= str_repeat('-', 80) . "\n";
        
        // Write to main log
        @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
        
        // Write errors to separate error log
        if ($level === 'error') {
            $error_log_file = self::$log_dir . 'error-' . $date . '.log';
            @file_put_contents($error_log_file, $log_entry, FILE_APPEND | LOCK_EX);
        }
    }
    
    /**
     * Clear all logs from database
     */
    public static function clear_all() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'crawler_logs';
        
        return $wpdb->query("TRUNCATE TABLE $table_name");
    }
    
    /**
     * Get recent logs from database
     * 
     * @param int $limit Number of logs to retrieve
     * @return array Array of log entries
     */
    public static function get_recent($limit = 100) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'crawler_logs';
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT %d",
                $limit
            )
        );
    }
    
    /**
     * Get log files from directory
     * 
     * @return array Array of log file paths
     */
    public static function get_log_files() {
        self::init();
        
        $files = glob(self::$log_dir . '*.log');
        
        if (!$files) {
            return array();
        }
        
        // Sort by modification time, newest first
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        return $files;
    }
    
    /**
     * Read log file contents
     * 
     * @param string $filename Log filename
     * @param int $lines Number of lines to read (0 = all)
     * @return string|false File contents or false on failure
     */
    public static function read_log_file($filename, $lines = 0) {
        self::init();
        
        $file_path = self::$log_dir . basename($filename);
        
        if (!file_exists($file_path)) {
            return false;
        }
        
        if ($lines <= 0) {
            return file_get_contents($file_path);
        }
        
        // Read last N lines
        $file = new SplFileObject($file_path, 'r');
        $file->seek(PHP_INT_MAX);
        $last_line = $file->key();
        
        $start_line = max(0, $last_line - $lines);
        
        $content = array();
        for ($i = $start_line; $i <= $last_line; $i++) {
            $file->seek($i);
            $content[] = $file->current();
        }
        
        return implode('', $content);
    }
    
    /**
     * Clear old log files (older than 30 days)
     */
    public static function clear_old_files() {
        self::init();
        
        $files = glob(self::$log_dir . '*.log');
        $cutoff_time = time() - (30 * DAY_IN_SECONDS);
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff_time) {
                @unlink($file);
            }
        }
    }
    
    /**
     * Get log directory path
     * 
     * @return string Log directory path
     */
    public static function get_log_dir() {
        self::init();
        return self::$log_dir;
    }
    
    /**
     * Get log file size
     * 
     * @param string $filename Log filename
     * @return int|false File size in bytes or false on failure
     */
    public static function get_log_file_size($filename) {
        self::init();
        
        $file_path = self::$log_dir . basename($filename);
        
        if (!file_exists($file_path)) {
            return false;
        }
        
        return filesize($file_path);
    }
}
