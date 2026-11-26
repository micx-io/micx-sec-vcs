<?php
declare(strict_types=1);

namespace Micx\Vcs\Repository;

use Micx\Vcs\Dto\BranchInfo;
use Micx\Vcs\Dto\ChangeSet;
use Micx\Vcs\Dto\LogEntry;

interface VcsRepository
{
    /**
     * Prüft die Erreichbarkeit des Backends ohne Repository-Checkout.
     * Mock-Repositories antworten sofort mit true.
     *
     * Beispiel: $online = $repo->ping(5.0);
     *
     * @param float $timeout Maximale RPC-Wartezeit in Sekunden, Standard 5.
     * @return bool true bei erfolgreicher Ping-Antwort
     * @see self::pull()
     */
    public function ping(float $timeout = 5.0): bool;

    /** Absolute application-visible directory holding this repository's files. */
    public function getDirectory(): string;

    /**
     * Ensure checkout exists, committing dirty local files before pulling.
     * Uses the supplied message for an automatic local commit; never pushes.
     * The worker prefers local contents if merging encounters conflicts.
     */
    public function pull(string $message = 'Save local changes before pull'): void;

    /**
     * Pull remote changes (auto-committing dirty files first), then push local commits.
     * Remote pull retains the worker's own-changes-win conflict strategy.
     * Example: $repo->sync();
     * @see self::pull()
     * @see self::push()
     */
    public function sync(): void;

    /**
     * Sets the author and committer identity for subsequent commits on this handle.
     * The change is local to the SDK handle; it does not trigger a worker RPC.
     *
     * Example: $repo->setCommitUser('Jane Doe', 'jane@example.com');
     *
     * @param string $name Non-empty Git commit name.
     * @param string $email Non-empty Git commit email address.
     * @return void
     * @throws \InvalidArgumentException If the identity is invalid.
     * @see self::commit()
     */
    public function setCommitUser(string $name, string $email): void;

    /** Record working tree changes without publishing them. */
    public function commit(string $message = 'Update workspace'): void;

    /** Publish local commits to the remote. */
    public function push(): void;

    /** True for dirty files OR committed changes not yet published. */
    public function hasUnpushedChanges(): bool;

    /**
     * Reads exact committed file bytes without changing the checkout or index.
     * HEAD refers to the local commit; a tag or full commit hash selects history.
     * Missing local tags can be fetched from origin. Mocks have no file history.
     *
     * Example: $content = $repo->getFile('index.html', 'v1.0.0');
     * @param string $path Repository-relative file path.
     * @param string|null $revision HEAD, tag, full commit hash; null selects the last known published state.
     * @return string|null Original bytes; null if the file did not exist at that version.
     * @throws \Micx\Vcs\Exception\VcsException If unavailable or the RPC fails.
     * @see self::getHistory()
     */
    public function getFile(string $path, ?string $revision = 'HEAD'): ?string;

    /**
     * Compares a selected revision or last known pushed state with the working tree or another revision.
     * Includes untracked (non-ignored) files and locally committed changes.
     * Missing tags may be fetched; never commits, stages or changes the working tree.
     * @param ?string $revision Null for origin branch, otherwise HEAD, tag or full local commit hash.
     * @param ?string $targetRevision Null for current working tree, otherwise HEAD, tag or full local commit hash.
     *
     * Example: foreach ($repo->getChangedFiles() as $change) { echo $change->filename; }
     * @return list<ChangeSet> One entry per file with a relative filename and change type.
     * @throws \Micx\Vcs\Exception\VcsException If the comparison is unavailable.
     * @see self::hasUnpushedChanges()
     */
    public function getChangedFiles(?string $revision = null, ?string $targetRevision = null): array;

    /** @return list<LogEntry> Most recent commits first. */
    public function getHistory(): array;

    /** @return list<BranchInfo> */
    public function getBranches(): array;
}
