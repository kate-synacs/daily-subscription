#!/bin/sh
set -e

cd /app

# Wait for DB and apply migrations (retry loop inside migrate.php, no fixed sleep guess)
php /app/bin/migrate.php

exec php -S 0.0.0.0:8080 -t public public/index.php
