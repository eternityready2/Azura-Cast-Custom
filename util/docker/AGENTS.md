# DOCKER KNOWLEDGE BASE

## OVERVIEW
`util/docker/` contains the image-build and runtime layout for AzuraCast Custom. Services are assembled into one deployment image and coordinated through supervisor/startup scripts.

## STRUCTURE
```text
util/docker/
├── common/      # Shared base image prep and init scripts
├── dev/         # Development-only setup and services
├── mariadb/     # DB image/service setup
├── redis/       # Redis image/service setup
├── stations/    # Radio stack pieces such as Liquidsoap/Icecast helpers
├── supervisor/  # Supervisor config assembly
└── web/         # Main app runtime: nginx, php-fpm, cron, centrifugo, sftpgo
```

## WHERE TO LOOK
| Task | Location | Notes |
|------|----------|-------|
| Main container init | `common/scripts/`, `web/scripts/` | `my_init` and app startup orchestration matter most |
| Web stack config | `web/nginx/`, `web/php/`, `web/service.full/` | nginx/php-fpm/runtime service wiring |
| Supervisor assembly | `supervisor/` | Process model for full runtime |
| DB/Redis image setup | `mariadb/`, `redis/` | Service-specific bootstrapping |
| Station runtime | `stations/` | Radio daemon and stream-related setup |
| Dev-only changes | `dev/` | Local developer image differences |

## CONVENTIONS
- Each service directory tends to repeat the same internal vocabulary: `setup/`, `scripts/`, `startup_scripts/`, and sometimes service-specific config directories.
- The repo ships as one operational unit. Docker changes often affect backend/frontend assumptions indirectly.
- Root `Dockerfile` is the authoritative build graph; these directories are its implementation details.
- The main GitHub Actions workflow builds and publishes images only; it is not a full validation pipeline.

## ANTI-PATTERNS
- Do not assume CI catches runtime regressions in Docker/service wiring.
- Do not edit generated or copied runtime artifacts without tracing their source scripts/templates here.
- Do not change service startup order casually; `web/scripts/app_startup` coordinates dependencies like DB/Redis before full boot.
- Do not add more special cases to giant shell scripts if a shared helper pattern can absorb them.

## NOTES
- `docker.sh` at repo root is a large operational entrypoint and part of the real user workflow.
- The compose setup includes self-update behavior and privileged socket access patterns; review security impact before expanding them.
