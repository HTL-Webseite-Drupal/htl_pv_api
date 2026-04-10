<?php

namespace Drupal\htl_pv_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\htl_pv_api\Model\PVSample;
use Drupal\htl_pv_api\Service\PVClient;
use Drupal\htl_pv_api\Service\PVStore;

/**
 * Full PV dashboard page and JSON fetch endpoint.
 */
class PVController extends ControllerBase
{
    private function client(): PVClient
    {
        $url =
            \Drupal::config("htl_pv_api.settings")->get("api_base_url") ??
            "http://localhost:4010";
        return new PVClient($url);
    }

    private function store(): PVStore
    {
        return new PVStore();
    }

    /**
     * Full dashboard page.
     */
    public function dashboard(): array
    {
        $client = $this->client();
        $store = $this->store();
        $cfg = \Drupal::config("htl_pv_api.settings");
        $fieldMap = \Drupal::service("htl_pv_api.field_map");
        $force = (bool) \Drupal::request()->query->get("refresh");

        // --- Live data -------------------------------------------------------
        $live = new PVSample();

        try {
            $live = $client->fetchLive();
            $store->upsert($live);
        } catch (\Throwable $e) {
            $live = $store->latest() ?? new PVSample();
        }

        // --- History (last 7 days) from database ----------------------------
        $tz = new \DateTimeZone("UTC");
        $now = new \DateTime("now", $tz);
        $from = (clone $now)->modify("-7 days")->setTime(0, 0, 0);

        $history = $store->history($from->getTimestamp(), $now->getTimestamp());

        // --- 15-min slot aggregation -----------------------------------------
        $intervalMin = max(
            1,
            min(15, (int) ($cfg->get("chart_interval_minutes") ?? 15)),
        );
        $slotSec = $intervalMin * 60;
        $startTs = $from->getTimestamp();
        $endTs = $now->getTimestamp();
        $totalSlots = (int) ceil(($endTs - $startTs) / $slotSec);
        $dayNames = ["So", "Mo", "Di", "Mi", "Do", "Fr", "Sa"];
        $buckets = [];
        $peakW = 0.0;
        $peakTime = null;

        foreach ($history as $s) {
            if (
                !$s->sampled_at instanceof \DateTimeInterface ||
                $s->power_w === null
            ) {
                continue;
            }
            $idx = (int) floor(
                ($s->sampled_at->getTimestamp() - $startTs) / $slotSec,
            );
            if ($idx >= 0 && $idx < $totalSlots) {
                $buckets[$idx][] = (float) $s->power_w;
            }
        }

        $labels = [];
        $data = [];
        for ($i = 0; $i < $totalSlots; $i++) {
            $dt = new \DateTime("@" . ($startTs + $i * $slotSec))->setTimezone(
                $tz,
            );
            $h = (int) $dt->format("G");
            $min = (int) $dt->format("i");

            if ($h === 0 && $min === 0) {
                $labels[] =
                    $dayNames[(int) $dt->format("w")] .
                    " " .
                    $dt->format("d.m.");
            } elseif ($min === 0) {
                $labels[] = $dt->format("H:i");
            } else {
                $labels[] = "";
            }

            if (isset($buckets[$i])) {
                $avg = array_sum($buckets[$i]) / count($buckets[$i]);
                $data[] = round($avg, 1);
                if ($avg > $peakW) {
                    $peakW = $avg;
                    $peakTime = $dt->format("D H:i");
                }
            } else {
                $data[] = null;
            }
        }

        $chart = [
            "labels" => $labels,
            "datasets" => [["label" => "PV Power (W)", "data" => $data]],
        ];

        // --- Calculate summaries from stored data -----------------------------
        $todayFrom = (clone $now)->setTime(0, 0, 0);
        $todayHistory = $store->history(
            $todayFrom->getTimestamp(),
            $now->getTimestamp(),
        );

        $todayKwh = 0.0;
        $prevSample = null;
        foreach ($todayHistory as $sample) {
            if (
                $sample->power_w !== null &&
                $prevSample !== null &&
                $prevSample->power_w !== null
            ) {
                $timeDiff =
                    $sample->sampled_at->getTimestamp() -
                    $prevSample->sampled_at->getTimestamp();
                if ($timeDiff > 0 && $timeDiff < 3600) {
                    $avgPower = ($sample->power_w + $prevSample->power_w) / 2;
                    $todayKwh += ($avgPower * $timeDiff) / 3600000.0;
                }
            }
            $prevSample = $sample;
        }

        $todayConsumed = 0.0;
        $todayExported = 0.0;
        $selfPct = 0;
        $monthProd = 0.0;
        $monthCons = 0.0;
        $monthExp = 0.0;

        $refreshUrl = Url::fromRoute(
            "htl_pv_api.dashboard",
            [],
            [
                "query" => ["refresh" => 1, "ts" => time()],
            ],
        )->toString();

        $render = [
            "#theme" => "pv_dashboard",
            "#live" => $live,
            "#field_map" => $fieldMap->getFieldDefinitions(),
            "#today_kwh" => $todayKwh,
            "#today_consumed_kwh" => $todayConsumed,
            "#today_exported_kwh" => $todayExported,
            "#self_pct" => $selfPct,
            "#month_produced_kwh" => $monthProd,
            "#month_consumed_kwh" => $monthCons,
            "#month_exported_kwh" => $monthExp,
            "#chart" => $chart,
            "#peak_w" => $peakW,
            "#peak_time" => $peakTime,
            "#sample_count" => count($history),
            "#refresh_url" => $refreshUrl,
            "#max_w" => (int) ($cfg->get("max_power_w") ?? 10000),
            "#css" => [
                "card_bg" => $cfg->get("css_card_bg") ?? "",
                "gauge_arc" => $cfg->get("css_gauge_arc") ?? "",
                "gauge_label" => $cfg->get("css_gauge_label") ?? "",
                "value_number" => $cfg->get("css_value_number") ?? "",
                "value_unit" => $cfg->get("css_value_unit") ?? "",
            ],
            "#attached" => [
                "library" => [
                    "htl_pv_api/pv_ui",
                    "htl_pv_api/pv_dashboard",
                ],
                "drupalSettings" => [
                    "htl_pv_api" => [
                        "poll_interval" =>
                            (int) ($cfg->get("poll_interval") ?? 5),
                        "chart_max_w" =>
                            (int) ($cfg->get("chart_max_w") ?? 10000),
                        "chart_interval_minutes" => $intervalMin,
                        "field_map" => $fieldMap->getFieldDefinitions(),
                    ],
                ],
            ],
        ];

        if ($force) {
            $render["#cache"] = ["max-age" => 0];
        }

        return $render;
    }

