<?php

declare(strict_types=1);

namespace Drupal\htl_pv_api\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\htl_core\Form\HtlSettingsFormBase;

class PVSettingsForm extends HtlSettingsFormBase
{
    public function getFormId(): string
    {
        return "htl_pv_api_settings";
    }

    protected function getConfigName(): string
    {
        return "htl_pv_api.settings";
    }

    protected function buildSettingsForm(
        array $form,
        FormStateInterface $form_state,
    ): array {
        $cfg = $this->settings();
        $store = \Drupal::service('htl_pv_api.store');
        $last = (int) \Drupal::state()->get("htl_pv_api.cron_last_run", 0);

        // --- Status -----------------------------------------------------------
        $form["status"] = [
            "#type" => "fieldset",
            "#title" => $this->t("Status"),
        ];
        $form["status"]["info"] = [
            "#markup" =>
                "<p>" .
                $this->t("Gespeicherte Samples: <strong>@n</strong>", [
                    "@n" => $store->count(),
                ]) .
                "</p>" .
                "<p>" .
                $this->t("Letzter Abruf: <strong>@t</strong>", [
                    "@t" => $last
                        ? \Drupal::service("date.formatter")->format(
                            $last,
                            "short",
                        )
                        : $this->t("Nie"),
                ]) .
                "</p>",
        ];
        $form["status"]["fetch_now"] = [
            "#type" => "submit",
            "#value" => $this->t("Jetzt Daten abrufen"),
            "#name" => "fetch_now",
            "#submit" => ["::submitFetchNow"],
            "#limit_validation_errors" => [],
            "#button_type" => "primary",
        ];

        // --- API connection ---------------------------------------------------
        $form["api_base_url"] = [
            "#type" => "textfield",
            "#title" => $this->t("API Base URL"),
            "#default_value" =>
                $cfg->get("api_base_url") ?? "http://localhost:4010",
            "#description" => $this->t(
                "Basis-URL der PV-API (z.B. Node.js mock API). Docker-Fallback ist automatisch.",
            ),
            "#required" => true,
        ];

        // --- Scheduling -------------------------------------------------------
        $form["scheduling"] = [
            "#type" => "details",
            "#title" => $this->t("Datensammlung"),
            "#open" => true,
        ];
        $form["scheduling"]["cron_enabled"] = [
            "#type" => "checkbox",
            "#title" => $this->t("Automatische Datensammlung (Cron)"),
            "#default_value" => (bool) ($cfg->get("cron_enabled") ?? true),
        ];
        $form["scheduling"]["cron_interval"] = [
            "#type" => "number",
            "#title" => $this->t("Cron-Intervall (Sekunden)"),
            "#default_value" => (int) ($cfg->get("cron_interval") ?? 60),
            "#min" => 10,
            "#states" => [
                "visible" => [
                    ':input[name="cron_enabled"]' => ["checked" => true],
                ],
            ],
        ];
        $form["scheduling"]["poll_interval"] = [
            "#type" => "number",
            "#title" => $this->t("Browser-Polling (Sekunden)"),
            "#default_value" => (int) ($cfg->get("poll_interval") ?? 5),
            "#min" => 1,
            "#description" => $this->t(
                "Wie oft der Browser im Hintergrund neue Daten abruft.",
            ),
        ];
        $form["scheduling"]["data_retention_days"] = [
            "#type" => "number",
            "#title" => $this->t("Daten aufbewahren (Tage)"),
            "#default_value" => (int) ($cfg->get("data_retention_days") ?? 30),
            "#min" => 0,
            "#description" => $this->t(
                "Ältere Samples werden beim Cron-Lauf gelöscht. 0 = nie löschen.",
            ),
        ];

        // --- Gauge -----------------------------------------------------------
        $form["gauge"] = [
            "#type" => "details",
            "#title" => $this->t("Kreis-Anzeige"),
            "#open" => true,
        ];
        $form["gauge"]["max_power_w"] = [
            "#type" => "number",
            "#title" => $this->t("Maximale PV-Leistung (W)"),
            "#default_value" => (int) ($cfg->get("max_power_w") ?? 10000),
            "#min" => 100,
            "#description" => $this->t(
                "Wert bei dem der Kreis vollständig gefüllt ist (z.B. 10000 = 10 kW Peak).",
            ),
        ];

        // --- CSS-Klassen -----------------------------------------------------
        $form["css"] = [
            "#type" => "details",
            "#title" => $this->t("Extra CSS-Klassen"),
            "#description" => $this->t(
                "Optionale zusätzliche CSS-Klassen für einzelne Elemente (Block und Dashboard). Das Standard-Styling bleibt erhalten – du kannst damit Farben etc. in deinem Theme überschreiben.",
            ),
            "#open" => false,
        ];
        $cls = [
            "css_card_bg" => $this->t("Card-Hintergrund (extra Klasse)"),
            "css_gauge_arc" => $this->t(
                "Kreisbogen (extra Klasse, z.B. für Farbe)",
            ),
            "css_gauge_label" => $this->t('"Erzeugung"-Label (extra Klasse)'),
            "css_value_number" => $this->t("Leistungs-Zahl (extra Klasse)"),
            "css_value_unit" => $this->t("Einheit kW (extra Klasse)"),
        ];
        foreach ($cls as $key => $label) {
            $form["css"][$key] = [
                "#type" => "textfield",
                "#title" => $label,
                "#default_value" => $cfg->get($key) ?? "",
                "#size" => 30,
                "#description" => $this->t("Leer lassen = kein extra Style."),
            ];
        }

        return $form;
    }

