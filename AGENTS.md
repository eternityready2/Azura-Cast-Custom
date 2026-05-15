# PROJECT KNOWLEDGE BASE

**Generated:** 2025-02-14
**Commit:** e83ba97
**Branch:** main

## OVERVIEW
AzuraCast Custom is a single-deployment monorepo for a self-hosted web radio platform. The stack is PHP 8.4+/Slim 4/Doctrine on the backend, Vue 3/Vite/TypeScript on the frontend, with Docker orchestrating the full runtime.

## STRUCTURE
```text
./
├── backend/       # PHP app: config, templates, CLI, domain code
├── frontend/      # Vue app: components, composables, TS entities, Vite inputs
├── tests/         # Codeception unit + functional tests
├── util/docker/   # Image build + runtime service scripts
├── web/           # HTTP entrypoint + built static assets
├── translations/  # gettext catalogs shared across backend + frontend
└── plugins/       # Composer-merge plugin packages; currently empty
```

## WHERE TO LOOK
| Task | Location | Notes |
|------|----------|-------|
| HTTP entrypoint | `web/index.php` | Boots app via `App\AppFactory::createApp()` |
| Route registration | `backend/config/routes.php` | Loads grouped route files under `backend/config/routes/` |
| DI and infrastructure | `backend/config/services.php` | PHP-DI definitions for DB, cache, lock, HTTP, events |
| CLI commands | `backend/bin/console`, `backend/config/cli.php` | Symfony Console app + command registration |
| Station API | `backend/src/Controller/Api/Stations/` | Deepest controller tree; nested by resource/sub-resource |
| Frontend pages | `frontend/js/pages/` | Vite discovers each page entry automatically |
| Shared Vue UI | `frontend/components/Common/` | Reusable tables, forms, modals, inputs |
| Frontend composables | `frontend/functions/` | Shared `use*` helpers and data providers |
| API types | `frontend/entities/ApiInterfaces.ts` | Large generated-ish TS API surface |
| Tests | `tests/` | Codeception Unit + Functional only; no JS test suite |
| Docker runtime | `util/docker/` | Service-specific setup, startup, supervisor config |

## CODE MAP
| Symbol | Type | Location | Refs | Role |
|--------|------|----------|------|------|
| `AppFactory::createApp` | factory | `backend/src/AppFactory.php` | high | Main HTTP bootstrap |
| `AppFactory::createCli` | factory | `backend/src/AppFactory.php` | high | Console bootstrap |
| `BuildRoutes` | event | `backend/src/Event/BuildRoutes.php` | high | Route extension seam for core + plugins |
| `BuildConsoleCommands` | event | `backend/src/Event/BuildConsoleCommands.php` | med | Console extension seam |
| `DecoratedEntityManager` | service | `backend/config/services.php` | med | Doctrine wrapper with restart/audit hooks |
| `frontend/js/layout.js` | entry | `frontend/js/layout.js` | med | Shared frontend shell/bootstrap |
| `frontend/components/Common/DataTable.vue` | component | `frontend/components/Common/DataTable.vue` | high | Reused table abstraction |
| `ImportPodcastFeedsTask` | task | `backend/src/Sync/Task/ImportPodcastFeedsTask.php` | med | Largest background task hotspot |
| `ConfigWriter` | service | `backend/src/Radio/Backend/Liquidsoap/ConfigWriter.php` | med | Large Liquidsoap config generator |

## CONVENTIONS
- Repo is one product, not multiple deployable packages. `backend/`, `frontend/`, and `util/docker/` are documentation boundaries, not independent apps.
- Backend source lives under `backend/`, but production Docker symlinks `bin/`, `src/`, `config/`, and `templates/` back to root-level paths.
- Frontend Vite root is `frontend/`, but the config file and package manifest stay at repository root.
- Each file in `frontend/js/pages/` becomes a separate Vite entry; this is a multi-page app, not one `App.vue` SPA shell.
- Plugins are wired through Composer merge-plugin at `plugins/*/composer.json`; directory is empty today, but extension seams exist.

