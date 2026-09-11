#!/usr/bin/env sh
set -e

php scripts/init.php

exec "$@"

