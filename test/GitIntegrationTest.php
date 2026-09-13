<?php
declare(strict_types=1);
use Micx\SecVcs\SecureGit;
use PHPUnit\Framework\TestCase;
final class GitIntegrationTest extends TestCase
{
    public function testPrivateMetadataCommitAndArchive(): void
    {
        $base=sys_get_temp_dir().'/micx-git-'.bin2hex(random_bytes(8)); mkdir($base); mkdir($base.'/work');
        $git=new SecureGit('git@example.test:repo.git',$base.'/work',$base.'/private.git',$base.'/key',$base.'/known_hosts');
        $git->run(['init','--bare',$base.'/private.git']);
        $git->run(['config','core.bare','false']);
        $git->run(['config','core.worktree',$base.'/work']);
        file_put_contents($base.'/work/a.txt','hello');
        $git->commit('First commit'); $first=$git->getRev();
        self::assertMatchesRegularExpression('/^[a-f0-9]{40,64}$/',$first);
        self::assertFileDoesNotExist($base.'/work/.git');
        file_put_contents($base.'/work/a.txt','updated'); $git->commit('Update');
        self::assertNotSame($first,$git->getRev());
        self::assertStringStartsWith('PK',$git->run(['archive','--format=zip','HEAD']));
        self::assertSame('', $git->run(['status','--porcelain']));
    }
    public function testServiceGenerationAndTransfers(): void
    {
        $base=sys_get_temp_dir().'/micx-service-'.bin2hex(random_bytes(8));
        $storage=new \Micx\SecVcs\Storage($base.'/data',$base.'/state');
        $id=hash('sha256','fixture'); $work=$base.'/data/work'; mkdir($work);
        $git=new SecureGit('git@example.test:repo.git',$work,$base.'/state/workspaces/'.$id.'.git',$base.'/key',$base.'/known_hosts');
        $git->run(['init','--bare',$base.'/state/workspaces/'.$id.'.git']);
        $git->run(['config','core.bare','false']); $git->run(['config','core.worktree',$work]);
        file_put_contents($work.'/a.txt','first'); $git->commit('Initial');
        $meta=['workspace'=>$id,'path'=>$work,'url'=>'git@example.test:repo.git','branch'=>'main','defaultBranch'=>'main','generation'=>0,'temporary'=>false];
        $storage->save($storage->metadata($id),$meta);
        $service=new \Micx\SecVcs\Service($storage,$base.'/key',$base.'/known_hosts');
        $args=['workspace'=>$id,'expectedRevision'=>$git->getRev(),'expectedGeneration'=>0,'files'=>[['path'=>'a.txt','content'=>base64_encode('second')]]];
        $updated=$service->execute('update',$args); self::assertSame(1,$updated['generation']);
        try { $service->execute('update',$args); self::fail('Stale writer accepted'); }
        catch (\Micx\SecVcs\Fault $e) { self::assertSame('CONFLICT',$e->kind); }
        self::assertSame('second',base64_decode($service->execute('read',['workspace'=>$id,'paths'=>['a.txt']])['files'][0]['content']));
        self::assertSame('first',base64_decode($service->execute('snapshot',['workspace'=>$id])['files'][0]['content']));
        $service->execute('commit',['workspace'=>$id,'expectedRevision'=>$updated['revision'],'expectedGeneration'=>1,'message'=>'Changed']);
        self::assertSame('second',base64_decode($service->execute('snapshot',['workspace'=>$id])['files'][0]['content']));
    }
    public function testCreateWorkspaceAndFirstCommitWithoutMetadata(): void
    {
        $base=sys_get_temp_dir().'/micx-create-'.bin2hex(random_bytes(8));
        $storage=new \Micx\SecVcs\Storage($base.'/data',$base.'/state');
        $service=new \Micx\SecVcs\Service($storage,$base.'/key',$base.'/known_hosts');
        $repo=$service->execute('create',['url'=>'git@example.test:new.git','directory'=>'project']);
        self::assertSame($base.'/data/project',$repo['path']);
        self::assertSame('',$repo['revision']);
        self::assertSame('main',$repo['branch']);
        self::assertFileDoesNotExist($repo['path'].'/.git');
        $args=['workspace'=>$repo['workspace']];
        $service->execute('update',$args+['files'=>[['path'=>'hello.txt','content'=>base64_encode('Hello')]]]);
        $commit=$service->execute('commit',$args);
        self::assertMatchesRegularExpression('/^[a-f0-9]{40,64}$/',$commit['revision']);
        self::assertSame($commit['revision'],$service->execute('commit',$args)['revision']);
        self::assertSame($repo['workspace'],$service->execute('create',['url'=>'git@example.test:new.git','directory'=>'project'])['workspace']);
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
