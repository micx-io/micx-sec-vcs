#!/bin/sh
set -eu
umask 077
mkdir -p /run/micx
if [ -n "${VCS_SSH_KEY_FILE:-}" ]; then
    cat "$VCS_SSH_KEY_FILE" > /run/micx/id_key
elif [ -n "${VCS_SSH_KEY:-}" ]; then
    printf '%s\n' "$VCS_SSH_KEY" > /run/micx/id_key
else
    echo 'VCS_SSH_KEY_FILE or VCS_SSH_KEY required' >&2
    exit 1
fi
unset VCS_SSH_KEY
cat "${VCS_KNOWN_HOSTS_FILE:-/run/secrets/known_hosts}" > /run/micx/known_hosts
chmod 600 /run/micx/id_key /run/micx/known_hosts
exec php /app/src/worker.php
