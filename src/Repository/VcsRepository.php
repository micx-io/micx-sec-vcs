<?php
declare(strict_types=1);

namespace Micx\Vcs\Repository;

use Micx\Vcs\Dto\BranchInfo;
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

    /** @return list<LogEntry> Most recent commits first. */
    public function getHistory(): array;

    /** @return list<BranchInfo> */
    public function getBranches(): array;
}
