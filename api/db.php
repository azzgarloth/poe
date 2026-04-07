<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function app_join_path(string ...$segments): string
{
    $cleaned = [];

    foreach ($segments as $index => $segment) {
        $normalized = str_replace('\\', '/', $segment);

        if ($index === 0) {
            $normalized = rtrim($normalized, '/');
        } else {
            $normalized = trim($normalized, '/');
        }

        if ($normalized !== '') {
            $cleaned[] = $normalized;
        }
    }

    return implode('/', $cleaned);
}

function app_ensure_directory(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException(sprintf('Unable to create directory: %s', $path));
    }
}

function app_storage_path(string $scope = ''): string
{
    if ($scope === '') {
        return APP_STORAGE_PATH;
    }

    return app_join_path(APP_STORAGE_PATH, $scope);
}

function app_cache_path(string $namespace, string $key): string
{
    return app_join_path(app_storage_path('cache/' . $namespace), sha1($key) . '.json');
}

function app_rate_limit_path(): string
{
    return app_join_path(app_storage_path('runtime'), 'poe-rate-limits.json');
}

function app_read_json_file(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function app_write_json_file(string $path, array $payload): void
{
    app_ensure_directory(dirname($path));

    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        throw new RuntimeException('Unable to encode JSON payload.');
    }

    if (file_put_contents($path, $encoded, LOCK_EX) === false) {
        throw new RuntimeException(sprintf('Unable to write file: %s', $path));
    }
}

function app_cache_remember(string $namespace, string $key, int $ttl, callable $resolver)
{
    $path = app_cache_path($namespace, $key);
    if ($ttl > 0 && is_file($path)) {
        $age = time() - (int) filemtime($path);
        if ($age >= 0 && $age <= $ttl) {
            $cached = app_read_json_file($path);
            if ($cached !== null && array_key_exists('value', $cached)) {
                return $cached['value'];
            }
        }
    }

    $value = $resolver();
    app_write_json_file($path, [
        'cachedAt' => gmdate(DATE_ATOM),
        'value' => $value,
    ]);

    return $value;
}

function app_parse_rate_limit_header(?string $headerValue): array
{
    if ($headerValue === null || trim($headerValue) === '') {
        return [];
    }

    $rules = [];
    foreach (explode(',', $headerValue) as $segment) {
        $parts = explode(':', trim($segment));
        if (count($parts) < 2) {
            continue;
        }

        $limit = (int) ($parts[0] ?? 0);
        $period = (int) ($parts[1] ?? 0);
        if ($limit > 0 && $period > 0) {
            $rules[] = [
                'limit' => $limit,
                'period' => $period,
            ];
        }
    }

    return $rules;
}

function app_get_rate_limit_rules(string $policyName): array
{
    $defaults = POE_RATE_LIMIT_POLICIES[$policyName] ?? [];
    $state = app_read_json_file(app_rate_limit_path());
    $storedRules = is_array($state[$policyName]['rules'] ?? null) ? $state[$policyName]['rules'] : [];

    return $storedRules !== [] ? $storedRules : $defaults;
}

