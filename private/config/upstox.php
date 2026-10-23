<?php
/**
 * Upstox API settings — edit for your environment only.
 * Do not commit a real access token to source control.
 *
 * Obtain an access token via the Upstox developer portal / OAuth or token-request flow,
 * then paste it in access_token below (or set UPSTOX_ACCESS_TOKEN in the server environment).
 *
 * @see private/cli/upstox-auth-check.php
 * @see private/cli/upstox-request-token.php
 */
return [
    'api_base' => 'https://api.upstox.com',
    'client_id' => '74a5bf9d-0749-474b-91a3-61200893179e',
    'client_secret' => '6yyz9zt8cu',
    'access_token' => 'eyJ0eXAiOiJKV1QiLCJrZXlfaWQiOiJza192MS4wIiwiYWxnIjoiSFMyNTYifQ.eyJzdWIiOiI0RkJRTksiLCJqdGkiOiI2YWRhZWU0NDllZWVhODIzMmRkZWQ3YTUiLCJpc011bHRpQ2xpZW50IjpmYWxzZSwiaXNQbHVzUGxhbiI6ZmFsc2UsImlhdCI6MTc5MjczMjc0MCwiaXNzIjoidWRhcGktZ2F0ZXdheS1zZXJ2aWNlIiwiZXhwIjoxNzkyNzkyODAwfQ.RuaPyJk3eWunjBW8DWSO1ycaYPTpeFl-5kWi8lZunG8',
    /** Must match the redirect URI registered in the Upstox developer app (no trailing .php). */
    'redirect_uri' => 'http://172.16.13.56/stock/oauth/upstox/',
    'http_timeout_seconds' => 30,
    'meta' => [],
    /** Used by sync-upstox-daily.php when no --instrument-key is passed */
    'default_instrument_key' => '',
    'default_symbol' => '',
    'default_name' => '',
    'default_exchange' => 'NSE',
    'default_instrument_type' => 'stock',
];
