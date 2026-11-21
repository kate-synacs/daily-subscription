#!/bin/sh
set -e

cd /app
exec php -S 0.0.0.0:8080 -t public public/index.php
