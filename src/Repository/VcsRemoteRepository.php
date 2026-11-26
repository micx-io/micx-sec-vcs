<?php

declare(strict_types=1);

namespace Micx\Vcs\Repository;

use Micx\Vcs\Dto\BranchInfo;
use Micx\Vcs\Dto\ChangeSet;
use Micx\Vcs\Dto\LogEntry;
use Micx\Vcs\Exception\ErrorCode;
use Micx\Vcs\Exception\InternalException;
use Micx\Vcs\Helper\Workspace\RpcClient;

/**
 * Stateless repository handle. URL, branch and relative directory travel
 * with every RPC; the client computes the checkout path without a roundtrip.
 */
final class VcsRemoteRepository implements VcsRepository
{
    private ?RpcClient $client = null;
    private ?string $commitName = null;
    private ?string $commitEmail = null;

    private readonly string $relativeDirectory;

    /** @param \Closure(): RpcClient $clientFactory */
    public function __construct(
        private \Closure $clientFactory,
        private string $repository,
        private string $branch,
        private string $sharedDirectory,
        ?string $directory = null,
    ) {
        $directory ??= 'repos/' . hash('sha256', json_encode([$repository, $branch], JSON_THROW_ON_ERROR));
        $this->relativeDirectory = self::validateDirectory($directory);
    }

    /**
     * Checks RabbitMQ/worker reachability without opening the checkout.
     *
     * Example: $ready = $repo->ping();
     * @return bool Worker ping result
     * @throws \InvalidArgumentException If timeout is invalid.
     * @see self::pull()
     */
    public function ping(float $timeout = 5.0): bool
    {
        if (!is_finite($timeout) || $timeout <= 0 || $timeout > 300) {
            throw new \InvalidArgumentException('Timeout must be in (0, 300] seconds');
        }

        $result = $this->client()->call('ping', $this->params(), $timeout);
        if (!is_bool($result['pong'] ?? null)) {
            throw new InternalException(ErrorCode::INVALID_RESPONSE, 'Ping-Antwort enthält kein boolesches pong.', 'ping');
        }

        return $result['pong'];
    }

    /**
     * Returns the deterministic absolute path without filesystem or RPC access.
     * The directory may not exist yet; pull() creates its checkout.
     *
     * Example: $directory = $repo->getDirectory();
     * @return string Absolute path beneath sharedDirectory.
     * @see self::pull()
     */
    public function getDirectory(): string
    {
        return rtrim($this->sharedDirectory, '/') . '/' . $this->relativeDirectory;
    }

    /**
     * Initialize the checkout or commit dirty files before pulling; never pushes.
     * Commit identity configured through setCommitUser() is respected.
     */
    public function pull(string $message = 'Save local changes before pull'): void
    {
        $params = $this->params() + ['message' => $message];
        if ($this->commitName !== null) {
            $params['authorName'] = $this->commitName;
            $params['authorEmail'] = $this->commitEmail;
        }
        $this->client()->call('pull', $params);
    }

    /** Pulls (committing dirty files first) then pushes. @see self::pull() */
    public function sync(): void
    {
        $this->pull();
        $this->push();
    }

    /**
     * Sets the identity used for future commit() calls on this repository handle.
     * No RPC is sent until commit() is invoked, and other handles are unaffected.
     *
     * Example: $repo->setCommitUser('Jane Doe', 'jane@example.com');
     *
     * @param string $name Git author and committer name.
     * @param string $email Git author and committer email address.
     * @return void
     * @throws \InvalidArgumentException For invalid names or email addresses.
     * @see self::commit()
     */
    public function setCommitUser(string $name, string $email): void
    {
        if (trim($name) === '' || strlen($name) > 256 || preg_match('/[\x00-\x1f\x7f]/', $name)
            || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Invalid Git commit author name or email.');
        }

        $this->commitName = $name;
        $this->commitEmail = $email;
    }

    /** Records local changes without a push. @see self::push() */
    public function commit(string $message = 'Update workspace'): void
    {
        $params = $this->params() + ['message' => $message];
        if ($this->commitName !== null) {
            $params['authorName'] = $this->commitName;
            $params['authorEmail'] = $this->commitEmail;
        }

        $this->client()->call('commit', $params);
    }

