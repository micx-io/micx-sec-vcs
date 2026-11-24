# Mix VCS error codes

All public SDK exceptions extend `Micx\Vcs\Exception\VcsException`.
The message starts with a stable identifier, e.g. `MIX-VCS-1001 (pull)`.
The fields `errorCode`, `operation` and `details` can be logged and
processed without parsing text. `details['workerCode']` retains the worker's
original RPC error code. Errors from caller argument validation remain native
`InvalidArgumentException` exceptions.

| Number | Exception group | Likely cause | Suggested action |
| --- | --- | --- | --- |
| `MIX-VCS-1001` | Internal / Connection | RabbitMQ unavailable, broker credentials invalid, connection closed | Check broker host, port, credentials, network and TLS |
| `MIX-VCS-1002` | Internal / Connection | RPC request was not routed to worker | Check exchange `micx.vcs.v1`, queue binding and worker |
| `MIX-VCS-1003` | Internal / Connection | Worker did not answer before RPC deadline | Inspect worker state; operation may already have executed |
| `MIX-VCS-1004` | Internal / Connection | Broker connection lost after publish | Check operation outcome before any retry |
| `MIX-VCS-1005` | Internal | Worker/connector missing, unsupported or misconfigured | Check worker version and configuration |
| `MIX-VCS-1006` | Internal | Shared directory for remote operation missing, not absolute or not readable | Configure existing shared mount accessible to the PHP service |
| `MIX-VCS-2001` | Repository / Authentication | Missing/invalid SSH key, failed authentication or host-key check | Configure worker SSH key and trusted hosts |
| `MIX-VCS-2002` | Repository / AccessDenied | No Git repository permissions or push not allowed | Check Git server authorization and branch policy |
| `MIX-VCS-2003` | Repository / NotFound | Repository or branch missing/unreachable | Check URL, branch and credentials |
| `MIX-VCS-2004` | Repository / Conflict | Dirty worktree or conflicting changes | Commit changes, then pull and retry |
| `MIX-VCS-2005` | Repository / Conflict | Remote rejected push | Pull newer commits; inspect branch protection |
| `MIX-VCS-2006` | Repository / Timeout | Git pull/push or worker-reported operation timed out | Inspect Git remote and result before retry |
| `MIX-VCS-2007` | Repository / OperationFailed | Unclassified Git failure | Review message, operation and worker diagnostics |
| `MIX-VCS-3001` | Repository / NotFound | Mock directory does not exist | Create the mapped directory or correct the path |
| `MIX-VCS-3002` | Repository / AccessDenied | Mock directory or journal cannot be accessed | Check ownership, filesystem and permissions |
| `MIX-VCS-3003` | Repository / OperationFailed | Invalid local `.mix-vcs.json` | Inspect or recover mock journal metadata |
| `MIX-VCS-3004` | Repository / OperationFailed | Mock filesystem read/write failure | Check free space, filesystem and symlinks |
| `MIX-VCS-3005` | Repository / NotFound | Requested repository + branch not registered in mock-only mode | Configure an exact mock mapping or a remote connector |
| `MIX-VCS-9001` | Internal | Malformed/incomplete RPC response | Match worker and SDK protocol versions |
| `MIX-VCS-9002` | Internal | Worker has not implemented a required RPC operation | Upgrade worker to implement the method |

## Catch by responsibility

```php
try {
    $repo->push();
} catch (\Micx\Vcs\Exception\AccessDeniedException $e) {
    // Git permissions or branch protection require user action.
    error_log($e->getMessage());
} catch (\Micx\Vcs\Exception\RepositoryException $e) {
    // A Git operation failed: examine $e->errorCode and $e->operation.
    error_log($e->getMessage());
} catch (\Micx\Vcs\Exception\InternalException $e) {
    // RabbitMQ, worker, configuration or protocol requires operator action.
    error_log($e->getMessage());
}
```

**Timeout safety:** An RPC timeout is not evidence that the worker rolled
back an operation. Do not repeat non-idempotent operations blindly.
