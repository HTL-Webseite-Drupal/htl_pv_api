<?php

declare(strict_types=1);

namespace Drupal\htl_pv_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Builds the admin overview page for the PV module.
 */
final class PVOverviewController extends ControllerBase
{
    public function __construct(
        private readonly ModuleExtensionList $moduleExtensionList,
    ) {}

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get("extension.list.module"),
        );
    }

    public function overview(): array
    {
        $overview = $this->loadOverview();
        $summary = (string) ($overview["purpose"]["summary"] ?? $this->t(
            "HTL PV API fetches photovoltaic data, stores samples, and provides admin tools for migration and diagnostics.",
        ));
        $migration = is_array($overview["purpose"]["migration_strategy"] ?? null)
            ? $overview["purpose"]["migration_strategy"]
            : [];
        $sections = is_array($overview["settings_sections"] ?? null)
            ? $overview["settings_sections"]
            : [];

        $build["intro"] = [
            "#markup" => "<p>" . $summary . "</p>",
        ];

        $build["links"] = [
            "#theme" => "item_list",
            "#title" => $this->t("Admin links"),
            "#items" => [
                Link::fromTextAndUrl(
                    $this->t("PV API settings"),
                    Url::fromRoute("htl_pv_api.settings"),
                )->toRenderable(),
                Link::fromTextAndUrl(
                    $this->t("PV API Inspector"),
                    Url::fromRoute("htl_pv_api.inspector"),
                )->toRenderable(),
            ],
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
                "#title" => $this->t("Migration support"),
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
