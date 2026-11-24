<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use Micx\Vcs\Transport\RabbitMqTransport;
use Micx\Vcs\Transport\RpcException;
use PhpAmqpLib\Connection\AMQPStreamConnection;
final class RabbitMqIntegrationTest extends TestCase
{
    public function testRealBrokerCorrelationAndTransport(): void
    {
        if (!getenv('AMQP_TEST_HOST')) self::markTestSkipped('Set AMQP_TEST_HOST and start test/rpc-fixture.php');
        $c=new AMQPStreamConnection(getenv('AMQP_TEST_HOST'),5672,'guest','guest');
        try {
            $request = ['version' => 1, 'id' => bin2hex(random_bytes(16)), 'method' => 'echo', 'params' => ['value' => 42]];
            $response = (new RabbitMqTransport($c))->request($request, 5.0);
            self::assertSame(['echo' => ['value' => 42]], $response['result']);
            self::assertSame($request['id'], $response['id']);
        } finally { $c->close(); }
    }
    public function testConcurrentClientsReceiveTheirOwnOutOfOrderReplies(): void
    {
        if (!getenv('AMQP_TEST_HOST')) self::markTestSkipped('Requires broker fixture');
        $base=sys_get_temp_dir().'/micx-clients-'.bin2hex(random_bytes(8)); mkdir($base);
        $code= <<<'CHILD'
require $argv[1];
$c=new PhpAmqpLib\Connection\AMQPStreamConnection($argv[2],5672,'guest','guest');
try {
    $request = ['version' => 1, 'id' => bin2hex(random_bytes(16)), 'method' => 'reverse', 'params' => ['client' => $argv[4]]];
    $response = (new Micx\Vcs\Transport\RabbitMqTransport($c))->request($request, 5.0);
    file_put_contents($argv[3], json_encode($response['result']));
} finally { $c->close(); }
CHILD;
        $children=[];
        for ($i=0;$i<2;++$i) {
            $p=proc_open([PHP_BINARY,'-c',php_ini_loaded_file(),'-r',$code,__DIR__.'/../vendor/autoload.php',getenv('AMQP_TEST_HOST'),$base.'/'.$i,(string)$i],[],$pipes);
            self::assertIsResource($p); $children[]=$p;
        }
        foreach ($children as $p) self::assertSame(0,proc_close($p));
        for ($i=0;$i<2;++$i) self::assertSame(['echo'=>['client'=>(string)$i]],json_decode(file_get_contents($base.'/'.$i),true));
    }
    public function testMalformedBrokerReplyIsRpcException(): void
    {
        if (!getenv('AMQP_TEST_HOST')) self::markTestSkipped('Requires broker fixture');
        $c=new AMQPStreamConnection(getenv('AMQP_TEST_HOST'),5672,'guest','guest');
        try {
            $request = ['version' => 1, 'id' => bin2hex(random_bytes(16)), 'method' => 'malformed', 'params' => []];
            try {
                (new RabbitMqTransport($c))->request($request, 5.0);
                self::fail('Malformed response must throw RpcException');
            } catch (RpcException $e) {
                self::assertSame('INVALID_RESPONSE', $e->errorCode);
            }
        } finally { $c->close(); }
    }
}
