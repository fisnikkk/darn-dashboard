#!/bin/sh
echo "Starting PHP server on port $PORT"
php -S 0.0.0.0:$PORT -t /app
