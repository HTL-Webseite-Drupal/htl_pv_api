<?php

declare(strict_types=1);

namespace Drupal\htl_pv_api\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\htl_pv_api\Model\PVSample;
use Drupal\htl_pv_api\Service\PVClient;
use Drupal\htl_pv_api\Service\PVFieldMap;
use Drupal\htl_pv_api\Service\PVStore;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Minimal live PV block – Fronius-style circular gauge.
 *
 * @Block(
 *   id = "pv_live_block",
 *   admin_label = @Translation("PV Live"),
 *   category = @Translation("HTL PV"),
 * )
 */
class PVLiveBlock extends BlockBase implements ContainerFactoryPluginInterface
{
    public function __construct(
        array $configuration,
        string $plugin_id,
        mixed $plugin_definition,
        private readonly PVClient $pvClient,
        private readonly PVStore $pvStore,
        private readonly PVFieldMap $fieldMap,
    ) {
        parent::__construct($configuration, $plugin_id, $plugin_definition);
    }

    public static function create(
        ContainerInterface $container,
        array $configuration,
        $plugin_id,
        $plugin_definition,
    ): static {
        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $container->get('htl_pv_api.client'),
            $container->get('htl_pv_api.store'),
            $container->get('htl_pv_api.field_map'),
        );
    }

    public function build(): array
    {
        $cfg = \Drupal::config("htl_pv_api.settings");
        $live = new PVSample();

        try {
            $live = $this->pvClient->fetchLive();
            $this->pvStore->upsert($live);
        } catch (\Throwable $e) {
            $live = $this->pvStore->latest() ?? new PVSample();
        }

        return [
            "#theme" => "pv_live_block",
            "#live" => $live,
            "#field_map" => $this->fieldMap->getFieldDefinitions(),
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
                        "field_map" => $this->fieldMap->getFieldDefinitions(),
                    ],
                ],
            ],
            "#cache" => ["max-age" => 60],
        ];
    }
}
