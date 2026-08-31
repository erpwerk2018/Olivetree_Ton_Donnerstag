<?php
declare(strict_types=1);

/* ═══════════════════════════════════════════════════════════════════════════
   Sensor Proxy for index.html
   ═══════════════════════════════════════════════════════════════════════════

   WHY THIS FILE EXISTS
   The frontend page cannot query the UP Logger Companion API directly: the
   API does not send an `Access-Control-Allow-Origin` header, causing browsers
   to abort any direct cross-origin request — regardless of the API key. A server
   environment is not bound by this restriction. Therefore, PHP fetches the
   data server-side, and the frontend page queries this local proxy endpoint on
   the same domain.

   Equally important: the API key remains securely stored on the server. If it
   were in the HTML file, any visitor could view it in source code and consume
   the API quota.

   PREREQUISITES: PHP 7.4 or newer with cURL or allow_url_fopen enabled.
   Both are standard on typical web hosting environments.

   OUTPUT: Identical JSON format to the Node backend at
   /api/public/sensor-series — allowing the frontend to run seamlessly against
   either data source.
   ═══════════════════════════════════════════════════════════════════════════ */


/* ───────────────────────────────────────────────────────────────────────────
   CONFIGURATION: API Key.
   Found in the project's .env under `Sensor_APIKEY`.
   Never commit this file with production credentials into a public repository.
   ─────────────────────────────────────────────────────────────────────────── */
const API_KEY = 'c809dab2-5d58-44a3-9fa4-e8896641763b';


const API_URL       = 'https://companion.upgmbh.cloud/data/api/v1';
const LOGGER_DENDRO = '865260083944615';   // Dendro_14 (Trunk diameter)
const LOGGER_LIGHT  = '868927086796609';   // Solar radiation (PPFD)

const BUCKET      = '10m';   // Time bucket — aligns both loggers on the same time axis
const WINDOW_DAYS = 14;      // Query window in days

/* The API allows only 30 requests per 10 minutes. Without caching, each page
   load would consume one request, rapidly exceeding the quota with concurrent
   visitors. 9 minutes sits safely within the window and matches the 10-minute
   measurement interval — polling more frequently would yield no new data. */
const CACHE_TTL_SEC = 540;
const CACHE_FILE    = __DIR__ . '/.sensor-cache.json';

/* Threshold (µm) across a data gap above which an offset is classified as a
   sensor remount rather than biological growth. A dendrometer measures relative
   to its physical mount: if reclamped, the baseline jumps without actual tree
   expansion. Typical daily rhythm is ~90 µm, longer spans ~135–157 µm — this
   threshold is intentionally set higher. */
const JUMP_THRESHOLD = 180.0;


header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/* Allow cross-origin access if needed. Output contains only environmental
   sensor measurements — no personal or user data. */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}


// ─── Main Flow ─────────────────────────────────────────────────────────────

$cache = cache_read();
if ($cache !== null && (time() - $cache['at']) < CACHE_TTL_SEC) {
    echo $cache['json'];
    exit;
}

$series = fetch_series();

if ($series === null) {
    /* Request failed: serve slightly older cached data rather than failing completely;
       the frontend clearly displays the age of the measurement. */
    if ($cache !== null) {
        echo $cache['json'];
        exit;
    }
    http_response_code(503);
    echo json_encode(['error' => 'Sensor data currently unavailable'], JSON_UNESCAPED_UNICODE);
    exit;
}

$json = json_encode($series, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
cache_write($json);
echo $json;
exit;


// ─── Cache Management ──────────────────────────────────────────────────────

function cache_read(): ?array
{
    if (!is_readable(CACHE_FILE)) {
        return null;
    }
    $raw = @file_get_contents(CACHE_FILE);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['at'], $data['json'])) {
        return null;
    }
    return ['at' => (int) $data['at'], 'json' => (string) $data['json']];
}

function cache_write(string $json): void
{
    /* Write to a temp file first, then atomically rename: prevents concurrent
       requests from reading a partially written file. */
    $tmp = CACHE_FILE . '.' . getmypid() . '.tmp';
    $content = json_encode(['at' => time(), 'json' => $json], JSON_UNESCAPED_SLASHES);
    if ($content !== false && @file_put_contents($tmp, $content) !== false) {
        @rename($tmp, CACHE_FILE);
    }
}


// ─── Fetching & Processing ─────────────────────────────────────────────────

function fetch_series(): ?array
{
    if (API_KEY === '') {
        return null;   // API key not configured
    }

    $end   = time();
    $start = $end - WINDOW_DAYS * 86400;

    $params = [
        'start'             => gmdate('Y-m-d\TH:i:s\Z', $start),
        'end'               => gmdate('Y-m-d\TH:i:s\Z', $end),
        'pivot'             => 'both_wide',
        'timeBucket'        => BUCKET,
        'aggregationMethod' => 'mean',
        'createEmpty'       => 'false',
    ];

    $url = API_URL . '?' . http_build_query($params)
        . '&loggers=' . rawurlencode(LOGGER_DENDRO)
        . '&loggers=' . rawurlencode(LOGGER_LIGHT)
        . '&channels=Dendrometer'
        . '&channels=PPFD';

    $csv = http_get($url);
    if ($csv === null) {
        return null;
    }

    return parse_csv($csv);
}

