<?php
declare(strict_types=1);
use Micx\SecVcs\Storage;
use PHPUnit\Framework\TestCase;
final class ConcurrencyTest extends TestCase
{
    public function testConcurrentDuplicateRequestsExecuteOnce(): void
    {
        $base=sys_get_temp_dir().'/micx-concurrent-'.bin2hex(random_bytes(8));
        new Storage($base.'/data',$base.'/state');
        $code= <<<'CHILD'
require $argv[1];
$s=new Micx\SecVcs\Storage($argv[2].'/data',$argv[2].'/state');
$d=new Micx\SecVcs\Dispatcher($s,function() use ($argv) {
    file_put_contents($argv[2].'/executions','write',FILE_APPEND);
    usleep(250000);
    return ['revision'=>'one'];
});
file_put_contents($argv[2].'/reply-'.$argv[3],json_encode($d->handle(['version'=>1,'id'=>'parallel-123','method'=>'commit','params'=>[]])));
CHILD;
        $children=[];
        for ($i=0;$i<2;++$i) {
            $p=proc_open([PHP_BINARY,'-c',php_ini_loaded_file(),'-r',$code,__DIR__.'/../vendor/autoload.php',$base,(string)$i],[], $pipes);
            self::assertIsResource($p); $children[]=$p;
        }
        foreach ($children as $p) self::assertSame(0,proc_close($p));
        self::assertSame('write',file_get_contents($base.'/executions'));
        self::assertSame(file_get_contents($base.'/reply-0'),file_get_contents($base.'/reply-1'));
        self::assertTrue(json_decode(file_get_contents($base.'/reply-0'),true)['ok']);
    }
    public function testSameWorkspaceWaitsWhileOtherWorkspaceCanRun(): void
    {
        $base=sys_get_temp_dir().'/micx-locks-'.bin2hex(random_bytes(8));
        $s=new Storage($base.'/data',$base.'/state');
        $code= <<<'CHILD'
require $argv[1];
$s=new Micx\SecVcs\Storage($argv[2].'/data',$argv[2].'/state');
$s->lock('workspace:two',function() use ($argv) { file_put_contents($argv[2].'/other','yes'); });
$s->lock('workspace:one',function() use ($argv) { file_put_contents($argv[2].'/same','yes'); });
CHILD;
        $p=null;
        try {
            $s->lock('workspace:one',function() use ($base,$code,&$p) {
                $p=proc_open([PHP_BINARY,'-c',php_ini_loaded_file(),'-r',$code,__DIR__.'/../vendor/autoload.php',$base],[],$pipes);
                self::assertIsResource($p);
                $until=microtime(true)+3;
                while (!is_file($base.'/other') && microtime(true)<$until) usleep(10000);
                self::assertFileExists($base.'/other');
                self::assertFileDoesNotExist($base.'/same');
            });
        } finally { if (is_resource($p)) self::assertSame(0,proc_close($p)); }
        self::assertFileExists($base.'/same');
    }
}
