<?php
declare(strict_types=1);
use Micx\SecVcs\{Storage,Dispatcher};
use PHPUnit\Framework\TestCase;
final class DispatcherTest extends TestCase
{
    public function testDuplicateWriteRunsOnceAndIdCannotBeReused(): void
    {
        $base=sys_get_temp_dir().'/micx-'.bin2hex(random_bytes(8));
        $s=new Storage($base.'/data',$base.'/state'); $calls=0;
        $d=new Dispatcher($s,function() use (&$calls) { ++$calls; return ['revision'=>'new']; });
        $r=['version'=>1,'id'=>'request-123','method'=>'commit','params'=>[]];
        self::assertSame($d->handle($r),$d->handle($r)); self::assertSame(1,$calls);
        $r['method']='push'; self::assertSame('ID_REUSED',$d->handle($r)['error']['code']);
    }
    public function testInterruptedWriteDoesNotRunAgain(): void
    {
        $base=sys_get_temp_dir().'/micx-'.bin2hex(random_bytes(8));
        $s=new Storage($base.'/data',$base.'/state');
        $r=['version'=>1,'id'=>'request-123','method'=>'commit','params'=>[]];
        $s->save($base.'/state/requests/'.hash('sha256',$r['id']).'.json',['hash'=>hash('sha256',json_encode($r))]);
        $d=new Dispatcher($s,function(){ self::fail('Must not replay interrupted mutation'); });
        self::assertSame('OUTCOME_UNKNOWN',$d->handle($r)['error']['code']);
    }
}