function app_acquire_rate_limit_slot(string $policyName): void
{
    $rules = app_get_rate_limit_rules($policyName);
    if ($rules === []) {
        return;
    }

    $path = app_rate_limit_path();
    app_ensure_directory(dirname($path));
    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Unable to open rate limit state file.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to lock rate limit state file.');
        }

        $raw = stream_get_contents($handle);
        $state = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
        if (!is_array($state)) {
            $state = [];
        }

        $policyState = is_array($state[$policyName] ?? null) ? $state[$policyName] : [];
        $timestamps = array_values(array_filter(
            array_map('floatval', $policyState['timestamps'] ?? []),
            static fn (float $timestamp): bool => $timestamp > 0
        ));

        $maxPeriod = 0;
        foreach ($rules as $rule) {
            $maxPeriod = max($maxPeriod, (int) ($rule['period'] ?? 0));
        }

        $now = microtime(true);
        $timestamps = array_values(array_filter(
            $timestamps,
            static fn (float $timestamp): bool => ($now - $timestamp) < $maxPeriod
        ));

        $sleepSeconds = 0.0;
        foreach ($rules as $rule) {
            $limit = (int) ($rule['limit'] ?? 0);
            $period = (int) ($rule['period'] ?? 0);
            if ($limit <= 0 || $period <= 0) {
                continue;
            }

            $windowTimestamps = array_values(array_filter(
                $timestamps,
                static fn (float $timestamp): bool => ($now - $timestamp) < $period
            ));

            if (count($windowTimestamps) >= $limit) {
                sort($windowTimestamps, SORT_NUMERIC);
                $earliestRelevant = $windowTimestamps[count($windowTimestamps) - $limit];
                $sleepSeconds = max($sleepSeconds, ($earliestRelevant + $period + 0.05) - $now);
            }
        }

        if ($sleepSeconds > 0) {
            usleep((int) ceil($sleepSeconds * 1000000));
        }

        $now = microtime(true);
        $timestamps[] = $now;
        $state[$policyName] = [
            'updatedAt' => gmdate(DATE_ATOM),
            'rules' => $rules,
            'timestamps' => array_slice($timestamps, -100),
        ];

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($handle);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function app_sync_rate_limit_rules(string $policyName, array $headers): void
{
    $rules = app_parse_rate_limit_header($headers['x-rate-limit-ip'] ?? null);
    if ($rules === []) {
        return;
    }

    $path = app_rate_limit_path();
    app_ensure_directory(dirname($path));
    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Unable to open rate limit state file.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to lock rate limit state file.');
        }

        $raw = stream_get_contents($handle);
        $state = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
        if (!is_array($state)) {
            $state = [];
        }

        $policyState = is_array($state[$policyName] ?? null) ? $state[$policyName] : [];
        $policyState['updatedAt'] = gmdate(DATE_ATOM);
        $policyState['rules'] = $rules;
        $policyState['timestamps'] = $policyState['timestamps'] ?? [];
        $state[$policyName] = $policyState;

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($handle);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function app_extract_error_message(array $decodedBody, string $fallback): string
{
    $error = $decodedBody['error'] ?? null;
    if (is_array($error) && isset($error['message']) && is_string($error['message']) && trim($error['message']) !== '') {
        return trim($error['message']);
    }

    return $fallback;
}

function poe_request(string $method, string $path, ?array $payload = null, ?string $policyName = null, int $attempt = 0): array
{
    $method = strtoupper($method);
    if ($policyName !== null) {
        app_acquire_rate_limit_slot($policyName);
    }

    $url = str_starts_with($path, 'http') ? $path : POE_BASE_URL . $path;
    $responseHeaders = [];
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Unable to initialize cURL.');
    }

    $httpHeaders = [
        'Accept: application/json',
        'User-Agent: ' . POE_USER_AGENT,
    ];
    if ($payload !== null) {
        $httpHeaders[] = 'Content-Type: application/json';
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => POE_CONNECT_TIMEOUT_SECONDS,
        CURLOPT_TIMEOUT => POE_REQUEST_TIMEOUT_SECONDS,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $httpHeaders,
        CURLOPT_ENCODING => '',
        CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $headerLine) use (&$responseHeaders): int {
            $trimmed = trim($headerLine);
            $length = strlen($headerLine);
            if ($trimmed === '' || !str_contains($trimmed, ':')) {
                return $length;
            }

            [$name, $value] = explode(':', $trimmed, 2);
            $responseHeaders[strtolower(trim($name))] = trim($value);
            return $length;
        },
    ];

    if ($payload !== null) {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Unable to encode request payload.');
        }

        $options[CURLOPT_POSTFIELDS] = $encoded;
    }

    curl_setopt_array($ch, $options);
    $rawBody = curl_exec($ch);
    if ($rawBody === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException($error !== '' ? $error : 'Unknown cURL error.');
    }

    $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($policyName !== null) {
        app_sync_rate_limit_rules($policyName, $responseHeaders);
    }

    $decoded = json_decode($rawBody, true);
    if (!is_array($decoded)) {
        $decoded = [];
    }

    if ($statusCode === 429 && $attempt < 2) {
        $retryAfter = max(1, (int) ($responseHeaders['retry-after'] ?? 1));
        usleep($retryAfter * 1000000);
        return poe_request($method, $path, $payload, $policyName, $attempt + 1);
    }

    if ($statusCode >= 500 && $statusCode < 600 && $attempt < 1) {
        usleep(500000);
        return poe_request($method, $path, $payload, $policyName, $attempt + 1);
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        throw new RuntimeException(app_extract_error_message(
            $decoded,
            sprintf('PoE API request failed with status %d.', $statusCode)
        ));
    }

    return [
        'status' => $statusCode,
        'headers' => $responseHeaders,
        'body' => $decoded,
    ];
}

