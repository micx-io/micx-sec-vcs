<?php
declare(strict_types=1);
use Micx\SecVcs\{Storage,Fault};
use PHPUnit\Framework\TestCase;
final class StorageTest extends TestCase
{
    public function testRejectsTraversalAndGitMetadata(): void
    {
        foreach (['../key','/etc/passwd','a/.git/config','a//b','a/../b',"a\0b",'a\\b'] as $path) {
            try { Storage::relative($path); self::fail('Accepted '.$path); } catch (Fault $e) { self::assertSame('INVALID_PATH',$e->kind); }
        }
    }
    public function testRejectsInjectedOriginsAndBranches(): void
    {
        foreach (['-oProxyCommand=evil','file:///etc','git@host:../secret','ssh://git@host/repo'] as $url) {
            try { Storage::url($url); self::fail(); } catch (Fault) { self::assertTrue(true); }
        }
        foreach (['main..evil','-b','a/.lock','a//b','a.lock'] as $branch) {
            try { Storage::branch($branch); self::fail(); } catch (Fault) { self::assertTrue(true); }
        }
    }
    public function testSymlinkEscapeAndSlugCollisions(): void
    {
        $base=sys_get_temp_dir().'/micx-'.bin2hex(random_bytes(8));
        $s=new Storage($base.'/data',$base.'/state');
        mkdir($base.'/data/repo'); symlink('/etc',$base.'/data/repo/link');
        self::assertNotSame($s->location('git@host:a/b','feature/a','id',null,false),$s->location('git@host:a/b','feature_a','id',null,false));
        try { $s->path($base.'/data/repo','link/passwd'); self::fail(); }
        catch (Fault $e) { self::assertSame('INVALID_PATH',$e->kind); }
        unlink($base.'/data/repo/link'); rmdir($base.'/data/repo');
    }
}
