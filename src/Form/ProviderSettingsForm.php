<?php

declare(strict_types=1);

namespace Drupal\htl_pv_api\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\htl_core\Form\HtlSettingsFormBase;

class ProviderSettingsForm extends HtlSettingsFormBase
{
    public function getFormId(): string
    {
        return "htl_pv_api_provider_settings";
    }

    protected function getConfigName(): string
    {
        return "htl_pv_api.settings";
    }

    protected function buildSettingsForm(
        array $form,
        FormStateInterface $form_state,
    ): array {
        $config = $this->settings();

        // --- Status section --------------------------------------------------
        $sampleCount = \Drupal::database()
            ->select("htl_pv_sample", "s")
            ->countQuery()
            ->execute()
            ->fetchField();
        $lastRun = (int) \Drupal::state()->get("htl_pv_api.cron_last_run", 0);
        $lastRunLabel = $lastRun
            ? \Drupal::service("date.formatter")->format($lastRun, "short")
            : $this->t("Noch nie ausgeführt");

        $form["status"] = [
            "#type" => "fieldset",
            "#title" => $this->t("Status"),
            "info" => [
                "#markup" =>
                    "<p>" .
                    $this->t(
                        "Gespeicherte PV-Samples: <strong>@count</strong>",
                        ["@count" => $sampleCount],
                    ) .
                    "</p>" .
                    "<p>" .
                    $this->t("Letzter Cron-Abruf: <strong>@time</strong>", [
                        "@time" => $lastRunLabel,
                    ]) .
                    "</p>",
            ],
            "fetch_now" => [
                "#type" => "submit",
                "#value" => $this->t("Jetzt Daten abrufen"),
                "#name" => "fetch_now",
                "#submit" => ["::submitFetchNow"],
                "#limit_validation_errors" => [],
                "#button_type" => "primary",
            ],
        ];

        // --- Connection & scheduling -----------------------------------------

        $form["prism_base_url"] = [
            "#type" => "textfield",
            "#title" => $this->t("Mock API base URL"),
            "#default_value" =>
                $config->get("prism_base_url") ?? "http://localhost:4010",
            "#description" => $this->t(
                "Base URL of the PV mock API. Endpoints used: /pv/live, /pv/history, /pv/summary/today, /pv/summary/month.",
            ),
            "#required" => true,
        ];

        $form["poll_interval"] = [
            "#type" => "number",
            "#title" => $this->t("Client poll interval (seconds)"),
            "#default_value" => (int) ($config->get("poll_interval") ?? 5),
            "#min" => 1,
            "#description" => $this->t(
                "How often the browser triggers a background fetch to keep node data fresh.",
            ),
        ];

        $form["cron_enabled"] = [
            "#type" => "checkbox",
            "#title" => $this->t(
                "Automatische Datensammlung aktivieren (Cron)",
            ),
            "#default_value" => (bool) ($config->get("cron_enabled") ?? true),
            "#description" => $this->t(
                "Wenn aktiv, werden PV-Samples beim Cron-Lauf automatisch vom Provider abgerufen und als Inhalte gespeichert. Auf dem Server muss zusaetzlich ein echter Cronjob eingerichtet werden, z. B. mit crontab -e und dem Eintrag * * * * * curl -sf https://example.com/pvoutput/cron/IHR_CRON_KEY. Den aktuellen Schluessel erhalten Sie mit drush state:get htl_pv_api.cron_key.",
            ),
        ];

        $form["cron_interval"] = [
            "#type" => "number",
            "#title" => $this->t("Cron fetch interval (seconds)"),
            "#default_value" => (int) ($config->get("cron_interval") ?? 60),
            "#min" => 10,
            "#description" => $this->t(
                "Minimum seconds between cron-triggered fetches (soft debounce).",
            ),
            "#states" => [
                "visible" => [
                    ':input[name="cron_enabled"]' => ["checked" => true],
                ],
            ],
        ];

        $form["data_retention_days"] = [
            "#type" => "number",
            "#title" => $this->t("Data retention (days)"),
            "#default_value" =>
                (int) ($config->get("data_retention_days") ?? 30),
            "#min" => 1,
            "#description" => $this->t(
                "PV-Sample-Nodes, die älter als diese Anzahl an Tagen sind, werden beim nächsten Cron-Lauf automatisch gelöscht (0 = niemals löschen).",
            ),
        ];

        // --- Global UI defaults ----------------------------------------------
        $ui = $config->get("ui") ?: [];
        $form["ui"] = [
            "#type" => "details",
            "#title" => $this->t("Display & Theme"),
            "#open" => true,
            "#tree" => true,
        ];

        $form["ui"]["layout"] = [
            "#type" => "select",
            "#title" => $this->t("Layout"),
            "#options" => [
                "compact" => $this->t("Compact"),
                "standard" => $this->t("Standard"),
            ],
            "#default_value" => $ui["layout"] ?? "standard",
        ];

        $form["ui"]["units"] = [
            "#type" => "select",
            "#title" => $this->t("Units"),
            "#options" => [
                "auto" => $this->t("Auto-scale (W/kW)"),
                "W" => $this->t("Always W"),
            ],
            "#default_value" => $ui["units"] ?? "auto",
        ];

        $form["ui"]["decimals"] = [
            "#type" => "number",
            "#title" => $this->t("Decimals"),
            "#min" => 0,
            "#max" => 3,
            "#default_value" => isset($ui["decimals"])
                ? (int) $ui["decimals"]
                : 1,
        ];

        $form["ui"]["theme_mode"] = [
            "#type" => "select",
            "#title" => $this->t("Theme mode"),
            "#options" => [
                "auto" => $this->t("Auto"),
                "light" => $this->t("Light"),
                "dark" => $this->t("Dark"),
            ],
            "#default_value" => $ui["theme_mode"] ?? "auto",
        ];

        $form["ui"]["primary_color"] = [
            "#type" => "textfield",
            "#title" => $this->t("Primary color (hex)"),
            "#default_value" => $ui["primary_color"] ?? "#1e88e5",
            "#description" => $this->t("e.g. #1e88e5"),
        ];

        $form["ui"]["show_today_energy"] = [
            "#type" => "checkbox",
            "#title" => $this->t("Show today's energy (kWh)"),
            "#default_value" => (bool) ($ui["show_today_energy"] ?? true),
        ];

        $form["ui"]["show_sparkline"] = [
            "#type" => "checkbox",
            "#title" => $this->t("Show sparkline in block"),
            "#default_value" => (bool) ($ui["show_sparkline"] ?? false),
        ];

        $form["ui"]["show_link"] = [
            "#type" => "checkbox",
            "#title" => $this->t("Show link to full view (in block)"),
            "#default_value" => (bool) ($ui["show_link"] ?? true),
        ];

        $form["ui"]["show_timestamp"] = [
            "#type" => "checkbox",
            "#title" => $this->t("Show last update timestamp (in block)"),
            "#default_value" => (bool) ($ui["show_timestamp"] ?? true),
        ];

        return $form;
    }