function poe_get_trade_league(): string
{
    return (string) app_cache_remember('poe-meta', 'trade-league', POE_LEAGUE_CACHE_TTL, static function (): string {
        $response = poe_request('GET', '/api/trade/data/leagues');
        $result = $response['body']['result'] ?? [];
        if (!is_array($result)) {
            return 'Standard';
        }

        foreach ($result as $league) {
            if (!is_array($league) || ($league['realm'] ?? '') !== 'pc') {
                continue;
            }

            $name = trim((string) ($league['text'] ?? ''));
            if ($name === '') {
                continue;
            }

            $isPermanent = in_array($name, ['Standard', 'Hardcore', 'Ruthless', 'Hardcore Ruthless'], true);
            $isHardcore = str_starts_with($name, 'Hardcore ') || str_starts_with($name, 'HC ');
            $isRuthless = str_contains($name, 'Ruthless');
            if (!$isPermanent && !$isHardcore && !$isRuthless) {
                return $name;
            }
        }

        return 'Standard';
    });
}

function poe_get_gem_catalog_entries(): array
{
    $catalog = app_cache_remember('poe-meta', 'gem-catalog-v2', POE_GEM_CATALOG_CACHE_TTL, static function (): array {
        $response = poe_request('GET', '/api/trade/data/items');
        $result = $response['body']['result'] ?? [];
        if (!is_array($result)) {
            return [];
        }

        $gemEntries = [];
        foreach ($result as $section) {
            if (is_array($section) && ($section['id'] ?? '') === 'gem') {
                $gemEntries = is_array($section['entries'] ?? null) ? $section['entries'] : [];
                break;
            }
        }

        $seen = [];
        $catalogEntries = [];
        foreach ($gemEntries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $type = trim((string) ($entry['type'] ?? ''));
            $displayName = trim((string) ($entry['text'] ?? $type));
            if ($type === '' || $displayName === '') {
                continue;
            }

            $normalized = strtolower($displayName);
            if (isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $catalogEntries[] = [
                'displayName' => $displayName,
                'type' => $type,
                'disc' => trim((string) ($entry['disc'] ?? '')),
            ];
        }

        usort($catalogEntries, static function (array $left, array $right): int {
            return strcasecmp((string) ($left['displayName'] ?? ''), (string) ($right['displayName'] ?? ''));
        });

        return array_values($catalogEntries);
    });

    return is_array($catalog) ? $catalog : [];
}

function poe_get_gem_catalog(): array
{
    $names = [];
    foreach (poe_get_gem_catalog_entries() as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $displayName = trim((string) ($entry['displayName'] ?? ''));
        if ($displayName !== '') {
            $names[] = $displayName;
        }
    }

    return $names;
}

function poe_find_gem_entry(string $candidate): ?array
{
    $candidate = trim($candidate);
    if ($candidate === '') {
        return null;
    }

    foreach (poe_get_gem_catalog_entries() as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $displayName = trim((string) ($entry['displayName'] ?? ''));
        if ($displayName !== '' && strcasecmp($displayName, $candidate) === 0) {
            return $entry;
        }
    }

    return null;
}

function poe_find_gem_name(string $candidate): ?string
{
    $entry = poe_find_gem_entry($candidate);
    if ($entry === null) {
        return null;
    }

    $displayName = trim((string) ($entry['displayName'] ?? ''));
    return $displayName !== '' ? $displayName : null;
}

function poe_filter_gem_suggestions(string $query, int $limit = 12): array
{
    $catalog = poe_get_gem_catalog();
    if ($query === '') {
        return array_slice($catalog, 0, max(1, $limit));
    }

    $queryLower = strtolower($query);
    $prefixMatches = [];
    $containsMatches = [];
    foreach ($catalog as $gemName) {
        $gemLower = strtolower($gemName);
        if (str_starts_with($gemLower, $queryLower)) {
            $prefixMatches[] = $gemName;
            continue;
        }

        if (str_contains($gemLower, $queryLower)) {
            $containsMatches[] = $gemName;
        }
    }

    return array_slice(array_merge($prefixMatches, $containsMatches), 0, max(1, $limit));
}

function poe_format_chaos_amount(?float $amount, string $fallback = 'n/a'): string
{
    if ($amount === null) {
        return $fallback;
    }

    $formatted = poe_format_number($amount);

    return $formatted . ' chaos';
}

function poe_format_number(float $amount): string
{
    $rounded = round($amount, 2);
    if (abs($rounded - round($rounded)) < 0.00001) {
        return number_format($rounded, 0, '.', '');
    }

    if (abs(($rounded * 10) - round($rounded * 10)) < 0.00001) {
        return number_format($rounded, 1, '.', '');
    }

    return number_format($rounded, 2, '.', '');
}

function poe_calculate_variant_cost_amount(int $quality, ?array $gcpBundle): ?float
{
    if ($quality === 0) {
        return 0.0;
    }

    if ($gcpBundle === null || !isset($gcpBundle['amount'])) {
        return null;
    }

    return round((float) $gcpBundle['amount'], 2);
}

function poe_fetch_currency_bundle_price(string $league, string $currencyId, float $quantity = 1.0): ?array
{
    if ($quantity <= 0) {
        return null;
    }

    if ($currencyId === POE_DEFAULT_PRICE_CURRENCY) {
        return [
            'quantity' => $quantity,
            'amount' => round($quantity, 2),
            'currency' => POE_DEFAULT_PRICE_CURRENCY,
            'display' => poe_format_chaos_amount($quantity),
        ];
    }

    $cacheKey = sprintf('%s|%s|%s|%s', $league, POE_DEFAULT_TRADE_STATUS, $currencyId, number_format($quantity, 4, '.', ''));

    $cached = app_cache_remember('currency-prices', $cacheKey, POE_GCP_CACHE_TTL, static function () use ($league, $currencyId, $quantity): ?array {
        $payload = [
            'query' => [
                'status' => ['option' => POE_DEFAULT_TRADE_STATUS],
                'want' => [$currencyId],
                'have' => [POE_DEFAULT_PRICE_CURRENCY],
            ],
            'sort' => ['have' => 'asc'],
        ];

        $exchangeResponse = poe_request(
            'POST',
            '/api/trade/exchange/' . rawurlencode($league),
            $payload,
            'trade-exchange-request-limit'
        );

        $result = $exchangeResponse['body']['result'] ?? [];
        if (!is_array($result)) {
            return null;
        }

        $offers = [];
        foreach ($result as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $listing = is_array($entry['listing'] ?? null) ? $entry['listing'] : [];
            $listingOffers = is_array($listing['offers'] ?? null) ? $listing['offers'] : [];

            foreach ($listingOffers as $offer) {
                if (!is_array($offer)) {
                    continue;
                }

                $exchange = is_array($offer['exchange'] ?? null) ? $offer['exchange'] : [];
                $item = is_array($offer['item'] ?? null) ? $offer['item'] : [];

                if (($exchange['currency'] ?? '') !== POE_DEFAULT_PRICE_CURRENCY || ($item['currency'] ?? '') !== $currencyId) {
                    continue;
                }

                $chaosAmount = isset($exchange['amount']) ? (float) $exchange['amount'] : 0.0;
                $targetAmount = isset($item['amount']) ? (float) $item['amount'] : 0.0;
                $stock = isset($item['stock']) ? (float) $item['stock'] : $targetAmount;

                if ($chaosAmount <= 0 || $targetAmount <= 0 || $stock <= 0) {
                    continue;
                }

                $offers[] = [
                    'unitPrice' => $chaosAmount / $targetAmount,
                    'stock' => $stock,
                ];
            }
        }

        if ($offers === []) {
            return null;
        }

        usort($offers, static function (array $left, array $right): int {
            $unitComparison = $left['unitPrice'] <=> $right['unitPrice'];
            if ($unitComparison !== 0) {
                return $unitComparison;
            }

            return $right['stock'] <=> $left['stock'];
        });

        $remaining = $quantity;
        $total = 0.0;

        foreach ($offers as $offer) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, (float) $offer['stock']);
            if ($take <= 0) {
                continue;
            }

            $total += $offer['unitPrice'] * $take;
            $remaining -= $take;
        }

        if ($remaining > 0) {
            return null;
        }

        return [
            'quantity' => $quantity,
            'amount' => round($total, 2),
            'currency' => POE_DEFAULT_PRICE_CURRENCY,
            'display' => poe_format_chaos_amount(round($total, 2)),
        ];
    });

    return is_array($cached) ? $cached : null;
}

