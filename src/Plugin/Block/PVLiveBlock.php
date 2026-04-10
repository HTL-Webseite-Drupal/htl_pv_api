<?php

namespace Drupal\htl_pv_api\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\htl_pv_api\Model\PVSample;
use Drupal\htl_pv_api\Service\PVClient;
use Drupal\htl_pv_api\Service\PVStore;

/**
 * Minimal live PV block – Fronius-style circular gauge.
 *
 * @Block(
 *   id = "pv_live_block",
 *   admin_label = @Translation("PV Live"),
 *   category = @Translation("HTL PV"),
 * )
 */
class PVLiveBlock extends BlockBase
{
    public function build(): array
    {
        $cfg = \Drupal::config("htl_pv_api.settings");
        $fieldMap = \Drupal::service("htl_pv_api.field_map");
        $store = new PVStore();
        $live = new PVSample();

        try {
            $client = new PVClient(
                $cfg->get("api_base_url") ?? "http://localhost:4010",
                $fieldMap,
            );
            $live = $client->fetchLive();
            $store->upsert($live);
        } catch (\Throwable $e) {
            $live = $store->latest() ?? new PVSample();
        }

        return [
            "#theme" => "pv_live_block",
            "#live" => $live,
            "#field_map" => $fieldMap->getFieldDefinitions(),
            "#max_w" => (int) ($cfg->get("max_power_w") ?? 10000),
            "#css" => [
                "card_bg" => $cfg->get("css_card_bg") ?? "",
                "gauge_arc" => $cfg->get("css_gauge_arc") ?? "",
                "gauge_label" => $cfg->get("css_gauge_label") ?? "",
                "value_number" => $cfg->get("css_value_number") ?? "",
                "value_unit" => $cfg->get("css_value_unit") ?? "",
            ],
            "#attached" => [
                "library" => ["htl_pv_api/pv_ui"],
                "drupalSettings" => [
                    "htl_pv_api" => [
                        "poll_interval" =>
                            (int) ($cfg->get("poll_interval") ?? 5),
                        "field_map" => $fieldMap->getFieldDefinitions(),
                    ],
                ],
            ],
            "#cache" => ["max-age" => 60],
        ];
    }
}
