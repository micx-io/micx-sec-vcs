<?php
declare(strict_types=1);
namespace Micx\Vcs\Transport;
/** The wait expired; request preserves the exact identity for a deliberate retry. */
final class OperationTimeoutException extends RpcException
{
    public function __construct(string $message, public readonly array $request, array $details = [])
    {
        parent::__construct('TIMEOUT', $message, array_merge($details, ['requestId'=>$request['id']]));
    }
}
