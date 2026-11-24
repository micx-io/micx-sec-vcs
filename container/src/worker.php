<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
use Micx\SecVcs\{Storage,Service,Dispatcher};
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
// Never log raw connection URIs, credentials, exceptions or stack traces.
$amqpContext='configuration not parsed';
$amqpPhase='startup';
set_exception_handler(static function (\Throwable $e) use (&$amqpContext,&$amqpPhase): void {
    $reason=match (true) {
        $e instanceof \PhpAmqpLib\Exception\AMQPProtocolChannelException => 'AMQP_CHANNEL: broker rejected channel; check exchange/queue declarations and permissions',
        $e instanceof \PhpAmqpLib\Exception\AMQPProtocolException => 'AMQP_CONNECTION: broker rejected connection; check username, password and vhost permissions',
        $e instanceof \PhpAmqpLib\Exception\AMQPExceptionInterface => 'AMQP_UNAVAILABLE: connection failed or was lost; check broker, DNS and network',
        default => 'WORKER_ERROR: startup, storage or response confirmation failed; check service configuration and volumes',
    };
    fwrite(STDERR,gmdate('c')." worker: ".$reason."; phase=".$amqpPhase."; ".$amqpContext."; supervisor will restart\n");
});
$stopping=false;
pcntl_async_signals(true);
pcntl_signal(SIGTERM,static function () use (&$stopping) { $stopping=true; });
pcntl_signal(SIGINT,static function () use (&$stopping) { $stopping=true; });
umask(0000);
$dataDir = getenv('CONF_DATA_DIR');
if (!is_string($dataDir) || $dataDir === '') {
    fwrite(STDERR, "CONF_DATA_DIR_MISSING: set CONF_DATA_DIR to an existing mounted directory\n");
    exit(1);
}
$storage = new Storage($dataDir);
// Server-only setting: request parameters cannot enable mock mode.
$mockSetting = strtolower(trim(getenv('CONF_MOCK_MODE') ?: 'false'));
if (!in_array($mockSetting, ['true', 'false', '1', '0', 'yes', 'no', 'on', 'off'], true)) {
    throw new \InvalidArgumentException('Invalid CONF_MOCK_MODE boolean');
}
$mockMode = in_array($mockSetting, ['true', '1', 'yes', 'on'], true);
$service=new Service($storage,'/run/micx/id_key','/run/micx/known_hosts',$mockMode);
$dispatcher=new Dispatcher($service->execute(...),$mockMode);
// Ein Connection-String konfiguriert Host, Port, Credentials und RabbitMQ-VHost.
$uri=getenv('CONF_AMQ_CONNECTION') ?: 'rabbitmq://micx:micx@rabbitmq:5672/';
$amq=parse_url($uri);
if ($amq===false || !in_array(strtolower($amq['scheme'] ?? ''), ['rabbitmq','amqp'],true)
    || empty($amq['host']) || !isset($amq['user'],$amq['pass'])
    || isset($amq['query']) || isset($amq['fragment'])) {
    throw new \InvalidArgumentException('Invalid CONF_AMQ_CONNECTION URI');
}
$host=$amq['host'];
$port=$amq['port'] ?? 5672;
$username=rawurldecode($amq['user']);
$password=rawurldecode($amq['pass']);
$vhost=rawurldecode(ltrim($amq['path'] ?? '/', '/')) ?: '/';
$exchange='micx.vcs.v1';
$queue='micx.vcs.v1.requests';
$routingKey='rpc.request';
// Escape control characters so URI-supplied values cannot forge additional log lines.
$safeLogValue=static fn (string $value): string => json_encode($value,JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
$amqpContext='host='.$safeLogValue($host).' port='.$port
    .' vhost='.$safeLogValue($vhost).' user='.$safeLogValue($username)
    .' password_set='.(($password !== '') ? 'yes' : 'no')
    .' exchange='.$safeLogValue($exchange).' queue='.$safeLogValue($queue)
    .' routing_key='.$safeLogValue($routingKey);
fwrite(STDOUT,gmdate('c')." worker: AMQP connecting; ".$amqpContext."\n");
$amqpPhase='connect';
$connection=new AMQPStreamConnection(
    $host,$port,$username,$password,$vhost,
    false,'AMQPLAIN',null,'en_US',5,130,null,false,60
);
$amqpPhase='channel';
$heartbeat=new \PhpAmqpLib\Connection\Heartbeat\PCNTLHeartbeatSender($connection);
$heartbeat->register();
$channel=$connection->channel();
$amqpPhase='declare exchange '.$safeLogValue($exchange);
$channel->exchange_declare($exchange,'topic',false,true,false);
fwrite(STDOUT,gmdate('c')." worker: AMQP exchange declared; ".$amqpContext."\n");
$amqpPhase='declare queue '.$safeLogValue($queue);
$channel->queue_declare($queue,false,true,false,false,false,new AMQPTable(['x-max-length'=>10000,'x-max-length-bytes'=>67108864,'x-overflow'=>'reject-publish']));
fwrite(STDOUT,gmdate('c')." worker: AMQP queue declared; ".$amqpContext."\n");
$amqpPhase='bind queue';
$channel->queue_bind($queue,$exchange,$routingKey);
fwrite(STDOUT,gmdate('c')." worker: AMQP queue bound; ".$amqpContext."\n");
$amqpPhase='configure consumer';
$channel->basic_qos(0,1,false);
$channel->confirm_select();
$nacked=false;
$channel->set_nack_handler(function () use (&$nacked) { $nacked=true; });
$channel->basic_consume($queue,'',false,false,false,false,function (AMQPMessage $msg) use ($dispatcher,$channel,&$nacked,&$stopping) {
    try {
    if ($stopping) { $msg->nack(true); return; }
    $reply=$msg->has('reply_to') ? $msg->get('reply_to') : '';
    $id=$msg->has('correlation_id') ? $msg->get('correlation_id') : '';
    if (!preg_match('/^micx\.vcs\.reply\.[a-f0-9]{32}$/D',$reply) || strlen($msg->getBody())>4194304) { $msg->reject(false); return; }
    try { $request=json_decode($msg->getBody(),true,64,JSON_THROW_ON_ERROR); }
    catch (\JsonException) { $msg->reject(false); return; }
    if (!is_array($request) || ($request['id'] ?? null)!==$id) { $msg->reject(false); return; }
    // Fehlerantworten sind endgültige Ergebnisse; nach bestätigtem Reply immer ack.
    $response=$dispatcher->handle($request);
    $nacked=false;
    $channel->basic_publish(new AMQPMessage(json_encode($response,JSON_THROW_ON_ERROR),['content_type'=>'application/json','correlation_id'=>$id,'expiration'=>'300000']), '',$reply);
    $channel->wait_for_pending_acks(10);
    if ($nacked) throw new \RuntimeException('Response not confirmed');
    $msg->ack();
    } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
        // A timeout inside request handling is not the harmless idle poll timeout below.
        throw new \PhpAmqpLib\Exception\AMQPRuntimeException('RPC handler timed out',0,$e);
    }
});
fwrite(STDOUT,gmdate('c')." worker: MICX VCS worker ready; ".$amqpContext."\n");
$amqpPhase='consume';
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
