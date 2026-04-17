<?php

declare(strict_types=1);

namespace Drupal\htl_pv_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\htl_pv_api\Service\PVStore;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Builds the admin overview page for the PV module.
 */
final class PVOverviewController extends ControllerBase
{
    public function __construct(
        private readonly ModuleExtensionList $moduleExtensionList,
        private readonly PVStore $pvStore,
    ) {}

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get("extension.list.module"),
            $container->get("htl_pv_api.store"),
        );
    }

    public function overview(): array
    {
        $overview = $this->loadOverview();
        $summary =
            (string) ($overview["purpose"]["summary"] ??
                $this->t(
                    "HTL PV API fetches photovoltaic data, stores samples, and provides admin tools for migration and diagnostics.",
                ));
        $migration = is_array(
            $overview["purpose"]["migration_strategy"] ?? null,
        )
            ? $overview["purpose"]["migration_strategy"]
            : [];
        $sections = is_array($overview["settings_sections"] ?? null)
            ? $overview["settings_sections"]
            : [];
        $config = $this->config("htl_pv_api.settings");
        $lastRun = (int) \Drupal::state()->get("htl_pv_api.cron_last_run", 0);
        $lastRunLabel = $lastRun
            ? \Drupal::service("date.formatter")->format($lastRun, "short")
            : (string) $this->t("Never");

        $build["intro"] = [
            "#markup" => "<p>" . $summary . "</p>",
        ];

        $build["status"] = [
            "#type" => "table",
            "#header" => [$this->t("Metric"), $this->t("Value")],
            "#rows" => [
                [$this->t("Stored samples"), (string) $this->pvStore->count()],
                [$this->t("Last fetch"), $lastRunLabel],
                [
                    $this->t("API base URL"),
                    (string) ($config->get("api_base_url") ??
                        "http://mock-api:4010"),
                ],
                [
                    $this->t("Live endpoint path"),
                    (string) ($config->get("live_endpoint_path") ?? "/pv/live"),
                ],
            ],
            "#caption" => $this->t("Current status"),
        ];

        $build["links"] = [
            "#type" => "table",
            "#header" => [$this->t("Page"), $this->t("Purpose")],
            "#rows" => [
                [
                    [
                        "data" => Link::fromTextAndUrl(
                            $this->t("PV API settings"),
                            Url::fromRoute("htl_pv_api.settings"),
                        )->toRenderable(),
                    ],
                    $this->t(
                        "Configure connection, collection, display, and field mapping.",
                    ),
                ],
                [
                    [
                        "data" => Link::fromTextAndUrl(
                            $this->t("PV API Inspector"),
                            Url::fromRoute("htl_pv_api.inspector"),
                        )->toRenderable(),
                    ],
                    $this->t(
                        "Inspect the live JSON payload and copy working JSON paths.",
                    ),
                ],
                [
                    [
                        "data" => Link::fromTextAndUrl(
                            $this->t("PV dashboard"),
                            Url::fromRoute("htl_pv_api.dashboard"),
                        )->toRenderable(),
                    ],
                    $this->t(
                        "Open the public dashboard view that uses the saved configuration.",
                    ),
                ],
            ],
            "#caption" => $this->t("Quick links"),
        ];

        if ($sections !== []) {
            $rows = [];
            foreach ($sections as $sectionId => $section) {
                $rows[] = [
                    (string) ($section["title"] ?? $sectionId),
                    is_array($section["settings"] ?? null)
                        ? (string) count($section["settings"])
                        : "0",
                ];
            }

            $build["sections"] = [
                "#type" => "table",
                "#header" => [
                    $this->t("Settings section"),
                    $this->t("Setting count"),
                ],
                "#rows" => $rows,
                "#caption" => $this->t("Configuration overview"),
            ];
        }

        if ($migration !== []) {
            $build["migration"] = [
                "#theme" => "item_list",
                "#title" => $this->t("Recommended migration workflow"),
                "#items" => $migration,
            ];
        }

        return $build;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadOverview(): array
    {
        $modulePath = $this->moduleExtensionList->getPath("htl_pv_api");
        $overviewFile = $modulePath . "/config/htl_pv_api.overview.yml";
        if (!is_file($overviewFile)) {
            return [];
        }

        try {
            $parsed = Yaml::parseFile($overviewFile);
            return is_array($parsed) ? $parsed : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
