#!/bin/sh
set -eu

# A healthy HTTP process alone cannot deliver reminders or keep calendars fresh.
status="$(/usr/bin/supervisorctl -c /etc/selfhandler/supervisord.conf status)"
printf '%s\n' "$status" | awk '
    $1 == "fpm" || $1 == "scheduler" || $1 == "queue" {
        if ($2 != "RUNNING") exit 1
        running++
    }
    END { if (running != 3) exit 1 }
'
php -r '$socket = @fsockopen("127.0.0.1", 9000, $errno, $error, 2); exit($socket ? 0 : 1);'
