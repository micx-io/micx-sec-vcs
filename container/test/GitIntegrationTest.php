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
