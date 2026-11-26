<?php
declare(strict_types=1);

namespace Micx\Vcs\Repository;

use Micx\Vcs\Exception\ErrorCode;
use Micx\Vcs\Exception\AccessDeniedException;
use Micx\Vcs\Exception\RepositoryNotFoundException;
use Micx\Vcs\Exception\OperationFailedException;
use Micx\Vcs\Exception\RepositoryNotReadyException;

use Micx\Vcs\Dto\BranchInfo;
use Micx\Vcs\Dto\ChangeSet;
use Micx\Vcs\Dto\LogEntry;

/**
 * Local-directory backend: no Git, SSH, AMQP or remote service required.
 * A small JSON journal records commits, the last pushed commit and file hashes.
 */
final class VcsMockRepository implements VcsRepository
{
    private const JOURNAL = '.mix-vcs.json';
    private ?string $commitName = null;
    private ?string $commitEmail = null;

    public function __construct(private string $directory, private string $branch)
    {
    }

    /**
     * Prüft den lokalen Mock-Connector ohne Netzwerkzugriff.
     * Antwortet bei gültigem Timeout immer sofort mit true.
     *
     * Beispiel: $ok = $repo->ping();
     *
     * @throws \InvalidArgumentException Bei ungültigem Timeout.
     * @see self::pull()
     */
    public function ping(float $timeout = 5.0): bool
    {
        if (!is_finite($timeout) || $timeout <= 0 || $timeout > 300) {
            throw new \InvalidArgumentException('Timeout must be in (0, 300] seconds');
        }

        return true;
    }

    /**
     * Gibt ein existierendes Mock-Verzeichnis sofort ohne Pull zurück.
     * Anders als beim echten Git-Checkout existiert das Verzeichnis bereits.
     *
     * Beispiel: $directory = $repo->getDirectory();
     *
     * @throws RepositoryNotFoundException Wenn der Pfad nicht mehr existiert.
     * @see self::pull()
     */
    public function getDirectory(): string
    {
        if (!is_dir($this->directory)) {
            throw new RepositoryNotFoundException(
                ErrorCode::MOCK_DIRECTORY_NOT_FOUND,
                "Mock-Verzeichnis nicht gefunden: $this->directory",
                'getDirectory'
            );
        }

        if (!is_readable($this->directory) || !is_writable($this->directory)) {
            throw new AccessDeniedException(
                ErrorCode::MOCK_DIRECTORY_DENIED,
                "Mock-Verzeichnis nicht lesbar oder beschreibbar: $this->directory",
                'getDirectory'
            );
        }

        return realpath($this->directory) ?: $this->directory;
    }

    /**
     * Aktiviert das vorhandene Mock-Verzeichnis ohne Remote-Zugriff.
     *
     * Beispiel: $repo->pull();
     *
     * @throws RepositoryNotFoundException Wenn der Pfad inzwischen fehlt.
     * @see self::getDirectory()
     */
    public function pull(string $message = 'Save local changes before pull'): void
    {
        if (!is_dir($this->directory)) {
            throw new RepositoryNotFoundException(
                ErrorCode::MOCK_DIRECTORY_NOT_FOUND,
                "Mock-Verzeichnis nicht gefunden: $this->directory",
                'pull'
            );
        }
        if (!is_readable($this->directory) || !is_writable($this->directory)) {
            throw new AccessDeniedException(
                ErrorCode::MOCK_DIRECTORY_DENIED,
                "Mock-Verzeichnis nicht lesbar oder beschreibbar: $this->directory",
                'pull'
            );
        }

    }

    /** Mirror remote sync: pull leaves drafts alone, push commits and publishes. */
    public function sync(): void
    {
        $this->pull();
        $this->push();
    }

    /**
     * Sets the identity of subsequent mock commits, without network access.
     * Mock commits store identity alongside their journal entries.
     *
     * Example: $repo->setCommitUser('Jane Doe', 'jane@example.com');
     *
     * @param string $name Commit author and committer name.
     * @param string $email Commit author and committer email.
     * @return void
     * @throws \InvalidArgumentException For invalid author information.
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

    public function commit(string $message = 'Update workspace'): void
    {
        $this->getDirectory();
        if (trim($message) === '') {
            throw new \InvalidArgumentException('Commit message must not be empty');
        }
        $journal = $this->readJournal();
        $current = $this->fingerprint();

        if ($current === ($journal['committedHash'] ?? null)) {
            return;
        }

        $entry = new LogEntry(bin2hex(random_bytes(16)), $message, gmdate('c'), $this->branch);
        array_unshift($journal['history'], [
            'id' => $entry->id, 'message' => $entry->message,
            'timestamp' => $entry->timestamp, 'branch' => $entry->branch,
            'authorName' => $this->commitName ?? 'MICX',
            'authorEmail' => $this->commitEmail ?? 'micx@localhost',
        ]);
        $journal['committedHash'] = $current;
        $this->writeJournal($journal);
    }

    public function push(string $message = 'Update workspace'): void
    {
        $this->commit($message);
        $journal = $this->readJournal();
        $journal['pushedCommit'] = $journal['history'][0]['id'] ?? null;
        $this->writeJournal($journal);
    }

    /** Mock deliberately simulates success without changing files or journal. */
    public function purgeChanged(): void
    {
        $this->getDirectory();
    }

