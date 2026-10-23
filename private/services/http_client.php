<?php

declare(strict_types=1);

/**
 * Minimal HTTP GET/POST with timeouts, retries on transient errors, and 429 backoff.
 *
 * @return array{ok:bool,status:int,body:string,json:mixed,error:?string,attempts:int}
 */
function http_request(string $method, string $url, array $options = []): array
{
    $method = strtoupper($method);
    $headers = $options['headers'] ?? [];
    $body = $options['body'] ?? null;
    $timeout = (int) ($options['timeout'] ?? 30);
    $maxAttempts = max(1, (int) ($options['max_attempts'] ?? 3));

    if (!extension_loaded('curl')) {
        return [
            'ok' => false,
            'status' => 0,
            'body' => '',
            'json' => null,
            'error' => 'PHP cURL extension is not enabled.',
            'attempts' => 0,
        ];
    }

    $attempt = 0;
    $lastError = null;
    $status = 0;
    $responseBody = '';

    while ($attempt < $maxAttempts) {
        $attempt++;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);

        if ($body !== null && $method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($responseBody === '' && $curlErr !== '') {
            $lastError = $curlErr;
            usleep(200000 * $attempt);
            continue;
        }

        if ($status === 429 || ($status >= 500 && $status <= 599)) {
            $lastError = $status === 429 ? 'Rate limited (HTTP 429).' : 'Server error HTTP ' . $status;
            usleep(500000 * $attempt);
            continue;
        }

        break;
    }

    $decoded = null;
    if ($responseBody !== '') {
        try {
            $decoded = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $decoded = null;
        }
    }

    $ok = $status >= 200 && $status < 300;

    return [
        'ok' => $ok,
        'status' => $status,
        'body' => $responseBody,
        'json' => $decoded,
        'error' => $ok ? null : ($lastError ?? ('HTTP ' . $status)),
        'attempts' => $attempt,
    ];
}

function http_get(string $url, array $headers = [], int $timeout = 30): array
{
    return http_request('GET', $url, ['headers' => $headers, 'timeout' => $timeout]);
}

function http_post_json(string $url, array $payload, array $headers = [], int $timeout = 30): array
{
    $headers = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);

    return http_request('POST', $url, [
        'headers' => $headers,
        'body' => json_encode($payload, JSON_THROW_ON_ERROR),
        'timeout' => $timeout,
    ]);
}