function http_get(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['x-api-key: ' . API_KEY],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ($response !== false && $status === 200) ? (string) $response : null;
    }

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => 'x-api-key: ' . API_KEY . "\r\n",
            'timeout' => 30,
        ],
    ]);
    $response = @file_get_contents($url, false, $ctx);
    return $response === false ? null : (string) $response;
}


/**
 * Builds the prepared time series from the UP API CSV response.
 *
 * Steps intentionally match the Node service 1:1
 * (backend/src/services/upSensors/index.ts) so both sources produce the identical curve.
 */
function parse_csv(string $text): ?array
{
    $lines = preg_split('/\r?\n/', $text);
    if ($lines === false) {
        return null;
    }

    $header = null;
    $rows   = [];
    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $cells = array_map(
            static fn(string $c): string => trim(trim($c), '"'),
            explode(';', $line)
        );
        if ($header === null) {
            $header = $cells;
            continue;
        }
        $rows[] = $cells;
    }
    if ($header === null || count($rows) === 0) {
        return null;
    }

    /* `both_wide` generates a column for every logger/channel combination, including
       empty ones. Locate the two active columns specifically. */
    $colDendro = array_search(LOGGER_DENDRO . '_Dendrometer', $header, true);
    $colLight  = array_search(LOGGER_LIGHT . '_PPFD', $header, true);
    if ($colDendro === false) {
        return null;
    }

    /* The API does not guarantee chronological order and can return duplicate
       timestamps when both loggers align in the same time window.
       Group by timestamp, merge channels, and sort chronologically. */
    $byTime = [];
    foreach ($rows as $r) {
        $time = $r[0] ?? '';
        if ($time === '' || strtotime($time) === false) {
            continue;
        }
        if (!isset($byTime[$time])) {
            $byTime[$time] = ['dendro' => null, 'light' => null];
        }
        $d = parse_number($r[$colDendro] ?? null);
        $l = $colLight === false ? null : parse_number($r[$colLight] ?? null);
        if ($d !== null) {
            $byTime[$time]['dendro'] = $d;
        }
        if ($l !== null) {
            $byTime[$time]['light'] = $l;
        }
    }
    if (count($byTime) === 0) {
        return null;
    }

    $timestamps = array_keys($byTime);
    usort($timestamps, static fn(string $a, string $b): int => strtotime($a) <=> strtotime($b));

    /* Trim to the range where dendrometer readings actually exist.
       Prevents padding borders with constant values, which would otherwise
       look like a flat standstill. */
    $firstValid = -1;
    foreach ($timestamps as $i => $t) {
        if ($byTime[$t]['dendro'] !== null) {
            $firstValid = $i;
            break;
        }
    }
    $lastValid = count($timestamps) - 1;
    while ($lastValid >= 0 && $byTime[$timestamps[$lastValid]]['dendro'] === null) {
        $lastValid--;
    }
    if ($firstValid === -1 || $lastValid < $firstValid) {
        return null;
    }
    $timestamps = array_slice($timestamps, $firstValid, $lastValid - $firstValid + 1);

    $rawDendro = [];
    $rawLight  = [];
    foreach ($timestamps as $t) {
        $rawDendro[] = $byTime[$t]['dendro'];
        $rawLight[]  = $byTime[$t]['light'];
    }

    /* OUTLIER & OUTAGE DETECTION: If a value drops below 70% or exceeds 150% of the
       median, it represents a sensor or transmission drop rather than biological movement
       (tree stems fluctuate ~5%, never 99%). Keeping such values would distort series scaling. */
    $validNumbers = array_values(array_filter($rawDendro, static fn($v): bool => $v !== null));
    sort($validNumbers);
    $median = count($validNumbers) > 0 ? $validNumbers[intdiv(count($validNumbers), 2)] : 0.0;
    if ($median > 0) {
        foreach ($rawDendro as $i => $v) {
            if ($v !== null && ($v < $median * 0.7 || $v > $median * 1.5)) {
                $rawDendro[$i] = null;
            }
        }
    }

    /* Compensate baseline shifts from sensor remounting — performed after outlier
       detection and prior to gap interpolation. */
    $rawDendro = correct_jumps($rawDendro);

    /* Moving average radius 2 on 10-minute grid ≈ 50-minute window: dampens single
       sensor spikes while preserving hourly diurnal dynamics intact. */
    $dendro = smooth_series(fill_gaps($rawDendro), 2);
    $light  = smooth_series(fill_gaps($rawLight), 2);

    $t0 = strtotime($timestamps[0]);
    $points = [];
    foreach ($timestamps as $i => $t) {
        $points[] = [
            't'      => (int) round((strtotime($t) - $t0)),
            'dendro' => round($dendro[$i], 3),
            'light'  => round($light[$i], 3),
        ];
    }

    return [
        'source'      => 'up-api-php',
        'fetchedAt'   => gmdate('Y-m-d\TH:i:s\Z'),
        'from'        => gmdate('Y-m-d\TH:i:s\Z', $t0),
        'to'          => gmdate('Y-m-d\TH:i:s\Z', strtotime($timestamps[count($timestamps) - 1])),
        'durationSec' => $points[count($points) - 1]['t'],
        'points'      => $points,
        'ranges'      => [
            'dendro' => [min($dendro), max($dendro)],
            'light'  => [min($light), max($light)],
        ],
    ];
}

