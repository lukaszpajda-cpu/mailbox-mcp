<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Support;

use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;

/**
 * Spawns `php tests/Support/scripted-server.php <scriptfile>`: a one-shot
 * TCP server on 127.0.0.1 that replays a scripted dialogue, so a socket
 * client can be tested against real socket I/O without a mail server.
 *
 * Steps (see scripted-server.php for details):
 *   ['send' => "* OK ready\r\n"]
 *   ['expect' => '/^A001 LOGIN /', 'reply' => "{tag} OK done\r\n"]
 *   ['read' => 12, 'reply' => '']   (exactly 12 raw literal bytes)
 *
 * {@see self::stop()} returns every line (and literal) the client sent,
 * and fails the test when the child saw an unexpected line, timed out or
 * the client disconnected before the script ended.
 */
final class ScriptedSocketServer
{
    private const START_TIMEOUT = 5.0;

    private const STOP_TIMEOUT = 10.0;

    /** @var array<int, resource> */
    private array $pipes;

    private string $stdout = '';

    private bool $stopped = false;

    /**
     * @param resource              $process
     * @param array<int, resource>  $pipes
     */
    private function __construct(
        private $process,
        array $pipes,
        private readonly int $port,
        private readonly string $scriptFile,
    ) {
        $this->pipes = $pipes;
    }

    /**
     * @param array<int, array<string, bool|int|string>> $steps
     */
    public static function start(array $steps): self
    {
        $scriptFile = tempnam(sys_get_temp_dir(), 'imap-script-');

        if (false === $scriptFile) {
            throw new RuntimeException('Cannot create script file');
        }

        file_put_contents($scriptFile, serialize($steps));

        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/scripted-server.php', $scriptFile],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Cannot spawn scripted server');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = '';
        $deadline = microtime(true) + self::START_TIMEOUT;

        while (!str_contains($out, "\n") && microtime(true) < $deadline) {
            $read = [$pipes[1]];
            $write = null;
            $except = null;

            if ((int) @stream_select($read, $write, $except, 0, 50000) > 0) {
                $chunk = fread($pipes[1], 8192);

                if (false === $chunk || ('' === $chunk && feof($pipes[1]))) {
                    break;
                }

                $out .= $chunk;
            }
        }

        $nl = strpos($out, "\n");

        if (false === $nl || 1 !== preg_match('/^\d+$/', substr($out, 0, $nl))) {
            $err = (string) stream_get_contents($pipes[2]);
            proc_terminate($process);
            proc_close($process);
            @unlink($scriptFile);

            throw new RuntimeException('Scripted server did not report a port: ' . $out . $err);
        }

        $server = new self($process, $pipes, (int) substr($out, 0, $nl), $scriptFile);
        $server->stdout = substr($out, $nl + 1);

        return $server;
    }

    public function port(): int
    {
        return $this->port;
    }

    /**
     * Waits for the child to finish and returns what the client sent.
     *
     * @return array<int, string> client lines (without CRLF) and literals
     */
    public function stop(): array
    {
        if ($this->stopped) {
            throw new RuntimeException('Scripted server already stopped');
        }

        $this->stopped = true;
        $stderr = '';
        $deadline = microtime(true) + self::STOP_TIMEOUT;
        $open = [1 => true, 2 => true];

        while ([] !== $open && microtime(true) < $deadline) {
            $read = [];

            foreach (array_keys($open) as $i) {
                $read[] = $this->pipes[$i];
            }

            $write = null;
            $except = null;

            if (false === @stream_select($read, $write, $except, 0, 50000)) {
                break;
            }

            foreach (array_keys($open) as $i) {
                $chunk = fread($this->pipes[$i], 65536);

                if (false !== $chunk && '' !== $chunk) {
                    if (1 === $i) {
                        $this->stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                } elseif (feof($this->pipes[$i])) {
                    unset($open[$i]);
                }
            }
        }

        if ([] !== $open) {
            proc_terminate($this->process);
        }

        fclose($this->pipes[1]);
        fclose($this->pipes[2]);
        $exit = proc_close($this->process);
        @unlink($this->scriptFile);

        $received = [];
        $encoded = trim($this->stdout);

        if ('' !== $encoded) {
            $decoded = base64_decode($encoded, true);
            $value = false === $decoded ? false : unserialize($decoded);

            if (is_array($value)) {
                $received = array_values(array_map('strval', $value));
            }
        }

        if (0 !== $exit || [] !== $open) {
            throw new AssertionFailedError(sprintf(
                "Scripted IMAP server failed (exit %d): %s\nClient sent:\n%s",
                $exit,
                trim($stderr) ?: 'no output (killed after timeout?)',
                implode("\n", $received),
            ));
        }

        return $received;
    }

    public function __destruct()
    {
        if (!$this->stopped) {
            $this->stopped = true;
            proc_terminate($this->process);
            @fclose($this->pipes[1]);
            @fclose($this->pipes[2]);
            proc_close($this->process);
            @unlink($this->scriptFile);
        }
    }
}
