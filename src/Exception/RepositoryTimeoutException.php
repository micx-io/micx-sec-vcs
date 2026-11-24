<?php
declare(strict_types=1);

namespace Micx\Vcs\Exception;

/** A worker-reported Git operation timeout; the remote outcome may be unknown. */
final class RepositoryTimeoutException extends RepositoryException
{
}
