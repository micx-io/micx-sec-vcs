<?php

declare(strict_types=1);

use Micx\SecVcs\Fault;
use Micx\SecVcs\Storage;
use PHPUnit\Framework\TestCase;

final class StorageTest extends TestCase
{
    public function testMissingDataRootIsNotCreated(): void
    {
        $missing = sys_get_temp_dir() . '/micx-missing-volume-' . bin2hex(random_bytes(8));

        try {
            new Storage($missing);
            self::fail('Storage created a missing external data directory');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($missing, $e->getMessage());
        }
        self::assertDirectoryDoesNotExist($missing);
    }

    public function testEntrypointRejectsMissingConfiguredDataDirectory(): void
    {
        $entrypoint = __DIR__ . '/../docker/entrypoint.sh';
        $missing = sys_get_temp_dir() . '/micx-missing-mount-' . bin2hex(random_bytes(8));
        $run = static function (array $environment) use ($entrypoint): array {
            $process = proc_open(
                ['sh', $entrypoint],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                $environment,
            );
            self::assertIsResource($process);
            fclose($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[2]);

            return [proc_close($process), $error];
        };

        [$status, $error] = $run(['PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
        self::assertNotSame(0, $status);
        self::assertStringContainsString('CONF_DATA_DIR_MISSING', $error);

        [$status, $error] = $run([
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'CONF_DATA_DIR' => $missing,
        ]);
        self::assertNotSame(0, $status);
        self::assertStringContainsString('CONF_DATA_DIR_NOT_FOUND', $error);
        self::assertStringContainsString($missing, $error);
        self::assertDirectoryDoesNotExist($missing);
    }

    public function testRejectsTraversalAndGitMetadata(): void
    {
        foreach (['../key', '/etc/passwd', 'a/.git/config', '.mix-vcs-locks/request', 'a//b', 'a/../b', "a\0b", 'a\\b'] as $path) {
            try {
                Storage::relative($path);
                self::fail('Accepted ' . $path);
            } catch (Fault $e) {
                self::assertSame('INVALID_PATH', $e->kind);
            }
        }
    }

    public function testRejectsInjectedOriginsAndBranches(): void
    {
        foreach (['-oProxyCommand=evil', 'file:///etc', 'git@host:../secret', 'ssh://git@host/repo'] as $url) {
            try {
                Storage::url($url);
                self::fail('Accepted ' . $url);
            } catch (Fault) {
                self::assertTrue(true);
            }
        }
        foreach (['main..evil', '-b', 'a/.lock', 'a//b', 'a.lock'] as $branch) {
            try {
                Storage::branch($branch);
                self::fail('Accepted ' . $branch);
            } catch (Fault) {
                self::assertTrue(true);
            }
        }
    }

    public function testClientDirectoryRejectsSymlinksAndNestedCheckouts(): void
    {
        $base = sys_get_temp_dir() . '/micx-path-' . bin2hex(random_bytes(8));
        mkdir($base . '/data', 0770, true);
        $storage = new Storage($base . '/data');
        mkdir($base . '/data/repo');
        symlink('/etc', $base . '/data/repo/link');

        self::assertSame($base . '/data/repo/main', $storage->directory('repo/main'));
        try {
            $storage->directory('repo/link/passwd');
            self::fail('Symlink accepted');
        } catch (Fault $e) {
            self::assertSame('INVALID_PATH', $e->kind);
        }

        mkdir($base . '/data/repo/.git');
        file_put_contents($base . '/data/repo/.git/HEAD', 'ref: refs/heads/main');
        try {
            $storage->directory('repo/nested');
            self::fail('Nested checkout accepted');
        } catch (Fault $e) {
            self::assertSame('INVALID_PATH', $e->kind);
        }
    }
}
