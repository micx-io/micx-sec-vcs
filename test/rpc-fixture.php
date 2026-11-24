<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
$c=new AMQPStreamConnection('127.0.0.1',5672,'guest','guest'); $ch=$c->channel();
$ch->exchange_declare('micx.vcs.v1','topic',false,true,false);
$ch->queue_declare('micx.vcs.v1.fixture',false,false,true,true);
$ch->queue_bind('micx.vcs.v1.fixture','micx.vcs.v1','rpc.request');
$pending=[];
$ch->basic_consume('micx.vcs.v1.fixture','',false,false,false,false,function(AMQPMessage $m) use ($ch,&$pending) {
    $r=json_decode($m->getBody(),true,64,JSON_THROW_ON_ERROR);
    if ($r['method']==='reverse') {
        $pending[]=[$r,$m->get('reply_to')];
        $m->ack();
        if (count($pending)<2) return;
        foreach (array_reverse($pending) as [$item,$destination]) {
            $ch->basic_publish(new AMQPMessage(json_encode(['version'=>1,'id'=>$item['id'],'ok'=>true,'result'=>['echo'=>$item['params']]]),['correlation_id'=>$item['id']]),'',$destination);
        }
        $pending=[]; return;
    }
    if ($r['method']==='malformed') {
        $ch->basic_publish(new AMQPMessage('not JSON',['correlation_id'=>$r['id']]),'',$m->get('reply_to'));
        $m->ack(); return;
    }
    $response=['version'=>1,'id'=>$r['id'],'ok'=>true,'result'=>['echo'=>$r['params']]];
    $ch->basic_publish(new AMQPMessage('{}'), '',$m->get('reply_to'));
    $ch->basic_publish(new AMQPMessage('{}',['correlation_id'=>'unrelated']), '',$m->get('reply_to'));
    $ch->basic_publish(new AMQPMessage(json_encode($response),['correlation_id'=>$r['id']]), '',$m->get('reply_to'));
    $m->ack();
});
file_put_contents(sys_get_temp_dir().'/micx-rpc-ready','ready');
while($ch->is_consuming()) $ch->wait();
