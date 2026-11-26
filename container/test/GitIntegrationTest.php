<?php
declare(strict_types=1);
use Micx\SecVcs\SecureGit;
use PHPUnit\Framework\TestCase;
final class GitIntegrationTest extends TestCase
{
    public function testLocalGitMetadataCommitAndArchive(): void
    {
        $base=sys_get_temp_dir().'/micx-git-'.bin2hex(random_bytes(8)); mkdir($base); mkdir($base.'/work');
        $git=new SecureGit('git@example.test:repo.git',$base.'/work',$base.'/work/.git',$base.'/key',$base.'/known_hosts');
        $git->run(['init','--bare',$base.'/work/.git']);
        $git->run(['config','core.bare','false']);
        $git->run(['config','core.worktree',$base.'/work']);
        file_put_contents($base.'/work/a.txt','hello');
        $git->commit('First commit'); $first=$git->getRev();
        self::assertMatchesRegularExpression('/^[a-f0-9]{40,64}$/',$first);
        self::assertFileExists($base.'/work/.git/HEAD');
        file_put_contents($base.'/work/a.txt','updated'); $git->commit('Update');
        self::assertNotSame($first,$git->getRev());
        self::assertStringStartsWith('PK',$git->run(['archive','--format=zip','HEAD']));
        self::assertSame('', $git->run(['status','--porcelain']));
    }
    public function testCommitUsesRpcAuthorAndKeepsDefaultForFollowingCommit(): void
    {
        $base = sys_get_temp_dir() . '/micx-author-' . bin2hex(random_bytes(8));
        mkdir($base . '/data', 0770, true);
        $storage = new \Micx\SecVcs\Storage($base . '/data');
        $directory = $base . '/data/author/main';
        mkdir($directory, 0770, true);

        $git = new SecureGit('git@example.test:author.git', $directory, $directory . '/.git', $base . '/key', $base . '/known_hosts');
        $git->initialize('main', true);
        $service = new \Micx\SecVcs\Service($storage, $base . '/key', $base . '/known_hosts');
        $params = ['url' => 'git@example.test:author.git', 'branch' => 'main', 'directory' => 'author/main'];

        file_put_contents($directory . '/test.txt', 'one');
        $service->execute('commit', $params + [
            'message' => 'Authored commit',
            'authorName' => 'Jane Doe',
            'authorEmail' => 'jane@example.com',
        ]);

        self::assertSame('Jane Doe <jane@example.com>', trim($git->run(['show', '-s', '--format=%an <%ae>', 'HEAD'])));
        self::assertSame('Jane Doe <jane@example.com>', trim($git->run(['show', '-s', '--format=%cn <%ce>', 'HEAD'])));

        file_put_contents($directory . '/test.txt', 'two');
        $service->execute('commit', $params + ['message' => 'Default author']);
        self::assertSame('MICX <micx@localhost>', trim($git->run(['show', '-s', '--format=%an <%ae>', 'HEAD'])));

        try {
            $service->execute('commit', $params + [
                'message' => 'Invalid author',
                'authorName' => "Bad\nName",
                'authorEmail' => 'bad@example.com',
            ]);
            self::fail('Expected invalid commit identity');
        } catch (\Micx\SecVcs\Fault $e) {
            self::assertSame('INVALID_REQUEST', $e->kind);
        }
    }

