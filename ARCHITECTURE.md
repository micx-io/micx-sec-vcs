# Architecture: draft and publication

## Working tree versus published state

The shared working tree is the user's editable draft. Pulling must not
create a commit from this draft: editing and publishing are separate
operations, and users must be able to discard unpublished changes.

The remote-tracking reference `refs/remotes/origin/<branch>` records the
last known published revision. Git updates it on successful fetch and
push. It is the baseline for change detection and `purgeChanged()`,
not the local HEAD, which may contain unpublished explicit commits.
Purge resets HEAD and tracked files to this baseline, removes non-ignored
untracked files and discards a pending MICX recovery stash. It does not
contact the remote or force-push. Without a published base it fails.

## Pull: preserve the draft (Accept Mine)

Initial checkout is unchanged. For an existing checkout, fetch the remote,
stash staged, unstaged and new non-ignored files, integrate the remote
with local preference, then merge the stash tree without committing.
The stash is the local side during restoration (Git's theirs), so
conflicting content, binary files and modify/delete changes favor the
draft. Independent remote changes remain. Reset only the index to HEAD,
leaving the resulting draft uncommitted. Drop the stash only after
successful restoration. Original staging distinctions are not preserved:
the shared workspace represents one draft, not a staging UI.

## Push: publish the current draft (Accept Mine)

Push first commits current changes with the supplied message and author
under the same repository lock. It fetches and merges newer remote
commits with local preference before normal push. Conflicting changes
favor the user's local version; non-conflicting remote edits are
included. The full history of both sides is retained. Use the normal
merge strategy with `-X ours`, never the `-s ours` strategy that discards
the entire remote tree, and never force-push.

A rejected push is retried at most twice; permission errors, branch
protection and unavailable remotes can still prevent publication.
Explicit commit remains optional and does not publish. Sync performs
pull followed by push.

## Failure and locking

The worker has no interactive merge editor. Ordinary conflicts are
resolved automatically by choosing the local side. Ambiguous structural
conflicts or storage failures must not publish a partial draft: abort
the merge, return an error, and retain the recovery stash if restoration
cannot finish. A pending stash is reported as an unpublished change and
blocks further commit/push/pull until recovered or explicitly purged.
The repository lock spans each RPC; applications must not concurrently
write shared files during a Git operation. Ignored files are not stashed
or cleaned; merge must not overwrite ignored paths.

Both mock backends retain their existing limitations; the local client
mock journals commits on commit/push, not pull, but does not store
restorable file versions.
