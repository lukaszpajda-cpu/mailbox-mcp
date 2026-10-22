<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Support;

use MailboxMcp\Tests\Support\ScriptedSocketServer;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

final class ScriptedSocketServerTest extends TestCase
{
    public function testReplaysScriptAndRecordsClientLines(): void
    {
        $server = ScriptedSocketServer::start([
            ['send' => "* OK hello\r\n"],
            ['expect' => '/^X1 PING$/', 'reply' => "{tag} OK pong\r\n"],
            ['read' => 3],
            ['expect' => '/^$/', 'reply' => "{tag} OK bytes\r\n"],
        ]);

        $sock = stream_socket_client('tcp://127.0.0.1:' . $server->port(), $errno, $errstr, 2);
        $this->assertIsResource($sock);
        $this->assertSame("* OK hello\r\n", fgets($sock));
        fwrite($sock, "X1 PING\r\n");
        $this->assertSame("X1 OK pong\r\n", fgets($sock));
        fwrite($sock, "a\r\n\r\n");
        $this->assertSame("X1 OK bytes\r\n", fgets($sock));
        fclose($sock);

        $this->assertSame(['X1 PING', "a\r\n", ''], $server->stop());
    }

    public function testUnexpectedLineFailsWithTheLine(): void
    {
        $server = ScriptedSocketServer::start([
            ['send' => "* OK hello\r\n"],
            ['expect' => '/^X1 PING$/', 'reply' => "{tag} OK pong\r\n"],
        ]);

        $sock = stream_socket_client('tcp://127.0.0.1:' . $server->port(), $errno, $errstr, 2);
        $this->assertIsResource($sock);
        fgets($sock);
        fwrite($sock, "X1 NOPE\r\n");
        $this->assertSame("* BAD UNEXPECTED: X1 NOPE\r\n", fgets($sock));
        fclose($sock);

        try {
            $server->stop();
            $this->fail('stop() should have failed');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('UNEXPECTED client line at step 1: X1 NOPE', $e->getMessage());
        }
    }
}
