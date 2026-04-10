<?php

namespace Drupal\htl_pv_api\Service;

use Drupal\htl_pv_api\Model\PVSample;

/**
 * Database storage for PV samples (htl_pv_sample table).
 */
class PVStore
{
    /**
     * Insert or update a single sample (keyed by provider + sampled_at).
     */
    public function upsert(PVSample $s): void
    {
        if (!$s->sampled_at instanceof \DateTimeInterface) {
            return;
        }
        $iso = gmdate("Y-m-d H:i:s", $s->sampled_at->getTimestamp());
        try {
            \Drupal::database()
                ->merge("htl_pv_sample")
                ->keys(["sampled_at" => $iso])
                ->fields([
                    "power_w" => $s->power_w,
                    "grid_power_w" => $s->grid_power_w,
                    "house_consumption_w" => $s->house_consumption_w,
                    "energy_wh_total" => $s->energy_wh_total,
                ])
                ->execute();
        } catch (\Throwable $e) {
            \Drupal::logger("htl_pv_api")->error(
                "PVStore: merge failed: @msg",
                ["@msg" => $e->getMessage()],
            );
        }
    }

    /**
     * Bulk upsert.
     *
     * @param PVSample[] $samples
     */
    public function upsertMany(array $samples): void
    {
        foreach ($samples as $s) {
            if ($s instanceof PVSample) {
                $this->upsert($s);
            }
        }
    }

    /**
     * Get the newest sample.
     */
    public function latest(): ?PVSample
    {
        $row = \Drupal::database()
            ->select("htl_pv_sample", "s")
            ->fields("s")
            ->orderBy("s.sampled_at", "DESC")
            ->range(0, 1)
            ->execute()
            ->fetchAssoc();
        return $row ? $this->toSample($row) : null;
    }

    /**
     * Get samples within a Unix timestamp range.
     *
     * @return PVSample[]
     */
    public function history(int $fromTs, int $toTs): array
    {
        $rows = \Drupal::database()
            ->select("htl_pv_sample", "s")
            ->fields("s")
            ->condition(
                "s.sampled_at",
                [gmdate("Y-m-d H:i:s", $fromTs), gmdate("Y-m-d H:i:s", $toTs)],
                "BETWEEN",
            )
            ->orderBy("s.sampled_at", "ASC")
            ->execute()
            ->fetchAll(\PDO::FETCH_ASSOC);
        return array_map([$this, "toSample"], $rows ?: []);
    }

    /**
     * Count total samples in the table.
     */
    public function count(): int
    {
        return (int) \Drupal::database()
            ->select("htl_pv_sample", "s")
            ->countQuery()
            ->execute()
            ->fetchField();
    }

    /**
     * Delete samples older than a cutoff ISO date.
     */
    public function deleteOlderThan(string $cutoffIso): int
    {
        return (int) \Drupal::database()
            ->delete("htl_pv_sample")
            ->condition("sampled_at", $cutoffIso, "<")
            ->execute();
    }

    // -----------------------------------------------------------------------

    private function toSample(array $row): PVSample
    {
        $ts = null;
        if (!empty($row["sampled_at"])) {
            try {
                $ts = new \DateTime(
                    $row["sampled_at"],
                    new \DateTimeZone("UTC"),
                );
            } catch (\Throwable $e) {
            }
        }

        return new PVSample([
            "sampled_at" => $ts,
            "power_w" => $row["power_w"] ?? null,
            "grid_power_w" => $row["grid_power_w"] ?? null,
            "house_consumption_w" => $row["house_consumption_w"] ?? null,
            "energy_wh_total" => $row["energy_wh_total"] ?? null,
        ]);
    }
}
