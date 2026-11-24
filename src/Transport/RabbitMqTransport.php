<?php
declare(strict_types=1);
namespace Micx\Vcs\Transport;

use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
final class RabbitMqTransport implements RpcTransport
{
    public const EXCHANGE = 'micx.vcs.v1';
    public const QUEUE = 'micx.vcs.v1.requests';
    public const ROUTING_KEY = 'rpc.request';
    public const MAX_BYTES = 4194304;
    public function __construct(private AbstractConnection $connection, private string $exchange = self::EXCHANGE) {}
    public function request(array $request, float $timeout): array
    {
        if (!is_finite($timeout) || $timeout <= 0 || $timeout > 300) throw new \InvalidArgumentException('Timeout must be in (0,300]');
        if (!is_string($request['id'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{8,128}$/D',$request['id'])) throw new \InvalidArgumentException('Invalid request ID');
        try { $body = json_encode($request, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new RpcException('INVALID_REQUEST', 'Request cannot be encoded as JSON'); }
        if (strlen($body) > self::MAX_BYTES) throw new RpcException('TOO_LARGE', 'Request exceeds 4 MiB');
        $channel = null;
        $publishAttempted = false;
        $reply = null;
        $returned = false;
        $nacked = false;
        $deadline = microtime(true) + $timeout;
        try {
            $channel = $this->connection->channel();
            $channel->exchange_declare($this->exchange, 'topic', false, true, false);
            [$queue] = $channel->queue_declare('micx.vcs.reply.' . bin2hex(random_bytes(16)), false, false, true, true,
                false, new AMQPTable(['x-message-ttl' => 300000, 'x-max-length-bytes' => self::MAX_BYTES]));
            $channel->set_return_listener(function () use (&$returned) { $returned = true; });
            $channel->set_nack_handler(function () use (&$nacked) { $nacked = true; });
            $channel->confirm_select();
            $channel->basic_consume($queue, '', false, true, false, false, function (AMQPMessage $message) use (&$reply, $request) {
                if (!$message->has('correlation_id') || $message->get('correlation_id') !== $request['id']) return;
                if (strlen($message->getBody()) > self::MAX_BYTES) throw new RpcException('TOO_LARGE', 'Response exceeds 4 MiB');
                try { $decoded = json_decode($message->getBody(), true, 64, JSON_THROW_ON_ERROR); }
                catch (\JsonException) { throw new RpcException('INVALID_RESPONSE','Response is not valid JSON; operation outcome is unknown'); }
                if (!is_array($decoded)) throw new RpcException('INVALID_RESPONSE','Response must be an object; operation outcome is unknown');
                $reply = $decoded;
            });
            $publishAttempted = true;
            $channel->basic_publish(new AMQPMessage($body, ['content_type' => 'application/json', 'delivery_mode' => 2,
                'correlation_id' => $request['id'], 'reply_to' => $queue, 'expiration' => (string)(int)($timeout * 1000)]),
                $this->exchange, self::ROUTING_KEY, true);
            $channel->wait_for_pending_acks_returns(max(0.01, $deadline - microtime(true)));
            if ($returned || $nacked) throw new RpcException($returned ? 'NO_ROUTE' : 'UNAVAILABLE', 'Request was not routed or confirmed');
            while ($reply === null) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) throw new OperationTimeoutException('Operation timed out; remote outcome may be unknown', $request);
                $channel->wait(null, false, $remaining);
            }
            return $reply;
        } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
            throw new OperationTimeoutException('Operation timed out; remote outcome may be unknown', $request);
        } catch (\PhpAmqpLib\Exception\AMQPExceptionInterface $e) {
            throw new RpcException($publishAttempted ? 'OUTCOME_UNKNOWN' : 'UNAVAILABLE',
                $publishAttempted ? 'Broker connection failed after publishing started; retry only the same request ID and payload'
                    : 'Cannot open RPC channel; check broker connection, credentials and permissions',
                ['requestId'=>$request['id']]);
        } finally {
            try { $channel?->close(); } catch (\Throwable) {}
        }
    }
}
