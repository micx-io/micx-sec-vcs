<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class SupervisorTest extends TestCase
{
    public function testRetryBackoffIsCappedAndSupervisorStopsOnSignal(): void
    {
        $base=sys_get_temp_dir().'/micx-supervisor-'.bin2hex(random_bytes(8)); mkdir($base);
        // Replace waiting only; observe the real supervisor's scheduled delays and launches.
        file_put_contents($base.'/sleep',"#!/bin/sh\nprintf '%s\\n' \"\$1\" >> \"\$SUPERVISOR_TEST_DIR/delays\"\nif [ \"\$(wc -l < \"\$SUPERVISOR_TEST_DIR/delays\")\" -ge 8 ]; then exec /bin/sleep 60; fi\n");
        chmod($base.'/sleep',0700);
        $env=['PATH'=>$base.':/usr/bin:/bin','SUPERVISOR_TEST_DIR'=>$base];
        $p=proc_open(['sh',__DIR__.'/../docker/supervisor.sh','sh','-c','false'],[1=>['file',$base.'/out','a'],2=>['file',$base.'/err','a']],$pipes,null,$env);
        self::assertIsResource($p);
        try {
            $deadline=microtime(true)+5;
            do {
                $delays=is_file($base.'/delays') ? file($base.'/delays',FILE_IGNORE_NEW_LINES) : [];
                if (count($delays)>=8) break;
                usleep(10000);
            } while (microtime(true)<$deadline);
            self::assertSame(['1','2','4','8','16','30','30','30'],$delays);
            self::assertTrue(proc_get_status($p)['running']);
            self::assertStringContainsString('retry in 30s',file_get_contents($base.'/err'));
        } finally {
            proc_terminate($p,SIGTERM);
            $until=microtime(true)+3;
            do { $status=proc_get_status($p); if (!$status['running']) break; usleep(10000); } while (microtime(true)<$until);
            if ($status['running']) proc_terminate($p,SIGKILL);
            proc_close($p);
        }
        self::assertFalse($status['running'],'Supervisor must stop promptly during backoff');
        self::assertStringContainsString('stopped by signal',file_get_contents($base.'/err'));
    }
}
