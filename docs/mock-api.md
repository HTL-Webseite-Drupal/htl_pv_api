# Mock API (Node.js/Express)

The `temp-api-files/` folder contains a small Node.js/Express server that simulates
a PV inverter API. It generates realistic-looking data based on time of day and season,
so you can develop and test the Drupal module without a real inverter.

---

## Files

| File | Description |
|---|---|
| `temp-api-files/server.js` | Express server — generates and serves live PV data |
| `temp-api-files/openapi.yaml` | OpenAPI 3.0 spec — describes the `/pv/live` endpoint and validates responses |

---

## Requirements

- Node.js >= 18
- npm packages: `express`, `express-openapi-validator`, `js-yaml`

Install dependencies (from inside `temp-api-files/`):

```bash
cd temp-api-files
npm install
```

---

## Starting the Server

```bash
node server.js
# or with a custom port:
PORT=4010 node server.js
```

The server listens on `0.0.0.0:4010` by default (all interfaces).

---

## Endpoint

### `GET /pv/live`

Returns the current simulated PV reading. Values are freshly generated on each request.

**Response example:**

```json
{
  "timestamp": "2025-04-10T10:30:00.000Z",
  "pv_power_w": 5832,
  "grid_power_w": -1240,
  "house_consumption_w": 1420
}
```

**Fields:**

| Field | Type | Range | Description |
|---|---|---|---|
| `timestamp` | ISO 8601 string | — | Current UTC time |
| `pv_power_w` | integer | 0 – 9000 W | Simulated PV generation |
| `grid_power_w` | integer | -5000 – 5000 W | Negative = exporting, positive = importing |
| `house_consumption_w` | integer | 250 – 6500 W | Simulated house demand |

---

## How the Simulation Works

### Solar curve

Power output follows a sine curve between 06:00 and 18:00 UTC, peaking at noon:

```
hour < 6  or hour > 18  →  0 W
otherwise               →  sin((hour - 6) / 12 * π) * peakPower * seasonFactor * weatherFactor
```

### Seasonal factor

| Month | Factor |
|---|---|
| June / July | 1.00 (peak summer) |
| May / August | 0.90 |
| April / September | 0.75 |
| March / October | 0.60 |
| February / November | 0.45 |
| January / December | 0.35 (deep winter) |

### Weather randomness

Each request multiplies the output by a random factor between **0.55 and 1.00**,
simulating cloud cover.

### Grid power

```
grid_power_w = clamp(house_consumption_w - pv_power_w, -5000, 5000)
```

Negative means the system is exporting excess solar; positive means it is drawing from the grid.

---

## OpenAPI Validation

The server uses `express-openapi-validator` to validate **both requests and responses**
against `openapi.yaml`. Any response that doesn't match the schema will produce a 500 error.
This keeps the mock honest and makes it easier to spot mismatches with what the Drupal
module expects.

---

## Running with Docker

If the Drupal stack runs in Docker Compose, add the mock API as a service:

```yaml
# docker-compose.yml
services:
  mock-api:
    image: node:20-alpine
    working_dir: /app
    volumes:
      - ./web/modules/custom/htl_pv_api-master/temp-api-files:/app
    command: sh -c "npm install && node server.js"
    ports:
      - "4010:4010"
```

Then set **API Base URL** in the Drupal settings to `http://mock-api:4010`.
The PVClient will resolve `mock-api` automatically via Docker's internal DNS.

---

## Switching to a Real API

When a real inverter API is available, the mock server is no longer needed.
See `docs/production-api-migration.md` for the step-by-step guide to map the
real API's field names, units and scale factors.
