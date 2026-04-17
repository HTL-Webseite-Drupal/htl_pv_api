# Settings Reference

All settings are managed at **Admin → Configuration → HTL → PV API**
(`/admin/config/htl/pv-api`).

They are stored in the Drupal config object `htl_pv_api.settings`.

---

## General

### API Base URL
**Config key:** `api_base_url`
**Default:** `http://mock-api:4010`

The root URL of the inverter or mock API. Must respond to `GET /pv/live`.

The HTTP client automatically tries the following fallbacks when `localhost` is used:
`host.docker.internal` → `172.17.0.1` → `mock-api`

---

### Live Endpoint Path
**Config key:** `live_endpoint_path`
**Default:** `/pv/live`

The relative path used for the live API request.

Change this when the production API does not expose its live endpoint at `/pv/live`.
Examples:

- `/solar/realtime`
- `/api/v1/inverter/current`
- `/plant/0/live`

---

### Browser-Polling Intervall
**Config key:** `poll_interval`
**Default:** `15` (seconds)

How often the dashboard page fetches a fresh live reading in the background and
updates the gauge value without a page reload.

Set to a high value (e.g. 60) to reduce server load in production.

---

### Max. PV-Leistung
**Config key:** `max_power_w`
**Default:** `10000` (Watts)

The gauge ring is 100% full when `power_w` equals this value.
Set it to your inverter's peak output (e.g. 9000 for a 9 kW system).

---

## Cron

### Cron aktiviert
**Config key:** `cron_enabled`
**Default:** `false`

Master switch for automatic data collection. When off, neither Drupal cron nor the
dedicated HTTP cron endpoint will fetch new data (they return early immediately).

---

### Cron-Intervall
**Config key:** `cron_interval`
**Default:** `60` (seconds)

Minimum time between cron fetches. Even if cron runs every minute, this setting
acts as a debounce so the API is not hammered.

Minimum enforced in code: 10 seconds.

---

### Datenhaltung
**Config key:** `data_retention_days`
**Default:** `30` (days)

Samples older than this are automatically deleted during each cron run.
Set to `0` to keep all data forever.

---

## Diagramm

### Diagramm-Intervall
**Config key:** `chart_interval_minutes`
**Default:** `15` (minutes), range 1–15

Controls the granularity of the **day** chart slot size.

| Interval | Slots per day | Use case |
|---|---|---|
| 1 min | 1440 | Fine-grained, needs dense data |
| 5 min | 288 | Good balance |
| 15 min | 96 | Default, fine for hourly patterns |

For week/month/year charts the slot size is derived from the full time range,
not from this setting.

---

## Feld-Mapping

This section maps the module's 4 internal fields to your API's actual JSON response.
Changes here affect the entire module — no code changes needed.

### Zeitstempel-Schlüssel
**Config key:** `field_map.timestamp_key`
**Default:** `timestamp`

The JSON key or path in the API response that holds the sample time.

Supported examples:

- `timestamp`
- `data.timestamp`
- `results[0].measured_at`

The module accepts ISO 8601 strings and Unix timestamps.

---

### Per-field settings

Each of the 4 data fields has the same 5 sub-settings:

| Sub-setting | Config key suffix | Description |
|---|---|---|
| API-Schlüssel | `.api_key` | Exact JSON key or dot path in the API response |
| Anzeige-Name | `.label` | Label shown in dashboard and block |
| Einheit | `.unit` | Unit string appended to values (`kW`, `%`, etc.) |
| Skalierungsfaktor | `.scale` | Raw value × scale = displayed value |
| Aktiviert | `.enabled` | If unchecked, field is not fetched or stored |

---

### Fields

#### power_w — PV Generation
| Setting | Default |
|---|---|
| api_key | `pv_power_w` |
| label | `PV-Erzeugung` |
| unit | `kW` |
| scale | `0.001` (W → kW) |
| enabled | yes |

The primary field. Drives the gauge ring and all chart data.

---

#### grid_power_w — Grid Power
| Setting | Default |
|---|---|
| api_key | `grid_power_w` |
| label | `Netzleistung` |
| unit | `kW` |
| scale | `0.001` |
| enabled | yes |

Negative = exporting to the grid. Positive = importing from the grid.

---

#### house_consumption_w — House Consumption
| Setting | Default |
|---|---|
| api_key | `house_consumption_w` |
| label | `Hausverbrauch` |
| unit | `kW` |
| scale | `0.001` |
| enabled | yes |

---

#### energy_wh_total — Total Energy Today
| Setting | Default |
|---|---|
| api_key | `energy_wh_total` |
| label | `Energie gesamt` |
| unit | `kWh` |
| scale | `0.001` |
| enabled | no |

Cumulative energy produced since midnight. Many inverters expose this separately.
Disabled by default because the mock API does not provide it.

---

## PV API Inspector

Route: `/admin/config/htl/pv-api/inspector`

Use this page when the real inverter API becomes available.

It shows:

- the raw JSON payload returned by the current live endpoint
- every detected scalar JSON path
- a preview of the current mapping and whether each configured path resolves

Recommended workflow:

1. Set **API Base URL** and **Live Endpoint Path**
2. Open **PV API Inspector**
3. Copy the detected path for each needed value
4. Save the matching `field_map` entries
5. Use **Jetzt Daten abrufen** to confirm the mapping works

---

## CSS-Klassen (optional)

Five optional CSS class strings let you override the visual style without touching the
template files. Leave blank to use the default stylesheet.

| Setting | Config key | Applies to |
|---|---|---|
| Card-Hintergrund | `css_card_bg` | Outer card `div` |
| Gauge-Bogen | `css_gauge_arc` | SVG arc element |
| Gauge-Label | `css_gauge_label` | Text label below gauge |
| Leistungs-Zahl | `css_value_number` | SVG power number |
| Einheits-Text | `css_value_unit` | SVG unit text |

Example: add a Tailwind class like `bg-gray-900` or a custom class from your theme.
