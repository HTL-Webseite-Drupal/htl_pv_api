# htl_pv_api — PV Dashboard (Drupal 10/11)

A Drupal module that fetches photovoltaic (PV) data from an inverter API,
stores it in a local database, and displays it in a Fronius-inspired dashboard
with a live gauge and an interactive chart.

---

## Features

- **Live gauge block** — circular SVG power gauge, placeable anywhere via Block Layout
- **Full dashboard** at `/pvoutput/view` — live gauge, daily energy summary, interactive
  chart with day / week / month / year navigation and prev/next date browsing
- **Browser polling** — gauge updates automatically in the background (configurable interval)
- **Cron data collection** — built-in Drupal cron + dedicated HTTP cron endpoint
- **Centralized field mapping** — change every API key, display label, unit and scale factor
  from one admin page; nothing else in the code needs to change
- **Nested JSON path support** — mapping also works for payloads like `data.live.power`
  or `results[0].value`
- **Payload inspector** — admin page that shows the raw JSON response and every detected
  scalar path for copy/paste into the mapping form
- **Data retention** — automatic cleanup of old samples
- **Docker-aware HTTP client** — auto-tries `host.docker.internal`, `172.17.0.1` and
  `mock-api` if `localhost` fails (useful in containerized setups)

---

## Installation

```bash
drush en htl_pv_api -y
drush cr
```

Or enable via **Admin -> Extend**.

---

## Configuration

All settings live at **Admin -> Configuration -> HTL -> PV API**
(`/admin/config/htl/pv-api`).

For unknown production payloads, open **PV API Inspector**
(`Admin -> Configuration -> HTL -> PV API Inspector`) to inspect the live JSON and copy
the correct paths into the mapping fields.

| Setting | Description |
|---|---|
| API Base URL | URL of the inverter / mock API (`http://mock-api:4010`) |
| Browser-Polling Intervall | Seconds between live gauge refreshes |
| Cron aktiviert | Enable/disable automatic data collection via cron |
| Cron-Intervall | Minimum seconds between cron fetches |
| Datenhaltung | Delete samples older than N days (0 = keep forever) |
| Max. PV-Leistung | Gauge maximum in Watts |
| Diagramm-Intervall | Day-chart aggregation granularity (1-15 min) |
| Feld-Mapping | Map each API JSON key to a label, unit and scale factor |

### Feld-Mapping (Field Mapping)

The **Feld-Mapping** section is the single place to adapt the module to any API.
For each data field you can configure:

- **API-Schluessel** — the exact JSON key the API returns (e.g. `PAC`, `pv_power_w`)
- **Anzeige-Name** — label shown in the dashboard
- **Einheit** — display unit (`kW`, `%`, `kWh`)
- **Skalierungsfaktor** — multiplier applied at display time (e.g. `0.001` for W->kW)
- **Aktiviert** — whether to fetch and store this field
- **Zeitstempel-Schluessel** — JSON key that holds the sample timestamp

See `docs/production-api-migration.md` for a step-by-step guide for switching
to a production API.

---

## Block

Place **PV Live Block** via **Admin -> Structure -> Block Layout**.
The block shows the circular live gauge with label and unit from the field mapping.

---

## Dashboard

Visit `/pvoutput/view`.

- **Gauge** — current PV power, auto-refreshes via polling
- **Energy summary** — today's produced / consumed / exported kWh
- **Chart** — day / week / month / year tabs with prev/next date navigation
  - Gaps caused by API downtime are bridged with a straight connecting line
  - Day chart resolution is controlled by **Diagramm-Intervall** in settings

---

## API Endpoints

| Method | Path | Description |
|---|---|---|
| `GET` | `/pvoutput/view` | Dashboard page |
| `GET` | `/pvoutput/fetch` | Live fetch + JSON response (used by polling) |
| `GET` | `/pvoutput/chart-data?period=day&date=YYYY-MM-DD` | Chart data JSON |
| `GET` | `/pvoutput/cron/{key}` | Dedicated cron endpoint (use in system crontab) |

### Dedicated cron endpoint

After install, retrieve the cron key:

```bash
drush state:get htl_pv_api.cron_key
```

Add to system crontab:

```
* * * * * wget -qO- https://yoursite.com/pvoutput/cron/YOUR_KEY
```

---

## Mock API (Node.js)

The module was developed against a Node.js mock API. Expected endpoints:

```
GET /pv/live
-> { "timestamp": "...", "pv_power_w": 3200, "grid_power_w": -100, "house_consumption_w": 900 }
```

The actual JSON keys are fully configurable via **Feld-Mapping** -- no code changes needed.

### Docker networking

If Drupal runs in Docker and the mock API is on the host, the client automatically
tries these fallbacks in order:

1. Configured base URL (e.g. `http://localhost:4010`)
2. `http://host.docker.internal:4010`
3. `http://172.17.0.1:4010`
4. `http://mock-api:4010`

---

## Database

Table: `htl_pv_sample`

| Column | Type | Description |
|---|---|---|
| `id` | serial PK | Auto-increment |
| `sampled_at` | varchar(19) | UTC timestamp `YYYY-MM-DD HH:MM:SS` |
| `power_w` | float | PV generation (raw Watts) |
| `grid_power_w` | float | Grid power (raw Watts) |
| `house_consumption_w` | float | House consumption (raw Watts) |
| `energy_wh_total` | float | Total energy today (raw Wh) |

Existing installations: run `drush updb` to apply the `htl_pv_api_update_10001`
update hook that adds the extra columns.

---

## File Structure

```
src/
  Controller/PVController.php     Dashboard page, fetch, chart data, cron endpoints
  Form/PVSettingsForm.php         Admin settings form
  Model/PVSample.php              Data transfer object (DTO)
  Plugin/Block/PVLiveBlock.php    Live gauge block
  Service/PVClient.php            HTTP client with Docker fallback
  Service/PVFieldMap.php          Central field mapping service
  Service/PVStore.php             Database persistence layer
config/
  install/htl_pv_api.settings.yml   Default config (used on first install)
  schema/htl_pv_api.schema.yml      Config schema
templates/
  pv-dashboard.html.twig          Full dashboard template
  pv-live-block.html.twig         Block template
css/pv-ui.css                     Fronius-inspired styles
js/pv-dashboard-new.js            Chart.js init + polling
js/chart.umd.min.js               Bundled Chart.js (local, no CDN)
docs/production-api-migration.md  Guide for switching to a real inverter API
```

---

## Development

```bash
# Clear caches after PHP/config changes
drush cr

# Apply DB updates after install file changes
drush updb

# Check logs
drush watchdog:show --type=htl_pv_api
```

---

## Documentation

| File | Description |
|---|---|
| `docs/mock-api.md` | How to run the Node.js mock API, how the simulation works, Docker setup |
| `docs/architecture.md` | Data flow, service map, DB schema, chart pipeline, cron details |
| `docs/settings-reference.md` | Every setting explained with defaults and valid values |
| `docs/cron-setup.md` | Setting up Drupal cron vs. dedicated HTTP cron endpoint |
| `docs/production-api-migration.md` | Step-by-step guide for switching to a real inverter API |
