<?php

declare(strict_types=1);

namespace Micx\SecVcs;

/** Resolves client-selected repository paths and serializes Git operations. */
final class Storage
{
    public function __construct(public readonly string $data)
    {
        if ($data === '' || $data[0] !== '/') {
            throw new \RuntimeException('Data root must be an absolute path');
        }
        if (!is_dir($data)) {
            throw new \RuntimeException('Configured data directory does not exist: ' . $data);
        }
        if (is_link($data) || realpath($data) !== $data) {
            throw new \RuntimeException('Data root must be a canonical absolute path');
        }
        $lockDirectory = $data . '/.mix-vcs-locks';
        if (!is_dir($lockDirectory) && !mkdir($lockDirectory, 0700)) {
            throw new \RuntimeException('Cannot create Git lock directory');
        }
        if (is_link($lockDirectory) || realpath($lockDirectory) !== $lockDirectory) {
            throw new \RuntimeException('Git lock directory must not be a symlink');
        }
    }

    /** @throws Fault */
    public static function relative(string $path, bool $empty = false): string
    {
        if (($path === '' && !$empty) || strlen($path) > 512
            || preg_match('/[\\\\\x00-\x1f\x7f]/', $path)) {
            throw new Fault('INVALID_PATH', 'Invalid relative path');
        }
        if ($path === '' && $empty) {
            return $path;
        }

        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..'
                || str_starts_with($part, '-') || strcasecmp($part, '.git') === 0
                || $part === '.mix-vcs-locks') {
                throw new Fault('INVALID_PATH', 'Reserved path component');
            }
        }

        return $path;
    }

    /** @throws Fault */
    public static function branch(string $branch): string
    {
        if (strlen($branch) > 200 || !preg_match('~^[A-Za-z0-9][A-Za-z0-9._/-]*$~D', $branch)
            || str_contains($branch, '..') || str_contains($branch, '//')) {
            throw new Fault('INVALID_BRANCH', 'Invalid branch');
        }
        foreach (explode('/', $branch) as $part) {
            if ($part === '' || $part[0] === '.' || str_ends_with($part, '.')
                || str_ends_with($part, '.lock')) {
                throw new Fault('INVALID_BRANCH', 'Invalid branch');
            }
        }

        return $branch;
    }

    /** Only authenticated SCP-like Git remotes are supported by this worker. */
    public static function url(string $url): string
    {
        if (strlen($url) > 512
            || !preg_match('~^git@([A-Za-z0-9][A-Za-z0-9.-]*):([A-Za-z0-9_][A-Za-z0-9._/-]*)$~D', $url, $m)) {
            throw new Fault('INVALID_REPOSITORY', 'Use git@host:owner/repository.git');
        }
        self::relative($m[2]);
        if (str_contains($m[1], '..')) {
            throw new Fault('INVALID_REPOSITORY', 'Invalid SSH hostname');
        }

        return $url;
    }

    /**
     * Safely resolves a relative client directory without symlink traversal.
     *
     * @throws Fault On traversal, symlinks or non-file nodes.
     */
    public function directory(string $relative): string
    {
        self::relative($relative);
        $cursor = $this->data;
        foreach (explode('/', $relative) as $part) {
            $cursor .= '/' . $part;
            clearstatcache(true, $cursor);
            if (is_link($cursor)) {
                throw new Fault('INVALID_PATH', 'Symbolic links are not accessible');
            }
            if ($cursor !== $this->data . '/' . $relative && is_file($cursor . '/.git/HEAD')) {
                throw new Fault('INVALID_PATH', 'Nested repository checkout is not allowed');
            }
            if (file_exists($cursor) && !is_dir($cursor)) {
                throw new Fault('INVALID_PATH', 'Repository directory is not a directory');
            }
        }

        return $cursor;
    }

    /**
     * Serializes operations on the same directory across worker instances.
     *
     * @template T
     * @param callable():T $action
     * @return T
     * @throws Fault When the workspace stays locked.
     */
    public function lock(string $directory, callable $action): mixed
    {
        $handle = fopen($this->data . '/.mix-vcs-locks/' . hash('sha256', $directory), 'c');
        if ($handle === false) {
            throw new Fault('IO_ERROR', 'Cannot acquire workspace lock');
        }

        try {
            $deadline = microtime(true) + 5;
            while (!flock($handle, LOCK_EX | LOCK_NB)) {
                if (microtime(true) > $deadline) {
                    throw new Fault('BUSY', 'Repository is busy');
                }
                usleep(20000);
            }

            return $action();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
