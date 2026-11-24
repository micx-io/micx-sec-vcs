<?php
declare(strict_types=1);

namespace Micx\Vcs\Exception;

/**
 * Stable support identifiers. Numbers 1xxx are infrastructure; 2xxx are Git;
 * 3xxx are local mock/filesystem and 9xxx are unexpected responses.
 */
final class ErrorCode
{
    public const CONNECTION_FAILED = 'MIX-VCS-1001';
    public const BROKER_NOT_ROUTED = 'MIX-VCS-1002';
    public const RPC_TIMEOUT = 'MIX-VCS-1003';
    public const RPC_OUTCOME_UNKNOWN = 'MIX-VCS-1004';
    public const WORKER_CONFIGURATION = 'MIX-VCS-1005';
    public const AUTHENTICATION_FAILED = 'MIX-VCS-2001';
    public const ACCESS_DENIED = 'MIX-VCS-2002';
    public const REPOSITORY_NOT_FOUND = 'MIX-VCS-2003';
    public const CONFLICT = 'MIX-VCS-2004';
    public const PUSH_REJECTED = 'MIX-VCS-2005';
    public const GIT_TIMEOUT = 'MIX-VCS-2006';
    public const GIT_OPERATION_FAILED = 'MIX-VCS-2007';
    public const MOCK_DIRECTORY_NOT_FOUND = 'MIX-VCS-3001';
    public const MOCK_DIRECTORY_DENIED = 'MIX-VCS-3002';
    public const MOCK_JOURNAL_INVALID = 'MIX-VCS-3003';
    public const MOCK_IO_FAILED = 'MIX-VCS-3004';
    public const MOCK_REPOSITORY_NOT_FOUND = 'MIX-VCS-3005';
    public const REPOSITORY_NOT_READY = 'MIX-VCS-2008';
    public const SHARED_DIRECTORY_INVALID = 'MIX-VCS-1006';
    public const INVALID_RESPONSE = 'MIX-VCS-9001';
    public const UNSUPPORTED_OPERATION = 'MIX-VCS-9002';

    public static function forWorker(string $code): string
    {
        return match ($code) {
            'UNAVAILABLE' => self::CONNECTION_FAILED,
            'NO_ROUTE' => self::BROKER_NOT_ROUTED,
            'OUTCOME_UNKNOWN' => self::RPC_OUTCOME_UNKNOWN,
            'TIMEOUT' => self::RPC_TIMEOUT,
            'SSH_KEY_MISSING', 'SSH_KEY_INVALID', 'SSH_AUTH_FAILED', 'SSH_HOST_KEY_FAILED',
            'KNOWN_HOSTS_MISSING', 'AUTHENTICATION_FAILED' => self::AUTHENTICATION_FAILED,
            'ACCESS_DENIED', 'PERMISSION_DENIED', 'FORBIDDEN', 'PUSH_DENIED' => self::ACCESS_DENIED,
            'REPOSITORY_UNAVAILABLE', 'REPOSITORY_NOT_FOUND', 'REMOTE_NOT_FOUND', 'BRANCH_NOT_FOUND'
                => self::REPOSITORY_NOT_FOUND,
            'CONFLICT', 'DIRTY_WORKTREE' => self::CONFLICT,
            'PUSH_REJECTED' => self::PUSH_REJECTED,
            'GIT_TIMEOUT', 'PULL_TIMEOUT', 'PUSH_TIMEOUT' => self::GIT_TIMEOUT,
            'INVALID_RESPONSE' => self::INVALID_RESPONSE,
            'UNSUPPORTED' => self::UNSUPPORTED_OPERATION,
            'INTERNAL_ERROR', 'INVALID_REQUEST', 'WORKER_UNAVAILABLE' => self::WORKER_CONFIGURATION,
            default => self::GIT_OPERATION_FAILED,
        };
    }
}
