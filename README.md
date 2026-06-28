# nightowl/agent-simulator

Synthetic [Nightwatch](https://github.com/laravel/nightwatch)-shaped telemetry generator and feeder
commands for the **NightOwl Tier‑1 demo** and the agent's own pipeline tests.

> ⚠️ **Not a customer dependency.** This package is intentionally **not** installed into production
> `nightowl/agent` installs. It ships only as a `require-dev` of the agent (for its Integration /
> System tests) and a `require` of the demo app — so a synthetic‑telemetry generator never lands in
> someone's real monitoring agent.

## What's inside

- **`NightwatchSimulator`** — generates realistic `laravel/nightwatch`-shaped payloads from captured
  fixtures and frames them onto a local agent's TCP ingest, so a demo dashboard fills with faithful,
  connected traces (the two‑row job dispatch/attempt model, fingerprint‑derived issues, rollups).
- **`nightowl:simulator-loop`** — continuous real‑time feeder (the live‑demo feed).
- **`nightowl:simulator-backfill`** — backdated one‑shot seed that primes the dashboard's wide
  default range immediately.

Both commands are gated behind `NIGHTOWL_SIMULATOR_ENABLED` (default **off**), so even when the
package is present nothing runs unless explicitly enabled.

## Install (internal)

```jsonc
// composer.json (agent: require-dev / demo app: require)
"repositories": [{ "type": "path", "url": "../nightowl-agent-simulator" }],
"require-dev": { "nightowl/agent-simulator": "@dev" }
```

## License

MIT
