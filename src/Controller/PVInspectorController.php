<?php

declare(strict_types=1);

namespace Drupal\htl_pv_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\htl_pv_api\Service\PVClient;
use Drupal\htl_pv_api\Service\PVFieldMap;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin page for inspecting the current PV API payload.
 */
final class PVInspectorController extends ControllerBase
{
    public function __construct(
        private readonly PVClient $pvClient,
        private readonly PVFieldMap $fieldMap,
    ) {}

    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get("htl_pv_api.client"),
            $container->get("htl_pv_api.field_map"),
        );
    }

    public function overview(): array
    {
        $build["intro"] = [
            "#markup" =>
                "<p>" .
                $this->t(
                    "Use this page when the real inverter API is available. It fetches the current live JSON, lists every detected scalar path, and lets you compare that payload with your saved field mapping. Update the mapping in <code>/admin/config/htl/pv-api</code>.",
                ) .
                "</p><p>" .
                $this->t(
                    "Supported path syntax: <code>timestamp</code>, <code>data.live.power</code>, <code>results[0].value</code>.",
                ) .
                "</p>",
        ];

        try {
            $payload = $this->pvClient->fetchLivePayload();
        } catch (\Throwable $e) {
            $build["error"] = [
                "#type" => "status_messages",
            ];
            $this->messenger()->addError(
                $this->t("Could not fetch live API payload: @message", [
                    "@message" => $e->getMessage(),
                ]),
            );
            return $build;
        }

        $mappingRows = [
            [
                $this->t("Timestamp"),
                $this->fieldMap->getTimestampKey(),
                $this->formatPayloadValue(
                    $this->fieldMap->extractValue(
                        $payload,
                        $this->fieldMap->getTimestampKey(),
                    ),
                ),
            ],
        ];

        foreach (
            $this->fieldMap->getFieldDefinitions()
            as $field => $definition
        ) {
            $mappingRows[] = [
                $field,
                $definition["api_key"],
                $this->formatPayloadValue(
                    $this->fieldMap->extractValue(
                        $payload,
                        $definition["api_key"],
                    ),
                ),
            ];
        }

        $build["mapping"] = [
            "#type" => "table",
            "#header" => [
                $this->t("Internal field"),
                $this->t("Configured JSON path"),
                $this->t("Resolved value"),
            ],
            "#rows" => $mappingRows,
            "#empty" => $this->t("No mapping configured."),
            "#caption" => $this->t("Current mapping preview"),
        ];

        $pathRows = [];
        foreach ($this->fieldMap->describePayload($payload) as $row) {
            $pathRows[] = [$row["path"], $row["type"], $row["value"]];
        }

        $build["paths"] = [
            "#type" => "table",
            "#header" => [
                $this->t("JSON path"),
                $this->t("Type"),
                $this->t("Sample value"),
            ],
            "#rows" => $pathRows,
            "#empty" => $this->t("No scalar values found in the payload."),
            "#caption" => $this->t("Detected scalar paths"),
        ];

        $build["payload"] = [
            "#markup" =>
                "<h2>" .
                $this->t("Raw JSON payload") .
                "</h2><pre>" .
                htmlspecialchars(
                    (string) json_encode(
                        $payload,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                    ),
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    "UTF-8",
                ) .
                "</pre>",
        ];

        return $build;
    }

    private function formatPayloadValue(mixed $value): string
    {
        if ($value === null) {
            return (string) $this->t("Missing");
        }

        if (is_bool($value)) {
            return $value ? "true" : "false";
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
    }
}
