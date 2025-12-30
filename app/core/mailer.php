<?php
namespace app\core;

class mailer {
    private string $host;
    private int $port;
    private ?string $secure; // 'tls' | 'ssl' | null
    private string $user;
    private string $pass;
    private string $fromEmail;
    private string $fromName;
    private int $timeout;
    private $sock = null;
    private string $err = '';
    private bool $debug;
    private string $logFile;
    private int $logMaxBytes; // rotate threshold
    private int $logKeep;     // how many archives to keep
    private bool $bccSelf;

    // connection robustness
    private int $connectRetries;
    private int $connectBackoffMs;

    public function __construct(array $cfg) {
        $this->host   = (string)($cfg['host']   ?? '');
        $this->port   = (int)   ($cfg['port']   ?? 587);
        $this->secure =             $cfg['secure'] ?? 'tls';
        $this->user   = (string)($cfg['user']   ?? '');
        $this->pass   = (string)($cfg['pass']   ?? '');
        $from         =             $cfg['from']   ?? ['no-reply@localhost','Mailer'];
        $this->fromEmail = is_array($from) ? ($from[0] ?? 'no-reply@localhost') : (string)$from;
        $this->fromName  = is_array($from) ? ($from[1] ?? '')                   : '';
        $this->timeout = (int)($cfg['timeout'] ?? 60);

        $this->debug   = (bool)($cfg['debug']   ?? false);
        $this->logFile =          $cfg['logFile'] ?? (__DIR__ . '/../logs/mailer.log');
        $this->logMaxBytes = (int)($cfg['logMaxBytes'] ?? 1024*1024);
        $this->logKeep     = max(1, (int)($cfg['logKeep'] ?? 5));
        $this->bccSelf     = (bool)($cfg['bccSelf'] ?? false);

        $this->connectRetries   = (int)($cfg['connectRetries']   ?? 3);
        $this->connectBackoffMs = (int)($cfg['connectBackoffMs'] ?? 500);
    }

    public function last_error(): string { return $this->err; }

    public function send($to, string $subject, string $body, bool $isHtml=false): bool {
        $rcpts = is_array($to) ? array_values($to) : [$to];
        $rcpts = array_filter(array_map('trim', $rcpts), fn($v)=>$v!=='');
        if (!$this->host || !$rcpts) return $this->fail('missing host or recipients');

        try {
            // connect + read banner with retries (handles greet-pause/tarpit)
            $attempt = 0;
            while (true) {
                $attempt++;
                try {
                    $this->connect();
                    $this->readMulti(220); // consume ALL 220- lines
                    break;
                } catch (\Throwable $e) {
                    $this->log("Banner attempt {$attempt} failed: ".$e->getMessage());
                    $this->close();
                    if ($attempt >= $this->connectRetries) throw $e;
                    usleep($this->connectBackoffMs * 1000);
                }
            }

            // EHLO + capabilities
            $this->cmd("EHLO ".$this->domain());
            $caps = $this->readMulti(250);

            // STARTTLS if requested and not already encrypted
            if ($this->secure === 'tls' && stripos($caps,'STARTTLS') !== false && !$this->encrypted()) {
                $this->cmd("STARTTLS"); $this->expect(220);
                if (!stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    return $this->fail('STARTTLS failed');
                }
                $this->cmd("EHLO ".$this->domain());
                $caps = $this->readMulti(250);
            }

            // AUTH (PLAIN preferred if available, else LOGIN)
            if ($this->user !== '' && $this->pass !== '') {
                if (stripos($caps,'AUTH') !== false && stripos($caps,'PLAIN') !== false) {
                    $this->cmd("AUTH PLAIN ".base64_encode("\0{$this->user}\0{$this->pass}"));
                    $this->expect(235);
                } else {
                    $this->cmd("AUTH LOGIN");    $this->expect(334);
                    $this->cmd(base64_encode($this->user)); $this->expect(334);
                    $this->cmd(base64_encode($this->pass)); $this->expect(235);
                }
            }

            // Envelope
            $this->cmd("MAIL FROM:<".$this->addr($this->fromEmail).">"); $this->expect(250);
            foreach ($rcpts as $r) {
                $this->cmd("RCPT TO:<".$this->addr($r).">");
                $this->expect([250,251]);
            }

            // DATA
            $this->cmd("DATA"); $this->expect(354);

            // Headers
            $headers = [];
            $fromHdr = $this->fromName
                ? $this->encode("From: {$this->fromName} <{$this->fromEmail}>")
                : "From: <{$this->fromEmail}>";
            $toHdr = 'To: ' . implode(', ', array_map(fn($r)=>'<'.$this->addr($r).'>', $rcpts));

            $headers[] = $fromHdr;
            $headers[] = $toHdr;

            if ($this->bccSelf) {
                $headers[] = 'Bcc: <'.$this->addr($this->fromEmail).'>';
            }

            $headers[] = 'Date: ' . gmdate('r');
            $headers[] = 'Message-ID: <'.bin2hex(random_bytes(12)).'@'.$this->domain().'>';
            $headers[] = $this->encode('Subject: '.$subject);
            $headers[] = 'MIME-Version: 1.0';
            $headers[] = $isHtml ? 'Content-Type: text/html; charset=UTF-8'
                                 : 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';

            // Payload with correct SMTP terminator
            $payload = implode("\r\n", $headers)
                     . "\r\n\r\n"
                     . $this->normalizeBody($body)
                     . "\r\n.\r\n"; // <-- required end of DATA

            $this->write($payload);

            // some servers send multi-line 250 after DATA
            $resp = $this->readMulti(250);
            $this->log("DATA accepted: ".trim($resp));

            $this->cmd("QUIT");
            $this->close();
            return true;

        } catch (\Throwable $e) {
            $this->close();
            return $this->fail($e->getMessage());
        }
    }

