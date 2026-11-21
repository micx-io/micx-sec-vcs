<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
final class RabbitMqIntegrationTest extends TestCase
{
    public function testPingReportsMissingKeyBeforeRepositoryIsConfigured(): void
    {
        if (!getenv('AMQP_TEST_HOST')) {
            self::markTestSkipped('Set AMQP_TEST_HOST and start src/worker.php without SSH credentials');
        }
        $connection = new AMQPStreamConnection(getenv('AMQP_TEST_HOST'), 5672, 'guest', 'guest');
        $channel = $connection->channel();
        try {
            [$reply] = $channel->queue_declare('micx.vcs.reply.' . bin2hex(random_bytes(16)), false, false, true, true);
            $id = bin2hex(random_bytes(16));
            $response = null;
            $channel->basic_consume($reply, '', false, true, false, false, function (AMQPMessage $message) use (&$response, $id): void {
                self::assertSame($id, $message->get('correlation_id'));
                $response = json_decode($message->getBody(), true, flags: JSON_THROW_ON_ERROR);
            });
            // Die korrelierte Antwort belegt den vollständigen Broker-/Worker-Rundlauf.
            $channel->basic_publish(new AMQPMessage(
                json_encode(['version' => 1, 'id' => $id, 'method' => 'ping', 'params' => []], JSON_THROW_ON_ERROR),
                ['correlation_id' => $id, 'reply_to' => $reply],
            ), 'micx.vcs.v1', 'rpc.request');
            $deadline = microtime(true) + 5;
            while ($response === null) {
                self::assertGreaterThan(0, $remaining = $deadline - microtime(true));
                $channel->wait(null, false, $remaining);
            }
            self::assertSame($id, $response['id']);
            self::assertTrue($response['ok']);
            self::assertTrue($response['result']['pong']);
            self::assertFalse($response['result']['ready']);
            self::assertContains('SSH_KEY_MISSING', array_column($response['result']['errors'], 'code'));
        } finally {
            $channel->close();
            $connection->close();
        }
    }
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
    public function testConcurrentRequestsEachReceiveExactlyTheirOwnReply(): void
    {
        if (!getenv('AMQP_TEST_HOST')) self::markTestSkipped('Requires running workers');
        $c=new AMQPStreamConnection(getenv('AMQP_TEST_HOST'),5672,'guest','guest'); $ch=$c->channel();
        try {
            [$reply]=$ch->queue_declare('micx.vcs.reply.'.bin2hex(random_bytes(16)),false,false,true,true);
            $expected=[]; $received=[];
            $ch->basic_consume($reply,'',false,true,false,false,function(AMQPMessage $m) use (&$received,&$expected) {
                $id=$m->get('correlation_id'); $body=json_decode($m->getBody(),true);
                self::assertArrayHasKey($id,$expected);
                self::assertArrayNotHasKey($id,$received);
                self::assertSame($id,$body['id']);
                self::assertSame('INVALID_REPOSITORY',$body['error']['code']);
                $received[$id]=true;
            });
            for($i=0;$i<20;++$i) {
                $id=bin2hex(random_bytes(16)); $expected[$id]=true;
                $ch->basic_publish(new AMQPMessage(json_encode(['version'=>1,'id'=>$id,'method'=>'checkout','params'=>['url'=>'file:///invalid-'.$i]]),['correlation_id'=>$id,'reply_to'=>$reply]),'micx.vcs.v1','rpc.request');
            }
            $deadline=microtime(true)+10;
            while(count($received)<20) {
                self::assertGreaterThan(0,$remaining=$deadline-microtime(true));
                $ch->wait(null,false,$remaining);
            }
            self::assertCount(20,$received);
        } finally { $ch->close(); $c->close(); }
    }
    public function testSdkRpcContractWithoutSshKey(): void
    {
        if (!getenv('AMQP_TEST_HOST')) {
            self::markTestSkipped('Requires running worker and broker');
        }

        $connection = new AMQPStreamConnection(getenv('AMQP_TEST_HOST'), 5672, 'guest', 'guest');
        $channel = $connection->channel();
        try {
            [$replyQueue] = $channel->queue_declare(
                'micx.vcs.reply.' . bin2hex(random_bytes(16)), false, false, true, true
            );
            $responses = [];
            $channel->basic_consume($replyQueue, '', false, true, false, false,
                static function (AMQPMessage $message) use (&$responses): void {
                    $responses[$message->get('correlation_id')] = json_decode(
                        $message->getBody(), true, flags: JSON_THROW_ON_ERROR
                    );
                }
            );

            // Derselbe RPC-Umschlag wie im SDK: keine SSH-Konfiguration nötig.
            $call = static function (string $method, array $params) use ($channel, $replyQueue, &$responses): array {
                $id = bin2hex(random_bytes(16));
                $request = ['version' => 1, 'id' => $id, 'method' => $method, 'params' => $params];
                $channel->basic_publish(new AMQPMessage(
                    json_encode($request, JSON_THROW_ON_ERROR),
                    ['correlation_id' => $id, 'reply_to' => $replyQueue]
                ), 'micx.vcs.v1', 'rpc.request');
                $deadline = microtime(true) + 10;
                while (!isset($responses[$id])) {
                    $remaining = $deadline - microtime(true);
                    self::assertGreaterThan(0, $remaining, 'Timeout for RPC ' . $method);
                    $channel->wait(null, false, $remaining);
                }
                $response = $responses[$id];
                unset($responses[$id]);
                self::assertSame($id, $response['id']);
                self::assertTrue($response['ok'], json_encode($response['error'] ?? []));
                return $response['result'];
            };

            $unique = bin2hex(random_bytes(8));
            $repo = $call('create', [
                'url' => 'git@example.test:fixtures/' . $unique . '.git',
                'branch' => 'main',
                'directory' => 'sdk-rpc-' . $unique,
            ]);
            $workspace = ['workspace' => $repo['workspace']];
            self::assertSame('main', $repo['branch']);
            self::assertFalse($call('status', $workspace)['hasUnpushedChanges']);
            self::assertSame([], $call('log', $workspace)['entries']);

            $call('update', $workspace + [
                'files' => [['path' => 'hello.txt', 'content' => base64_encode('hello')]],
            ]);
            self::assertTrue($call('status', $workspace)['hasUnpushedChanges']);
            $commit = $call('commit', $workspace + ['message' => 'Broker contract']);
            $history = $call('log', $workspace)['entries'];
            self::assertSame($commit['revision'], $history[0]['id']);
            self::assertSame('Broker contract', $history[0]['message']);
            $branches = $call('branches', $workspace)['branches'];
            self::assertSame('main', $branches[0]['name']);
            self::assertSame($history[0], $branches[0]['latestCommit']);
        } finally {
            $channel->close();
            $connection->close();
        }
    }

}
