<?php

declare(strict_types=1);

require_once PRIVATE_ROOT . '/services/instrument_master.php';

function research_stale_message(?string $fetchedAt, ?string $tradeOrNavDate, bool $isNav = false): ?string
{
    if ($fetchedAt === null || $fetchedAt === '') {
        return 'No fetch timestamp on record — data may be incomplete.';
    }

    try {
        $fetched = new DateTimeImmutable($fetchedAt);
        $now = new DateTimeImmutable('now');
        $fetchAgeHours = ($now->getTimestamp() - $fetched->getTimestamp()) / 3600;
        if ($fetchAgeHours > 48) {
            return 'Last fetch was more than 48 hours ago (' . $fetched->format('Y-m-d H:i') . ' UTC). Data may be stale.';
        }
    } catch (Exception $e) {
        return 'Could not evaluate data freshness.';
    }

    if ($tradeOrNavDate !== null && $tradeOrNavDate !== '') {
        try {
            $dataDate = new DateTimeImmutable($tradeOrNavDate);
            $today = new DateTimeImmutable('today');
            $daysOld = (int) floor(($today->getTimestamp() - $dataDate->getTimestamp()) / 86400);
            $threshold = $isNav ? 5 : 3;
            if ($daysOld > $threshold) {
                $label = $isNav ? 'NAV date' : 'Trade date';
                return $label . ' is older than expected (' . $dataDate->format('Y-m-d') . '). Market may be closed or sync pending.';
            }
        } catch (Exception $e) {
            // ignore
        }
    }

    return null;
}

/** @return list<array<string,mixed>> */
function research_list_instruments(PDO $pdo, string $type, string $search, int $limit, int $offset): array
{
    $sql = "SELECT i.id, i.symbol, i.name, i.exchange, i.provider, i.provider_instrument_key,
            dp.close AS last_close, dp.trade_date AS last_trade_date, dp.fetched_at AS last_fetched_at, dp.source AS last_source,
            EXISTS(SELECT 1 FROM watchlist w WHERE w.instrument_id = i.id) AS on_watchlist
        FROM instruments i
        LEFT JOIN daily_prices dp ON dp.id = (
            SELECT dp2.id FROM daily_prices dp2
            WHERE dp2.instrument_id = i.id ORDER BY dp2.trade_date DESC LIMIT 1
        )
        WHERE i.instrument_type = :type AND i.is_active = 1";
    $sql .= instrument_equity_list_sql_extra($type, 'i');

    $params = ['type' => $type];
    if ($search !== '') {
        $sql .= ' AND (i.symbol LIKE :q1 OR i.name LIKE :q2)';
        $like = '%' . $search . '%';
        $params['q1'] = $like;
        $params['q2'] = $like;
    }
    $sql .= " ORDER BY (CASE WHEN i.exchange = 'NSE' THEN 0 WHEN i.exchange = 'BSE' THEN 1 ELSE 2 END),
        (CASE WHEN TRIM(i.symbol) = '' THEN 1 ELSE 0 END), i.symbol ASC LIMIT :lim OFFSET :off";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue('off', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll() ?: [];
}

