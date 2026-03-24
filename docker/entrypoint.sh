#!/usr/bin/env sh
set -eu

APP_DIR="/var/www/html"
STORAGE_DIR="$APP_DIR/storage"
DB_WAIT_MAX_ATTEMPTS="${DB_WAIT_MAX_ATTEMPTS:-30}"
DB_WAIT_SLEEP_SECONDS="${DB_WAIT_SLEEP_SECONDS:-2}"

mkdir -p "$STORAGE_DIR/imports" "$STORAGE_DIR/portal-documents"
chown -R www-data:www-data "$STORAGE_DIR"
chmod -R ug+rwX "$STORAGE_DIR"

if [ "${WAIT_FOR_DB:-1}" = "1" ]; then
    attempt=1

    while [ "$attempt" -le "$DB_WAIT_MAX_ATTEMPTS" ]; do
        if php -r '
$host = getenv("DB_HOST") ?: "db";
$port = getenv("DB_PORT") ?: "3306";
$db = getenv("DB_NAME") ?: "";
$user = getenv("DB_USER") ?: "root";
$pass = getenv("DB_PASS") ?: "";

if ($db === "") {
    fwrite(STDERR, "DB_NAME is empty.\n");
    exit(1);
}

try {
    new PDO(
        "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_TIMEOUT => 3]
    );
    exit(0);
} catch (Throwable $exception) {
    exit(1);
}
'; then
            echo "Database is ready."
            break
        fi

        if [ "$attempt" -eq "$DB_WAIT_MAX_ATTEMPTS" ]; then
            echo "Database unavailable after ${DB_WAIT_MAX_ATTEMPTS} attempts." >&2
            exit 1
        fi

        echo "Waiting for database (${attempt}/${DB_WAIT_MAX_ATTEMPTS})..."
        attempt=$((attempt + 1))
        sleep "$DB_WAIT_SLEEP_SECONDS"
    done
fi

exec "$@"
