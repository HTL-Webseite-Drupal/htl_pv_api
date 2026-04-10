<?php

namespace Drupal\htl_pv_api\Service;

use Drupal\htl_pv_api\Model\PVSample;

/**
 * HTTP client for the PV mock API.
 *
 * Field mapping (API keys, labels, units) is driven by PVFieldMap.
 */
class PVClient
{
    protected string $baseUrl;
    protected PVFieldMap $fieldMap;

    public function __construct(string $baseUrl, ?PVFieldMap $fieldMap = null)
    {
        $this->baseUrl = rtrim($baseUrl, "/");
        $this->fieldMap =
            $fieldMap ?? new PVFieldMap(\Drupal::service("config.factory"));
    }

    /**
     * Fetch current live measurement.
     */
    public function fetchLive(): PVSample
    {
        $d = $this->get("/pv/live");

        $tsKey = $this->fieldMap->getTimestampKey();
        try {
            $ts = !empty($d[$tsKey])
                ? new \DateTime($d[$tsKey])
                : new \DateTime();
        } catch (\Throwable $e) {
            $ts = new \DateTime();
        }

        return new PVSample(
            ["sampled_at" => $ts] + $this->fieldMap->mapApiResponse($d),
        );
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
                \Drupal::logger("htl_pv_api")->notice(
                    "PVClient: tried @url – @msg",
                    ["@url" => $url, "@msg" => $e->getMessage()],
                );
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
}
