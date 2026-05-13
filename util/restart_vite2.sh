#!/usr/bin/env bash
# Kill Vite
kill -9 $(ps aux | grep 'vite.js' | grep -v grep | awk '{print $2}') 2>/dev/null
kill -9 $(ps aux | grep 'esbuild' | grep -v grep | awk '{print $2}') 2>/dev/null
sleep 1

# Clear Vite dep cache
rm -rf /var/azuracast/www/node_modules/.vite
rm -f /tmp/vite.log
echo "Cache cleared"

# Start fresh
cd /var/azuracast/www
nohup node ./node_modules/vite/bin/vite.js > /tmp/vite.log 2>&1 &
NEW_PID=$!
echo "New Vite PID: $NEW_PID"
sleep 5
cat /tmp/vite.log
