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

    public function testStartupInfoLogsOnlyDirectDirectoriesAndMockWarning(): void
    {
        $base = sys_get_temp_dir() . '/micx-startup-info-' . bin2hex(random_bytes(8));
        mkdir($base, 0770, true);
        mkdir($base . '/first');
        mkdir($base . '/first/nested');
        mkdir($base . '/second');
        file_put_contents($base . '/secret-file.txt', 'MUST_NOT_LOG');
        symlink($base . '/first', $base . '/link-to-first');

        $script = __DIR__ . '/../docker/startup-info.php';
        $run = static function (string $mock) use ($script, $base): array {
            $process = proc_open(
                [PHP_BINARY, $script],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                [
                    'CONF_DATA_DIR' => $base,
                    'CONF_MOCK_MODE' => $mock,
                    'CONF_AMQ_CONNECTION' => 'rabbitmq://user:SECRET_PASSWORD@host/',
                ],
            );
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            return [proc_close($process), $stdout, $stderr];
        };

        [$exit, $out, $log] = $run('true');
        self::assertSame(0, $exit);
        self::assertSame('', $out);
        self::assertSame(1, substr_count($log, 'VCS STARTUP CONFIGURATION'));
        self::assertStringContainsString('CONF_DATA_DIR=' . json_encode($base, JSON_UNESCAPED_SLASHES), $log);
        self::assertStringContainsString('CONF_MOCK_MODE=true', $log);
        self::assertStringContainsString('worker_uid=', $log);
        self::assertStringContainsString('worker_gid=', $log);
        self::assertStringContainsString('data_owner_uid=', $log);
        self::assertStringContainsString('data_mode=', $log);
        self::assertStringContainsString('worker_access=read:', $log);
        self::assertStringContainsString('immediate_subdirectories=["first","second"]', $log);
        self::assertStringNotContainsString('secret-file.txt', $log);
        self::assertStringNotContainsString('"nested"', $log);
        self::assertStringNotContainsString('link-to-first', $log);
        self::assertStringNotContainsString('MUST_NOT_LOG', $log);
        self::assertStringNotContainsString('SECRET_PASSWORD', $log);
        self::assertStringContainsString('WARNING: VCS WORKER RUNNING IN MOCK MODE', $log);
        self::assertStringContainsString('CONF_MOCK_MODE=false', $log);

        [$exit, , $productionLog] = $run('false');
        self::assertSame(0, $exit);
        self::assertStringContainsString('CONF_MOCK_MODE=false', $productionLog);
        self::assertStringNotContainsString('WARNING: VCS WORKER RUNNING IN MOCK MODE', $productionLog);
    }

    public function testStartupDiagnosticsExplainUnwritableMount(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Root bypasses normal filesystem permission checks.');
        }

        $base = sys_get_temp_dir() . '/micx-locked-mount-' . bin2hex(random_bytes(8));
        mkdir($base, 0700);
        chmod($base, 0500);
        try {
            $process = proc_open(
                [PHP_BINARY, __DIR__ . '/../docker/startup-info.php'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                ['CONF_DATA_DIR' => $base, 'CONF_MOCK_MODE' => 'false'],
            );
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            self::assertNotSame(0, proc_close($process));
            self::assertSame('', $stdout);
            self::assertStringContainsString('CONF_DATA_DIR_ACCESS_DENIED', $stderr);
            self::assertStringContainsString('write', $stderr);
            self::assertStringContainsString($base, $stderr);
            self::assertStringContainsString('worker_uid=', $stderr);
            self::assertStringContainsString('data_owner_uid=', $stderr);
            self::assertStringContainsString('data_mode=0500', $stderr);
            self::assertStringContainsString('worker_access=read:yes,write:no,traverse:yes', $stderr);
            self::assertStringContainsString('Directory metadata (ls -ld equivalent)', $stderr);
        } finally {
            chmod($base, 0700);
            rmdir($base);
        }
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
