<?php
declare(strict_types=1);

namespace Micx\Vcs\Exception;

/** Translate worker errors to typed public SDK failures. */
final class ExceptionMapper
{
    /** @param array<string, mixed> $details */
    public static function fromWorker(
        string $code,
        string $message,
        string $operation,
        array $details = [],
    ): VcsException {
        $class = match ($code) {
            'SSH_KEY_MISSING', 'SSH_KEY_INVALID', 'SSH_AUTH_FAILED', 'SSH_HOST_KEY_FAILED',
            'KNOWN_HOSTS_MISSING', 'AUTHENTICATION_FAILED' => AuthenticationException::class,
            'ACCESS_DENIED', 'PERMISSION_DENIED', 'FORBIDDEN', 'PUSH_DENIED' => AccessDeniedException::class,
            'REPOSITORY_UNAVAILABLE', 'REPOSITORY_NOT_FOUND', 'REMOTE_NOT_FOUND', 'BRANCH_NOT_FOUND' => RepositoryNotFoundException::class,
            'CONFLICT', 'DIRTY_WORKTREE', 'PUSH_REJECTED' => ConflictException::class,
            'TIMEOUT', 'GIT_TIMEOUT', 'PULL_TIMEOUT', 'PUSH_TIMEOUT' => RepositoryTimeoutException::class,
            'UNAVAILABLE', 'NO_ROUTE', 'OUTCOME_UNKNOWN' => ConnectionException::class,
            'INVALID_RESPONSE', 'WORKER_UNAVAILABLE', 'INTERNAL_ERROR', 'UNSUPPORTED', 'INVALID_REQUEST' => InternalException::class,
            default => OperationFailedException::class,
        };
        $hint = match ($code) {
            'SSH_KEY_MISSING' => 'Kein SSH-Key im Worker konfiguriert.',
            'SSH_AUTH_FAILED' => 'Git-Server hat SSH-Anmeldung abgelehnt.',
            'SSH_HOST_KEY_FAILED' => 'Git-Server-Host-Key nicht verifiziert.',
            'ACCESS_DENIED', 'PERMISSION_DENIED', 'FORBIDDEN', 'PUSH_DENIED' =>
                'Repository- oder Push-Berechtigung fehlt.',
            'PUSH_REJECTED' => 'Push zurückgewiesen; Branchschutz oder neuere Remote-Commits prüfen.',
            'DIRTY_WORKTREE' => 'Uncommittete Änderungen zuerst committen.',
            'REPOSITORY_UNAVAILABLE', 'REPOSITORY_NOT_FOUND' => 'Remote-URL und Zugriff prüfen.',
            'TIMEOUT', 'GIT_TIMEOUT', 'PULL_TIMEOUT', 'PUSH_TIMEOUT' =>
                'Zeitlimit überschritten; vor Retry Remote-Status prüfen.',
            default => '',
        };
        return new $class(ErrorCode::forWorker($code), trim($hint . ' ' . $message), $operation, ['workerCode' => $code] + $details);
    }
}
