<?php
declare(strict_types=1);

namespace Micx\Vcs\Helper\Workspace;

use Micx\Vcs\Transport\RpcTransport;
use Micx\Vcs\Transport\RpcException;
use Micx\Vcs\Transport\OperationTimeoutException;

use Micx\Vcs\Exception\ConnectionException;
use Micx\Vcs\Exception\ErrorCode;
use Micx\Vcs\Exception\ExceptionMapper;
use Micx\Vcs\Exception\InternalException;

/** Internal RPC client; application code uses MixVcsManager. */
final class RpcClient
{
    public function __construct(private RpcTransport $transport, private float $timeout = 60.0)
    {
        if (!is_finite($timeout) || $timeout <= 0 || $timeout > 300) {
            throw new \InvalidArgumentException('Timeout must be in (0, 300] seconds');
        }
    }

    /** @param array<string,mixed> $params
     *  @return array<string,mixed>
     */
    public function call(string $method, array $params = [], ?float $timeout = null): array
    {
        $timeout ??= $this->timeout;
        if (!is_finite($timeout) || $timeout <= 0 || $timeout > 300) {
            throw new \InvalidArgumentException('Timeout must be in (0, 300] seconds');
        }

        $id = bin2hex(random_bytes(16));
        $request = ['version' => 1, 'id' => $id, 'method' => $method, 'params' => $params];

        try {
            $reply = $this->transport->request($request, $timeout);
        } catch (OperationTimeoutException $e) {
            throw new ConnectionException(
                ErrorCode::RPC_TIMEOUT,
                'Keine rechtzeitige Antwort des Workers. Der Ausgang der Operation ist unbekannt.',
                $method,
                ['request' => $e->request] + $e->details,
                $e
            );
        } catch (RpcException $e) {
            $type = in_array($e->errorCode, ['UNAVAILABLE', 'NO_ROUTE', 'OUTCOME_UNKNOWN'], true)
                ? ConnectionException::class : InternalException::class;
            throw new $type(
                ErrorCode::forWorker($e->errorCode),
                'Kommunikation mit dem Mix-VCS-Worker fehlgeschlagen: ' . $e->getMessage(),
                $method,
                ['workerCode' => $e->errorCode] + $e->details,
                $e
            );
        } catch (\Throwable $e) {
            throw new ConnectionException(
                ErrorCode::CONNECTION_FAILED,
                'Verbindung zum VCS-Worker fehlgeschlagen. RabbitMQ-Adresse, Zugangsdaten und Netzwerk prüfen.',
                $method,
                [],
                $e
            );
        }

        if (($reply['version'] ?? null) !== 1 || ($reply['id'] ?? null) !== $id
            || !is_bool($reply['ok'] ?? null)) {
            throw new InternalException(ErrorCode::INVALID_RESPONSE, 'Ungültige RPC-Antwort vom Worker.', $method);
        }
        if (!$reply['ok']) {
            $error = $reply['error'] ?? null;
            if (!is_array($error) || !is_string($error['code'] ?? null) || ($error['code'] ?? '') === ''
                || !is_string($error['message'] ?? null) || ($error['message'] ?? '') === ''
                || (isset($error['details']) && !is_array($error['details']))) {
                throw new InternalException(ErrorCode::INVALID_RESPONSE, 'Ungültiges Fehlerformat vom Worker.', $method);
            }
            throw ExceptionMapper::fromWorker($error['code'], $error['message'], $method, $error['details'] ?? []);
        }
        if (!is_array($reply['result'] ?? null)) {
            throw new InternalException(ErrorCode::INVALID_RESPONSE, 'RPC-Antwort enthält kein Ergebnis.', $method);
        }
        return $reply['result'];
    }
}
