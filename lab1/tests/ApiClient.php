<?php
declare(strict_types=1);

final class ApiClient
{
    private string $directory;
    private string $baseUrl;
    private mixed $process = null;
    private static ?array $phpOptions = null;

    public function __construct()
    {
        $this->directory = sys_get_temp_dir() . '/nztp-lab1-php-' . bin2hex(random_bytes(8));
        if (!mkdir($this->directory)) {
            throw new RuntimeException('Nie można utworzyć katalogu testu.');
        }
        try {
            $this->start();
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    private static function phpOptions(): array
    {
        if (self::$phpOptions !== null) {
            return self::$phpOptions;
        }
        // php -d options of the test process are not inherited by the HTTP server.
        $probe = proc_open([PHP_BINARY, '-r', 'echo json_encode(get_loaded_extensions());'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            options: ['bypass_shell' => true, 'create_no_window' => true]);
        if ($probe === false) {
            throw new RuntimeException('Nie można sprawdzić rozszerzeń PHP.');
        }
        fclose($pipes[0]);
        $extensions = array_map('strtolower', json_decode(stream_get_contents($pipes[1]), true, flags: JSON_THROW_ON_ERROR));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($probe);
        self::$phpOptions = [];
        foreach (['pdo_sqlite', 'mbstring', 'intl'] as $extension) {
            if (!in_array($extension, $extensions, true)) {
                array_push(self::$phpOptions, '-d', 'extension=' . $extension);
            }
        }
        return self::$phpOptions;
    }

    private function start(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($socket === false) {
            throw new RuntimeException($errorMessage, $errorCode);
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->baseUrl = 'http://' . $address;
        $root = dirname(__DIR__);
        $environment = getenv();
        $environment['DB_PATH'] = $this->directory . '/test.sqlite';
        $command = [PHP_BINARY, ...self::phpOptions(), '-S', $address, '-t', $root . '/public', $root . '/public/index.php'];
        $this->process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['file', $this->directory . '/stdout.log', 'a'],
            2 => ['file', $this->directory . '/stderr.log', 'a'],
        ], $pipes, $root, $environment, ['bypass_shell' => true, 'create_no_window' => true]);
        if ($this->process === false) {
            throw new RuntimeException('Nie można uruchomić serwera PHP.');
        }
        fclose($pipes[0]);
        $deadline = microtime(true) + 5;
        do {
            if (!proc_get_status($this->process)['running']) {
                break;
            }
            try {
                $response = $this->request('GET', '/api/v1/products');
                if ($response['status'] === 200) {
                    return;
                }
                break;
            } catch (RuntimeException) {
                usleep(20000);
            }
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Serwer testowy nie wystartował: ' . file_get_contents($this->directory . '/stderr.log'));
    }

    private function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;
    }

    public function restart(): void
    {
        $this->stop();
        $this->start();
    }

    public function close(): void
    {
        $this->stop();
        $log = is_file($this->directory . '/stderr.log') ? file_get_contents($this->directory . '/stderr.log') : '';
        foreach (scandir($this->directory) as $name) {
            if ($name !== '.' && $name !== '..') {
                unlink($this->directory . '/' . $name);
            }
        }
        rmdir($this->directory);
        if (preg_match('/PHP (?:Fatal error|Warning|Deprecated)|Stack trace:/', $log) === 1) {
            throw new RuntimeException('Błędy PHP podczas testu: ' . $log);
        }
    }

    public function request(string $method, string $path, mixed $body = null, array $headers = []): array
    {
        $json = func_num_args() >= 3 ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null;
        if ($json !== null && $headers === []) {
            $headers = ['Content-Type: application/json'];
        }
        return $this->raw($method, $path, $json, $headers);
    }

    private function handle(string $method, string $path, ?string $body, array $headers): CurlHandle
    {
        $handle = curl_init($this->baseUrl . $path);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT_MS => 300,
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        return $handle;
    }

    private function decode(CurlHandle $handle, string|false $result): array
    {
        if ($result === false) {
            throw new RuntimeException('Błąd połączenia HTTP: ' . curl_error($handle));
        }
        $size = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $headers = [];
        foreach (explode("\r\n", substr($result, 0, $size)) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower($name)] = trim($value);
            }
        }
        $body = substr($result, $size);
        return ['status' => curl_getinfo($handle, CURLINFO_HTTP_CODE), 'headers' => $headers,
            'body' => $body === '' ? null : (str_contains($headers['content-type'] ?? '', 'application/json')
                ? json_decode($body, true, flags: JSON_THROW_ON_ERROR) : $body)];
    }

    public function raw(string $method, string $path, ?string $body, array $headers = ['Content-Type: application/json']): array
    {
        $handle = $this->handle($method, $path, $body, $headers);
        return $this->decode($handle, curl_exec($handle));
    }

    public function parallelCreate(array $body, int $count): array
    {
        $multi = curl_multi_init();
        $handles = [];
        try {
            for ($index = 0; $index < $count; $index++) {
                $handle = $this->handle('POST', '/api/v1/products', json_encode($body, JSON_THROW_ON_ERROR), ['Content-Type: application/json']);
                $handles[] = $handle;
                curl_multi_add_handle($multi, $handle);
            }
            do {
                $status = curl_multi_exec($multi, $active);
                if ($status !== CURLM_OK) {
                    throw new RuntimeException('Błąd równoległych żądań HTTP.');
                }
                if ($active) {
                    curl_multi_select($multi, 0.1);
                }
            } while ($active);
            return array_map(fn ($handle) => $this->decode($handle, curl_multi_getcontent($handle)), $handles);
        } finally {
            foreach ($handles as $handle) {
                curl_multi_remove_handle($multi, $handle);
            }
        }
    }
}
