<?php

declare(strict_types=1);

namespace Drupal\htl_pv_api\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Central field mapping between API response paths and internal/UI names.
 *
 * This is the single place to change when the production API delivers
 * different field names, labels, units, or scaling factors.
 *
 * Configure at: /admin/config/htl/pv-api (JSON field mapping section).
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
                "api_key" => "house_consumption_w",
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
            $config_factory->get("htl_pv_api.settings")->get("field_map") ?? [];
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

    /** The API JSON key or dot path that holds the sample timestamp. */
    public function getTimestampKey(): string
    {
        return $this->map["timestamp_key"];
    }

    /** The JSON key or dot path to read this field from the API response. */
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
            $value = $this->extractValue($raw, $this->getApiKey($field));
            $result[$field] = is_numeric($value) ? (float) $value : null;
        }
        return $result;
    }

    /**
     * Extract a scalar value from a nested JSON structure using dot notation.
     *
     * Examples:
     * - timestamp
     * - data.live.pv_power_w
     * - results[0].power.value
     */
    public function extractValue(array $raw, string $path): mixed
    {
        $segments = $this->getPathSegments($path);
        if ($segments === []) {
            return null;
        }

        $current = $raw;
        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Returns scalar leaf values from the payload as a flat list of dot paths.
     *
     * @return array<int, array{path: string, type: string, value: string}>
     *   Each row contains a dot path, detected scalar type, and sample value.
     */
    public function describePayload(array $payload): array
    {
        $rows = [];
        $this->collectPayloadRows($payload, "", $rows);
        return $rows;
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

    /**
     * @return list<string>
     */
    private function getPathSegments(string $path): array
    {
        $normalized = trim($path);
        if ($normalized === "") {
            return [];
        }

        $normalized =
            preg_replace("/\[(\d+)\]/", '.$1', $normalized) ?? $normalized;
        $normalized = trim($normalized, ".");

        return array_values(
            array_filter(
                explode(".", $normalized),
                static fn(string $segment): bool => $segment !== "",
            ),
        );
    }

    /**
     * @param array<mixed> $value
     * @param array<int, array{path: string, type: string, value: string}> $rows
     */
    private function collectPayloadRows(
        array $value,
        string $prefix,
        array &$rows,
    ): void {
        foreach ($value as $key => $item) {
            $path = $prefix === "" ? (string) $key : $prefix . "." . $key;

            if (is_array($item)) {
                if ($item === []) {
                    $rows[] = [
                        "path" => $path,
                        "type" => "array",
                        "value" => "[]",
                    ];
                    continue;
                }

                $this->collectPayloadRows($item, $path, $rows);
                continue;
            }

            $rows[] = [
                "path" => $path,
                "type" => $this->describeValueType($item),
                "value" => $this->stringifyValue($item),
            ];
        }
    }

    private function describeValueType(mixed $value): string
    {
        return match (true) {
            is_int($value) => "integer",
            is_float($value) => "float",
            is_bool($value) => "boolean",
            $value === null => "null",
            default => "string",
        };
    }

    private function stringifyValue(mixed $value): string
    {
        if ($value === null) {
            return "null";
        }

        if (is_bool($value)) {
            return $value ? "true" : "false";
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES) ?: "[unprintable]";
    }
}
