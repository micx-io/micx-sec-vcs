<?php
declare(strict_types=1);

namespace Micx\Vcs\Exception;

/** Git operation failed for a reason not mapped to a more specific exception. */
final class OperationFailedException extends RepositoryException
{
}
