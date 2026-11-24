<?php
declare(strict_types=1);

use Micx\Vcs\MixSecVcsClient;
use Micx\Vcs\Repository\VcsRepository;
use Micx\Vcs\Repository\VcsRemoteRepository;
use Micx\Vcs\Repository\VcsMockRepository;
use Micx\Vcs\Exception\InternalException;
use Micx\Vcs\Exception\ConnectionException;
use Micx\Vcs\Exception\AccessDeniedException;
use Micx\Vcs\Exception\RepositoryNotFoundException;
use Micx\Vcs\Exception\ErrorCode;
use Micx\Vcs\Exception\ExceptionMapper;
use Micx\Vcs\Exception\RepositoryNotReadyException;
use Micx\Vcs\Transport\RpcTransport;
use Micx\Vcs\Transport\TransportFactory;
use PHPUnit\Framework\TestCase;

final class RepositoryTest extends TestCase
{
    public function testMockPingRespondsImmediatelyWithoutRemote(): void
    {
        $manager = new MixSecVcsClient();
        $manager->setAllowMockDir(true);
        $manager->addMockDir('mock-ping.git', 'main', sys_get_temp_dir());
        $repo = $manager->getRepository('mock-ping.git');

        self::assertTrue($repo->ping());
        self::assertTrue($repo->ping(1.0));
        $this->expectException(\InvalidArgumentException::class);
        $repo->ping(0.0);
    }

    public function testRemotePingUsesFiveSecondTimeoutWithoutCheckout(): void
    {
        $transport = new class implements RpcTransport {
            public array $requests = [];
            public array $timeouts = [];

            public function request(array $request, float $timeout): array
            {
                $this->requests[] = $request['method'];
                $this->timeouts[] = $timeout;
                return [
                    'version' => 1,
                    'id' => $request['id'],
                    'ok' => true,
                    'result' => ['pong' => true, 'ready' => false],
                ];
            }
        };

        $manager = new MixSecVcsClient(realpath(sys_get_temp_dir()), $transport);
        $repo = $manager->getRepository('git@example.test:ping.git');
        self::assertSame([], $transport->requests);
        self::assertTrue($repo->ping());
        self::assertTrue($repo->ping(2.0));
        self::assertSame(['ping', 'ping'], $transport->requests);
        self::assertSame([5.0, 2.0], $transport->timeouts);
    }