    /**
     * JSON endpoint for client polling.
     */
    public function fetch(): JsonResponse
    {
        try {
            $client = $this->client();
            $store = $this->store();
            $live = $client->fetchLive();
            $store->upsert($live);

            return new JsonResponse([
                "ok" => true,
                "timestamp" => $live->sampled_at?->format(DATE_ATOM),
                "power_w" => $live->power_w,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(
                ["ok" => false, "error" => $e->getMessage()],
                500,
            );
        }
    }

    /**
     * Dedicated cron endpoint for PV data collection.
     *
     * This endpoint can be called directly from system crontab, independent of Drupal cron.
     * URL: /pvoutput/cron/{key}
     *
     * Example crontab entry:
     *   * * * * * wget -O - -q -t 1 https://yoursite.com/pvoutput/cron/YOUR_KEY
     */
    public function cron(string $key): JsonResponse
    {
        // Verify the security key
        $validKey = \Drupal::state()->get("htl_pv_api.cron_key", "");
        if (empty($validKey) || $key !== $validKey) {
            return new JsonResponse(
                ["ok" => false, "error" => "Invalid cron key"],
                403,
            );
        }

        try {
            $state = \Drupal::state();
            $cfg = \Drupal::config("htl_pv_api.settings");
            $last = (int) $state->get("htl_pv_api.cron_last_run", 0);
            $now = time();

            // Check if cron is enabled
            if (!(bool) ($cfg->get("cron_enabled") ?? true)) {
                return new JsonResponse([
                    "ok" => false,
                    "message" => "Cron disabled in settings",
                ]);
            }

            // Check if enough time has passed
            $interval = max(10, (int) ($cfg->get("cron_interval") ?? 60));
            if ($now - $last < $interval) {
                return new JsonResponse([
                    "ok" => true,
                    "message" => "Interval not reached",
                    "next_in" => $interval - ($now - $last),
                ]);
            }

            // Fetch and store data
            $client = $this->client();
            $store = $this->store();
            $live = $client->fetchLive();
            $store->upsert($live);

            $state->set("htl_pv_api.cron_last_run", $now);

            // Retention cleanup
            $retention_days = (int) ($cfg->get("data_retention_days") ?? 30);
            $deleted = 0;
            if ($retention_days > 0) {
                $cutoff = gmdate(
                    "Y-m-d H:i:s",
                    strtotime("-{$retention_days} days", $now),
                );
                $deleted = $store->deleteOlderThan($cutoff);
            }

            return new JsonResponse([
                "ok" => true,
                "timestamp" => $live->sampled_at?->format(DATE_ATOM),
                "power_w" => $live->power_w,
                "deleted" => $deleted,
            ]);
        } catch (\Throwable $e) {
            \Drupal::logger("htl_pv_api")->error(
                "Dedicated cron failed: @msg",
                ["@msg" => $e->getMessage()],
            );
            return new JsonResponse(
                ["ok" => false, "error" => $e->getMessage()],
                500,
            );
        }
    }

    /**
     * Chart data API endpoint.
     *
     * Query parameters:
     *   - period: day|week|month|year (default: day)
     *   - date: YYYY-MM-DD (default: today)
     *
     * Returns aggregated chart data optimized for the requested period.
     */
    public function chartData(): JsonResponse
    {
        try {
            $request = \Drupal::request();
            $period = $request->query->get("period", "day");
            $date = $request->query->get("date", date("Y-m-d"));

            $tz = new \DateTimeZone("Europe/Vienna"); // TODO: Config
            $store = $this->store();
            $cfg = \Drupal::config("htl_pv_api.settings");
            $intervalMin = max(
                5,
                min(15, (int) ($cfg->get("chart_interval_minutes") ?? 15)),
            );

            // Parse the requested date
            try {
                $requestedDate = new \DateTime($date, $tz);
            } catch (\Throwable $e) {
                $requestedDate = new \DateTime("now", $tz);
            }

            // Determine time range based on period
            switch ($period) {
                case "week":
                    $from = (clone $requestedDate)
                        ->modify("monday this week")
                        ->setTime(0, 0, 0);
                    $to = (clone $from)->modify("+6 days")->setTime(23, 59, 59);
                    $label =
                        $from->format("d.m.Y") . " - " . $to->format("d.m.Y");
                    $targetPoints = 96; // ~14 pro Tag
                    break;

                case "month":
                    $from = (clone $requestedDate)
                        ->modify("first day of this month")
                        ->setTime(0, 0, 0);
                    $to = (clone $requestedDate)
                        ->modify("last day of this month")
                        ->setTime(23, 59, 59);
                    $label = $from->format("F Y");
                    $targetPoints = 96; // ~3 pro Tag
                    break;

                case "year":
                    $from = (clone $requestedDate)
                        ->setDate((int) $requestedDate->format("Y"), 1, 1)
                        ->setTime(0, 0, 0);
                    $to = (clone $requestedDate)
                        ->setDate((int) $requestedDate->format("Y"), 12, 31)
                        ->setTime(23, 59, 59);
                    $label = $from->format("Y");
                    $targetPoints = 96; // ~8 pro Monat
                    break;

                case "day":
                default:
                    $from = (clone $requestedDate)->setTime(0, 0, 0);
                    $to = (clone $requestedDate)->setTime(23, 59, 59);
                    $label = $from->format("d.m.Y");
                    $targetPoints = (int) ceil(1440 / $intervalMin);
                    break;
            }

            // Fetch raw data
            $samples = $store->history(
                $from->getTimestamp(),
                $to->getTimestamp(),
            );

            if (empty($samples)) {
                return new JsonResponse([
                    "ok" => true,
                    "period" => $period,
                    "date" => $date,
                    "label" => $label,
                    "labels" => [],
                    "data" => [],
                    "peak" => 0,
                    "count" => 0,
                ]);
            }

            // Aggregate data
            $aggregated = $this->aggregateChartData(
                $samples,
                $from,
                $to,
                $period,
                $targetPoints,
                $tz,
                $intervalMin,
            );

            // Find peak
            $peak = 0;
            foreach ($aggregated["data"] as $value) {
                if ($value !== null && $value > $peak) {
                    $peak = $value;
                }
            }

            return new JsonResponse([
                "ok" => true,
                "period" => $period,
                "date" => $date,
                "label" => $label,
                "labels" => $aggregated["labels"],
                "data" => $aggregated["data"],
                "peak" => round($peak, 0),
                "count" => count($samples),
            ]);
        } catch (\Throwable $e) {
            \Drupal::logger("htl_pv_api")->error("Chart data failed: @msg", [
                "@msg" => $e->getMessage(),
            ]);
            return new JsonResponse(
                ["ok" => false, "error" => $e->getMessage()],
                500,
            );
        }
    }

    /**
     * Aggregate samples into chart-ready data points.
     */
    private function aggregateChartData(
        array $samples,
        \DateTime $from,
        \DateTime $to,
        string $period,
        int $targetPoints,
        \DateTimeZone $tz,
        int $intervalMin = 15,
    ): array {
        $totalSeconds = $to->getTimestamp() - $from->getTimestamp();
        $bucketSize = (int) ceil($totalSeconds / $targetPoints);

        // Create buckets
        $buckets = [];
        for ($i = 0; $i < $targetPoints; $i++) {
            $buckets[$i] = [
                "values" => [],
                "time" => $from->getTimestamp() + $i * $bucketSize,
            ];
        }

        // Fill buckets with samples
        foreach ($samples as $sample) {
            if (!$sample->sampled_at || $sample->power_w === null) {
                continue;
            }

            $sampleTime = $sample->sampled_at->getTimestamp();
            $bucketIndex = (int) floor(
                ($sampleTime - $from->getTimestamp()) / $bucketSize,
            );

            if ($bucketIndex >= 0 && $bucketIndex < $targetPoints) {
                $buckets[$bucketIndex]["values"][] = (float) $sample->power_w;
            }
        }

        // Generate labels and data
        $labels = [];
        $data = [];

        foreach ($buckets as $i => $bucket) {
            // Calculate average for this bucket
            if (!empty($bucket["values"])) {
                $data[] = round(
                    array_sum($bucket["values"]) / count($bucket["values"]),
                    0,
                );
            } else {
                $data[] = null;
            }

            // Generate label
            $dt = new \DateTime("@" . $bucket["time"])->setTimezone($tz);

            switch ($period) {
                case "day":
                    // Show hour label once per hour — every (60 / intervalMin) slots
                    $slotsPerHour = (int) max(1, round(60 / $intervalMin));
                    if ($i % $slotsPerHour === 0) {
                        $labels[] = $dt->format("H:i");
                    } else {
                        $labels[] = "";
                    }
                    break;

                case "week":
                    // Show day labels
                    if ($i % 14 === 0) {
                        $labels[] = $dt->format("D d.m");
                    } else {
                        $labels[] = "";
                    }
                    break;

                case "month":
                    // Show day-of-month labels
                    if ($i % 3 === 0) {
                        $labels[] = $dt->format("d.");
                    } else {
                        $labels[] = "";
                    }
                    break;

                case "year":
                    // Show month labels
                    if ($i % 8 === 0) {
                        $labels[] = $dt->format("M");
                    } else {
                        $labels[] = "";
                    }
                    break;

                default:
                    $labels[] = "";
            }
        }

        return ["labels" => $labels, "data" => $data];
    }
}