function poe_convert_listing_price_to_chaos(string $league, array $price, string $indexedAt = ''): ?array
{
    $amount = isset($price['amount']) ? (float) $price['amount'] : null;
    $currency = isset($price['currency']) ? (string) $price['currency'] : '';
    if ($amount === null || $currency === '') {
        return null;
    }

    $unitConverted = poe_fetch_currency_bundle_price($league, $currency, 1.0);
    if ($unitConverted === null) {
        return null;
    }

    $totalChaosAmount = $unitConverted['amount'] * $amount;

    $display = $currency === POE_DEFAULT_PRICE_CURRENCY
        ? poe_format_chaos_amount($totalChaosAmount)
        : sprintf(
            '%s chaos (from %s %s)',
            poe_format_number($totalChaosAmount),
            poe_format_number($amount),
            $currency
        );

    return [
        'amount' => $totalChaosAmount,
        'currency' => POE_DEFAULT_PRICE_CURRENCY,
        'display' => $display,
        'indexedAt' => $indexedAt,
        'original' => [
            'amount' => $amount,
            'currency' => $currency,
        ],
    ];
}

function poe_extract_item_property_number(array $item, string $propertyName): ?int
{
    $properties = is_array($item['properties'] ?? null) ? $item['properties'] : [];
    foreach ($properties as $property) {
        if (!is_array($property)) {
            continue;
        }

        if (trim((string) ($property['name'] ?? '')) !== $propertyName) {
            continue;
        }

        $values = is_array($property['values'] ?? null) ? $property['values'] : [];
        $rawValue = (string) ($values[0][0] ?? '');
        if ($rawValue === '') {
            return null;
        }

        if (preg_match('/-?\d+/', $rawValue, $matches) !== 1) {
            return null;
        }

        return (int) $matches[0];
    }

    return null;
}

