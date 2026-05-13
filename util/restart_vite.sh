#!/usr/bin/env bash
# Kill all Vite/esbuild processes
for PID in $(ps aux | grep -E 'vite|esbuild' | grep -v grep | awk '{print $2}'); do
    kill -9 $PID 2>/dev/null && echo "Killed PID $PID"
done
sleep 1

# Clear ALL Vite caches
rm -rf /var/azuracast/www/node_modules/.vite
rm -f /tmp/vite.log
echo "Cache cleared"

# Start Vite fresh
cd /var/azuracast/www
nohup node ./node_modules/vite/bin/vite.js > /tmp/vite.log 2>&1 &
echo "New Vite PID: $!"
sleep 5
echo "--- Vite started ---"
cat /tmp/vite.log
