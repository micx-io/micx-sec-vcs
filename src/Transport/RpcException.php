<?php
declare(strict_types=1);
namespace Micx\Vcs\Transport;
class RpcException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