    public function hasUnpushedChanges(): bool
    {
        $this->getDirectory();
        $journal = $this->readJournal();

        return $this->fingerprint() !== ($journal['committedHash'] ?? null)
            || ($journal['history'][0]['id'] ?? null) !== ($journal['pushedCommit'] ?? null);
    }

    /** @inheritDoc */
    public function getFile(string $path, ?string $revision = 'HEAD'): ?string
    {
        throw new OperationFailedException(
            ErrorCode::UNSUPPORTED_OPERATION,
            'Mock-Repositories speichern keine versionierten Dateiinhalte: ' . $path,
            'getFile'
        );
    }

    /** @inheritDoc */
    public function getChangedFiles(?string $revision = null, ?string $targetRevision = null): array
    {
        $this->getDirectory();
        return [
            new ChangeSet('mock_created.txt', 'created'),
            new ChangeSet('mock_modified.txt', 'changed'),
            new ChangeSet('mock_deleted.txt', 'deleted'),
        ];
    }

    public function getHistory(): array
    {
        $this->getDirectory();
        return array_map(
            static fn (array $entry): LogEntry => LogEntry::fromArray($entry),
            $this->readJournal()['history']
        );
    }

    public function getBranches(): array
    {
        $this->getDirectory();
        return [new BranchInfo($this->branch, $this->getHistory()[0] ?? null, true)];
    }

    /** @return array{history: list<array<string,mixed>>, committedHash: ?string, pushedCommit: ?string} */
    private function readJournal(): array
    {
        $file = $this->directory . '/' . self::JOURNAL;
        if (!is_file($file)) {
            return ['history' => [], 'committedHash' => null, 'pushedCommit' => null];
        }
        if (!is_readable($file)) {
            throw new AccessDeniedException(ErrorCode::MOCK_DIRECTORY_DENIED, "Mock-Journal nicht lesbar: $file", 'history');
        }
        $content = file_get_contents($file);
        if ($content === false) {
            throw new OperationFailedException(ErrorCode::MOCK_IO_FAILED, "Mock-Journal konnte nicht gelesen werden: $file", 'history');
        }
        $data = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_array($data['history'] ?? null)) {
            throw new OperationFailedException(ErrorCode::MOCK_JOURNAL_INVALID, "Mock-Journal ungültig: $file", 'history');
        }

        return $data;
    }

    /** @param array<string, mixed> $journal */
    private function writeJournal(array $journal): void
    {
        $file = $this->directory . '/' . self::JOURNAL;
        $temporary = tempnam($this->directory, '.mix-vcs-');
        if ($temporary === false) {
            throw new OperationFailedException(ErrorCode::MOCK_IO_FAILED, "Mock-Journal konnte nicht erstellt werden: $this->directory", 'commit');
        }

        try {
            $json = json_encode($journal, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (file_put_contents($temporary, $json . "\n", LOCK_EX) === false
                || !chmod($temporary, 0666)
                || !rename($temporary, $file)
                || !chmod($file, 0666)) {
                throw new OperationFailedException(ErrorCode::MOCK_IO_FAILED, "Mock-Journal konnte nicht mit gemeinsamen Schreibrechten gespeichert werden: $file", 'commit');
            }
        } finally {
            if (file_exists($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** Snapshot of file contents and names, excluding internal metadata and .git. */
    private function fingerprint(): string
    {
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($this->directory) + 1);
            if ($relative === self::JOURNAL || $relative === '.git' || str_starts_with($relative, '.git/')
                || str_starts_with($relative, '.mix-vcs-')) {
                continue;
            }
            if ($file->isLink()) {
                throw new OperationFailedException(ErrorCode::MOCK_IO_FAILED, "Symlink im Mock-Verzeichnis nicht unterstützt: $relative", 'status');
            }
            if (!$file->isFile()) {
                continue;
            }
            $hash = hash_file('sha256', $file->getPathname());
            if ($hash === false) {
                throw new OperationFailedException(ErrorCode::MOCK_IO_FAILED, "Mock-Datei konnte nicht gelesen werden: $relative", 'status');
            }
            $paths[$relative] = $hash;
        }

        ksort($paths, SORT_STRING);
        return hash('sha256', json_encode($paths, JSON_THROW_ON_ERROR));
    }
}
