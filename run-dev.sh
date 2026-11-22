#!/bin/bash
# Starts what the app needs in development: web server, queue worker, scheduler.
# Each process is remembered by its own pid file, so stopping never touches another project.
cd "$(dirname "$0")"
mkdir -p storage/run

stop() {
    for file in storage/run/*.pid; do
        [ -f "$file" ] || continue
        pid=$(cat "$file")
        # Only stop it if that pid is still one of ours.
        if [ -n "$pid" ] && grep -q "$(pwd)" "/proc/$pid/environ" 2>/dev/null; then kill "$pid" 2>/dev/null; fi
        rm -f "$file"
    done
}

start() {
    PIXELFORGE_DEV_DIR="$(pwd)" setsid nohup "${@:2}" > "storage/logs/$1.log" 2>&1 < /dev/null &
    echo $! > "storage/run/$1.pid"
}

stop
[ "$1" = "stop" ] && { echo stopped; exit 0; }
sleep 1
start serve php artisan serve --host=127.0.0.1 --port=8455
start queue php artisan queue:work --tries=1 --sleep=1
start schedule php artisan schedule:work
sleep 2
echo started