function poe_get_listing_gem_name(array $item): string
{
    $typeLine = trim((string) ($item['typeLine'] ?? ''));
    if ($typeLine !== '') {
        return $typeLine;
    }

    $baseType = trim((string) ($item['baseType'] ?? ''));
    if ($baseType !== '') {
        return $baseType;
    }

    $name = trim((string) ($item['name'] ?? ''));
    return $name !== '' ? $name : 'Unknown gem';
}

function poe_is_skill_gem_item(array $item): bool
{
    $candidates = [
        trim((string) ($item['baseType'] ?? '')),
        trim((string) ($item['typeLine'] ?? '')),
        trim((string) ($item['name'] ?? '')),
    ];

    foreach ($candidates as $candidate) {
        if ($candidate !== '' && preg_match('/\bSupport$/i', $candidate) === 1) {
            return false;
        }
    }

    return true;
}

function poe_fetch_sidebar_gems(): array
{
    $league = poe_get_trade_league();
    $cacheKey = sprintf(
        '%s|%s|%d|%d|%s|%s',
        $league,
        POE_DEFAULT_TRADE_STATUS,
        POE_SIDEBAR_GEMS_MIN_LEVEL,
        POE_SIDEBAR_GEMS_MIN_QUALITY,
        POE_DEFAULT_CORRUPTED_OPTION,
        number_format(POE_SIDEBAR_GEMS_MAX_PRICE_CHAOS, 2, '.', '')
    );

    $cached = app_cache_remember('sidebar-gems', $cacheKey, POE_SIDEBAR_GEMS_CACHE_TTL, static function () use ($league): array {
        $payload = [
            'query' => [
                'status' => ['option' => POE_DEFAULT_TRADE_STATUS],
                'stats' => [],
                'filters' => [
                    'type_filters' => [
                        'filters' => [
                            'category' => ['option' => 'gem'],
                        ],
                    ],
                    'misc_filters' => [
                        'filters' => [
                            'gem_level' => ['min' => POE_SIDEBAR_GEMS_MIN_LEVEL],
                            'quality' => ['min' => POE_SIDEBAR_GEMS_MIN_QUALITY],
                            'corrupted' => ['option' => POE_DEFAULT_CORRUPTED_OPTION],
                        ],
                    ],
                    'trade_filters' => [
                        'filters' => [
                            'sale_type' => ['option' => 'priced'],
                            'price' => ['max' => POE_SIDEBAR_GEMS_MAX_PRICE_CHAOS],
                        ],
                    ],
                ],
            ],
            'sort' => ['price' => 'asc'],
        ];

        $searchResponse = poe_request(
            'POST',
            '/api/trade/search/' . rawurlencode($league),
            $payload,
            'trade-search-request-limit'
        );

        $searchBody = $searchResponse['body'];
        $queryId = (string) ($searchBody['id'] ?? '');
        $resultIds = is_array($searchBody['result'] ?? null) ? $searchBody['result'] : [];
        $resultIds = array_values(array_filter(array_slice($resultIds, 0, POE_SIDEBAR_GEMS_FETCH_LIMIT), 'is_string'));

        if ($queryId === '' || $resultIds === []) {
            return [
                'league' => $league,
                'searchStatus' => POE_DEFAULT_TRADE_STATUS,
                'filters' => [
                    'category' => 'skill-gem',
                    'minLevel' => POE_SIDEBAR_GEMS_MIN_LEVEL,
                    'minQuality' => POE_SIDEBAR_GEMS_MIN_QUALITY,
                    'corrupted' => POE_DEFAULT_CORRUPTED_OPTION,
                    'maxPriceChaos' => POE_SIDEBAR_GEMS_MAX_PRICE_CHAOS,
                ],
                'count' => 0,
                'items' => [],
                'generatedAt' => gmdate(DATE_ATOM),
            ];
        }

        $fetchResult = [];
        foreach (array_chunk($resultIds, 10) as $fetchBatch) {
            $fetchResponse = poe_request(
                'GET',
                '/api/trade/fetch/' . implode(',', array_map('rawurlencode', $fetchBatch)) . '?query=' . rawurlencode($queryId),
                null,
                'trade-fetch-request-limit'
            );

            $batchResult = $fetchResponse['body']['result'] ?? [];
            if (is_array($batchResult) && $batchResult !== []) {
                $fetchResult = array_merge($fetchResult, $batchResult);
            }
        }

        $matchesByGem = [];

        foreach ($fetchResult as $row) {
            if (!is_array($row)) {
                continue;
            }

            $item = is_array($row['item'] ?? null) ? $row['item'] : [];
            $listing = is_array($row['listing'] ?? null) ? $row['listing'] : [];
            $price = is_array($listing['price'] ?? null) ? $listing['price'] : [];

            if (!poe_is_skill_gem_item($item)) {
                continue;
            }

            $convertedPrice = poe_convert_listing_price_to_chaos($league, $price, (string) ($listing['indexed'] ?? ''));
            if ($convertedPrice === null || (float) $convertedPrice['amount'] > POE_SIDEBAR_GEMS_MAX_PRICE_CHAOS) {
                continue;
            }

            $gemName = poe_get_listing_gem_name($item);
            $baseType = trim((string) ($item['baseType'] ?? ''));
            $level = poe_extract_item_property_number($item, 'Level');
            $quality = poe_extract_item_property_number($item, 'Quality');

            $entry = [
                'gemName' => $gemName,
                'baseType' => $baseType !== '' ? $baseType : $gemName,
                'level' => $level,
                'quality' => $quality,
                'price' => $convertedPrice,
                'priceChaos' => round((float) $convertedPrice['amount'], 2),
                'priceDisplay' => $convertedPrice['display'] ?? poe_format_chaos_amount((float) $convertedPrice['amount']),
                'indexedAt' => (string) ($listing['indexed'] ?? ''),
            ];

            $key = strtolower($gemName);
            if (!isset($matchesByGem[$key]) || (float) $entry['priceChaos'] < (float) ($matchesByGem[$key]['priceChaos'] ?? INF)) {
                $matchesByGem[$key] = $entry;
            }
        }

        $items = array_values($matchesByGem);
        usort($items, static function (array $left, array $right): int {
            $priceComparison = ((float) ($left['priceChaos'] ?? INF)) <=> ((float) ($right['priceChaos'] ?? INF));
            if ($priceComparison !== 0) {
                return $priceComparison;
            }

            return strcasecmp((string) ($left['gemName'] ?? ''), (string) ($right['gemName'] ?? ''));
        });

        $items = array_slice($items, 0, POE_SIDEBAR_GEMS_RESULT_LIMIT);

        return [
            'league' => $league,
            'searchStatus' => POE_DEFAULT_TRADE_STATUS,
            'filters' => [
                'category' => 'skill-gem',
                'minLevel' => POE_SIDEBAR_GEMS_MIN_LEVEL,
                'minQuality' => POE_SIDEBAR_GEMS_MIN_QUALITY,
                'corrupted' => POE_DEFAULT_CORRUPTED_OPTION,
                'maxPriceChaos' => POE_SIDEBAR_GEMS_MAX_PRICE_CHAOS,
            ],
            'count' => count($items),
            'items' => $items,
            'generatedAt' => gmdate(DATE_ATOM),
        ];
    });

    return is_array($cached) ? $cached : [
        'league' => $league,
        'searchStatus' => POE_DEFAULT_TRADE_STATUS,
        'filters' => [
            'category' => 'skill-gem',
            'minLevel' => POE_SIDEBAR_GEMS_MIN_LEVEL,
            'minQuality' => POE_SIDEBAR_GEMS_MIN_QUALITY,
            'corrupted' => POE_DEFAULT_CORRUPTED_OPTION,
            'maxPriceChaos' => POE_SIDEBAR_GEMS_MAX_PRICE_CHAOS,
        ],
        'count' => 0,
        'items' => [],
        'generatedAt' => gmdate(DATE_ATOM),
    ];
}

