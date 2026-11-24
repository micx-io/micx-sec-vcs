#!/bin/sh
set -eu
umask 077

# Das Datenvolume muss extern bereitgestellt werden; niemals hier erzeugen.
if [ -z "${CONF_DATA_DIR:-}" ]; then
    echo 'CONF_DATA_DIR_MISSING: set CONF_DATA_DIR to an existing externally mounted directory' >&2
    exit 1
fi
case "$CONF_DATA_DIR" in
    /*) ;;
    *) echo "CONF_DATA_DIR_INVALID: directory must be absolute: $CONF_DATA_DIR" >&2; exit 1 ;;
esac
if [ ! -d "$CONF_DATA_DIR" ] || [ -L "$CONF_DATA_DIR" ]; then
    echo "CONF_DATA_DIR_NOT_FOUND: configured data directory does not exist or is a symlink: $CONF_DATA_DIR" >&2
    exit 1
fi
if [ ! -r "$CONF_DATA_DIR" ] || [ ! -w "$CONF_DATA_DIR" ] || [ ! -x "$CONF_DATA_DIR" ]; then
    echo "CONF_DATA_DIR_ACCESS_DENIED: worker cannot access data directory: $CONF_DATA_DIR" >&2
    exit 1
fi

php /app/docker/startup-info.php

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
