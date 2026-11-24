<?php
declare(strict_types=1);

namespace Micx\Vcs\Transport;

use Micx\Vcs\Exception\ConnectionException;
use Micx\Vcs\Exception\ErrorCode;
use Micx\Vcs\Exception\InternalException;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * Erstellt und prüft technische Verbindungen, ohne Git-Repositories zu öffnen.
 * Neue Transports können hier ergänzt werden, ohne den Manager anzupassen.
 */
final class TransportFactory
{
    /**
     * Prüft die Connector-Konfiguration ohne einen Netzwerkzugriff.
     * null, mock und file bezeichnen reine lokale Betriebsarten.
     *
     * Beispiel: TransportFactory::validate('rabbitmq://user:pass@broker:5672');
     *
     * @throws InternalException Bei ungültiger oder unbekannter URL.
     * @see self::connect()
     */
    public static function validate(?string $url): bool
    {
        if ($url === null || in_array(strtolower(trim($url)), ['', 'mock', 'file'], true)
            || str_starts_with(strtolower(trim($url)), 'mock://')
            || str_starts_with(strtolower(trim($url)), 'file://')) {
            return false;
        }

        try {
            $parts = parse_url($url);
        } catch (\ValueError $e) {
            throw new InternalException(
                ErrorCode::WORKER_CONFIGURATION,
                'RabbitMQ-URL ist ungültig: Host, Port und URL-Syntax prüfen.',
                'configure',
                [],
                $e
            );
        }

        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'rabbitmq'
            || !is_string($parts['host'] ?? null) || $parts['host'] === ''
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InternalException(
                ErrorCode::WORKER_CONFIGURATION,
                'Connector-URL muss rabbitmq://user:pass@host:5672[/topic] sein.',
                'configure'
            );
        }

        $topic = rawurldecode(ltrim($parts['path'] ?? '', '/'));
        if ($topic !== '' && !preg_match('/^[A-Za-z0-9._-]{1,255}$/D', $topic)) {
            throw new InternalException(
                ErrorCode::WORKER_CONFIGURATION,
                'Ungültiger RabbitMQ-Topic-Exchange-Name im URL-Pfad.',
                'configure'
            );
        }

        return true;
    }

    /**
     * Baut eine RabbitMQ-Verbindung auf und prüft durch Öffnen eines Channels,
     * ob der Broker erreichbar und die Authentifizierung gültig ist.
     * Für reine Mock-Konfigurationen ist kein Transport erforderlich.
     *
     * Beispiel: $transport = TransportFactory::connect('rabbitmq://user:pass@broker:5672');
     *
     * @throws InternalException Bei falscher Konfiguration oder Mock-only-Modus.
     * @throws ConnectionException Bei Netzwerk-, Login- oder Brokerfehlern.
     * @see RabbitMqTransport
     */
    public static function connect(string $url): RpcTransport
    {
        if (!self::validate($url)) {
            throw new InternalException(
                ErrorCode::WORKER_CONFIGURATION,
                'Im Mock-Modus wird keine Remote-Verbindung aufgebaut.',
                'connect'
            );
        }

        $parts = parse_url($url);
        $host = $parts['host'];
        $port = $parts['port'] ?? 5672;
        $user = rawurldecode($parts['user'] ?? 'guest');
        $password = rawurldecode($parts['pass'] ?? 'guest');
        $topic = rawurldecode(ltrim($parts['path'] ?? '', '/'));
        $exchange = $topic !== '' ? $topic : RabbitMqTransport::EXCHANGE;
        $vhost = '/';

        $connection = null;
        try {
            $connection = new AMQPStreamConnection($host, $port, $user, $password, $vhost);
            $channel = $connection->channel();
            $channel->close();

            return new RabbitMqTransport($connection, $exchange);
        } catch (\Throwable $e) {
            try {
                $connection?->close();
            } catch (\Throwable) {
            }

            throw new ConnectionException(
                ErrorCode::CONNECTION_FAILED,
                'RabbitMQ-Verbindung oder Channel-Prüfung fehlgeschlagen. Host, Port, Credentials, Vhost und Broker prüfen.',
                'connect',
                [],
                $e
            );
        }
    }
}
