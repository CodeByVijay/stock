<?php

declare(strict_types=1);

const MFAPI_META_SOURCE = 'mfapi.in';

function mfapi_optional_meta_string($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    $s = trim((string) $value);
    if ($s === '' || strcasecmp($s, 'null') === 0) {
        return null;
    }

    return $s;
}

/**
 * Map MFapi NAV response `meta` object (see mfapi.in docs).
 *
 * @param array<string,mixed> $meta
 * @return array<string,mixed>
 */
function mfapi_extract_scheme_meta(array $meta): array
{
    $code = $meta['scheme_code'] ?? '';
    if (is_int($code) || is_float($code)) {
        $code = (string) $code;
    }

    return [
        'scheme_code' => trim((string) $code),
        'scheme_name' => trim((string) ($meta['scheme_name'] ?? '')),
        'fund_house' => mfapi_optional_meta_string($meta['fund_house'] ?? null),
        'scheme_category' => mfapi_optional_meta_string($meta['scheme_category'] ?? null),
        'scheme_type' => mfapi_optional_meta_string($meta['scheme_type'] ?? null),
        'isin_growth' => mfapi_optional_meta_string($meta['isin_growth'] ?? null),
        'isin_div_reinvestment' => mfapi_optional_meta_string($meta['isin_div_reinvestment'] ?? null),
    ];
}

/** @param array<string,mixed> $fields */
function mf_scheme_display_value($value, string $emptyLabel = 'Not available'): string
{
    if ($value === null) {
        return $emptyLabel;
    }
    $s = trim((string) $value);

    return $s === '' ? $emptyLabel : $s;
}

/**
 * @param array<string,mixed> $scheme
 * @param array<string,mixed>|null $coverage
 * @return list<array{label:string,value:string}>
 */
function mf_scheme_info_rows(array $scheme, ?array $coverage): array
{
    $earliest = $coverage['earliest_nav_date'] ?? null;
    $latestNavDate = $coverage['latest_nav_date'] ?? null;
    $source = $coverage['source'] ?? null;
    $fetched = $coverage['last_fetched_at'] ?? null;

    return [
        ['label' => 'Scheme name', 'value' => mf_scheme_display_value($scheme['scheme_name'] ?? null, 'Not available')],
        ['label' => 'Fund house', 'value' => mf_scheme_display_value($scheme['fund_house'] ?? null)],
        ['label' => 'Scheme code', 'value' => mf_scheme_display_value($scheme['scheme_code'] ?? null)],
        ['label' => 'Scheme type', 'value' => mf_scheme_display_value($scheme['scheme_type'] ?? null)],
        ['label' => 'Category', 'value' => mf_scheme_display_value($scheme['scheme_category'] ?? null)],
        ['label' => 'Growth ISIN', 'value' => mf_scheme_display_value($scheme['isin_growth'] ?? null)],
        ['label' => 'Dividend / reinvestment ISIN', 'value' => mf_scheme_display_value($scheme['isin_div_reinvestment'] ?? null)],
        ['label' => 'Official launch date', 'value' => 'Not provided by data source'],
        [
            'label' => 'Earliest available NAV date',
            'value' => $earliest ? (string) $earliest : 'Not available (sync NAV history first)',
        ],
        [
            'label' => 'Latest NAV date',
            'value' => $latestNavDate ? (string) $latestNavDate : 'Not available',
        ],
        ['label' => 'Metadata source', 'value' => mf_scheme_display_value($scheme['meta_source'] ?? null)],
        [
            'label' => 'Metadata last synced',
            'value' => !empty($scheme['meta_synced_at']) ? (string) $scheme['meta_synced_at'] : 'Not available',
        ],
        ['label' => 'NAV data source', 'value' => mf_scheme_display_value($source)],
        ['label' => 'NAV last fetched', 'value' => $fetched ? (string) $fetched : 'Not available'],
    ];
}

/** @return array<string,mixed>|null */
function research_get_scheme_nav_coverage(PDO $pdo, int $schemeId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM mf_nav_coverage WHERE scheme_id = :id LIMIT 1');
    $stmt->execute(['id' => $schemeId]);
    $row = $stmt->fetch();

    return $row ?: null;
}
