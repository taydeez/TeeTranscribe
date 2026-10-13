#!/bin/sh
set -eu
cd /var/www/html

role="${1:-api}"
case "$role" in
    api|general|processing|ingestion|transcriptions|exports|scheduler|subtitle|dubbing|staging-workers) ;;
    *) echo "Unknown service role: $role" >&2; exit 64 ;;
esac

: "${APP_KEY:?Set the shared Laravel APP_KEY in Railway variables.}"
mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private

# Runtime variables are injected by Railway, so never cache configuration during image builds.
php artisan config:cache --no-interaction

case "$role" in
    api)
        exec php artisan octane:start --server=swoole --host=0.0.0.0 --port="${PORT:-8080}" \
            --workers="${OCTANE_WORKERS:-2}" --task-workers="${OCTANE_TASK_WORKERS:-0}" \
            --max-requests="${OCTANE_MAX_REQUESTS:-500}" --no-interaction
        ;;
    general|processing|staging-workers)
        exec /usr/bin/supervisord -n -c "docker/supervisor/$role.conf"
        ;;
    scheduler)
        exec php artisan schedule:work --no-interaction
        ;;
    ingestion|transcriptions|exports)
        exec php artisan queue:work redis --queue="$role" --sleep=3 --tries=3 \
            --timeout=900 --memory="${WORKER_MEMORY:-512}" --max-time=86400 --no-interaction
        ;;
    subtitle)
        exec php artisan queue:work subtitle_rendering --queue=subtitle-rendering --sleep=3 --tries=3 \
            --timeout=7200 --memory="${WORKER_MEMORY:-512}" --max-time=86400 --no-interaction
        ;;
    dubbing)
        exec php artisan queue:work dubbing --queue=dubbing --sleep=3 --tries=3 \
            --timeout=5400 --memory="${WORKER_MEMORY:-512}" --max-time=86400 --no-interaction
        ;;
esac
