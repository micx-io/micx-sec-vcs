<?php
declare(strict_types=1);

namespace Micx\Vcs\Exception;

/** Git/repository-specific failures: distinct from SDK transport problems. */
class RepositoryException extends VcsException
{
}
