<?php
declare(strict_types=1);
namespace Micx\Vcs\Transport;
interface RpcTransport
{
    /** Implementations must preserve request IDs and enforce a bounded wait. */
    public function request(array $request, float $timeout): array;
}
