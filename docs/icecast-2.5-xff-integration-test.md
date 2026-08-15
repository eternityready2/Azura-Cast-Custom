# Icecast 2.5 reverse-proxy integration test

This procedure validates the listener path `client -> nginx -> Icecast -> AzuraCast statistics` after rebuilding the
AzuraCast image.

## Preconditions

- Use a disposable development or pre-production station.
- Keep the direct Icecast port closed to the Internet unless the direct-port spoof test is being run from an allowed
  address.
- Know the station short name, mount name and generated Icecast port.

If an external reverse proxy connects directly to the Icecast port, set its address in **Edit Station → Broadcasting →
Advanced Configuration → Trusted Proxy Address** before restarting the station. Leave this field blank when listeners
reach Icecast through AzuraCast's built-in nginx proxy.

## Runtime and configuration

```bash
docker compose exec web /usr/local/bin/icecast --version
docker compose exec web grep -A8 -B1 '<listen-socket' \
  /var/azuracast/stations/STATION/config/icecast.xml
```

Expected results:

- The binary reports Icecast `2.5.0`.
- The public socket contains `<trusted-proxy>#azuracast-proxy</trusted-proxy>`.
- The virtual `azuracast-proxy` socket contains the configured proxy address. It defaults to
  `<client-address>127.0.0.1</client-address>` for the built-in proxy.
- The station starts and its public HTTPS URL without `:port` plays normally.

## Distinct listener addresses

1. Connect client A through the public HTTPS URL from public network A.
2. Connect client B through the same URL from a different public network B.
3. Keep both connections open long enough for Now Playing synchronization.
4. Inspect the Icecast access log and AzuraCast listener report.

```bash
docker compose exec web tail -n 100 \
  /var/azuracast/stations/STATION/config/icecast_access.log
```

Expected result: the two public client addresses are distinct; `127.0.0.1` is not recorded as the address of both
listeners.

## Spoof resistance

From client A, send a forged header through the public proxy:

```bash
curl --max-time 10 \
  -H 'X-Forwarded-For: 203.0.113.77' \
  -o /dev/null \
  'https://PUBLIC_HOST/listen/STATION/MOUNT'
```

Expected result: Icecast records client A's real public address, not the documentation-only address `203.0.113.77`.
The trusted reverse proxy replaces or safely extends the XFF chain; Icecast accepts it only because the immediate peer
matches the configured proxy address.

If the direct Icecast port is intentionally exposed for the test, repeat the forged request against
`http://PUBLIC_HOST:ICECAST_PORT/MOUNT`. Expected result: the forged XFF value is ignored because a direct client is not
a trusted proxy.

## Radio regression checks

- AutoDJ plays and advances normally.
- A live source takes over and returns to AutoDJ after disconnecting.
- Explicit fallback mounts work.
- Every configured mount is reachable.
- Listener authentication and configured IP/country/User-Agent blocks still apply.
- Instant listener counts and listener history remain coherent after synchronization.