function poe_fetch_gem_price(string $league, array $gemEntry, int $level, int $quality): ?array
{
    $displayName = trim((string) ($gemEntry['displayName'] ?? ''));
    $type = trim((string) ($gemEntry['type'] ?? ''));
    $disc = trim((string) ($gemEntry['disc'] ?? ''));
    if ($displayName === '' || $type === '') {
        throw new RuntimeException('Unknown gem trade entry.');
    }

    $cacheKey = sprintf(
        '%s|%s|%s|%s|%d|%d',
        $league,
        POE_DEFAULT_TRADE_STATUS,
        $type,
        $disc,
        $level,
        $quality
    );

    $cached = app_cache_remember('gem-prices', $cacheKey, POE_GEM_PRICE_CACHE_TTL, static function () use ($league, $type, $disc, $level, $quality): ?array {
        $payload = [
            'query' => [
                'status' => ['option' => POE_DEFAULT_TRADE_STATUS],
                'type' => $type,
                'stats' => [],
                'filters' => [
                    'misc_filters' => [
                        'filters' => [
                            'gem_level' => ['min' => $level, 'max' => $level],
                            'quality' => ['min' => $quality, 'max' => $quality],
                            'corrupted' => ['option' => POE_DEFAULT_CORRUPTED_OPTION],
                        ],
                    ],
                    'trade_filters' => [
                        'filters' => [
                            'sale_type' => ['option' => 'priced'],
                        ],
                    ],
                ],
            ],
            'sort' => ['price' => 'asc'],
        ];

        if ($disc !== '') {
            $payload['query']['disc'] = $disc;
        }

        $searchResponse = poe_request(
            'POST',
            '/api/trade/search/' . rawurlencode($league),
            $payload,
            'trade-search-request-limit'
        );

        $searchBody = $searchResponse['body'];
        $resultIds = is_array($searchBody['result'] ?? null) ? $searchBody['result'] : [];
        $queryId = (string) ($searchBody['id'] ?? '');
        $resultIds = array_values(array_filter(array_slice($resultIds, 0, 5), 'is_string'));

        if ($queryId === '' || $resultIds === []) {
            return null;
        }

        $fetchResponse = poe_request(
            'GET',
            '/api/trade/fetch/' . implode(',', array_map('rawurlencode', $resultIds)) . '?query=' . rawurlencode($queryId),
            null,
            'trade-fetch-request-limit'
        );

        $fetchResult = $fetchResponse['body']['result'] ?? [];
        if (!is_array($fetchResult) || $fetchResult === []) {
            return null;
        }

        $bestPrice = null;
        foreach ($fetchResult as $row) {
            if (!is_array($row)) {
                continue;
            }

            $listing = is_array($row['listing'] ?? null) ? $row['listing'] : [];
            $price = is_array($listing['price'] ?? null) ? $listing['price'] : [];
            $convertedPrice = poe_convert_listing_price_to_chaos($league, $price, (string) ($listing['indexed'] ?? ''));
            if ($convertedPrice === null) {
                continue;
            }

            if ($bestPrice === null || (float) $convertedPrice['amount'] < (float) $bestPrice['amount']) {
                $bestPrice = $convertedPrice;
            }
        }

        return $bestPrice;
    });

    return is_array($cached) ? $cached : null;
}

