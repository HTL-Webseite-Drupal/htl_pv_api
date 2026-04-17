# Architecture Overview

## Data Flow

```
[Inverter API / Node.js mock]
         |
         | HTTP GET /pv/live
         v
    PVClient.php          -- HTTP client, Docker-aware fallback
         |
         | PVSample DTO
         v
    PVStore.php           -- DB layer (htl_pv_sample table)
         |
         +---> PVController::dashboard()   -- full page render
         |          |
         |          v
         |     pv-dashboard.html.twig      -- gauge + chart + summary
         |
         +---> PVLiveBlock::build()        -- Drupal block
                    |
                    v
              pv-live-block.html.twig      -- circular gauge only
```

Browser polling (`/pvoutput/fetch`) hits `PVController::fetch()` which re-runs
the PVClient → PVStore pipeline and returns JSON. The JS updates the gauge in place.

---

## Services

| Service ID | Class | Description |
|---|---|---|
| `htl_pv_api.client` | `PVClient` | HTTP client. Takes base URL from config. Falls back through multiple host candidates when running in Docker. |
| `htl_pv_api.store` | `PVStore` | All DB reads/writes. Wraps Drupal's database API. |
| `htl_pv_api.field_map` | `PVFieldMap` | Single source of truth for API key names, display labels, units and scale factors. Reads from `htl_pv_api.settings`. |

---

## PVFieldMap — Central Field Registry

`PVFieldMap` is the key abstraction that decouples the API shape from the rest of the code.

```
htl_pv_api.settings (config)
       |
       v
  PVFieldMap::__construct()
       |
       |  merges saved overrides onto hard-coded DEFAULTS
       v
  $this->map  [ field_name => { api_key, label, unit, scale, enabled } ]
       |
       +---> PVClient::fetchLive()       uses getTimestampKey(), mapApiResponse()
       +---> PVSettingsForm              reads/writes via getFieldDefinitions()
       +---> PVController::dashboard()   passes field_map array to template
       +---> PVLiveBlock::build()        passes field_map array to template
       +---> Twig templates              {{ field_map.power_w.label }} etc.
       +---> drupalSettings.htl_pv_api.field_map  (for JS polling)
```

Raw API values are stored **unscaled** (Watts) in the database.
The `scale` factor is applied only at display time in templates and JS.
This keeps the stored data accurate regardless of how the display unit changes.

---

## DB Table: htl_pv_sample

```sql
CREATE TABLE htl_pv_sample (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sampled_at          VARCHAR(19) NOT NULL,   -- 'YYYY-MM-DD HH:MM:SS' UTC
  power_w             FLOAT,
  grid_power_w        FLOAT,
  house_consumption_w FLOAT,
  energy_wh_total     FLOAT,
  UNIQUE KEY (sampled_at),
  INDEX (sampled_at)
);
```

`PVStore::upsert()` uses `INSERT ... ON DUPLICATE KEY UPDATE` so re-running the same
timestamp is safe (idempotent).

---

## HTTP Endpoints

| Route | Method | Handler | Auth |
|---|---|---|---|
| `/pvoutput/view` | GET | `PVController::dashboard` | public |
| `/pvoutput/fetch` | GET | `PVController::fetch` | public |
| `/pvoutput/chart-data` | GET | `PVController::chartData` | public |
| `/pvoutput/cron/{key}` | GET | `PVController::cron` | key in URL |
| `/admin/config/htl/pv-api` | GET/POST | `PVSettingsForm` | Administrator htl settings |
| `/admin/config/htl/pv-api/inspector` | GET | `PVInspectorController::overview` | Administrator htl settings |

---

## Chart Data Pipeline

```
Browser click (tab / nav)
      |
      | fetch /pvoutput/chart-data?period=day&date=2025-04-10
      v
PVController::chartData()
      |
      | PVStore::history($from, $to)  — raw samples
      |
      v
aggregateChartData()
      |   buckets samples into N slots (slot size = range / targetPoints)
      |   averages power_w per slot
      |   null for empty slots (gaps bridged by spanGaps:true in Chart.js)
      v
JSON { labels[], data[], peak, count }
      |
      v
pv-dashboard-new.js :: updateChart()
      |   sets chart.data.labels + chart.data.datasets[0].data
      |   chart.update("none")
      v
Chart.js renders line with spanGaps:true
      (straight line drawn between data points across null gaps)
```

---

## Cron

Two cron paths exist:

1. **Drupal cron** (`htl_pv_api_cron()` in `htl_pv_api.module`)
   - Called by Drupal's own cron run (`drush cron` or the UI)
   - Gated by a flood-control lock (120 s timeout) and `cron_interval` setting
   - Fetches live data, upserts to DB, runs retention cleanup

2. **Dedicated HTTP cron** (`/pvoutput/cron/{key}`)
   - Callable from system crontab with `wget` or `curl`
   - Secured by a random key stored in Drupal state (`htl_pv_api.cron_key`)
   - Same fetch + upsert + cleanup logic
   - Returns JSON so you can log it

Both paths share the same `cron_enabled` / `cron_interval` guards.

---

## Config Object: htl_pv_api.settings

All persistent settings live in one config object. Key structure:

```yaml
api_base_url: "http://mock-api:4010"
live_endpoint_path: "/pv/live"
poll_interval: 15
cron_enabled: false
cron_interval: 60
data_retention_days: 30
max_power_w: 10000
chart_interval_minutes: 15
field_map:
  timestamp_key: "timestamp"
  fields:
    power_w:         { api_key, label, unit, scale, enabled }
    grid_power_w:    { ... }
    house_consumption_w: { ... }
    energy_wh_total: { ... }
```

Defaults are defined in two places:
- `config/install/htl_pv_api.settings.yml` — used on first install
- `PVFieldMap::DEFAULTS` — hard-coded PHP fallback for when no config has been saved

The mapping supports dot notation and array indexes, so a production payload can be
repointed without code changes in many cases.
