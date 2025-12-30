<?php
namespace app\core;

class logger {
    protected $log_dir;

    public function __construct($log_dir = null) {
        $this->log_dir = $log_dir ?? '../../logs';
        if (!is_dir($this->log_dir)) {
            mkdir($this->log_dir, 0755, true);
        }
    }

    public function error($message, $class_name = 'general') {
        $this->write_log('ERROR', $message, $class_name);
    }

    public function info($message, $class_name = 'general') {
        $this->write_log('INFO', $message, $class_name);
    }

    public function debug($message, $class_name = 'general') {
        $this->write_log('DEBUG', $message, $class_name);
    }

    protected function write_log($level, $message, $class_name) {
        $filename = $this->log_dir . '/' . strtolower($class_name) . '.log';
        $time = date('Y-m-d H:i:s');
        $entry = "[$time][$level] $message" . PHP_EOL;
        file_put_contents($filename, $entry, FILE_APPEND);
    }
}
