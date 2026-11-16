<?php

// Real concurrent TCP fixture, not an HTTP mock. Modes hang/drip remain available to old tests.
// php slow-s3-server.php <port> <mode> [private fixture dir]; self-exits after 120s.
[$script, $port, $mode, $directory] = $argv + [null, 0, 'hang', null];
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $err);
if (! $server) {
    fwrite(STDERR, "listen failed: $err\n");
    exit(1);
}
stream_set_blocking($server, false);
echo "ready\n";
$clients = [];
$deadline = microtime(true) + 120;
$lastDrip = microtime(true);
$image = imagecreatetruecolor($mode === 'large-image' ? 2200 : ($mode === 'benchmark' ? 1600 : 32), $mode === 'large-image' ? 1900 : ($mode === 'benchmark' ? 1200 : 24));
imagefill($image, 0, 0, imagecolorallocate($image, 240, 100, 50));
ob_start();
imagejpeg($image);
$jpeg = ob_get_clean();
imagedestroy($image);
$respond = static function (int $status, string $body = '', string $extra = ''): string {
    return "HTTP/1.1 $status fixture\r\nContent-Type: image/jpeg\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n$extra\r\n$body";
};
while (microtime(true) < $deadline) {
    $read = array_merge([$server], array_column($clients, 'sock'));
    $write = [];
    foreach ($clients as $client) {
        if ($client['output'] !== '') {
            $write[] = $client['sock'];
        }
    }
    $except = null;
    @stream_select($read, $write, $except, 0, 20000);
    foreach ($read as $socket) {
        if ($socket === $server) {
            if ($client = @stream_socket_accept($server, 0)) {
                stream_set_blocking($client, false);
                $clients[(int) $client] = ['sock' => $client, 'input' => '', 'output' => '', 'parsed' => false, 'close' => false, 'drip' => false, 'expect' => false];
            }

            continue;
        }
        $id = (int) $socket;
        $data = @fread($socket, 65536);
        if ($data === false || ($data === '' && feof($socket))) {
            fclose($socket);
            unset($clients[$id]);

            continue;
        }
        $clients[$id]['input'] .= $data;
        if ($clients[$id]['parsed'] || ! str_contains($clients[$id]['input'], "\r\n\r\n")) {
            continue;
        }
        [$headers, $body] = explode("\r\n\r\n", $clients[$id]['input'], 2);
        if (stripos($headers, 'Expect: 100-continue') !== false && ! $clients[$id]['expect']) {
            @fwrite($socket, "HTTP/1.1 100 Continue\r\n\r\n");
            $clients[$id]['expect'] = true;
        }
        preg_match('/Content-Length:\s*(\d+)/i', $headers, $length);
        if (strlen($body) < (int) ($length[1] ?? 0)) {
            continue;
        }
        preg_match('~^(\w+) ([^ ]+) HTTP~', $headers, $request);
        $method = $request[1] ?? 'GET';
        $path = rawurldecode(parse_url($request[2] ?? '/', PHP_URL_PATH));
        $clients[$id]['parsed'] = true;
        if ($directory) {
            file_put_contents($directory.'/requests.jsonl', json_encode(['method' => $method, 'path' => $path, 'time' => microtime(true)])."\n", FILE_APPEND | LOCK_EX);
        }
        if ($mode === 'hang' || ($mode === 'phases' && ($method === 'PUT' || str_contains($path, '/thumbs/'))) || str_contains($path, 'hang.jpg')) {
            continue;
        }
        if ($mode === 'drip') {
            $clients[$id]['output'] = "HTTP/1.1 200 OK\r\nContent-Type: image/jpeg\r\nContent-Length: 100000\r\n\r\nx";
            $clients[$id]['drip'] = true;

            continue;
        }
        if ($mode === 'retry') {
            $clients[$id]['output'] = $respond(503, '<Error><Code>SlowDown</Code></Error>');
        } elseif ($mode === 'chunked' || ($mode === 'huge-thumb' && str_contains($path, '/thumbs/'))) {
            // No Content-Length, larger than either accepted body bound.
            $large = str_repeat('x', 6 * 1024 * 1024);
            $clients[$id]['output'] = "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n".dechex(strlen($large))."\r\n$large\r\n0\r\n\r\n";
        } elseif (str_contains($path, '/thumbs/')) {
            $cache = $directory ? $directory.'/'.basename($path) : null;
            if ($method === 'PUT') {
                if ($cache) {
                    file_put_contents($cache, $body);
                }
                $clients[$id]['output'] = $respond(200, '', 'ETag: "fixture"'."\r\n");
            } else {
                $clients[$id]['output'] = $cache && is_file($cache) ? $respond(200, file_get_contents($cache)) : $respond(404);
            }
        } else {
            $clients[$id]['output'] = $respond(200, $jpeg);
        }
        $clients[$id]['close'] = true;
    }
    foreach ($write as $socket) {
        $id = (int) $socket;
        if (! isset($clients[$id])) {
            continue;
        }
        $sent = @fwrite($socket, substr($clients[$id]['output'], 0, 65536));
        if ($sent === false) {
            fclose($socket);
            unset($clients[$id]);

            continue;
        }
        $clients[$id]['output'] = substr($clients[$id]['output'], $sent);
        if ($clients[$id]['output'] === '' && $clients[$id]['close']) {
            fclose($socket);
            unset($clients[$id]);
        }
    }
    if (microtime(true) - $lastDrip >= 0.1) {
        $lastDrip = microtime(true);
        foreach ($clients as &$client) {
            if ($client['drip']) {
                $client['output'] .= 'x';
            }
        }
        unset($client);
    }
}