## ANTI-PATTERNS (THIS PROJECT)
- Do not edit generated Liquidsoap config output directly; generator comments say it will be overwritten.
- Do not change installed DB connection values casually; sample env warns these are fixed after install.
- Do not assume CI runs tests; current GitHub Actions build only pushes container images.
- Do not hold Doctrine entities across batch iterators that explicitly clear the entity manager.
- Do not introduce more god-files in already hot areas such as `AiNews.vue`, `ImportPodcastFeedsTask.php`, or `ConfigWriter.php`.

## UNIQUE STYLES
- REST controllers mirror resource nesting literally: `Stations/Podcasts/Episodes/Art`, `Stations/Mounts/Intro`, `Stations/Reports/Overview`.
- PHP DTO trees use `Entity/Api/.../Vue` subdirectories for frontend-specific response shapes.
- Frontend commonly uses `Form/` and `Form/Common/` subtrees instead of colocating every field with page-level containers.
- Tests are PHP-only and rely on a custom Codeception module booting the Slim app in-process.

## COMMANDS
```bash
npm run build
npm run lint
npm run tsc
composer run dev-test
composer run cleanup-and-test
composer run codeception
make up
make test
```

## NOTES
- Large files cluster in `backend/src/Sync/Task`, `backend/src/Radio`, `backend/src/Entity`, and `frontend/components/Stations`.
- `backend/bin/console` uses a Docker-oriented autoload path; prefer running it inside the containerized environment.
- `web/static/vite_dist/` is build output; avoid treating it as source.

<!-- gitnexus:start -->
# GitNexus — Code Intelligence

This project is indexed by GitNexus as **Azura-Cast-Custom** (15132 symbols, 38873 relationships, 300 execution flows). Use the GitNexus MCP tools to understand code, assess impact, and navigate safely.

> If any GitNexus tool warns the index is stale, run `npx gitnexus analyze` in terminal first.

## Always Do

- **MUST run impact analysis before editing any symbol.** Before modifying a function, class, or method, run `gitnexus_impact({target: "symbolName", direction: "upstream"})` and report the blast radius (direct callers, affected processes, risk level) to the user.
- **MUST run `gitnexus_detect_changes()` before committing** to verify your changes only affect expected symbols and execution flows.
- **MUST warn the user** if impact analysis returns HIGH or CRITICAL risk before proceeding with edits.
- When exploring unfamiliar code, use `gitnexus_query({query: "concept"})` to find execution flows instead of grepping. It returns process-grouped results ranked by relevance.
- When you need full context on a specific symbol — callers, callees, which execution flows it participates in — use `gitnexus_context({name: "symbolName"})`.

## Never Do

- NEVER edit a function, class, or method without first running `gitnexus_impact` on it.
- NEVER ignore HIGH or CRITICAL risk warnings from impact analysis.
- NEVER rename symbols with find-and-replace — use `gitnexus_rename` which understands the call graph.
- NEVER commit changes without running `gitnexus_detect_changes()` to check affected scope.

## Resources

| Resource | Use for |
|----------|---------|
| `gitnexus://repo/Azura-Cast-Custom/context` | Codebase overview, check index freshness |
| `gitnexus://repo/Azura-Cast-Custom/clusters` | All functional areas |
| `gitnexus://repo/Azura-Cast-Custom/processes` | All execution flows |
| `gitnexus://repo/Azura-Cast-Custom/process/{name}` | Step-by-step execution trace |

## CLI

| Task | Read this skill file |
|------|---------------------|
| Understand architecture / "How does X work?" | `.claude/skills/gitnexus/gitnexus-exploring/SKILL.md` |
| Blast radius / "What breaks if I change X?" | `.claude/skills/gitnexus/gitnexus-impact-analysis/SKILL.md` |
| Trace bugs / "Why is X failing?" | `.claude/skills/gitnexus/gitnexus-debugging/SKILL.md` |
| Rename / extract / split / refactor | `.claude/skills/gitnexus/gitnexus-refactoring/SKILL.md` |
| Tools, resources, schema reference | `.claude/skills/gitnexus/gitnexus-guide/SKILL.md` |
| Index, status, clean, wiki CLI commands | `.claude/skills/gitnexus/gitnexus-cli/SKILL.md` |

<!-- gitnexus:end -->
