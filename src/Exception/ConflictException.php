<?php
declare(strict_types=1);

namespace Micx\Vcs\Exception;

/** Local changes or remote branch updates prevent the requested operation. */
final class ConflictException extends RepositoryException
{
}
