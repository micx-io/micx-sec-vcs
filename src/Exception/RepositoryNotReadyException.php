<?php
declare(strict_types=1);

namespace Micx\Vcs\Exception;

/** Access to a repository is attempted before a successful pull/sync. */
final class RepositoryNotReadyException extends RepositoryException
{
}
