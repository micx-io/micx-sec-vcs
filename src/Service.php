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
        if ($method === 'ping') {
            $errors = $this->sshErrors();
            return ['pong' => true, 'ready' => $errors === [], 'errors' => $errors];
        }

        if (!in_array($method, ['pull', 'push', 'commit', 'status', 'log', 'branches'], true)) {
            throw new Fault('UNKNOWN_METHOD', 'Unknown RPC method');
        }

        return $this->storage->lock($relative, function () use ($method, $params, $url, $branch, $relative): array {
            // Den Pfad nach Erwerb der Sperre erneut auf Symlinks prüfen.
            $directory = $this->storage->directory($relative);
            $gitDirectory = $directory . '/.git';
            if (is_link($gitDirectory)) {
                throw new Fault('INVALID_PATH', 'Git metadata must not be a symlink');
            }

            $exists = is_file($gitDirectory . '/HEAD');
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