    public function testSdkMethodsUseClientDirectoryWithoutWorkspaceRegistry(): void
    {
        $base = sys_get_temp_dir() . '/micx-service-' . bin2hex(random_bytes(8));
        mkdir($base . '/data', 0770, true);
        $storage = new \Micx\SecVcs\Storage($base . '/data');
        $directory = $base . '/data/repo/main';
        mkdir($directory, 0770, true);
        $git = new SecureGit(
            'git@example.test:repo.git',
            $directory,
            $directory . '/.git',
            $base . '/key',
            $base . '/known_hosts'
        );
        $git->initialize('main', true);

        $service = new \Micx\SecVcs\Service($storage, $base . '/key', $base . '/known_hosts');
        $params = ['url' => 'git@example.test:repo.git', 'branch' => 'main', 'directory' => 'repo/main'];

        self::assertFalse($service->execute('status', $params)['hasUnpushedChanges']);
        self::assertSame([], $service->execute('log', $params)['entries']);
        self::assertSame([], $service->execute('branches', $params)['branches']);
        file_put_contents($directory . '/hello.txt', 'Hello');

        self::assertTrue($service->execute('status', $params)['hasUnpushedChanges']);
        $committed = $service->execute('commit', $params + ['message' => 'First']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{40,64}$/', $committed['revision']);
        self::assertSame($committed['revision'], $service->execute('commit', $params)['revision']);
        self::assertSame('First', $service->execute('log', $params)['entries'][0]['message']);
        self::assertSame('main', $service->execute('branches', $params)['branches'][0]['name']);

        $git->run(['tag', '-a', 'v1.0', '-m', 'First release']);
        file_put_contents($directory . '/hello.txt', "Second\0version\n");
        $service->execute('commit', $params + ['message' => 'Second']);
        file_put_contents($directory . '/hello.txt', 'Uncommitted');
        $head = $git->getRev();
        $status = $git->run(['status', '--porcelain=v1']);
        $fileParams = $params + ['path' => 'hello.txt'];
        self::assertSame(base64_encode("Second\0version\n"), $service->execute('getFile', $fileParams)['contentBase64']);
        self::assertSame(base64_encode('Hello'), $service->execute('getFile', $fileParams + ['revision' => 'v1.0'])['contentBase64']);
        self::assertSame(base64_encode('Hello'), $service->execute('getFile', $fileParams + ['revision' => $committed['revision']])['contentBase64']);
        self::assertSame('Uncommitted', file_get_contents($directory . '/hello.txt'));
        self::assertSame($head, $git->getRev());
        self::assertSame($status, $git->run(['status', '--porcelain=v1']));

        self::assertSame(['exists' => false, 'contentBase64' => null], $service->execute('getFile', $fileParams + ['revision' => null]));
        self::assertSame(['exists' => false, 'contentBase64' => null], $service->execute('getFile', $params + ['path' => 'missing.txt']));
        try {
            $service->execute('getFile', $fileParams + ['revision' => str_repeat('0', 40)]);
            self::fail('Invalid revisions must not be treated as missing files.');
        } catch (\Micx\SecVcs\Fault $error) {
            self::assertSame('GIT_FAILED', $error->kind);
        }

        // Compare with the last known remote state, not with local HEAD.
        file_put_contents($directory . '/deleted.txt', 'delete me');
        $service->execute('commit', $params + ['message' => 'Published']);
        $git->run(['update-ref', 'refs/remotes/origin/main', $git->getRev()]);
        $git->run(['tag', 'published']);
        file_put_contents($directory . '/hello.txt', 'Local commit');
        $service->execute('commit', $params + ['message' => 'Local only']);
        unlink($directory . '/deleted.txt');
        file_put_contents($directory . '/added.txt', 'Untracked');
        file_put_contents($directory . '/staged.txt', 'Staged');
        $git->run(['add', '--', 'staged.txt']);
        $head = $git->getRev();
        $status = $git->run(['status', '--porcelain=v1']);
        self::assertSame(base64_encode('Uncommitted'), $service->execute('getFile', $fileParams + ['revision' => null])['contentBase64']);
        self::assertSame(base64_encode('Local commit'), $service->execute('getFile', $fileParams)['contentBase64']);
        self::assertSame(['exists' => false, 'contentBase64' => null], $service->execute('getFile', $params + ['path' => 'added.txt', 'revision' => null]));
        self::assertSame(base64_encode('delete me'), $service->execute('getFile', $params + ['path' => 'deleted.txt', 'revision' => null])['contentBase64']);
        $expected = ['added' => ['added.txt', 'staged.txt'], 'modified' => ['hello.txt'], 'deleted' => ['deleted.txt']];
        self::assertSame($expected, $service->execute('getChangedFiles', $params));
        self::assertSame($expected, $service->execute('getChangedFiles', $params + ['revision' => 'published']));
        self::assertSame(
            ['added' => ['added.txt', 'staged.txt'], 'modified' => ['hello.txt'], 'deleted' => []],
            $service->execute('getChangedFiles', $params + ['revision' => $committed['revision']])
        );
        self::assertSame(
            ['added' => ['added.txt', 'staged.txt'], 'modified' => [], 'deleted' => ['deleted.txt']],
            $service->execute('getChangedFiles', $params + ['revision' => 'HEAD'])
        );
        self::assertSame(
            ['added' => ['deleted.txt'], 'modified' => ['hello.txt'], 'deleted' => []],
            $service->execute('getChangedFiles', $params + ['revision' => 'v1.0', 'targetRevision' => 'published'])
        );
        self::assertSame($head, $git->getRev());
        self::assertSame($status, $git->run(['status', '--porcelain=v1']));

        $git->run(['config', 'core.excludesFile', $base . '/ignored-patterns']);
        file_put_contents($base . '/ignored-patterns', "keep-local/\n");
        mkdir($directory . '/keep-local');
        file_put_contents($directory . '/keep-local/config', 'Keep');
        $service->execute('purgeChanged', $params);
        self::assertSame('Uncommitted', file_get_contents($directory . '/hello.txt'));
        self::assertFileExists($directory . '/deleted.txt');
        self::assertFileDoesNotExist($directory . '/added.txt');
        self::assertFileDoesNotExist($directory . '/staged.txt');
        self::assertSame('Keep', file_get_contents($directory . '/keep-local/config'));
        self::assertSame(['added' => [], 'modified' => [], 'deleted' => []], $service->execute('getChangedFiles', $params));

        try {
            $service->execute('status', array_replace($params, ['directory' => '../invalid']));
            self::fail('Traversal accepted');
        } catch (\Micx\SecVcs\Fault $e) {
            self::assertSame('INVALID_PATH', $e->kind);
        }
    }

    public function testMergeKeepsOursAndAcceptsNonConflictingRemoteChanges(): void
    {
        $base=sys_get_temp_dir().'/micx-merge-'.bin2hex(random_bytes(8)); mkdir($base); mkdir($base.'/work');
        $git=new SecureGit('git@example.test:repo.git',$base.'/work',$base.'/private.git',$base.'/key',$base.'/known_hosts');
        $git->initialize('main', true);
        foreach (['content.txt','deleted-locally.txt','deleted-remotely.txt','binary.dat'] as $path) {
            file_put_contents($base.'/work/'.$path, "base\n");
        }
        $git->commit('Base');
        $git->run(['checkout','-b','incoming']);
        file_put_contents($base.'/work/content.txt', "remote\n");
        file_put_contents($base.'/work/deleted-locally.txt', "remote\n");
        file_put_contents($base.'/work/binary.dat', "remote\0binary");
        unlink($base.'/work/deleted-remotely.txt');
        file_put_contents($base.'/work/remote-only.txt','keep remote');
        $git->commit('Remote changes');
        $git->run(['checkout','main']);
        file_put_contents($base.'/work/content.txt', "ours\n");
        file_put_contents($base.'/work/deleted-remotely.txt', "ours\n");
        file_put_contents($base.'/work/binary.dat', "ours\0binary");
        unlink($base.'/work/deleted-locally.txt');
        $git->commit('Our changes');
        $git->mergeOurs('incoming');
        self::assertSame("ours\n",file_get_contents($base.'/work/content.txt'));
        self::assertSame("ours\n",file_get_contents($base.'/work/deleted-remotely.txt'));
        self::assertSame("ours\0binary",file_get_contents($base.'/work/binary.dat'));
        self::assertFileDoesNotExist($base.'/work/deleted-locally.txt');
        self::assertSame('keep remote',file_get_contents($base.'/work/remote-only.txt'));
        self::assertSame('',$git->run(['status','--porcelain']));
        self::assertCount(3,explode(' ',trim($git->run(['rev-list','--parents','-n','1','HEAD']))));
    }
    public function testCheckoutAndMergeCreateSharedFilesWithPrivateGitMetadata(): void
    {
        $base = sys_get_temp_dir() . '/micx-shared-' . bin2hex(random_bytes(8));
        $work = $base . '/work';
        $previousUmask = umask(0077);
        try {
            mkdir($work, 0700, true);
            $git = new SecureGit('git@example.test:repo.git', $work, $work . '/.git', $base . '/key', $base . '/known_hosts');
            $git->initialize('main', true);

            // Eine unveränderte Datei darf auch mit restriktiven Rechten nicht chmodded werden.
            file_put_contents($work . '/unchanged.txt', 'keep');
            $git->commit('Base');
            $git->run(['checkout', '-b', 'incoming']);
            mkdir($work . '/nested');
            file_put_contents($work . '/nested/file.txt', 'new');
            file_put_contents($work . '/script.sh', "#!/bin/sh\n");
            chmod($work . '/script.sh', 0700);
            $git->commit('Incoming');
            $git->run(['checkout', 'main']);

            // Erstes Materialisieren des Branches und späterer Merge erzeugen dieselben Modi.
            $assertModes = static function () use ($work): void {
                clearstatcache();
                self::assertSame(0777, fileperms($work . '/nested') & 0777);
                self::assertSame(0666, fileperms($work . '/nested/file.txt') & 0777);
                self::assertSame(0777, fileperms($work . '/script.sh') & 0777);
                self::assertSame(0600, fileperms($work . '/unchanged.txt') & 0777);
                self::assertSame(0700, fileperms($work . '/.git') & 0777);
                self::assertSame(0600, fileperms($work . '/.git/index') & 0777);
                self::assertSame(0600, fileperms($work . '/.git/HEAD') & 0777);
                self::assertSame(0077, umask());
            };
            $git->run(['checkout', 'incoming']);
            $assertModes();
            $git->run(['checkout', 'main']);
            $git->mergeOurs('incoming');
            $assertModes();
        } finally {
            umask($previousUmask);
        }
    }

    public function testPermissionScanIgnoresGitMetadataAndDoesNotRepairOrDeleteFiles(): void
    {
        $base = sys_get_temp_dir() . '/micx-permission-' . bin2hex(random_bytes(8));
        $work = $base . '/repo';
        $previousUmask = umask(0000);
        try {
            mkdir($work, 0777, true);
        } finally {
            umask($previousUmask);
        }
        $storage = new \Micx\SecVcs\Storage($base);
        $url = 'git@example.test:repo.git';
        $git = new SecureGit($url, $work, $work . '/.git', $base . '/key', $base . '/known_hosts');
        $git->initialize('main', true);

        // Die Datei gehört zu den Metadaten und ist absichtlich nicht beschreibbar.
        file_put_contents($work . '/.git/private-test', 'private');
        chmod($work . '/.git/private-test', 0400);
        $service = new \Micx\SecVcs\Service($storage, $base . '/key', $base . '/known_hosts');
        $params = ['url' => $url, 'branch' => 'main', 'directory' => 'repo'];
        self::assertFalse($service->execute('status', $params)['hasUnpushedChanges']);

        // Pull meldet fehlende gemeinsame Rechte, ohne fremde Dateien anzufassen.
        file_put_contents($work . '/restricted.txt', 'preserve me');
        chmod($work . '/restricted.txt', 0600);
        try {
            $service->execute('pull', $params);
            self::fail('Expected shared permission error before SSH access');
        } catch (\Micx\SecVcs\Fault $e) {
            self::assertSame('IO_ERROR', $e->kind);
            self::assertStringContainsString($work . '/restricted.txt', $e->getMessage());
        }
        clearstatcache();
        self::assertSame('preserve me', file_get_contents($work . '/restricted.txt'));
        self::assertSame(0600, fileperms($work . '/restricted.txt') & 0777);
        self::assertSame(0400, fileperms($work . '/.git/private-test') & 0777);
        self::assertFileExists($work . '/.git/HEAD');
    }

    public function testGitDiagnosticsBecomeSafeActionableFaults(): void
    {
        $base=sys_get_temp_dir().'/micx-errors-'.bin2hex(random_bytes(8)); mkdir($base); mkdir($base.'/work');
        $git=new SecureGit('git@example.test:repo.git',$base.'/work',$base.'/private.git',$base.'/key',$base.'/known_hosts');
        $git->run(['init','--bare',$base.'/private.git']);
        $cases = [
            'Load key "/secret/key": invalid format'=>'SSH_KEY_INVALID',
            'Permission denied (publickey).'=>'SSH_AUTH_FAILED',
            'Host key verification failed.'=>'SSH_HOST_KEY_FAILED',
            'ERROR: Repository not found.'=>'REPOSITORY_UNAVAILABLE',
            'ssh: Could not resolve hostname example.test'=>'REMOTE_UNREACHABLE',
            "fatal: couldn't find remote ref missing"=>'BRANCH_NOT_FOUND',
            '! [rejected] main -> main (non-fast-forward)'=>'PUSH_REJECTED',
            'No space left on device'=>'IO_ERROR',
            'unexpected failure'=>'GIT_FAILED',
        ];
        foreach ($cases as $diagnostic=>$code) {
            // Real Git subprocess with deterministic stderr; no SSH service or secret required.
            $command='!printf %s '.escapeshellarg($diagnostic.' PRIVATE_SENTINEL').' >&2; false';
            try { $git->run(['-c','alias.fail='.$command,'fail']); self::fail('Expected Git failure'); }
            catch (\Micx\SecVcs\Fault $e) {
                self::assertSame($code,$e->kind);
                self::assertNotSame('Git operation failed',$e->getMessage());
                self::assertStringNotContainsString('PRIVATE_SENTINEL',json_encode([$e->getMessage(),$e->details]));
                self::assertStringNotContainsString('/secret/key',$e->getMessage());
            }
        }
    }
}