    /**
     * Submit handler for "Jetzt Daten abrufen".
     */
    public function submitFetchNow(
        array &$form,
        FormStateInterface $form_state,
    ): void {
        $providerId = (string) $this->settings()->get("provider");
        try {
            /** @var \Drupal\htl_pv_api\Service\PVDataProviderInterface $provider */
            $provider = \Drupal::service("htl_pv_api.provider");
            /** @var \Drupal\htl_pv_api\Service\PVRepository $repo */
            $repo = \Drupal::service("htl_pv_api.repository");

            $model = $provider->getCurrent();
            $repo->upsertSample($model, $providerId);

            $tz = new \DateTimeZone("UTC");
            $now = new \DateTime("now", $tz);
            $from = (clone $now)->setTime(0, 0, 0);
            $history = $provider->getHistory(
                $from->format(DATE_ATOM),
                $now->format(DATE_ATOM),
            );
            if (!empty($history)) {
                $repo->upsertSamples($history, $providerId);
            }

            \Drupal::state()->set("htl_pv_api.cron_last_run", time());

            $this->messenger()->addStatus(
                $this->t(
                    "Daten erfolgreich abgerufen: @w W (+ @hist Verlaufspunkte gespeichert).",
                    [
                        "@w" => number_format($model->power_w ?? 0, 1),
                        "@hist" => count($history),
                    ],
                ),
            );
        } catch (\Throwable $e) {
            $this->messenger()->addError(
                $this->t("Abruf fehlgeschlagen: @msg", [
                    "@msg" => $e->getMessage(),
                ]),
            );
        }
    }

    protected function submitSettingsForm(
        array &$form,
        FormStateInterface $form_state,
    ): void {
        $ui = $form_state->getValue("ui") ?: [];
        $this->settings()
            ->set("provider", "prism")
            ->set("prism_base_url", $form_state->getValue("prism_base_url"))
            ->set("poll_interval", (int) $form_state->getValue("poll_interval"))
            ->set("cron_enabled", (bool) $form_state->getValue("cron_enabled"))
            ->set("cron_interval", (int) $form_state->getValue("cron_interval"))
            ->set(
                "data_retention_days",
                (int) $form_state->getValue("data_retention_days"),
            )
            ->set("ui", [
                "layout" => $ui["layout"] ?? "standard",
                "units" => $ui["units"] ?? "auto",
                "decimals" => isset($ui["decimals"])
                    ? (int) $ui["decimals"]
                    : 1,
                "theme_mode" => $ui["theme_mode"] ?? "auto",
                "primary_color" => $ui["primary_color"] ?? "#1e88e5",
                "show_today_energy" => !empty($ui["show_today_energy"]),
                "show_timestamp" => !empty($ui["show_timestamp"]),
                "show_link" => !empty($ui["show_link"]),
                "show_sparkline" => !empty($ui["show_sparkline"]),
            ])
            ->save();
    }
}
