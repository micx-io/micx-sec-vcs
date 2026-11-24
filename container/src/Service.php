<?php

declare(strict_types=1);

namespace Micx\SecVcs;

/** Git-backed implementation of the public VcsRepository RPC methods. */
final class Service
{
    public function __construct(
        private Storage $storage,
        private string $key,
        private string $knownHosts,
        private bool $mockMode = false,
    ) {
    }

    /**
     * Executes a repository RPC from its URL, branch and client-chosen path.
     *
     * @param string $method SDK repository method: ping, pull, push, commit,
     *                       status, log or branches.
     * @param array<string,mixed> $params Required url, branch and directory.
     * @return array<string,mixed> RPC result.
     * @throws Fault Invalid parameters, unavailable Git or failed operation.
     * @example $result = $service->execute('status', ['url' => 'git@host:org/repo.git', 'branch' => 'main', 'directory' => 'repos/site']);
     * @see \Micx\SecVcs\Dispatcher::handle()
     */
    public function execute(string $method, array $params): array
    {
        $url = Storage::url($params['url'] ?? '');
        $branch = Storage::branch($params['branch'] ?? '');
        $relative = $params['directory'] ?? '';
        if (!is_string($relative)) {
            throw new Fault('INVALID_REQUEST', 'directory must be a string');
        }

        $directory = $this->storage->directory($relative);
        if ($method === 'ping' && $this->mockMode) {
            return ['pong' => true, 'ready' => true, 'errors' => [], 'mockMode' => true];
        }
        if ($method === 'ping') {
            $errors = $this->sshErrors();
            return ['pong' => true, 'ready' => $errors === [], 'errors' => $errors];
        }

        if (!in_array($method, ['pull', 'push', 'commit', 'status', 'log', 'branches'], true)) {
            throw new Fault('UNKNOWN_METHOD', 'Unknown RPC method');
        }

        if ($this->mockMode) {
            return $this->mockExecute($method, $params, $branch, $relative);
        }

        return $this->storage->lock($relative, function () use ($method, $params, $url, $branch, $relative): array {
            // Den Pfad nach Erwerb der Sperre erneut auf Symlinks prüfen.
            $directory = $this->storage->directory($relative);
            $gitDirectory = $directory . '/.git';
            if (is_link($gitDirectory)) {
                throw new Fault('INVALID_PATH', 'Git metadata must not be a symlink');
            }

            $exists = is_file($gitDirectory . '/HEAD');
            if ($exists) {
                $this->assertCheckoutPermissions($directory);
            }
            if (!$exists && $method !== 'pull') {
                throw new Fault('NOT_FOUND', 'Repository not checked out; pull first');
            }

            if ($method === 'pull') {
                $this->requireSsh();
                if (!$exists) {
                    // Niemals ein fremdes oder teilinitialisiertes Verzeichnis übernehmen.
                    if (is_dir($directory) && iterator_count(new \FilesystemIterator($directory)) > 0) {
                        throw new Fault('DIRECTORY_EXISTS', 'Checkout directory is not empty');
                    }
                    if (!is_dir($directory) && !mkdir($directory, 0770, true)) {
                        throw new Fault('IO_ERROR', 'Cannot create checkout directory');
                    }
                }
            }

            $git = new SecureGit($url, $directory, $gitDirectory, $this->key, $this->knownHosts);
            if (!$exists) {
                // Fehlschläge beim ersten Fetch dürfen keinen ungültigen Checkout zurücklassen.
                try {
                    $git->initialize($branch);
                    $this->assertCheckoutPermissions($directory);
                } catch (\Throwable $e) {
                    $this->removeDirectory($directory);
                    throw $e;
                }
                return ['revision' => $git->getRev()];
            }

            // URL und Branch müssen zum übergebenen Checkout passen.
            if (trim($git->run(['remote', 'get-url', 'origin'])) !== $url
                || trim($git->run(['symbolic-ref', '--short', 'HEAD'])) !== $branch) {
                throw new Fault('CONFLICT', 'Repository URL or branch does not match checkout');
            }

            switch ($method) {
                case 'pull':
                    if ($git->run(['status', '--porcelain=v1']) !== '') {
                        throw new Fault('DIRTY_WORKTREE', 'Commit changes before pull');
                    }
                    $git->pull();
                    $this->assertCheckoutPermissions($directory);
                    break;

                case 'push':
                    $this->requireSsh();
                    $git->push();
                    break;

                case 'commit':
                    $message = $params['message'] ?? 'Update workspace';
                    if (!is_string($message) || trim($message) === ''
                        || strlen($message) > 8192 || str_contains($message, "\0")) {
                        throw new Fault('INVALID_REQUEST', 'Invalid commit message');
                    }
                    $authorName = $params['authorName'] ?? null;
                    $authorEmail = $params['authorEmail'] ?? null;
                    if (array_key_exists('authorName', $params) !== array_key_exists('authorEmail', $params)) {
                        throw new Fault('INVALID_REQUEST', 'Commit author name and email must be provided together');
                    }
                    if ($authorName !== null || $authorEmail !== null) {
                        if (!is_string($authorName) || trim($authorName) === ''
                            || strlen($authorName) > 256 || preg_match('/[\x00-\x1f\x7f]/', $authorName)
                            || !is_string($authorEmail) || strlen($authorEmail) > 254
                            || filter_var($authorEmail, FILTER_VALIDATE_EMAIL) === false) {
                            throw new Fault('INVALID_REQUEST', 'Invalid commit author name or email');
                        }
                    }
                    $git->commit($message, $authorName, $authorEmail);
                    break;

                case 'status':
                    return [
                        'revision' => $git->getRev(),
                        'hasUnpushedChanges' => $git->hasUnpushedChanges(),
                    ];

                case 'log':
                    return ['entries' => $git->getHistory($branch)];

                case 'branches':
                    return ['branches' => $git->getBranches()];
            }

            return ['revision' => $git->getRev()];
        });
    }

