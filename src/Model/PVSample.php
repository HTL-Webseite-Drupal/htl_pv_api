<?php

namespace Drupal\htl_pv_api\Model;

/**
 * DTO for a single PV measurement.
 */
class PVSample
{
    public ?\DateTimeInterface $sampled_at;
    public ?float $power_w;
    public ?float $grid_power_w;
    public ?float $house_consumption_w;
    public ?float $energy_wh_total;

    public function __construct(array $data = [])
    {
        $ts = $data["sampled_at"] ?? null;
        if (is_string($ts)) {
            try {
                $this->sampled_at = new \DateTime($ts);
            } catch (\Throwable $e) {
                $this->sampled_at = null;
            }
        } elseif ($ts instanceof \DateTimeInterface) {
            $this->sampled_at = $ts;
        } else {
            $this->sampled_at = null;
        }

        $this->power_w = isset($data["power_w"])
            ? (float) $data["power_w"]
            : null;
        $this->grid_power_w = isset($data["grid_power_w"])
            ? (float) $data["grid_power_w"]
            : null;
        $this->house_consumption_w = isset($data["house_consumption_w"])
            ? (float) $data["house_consumption_w"]
            : null;
        $this->energy_wh_total = isset($data["energy_wh_total"])
            ? (float) $data["energy_wh_total"]
            : null;
    }
}