    /** Publishes local commits. @see self::commit() */
    public function push(): void
    {
        $this->client()->call('push', $this->params());
    }

    /** Returns true for dirty or unpushed commits. @see self::getHistory() */
    public function hasUnpushedChanges(): bool
    {
        $status = $this->client()->call('status', $this->params());
        if (!is_bool($status['hasUnpushedChanges'] ?? null)) {
            throw new InternalException(ErrorCode::INVALID_RESPONSE, 'Worker liefert keinen gültigen Status.', 'status');
        }

        return $status['hasUnpushedChanges'];
    }

    /** @inheritDoc */
    public function getFile(string $path, ?string $revision = 'HEAD'): ?string
    {
        $result = $this->client()->call('getFile', $this->params() + ['path' => $path, 'revision' => $revision]);
        if (($result['exists'] ?? null) === false && array_key_exists('contentBase64', $result) && $result['contentBase64'] === null) {
            return null;
        }
        $encoded = $result['contentBase64'] ?? null;
        $content = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($content === false) {
            throw new InternalException(ErrorCode::INVALID_RESPONSE, 'Worker liefert keinen gültigen Dateiinhalt.', 'getFile');
        }

        return $content;
    }

    /** @inheritDoc */
    public function getChangedFiles(?string $revision = null, ?string $targetRevision = null): array
    {
        $result = $this->client()->call('getChangedFiles', $this->params() + ['revision' => $revision, 'targetRevision' => $targetRevision]);
        $changes = [];
        foreach (['added' => 'created', 'modified' => 'changed', 'deleted' => 'deleted'] as $kind => $change) {
            $paths = $result[$kind] ?? null;
            if (!is_array($paths) || !array_is_list($paths) || count(array_filter($paths, 'is_string')) !== count($paths)) {
                throw new InternalException(ErrorCode::INVALID_RESPONSE, 'Worker liefert keine gültige Dateiliste.', 'getChangedFiles');
            }
            foreach ($paths as $path) {
                $changes[] = new ChangeSet($path, $change);
            }
        }

        return $changes;
    }

    /** @return list<LogEntry> @see self::getBranches() */
    public function getHistory(): array
    {
        $result = $this->client()->call('log', $this->params());
        if (!is_array($result['entries'] ?? null)) {
            throw new InternalException(ErrorCode::INVALID_RESPONSE, 'Worker liefert keine gültige Log-Liste.', 'log');
        }

        return array_map(static fn (array $item): LogEntry => LogEntry::fromArray($item), $result['entries']);
    }

    /** @return list<BranchInfo> @see self::getHistory() */
    public function getBranches(): array
    {
        $result = $this->client()->call('branches', $this->params());
        if (!is_array($result['branches'] ?? null)) {
            throw new InternalException(ErrorCode::INVALID_RESPONSE, 'Worker liefert keine gültige Branch-Liste.', 'branches');
        }

        return array_map(
            fn (array $item): BranchInfo => new BranchInfo(
                (string) $item['name'],
                isset($item['latestCommit']) ? LogEntry::fromArray($item['latestCommit']) : null,
                (string) $item['name'] === $this->branch
            ),
            $result['branches']
        );
    }

    /** @return array{url:string,branch:string,directory:string} */
    private function params(): array
    {
        return [
            'url' => $this->repository,
            'branch' => $this->branch,
            'directory' => $this->relativeDirectory,
        ];
    }

    /** Relative paths must not access Git internals or leave the shared volume. */
    private static function validateDirectory(string $directory): string
    {
        if ($directory === '' || strlen($directory) > 512 || preg_match('/[\\\\\x00-\x1f\x7f]/', $directory)) {
            throw new \InvalidArgumentException('Ungültiges relatives Repository-Verzeichnis.');
        }

        foreach (explode('/', $directory) as $part) {
            if ($part === '' || $part === '.' || $part === '..'
                || str_starts_with($part, '-') || strcasecmp($part, '.git') === 0) {
                throw new \InvalidArgumentException('Ungültige Repository-Verzeichniskomponente.');
            }
        }

        return $directory;
    }

    private function client(): RpcClient
    {
        return $this->client ??= ($this->clientFactory)();
    }
}
