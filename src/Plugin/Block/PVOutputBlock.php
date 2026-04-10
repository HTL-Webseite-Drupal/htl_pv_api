<?php

namespace Drupal\htl_pv_api\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\htl_pv_api\Service\PVDataProviderInterface;
use Drupal\htl_pv_api\Service\PVRepository;

/**
 * Provides a 'PV Dashboard' Block.
 *
 * @Block(
 *   id = "pvoutput_block",
 *   admin_label = @Translation("PV Dashboard"),
 * )
 */
class PVOutputBlock extends BlockBase implements ContainerFactoryPluginInterface
{
    protected PVDataProviderInterface $pvProvider;
    protected PVRepository $repository;

    public function __construct(
        array $configuration,
        $plugin_id,
        $plugin_definition,
        PVDataProviderInterface $pv_provider,
        PVRepository $repository,
    ) {
        parent::__construct($configuration, $plugin_id, $plugin_definition);
        $this->pvProvider = $pv_provider;
        $this->repository = $repository;
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
            $container->get("htl_pv_api.provider"),
            $container->get("htl_pv_api.repository"),
        );
    }

    public function build(): array
    {
        $data = $this->getPvData();
        return [
            "#theme" => "pvoutput_block",
            "#current" => $data["current"],
            "#chart_data" => $data["chart_data"],
            "#stats" => $data["stats"],
            "#today" => $data["today"],
            "#attached" => ["library" => ["htl_pv_api/pv_ui"]],
            "#cache" => ["max-age" => 300],
        ];
    }

    protected function getPvData(): array
    {
        $providerId = (string) \Drupal::config("htl_pv_api.settings")->get(
            "provider",
        );

        // Latest sample from custom table.
        $latest = $this->repository->getLatest($providerId);
        $current = [
            "power" => (float) ($latest?->power_w ?? 0),
            "grid_power" => (float) ($latest?->grid_power_w ?? 0),
            "house_consumption" => (float) ($latest?->house_consumption_w ?? 0),
            "energy_today" => 0,
            "timestamp" => $latest?->timestamp?->format("Y-m-d H:i:s"),
            "provider" => $providerId,
        ];

        // Today summary from provider.
        $today = [];
        try {
            $today = $this->pvProvider->getTodaySummary();
            $current["energy_today"] =
                (float) ($today["energy_produced_kwh"] ?? 0);
        } catch (\Throwable $e) {
            /* non-fatal */
        }

        // Today's chart data from custom table.
        $tz = new \DateTimeZone("UTC");
        $today_start = new \DateTime("today", $tz)->getTimestamp();
        $now = new \DateTime("now", $tz)->getTimestamp();
        $history = $this->repository->getHistory(
            $providerId,
            $today_start,
            $now,
        );

        $chart_data = [];
        foreach ($history as $m) {
            if ($m->timestamp instanceof \DateTimeInterface) {
                $chart_data[] = [
                    "time" => $m->timestamp->format("H:i"),
                    "power" => (float) ($m->power_w ?? 0),
                ];
            }
        }

        return [
            "current" => $current,
            "chart_data" => $chart_data,
            "stats" => $this->buildStats($chart_data, $current),
            "today" => $today,
        ];
    }

    protected function buildStats(array $chart_data, array $current): array
    {
        $max_power = 0.0;
        $total = 0.0;
        $peak_time = "-";

        foreach ($chart_data as $pt) {
            $total += $pt["power"];
            if ($pt["power"] > $max_power) {
                $max_power = $pt["power"];
                $peak_time = $pt["time"];
            }
        }

        $count = count($chart_data);
        return [
            "max_power" => $max_power,
            "avg_power" => $count > 0 ? $total / $count : 0.0,
            "peak_time" => $peak_time,
            "energy_today" => $current["energy_today"],
        ];
    }
}