    /**
     * Executes the directory-based test mode without invoking Git or SSH.
     * No repository metadata or simulated commits are persisted.
     *
     * @param array<string,mixed> $params RPC parameters
     * @return array<string,mixed> Simulated response
     * @throws Fault On invalid input or directory access errors
     * @see self::execute()
     */
    private function mockExecute(string $method, array $params, string $branch, string $relative): array
    {
        return $this->storage->lock($relative, function () use ($method, $params, $branch, $relative): array {
            // Dieselben Pfadregeln gelten auch für simulierte Operationen.
            $directory = $this->storage->directory($relative);
            if ($method === 'commit') {
                $message = $params['message'] ?? 'Update workspace';
                if (!is_string($message) || trim($message) === ''
                    || strlen($message) > 8192 || str_contains($message, "\0")) {
                    throw new Fault('INVALID_REQUEST', 'Invalid commit message');
                }
                $name = $params['authorName'] ?? null;
                $email = $params['authorEmail'] ?? null;
                if (array_key_exists('authorName', $params) !== array_key_exists('authorEmail', $params)
                    || ($name !== null && (!is_string($name) || trim($name) === ''
                        || strlen($name) > 256 || preg_match('/[\\x00-\\x1f\\x7f]/', $name)
                        || !is_string($email) || strlen($email) > 254
                        || filter_var($email, FILTER_VALIDATE_EMAIL) === false))) {
                    throw new Fault('INVALID_REQUEST', 'Invalid commit author name or email');
                }
            }
            if (is_link($directory . '/.git')) {
                throw new Fault('INVALID_PATH', 'Git metadata must not be a symlink');
            }
            if ($method === 'pull' && !is_dir($directory)
                && !mkdir($directory, 0770, true) && !is_dir($directory)) {
                throw new Fault('IO_ERROR', 'Cannot create checkout directory');
            }
            if (!is_dir($directory)) {
                throw new Fault('NOT_FOUND', 'Repository not checked out; pull first');
            }
            $this->assertCheckoutPermissions($directory);
            if ($method === 'status') {
                return ['revision' => '', 'hasUnpushedChanges' => false, 'mockMode' => true];
            }
            if ($method === 'log') {
                return ['entries' => [], 'mockMode' => true];
            }
            if ($method === 'branches') {
                return ['branches' => [['name' => $branch, 'latestCommit' => null]], 'mockMode' => true];
            }
            return ['revision' => '', 'mockMode' => true];
        });
    }

    /**
     * Check access needed by the worker without changing ownership or modes.
     * Symlinks are not followed. Report the first inaccessible path to the client.
     */
    private function assertCheckoutPermissions(string $directory): void
    {
        // Git must be able to traverse and create files in every checkout parent.
        $parent = $directory;
        while (true) {
            if (is_link($parent) || !is_dir($parent)
                || !is_readable($parent) || !is_writable($parent) || !is_executable($parent)) {
                throw new Fault('IO_ERROR', 'Insufficient directory permissions: ' . $parent);
            }
            if ($parent === $this->storage->data) {
                break;
            }
            $parent = dirname($parent);
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );
        foreach ($items as $item) {
            if ($item->isLink()) {
                continue;
            }
            $path = $item->getPathname();
            if ($item->isDir()) {
                if (!is_readable($path) || !is_writable($path) || !is_executable($path)) {
                    throw new Fault('IO_ERROR', 'Insufficient directory permissions: ' . $path);
                }
            } elseif ($item->isFile()) {
                // Git objects are immutable and intentionally often read-only (0444).
                $isGitObject = str_starts_with($path, $directory . '/.git/objects/');
                if (!is_readable($path) || (!$isGitObject && !is_writable($path))) {
                    throw new Fault('IO_ERROR', 'Insufficient file permissions: ' . $path);
                }
            }
        }
    }

    private function sshErrors(): array
    {
        $errors = [];
        clearstatcache(true, $this->key);
        if (!is_file($this->key) || !is_readable($this->key) || filesize($this->key) <= 0) {
            $errors[] = [
                'code' => 'SSH_KEY_MISSING',
                'message' => 'SSH private key file is missing, unreadable or empty',
            ];
        }

        // New host keys are written to this temporary file on first SSH contact.
        clearstatcache(true, $this->knownHosts);
        if (!is_file($this->knownHosts) || !is_readable($this->knownHosts) || !is_writable($this->knownHosts)) {
            $errors[] = [
                'code' => 'KNOWN_HOSTS_MISSING',
                'message' => 'Temporary SSH known_hosts file is missing or inaccessible',
            ];
        }

        return $errors;
    }

    private function requireSsh(): void
    {
        $errors = $this->sshErrors();
        if ($errors !== []) {
            throw new Fault($errors[0]['code'], $errors[0]['message']);
        }
    }

    /** Remove only a failed, previously empty initial checkout. */
    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }

        $entries = new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS);
        foreach ($entries as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                $this->removeDirectory($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }
}
