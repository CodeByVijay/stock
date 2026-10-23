# Instrument & market data sources

## Coverage policy

The portal stores only what was **imported or synced successfully**. It does **not** claim full NSE/BSE/MF universe coverage until you compare counts to a verified source list (`providers.php` → `coverage_claim_complete` remains false by default).

## Mutual funds — MFapi.in

- Scheme list: `GET https://api.mfapi.in/mf?limit=&offset=` (paginated).
- NAV history: `GET https://api.mfapi.in/mf/{scheme_code}?startDate=&endDate=`
- Rate limits: use Sync Center / worker batches; throttling is built into `mfapi.php`.

## Stocks & ETFs — Upstox BOD JSON (recommended when API is connected)

Upstox publishes **beginning-of-day** instrument files (no OAuth required for the CDN):

| Exchange | URL |
|----------|-----|
| NSE | `https://assets.upstox.com/market-quote/instruments/exchange/NSE.json.gz` |
| BSE | `https://assets.upstox.com/market-quote/instruments/exchange/BSE.json.gz` |

Official docs: [Upstox Instruments](https://upstox.com/developer/api-documentation/instruments)

**Market Data Center → Import instruments**

- **NSE stocks (Upstox master)** — `segment = NSE_EQ`, `instrument_type = EQ` (excludes F&O and indices).
- **BSE stocks (Upstox master)** — `segment = BSE_EQ`, `instrument_type` = BSE cash series (e.g. **A**, B, T, M, X — **not** `EQ`; NSE uses `EQ`).
- **NSE ETFs (Upstox master)** — `segment = NSE_EQ`, `instrument_type = ETF`.

Each row stores `trading_symbol`, company `name`, `exchange`, `isin`, and **`instrument_key`** (required for Upstox price sync).

CLI:

```bash
php private/cli/import-upstox-instruments.php --exchange=NSE --type=stock
php private/cli/import-upstox-instruments.php --exchange=NSE --type=etf
```

**If the CDN returns HTTP 403** from your server (firewall/geo block):

1. Download `NSE.json.gz` / `BSE.json.gz` manually from the URLs above.
2. Save on the server (outside web root when possible).
3. Set in `private/config/providers.php`:
   - `upstox_instruments_nse_gz_path` → absolute path to local `NSE.json.gz`
   - `upstox_instruments_bse_gz_path` → absolute path to local `BSE.json.gz`
4. Re-run the import job or CLI.

## NSE / BSE equities — official CSV (fallback)

1. Download the current **equity master / securities list** from NSE and BSE official sites.
2. Save files on the server.
3. Set paths in `private/config/providers.php`:
   - `nse_equity_csv`
   - `bse_equity_csv`
4. Run **Import NSE stocks (CSV)** / **Import BSE stocks (CSV)** from Market Data Center.

Expected CSV headers (flexible aliases): `symbol`, `name`, optional `isin`, `status`, `category`, `instrument_type` (`stock`/`etf`).

**Note:** CSV import alone does not set Upstox `instrument_key` unless you extend the CSV pipeline; use Upstox master import for automatic price download.

## ETFs (CSV)

- Set `etf_master_csv` to a CSV with ETF rows (`instrument_type=etf`).
- Run **Import ETFs (CSV)**.

## Daily OHLCV without Upstox

- Set `equity_ohlcv_csv` to a CSV with: `symbol`, `exchange`, `trade_date`, `open`, `high`, `low`, `close`, `volume`.
- Queue **Import price history from file**.

## Upstox prices (after instruments exist)

- Requires a saved access token (Settings → Upstox).
- **Sync stocks** / **Latest stock prices** only update instruments that already have `provider_instrument_key`.
- Smoke test: `php private/cli/test-upstox-price-sync.php --type=stock`

## Intraday

- Only when Upstox intraday access is confirmed and `intraday_enabled` is true in `sync.php`.
