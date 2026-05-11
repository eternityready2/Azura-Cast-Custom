#!/usr/bin/env bash
# Patch the file in-place to force Vite's watcher to recompile
sed -i 's/const valid = await r\$\.value\.\$validate();/const { valid } = await r\$.\$validate();/' \
  /var/azuracast/www/frontend/components/Stations/ClockWheels/EditModal.vue
echo "Patched. Current line:"
grep 'validate' /var/azuracast/www/frontend/components/Stations/ClockWheels/EditModal.vue
