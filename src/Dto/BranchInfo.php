<?php
declare(strict_types=1);

namespace Micx\Vcs\Dto;

final readonly class BranchInfo
{
    public function __construct(
        public string $name,
        public ?LogEntry $latestCommit,
        public bool $current = false,
    ) {
    }
}
