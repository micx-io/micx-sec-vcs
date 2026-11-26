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
     *                       status, log, branches, getFile or getChangedFiles.
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

        if (!in_array($method, ['pull', 'push', 'commit', 'status', 'log', 'branches', 'getFile', 'getChangedFiles', 'purgeChanged'], true)) {
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
                $this->assertCheckoutPermissions($directory, $method === 'pull');
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
                    if (!is_dir($directory)) {
                        $previousUmask = umask(0000);
                        try {
                            if (!mkdir($directory, 0777, true)) {
                                throw new Fault('IO_ERROR', 'Cannot create checkout directory: ' . $directory);
                            }
                        } finally {
                            umask($previousUmask);
                        }
                    }
                }
            }

            $git = new SecureGit($url, $directory, $gitDirectory, $this->key, $this->knownHosts);
            if (!$exists) {
                // Fehlschläge beim ersten Fetch dürfen keinen ungültigen Checkout zurücklassen.
                try {
                    $git->initialize($branch);
                } catch (\Throwable $e) {
                    if (!$e instanceof Fault || $e->kind !== 'IO_ERROR') {
                        $this->removeDirectory($directory);
                    }
                    throw $e;
                }
                // Eine fehlgeschlagene Rechteprüfung darf niemals Dateien löschen.
                $this->assertCheckoutPermissions($directory, true);
                return ['revision' => $git->getRev()];
            }

            // URL und Branch müssen zum übergebenen Checkout passen.
            if (trim($git->run(['remote', 'get-url', 'origin'])) !== $url
                || trim($git->run(['symbolic-ref', '--short', 'HEAD'])) !== $branch) {
                throw new Fault('CONFLICT', 'Repository URL or branch does not match checkout');
            }

            switch ($method) {
                case 'pull':
                    // The full transaction runs under the same repository lock.
                    $this->requireSsh();
                    $message = $params['message'] ?? 'Save local changes before pull';
                    if (!is_string($message) || trim($message) === ''
                        || strlen($message) > 8192 || str_contains($message, "\0")) {
                        throw new Fault('INVALID_REQUEST', 'Invalid commit message');
                    }
                    $authorName = $params['authorName'] ?? null;
                    $authorEmail = $params['authorEmail'] ?? null;
                    if (array_key_exists('authorName', $params) !== array_key_exists('authorEmail', $params)
                        || ($authorName !== null && (!is_string($authorName) || trim($authorName) === ''
                            || strlen($authorName) > 256 || preg_match('/[\x00-\x1f\x7f]/', $authorName)
                            || !is_string($authorEmail) || strlen($authorEmail) > 254
                            || filter_var($authorEmail, FILTER_VALIDATE_EMAIL) === false))) {
                        throw new Fault('INVALID_REQUEST', 'Invalid commit author name or email');
                    }
                    if ($git->run(['status', '--porcelain=v1']) !== '') {
                        $git->commit($message, $authorName, $authorEmail);
                    }
                    $git->pull();
                    $this->assertCheckoutPermissions($directory, true);
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

                case 'getChangedFiles':
                    $revision = $params['revision'] ?? null;
                    if ($revision !== null && !is_string($revision)) {
                        throw new Fault('INVALID_REQUEST', 'revision must be a string or null');
                    }
                    $targetRevision = $params['targetRevision'] ?? null;
                    if ($targetRevision !== null && !is_string($targetRevision)) {
                        throw new Fault('INVALID_REQUEST', 'targetRevision must be a string or null');
                    }
                    $base = $revision === null ? null : $this->resolveRevision($git, $revision);
                    $target = $targetRevision === null ? null : $this->resolveRevision($git, $targetRevision);

                    return $git->getChangesSincePush($branch, $base, $target);

                case 'purgeChanged':
                    $git->purgeChanged($branch);
                    return ['revision' => $git->getRev()];

                case 'getFile':
                    $path = Storage::relative($params['path'] ?? '');
                    $revision = array_key_exists('revision', $params) ? $params['revision'] : 'HEAD';
                    if ($revision !== null && !is_string($revision)) {
                        throw new Fault('INVALID_REQUEST', 'revision must be a string or null');
                    }
                    $resolved = $revision === null
                        ? $git->getPublishedRevision($branch)
                        : $this->resolveRevision($git, $revision);
                    $content = $resolved === null ? null : $git->readCommittedFile($path, $resolved);
                    return [
                        'exists' => $content !== null,
                        'contentBase64' => $content === null ? null : base64_encode($content),
                    ];

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

    /** Resolve a local commit or fetch a missing explicit tag without checkout. */
    private function resolveRevision(SecureGit $git, string $revision): string
    {
        $ref = $revision;
        if ($revision !== 'HEAD' && !preg_match('/^(?:[a-fA-F0-9]{40}|[a-fA-F0-9]{64})$/D', $revision)) {
            $ref = 'refs/tags/' . Storage::branch($revision);
            try {
                $git->run(['show-ref', '--verify', '--quiet', $ref]);
            } catch (Fault $e) {
                if ($e->kind !== 'GIT_FAILED' || ($e->details['exitCode'] ?? null) !== 1) {
                    throw $e;
                }
                $this->requireSsh();
                // Fetch only this tag, without changing HEAD, index or worktree.
                $git->run(['fetch', '--no-tags', '--no-write-fetch-head', 'origin', $ref . ':' . $ref]);
            }
        }

        try {
            return trim($git->run(['rev-parse', '--verify', '--end-of-options', $ref . '^{commit}']));
        } catch (Fault $e) {
            if ($e->kind === 'GIT_FAILED') {
                throw new Fault('GIT_FAILED', 'Cannot resolve revision: ' . $revision);
            }
            throw $e;
        }
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
            if ($method === 'commit' || $method === 'pull') {
                $message = $params['message'] ?? ($method === 'pull' ? 'Save local changes before pull' : 'Update workspace');
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
            if (!is_dir($directory)) {
                throw new Fault(
                    'NOT_FOUND',
                    'Mock mode is active (CONF_MOCK_MODE=true): directory "' . $directory
                    . '" does not exist on this system. Is Development mode intended? '
                    . 'For Production mode, set CONF_MOCK_MODE=false in the worker configuration.'
                );
            }
            $this->assertCheckoutPermissions($directory);
            if ($method === 'getChangedFiles') {
                return [
                    'added' => ['mock_created.txt'],
                    'modified' => ['mock_modified.txt'],
                    'deleted' => ['mock_deleted.txt'],
                    'mockMode' => true,
                ];
            }
            if ($method === 'getFile') {
                throw new Fault('UNSUPPORTED', 'Mock mode does not store versioned file contents');
            }
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
     * Check working-tree access without changing ownership or modes.
     * Pull additionally checks shared permissions; other RPCs check worker access.
     * Git metadata and symlinks are excluded from the recursive scan.
     */
    private function assertCheckoutPermissions(string $directory, bool $shared = false): void
    {
        clearstatcache();
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
            $required = $parent === $directory ? 0777 : 0111;
            if ($shared && (fileperms($parent) & $required) !== $required) {
                throw new Fault('IO_ERROR', 'Insufficient shared directory permissions: ' . $parent);
            }
            $parent = dirname($parent);
        }

        // Filter before recursion: even inaccessible .git children are never visited.
        $workingTree = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $item): bool => !$item->isLink()
                && strcasecmp($item->getFilename(), '.git') !== 0,
        );
        $items = new \RecursiveIteratorIterator(
            $workingTree,
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
                if ($shared && ($item->getPerms() & 0777) !== 0777) {
                    throw new Fault('IO_ERROR', 'Insufficient shared directory permissions: ' . $path);
                }
            } elseif ($item->isFile()) {
                if (!is_readable($path) || !is_writable($path)) {
                    throw new Fault('IO_ERROR', 'Insufficient file permissions: ' . $path);
                }
                if ($shared && ($item->getPerms() & 0666) !== 0666) {
                    throw new Fault('IO_ERROR', 'Insufficient shared file permissions: ' . $path);
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
