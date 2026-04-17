<?php

declare(strict_types=1);

namespace Drupal\htl_pv_api\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\htl_core\Trait\HtlLoggerTrait;
use Drupal\htl_pv_api\Model\PVSample;

/**
 * HTTP client for the PV mock API.
 *
 * Field mapping (API keys, labels, units) is driven by PVFieldMap.
 */
class PVClient
{
    use HtlLoggerTrait;

    protected string $baseUrl;
    protected string $liveEndpointPath;

    public function __construct(
        ConfigFactoryInterface $configFactory,
        protected readonly PVFieldMap $fieldMap,
    ) {
        $this->baseUrl = rtrim(
            $configFactory->get("htl_pv_api.settings")->get("api_base_url") ??
                "http://localhost:4010",
            "/",
        );
        $this->liveEndpointPath = $this->normalizePath(
            (string) ($configFactory
                ->get("htl_pv_api.settings")
                ->get("live_endpoint_path") ?? "/pv/live"),
        );
    }

    /**
     * Fetch current live measurement.
     */
    public function fetchLive(): PVSample
    {
        $d = $this->fetchLivePayload();

        $tsKey = $this->fieldMap->getTimestampKey();
        $rawTimestamp = $this->fieldMap->extractValue($d, $tsKey);
        try {
            $ts = $this->parseTimestamp($rawTimestamp);
        } catch (\Throwable $e) {
            $ts = new \DateTime();
        }

        return new PVSample(
            ["sampled_at" => $ts] + $this->fieldMap->mapApiResponse($d),
        );
    }

    /**
     * Fetch the raw live JSON payload from the configured endpoint.
     */
    public function fetchLivePayload(): array
    {
        return $this->get($this->liveEndpointPath);
    }

    // -----------------------------------------------------------------------

    /**
     * Perform GET with Docker host fallback.
     */
    private function get(string $path, array $query = []): array
    {
        $suffix =
            $path . (!empty($query) ? "?" . http_build_query($query) : "");
        $lastErr = null;

        foreach ($this->candidateUrls() as $base) {
            $url = $base . $suffix;
            try {
                $resp = \Drupal::httpClient()->request("GET", $url, [
                    "headers" => [
                        "Accept" => "application/json",
                        "User-Agent" => "htl_pv_api/2.0",
                    ],
                    "timeout" => 5,
                    "http_errors" => true,
                ]);
                $body = json_decode((string) $resp->getBody(), true);
                if (!is_array($body)) {
                    throw new \RuntimeException("Invalid JSON from " . $url);
                }
                $this->baseUrl = $base;
                return $body;
            } catch (\Throwable $e) {
                $this->htlNotice("htl_pv_api", "PVClient: tried @url – @msg", [
                    "@url" => $url,
                    "@msg" => $e->getMessage(),
                ]);
                $lastErr = $e;
            }
        }

        throw new \RuntimeException(
            "PVClient: all hosts failed for " .
                $path .
                ". Last: " .
                $lastErr?->getMessage(),
        );
    }

    /**
     * Build candidate base URLs (Docker networking fallback).
     */
    private function candidateUrls(): array
    {
        $candidates = [$this->baseUrl];
        if (
            preg_match(
                '#^(https?://)(?:localhost|127\.\d+\.\d+\.\d+)(:\d+)?(.*)$#',
                $this->baseUrl,
                $m,
            )
        ) {
            $s = $m[1];
            $p = $m[2] ?? "";
            $t = $m[3] ?? "";
            $candidates[] = $s . "host.docker.internal" . $p . $t;
            $candidates[] = $s . "172.17.0.1" . $p . $t;
            $candidates[] = $s . "mock-api" . $p . $t;
        }
        return array_unique($candidates);
    }

    private function normalizePath(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === "") {
            return "/pv/live";
        }

        return str_starts_with($trimmed, "/") ? $trimmed : "/" . $trimmed;
    }

    private function parseTimestamp(mixed $rawTimestamp): \DateTime
    {
        if ($rawTimestamp === null || $rawTimestamp === "") {
            return new \DateTime();
        }

        if (is_int($rawTimestamp) || is_float($rawTimestamp)) {
            return new \DateTime("@" . (int) $rawTimestamp)->setTimezone(
                new \DateTimeZone("UTC"),
            );
        }

        $stringValue = trim((string) $rawTimestamp);
        if ($stringValue !== "" && preg_match('/^\d+$/', $stringValue)) {
            return new \DateTime("@" . (int) $stringValue)->setTimezone(
                new \DateTimeZone("UTC"),
            );
        }

        return new \DateTime($stringValue);
    }
}
