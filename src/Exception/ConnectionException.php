<?php
declare(strict_types=1);

namespace Micx\Vcs\Exception;

/** RabbitMQ unavailable, misconfigured, disconnected or requests not routed. */
final class ConnectionException extends InternalException
{
}
