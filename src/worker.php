<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
use Micx\SecVcs\{Storage,Service,Dispatcher};
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
// Log a safe reason, never an AMQP password, key, raw exception or stack trace.
set_exception_handler(static function (\Throwable $e): void {
    $reason=match (true) {
        $e instanceof \PhpAmqpLib\Exception\AMQPProtocolChannelException => 'AMQP_CHANNEL: broker rejected channel; check queue settings and permissions',
        $e instanceof \PhpAmqpLib\Exception\AMQPProtocolException => 'AMQP_CONNECTION: broker rejected connection; check credentials and vhost permissions',
        $e instanceof \PhpAmqpLib\Exception\AMQPExceptionInterface => 'AMQP_UNAVAILABLE: connection failed or was lost; check broker, DNS and network',
        default => 'WORKER_ERROR: startup, storage or response confirmation failed; check service configuration and volumes',
    };
    fwrite(STDERR,gmdate('c')." worker: ".$reason."; supervisor will restart\n");
});
$stopping=false;
pcntl_async_signals(true);
pcntl_signal(SIGTERM,static function () use (&$stopping) { $stopping=true; });
pcntl_signal(SIGINT,static function () use (&$stopping) { $stopping=true; });
umask(0007);
$storage=new Storage(getenv('VCS_DATA_DIR') ?: '/data', getenv('VCS_STATE_DIR') ?: '/state', getenv('VCS_PATH_TEMPLATE') ?: '{repo}/{branch}');
$service=new Service($storage,'/run/micx/id_key','/run/micx/known_hosts');
$dispatcher=new Dispatcher($storage,$service->execute(...));
$connection=new AMQPStreamConnection(getenv('AMQP_HOST') ?: 'rabbitmq',(int)(getenv('AMQP_PORT') ?: 5672),getenv('AMQP_USER') ?: 'micx',getenv('AMQP_PASSWORD') ?: 'micx',getenv('AMQP_VHOST') ?: '/',false,'AMQPLAIN',null,'en_US',5,130,null,false,60);
$heartbeat=new \PhpAmqpLib\Connection\Heartbeat\PCNTLHeartbeatSender($connection);
$heartbeat->register();
$channel=$connection->channel();
$channel->exchange_declare('micx.vcs.v1','topic',false,true,false);
$channel->queue_declare('micx.vcs.v1.requests',false,true,false,false,false,new AMQPTable(['x-max-length'=>10000,'x-max-length-bytes'=>67108864,'x-overflow'=>'reject-publish']));
$channel->queue_bind('micx.vcs.v1.requests','micx.vcs.v1','rpc.request');
$channel->basic_qos(0,1,false);
$channel->confirm_select();
$nacked=false;
$channel->set_nack_handler(function () use (&$nacked) { $nacked=true; });
$channel->basic_consume('micx.vcs.v1.requests','',false,false,false,false,function (AMQPMessage $msg) use ($dispatcher,$channel,&$nacked,&$stopping) {
    if ($stopping) { $msg->nack(true); return; }
    $reply=$msg->has('reply_to') ? $msg->get('reply_to') : '';
    $id=$msg->has('correlation_id') ? $msg->get('correlation_id') : '';
    if (!preg_match('/^micx\.vcs\.reply\.[a-f0-9]{32}$/D',$reply) || strlen($msg->getBody())>4194304) { $msg->reject(false); return; }
    try { $request=json_decode($msg->getBody(),true,64,JSON_THROW_ON_ERROR); }
    catch (\JsonException) { $msg->reject(false); return; }
    if (!is_array($request) || ($request['id'] ?? null)!==$id) { $msg->reject(false); return; }
    // Publish cached result before ack. If callback expired, a retry retrieves it by ID.
    $response=$dispatcher->handle($request);
    $nacked=false;
    $channel->basic_publish(new AMQPMessage(json_encode($response,JSON_THROW_ON_ERROR),['content_type'=>'application/json','correlation_id'=>$id,'expiration'=>'300000']), '',$reply);
    $channel->wait_for_pending_acks(10);
    if ($nacked) throw new \RuntimeException('Response not confirmed');
    $msg->ack();
});
fwrite(STDOUT,"MICX VCS worker ready\n");
try {
    while (!$stopping && $channel->is_consuming()) {
        try { $channel->wait(null,false,1); }
        catch (\PhpAmqpLib\Exception\AMQPTimeoutException) { /* Check stop flag every second while idle. */ }
    }
} finally {
    $heartbeat->unregister();
    try { $channel->close(); } catch (\Throwable) {}
    try { $connection->close(); } catch (\Throwable) {}
}
