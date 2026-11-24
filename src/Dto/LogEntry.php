<?php
declare(strict_types=1);

namespace Micx\Vcs\Dto;

final readonly class LogEntry
{
    public function __construct(
        public string $id,
        public string $message,
        public string $timestamp,
        public string $branch,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['id'] ?? ''),
            (string) ($data['message'] ?? ''),
            (string) ($data['timestamp'] ?? ''),
            (string) ($data['branch'] ?? ''),
        );
    }
}
