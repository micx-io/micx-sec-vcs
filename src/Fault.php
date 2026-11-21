<?php
declare(strict_types=1);
namespace Micx\SecVcs;
final class Fault extends \RuntimeException
{
    public function __construct(public readonly string $kind, string $message, public readonly array $details = []) { parent::__construct($message); }
}