    public function testMockCommitPushAndHistory(): void
    {
        $directory = sys_get_temp_dir() . '/mix-vcs-test-' . bin2hex(random_bytes(6));
        mkdir($directory);
        try {
            $manager = new MixSecVcsClient();
            $manager->addMockDir('demo', 'main', $directory);
            $manager->setAllowMockDir(true);
            $repo = $manager->getRepository('demo', 'main');
            self::assertInstanceOf(VcsMockRepository::class, $repo);
            self::assertInstanceOf(VcsRepository::class, $repo);
            self::assertSame($directory, $repo->getDirectory());
            $repo->pull();

            file_put_contents($directory . '/a.txt', 'one');
            self::assertTrue($repo->hasUnpushedChanges());
            $repo->setCommitUser('Jane Doe', 'jane@example.com');
            $repo->commit('First');
            $journal = json_decode(file_get_contents($directory . '/.mix-vcs.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('Jane Doe', $journal['history'][0]['authorName']);
            self::assertSame('jane@example.com', $journal['history'][0]['authorEmail']);
            self::assertTrue($repo->hasUnpushedChanges());
            $repo->push();
            self::assertFalse($repo->hasUnpushedChanges());
            self::assertSame('First', $repo->getHistory()[0]->message);
            self::assertSame('main', $repo->getBranches()[0]->name);

            file_put_contents($directory . '/a.txt', 'two');
            self::assertTrue($repo->hasUnpushedChanges());
            $repo->commit('Second');
            self::assertCount(2, $repo->getHistory());
        } finally {
            foreach (glob($directory . '/{*,.*}', GLOB_BRACE) ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory);
        }
    }

    public function testPullCommitsDirtyLocalMockWithoutPush(): void
    {
        $directory = sys_get_temp_dir() . '/micx-pull-mock-' . bin2hex(random_bytes(8));
        mkdir($directory);
        try {
            $manager = new MixSecVcsClient();
            $manager->setAllowMockDir(true);
            $manager->addMockDir('demo', 'main', $directory);
            $repo = $manager->getRepository('demo', 'main');

            $repo->pull();
            self::assertCount(0, $repo->getHistory());
            file_put_contents($directory . '/draft.txt', 'local');
            $repo->pull('Preserve dirty work');
            self::assertSame('Preserve dirty work', $repo->getHistory()[0]->message);
            self::assertTrue($repo->hasUnpushedChanges());
            $repo->pull();
            self::assertCount(1, $repo->getHistory());
            self::assertSame('local', file_get_contents($directory . '/draft.txt'));
        } finally {
            foreach (glob($directory . '/{*,.*}', GLOB_BRACE) ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory);
        }
    }

    public function testRemotePullPassesCommitMessageAndIdentityInOneRpc(): void
    {
        $transport = new class implements RpcTransport {
            public array $requests = [];
            public function request(array $request, float $timeout): array
            {
                $this->requests[] = $request;
                return [
                    'version' => 1, 'id' => $request['id'], 'ok' => true,
                    'result' => ['revision' => ''],
                ];
            }
        };
        $manager = new MixSecVcsClient(realpath(sys_get_temp_dir()), $transport);
        $repo = $manager->getRepository('git@example.test:repo.git', 'main', 'demo/checkout');
        $repo->setCommitUser('Jane Doe', 'jane@example.com');
        $repo->pull('My local savepoint');
        self::assertCount(1, $transport->requests);
        self::assertSame('pull', $transport->requests[0]['method']);
        self::assertSame('My local savepoint', $transport->requests[0]['params']['message']);
        self::assertSame('Jane Doe', $transport->requests[0]['params']['authorName']);
        self::assertSame('jane@example.com', $transport->requests[0]['params']['authorEmail']);
        self::assertSame('demo/checkout', $transport->requests[0]['params']['directory']);
    }

    public function testMockJournalPermissionsIgnoreRestrictiveUmask(): void
    {
        $directory = sys_get_temp_dir() . '/mix-vcs-permissions-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $previousUmask = umask(0077);

        try {
            $manager = new MixSecVcsClient();
            $manager->setAllowMockDir(true);
            $manager->addMockDir('permissions', 'main', $directory);
            $repo = $manager->getRepository('permissions');

            file_put_contents($directory . '/content.txt', 'one');
            $repo->commit('First');
            $journal = $directory . '/.mix-vcs.json';
            clearstatcache(true, $journal);
            self::assertSame(0666, fileperms($journal) & 0777);

            $repo->push();
            clearstatcache(true, $journal);
            self::assertSame(0666, fileperms($journal) & 0777);
            self::assertFalse($repo->hasUnpushedChanges());
        } finally {
            umask($previousUmask);
            foreach (glob($directory . '/{*,.*}', GLOB_BRACE) ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory);
        }
    }

    public function testRealRepositoryDelegatesToWorker(): void
    {
        $transport = new class implements RpcTransport {
            public array $requests = [];

            public function request(array $request, float $timeout): array
            {
                $this->requests[] = $request;
                return [
                    'version' => 1,
                    'id' => $request['id'],
                    'ok' => true,
                    'result' => match ($request['method']) {
                        'status' => ['hasUnpushedChanges' => false],
                        'log' => ['entries' => []],
                        'branches' => ['branches' => []],
                        default => ['revision' => ''],
                    },
                ];
            }
        };

        $sharedDirectory = realpath(sys_get_temp_dir());
        $manager = new MixSecVcsClient($sharedDirectory, $transport);
        $repo = $manager->getRepository('git@example.com:example/site.git', 'main', 'sites/a');
        self::assertInstanceOf(VcsRemoteRepository::class, $repo);
        self::assertSame($sharedDirectory . '/sites/a', $repo->getDirectory());
        self::assertSame([], $transport->requests);

        $repo->pull();
        $repo->commit('Commit');
        $repo->sync();
        self::assertFalse($repo->hasUnpushedChanges());
        self::assertSame([], $repo->getHistory());
        self::assertSame([], $repo->getBranches());

        self::assertSame(
            ['pull', 'commit', 'pull', 'push', 'status', 'log', 'branches'],
            array_column($transport->requests, 'method')
        );
        foreach ($transport->requests as $request) {
            self::assertSame([
                'url' => 'git@example.com:example/site.git',
                'branch' => 'main',
                'directory' => 'sites/a',
            ], array_intersect_key($request['params'], array_flip(['url', 'branch', 'directory'])));
        }
    }

    public function testCommitUserTravelsWithPullAndCommitButNotPush(): void
    {
        $transport = new class implements RpcTransport {
            public array $requests = [];

            public function request(array $request, float $timeout): array
            {
                $this->requests[] = $request;
                return [
                    'version' => 1,
                    'id' => $request['id'],
                    'ok' => true,
                    'result' => ['revision' => ''],
                ];
            }
        };

        $manager = new MixSecVcsClient(realpath(sys_get_temp_dir()), $transport);
        $repo = $manager->getRepository('git@example.test:author.git', 'main', 'author/main');
        $repo->setCommitUser('Jane Doe', 'jane@example.com');
        self::assertSame([], $transport->requests);

        $repo->pull();
        $repo->commit('Authored update');
        $repo->push();

        self::assertSame('Jane Doe', $transport->requests[0]['params']['authorName']);
        self::assertSame('jane@example.com', $transport->requests[0]['params']['authorEmail']);
        self::assertSame('Save local changes before pull', $transport->requests[0]['params']['message']);
        self::assertSame('Jane Doe', $transport->requests[1]['params']['authorName']);
        self::assertSame('jane@example.com', $transport->requests[1]['params']['authorEmail']);
        self::assertSame('Authored update', $transport->requests[1]['params']['message']);
        self::assertArrayNotHasKey('authorName', $transport->requests[2]['params']);

        $other = $manager->getRepository('git@example.test:author.git', 'main', 'author/main');
        $other->commit('Default author');
        self::assertArrayNotHasKey('authorName', $transport->requests[3]['params']);
    }

    public function testCommitUserRejectsInvalidIdentityForRemoteAndMock(): void
    {
        $transport = $this->createMock(RpcTransport::class);
        $manager = new MixSecVcsClient(realpath(sys_get_temp_dir()), $transport);
        $remote = $manager->getRepository('git@example.test:author.git', 'main');
        try {
            $remote->setCommitUser("Name\nInjected", 'jane@example.com');
            self::fail('Expected invalid author name');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }

        $manager = new MixSecVcsClient();
        $manager->addMockDir('mock-author', 'main', sys_get_temp_dir());
        $manager->setAllowMockDir(true);
        $mock = $manager->getRepository('mock-author', 'main');
        try {
            $mock->setCommitUser('Jane Doe', 'not-an-email');
            self::fail('Expected invalid author email');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    public function testLazyCheckoutAndSyncOrdering(): void
    {
        $transport = new class implements RpcTransport {
            public array $methods = [];
            public function request(array $request, float $timeout): array
            {
                $this->methods[] = $request['method'];
                return ['version' => 1, 'id' => $request['id'], 'ok' => true, 'result' => []];
            }
        };

        $manager = new MixSecVcsClient(realpath(sys_get_temp_dir()), $transport);
        $repo = $manager->getRepository('git@example.test:example/repo.git', 'main');
        self::assertSame(realpath(sys_get_temp_dir()) . '/repo/main', $repo->getDirectory());
        self::assertSame([], $transport->methods);
        $repo->sync();
        self::assertSame(['pull', 'push'], $transport->methods);
    }

    public function testSecondHandleReadsExistingCheckoutWithoutRpc(): void
    {
        $transport = $this->createMock(RpcTransport::class);
        $transport->expects(self::never())->method('request');
        $shared = realpath(sys_get_temp_dir());
        $url = 'git@example.test:example/site.git';

        $first = new MixSecVcsClient($shared, $transport, storageTemplate: 'projects/{repo}/{branch}');
        $second = new MixSecVcsClient($shared, $transport, storageTemplate: 'projects/{repo}/{branch}');
        self::assertSame(
            $shared . '/projects/site/main',
            $first->getRepository($url, 'main')->getDirectory()
        );
        self::assertSame(
            $first->getRepository($url, 'main')->getDirectory(),
            $second->getRepository($url, 'main')->getDirectory()
        );
        self::assertSame(
            $shared . '/custom/checkout',
            $first->getRepository($url, 'main', 'custom/checkout')->getDirectory()
        );
    }

    public function testMockSyncUsesLocalJournalWithoutAdditionalCommit(): void
    {
        $directory = sys_get_temp_dir() . '/mix-vcs-sync-' . bin2hex(random_bytes(6));
        mkdir($directory);
        try {
            $manager = new MixSecVcsClient();
            $manager->setAllowMockDir(true);
            $manager->addMockDir('repo1.git', 'main', $directory);
            $repo = $manager->getRepository('repo1.git');
            $repo->pull();
            file_put_contents($directory . '/file.txt', 'test');
            $repo->commit('Test');
            $repo->sync();
            self::assertFalse($repo->hasUnpushedChanges());
            self::assertCount(1, $repo->getHistory());
        } finally {
            foreach (glob($directory . '/{*,.*}', GLOB_BRACE) ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory);
        }
    }

    public function testDiagnosticErrorNumbersAndCategories(): void
    {
        $denied = ExceptionMapper::fromWorker('ACCESS_DENIED', 'Write permission missing', 'push');
        self::assertInstanceOf(AccessDeniedException::class, $denied);
        self::assertSame(ErrorCode::ACCESS_DENIED, $denied->errorCode);
        self::assertStringContainsString('MIX-VCS-2002', $denied->getMessage());

        $routing = ExceptionMapper::fromWorker('NO_ROUTE', 'Queue not bound', 'pull');
        self::assertInstanceOf(ConnectionException::class, $routing);
        self::assertSame(ErrorCode::BROKER_NOT_ROUTED, $routing->errorCode);

        $missing = ExceptionMapper::fromWorker('REPOSITORY_NOT_FOUND', 'Missing', 'checkout');
        self::assertInstanceOf(RepositoryNotFoundException::class, $missing);
        self::assertSame(ErrorCode::REPOSITORY_NOT_FOUND, $missing->errorCode);
    }

    public function testMockDirectoryMissingHasDiagnosticCode(): void
    {
        $manager = new MixSecVcsClient();
        $directory = sys_get_temp_dir() . '/missing-mix-vcs-' . bin2hex(random_bytes(6));
        $manager->setAllowMockDir(true);
        $manager->addMockDir('demo', 'main', $directory);
        $repository = $manager->getRepository('demo', 'main');
        self::assertTrue($repository->ping());
        try {
            $repository->getDirectory();
            self::fail('Expected missing mock directory error');
        } catch (RepositoryNotFoundException $e) {
            self::assertSame(ErrorCode::MOCK_DIRECTORY_NOT_FOUND, $e->errorCode);
        }
    }

    public function testMockDirectoryIsValidatedOnPull(): void
    {
        $manager = new MixSecVcsClient();
        $manager->setAllowMockDir(true);
        $manager->addMockDir('demo', 'main', sys_get_temp_dir() . '/missing-mix-vcs-' . bin2hex(random_bytes(6)));
        $repository = $manager->getRepository('demo', 'main');
        $this->expectException(RepositoryNotFoundException::class);
        $repository->pull();
    }

    public function testUnusedInvalidMockDoesNotBlockOtherRepositories(): void
    {
        $manager = new MixSecVcsClient();
        $manager->setAllowMockDir(true);
        $manager->addMockDir('unused', 'main', '/missing-mix-vcs-' . bin2hex(random_bytes(6)));
        $manager->addMockDir('demo', 'main', sys_get_temp_dir());
        self::assertSame(realpath(sys_get_temp_dir()), $manager->getRepository('demo', 'main')->getDirectory());
    }

    public function testRabbitMqUrlDoesNotConnectWhenMockMatches(): void
    {
        $directory = sys_get_temp_dir() . '/micx-remote-mock-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $sshUrl = 'git@github.com:example/repo1.git';
        $manager = new MixSecVcsClient(sharedDirectory: realpath(sys_get_temp_dir()), connector: 'rabbitmq://user:password@unreachable.invalid:5672');
        $manager->setAllowMockDir(true);
        $manager->addMockDir($sshUrl, 'main', $directory);

        $repo = $manager->getRepository($sshUrl, 'main');
        self::assertSame(realpath($directory), $repo->getDirectory());
        $repo->pull();
        self::assertSame(realpath($directory), $repo->getDirectory());
        self::assertSame('main', $repo->getBranches()[0]->name);
        rmdir($directory);
    }

    public function testMockSelectorsDoNotInitializeRemoteConnections(): void
    {
        $directory = sys_get_temp_dir() . '/micx-selectors-mock-' . bin2hex(random_bytes(8));
        mkdir($directory);
        foreach ([null, 'mock', 'file', 'mock://local', 'file:///tmp'] as $connector) {
            $manager = new MixSecVcsClient(connector: $connector);
            $manager->setAllowMockDir(true);
            $manager->addMockDir('repo1.git', 'main', $directory);
            $repo = $manager->getRepository('repo1.git');
            $repo->pull();
            self::assertSame(realpath($directory), $repo->getDirectory());
            try {
                $manager->getRepository('repo2.git');
            } catch (RepositoryNotFoundException $e) {
                self::assertSame(ErrorCode::MOCK_REPOSITORY_NOT_FOUND, $e->errorCode);
                continue;
            }
            self::fail('Unmapped repository should require a connector');
        }
        rmdir($directory);
    }

    public function testMockBranchUsesExactMapping(): void
    {
        $manager = new MixSecVcsClient(connector: 'file');
        $manager->setAllowMockDir(true);
        $manager->addMockDir('git@github.com:example/repo1.git', 'main', sys_get_temp_dir());

        $this->expectException(RepositoryNotFoundException::class);
        $manager->getRepository('git@github.com:example/repo1.git', 'develop');
    }

    public function testTransportFactoryRecognizesMockAndRabbitMqUrls(): void
    {
        foreach ([null, '', 'mock', 'file', 'mock://local', 'file:///tmp'] as $url) {
            self::assertFalse(TransportFactory::validate($url));
        }

        self::assertTrue(TransportFactory::validate('rabbitmq://user:pass@broker:5672'));
        self::assertTrue(TransportFactory::validate('rabbitmq://user:p%40ss@broker:5672/micx.vcs.v1'));
    }

    public function testTransportFactoryRejectsInvalidTopic(): void
    {
        $this->expectException(InternalException::class);
        TransportFactory::validate('rabbitmq://user:pass@broker:5672/invalid%2Ftopic');
    }

    public function testTransportFactoryRejectsUnsupportedUrl(): void
    {
        $this->expectException(InternalException::class);
        TransportFactory::validate('http://broker:5672');
    }

    public function testMissingSharedDirectoryIsRejectedForRabbitMqConnector(): void
    {
        try {
            new MixSecVcsClient(connector: 'rabbitmq://user:pass@rabbitmq:5672');
            self::fail('Remote connector must require shared directory');
        } catch (InternalException $e) {
            self::assertSame(ErrorCode::SHARED_DIRECTORY_INVALID, $e->errorCode);
        }
    }

    public function testMissingSharedDirectoryIsRejectedForExplicitTransport(): void
    {
        $transport = $this->createMock(RpcTransport::class);
        $this->expectException(InternalException::class);
        new MixSecVcsClient(connector: $transport);
    }

    public function testInvalidSharedDirectoryIsRejectedBeforeAnyBrokerConnection(): void
    {
        $missing = sys_get_temp_dir() . '/mix-vcs-missing-' . bin2hex(random_bytes(6));
        try {
            new MixSecVcsClient(sharedDirectory: $missing, connector: 'rabbitmq://user:pass@rabbitmq:5672');
            self::fail('Missing volume should not be accepted');
        } catch (InternalException $e) {
            self::assertSame(ErrorCode::SHARED_DIRECTORY_INVALID, $e->errorCode);
        }
    }

    public function testDeferredValidationAllowsMockWithMissingSharedDirectory(): void
    {
        $manager = new MixSecVcsClient(
            sharedDirectory: sys_get_temp_dir() . '/mix-vcs-missing-' . bin2hex(random_bytes(6)),
            connector: 'rabbitmq://user:pass@unreachable.invalid:5672',
            deferSharedDirectoryValidation: true,
        );
        $manager->setAllowMockDir(true);
        $manager->addMockDir('demo', 'main', sys_get_temp_dir());
        self::assertSame(realpath(sys_get_temp_dir()), $manager->getRepository('demo')->getDirectory());
    }

    public function testDeferredValidationDoesNotTouchFilesystemOnGetRepository(): void
    {
        $directory = sys_get_temp_dir() . '/mix-vcs-missing-' . bin2hex(random_bytes(6));
        $transport = $this->createMock(RpcTransport::class);
        $transport->expects(self::never())->method('request');
        $manager = new MixSecVcsClient(
            sharedDirectory: $directory,
            connector: $transport,
            deferSharedDirectoryValidation: true,
        );

        $repository = $manager->getRepository('git@example.test:example/site.git', 'main');
        self::assertSame($directory . '/site/main', $repository->getDirectory());
        self::assertDirectoryDoesNotExist($directory);
    }

    public function testDeferredValidationAcceptsExistingDirectoryWithoutRpc(): void
    {
        $transport = $this->createMock(RpcTransport::class);
        $transport->expects(self::never())->method('request');
        $manager = new MixSecVcsClient(
            sharedDirectory: realpath(sys_get_temp_dir()),
            connector: $transport,
            deferSharedDirectoryValidation: true,
        );
        self::assertInstanceOf(VcsRemoteRepository::class, $manager->getRepository('git@example.test:repo.git'));
    }

    public function testMockOnlyDoesNotRequireSharedDirectory(): void
    {
        $directory = sys_get_temp_dir() . '/micx-only-mock-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $manager = new MixSecVcsClient();
        $manager->setAllowMockDir(true);
        $manager->addMockDir('repo1.git', 'main', $directory);
        $repo = $manager->getRepository('repo1.git');
        $repo->pull();
        self::assertSame(realpath($directory), $repo->getDirectory());
        rmdir($directory);
    }

    public function testLocalPathsAreRejectedInRemoteMode(): void
    {
        $transport = $this->createMock(RpcTransport::class);
        $manager = new MixSecVcsClient(realpath(sys_get_temp_dir()), $transport);

        foreach (['/tmp/repo.git', './repo.git', '../repo.git', 'file:///tmp/repo.git', 'repo1.git'] as $url) {
            try {
                $manager->getRepository($url);
                self::fail('Local path accepted as remote repository: ' . $url);
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('Remote-Repository', $e->getMessage());
            }
        }
    }

    public function testRealGitRemoteUrlsAreAcceptedWithoutRpc(): void
    {
        $transport = new class implements RpcTransport {
            public int $calls = 0;
            public function request(array $request, float $timeout): array
            {
                ++$this->calls;
                throw new \LogicException('No RPC expected');
            }
        };

        $manager = new MixSecVcsClient(realpath(sys_get_temp_dir()), $transport);
        foreach ([
            'git@github.com:example/repo1.git',
            'ssh://git@github.com/example/repo1.git',
            'https://github.com/example/repo1.git',
            'git://github.com/example/repo1.git',
        ] as $url) {
            self::assertInstanceOf(VcsRemoteRepository::class, $manager->getRepository($url));
        }
        self::assertSame(0, $transport->calls);
    }

    public function testDisabledMockCannotBeUsedWithoutConnector(): void
    {
        $directory = sys_get_temp_dir();
        $manager = new MixSecVcsClient();
        $manager->addMockDir('demo', 'main', $directory);
        $this->expectException(RepositoryNotFoundException::class);
        $manager->getRepository('demo', 'main');
    }
}
