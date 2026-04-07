<?php

declare(strict_types=1);

define('APP_SHARED_PATH', dirname(__DIR__) . '/shared');
define('APP_STORAGE_PATH', dirname(__DIR__) . '/storage');
define('POE_BASE_URL', 'https://www.pathofexile.com');
define('POE_USER_AGENT', 'gemEval-local/1.0');
define('POE_DEFAULT_TRADE_STATUS', 'securable');
define('POE_DEFAULT_PRICE_CURRENCY', 'chaos');
define('POE_DEFAULT_CORRUPTED_OPTION', 'no');
define('POE_GCP_CURRENCY_ID', 'gcp');
define('POE_GCP_BUNDLE_SIZE', 20);
define('POE_CONNECT_TIMEOUT_SECONDS', 10);
define('POE_REQUEST_TIMEOUT_SECONDS', 25);
define('POE_GEM_CATALOG_CACHE_TTL', 21600);
define('POE_LEAGUE_CACHE_TTL', 900);
define('POE_GCP_CACHE_TTL', 30);
define('POE_GEM_PRICE_CACHE_TTL', 30);
define('POE_SIDEBAR_GEMS_CACHE_TTL', 90);
define('POE_SIDEBAR_GEMS_FETCH_LIMIT', 40);
define('POE_SIDEBAR_GEMS_RESULT_LIMIT', 10);
define('POE_SIDEBAR_GEMS_MIN_LEVEL', 20);
define('POE_SIDEBAR_GEMS_MIN_QUALITY', 20);
define('POE_SIDEBAR_GEMS_MAX_PRICE_CHAOS', 3.0);

define('POE_GEM_VARIANTS', [
    ['level' => 1, 'quality' => 0],
    ['level' => 1, 'quality' => 20],
    ['level' => 20, 'quality' => 0],
    ['level' => 20, 'quality' => 20],
]);

define('POE_RATE_LIMIT_POLICIES', [
    'trade-search-request-limit' => [
        ['limit' => 5, 'period' => 10],
        ['limit' => 15, 'period' => 60],
        ['limit' => 30, 'period' => 300],
    ],
    'trade-fetch-request-limit' => [
        ['limit' => 12, 'period' => 4],
        ['limit' => 16, 'period' => 12],
    ],
    'trade-exchange-request-limit' => [
        ['limit' => 5, 'period' => 15],
        ['limit' => 10, 'period' => 90],
        ['limit' => 30, 'period' => 300],
    ],
]);
