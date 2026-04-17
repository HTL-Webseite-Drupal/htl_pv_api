<?php

namespace Drupal\htl_pv_api\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Central field mapping between API response keys and internal/UI names.
 *
 * This is the single place to change when the production API delivers
 * different field names, labels, units, or scaling factors.
 *
 * Configure at: /admin/config/htl/pv-api (Feld-Mapping section).
 */
class PVFieldMap
{
    /** All internal field names that PVSample and the DB table support. */
    const FIELD_NAMES = [
        "power_w",
        "grid_power_w",
        "house_consumption_w",
        "energy_wh_total",
    ];

    /**
     * Hard-coded fallback defaults.
     * Used when no config has been saved yet.
     */
    const DEFAULTS = [
        "timestamp_key" => "timestamp",
        "fields" => [
            "power_w" => [
                "api_key" => "pv_power_w",
                "label" => "PV-Erzeugung",
                "unit" => "kW",
                "scale" => 0.001,
                "enabled" => true,
            ],
            "grid_power_w" => [
                "api_key" => "grid_power_w",
                "label" => "Netzleistung",
                "unit" => "kW",
                "scale" => 0.001,
                "enabled" => true,
            ],
            "house_consumption_w" => [
                "api_key" => "consumption_w",
                "label" => "Hausverbrauch",
                "unit" => "kW",
                "scale" => 0.001,
                "enabled" => true,
            ],
            "energy_wh_total" => [
                "api_key" => "energy_wh_total",
                "label" => "Energie gesamt",
                "unit" => "kWh",
                "scale" => 0.001,
                "enabled" => false,
            ],
        ],
    ];

    protected array $map;

    public function __construct(ConfigFactoryInterface $config_factory)
    {
        $saved =
            $config_factory->get("htl_pv_api.settings")->get("field_map") ??
            [];
        $this->map = [
            "timestamp_key" =>
                $saved["timestamp_key"] ?? self::DEFAULTS["timestamp_key"],
            "fields" => [],
        ];

        foreach (self::FIELD_NAMES as $field) {
            $default = self::DEFAULTS["fields"][$field];
            $override = $saved["fields"][$field] ?? [];
            $this->map["fields"][$field] = $override + $default;
        }
    }

    /** The API JSON key that holds the sample timestamp. */
    public function getTimestampKey(): string
    {
        return $this->map["timestamp_key"];
    }

    /** The JSON key to read this field from the API response. */
    public function getApiKey(string $field): string
    {
        return $this->map["fields"][$field]["api_key"] ?? $field;
    }

    /** The human-readable label shown in the UI. */
    public function getLabel(string $field): string
    {
        return $this->map["fields"][$field]["label"] ?? $field;
    }

    /** The display unit (e.g. "kW", "%", "kWh"). */
    public function getUnit(string $field): string
    {
        return $this->map["fields"][$field]["unit"] ?? "";
    }

    /**
     * Multiply raw API value by this factor to get the display value.
     * Example: 0.001 converts Watts → kilowatts.
     */
    public function getScale(string $field): float
    {
        return (float) ($this->map["fields"][$field]["scale"] ?? 1.0);
    }

    /** Whether this field should be fetched from the API and stored. */
    public function isEnabled(string $field): bool
    {
        return (bool) ($this->map["fields"][$field]["enabled"] ?? true);
    }

    /**
     * Map a raw API response to internal field values (unscaled, raw from API).
     *
     * @param array $raw  Decoded JSON from the API.
     * @return array<string, float|null>  Internal field name => raw value.
     */
    public function mapApiResponse(array $raw): array
    {
        $result = [];
        foreach (self::FIELD_NAMES as $field) {
            if (!$this->isEnabled($field)) {
                $result[$field] = null;
                continue;
            }
            $key = $this->getApiKey($field);
            $result[$field] = isset($raw[$key]) ? (float) $raw[$key] : null;
        }
        return $result;
    }

    /**
     * Get all field definitions as a flat array (for templates and settings form).
     *
     * @return array<string, array{api_key: string, label: string, unit: string, scale: float, enabled: bool}>
     */
    public function getFieldDefinitions(): array
    {
        $defs = [];
        foreach (self::FIELD_NAMES as $field) {
            $defs[$field] = [
                "api_key" => $this->getApiKey($field),
                "label" => $this->getLabel($field),
                "unit" => $this->getUnit($field),
                "scale" => $this->getScale($field),
                "enabled" => $this->isEnabled($field),
            ];
        }
        return $defs;
    }
}