    // ===== internals =====

    private function connect(): void {
        $remote = $this->host . ':' . $this->port;

        $ssl = [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
            'SNI_enabled'       => true,
            'peer_name'         => $this->host,
            'SNI_server_name'   => $this->host,
            'crypto_method'     => STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_SSLv23_CLIENT,
        ];
        $ctx = stream_context_create(['ssl' => $ssl]);

        if ($this->secure === 'ssl') {
            $remote = 'ssl://' . $remote; // implicit TLS for 465
        }

        $this->sock = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            $this->timeout > 0 ? $this->timeout : 60,
            STREAM_CLIENT_CONNECT,
            $ctx
        );
        if (!$this->sock) {
            throw new \RuntimeException("connect failed: {$errstr} ({$errno})");
        }
        stream_set_timeout($this->sock, max(30, $this->timeout));
        $this->log("Connected to $remote");
    }

    private function encrypted(): bool {
        $m = stream_get_meta_data($this->sock);
        return !empty($m['crypto']);
    }

    private function cmd(string $l): void {
        $this->log("C: $l");
        $this->write($l."\r\n");
    }

    private function write(string $d): void {
        $n = strlen($d); $s = 0;
        while ($s < $n) {
            $w = @fwrite($this->sock, substr($d, $s));
            if ($w === false || $w === 0) throw new \RuntimeException('write');
            $s += $w;
        }
    }

    private function readLine(): string {
        $l = fgets($this->sock);
        if ($l === false) {
            $meta = @stream_get_meta_data($this->sock) ?: [];
            $phpErr = error_get_last();
            $metaStr = 'meta=' . json_encode($meta) . ' last_error=' . ($phpErr['message'] ?? 'none');
            $this->log('READ FAIL ' . $metaStr);
            throw new \RuntimeException('read; ' . $metaStr);
        }
        $this->log("S: $l");
        return rtrim($l, "\r\n");
    }

    private function readMulti(int $expect): string {
        $out = '';
        do {
            $ln  = $this->readLine();
            $out .= $ln . "\n";
            if (!preg_match('/^(\d{3})([ -])/', $ln, $m)) throw new \RuntimeException("bad resp: $ln");
            if ((int)$m[1] !== $expect)               throw new \RuntimeException("exp $expect got $ln");
            $more = ($m[2] === '-');
        } while ($more);
        return $out;
    }

    private function expect(int|array $codes): void {
        $ln = $this->readLine();
        if (!preg_match('/^(\d{3})\b/', $ln, $m)) throw new \RuntimeException("resp: $ln");
        $ok = is_array($codes) ? in_array((int)$m[1], $codes, true) : ((int)$m[1] === $codes);
        if (!$ok) throw new \RuntimeException("exp " . (is_array($codes) ? implode('|', $codes) : $codes) . " got $ln");
    }

    private function addr(string $e): string { return trim($e, " \t\r\n<>"); }

    private function normalizeBody(string $b): string {
        $b = str_replace(["\r\n", "\r"], "\n", $b);
        $b = str_replace("\n.", "\n..", $b); // dot-stuffing
        return str_replace("\n", "\r\n", $b);
    }

    private function encode(string $h): string {
        if (!preg_match('/[^\x20-\x7E]/', $h)) return $h;
        [$name, $val] = array_pad(explode(':', $h, 2), 2, '');
        $bytes = (string)mb_convert_encoding($val, 'UTF-8');
        $out = '';
        foreach (str_split($bytes) as $ch) { $out .= '=' . strtoupper(bin2hex($ch)); }
        return $name . ': =?UTF-8?Q?' . str_replace(' ', '_', $out) . '?=';
    }

    private function domain(): string {
        $at = strpos($this->fromEmail, '@');
        return $at !== false ? substr($this->fromEmail, $at + 1) : 'localhost';
    }

    private function fail(string $m): bool {
        $this->err = $m;
        $this->log("ERR: $m");
        return false;
    }

    private function close(): void {
        if (is_resource($this->sock)) {
            @fclose($this->sock);
        }
        $this->sock = null;
        $this->log("Closed");
    }

    // ===== logging helpers =====

    private function log(string $msg): void {
        if (!$this->debug) return;

        $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";

        // rotate if too large (if file exists)
        if (is_file($this->logFile) && @filesize($this->logFile) >= $this->logMaxBytes) {
            $this->rotateLogs();
        }

        // ensure dir & write
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        $ok = @file_put_contents($this->logFile, $line, FILE_APPEND);

        // ALWAYS mirror to PHP error_log as fallback
        error_log('[MAILER] ' . $msg);
        // if $ok !== false and file grew beyond threshold, rotate next time
    }

    private function rotateLogs(): void {
        // Delete oldest
        $oldest = $this->logFile . '.' . $this->logKeep;
        if (is_file($oldest)) { @unlink($oldest); }

        // Shift chain
        for ($i = $this->logKeep - 1; $i >= 1; $i--) {
            $src = $this->logFile . '.' . $i;
            $dst = $this->logFile . '.' . ($i + 1);
            if (is_file($src)) { @rename($src, $dst); }
        }

        // Current -> .1
        if (is_file($this->logFile)) {
            @rename($this->logFile, $this->logFile . '.1');
        }
    }
}
