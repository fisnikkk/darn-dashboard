#!/bin/sh
echo "Starting PHP server on port $PORT"
php -d upload_max_filesize=64M -d post_max_size=65M -d memory_limit=512M -d max_execution_time=300 -S 0.0.0.0:$PORT -t /app
