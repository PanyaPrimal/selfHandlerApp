#!/bin/sh
set -eu

# A healthy HTTP process alone cannot deliver reminders or keep calendars fresh.
status="$(/usr/bin/supervisorctl -c /etc/selfhandler/supervisord.conf status)"
printf '%s\n' "$status" | awk '
    $1 == "fpm" || $1 == "scheduler" || $1 == "queue" || $1 == "chatgpt" {
        if ($2 != "RUNNING") exit 1
        running++
    }
    END { if (running != 4) exit 1 }
'
php -r '$socket = @fsockopen("127.0.0.1", 9000, $errno, $error, 2); exit($socket ? 0 : 1);'
node -e 'fetch("http://127.0.0.1:8091/health", {signal: AbortSignal.timeout(1500)}).then(r => process.exit(r.ok ? 0 : 1)).catch(() => process.exit(1))'
