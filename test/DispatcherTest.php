<?php

declare(strict_types=1);

use Micx\SecVcs\Dispatcher;
use Micx\SecVcs\Fault;
use Micx\SecVcs\Service;
use Micx\SecVcs\Storage;
use PHPUnit\Framework\TestCase;

final class DispatcherTest extends TestCase
{
    public function testPingReportsMissingSshWithoutStartingRepositoryWork(): void
    {
        $base = sys_get_temp_dir() . '/micx-ping-' . bin2hex(random_bytes(8));
        $storage = new Storage($base . '/data');
        $service = new Service($storage, $base . '/key', $base . '/known_hosts');
        $dispatcher = new Dispatcher($service->execute(...));

        $reply = $dispatcher->handle(['version' => 1, 'id' => 'ping-no-key', 'method' => 'ping', 'params' => ['url' => 'git@example.test:demo/site.git', 'branch' => 'main', 'directory' => 'demo/site']]);
        self::assertTrue($reply['ok']);
        self::assertTrue($reply['result']['pong']);
        self::assertFalse($reply['result']['ready']);
        self::assertSame(['SSH_KEY_MISSING', 'KNOWN_HOSTS_MISSING'], array_column($reply['result']['errors'], 'code'));

        $checkout = $dispatcher->handle([
            'version' => 1,
            'id' => 'pull-no-key',
            'method' => 'pull',
            'params' => ['url' => 'git@example.test:demo/site.git', 'branch' => 'main', 'directory' => 'demo/site'],
        ]);
        self::assertFalse($checkout['ok']);
        self::assertSame('SSH_KEY_MISSING', $checkout['error']['code']);
        self::assertSame([], glob($base . '/data/*'));

        file_put_contents($base . '/key', 'test-key-content');
        file_put_contents($base . '/known_hosts', 'test-host-content');
        self::assertSame(['pong' => true, 'ready' => true, 'errors' => []], $service->execute('ping', []));
    }

    public function testRepeatedRequestRunsAgainWithoutJournal(): void
    {
        $calls = 0;
        $dispatcher = new Dispatcher(function () use (&$calls): array {
            ++$calls;
            return ['calls' => $calls];
        });
        $request = ['version' => 1, 'id' => 'request-123', 'method' => 'commit', 'params' => []];

        self::assertSame(1, $dispatcher->handle($request)['result']['calls']);
        self::assertSame(2, $dispatcher->handle($request)['result']['calls']);
    }

    public function testFailedOperationReturnsErrorWithoutBlockingNextRequest(): void
    {
        $calls = 0;
        $dispatcher = new Dispatcher(function () use (&$calls): array {
            ++$calls;
            if ($calls === 1) {
                throw new Fault('GIT_FAILED', 'Commit failed');
            }
            return ['committed' => true];
        });
        $request = ['version' => 1, 'id' => 'failure-123', 'method' => 'commit', 'params' => []];

        self::assertSame('GIT_FAILED', $dispatcher->handle($request)['error']['code']);
        self::assertTrue($dispatcher->handle($request)['ok']);
        self::assertSame(2, $calls);
    }

    public function testFaultKeepsCodeMessageAndDetails(): void
    {
        $dispatcher = new Dispatcher(function (): array {
            throw new Fault('SSH_AUTH_FAILED', 'SSH authentication failed', ['exitCode' => 128]);
        });

        $reply = $dispatcher->handle(['version' => 1, 'id' => 'failure-123', 'method' => 'checkout', 'params' => []]);
        self::assertSame('failure-123', $reply['id']);
        self::assertFalse($reply['ok']);
        self::assertSame(
            ['code' => 'SSH_AUTH_FAILED', 'message' => 'SSH authentication failed', 'details' => ['exitCode' => 128]],
            $reply['error'],
        );
    }
}