    /**
     * "Jetzt Daten abrufen" handler.
     */
    public function submitFetchNow(
        array &$form,
        FormStateInterface $form_state,
    ): void {
        /** @var \Drupal\htl_pv_api\Service\PVClient $client */
        $client = \Drupal::service('htl_pv_api.client');
        /** @var \Drupal\htl_pv_api\Service\PVStore $store */
        $store = \Drupal::service('htl_pv_api.store');

        try {
            $live = $client->fetchLive();
            $store->upsert($live);

            \Drupal::state()->set("htl_pv_api.cron_last_run", time());
            $this->messenger()->addStatus(
                $this->t("Live-Daten abgerufen: @w W", [
                    "@w" => number_format($live->power_w ?? 0, 1),
                ]),
            );
        } catch (\Throwable $e) {
            $this->messenger()->addError(
                $this->t("Fehler: @msg", ["@msg" => $e->getMessage()]),
            );
        }
    }

    protected function submitSettingsForm(
        array &$form,
        FormStateInterface $form_state,
    ): void {
        $this->settings()
            ->set("api_base_url", $form_state->getValue("api_base_url"))
            ->set("cron_enabled", (bool) $form_state->getValue("cron_enabled"))
            ->set("cron_interval", (int) $form_state->getValue("cron_interval"))
            ->set("poll_interval", (int) $form_state->getValue("poll_interval"))
            ->set(
                "data_retention_days",
                (int) $form_state->getValue("data_retention_days"),
            )
            ->set("max_power_w", (int) $form_state->getValue("max_power_w"))
            ->set(
                "css_card_bg",
                trim($form_state->getValue("css_card_bg") ?? ""),
            )
            ->set(
                "css_gauge_arc",
                trim($form_state->getValue("css_gauge_arc") ?? ""),
            )
            ->set(
                "css_gauge_label",
                trim($form_state->getValue("css_gauge_label") ?? ""),
            )
            ->set(
                "css_value_number",
                trim($form_state->getValue("css_value_number") ?? ""),
            )
            ->set(
                "css_value_unit",
                trim($form_state->getValue("css_value_unit") ?? ""),
            )
            ->save();
    }
}
