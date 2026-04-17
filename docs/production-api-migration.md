# Switching to a Production API

This guide explains what to change when replacing the Node.js mock API with a real
photovoltaic inverter API (Fronius, SMA, Huawei, custom, etc.).

---

## 1. Change the Base URL

### Via the Admin UI (recommended)

1. Go to **Admin → Configuration → HTL → PV API**
   (`/admin/config/htl/pv-api`)
2. Change **API Base URL** to your real endpoint, e.g. `http://192.168.1.10/api`
3. If needed, change **Live Endpoint Path** from `/pv/live` to the real path
4. Click **Save**

### Via config file (for fresh installs)

Edit `config/install/htl_pv_api.settings.yml`:

```yaml
api_base_url: "http://192.168.1.10/api"
```

> **Note:** The module automatically tries `host.docker.internal`, `172.17.0.1`,
> and `mock-api` as fallbacks if `localhost` is used. For production, set the exact
> IP/hostname so no fallback logic runs.

---

## 2. Map Your API's Field Names (the most important step)

The production API will almost certainly return different JSON keys than the mock.
The **Feld-Mapping** section in the settings form is the single place to change this —
nothing else in the codebase needs to be touched.

### Where to configure

**Admin → Configuration → HTL → PV API → Feld-Mapping section**

### What each setting does

| Setting | Description | Example |
|---|---|---|
| **API-Schlüssel** | The exact JSON key the API returns for this value | `pac` or `Power.AC.Phase.1` |
| **Anzeige-Name** | Label shown in the dashboard and block | `Solar Power` |
| **Einheit** | Unit shown next to the value | `kW`, `%`, `kWh` |
| **Skalierungsfaktor** | Multiplier: raw value × scale = displayed value | `0.001` (W → kW), `1.0` (no change) |
| **Aktiviert** | Whether to fetch and store this field at all | ✓ / ✗ |
| **Zeitstempel-Schlüssel** | JSON key that holds the sample time | `timestamp`, `date_time`, `ts` |

### Which fields exist

| Internal name | What it represents |
|---|---|
| `power_w` | Current PV generation (in Watts raw, converted by scale for display) |
| `grid_power_w` | Power exported to / imported from the grid |
| `house_consumption_w` | Total house consumption |
| `energy_wh_total` | Total energy produced today (Wh) |

### Example: Fronius Symo API

If your Fronius inverter returns:

```json
{
  "timestamp": "2025-04-10T08:30:00Z",
  "PAC": 3450,
  "P_Grid": -120,
  "P_Load": 980,
  "SOC": 87,
  "P_Akku": 200,
  "Day_Energy": 12400
}
```

Then set:

| Field | API-Schlüssel | Einheit | Skalierungsfaktor |
|---|---|---|---|
| `power_w` | `PAC` | `kW` | `0.001` |
| `grid_power_w` | `P_Grid` | `kW` | `0.001` |
| `house_consumption_w` | `P_Load` | `kW` | `0.001` |
| `energy_wh_total` | `Day_Energy` | `kWh` | `0.001` |
| Zeitstempel-Schlüssel | `timestamp` | — | — |

### Example: API that already returns kilowatts

If the API returns values already in kW (not W):

```json
{ "power_kw": 3.45, "timestamp": "..." }
```

Set **Skalierungsfaktor** to `1.0` and **Einheit** to `kW`.

---

## 3. If the API Returns a Different Timestamp Format

The module accepts both ISO 8601 timestamps and Unix timestamps.

You can point the timestamp mapping to simple keys or nested paths such as:

- `timestamp`
- `data.live.timestamp`
- `results[0].time`

---

## 4. Use the PV API Inspector

Open:

- **Admin → Configuration → HTL → PV API Inspector**
- `/admin/config/htl/pv-api/inspector`

This page fetches the current live payload and shows:

- the complete raw JSON
- every detected scalar path in that JSON
- the current configured mapping and which values it resolves

This is the easiest way to adapt the module when the production API is unknown in advance.

### Example

If the payload looks like this:

```json
{
  "data": {
    "live": {
      "time": 1760000000,
      "pv": { "current_w": 4200 },
      "grid": { "power": -300 },
      "house": { "consumption": 1800 }
    }
  }
}
```

then the mapping can be:

- `field_map.timestamp_key` → `data.live.time`
- `field_map.fields.power_w.api_key` → `data.live.pv.current_w`
- `field_map.fields.grid_power_w.api_key` → `data.live.grid.power`
- `field_map.fields.house_consumption_w.api_key` → `data.live.house.consumption`

---

## 5. Config File Defaults (for fresh installs / version control)

If you want the correct production values committed to the repository, update
`config/install/htl_pv_api.settings.yml`. This file is only used on first install;
existing sites use the database config (changed via the admin UI).

After changing the YAML, run:

```bash
drush config-import
# or
drush cr   # just cache-rebuild if settings were changed in the UI
```

---

## 6. Checklist Summary

- [ ] Set **API Base URL** to production endpoint
- [ ] Set **Live Endpoint Path** if the live URL is not `/pv/live`
- [ ] Open **PV API Inspector** and inspect the live payload
- [ ] For each field: set the correct **API-Schlüssel** (JSON key/path from your API)
- [ ] For each field: set the correct **Skalierungsfaktor** (check if API returns W or kW)
- [ ] Disable fields that your inverter doesn't provide (**Aktiviert** unchecked)
- [ ] Set the correct **Zeitstempel-Schlüssel**
- [ ] Click **Save** and then **Fetch now** (manual fetch button) to verify data comes in
- [ ] Check **Admin → Reports → Recent log messages** (`/admin/reports/dblog`) for any
  `PVClient` errors if data doesn't appear

---

## 7. Where Things Live in the Code (for developers)

| What | File |
|---|---|
| All field defaults & fallback mapping | `src/Service/PVFieldMap.php` — `DEFAULTS` constant |
| API HTTP requests | `src/Service/PVClient.php` |
| Payload inspection page | `src/Controller/PVInspectorController.php` |
| DB storage | `src/Service/PVStore.php` |
| Data model (DTO) | `src/Model/PVSample.php` |
| Admin settings form | `src/Form/PVSettingsForm.php` |
| Saved config location | `htl_pv_api.settings` → key `field_map` |
