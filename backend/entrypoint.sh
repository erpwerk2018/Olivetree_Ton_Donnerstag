#!/bin/bash
set -e  # only for the setup steps below

: "${ICECAST_SOURCE_PASSWORD:?must be set}"
: "${ICECAST_ADMIN_PASSWORD:?must be set}"
: "${ICECAST_RELAY_PASSWORD:?must be set}"
: "${DENDRO_API_KEY:?must be set}"

sed -e "s/OLIVETREE_SOURCE_PASSWORD/${ICECAST_SOURCE_PASSWORD}/" \
    -e "s/OLIVETREE_ADMIN_PASSWORD/${ICECAST_ADMIN_PASSWORD}/" \
    -e "s/OLIVETREE_RELAY_PASSWORD/${ICECAST_RELAY_PASSWORD}/" \
    /app/icecast.xml > /etc/icecast2/icecast.xml

mkdir -p /var/log/icecast2 /data/queue /data/logs
chown -R icecast2:icecast /var/log/icecast2

# Docker's own log storage on this host rotates/truncates aggressively, so any
# crash diagnostic printed only via echo/docker-logs can be lost before anyone
# reads it. Everything below is ALSO persisted to /data/logs, which survives
# container restarts and is unaffected by docker's log rotation.
BOOT_TIME="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "=== boot $BOOT_TIME ===" >> /data/logs/crash.log

echo "[entrypoint] starting icecast2"
icecast2 -c /etc/icecast2/icecast.xml >> /data/logs/icecast.log 2>&1 &
ICECAST_PID=$!

echo "[entrypoint] starting streamer"
python3 /app/streamer.py >> /data/logs/streamer.log 2>&1 &
STREAMER_PID=$!

echo "[entrypoint] starting generator (foreground)"
python3 /app/generator.py >> /data/logs/generator.log 2>&1 &
GENERATOR_PID=$!

set +e  # from here on, handle failures ourselves so the diagnostic below always runs

# if any of the three dies, log exactly which one and its exit code, then
# shut the whole container down cleanly so Docker restarts everything fresh
wait -n -p DIED_PID "$ICECAST_PID" "$STREAMER_PID" "$GENERATOR_PID"
EXIT_CODE=$?

case "$DIED_PID" in
    "$ICECAST_PID")   WHO="icecast2" ;;
    "$STREAMER_PID")  WHO="streamer.py" ;;
    "$GENERATOR_PID") WHO="generator.py" ;;
    *)                WHO="unknown (pid $DIED_PID)" ;;
esac
MSG="[entrypoint] $WHO exited with code $EXIT_CODE at $(date -u +%Y-%m-%dT%H:%M:%SZ) -- shutting down container for a clean restart"
echo "$MSG"
echo "$MSG" >> /data/logs/crash.log

kill "$ICECAST_PID" "$STREAMER_PID" "$GENERATOR_PID" 2>/dev/null
exit 1
