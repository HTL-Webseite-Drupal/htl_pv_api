<?php

declare(strict_types=1);

namespace Drupal\htl_pv_api\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\htl_core\Form\HtlSettingsFormBase;
use Drupal\htl_pv_api\Service\PVFieldMap;

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
        $store = \Drupal::service("htl_pv_api.store");
        $fieldMap = (array) ($cfg->get("field_map") ?? []);
        $last = (int) \Drupal::state()->get("htl_pv_api.cron_last_run", 0);
        $lastLabel = $last
            ? \Drupal::service("date.formatter")->format($last, "short")
            : (string) $this->t("Nie");
        $overviewLink = Link::fromTextAndUrl(
            $this->t("PV API Overview"),
            Url::fromRoute("htl_pv_api.overview"),
        )->toString();
        $inspectorLink = Link::fromTextAndUrl(
            $this->t("PV API Inspector"),
            Url::fromRoute("htl_pv_api.inspector"),
        )->toString();

        $form["intro"] = [
            "#type" => "markup",
            "#markup" =>
                "<p>" .
                $this->t(
                    "Use this page to configure the live endpoint, collection behavior, display options, and JSON field mapping.",
                ) .
                " " .
                $this->t("For a high-level module summary use") .
                " " .
                $overviewLink .
                ". " .
                $this->t("For unknown production payloads use") .
                " " .
                $inspectorLink .
                ".</p>",
        ];

        // --- Status -----------------------------------------------------------
        $form["status"] = [
            "#type" => "details",
            "#title" => $this->t("Status und Schnellaktionen"),
            "#open" => true,
        ];
        $form["status"]["summary"] = [
            "#type" => "table",
            "#header" => [$this->t("Metrik"), $this->t("Wert")],
            "#rows" => [
                [$this->t("Gespeicherte Samples"), (string) $store->count()],
                [$this->t("Letzter Abruf"), $lastLabel],
                [
                    $this->t("API Base URL"),
                    (string) ($cfg->get("api_base_url") ??
                        "http://mock-api:4010"),
                ],
                [
                    $this->t("Live-Endpunkt"),
                    (string) ($cfg->get("live_endpoint_path") ?? "/pv/live"),
                ],
            ],
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
        $form["connection"] = [
            "#type" => "details",
            "#title" => $this->t("API-Verbindung"),
            "#open" => true,
            "#tree" => true,
            "#description" => $this->t(
                "Definiere hier, wo die Live-Daten abgeholt werden. Diese Werte sind der erste Schritt beim Umstellen auf eine spaetere Produktions-API.",
            ),
        ];
        $form["connection"]["api_base_url"] = [
            "#type" => "textfield",
            "#title" => $this->t("API Base URL"),
            "#default_value" =>
                $cfg->get("api_base_url") ?? "http://mock-api:4010",
            "#description" => $this->t(
                "Basis-URL der PV-API (z.B. Node.js mock API). Docker-Fallback ist automatisch.",
            ),
            "#required" => true,
        ];
        $form["connection"]["live_endpoint_path"] = [
            "#type" => "textfield",
            "#title" => $this->t("Live endpoint path"),
            "#default_value" => $cfg->get("live_endpoint_path") ?? "/pv/live",
            "#description" => $this->t(
                "Pfad fuer den Live-Endpunkt relativ zur Base-URL, z.B. /pv/live oder /solar/realtime.",
            ),
            "#required" => true,
        ];

        // --- JSON field mapping -----------------------------------------------
        $form["field_map"] = [
            "#type" => "details",
            "#title" => $this->t("JSON Feld-Mapping"),
            "#tree" => true,
            "#open" => false,
            "#description" => $this->t(
                "Lege hier fest, wo die Werte im API-JSON gefunden werden. Unterstuetzt einfache Keys, verschachtelte Pfade wie <code>data.live.power</code> und Array-Zugriffe wie <code>results[0].value</code>. Nutze den PV API Inspector, um das aktuelle JSON und alle erkannten Pfade zu sehen.",
            ),
        ];
        $form["field_map"]["timestamp_key"] = [
            "#type" => "textfield",
            "#title" => $this->t("Zeitstempel-Pfad"),
            "#default_value" =>
                $fieldMap["timestamp_key"] ??
                PVFieldMap::DEFAULTS["timestamp_key"],
            "#description" => $this->t(
                "JSON-Key oder Pfad fuer den Zeitstempel. ISO-8601 und Unix-Timestamps werden unterstuetzt.",
            ),
            "#required" => true,
        ];
        $form["field_map"]["summary"] = [
            "#type" => "table",
            "#header" => [
                $this->t("Internes Feld"),
                $this->t("Anzeige"),
                $this->t("JSON-Pfad"),
                $this->t("Aktiv"),
            ],
            "#rows" => $this->buildFieldMapSummaryRows($fieldMap),
            "#caption" => $this->t("Aktuelle Mapping-Uebersicht"),
        ];

        foreach (PVFieldMap::FIELD_NAMES as $field) {
            $definition =
                $fieldMap["fields"][$field] ??
                PVFieldMap::DEFAULTS["fields"][$field];
            $form["field_map"]["fields"][$field] = [
                "#type" => "details",
                "#title" => $this->t("@title (@field)", [
                    "@title" => $this->getFieldTitle($field),
                    "@field" => $field,
                ]),
                "#open" => false,
            ];
            $form["field_map"]["fields"][$field]["api_key"] = [
                "#type" => "textfield",
                "#title" => $this->t("JSON-Pfad"),
                "#default_value" => $definition["api_key"] ?? "",
                "#description" => $this->t(
                    "Pfad zum Rohwert im JSON, z.B. pv_power_w oder data.live.metrics.power.",
                ),
            ];
            $form["field_map"]["fields"][$field]["label"] = [
                "#type" => "textfield",
                "#title" => $this->t("Anzeige-Name"),
                "#default_value" => $definition["label"] ?? $field,
            ];
            $form["field_map"]["fields"][$field]["unit"] = [
                "#type" => "textfield",
                "#title" => $this->t("Einheit"),
                "#default_value" => $definition["unit"] ?? "",
            ];
            $form["field_map"]["fields"][$field]["scale"] = [
                "#type" => "number",
                "#title" => $this->t("Skalierungsfaktor"),
                "#step" => 0.001,
                "#default_value" => $definition["scale"] ?? 1,
            ];
            $form["field_map"]["fields"][$field]["enabled"] = [
                "#type" => "checkbox",
                "#title" => $this->t("Aktiviert"),
                "#default_value" => (bool) ($definition["enabled"] ?? true),
            ];
        }

        // --- Scheduling -------------------------------------------------------
        $form["scheduling"] = [
            "#type" => "details",
            "#title" => $this->t("Datensammlung"),
            "#open" => true,
            "#tree" => true,
            "#description" => $this->t(
                "Steuert, wie oft Daten im Browser und im Hintergrund gesammelt werden.",
            ),
        ];
        $form["scheduling"]["cron_enabled"] = [
            "#type" => "checkbox",
            "#title" => $this->t("Automatische Datensammlung (Cron)"),
            "#default_value" => (bool) ($cfg->get("cron_enabled") ?? false),
            "#description" => $this->t(
                "Aktiviert Abrufe per Drupal-Cron und per geschuetztem Modul-Endpunkt. Auf dem Server muss zusaetzlich ein echter Cronjob eingerichtet werden, z. B. mit crontab -e und dem Eintrag * * * * * curl -sf https://example.com/pvoutput/cron/IHR_CRON_KEY. Den aktuellen Schluessel erhalten Sie mit drush state:get htl_pv_api.cron_key.",
            ),
        ];
        $form["scheduling"]["cron_interval"] = [
            "#type" => "number",
            "#title" => $this->t("Cron-Intervall (Sekunden)"),
            "#default_value" => (int) ($cfg->get("cron_interval") ?? 60),
            "#min" => 10,
            "#states" => [
                "visible" => [
                    ':input[name="scheduling[cron_enabled]"]' => [
                        "checked" => true,
                    ],
                ],
            ],
        ];
        $form["scheduling"]["poll_interval"] = [
            "#type" => "number",
            "#title" => $this->t("Browser-Polling (Sekunden)"),
            "#default_value" => (int) ($cfg->get("poll_interval") ?? 15),
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

        // --- Display -----------------------------------------------------------
        $form["display"] = [
            "#type" => "details",
            "#title" => $this->t("Anzeige"),
            "#open" => false,
            "#tree" => true,
            "#description" => $this->t(
                "Einstellungen fuer Gauge und Diagramm-Darstellung.",
            ),
        ];
        $form["display"]["max_power_w"] = [
            "#type" => "number",
            "#title" => $this->t("Maximale PV-Leistung (W)"),
            "#default_value" => (int) ($cfg->get("max_power_w") ?? 10000),
            "#min" => 100,
            "#description" => $this->t(
                "Wert bei dem der Kreis vollständig gefüllt ist (z.B. 10000 = 10 kW Peak).",
            ),
        ];
        $form["display"]["chart_interval_minutes"] = [
            "#type" => "number",
            "#title" => $this->t("Diagramm-Intervall (Minuten)"),
            "#default_value" =>
                (int) ($cfg->get("chart_interval_minutes") ?? 15),
            "#min" => 1,
            "#max" => 15,
            "#description" => $this->t(
                "Groesse der Zeitbloecke im Tagesdiagramm.",
            ),
        ];

        // --- CSS-Klassen -----------------------------------------------------
        $form["appearance"] = [
            "#type" => "details",
            "#title" => $this->t("Darstellung anpassen"),
            "#tree" => true,
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
            $form["appearance"][$key] = [
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
        $client = \Drupal::service("htl_pv_api.client");
        /** @var \Drupal\htl_pv_api\Service\PVStore $store */
        $store = \Drupal::service("htl_pv_api.store");

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
        $fieldMap = (array) ($form_state->getValue("field_map") ?? []);
        $fieldDefinitions = [];
        foreach (PVFieldMap::FIELD_NAMES as $field) {
            $definition = (array) ($fieldMap["fields"][$field] ?? []);
            $defaults = PVFieldMap::DEFAULTS["fields"][$field];
            $fieldDefinitions[$field] = [
                "api_key" => trim(
                    (string) ($definition["api_key"] ?? $defaults["api_key"]),
                ),
                "label" => trim(
                    (string) ($definition["label"] ?? $defaults["label"]),
                ),
                "unit" => trim(
                    (string) ($definition["unit"] ?? $defaults["unit"]),
                ),
                "scale" => is_numeric($definition["scale"] ?? null)
                    ? (float) $definition["scale"]
                    : (float) $defaults["scale"],
                "enabled" => !empty($definition["enabled"]),
            ];
        }

        $this->settings()
            ->set(
                "api_base_url",
                $form_state->getValue(["connection", "api_base_url"]),
            )
            ->set(
                "live_endpoint_path",
                trim(
                    (string) ($form_state->getValue([
                        "connection",
                        "live_endpoint_path",
                    ]) ?? "/pv/live"),
                ),
            )
            ->set(
                "cron_enabled",
                (bool) $form_state->getValue(["scheduling", "cron_enabled"]),
            )
            ->set(
                "cron_interval",
                (int) $form_state->getValue(["scheduling", "cron_interval"]),
            )
            ->set(
                "poll_interval",
                (int) $form_state->getValue(["scheduling", "poll_interval"]),
            )
            ->set(
                "data_retention_days",
                (int) $form_state->getValue([
                    "scheduling",
                    "data_retention_days",
                ]),
            )
            ->set(
                "max_power_w",
                (int) $form_state->getValue(["display", "max_power_w"]),
            )
            ->set(
                "chart_interval_minutes",
                (int) $form_state->getValue([
                    "display",
                    "chart_interval_minutes",
                ]),
            )
            ->set(
                "css_card_bg",
                trim(
                    $form_state->getValue(["appearance", "css_card_bg"]) ?? "",
                ),
            )
            ->set(
                "css_gauge_arc",
                trim(
                    $form_state->getValue(["appearance", "css_gauge_arc"]) ??
                        "",
                ),
            )
            ->set(
                "css_gauge_label",
                trim(
                    $form_state->getValue(["appearance", "css_gauge_label"]) ??
                        "",
                ),
            )
            ->set(
                "css_value_number",
                trim(
                    $form_state->getValue(["appearance", "css_value_number"]) ??
                        "",
                ),
            )
            ->set(
                "css_value_unit",
                trim(
                    $form_state->getValue(["appearance", "css_value_unit"]) ??
                        "",
                ),
            )
            ->set("field_map", [
                "timestamp_key" => trim(
                    (string) ($fieldMap["timestamp_key"] ??
                        PVFieldMap::DEFAULTS["timestamp_key"]),
                ),
                "fields" => $fieldDefinitions,
            ])
            ->save();
    }

    /**
     * @param array<string, mixed> $fieldMap
     *
     * @return array<int, array<int, string>>
     */
    private function buildFieldMapSummaryRows(array $fieldMap): array
    {
        $rows = [];

        foreach (PVFieldMap::FIELD_NAMES as $field) {
            $definition =
                $fieldMap["fields"][$field] ??
                PVFieldMap::DEFAULTS["fields"][$field];
            $rows[] = [
                $this->getFieldTitle($field),
                (string) ($definition["label"] ?? $field),
                (string) ($definition["api_key"] ?? ""),
                !empty($definition["enabled"])
                    ? (string) $this->t("Ja")
                    : (string) $this->t("Nein"),
            ];
        }

        return $rows;
    }

    private function getFieldTitle(string $field): string
    {
        return match ($field) {
            "power_w" => (string) $this->t("PV-Erzeugung"),
            "grid_power_w" => (string) $this->t("Netzleistung"),
            "house_consumption_w" => (string) $this->t("Hausverbrauch"),
            "energy_wh_total" => (string) $this->t("Gesamtenergie"),
            default => $field,
        };
    }
}
