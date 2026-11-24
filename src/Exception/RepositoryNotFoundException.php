<?php
declare(strict_types=1);

namespace Micx\Vcs\Exception;

/** A requested repository or remote branch does not exist. */
final class RepositoryNotFoundException extends RepositoryException
{
}