function research_count_instruments(PDO $pdo, string $type, string $search): int
{
    $sql = 'SELECT COUNT(*) FROM instruments i WHERE i.instrument_type = :type AND i.is_active = 1';
    $sql .= instrument_equity_list_sql_extra($type, 'i');
    $params = ['type' => $type];
    if ($search !== '') {
        $sql .= ' AND (i.symbol LIKE :q1 OR i.name LIKE :q2)';
        $like = '%' . $search . '%';
        $params['q1'] = $like;
        $params['q2'] = $like;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

/** Instruments that have at least one stored daily price row (for list coverage UI). */
function research_count_instruments_with_prices(PDO $pdo, string $type): int
{
    $sql = 'SELECT COUNT(DISTINCT i.id) FROM instruments i
        INNER JOIN daily_prices dp ON dp.instrument_id = i.id
        WHERE i.instrument_type = :type AND i.is_active = 1';
    $sql .= instrument_equity_list_sql_extra($type, 'i');
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['type' => $type]);

    return (int) $stmt->fetchColumn();
}

function research_get_instrument(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM instruments WHERE id = :id AND is_active = 1 LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function research_watchlist_has(PDO $pdo, int $instrumentId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM watchlist WHERE instrument_id = :id LIMIT 1');
    $stmt->execute(['id' => $instrumentId]);

    return (bool) $stmt->fetchColumn();
}

function research_watchlist_add(PDO $pdo, int $instrumentId): void
{
    $stmt = $pdo->prepare('INSERT IGNORE INTO watchlist (instrument_id) VALUES (:id)');
    $stmt->execute(['id' => $instrumentId]);
}

function research_watchlist_remove(PDO $pdo, int $instrumentId): void
{
    $stmt = $pdo->prepare('DELETE FROM watchlist WHERE instrument_id = :id');
    $stmt->execute(['id' => $instrumentId]);
}

function research_normalize_scheme_nav_filter(string $filter): string
{
    $f = strtolower(trim($filter));
    if ($f === 'with' || $f === 'without') {
        return $f;
    }

    return 'all';
}

function research_append_scheme_nav_filter(string &$sql, string $navFilter): void
{
    if ($navFilter === 'with') {
        $sql .= ' AND EXISTS (SELECT 1 FROM mutual_fund_nav n0 WHERE n0.scheme_id = s.id)';
    } elseif ($navFilter === 'without') {
        $sql .= ' AND NOT EXISTS (SELECT 1 FROM mutual_fund_nav n0 WHERE n0.scheme_id = s.id)';
    }
}

function research_count_schemes(PDO $pdo, string $search, string $navFilter = 'all'): int
{
    $navFilter = research_normalize_scheme_nav_filter($navFilter);
    $sql = 'SELECT COUNT(*) FROM mutual_fund_schemes s WHERE s.is_active = 1';
    $params = [];
    if ($search !== '') {
        $sql .= ' AND (s.scheme_name LIKE :q1 OR s.scheme_code LIKE :q2 OR s.fund_house LIKE :q3)';
        $like = '%' . $search . '%';
        $params['q1'] = $like;
        $params['q2'] = $like;
        $params['q3'] = $like;
    }
    research_append_scheme_nav_filter($sql, $navFilter);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

/** @return list<array<string,mixed>> */
function research_list_schemes(PDO $pdo, string $search, int $limit, int $offset, string $navFilter = 'all'): array
{
    $navFilter = research_normalize_scheme_nav_filter($navFilter);
    $sql = 'SELECT s.id, s.scheme_code, s.scheme_name, s.fund_house, s.scheme_category,
            n.nav AS last_nav, n.nav_date AS last_nav_date, n.fetched_at AS last_fetched_at, n.source AS last_source,
            (EXISTS (SELECT 1 FROM mutual_fund_nav nx WHERE nx.scheme_id = s.id)) AS has_nav
        FROM mutual_fund_schemes s
        LEFT JOIN mutual_fund_nav n ON n.id = (
            SELECT n2.id FROM mutual_fund_nav n2
            WHERE n2.scheme_id = s.id ORDER BY n2.nav_date DESC LIMIT 1
        )
        WHERE s.is_active = 1';
    $params = [];
    if ($search !== '') {
        $sql .= ' AND (s.scheme_name LIKE :q1 OR s.scheme_code LIKE :q2 OR s.fund_house LIKE :q3)';
        $like = '%' . $search . '%';
        $params['q1'] = $like;
        $params['q2'] = $like;
        $params['q3'] = $like;
    }
    research_append_scheme_nav_filter($sql, $navFilter);
    $sql .= ' ORDER BY s.scheme_name ASC LIMIT :lim OFFSET :off';

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue('off', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll() ?: [];
}

function research_get_scheme(PDO $pdo, int $schemeId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM mutual_fund_schemes WHERE id = :id AND is_active = 1 LIMIT 1');
    $stmt->execute(['id' => $schemeId]);
    $row = $stmt->fetch();

    return $row ?: null;
}
