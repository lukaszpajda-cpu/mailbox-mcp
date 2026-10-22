<?php

declare(strict_types=1);

/*
 * Child process for {@see \MailboxMcp\Tests\Support\ScriptedSocketServer}.
 *
 * Usage: php scripted-server.php <script-file>
 *
 * The script file holds a serialize()d list of steps:
 *   ['send' => "bytes"]                      write bytes to the client
 *   ['expect' => '/regex/', 'reply' => "…"]  read one CRLF line, match it,
 *                                            then write the (optional) reply
 *   ['read' => n, 'reply' => "…"]            read exactly n raw bytes (a
 *                                            literal), then the optional reply
 *   ['close' => true]                        drop the connection and finish
 * `{tag}` in a reply is replaced by the tag of the most recent client line
 * (`^(\S+) `). Listens on 127.0.0.1:0, prints the chosen port on stdout,
 * serves one connection, then prints base64(serialize(received)) on
 * stdout. After the last step every further client byte is unexpected;
 * the server waits for EOF. An unexpected line is answered with
 * `* BAD UNEXPECTED: <line>`. Exit codes: 0 ok, 1 unexpected client line,
 * 2 bad usage, 3 timeout, 4 client disconnected early.
 */

final class ScriptedServerChild
{
    private const IDLE_TIMEOUT = 5.0;

    /** @var array<int, string> */
    private array $received = [];

    private string $buffer = '';

    private string $tag = '';

    /**
     * @param resource $conn
     */
    public function __construct(private $conn)
    {
    }

    /**
     * @param array<int, mixed> $steps
     */
    public function run(array $steps): never
    {
        foreach ($steps as $i => $step) {
            if (!is_array($step)) {
                $this->finish(2, "bad step {$i}");
            }

            if (isset($step['close'])) {
                $this->finish(0);
            }

            if (isset($step['send'])) {
                fwrite($this->conn, (string) $step['send']);

                continue;
            }

            if (isset($step['read'])) {
                $data = $this->readBytes((int) $step['read']);

                if (null === $data) {
                    $this->finish(4, "client disconnected before step {$i} (literal of {$step['read']} bytes)");
                }

                $this->received[] = $data;
                $this->reply($step);

                continue;
            }

            if (isset($step['expect'])) {
                $expect = (string) $step['expect'];
                $line = $this->readLine($expect);

                if (null === $line) {
                    $this->finish(4, "client disconnected before step {$i} (expected {$expect})");
                }

                $this->received[] = $line;

                if (1 !== preg_match($expect, $line)) {
                    fwrite($this->conn, "* BAD UNEXPECTED: {$line}\r\n");
                    $this->finish(1, "UNEXPECTED client line at step {$i}: {$line} (expected {$expect})");
                }

                if (1 === preg_match('/^(\S+) /', $line, $m)) {
                    $this->tag = $m[1];
                }

                $this->reply($step);

                continue;
            }

            $this->finish(2, "bad step {$i}");
        }

        // Script exhausted: anything else the client sends is unexpected.
        while (true) {
            if ('' !== $this->buffer) {
                $nl = strpos($this->buffer, "\r\n");
                $line = false === $nl ? $this->buffer : substr($this->buffer, 0, $nl);
                $this->received[] = $line;
                fwrite($this->conn, "* BAD UNEXPECTED: {$line}\r\n");
                $this->finish(1, "UNEXPECTED client line after end of script: {$line}");
            }

            $r = $this->fill();

            if (null === $r) {
                $this->finish(0);
            }

            if (false === $r) {
                $this->finish(3, 'timeout waiting for the client to disconnect');
            }
        }
    }

    /**
     * @param array<mixed> $step
     */
    private function reply(array $step): void
    {
        if (isset($step['reply']) && '' !== $step['reply']) {
            fwrite($this->conn, str_replace('{tag}', $this->tag, (string) $step['reply']));
        }
    }

    /**
     * @return ?bool true: bytes buffered, false: idle timeout, null: EOF
     */
    private function fill(): ?bool
    {
        $deadline = microtime(true) + self::IDLE_TIMEOUT;

        while (microtime(true) < $deadline) {
            $read = [$this->conn];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 0, 100000);

            if (false === $ready) {
                $this->finish(3, 'stream_select failed');
            }

            if ($ready > 0) {
                $chunk = fread($this->conn, 65536);

                if (false === $chunk || '' === $chunk) {
                    return feof($this->conn) ? null : true;
                }

                $this->buffer .= $chunk;

                return true;
            }
        }

        return false;
    }

    private function readLine(string $waitingFor): ?string
    {
        while (false === ($nl = strpos($this->buffer, "\r\n"))) {
            $r = $this->fill();

            if (null === $r) {
                return null;
            }

            if (false === $r) {
                $this->finish(3, "timeout waiting for client line ({$waitingFor})");
            }
        }

        $line = substr($this->buffer, 0, $nl);
        $this->buffer = substr($this->buffer, $nl + 2);

        return $line;
    }

    private function readBytes(int $n): ?string
    {
        while (strlen($this->buffer) < $n) {
            $r = $this->fill();

            if (null === $r) {
                return null;
            }

            if (false === $r) {
                $this->finish(3, "timeout waiting for {$n} literal bytes");
            }
        }

        $data = substr($this->buffer, 0, $n);
        $this->buffer = substr($this->buffer, $n);

        return $data;
    }

    private function finish(int $code, string $error = ''): never
    {
        if ('' !== $error) {
            fwrite(STDERR, $error . "\n");
        }

        fwrite(STDOUT, base64_encode(serialize($this->received)) . "\n");
        fclose($this->conn);

        exit($code);
    }
}

if (!isset($argv[1]) || !is_file($argv[1])) {
    fwrite(STDERR, "usage: scripted-server.php <script-file>\n");
    exit(2);
}

$steps = unserialize((string) file_get_contents($argv[1]));

if (!is_array($steps)) {
    fwrite(STDERR, "script file does not hold a step list\n");
    exit(2);
}

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

if (false === $server) {
    fwrite(STDERR, "listen failed: {$errstr}\n");
    exit(2);
}

$name = (string) stream_socket_get_name($server, false);
fwrite(STDOUT, substr($name, (int) strrpos($name, ':') + 1) . "\n");
fflush(STDOUT);

$conn = @stream_socket_accept($server, 5.0);
fclose($server);

if (false === $conn) {
    fwrite(STDERR, "no client connected\n");
    fwrite(STDOUT, base64_encode(serialize([])) . "\n");
    exit(3);
}

(new ScriptedServerChild($conn))->run(array_values($steps));
