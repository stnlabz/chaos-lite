<?php
/*
 * /app/core/utility.php
 * Miscellaneous functions for a site.
*/

namespace app\core;

class utility {
	
    public function json_read() {
	    $root_path = dirname(__DIR__);
	    //$file = $root_path . '/data/site_settings.json';
	    $file = rtrim(DATA_PATH, '/\\') . '/site_settings.json';
	    $raw = @file_get_contents($file);
	    $data = json_decode($raw, true);
	    return $data;
    }

    public function get_settings(): array {
	     $path = rtrim(DATA_PATH, '/\\') . '/site_settings.json';
         $data = $this->json_read($path, []);

        //$data = $this->json_read();
        if (!is_array($data)) {
            return [];
        }
        return $data;
    }

    public static function redirect_to($url) {
        header('Location: '. $url);
	    exit;
    }

    public function load_file($file) {
        if (is_file($file)) {
            include $file;
        }
        else {
                $this->pretty_error("Missing file: <code>$path</code>");
                exit;
        }
    }
    
    function serve_json_file(string $file): void {
        if (is_file($file)) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-cache, must-revalidate');
            echo file_get_contents($file);
            exit; // hard stop, no theme/bootstrap leaks
        }
    }
    
    public function pretty_error($message) {
            echo "<div style='
                background: #1e1e1e;
                color: #f88;
                padding: 1.5em;
                border: 2px solid #f00;
                font-family: monospace;
                margin: 2em;
                border-radius: 10px;
            '><strong>Error:</strong><br>$message</div>";
        
            $log_file = APP_ROOT . '/logs/site_errors.log'; // ?? This was missing!
        
            $log_line = "[" . date('Y-m-d H:i:s') . "] $message\n";
        
            if (file_exists($log_file) && filesize($log_file) > 1024 * 1024) { // 1MB
                rename($log_file, $log_file . '.' . time());
            }
        
            file_put_contents($log_file, $log_line, FILE_APPEND); // ?? Was missing semicolon
        }
        
    	public function throw_error($code = 500, $message = 'Unknown Error') {
            http_response_code($code);
        
            $friendly = [
                400 => 'Bad Request',
                403 => 'Forbidden',
                404 => 'Not Found',
                500 => 'Internal Server Error',
                503 => 'Service Unavailable'
            ];
        
            $title = $friendly[$code] ?? 'Error';
            pretty_error("[$code] $title: $message");
        
            // Optional: Log it
            $log_line = "[" . date('Y-m-d H:i:s') . "] [$code] $title — $message\n";
            @file_put_contents(APP_ROOT . '/logs/site_errors.log', $log_line, FILE_APPEND);
        
            exit;
        }
}