function poe_fetch_gcp_bundle_price(string $league, int $quantity = POE_GCP_BUNDLE_SIZE): ?array
{
    $cached = poe_fetch_currency_bundle_price($league, POE_GCP_CURRENCY_ID, (float) $quantity);
    return is_array($cached) ? $cached : null;
}

function poe_evaluate_gem(string $gemName): array
{
    $gemEntry = poe_find_gem_entry($gemName);
    if ($gemEntry === null) {
        throw new RuntimeException('Unknown gem trade entry.');
    }

    $displayName = trim((string) ($gemEntry['displayName'] ?? $gemName));
    $league = poe_get_trade_league();
    $gcpBundle = poe_fetch_gcp_bundle_price($league, POE_GCP_BUNDLE_SIZE);
    $rows = [];

    foreach (POE_GEM_VARIANTS as $variant) {
        $level = (int) ($variant['level'] ?? 0);
        $quality = (int) ($variant['quality'] ?? 0);
        $price = poe_fetch_gem_price($league, $gemEntry, $level, $quality);

        $costAmount = poe_calculate_variant_cost_amount($quality, $gcpBundle);
        $costDisplay = poe_format_chaos_amount($costAmount);

        $profitAmount = null;
        $profitDisplay = 'n/a';
        if ($price !== null && $costAmount !== null) {
            $profitAmount = round((float) $price['amount'] - $costAmount, 2);
            $profitDisplay = poe_format_chaos_amount($profitAmount);
        }

        $rows[] = [
            'gemName' => $displayName,
            'level' => $level,
            'quality' => $quality,
            'price' => $price,
            'priceDisplay' => $price['display'] ?? 'No online buyout listings',
            'cost' => $costAmount,
            'costDisplay' => $costDisplay,
            'profit' => $profitAmount,
            'profitDisplay' => $profitDisplay,
        ];
    }

    return [
        'league' => $league,
        'searchStatus' => POE_DEFAULT_TRADE_STATUS,
        'currency' => POE_DEFAULT_PRICE_CURRENCY,
        'gcpBundle' => $gcpBundle,
        'rows' => $rows,
        'generatedAt' => gmdate(DATE_ATOM),
    ];
}
