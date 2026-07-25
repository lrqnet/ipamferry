#!/bin/sh
set -eu
exec php artisan queue:work database --sleep=2 --tries=3 --timeout=300
