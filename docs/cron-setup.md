# Cron Setup

The module collects PV data via cron. Two options are available — use one or both.

---

## Option 1: Drupal Cron

The module hooks into Drupal's built-in cron via `hook_cron()` in `htl_pv_api.module`.

### Enable

1. Go to **Settings** → set **Cron aktiviert** to checked
2. Set **Cron-Intervall** (minimum seconds between fetches, e.g. `60`)
3. Make sure Drupal cron is running — check **Admin → Configuration → System → Cron**

### Trigger manually

```bash
drush cron
```

### How it works

Each time Drupal cron runs, `htl_pv_api_cron()` is called. It:

1. Checks `cron_enabled` — exits if disabled
2. Acquires a lock (120 s timeout) to prevent parallel runs
3. Checks that at least `cron_interval` seconds have passed since the last run
4. Fetches `/pv/live` via PVClient
5. Upserts the sample into `htl_pv_sample`
6. Runs data retention cleanup (deletes rows older than `data_retention_days`)
7. Releases the lock

---

## Option 2: Dedicated HTTP Cron Endpoint

For higher-frequency collection (e.g. every minute), bypass Drupal cron entirely
and call the module's own endpoint from your system crontab.

### Get the cron key

```bash
drush state:get htl_pv_api.cron_key
```

The key is generated randomly at module install. Keep it private.

### Add to system crontab

```bash
crontab -e
```

```
# Fetch PV data every minute
* * * * * wget -qO- https://yoursite.com/pvoutput/cron/YOUR_KEY_HERE
```

Or with curl:

```
* * * * * curl -sf https://yoursite.com/pvoutput/cron/YOUR_KEY_HERE
```

### Endpoint response

On success:

```json
{
  "ok": true,
  "timestamp": "2025-04-10T10:30:00+00:00",
  "power_w": 3842,
  "deleted": 0
}
```

On interval not reached yet:

```json
{
  "ok": true,
  "message": "Interval not reached",
  "next_in": 45
}
```

On invalid key:

```json
{ "ok": false, "error": "Invalid cron key" }
```
HTTP 403.

### Regenerating the key

There is currently no UI for this. Use Drush:

```bash
drush php-eval "\Drupal::state()->set('htl_pv_api.cron_key', bin2hex(random_bytes(32))); echo \Drupal::state()->get('htl_pv_api.cron_key');"
```

---

## Recommended Setup

| Scenario | Recommendation |
|---|---|
| Development / local | Drupal cron is enough, or trigger manually with `drush cron` |
| Production, data every 1 min | System crontab `* * * * *` → HTTP endpoint |
| Production, data every 5 min | System crontab `*/5 * * * *` → HTTP endpoint, set `cron_interval: 240` |
| Shared hosting (no crontab) | Drupal cron via external URL ping service |

---

## Debugging Cron

Check recent cron log entries:

```bash
drush watchdog:show --type=htl_pv_api
```

Or in the UI: **Admin → Reports → Recent log messages**, filter by `htl_pv_api`.
