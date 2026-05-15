# BACKEND KNOWLEDGE BASE

## OVERVIEW
`backend/src` contains the PHP application: Slim bootstrap seams, Doctrine entities, console commands, HTTP controllers, radio/media logic, and background tasks.

## STRUCTURE
```text
backend/src/
├── Controller/     # HTTP actions grouped by API/public/admin/station scope
├── Entity/         # Doctrine entities + API DTOs
├── Radio/          # Liquidsoap, AutoDJ, streaming backends/frontends
├── Media/          # Metadata, album art, media processing
├── Sync/           # Background tasks + now playing sync
├── Service/        # Infra services (mail, vite, geoip, AI news, etc.)
├── Console/        # Symfony Console commands
├── Event/          # Extension seams for routes, CLI, mappings, notifications
└── Tests/          # Codeception module + BrowserKit connector
```

## WHERE TO LOOK
| Task | Location | Notes |
|------|----------|-------|
| Add route behavior | `Controller/...` + `backend/config/routes/*.php` | Route files wire groups; actions stay single-purpose |
| Add service wiring | `backend/config/services.php` | Prefer DI definitions over ad-hoc factories |
| Extend console | `Console/Command/` + `backend/config/cli.php` | Commands register via `BuildConsoleCommands` |
| Add plugin seam | `Event/` | Many extension points dispatch events before final assembly |
| Change station API | `Controller/Api/Stations/` | Nested by resource/sub-resource |
| Change persistence | `Entity/`, `Entity/Repository/` | Doctrine attributes, repositories, custom queries |
| Change radio behavior | `Radio/` | AutoDJ + Liquidsoap are complexity hotspots |
| Change async work | `Sync/Task/`, `Message/`, `MessageQueue/` | Background and queue-driven behavior |

## CONVENTIONS
- Use `declare(strict_types=1);` and PSR-12 style; project tooling adds Slevomat rules and strict typing expectations.
- Keep controller actions narrow. Route grouping lives in config; behavior lives in action classes.
- Respect the nested REST tree. Deep paths like `Stations/Podcasts/Episodes/Art` are intentional and mirror HTTP structure.
- Prefer constructor injection through PHP-DI. Shared infrastructure comes from `backend/config/services.php`.
- Doctrine metadata is attribute-driven. Mapping roots begin at `backend/src/Entity` and can be extended by events/plugins.
- Cache, lock, and DB backends switch by environment; testing often uses in-memory adapters.
- Traits and enums are distributed per module. Before adding a new shared one, check whether it belongs inside a domain-local `Traits/` or `Enums/` directory.

## ANTI-PATTERNS
- Do not bypass entity encapsulation further; many entities already carry `TODO Remove direct identifier access` markers.
- Do not add more undocumented API endpoints without response schema work; many controllers already have `TODO API Response Body` debt.
- Do not edit generated Liquidsoap output directly; change the generator in `Radio/Backend/Liquidsoap/ConfigWriter.php`.
- Do not keep managed entities alive across loops that clear the entity manager.
- Do not put more business logic into already oversized classes when extraction is feasible.

## HOTSPOTS
- `Sync/Task/ImportPodcastFeedsTask.php` — very large sync workflow.
- `Radio/Backend/Liquidsoap/ConfigWriter.php` — very large config generator.
- `Entity/Station.php`, `Entity/Settings.php` — large entities with broad reach.
- `Controller/Api/Stations/` — deepest controller subtree and best candidate for careful consistency checks.

## TESTING
- Tests are Codeception-based under `tests/`; backend changes usually validate through `composer run dev-test` and `composer run codeception`.
- Functional tests boot the real Slim app through `App\Tests\Module`; keep DI-friendly code paths intact.
