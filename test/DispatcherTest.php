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
    public function testFaultKeepsCodeMessageAndDetails(): void
    {
        $base=sys_get_temp_dir().'/micx-fault-'.bin2hex(random_bytes(8));
        $d=new Dispatcher(new Storage($base.'/data',$base.'/state'),function() {
            throw new \Micx\SecVcs\Fault('SSH_AUTH_FAILED','SSH authentication failed',['exitCode'=>128]);
        });
        $reply=$d->handle(['version'=>1,'id'=>'failure-123','method'=>'checkout','params'=>[]]);
        self::assertSame('failure-123',$reply['id']);
        self::assertFalse($reply['ok']);
        self::assertSame(['code'=>'SSH_AUTH_FAILED','message'=>'SSH authentication failed','details'=>['exitCode'=>128]],$reply['error']);
    }
    public function testUnreadableJournalDoesNotExecuteOrEscape(): void
    {
        $base=sys_get_temp_dir().'/micx-journal-'.bin2hex(random_bytes(8));
        $s=new Storage($base.'/data',$base.'/state');
        file_put_contents($base.'/state/requests/'.hash('sha256','corrupt-123').'.json','broken json');
        $d=new Dispatcher($s,function() { self::fail('Must not execute with corrupt journal'); });
        self::assertSame('IO_ERROR',$d->handle(['version'=>1,'id'=>'corrupt-123','method'=>'commit','params'=>[]])['error']['code']);
    }
    public function testLostFinalJournalWriteReportsUnknownOutcome(): void
    {
        $base=sys_get_temp_dir().'/micx-final-'.bin2hex(random_bytes(8));
        $s=new Storage($base.'/data',$base.'/state');
        $d=new Dispatcher($s,function() use ($base) {
            $file=$base.'/state/requests/'.hash('sha256','final-123').'.json';
            unlink($file); mkdir($file); // Force rename failure after execution.
            return ['committed'=>true];
        });
        set_error_handler(static fn()=>true);
        try { $reply=$d->handle(['version'=>1,'id'=>'final-123','method'=>'commit','params'=>[]]); }
        finally { restore_error_handler(); }
        self::assertSame('OUTCOME_UNKNOWN',$reply['error']['code']);
    }
}
