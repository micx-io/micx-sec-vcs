#!/bin/sh
# One worker at a time. Keep PID 1 alive while RabbitMQ is unavailable.
set -u
stopping=0
child=''
delay=30
stop() {
    stopping=1
    if [ -n "$child" ]; then kill -TERM "$child" 2>/dev/null || true; fi
}
trap stop TERM INT
while [ "$stopping" -eq 0 ]; do
    printf '%s supervisor: starting worker\n' "$(date -u +%FT%TZ)" >&2
    "$@" &
    child=$!
    # Cover SIGTERM arriving between launch and assignment of child PID.
    if [ "$stopping" -eq 1 ]; then kill -TERM "$child" 2>/dev/null || true; fi
    status=0
    wait "$child" || status=$?
    if [ "$stopping" -eq 1 ]; then
        wait "$child" 2>/dev/null || true
        break
    fi
    child=''
    # Jeder erneute Versuch wartet 30 Sekunden, auch direkt nach dem Start.
    printf '%s supervisor: worker stopped (exit=%s); retry in %ss\n' "$(date -u +%FT%TZ)" "$status" "$delay" >&2
    sleep "$delay" &
    child=$!
    if [ "$stopping" -eq 1 ]; then kill -TERM "$child" 2>/dev/null || true; fi
    wait "$child" || true
    child=''
done
printf '%s supervisor: stopped by signal\n' "$(date -u +%FT%TZ)" >&2
