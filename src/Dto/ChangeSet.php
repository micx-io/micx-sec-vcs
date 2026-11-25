<?php
declare(strict_types=1);

namespace Micx\Vcs\Dto;

/** One changed file relative to the selected comparison base. */
final readonly class ChangeSet
{
    /**
     * @param string $filename Repository-relative file path.
     * @param 'created'|'changed'|'deleted' $change Change relative to the base.
     * @throws \InvalidArgumentException If the change type is invalid.
     * @see \Micx\Vcs\Repository\VcsRepository::getChangedFiles()
     */
    public function __construct(
        public string $filename,
        public string $change,
    ) {
        if (!in_array($change, ['created', 'changed', 'deleted'], true)) {
            throw new \InvalidArgumentException('Invalid file change type: ' . $change);
        }
    }
}
