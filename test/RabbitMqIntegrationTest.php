<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
final class RabbitMqIntegrationTest extends TestCase
{
    public function testWorkerReturnsAndReplaysValidationError(): void
    {
        if (!getenv('AMQP_TEST_HOST')) self::markTestSkipped('Set AMQP_TEST_HOST and start src/worker.php');
        $c=new AMQPStreamConnection(getenv('AMQP_TEST_HOST'),5672,'guest','guest'); $ch=$c->channel();
        try {
            [$reply]=$ch->queue_declare('micx.vcs.reply.'.bin2hex(random_bytes(16)),false,false,true,true);
            $id=bin2hex(random_bytes(16));
            $body=json_encode(['version'=>1,'id'=>$id,'method'=>'checkout','params'=>['url'=>'file:///etc']]);
            $responses=[];
            $ch->basic_consume($reply,'',false,true,false,false,function(AMQPMessage $m) use (&$responses,$id) {
                self::assertSame($id,$m->get('correlation_id'));
                $responses[]=json_decode($m->getBody(),true);
            });
            for($i=0;$i<2;++$i) {
                $ch->basic_publish(new AMQPMessage($body,['correlation_id'=>$id,'reply_to'=>$reply]),'micx.vcs.v1','rpc.request');
                while(count($responses)<$i+1) $ch->wait(null,false,5);
            }
            self::assertSame('INVALID_REPOSITORY',$responses[0]['error']['code']);
            self::assertSame($responses[0],$responses[1]);
        } finally { $ch->close(); $c->close(); }
    }
}
