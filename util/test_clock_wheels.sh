#!/usr/bin/env bash
API_KEY="testclockdev1:testclockdev1_secret256"
BASE="http://localhost"

echo "=== POST: Create 'Morning Drive' clock wheel ==="
RESP=$(curl -s -X POST \
  -H "X-API-Key: $API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"name":"Morning Drive","color":"#3498db","is_active":true,"slots":[{"type":"music","algorithm":"random"},{"type":"ad","algorithm":"random"},{"type":"id","algorithm":"random"}]}' \
  "$BASE/api/station/2/clock-wheels")
echo "$RESP"
NEW_ID=$(echo "$RESP" | php -r 'echo json_decode(stream_get_contents(STDIN))->id;')
echo ""
echo "=== GET: Clock wheel $NEW_ID ==="
curl -s -H "X-API-Key: $API_KEY" "$BASE/api/station/2/clock-wheel/$NEW_ID"
echo ""
echo "=== PUT slots on wheel $NEW_ID ==="
curl -s -X PUT \
  -H "X-API-Key: $API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"slots":[{"type":"music","algorithm":"oldest_track"},{"type":"promo","algorithm":"random"},{"type":"music","algorithm":"random"}]}' \
  "$BASE/api/station/2/clock-wheel/$NEW_ID/slots"
echo ""
echo "=== GET: Verify slots updated ==="
curl -s -H "X-API-Key: $API_KEY" "$BASE/api/station/2/clock-wheel/$NEW_ID"
echo ""
echo "=== DELETE: Remove test wheel $NEW_ID ==="
curl -s -X DELETE -H "X-API-Key: $API_KEY" "$BASE/api/station/2/clock-wheel/$NEW_ID"
echo ""
echo "=== GET: List all wheels (should not include $NEW_ID) ==="
curl -s -H "X-API-Key: $API_KEY" "$BASE/api/station/2/clock-wheels"
