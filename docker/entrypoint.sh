#!/bin/sh
set -eu
umask 077
mkdir -p /run/micx

# Fehlende Credentials verhindern nur Git-Zugriffe, nicht den RPC-Ping.
rm -f /run/micx/id_key /run/micx/known_hosts
if [ -n "${VCS_SSH_KEY_FILE:-}" ]; then
    if [ -f "$VCS_SSH_KEY_FILE" ] && [ -r "$VCS_SSH_KEY_FILE" ] && [ -s "$VCS_SSH_KEY_FILE" ] \
        && cat "$VCS_SSH_KEY_FILE" > /run/micx/id_key 2>/dev/null; then
        chmod 600 /run/micx/id_key
    else
        rm -f /run/micx/id_key
        echo 'SSH_KEY_MISSING: configured SSH key file is missing, unreadable or empty; ping remains available' >&2
    fi
elif [ -n "${VCS_SSH_KEY:-}" ]; then
    printf '%s\n' "$VCS_SSH_KEY" > /run/micx/id_key
    chmod 600 /run/micx/id_key
else
    echo 'SSH_KEY_MISSING: no SSH private key configured; ping remains available' >&2
fi
unset VCS_SSH_KEY

# known_hosts is recreated in the ephemeral /run/micx tmpfs on every container start.
: > /run/micx/known_hosts
chmod 600 /run/micx/known_hosts
exec sh /app/docker/supervisor.sh php /app/src/worker.php
