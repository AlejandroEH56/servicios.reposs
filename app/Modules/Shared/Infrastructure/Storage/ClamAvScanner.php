<?php

namespace App\Modules\Shared\Infrastructure\Storage;

use App\Modules\Shared\Application\Ports\VirusScanner;
use RuntimeException;

class ClamAvScanner implements VirusScanner
{
    public function isClean(string $path): bool
    {
        $host = (string) config('modernization.storage.scanner_host');
        $port = (int) config('modernization.storage.scanner_port');
        $socket = @stream_socket_client('tcp://'.$host.':'.$port, $errno, $error, 2);
        if ($socket === false) {
            throw new RuntimeException('SCANNER_UNAVAILABLE');
        }
        $file = @fopen($path, 'rb');
        if ($file === false) {
            fclose($socket);
            throw new RuntimeException('SCAN_INPUT_UNAVAILABLE');
        }
        try {
            stream_set_timeout($socket, 5);
            $this->write($socket, "zINSTREAM\0");
            while (! feof($file)) {
                $chunk = fread($file, 65536);
                if ($chunk === false) {
                    throw new RuntimeException('SCAN_INPUT_UNAVAILABLE');
                }
                if ($chunk !== '') {
                    $this->write($socket, pack('N', strlen($chunk)).$chunk);
                }
            }
            $this->write($socket, pack('N', 0));
            $reply = '';
            while (strlen($reply) < 4096 && ! str_contains($reply, "\0") && ! feof($socket)) {
                $chunk = fread($socket, 1024);
                if ($chunk === false || $chunk === '' || stream_get_meta_data($socket)['timed_out']) {
                    throw new RuntimeException('SCANNER_TIMEOUT');
                }
                $reply .= $chunk;
            }
            if ($reply === "stream: OK\0") {
                return true;
            }
            if (preg_match('/^stream: [^\x00\r\n]+ FOUND\x00$/', $reply)) {
                return false;
            }
            throw new RuntimeException('SCANNER_RESPONSE_INVALID');
        } finally {
            fclose($file);
            fclose($socket);
        }
    }

    /** @param resource $socket */
    private function write($socket, string $data): void
    {
        while ($data !== '') {
            $written = fwrite($socket, $data);
            if ($written === false || $written === 0) {
                throw new RuntimeException('SCANNER_WRITE_FAILED');
            }
            $data = substr($data, $written);
        }
    }
}
