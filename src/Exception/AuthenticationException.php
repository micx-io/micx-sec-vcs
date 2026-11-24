<?php
declare(strict_types=1);

namespace Micx\Vcs\Exception;

/** Git authentication or SSH key/host verification failed. */
final class AuthenticationException extends RepositoryException
{
}
