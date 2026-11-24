<?php

declare(strict_types=1);

use Micx\SecVcs\Dispatcher;
use Micx\SecVcs\Service;
use Micx\SecVcs\Storage;
use PHPUnit\Framework\TestCase;

final class MockModeTest extends TestCase
{
    public function testSimulatesRequestsWithoutGitAndPreservesWorkspace(): void
    {
        $base = sys_get_temp_dir() . '/micx-mock-' . bin2hex(random_bytes(8));
        mkdir($base, 0770, true);
        $service = new Service(new Storage($base), $base . '/missing-key', $base . '/missing-hosts', true);
        $dispatcher = new Dispatcher($service->execute(...), true);
        $params = ['url' => 'git@example.test:org/repo.git', 'branch' => 'main', 'directory' => 'repo/main'];
        $request = static fn (string $method, array $extra = []): array => [
            'version' => 1,
            'id' => 'mock-test-1234',
            'method' => $method,
            'params' => array_replace($params, $extra),
        ];

        $ping = $dispatcher->handle($request('ping'));
        self::assertTrue($ping['ok']);
        self::assertTrue($ping['mockMode']);
        self::assertTrue($ping['result']['ready']);
        self::assertSame([], $ping['result']['errors']);

        $missing = $dispatcher->handle($request('status'));
        self::assertFalse($missing['ok']);
        self::assertTrue($missing['mockMode']);
        self::assertSame('NOT_FOUND', $missing['error']['code']);

        $missingPull = $dispatcher->handle($request('pull'));
        self::assertFalse($missingPull['ok']);
        self::assertTrue($missingPull['mockMode']);
        self::assertSame('NOT_FOUND', $missingPull['error']['code']);
        self::assertStringContainsString('Mock mode is active', $missingPull['error']['message']);
        self::assertStringContainsString($base . '/repo/main', $missingPull['error']['message']);
        self::assertStringContainsString('Development mode intended?', $missingPull['error']['message']);
        self::assertStringContainsString('CONF_MOCK_MODE=false', $missingPull['error']['message']);
        self::assertDirectoryDoesNotExist($base . '/repo/main');

        mkdir($base . '/repo/main', 0770, true);
        $pull = $dispatcher->handle($request('pull', ['message' => 'Preserve local changes']));
        self::assertTrue($pull['ok']);
        self::assertTrue($pull['mockMode']);
        self::assertTrue($pull['result']['mockMode']);
        self::assertDirectoryExists($base . '/repo/main');
        self::assertDirectoryDoesNotExist($base . '/repo/main/.git');

        file_put_contents($base . '/repo/main/hello.txt', 'unchanged');
        self::assertTrue($dispatcher->handle($request('commit', ['message' => 'Test']))['ok']);
        self::assertTrue($dispatcher->handle($request('push'))['ok']);
        self::assertFalse($dispatcher->handle($request('status'))['result']['hasUnpushedChanges']);
        self::assertSame([], $dispatcher->handle($request('log'))['result']['entries']);
        self::assertSame('main', $dispatcher->handle($request('branches'))['result']['branches'][0]['name']);
        self::assertSame('unchanged', file_get_contents($base . '/repo/main/hello.txt'));
        self::assertDirectoryDoesNotExist($base . '/repo/main/.git');

        $invalid = $dispatcher->handle($request('pull', ['directory' => '../escape']));
        self::assertFalse($invalid['ok']);
        self::assertTrue($invalid['mockMode']);
        self::assertSame('INVALID_PATH', $invalid['error']['code']);
    }

    public function testRealDispatcherDoesNotAnnotateResponses(): void
    {
        $dispatcher = new Dispatcher(static fn (): array => ['pong' => true]);
        $response = $dispatcher->handle([
            'version' => 1, 'id' => 'mock-test-1234', 'method' => 'ping', 'params' => [],
        ]);
        self::assertArrayNotHasKey('mockMode', $response);
        self::assertArrayNotHasKey('mockMode', $response['result']);
    }
}