/** The API returns commas as decimal separators; empty fields = no measurement. */
function parse_number(?string $cell): ?float
{
    if ($cell === null || $cell === '') {
        return null;
    }
    $n = str_replace(',', '.', $cell);
    return is_numeric($n) ? (float) $n : null;
}

/**
 * Linearly interpolates measurement gaps to provide a continuous curve.
 * Both loggers transmit slightly out of sync causing single-channel gaps;
 * additionally, the API only delivers timestamps with data.
 */
function fill_gaps(array $values): array
{
    $out   = $values;
    $count = count($out);

    $first = -1;
    for ($i = 0; $i < $count; $i++) {
        if ($out[$i] !== null) {
            $first = $i;
            break;
        }
    }
    if ($first === -1) {
        return array_fill(0, $count, 0.0);
    }

    // Pad leading missing values with first valid value
    for ($i = 0; $i < $first; $i++) {
        $out[$i] = $out[$first];
    }
    $last = $count - 1;
    while ($last >= 0 && $out[$last] === null) {
        $last--;
    }
    // Pad trailing missing values with last valid value
    for ($i = $last + 1; $i < $count; $i++) {
        $out[$i] = $out[$last];
    }

    // Linearly interpolate internal gaps
    $i = $first;
    while ($i <= $last) {
        if ($out[$i] !== null) {
            $i++;
            continue;
        }
        $j = $i;
        while ($j <= $last && $out[$j] === null) {
            $j++;
        }
        $prev = (float) $out[$i - 1];
        $next = (float) $out[$j];
        for ($k = $i; $k < $j; $k++) {
            $out[$k] = $prev + (($next - $prev) * ($k - $i + 1)) / ($j - $i + 1);
        }
        $i = $j + 1;
    }

    return array_map(static fn($v): float => (float) $v, $out);
}

/**
 * Compensates baseline shifts resulting from physical sensor repositioning/remounting.
 *
 * Compares MEDIANS on both sides of a gap rather than immediate edge values,
 * since points adjacent to an outage are frequently compromised.
 */
function correct_jumps(array $values): array
{
    $out    = $values;
    $count  = count($out);
    $window = 12;   // 12 × 10 minutes = 2 hours
    $offset = 0.0;

    $i = 0;
    while ($i < $count) {
        if ($out[$i] === null) {
            $i++;
            continue;
        }

        // End of current contiguous segment
        $end = $i;
        while ($end + 1 < $count && $out[$end + 1] !== null) {
            $end++;
        }
        // Start of next segment
        $start = $end + 1;
        while ($start < $count && $out[$start] === null) {
            $start++;
        }
        if ($start >= $count) {
            break;
        }
        $nextEnd = $start;
        while ($nextEnd + 1 < $count && $out[$nextEnd + 1] !== null) {
            $nextEnd++;
        }

        $prevMed = median_of(array_slice($out, max($i, $end - $window + 1), min($window, $end - max($i, $end - $window + 1) + 1)));
        $nextMed = median_of(array_slice($out, $start, min($window, $nextEnd - $start + 1)));

        $diff = $nextMed - $prevMed;
        if (abs($diff) >= JUMP_THRESHOLD) {
            $offset += $diff;
        }
        if ($offset !== 0.0) {
            for ($k = $start; $k <= $nextEnd; $k++) {
                if ($out[$k] !== null) {
                    $out[$k] = $out[$k] - $offset;
                }
            }
        }

        $i = $nextEnd + 1;
    }

    return $out;
}

function median_of(array $values): float
{
    $valid = array_values(array_filter($values, static fn($v): bool => $v !== null));
    if (count($valid) === 0) {
        return 0.0;
    }
    sort($valid);
    return (float) $valid[intdiv(count($valid), 2)];
}

/** Moving average — dampens single sensor spikes. */
function smooth_series(array $values, int $radius): array
{
    if ($radius <= 0) {
        return $values;
    }
    $count = count($values);
    $out   = [];
    for ($i = 0; $i < $count; $i++) {
        $from = max(0, $i - $radius);
        $to   = min($count - 1, $i + $radius);
        $sum  = 0.0;
        for ($j = $from; $j <= $to; $j++) {
            $sum += $values[$j];
        }
        $out[] = $sum / ($to - $from + 1);
    }
    return $out;
}
