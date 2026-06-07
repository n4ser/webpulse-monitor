<?php
declare(strict_types=1);  // must be first statement after <?php

// Suppress PHP error output — errors must never break JSON responses
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ob_start();  // buffer all output so stray warnings don't corrupt JSON

// ============================================================
//  URL Monitor v4 - monitor.php
//  Runs as a cron job OR as a secure HTTP-triggered endpoint.
//
//  Cron setup (add to cPanel Cron Jobs):
//  Run every 5 min:
//    /usr/local/bin/php8.3 /path/to/monitor.php >> /path/to/logs/cron.log 2>&1
//
//  Web trigger (from dashboard Manual Test button):
//    GET https://yoursite.com/monitor/monitor.php?token=YOUR_TOKEN
//    Header: X-Monitor-Token: YOUR_TOKEN
//
//  PHP version:
//    Recommended : PHP 8.3  -> /usr/local/bin/php8.3
//    Minimum     : PHP 8.0
// ============================================================

// ─────────────────────────────────────────────────────────────
//  !! MUST MATCH DASHBOARD_TOKEN IN api.php !!
// ─────────────────────────────────────────────────────────────
define('DASHBOARD_TOKEN', 'your-secure-random-token');

define('IS_CLI', PHP_SAPI === 'cli');
define('LOG_DIR', __DIR__ . '/logs');
define('SETTINGS_FILE', LOG_DIR . '/settings.json');

// ── Load config ──────────────────────────────────────────────
// Priority: config.php > settings.json > built-in defaults
$CFG = loadConfig();

// ── HTTP auth guard ───────────────────────────────────────────
if (!IS_CLI) {
    // Accept token via GET param OR request headers
    $incomingToken = $_GET['token']
        ?? $_SERVER['HTTP_X_MONITOR_TOKEN']
        ?? $_SERVER['HTTP_X_TOKEN']
        ?? '';

    if (DASHBOARD_TOKEN === 'CHANGE_THIS_SECRET_TOKEN' || $incomingToken !== DASHBOARD_TOKEN) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized', 'ts' => date('Y-m-d H:i:s')]);
        exit;
    }

    ob_clean();  // discard any PHP warnings buffered before this point
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: X-Monitor-Token, X-Token, Content-Type');
    @set_time_limit(300);
}

// ─────────────────────────────────────────────────────────────
//  BOOTSTRAP
// ─────────────────────────────────────────────────────────────
@mkdir(LOG_DIR, 0755, true);

$snapshots  = loadJson(LOG_DIR . '/snapshots.json',   []);
$history    = loadJson(LOG_DIR . '/history.json',     []);
$alertState = loadJson(LOG_DIR . '/alert_state.json', []);
$seoScores  = loadJson(LOG_DIR . '/seo_scores.json',  []);
$masterLog  = loadJson(LOG_DIR . '/master_log.json',  []);

$runResults   = [];
$runTimestamp = date('Y-m-d H:i:s');
$runDate      = date('Y-m-d');
$runLog       = [];

// ─────────────────────────────────────────────────────────────
//  MAIN LOOP — iterate groups from config
// ─────────────────────────────────────────────────────────────
$urlGroups = $CFG['url_groups'] ?? [];

foreach ($urlGroups as $groupName => $urls) {
    foreach ($urls as $cfg) {
        if (empty($cfg['url'])) continue;

        $result        = monitorUrl($cfg, $groupName, $snapshots, $alertState, $seoScores, $CFG);
        $runResults[]  = $result;

        $key = md5($cfg['url']);
        if (!isset($history[$key])) {
            $history[$key] = [
                'url'     => $cfg['url'],
                'label'   => $cfg['label'] ?? $cfg['url'],
                'group'   => $groupName,
                'entries' => [],
            ];
        }

        $history[$key]['entries'][] = [
            'ts'          => $runTimestamp,
            'date'        => $runDate,
            'status'      => $result['http_status'],
            'ms'          => $result['response_ms'],
            'ttfb_ms'     => $result['vitals']['ttfb_ms']  ?? 0,
            'size_kb'     => $result['vitals']['size_kb']  ?? 0,
            'category'    => $result['error_category'],
            'issue_count' => count($result['issues']),
            'seo_score'   => $result['seo_score'],
        ];

        $maxHistory = (int) ($CFG['max_history_entries'] ?? 2000);
        if (count($history[$key]['entries']) > $maxHistory) {
            $history[$key]['entries'] = array_slice($history[$key]['entries'], -$maxHistory);
        }

        $line = sprintf(
            "[%s] [%s] %s — HTTP %d — %dms — %s — SEO:%d — Issues:%d",
            $runTimestamp, $groupName,
            $cfg['label'] ?? $cfg['url'],
            $result['http_status'], $result['response_ms'],
            $result['error_category'],
            $result['seo_score'],
            count($result['issues'])
        );
        $runLog[] = $line;
        if (IS_CLI) echo $line . PHP_EOL;
    }
}

// ── Persist ───────────────────────────────────────────────────
$masterLog[] = ['ts' => $runTimestamp, 'results' => $runResults];
if (count($masterLog) > 500) $masterLog = array_slice($masterLog, -500);

saveJson(LOG_DIR . '/master_log.json',  $masterLog);
saveJson(LOG_DIR . '/snapshots.json',   $snapshots);
saveJson(LOG_DIR . '/history.json',     $history);
saveJson(LOG_DIR . '/alert_state.json', $alertState);
saveJson(LOG_DIR . '/seo_scores.json',  $seoScores);

buildLatestSnapshot($runResults, $history, $urlGroups, $runTimestamp);

$summary = sprintf("Run complete: %s — %d URLs checked", $runTimestamp, count($runResults));
if (IS_CLI) {
    ob_end_flush();
    echo $summary . PHP_EOL;
} else {
    ob_clean();  // final clean before JSON output
    $latest = loadJson(LOG_DIR . '/latest.json', []);
    $latest['run_log'] = $runLog;
    $latest['summary'] = $summary;
    echo json_encode($latest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ob_end_flush();
}


// ═════════════════════════════════════════════════════════════
//  CONFIG LOADER
//  Merges: built-in defaults ← settings.json ← config.php
// ═════════════════════════════════════════════════════════════
function loadConfig(): array
{
    $defaults = [
        'dashboard_token'    => 'CHANGE_THIS_SECRET_TOKEN',
        'telegram_bot_token' => 'YOUR_BOT_TOKEN_HERE',
        'telegram_chat_id'   => 'YOUR_CHAT_ID_HERE',
        'slow_threshold_ms'  => 3000,
        'ttfb_slow_ms'       => 600,
        'max_retries'        => 3,
        'retry_delay_sec'    => 5,
        'request_timeout'    => 20,
        'alert_cooldown_min' => 60,
        'seo_score_drop_pct' => 10,
        'max_history_entries'=> 2000,
        'url_groups'         => [],
    ];

    // Merge settings.json (managed via dashboard)
    $settingsFile = LOG_DIR . '/settings.json';
    if (file_exists($settingsFile)) {
        $saved = json_decode(file_get_contents($settingsFile), true) ?? [];
        $defaults = array_merge($defaults, $saved);
    }

    // config.php can override anything (for server-side secrets)
    $configFile = __DIR__ . '/config.php';
    if (file_exists($configFile)) {
        $override = include $configFile;   // must return an array
        if (is_array($override)) {
            $defaults = array_merge($defaults, $override);
        }
    }

    return $defaults;
}


// ═════════════════════════════════════════════════════════════
//  MONITOR SINGLE URL
// ═════════════════════════════════════════════════════════════
function monitorUrl(
    array  $cfg,
    string $group,
    array  &$snapshots,
    array  &$alertState,
    array  &$seoScores,
    array  $CFG
): array {
    $url    = $cfg['url'];
    $label  = $cfg['label'] ?? $url;
    $urlKey = md5($url);
    $ts     = date('Y-m-d H:i:s');
    $issues = [];

    // ── Retry loop ────────────────────────────────────────────
    $maxRetries = (int) ($CFG['max_retries'] ?? 3);
    $attempt = 0;
    $resp    = null;
    while ($attempt < $maxRetries) {
        $attempt++;
        $resp = fetchUrl($url, $CFG);
        if ($resp['success']) break;
        if ($attempt < $maxRetries) sleep((int)($CFG['retry_delay_sec'] ?? 5));
    }

    $httpStatus = $resp['http_code'];
    $responseMs = $resp['response_ms'];
    $html       = $resp['body'];
    $headers    = $resp['headers'];

    // ── Classify ──────────────────────────────────────────────
    $slowMs   = (int)($CFG['slow_threshold_ms'] ?? 3000);
    $category = classifyError($httpStatus, $responseMs, $resp['success'], $slowMs);

    // ── HTTP issue ────────────────────────────────────────────
    $expectedStatus = (int)($cfg['expected_status'] ?? 200);
    if (!$resp['success'] || $httpStatus !== $expectedStatus) {
        $issues[] = [
            'type'       => $category,
            'detail'     => "Expected HTTP {$expectedStatus}, got {$httpStatus}" .
                            (!$resp['success'] ? " ({$resp['error']})" : '') .
                            " after {$attempt} attempt(s).",
            'seo_impact' => in_array($httpStatus, [404, 403, 500, 503], true) ? 'high' : 'medium',
        ];
    }

    // ── Slow ─────────────────────────────────────────────────
    if ($category === 'SLOW' && $httpStatus === $expectedStatus) {
        $issues[] = ['type' => 'SLOW', 'detail' => "Response {$responseMs}ms exceeds {$slowMs}ms threshold.", 'seo_impact' => 'medium'];
    }

    // ── Keyword presence ─────────────────────────────────────
    $keyword = trim($cfg['keyword_present'] ?? '');
    if ($keyword !== '' && $html !== '' && !str_contains($html, $keyword)) {
        $issues[] = ['type' => 'CONTENT_ISSUE', 'detail' => "Keyword not found: \"{$keyword}\"", 'seo_impact' => 'medium'];
        if ($category === 'OK') $category = 'CONTENT_ISSUE';
    }

    // ── Vitals ───────────────────────────────────────────────
    $vitals = [];
    if (!empty($cfg['check_vitals'])) {
        $vitals = computeVitals($resp, $CFG);
        if ($vitals['speed_class'] === 'Slow') {
            $issues[] = ['type' => 'SLOW', 'detail' => "Vitals: TTFB {$vitals['ttfb_ms']}ms, Size {$vitals['size_kb']}KB — Slow", 'seo_impact' => 'medium'];
        }
    }

    // ── Indexability ─────────────────────────────────────────
    $indexability = [];
    if (!empty($cfg['check_indexability']) && $html !== '') {
        $indexability = checkIndexability($html, $headers, $url);
        foreach ($indexability['issues'] as $ii) {
            $issues[] = ['type' => 'SEO_RISK', 'detail' => $ii, 'seo_impact' => 'high'];
            if ($category === 'OK') $category = 'CONTENT_ISSUE';
        }
    }

    // ── Basic SEO ────────────────────────────────────────────
    $seoAudit = [];
    if (!empty($cfg['check_seo']) && $html !== '') {
        $seoAudit = auditSeoBasic($html);
        foreach ($seoAudit['issues'] as $si) {
            $issues[] = ['type' => 'CONTENT_ISSUE', 'detail' => $si, 'seo_impact' => 'medium'];
            if ($category === 'OK') $category = 'CONTENT_ISSUE';
        }
    }

    // ── Keyword Consistency ──────────────────────────────────
    $keywordCheck = [];
    $targetKws = $cfg['target_keywords'] ?? [];
    if (!empty($cfg['check_keywords']) && $html !== '' && !empty($targetKws)) {
        $keywordCheck = checkKeywordConsistency($html, $targetKws);
        foreach ($keywordCheck['issues'] as $ki) {
            $issues[] = ['type' => 'CONTENT_ISSUE', 'detail' => $ki, 'seo_impact' => 'medium'];
            if ($category === 'OK') $category = 'CONTENT_ISSUE';
        }
    }

    // ── Content Change + Impact Analysis ─────────────────────
    $changeAnalysis = [];
    if (!empty($cfg['check_content']) && $html !== '') {
        $newHash = md5($html);
        $prev    = $snapshots[$urlKey] ?? null;

        if ($prev !== null && ($prev['hash'] ?? '') !== $newHash) {
            if (!empty($cfg['check_change_impact'])) {
                $changeAnalysis = analyzeChangeImpact($prev, $html);
                foreach ($changeAnalysis['issues'] as $ci) {
                    $issues[] = ['type' => 'CONTENT_ISSUE', 'detail' => $ci, 'seo_impact' => $changeAnalysis['impact_level']];
                }
            } else {
                $issues[] = ['type' => 'CONTENT_ISSUE', 'detail' => 'HTML content changed since ' . ($prev['last_changed'] ?? 'last check'), 'seo_impact' => 'low'];
            }
            $snapshots[$urlKey]['last_changed'] = $ts;
            if ($category === 'OK') $category = 'CONTENT_ISSUE';
        }

        $snapshots[$urlKey] = array_merge($snapshots[$urlKey] ?? [], [
            'hash'         => $newHash,
            'last_checked' => $ts,
            'title'        => extractText($html, '//title'),
            'description'  => extractMeta($html, 'description'),
            'h1'           => extractText($html, '//h1'),
            'canonical'    => extractLinkHref($html, 'canonical'),
        ]);
    }

    // ── SERP Risks ───────────────────────────────────────────
    $serpRisks = [];
    if (!empty($cfg['check_serp_risk'])) {
        $serpRisks = detectSerpRisks($httpStatus, $indexability, $changeAnalysis, $snapshots[$urlKey] ?? []);
        foreach ($serpRisks as $sr) {
            $issues[] = ['type' => 'SERP_RISK', 'detail' => $sr, 'seo_impact' => 'high'];
            if ($category === 'OK') $category = 'CONTENT_ISSUE';
        }
    }

    // ── SEO Score ────────────────────────────────────────────
    $seoScore = calculateSeoScore($issues, $vitals, $indexability, $httpStatus);

    // Track daily score
    if (!isset($seoScores[$urlKey])) $seoScores[$urlKey] = ['url' => $url, 'daily' => []];
    $seoScores[$urlKey]['daily'][$runDate] = $seoScore;
    if (count($seoScores[$urlKey]['daily']) > 90) {
        $seoScores[$urlKey]['daily'] = array_slice($seoScores[$urlKey]['daily'], -90, null, true);
    }

    // Score drop alert
    $prevScores = array_values($seoScores[$urlKey]['daily']);
    if (count($prevScores) >= 2) {
        $yesterday = $prevScores[count($prevScores) - 2];
        $drop = $yesterday - $seoScore;
        $dropThreshold = (int)($CFG['seo_score_drop_pct'] ?? 10);
        if ($drop >= $dropThreshold) {
            $issues[] = ['type' => 'SEO_RISK', 'detail' => "SEO score dropped {$drop}pts ({$yesterday}→{$seoScore}).", 'seo_impact' => 'high'];
        }
    }

    // ── Telegram ─────────────────────────────────────────────
    if (!empty($issues)) {
        maybeSendTelegram($label, $url, $group, $issues, $responseMs, $seoScore, $alertState, $CFG);
    } else {
        unset($alertState[md5($url)]);
    }

    return [
        'ts'              => $ts,
        'url'             => $url,
        'label'           => $label,
        'group'           => $group,
        'http_status'     => $httpStatus,
        'response_ms'     => $responseMs,
        'error_category'  => $category,
        'issues'          => $issues,
        'retries'         => $attempt,
        'vitals'          => $vitals,
        'indexability'    => $indexability,
        'keyword_check'   => $keywordCheck,
        'change_analysis' => $changeAnalysis,
        'serp_risks'      => $serpRisks,
        'seo_score'       => $seoScore,
    ];
}


// ═════════════════════════════════════════════════════════════
//  SUPPORT FUNCTIONS (same as v3 — adapted to use $CFG)
// ═════════════════════════════════════════════════════════════

function classifyError(int $httpStatus, int $responseMs, bool $success, int $slowMs): string
{
    if (!$success || $httpStatus === 0)                         return 'DOWN';
    if (in_array($httpStatus, [401, 403], true))               return 'BLOCKED';
    if (in_array($httpStatus, [301, 302, 307, 308], true))     return 'REDIRECT';
    if ($httpStatus >= 500)                                     return 'DOWN';
    if ($httpStatus >= 400)                                     return 'BLOCKED';
    if ($responseMs > $slowMs)                                  return 'SLOW';
    return 'OK';
}

function fetchUrl(string $url, array $CFG): array
{
    $timeout = (int)($CFG['request_timeout'] ?? 20);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'URLMonitorBot/4.0',
        CURLOPT_ENCODING       => '',
    ]);

    $start   = microtime(true);
    $raw     = curl_exec($ch);
    $totalMs = (int) round((microtime(true) - $start) * 1000);
    $ttfbMs  = (int) round(curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME) * 1000);

    $httpCode   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $sizeBytes  = (int) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
    $error      = curl_error($ch);
    curl_close($ch);

    $rawHeaders = $raw ? substr((string)$raw, 0, $headerSize) : '';
    $body       = $raw ? substr((string)$raw, $headerSize)    : '';

    return [
        'success'     => ($raw !== false && empty($error)),
        'http_code'   => $httpCode,
        'response_ms' => $totalMs,
        'ttfb_ms'     => $ttfbMs,
        'size_bytes'  => $sizeBytes,
        'body'        => $body,
        'headers'     => parseHeaders($rawHeaders),
        'error'       => $error,
    ];
}

function parseHeaders(string $raw): array
{
    $h = [];
    foreach (explode("\n", $raw) as $line) {
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) $h[strtolower(trim($parts[0]))] = trim($parts[1]);
    }
    return $h;
}

function computeVitals(array $resp, array $CFG): array
{
    $ttfb    = $resp['ttfb_ms'];
    $total   = $resp['response_ms'];
    $sizeKb  = round($resp['size_bytes'] / 1024, 1);
    $ttfbSlowMs = (int)($CFG['ttfb_slow_ms'] ?? 600);

    $speedClass = match (true) { $total < 1000 => 'Fast', $total < 3000 => 'Medium', default => 'Slow' };
    $ttfbClass  = match (true) { $ttfb < 200 => 'Good', $ttfb < $ttfbSlowMs => 'Needs Improvement', default => 'Poor' };

    return ['ttfb_ms' => $ttfb, 'total_ms' => $total, 'size_kb' => $sizeKb, 'speed_class' => $speedClass, 'ttfb_class' => $ttfbClass];
}

function checkIndexability(string $html, array $headers, string $pageUrl): array
{
    $issues = []; $warnings = []; $isBlocked = false;
    $dom = parseDom($html); $xpath = new DOMXPath($dom);

    $metaRobots = $xpath->query('//meta[@name="robots"]');
    if ($metaRobots->length > 0) {
        $c = strtolower($metaRobots->item(0)->getAttribute('content'));
        if (str_contains($c, 'noindex')) { $issues[] = 'INDEXABILITY: Meta robots "noindex" — excluded from search engines.'; $isBlocked = true; }
        if (str_contains($c, 'nofollow')) $warnings[] = 'Meta robots "nofollow" — links not followed.';
    }

    $xRobots = $headers['x-robots-tag'] ?? '';
    if ($xRobots !== '' && str_contains(strtolower($xRobots), 'noindex')) {
        $issues[] = 'INDEXABILITY: X-Robots-Tag header contains "noindex".'; $isBlocked = true;
    }

    $canonical = $xpath->query('//link[@rel="canonical"]');
    $canonUrl  = '';
    if ($canonical->length > 0) {
        $canonUrl  = trim($canonical->item(0)->getAttribute('href'));
        $pageHost  = parse_url($pageUrl, PHP_URL_HOST);
        $canonHost = parse_url($canonUrl, PHP_URL_HOST);
        if ($canonHost && $canonHost !== $pageHost) {
            $issues[] = "INDEXABILITY: Canonical points to different domain ({$canonUrl}).";
        } elseif ($canonUrl !== '' && $canonUrl !== $pageUrl) {
            $warnings[] = "Canonical ({$canonUrl}) differs from page URL.";
        }
    } else {
        $warnings[] = 'No canonical tag found.';
    }

    return ['is_blocked' => $isBlocked, 'canonical' => $canonUrl, 'issues' => $issues, 'warnings' => $warnings];
}

function auditSeoBasic(string $html): array
{
    $issues = []; $dom = parseDom($html); $xpath = new DOMXPath($dom);

    $titles = $xpath->query('//title');
    if ($titles->length === 0 || trim($titles->item(0)->textContent) === '') { $issues[] = 'SEO: Missing or empty <title>.'; }
    else { $l = mb_strlen(trim($titles->item(0)->textContent)); if ($l < 10) $issues[] = "SEO: <title> too short ({$l})."; if ($l > 70) $issues[] = "SEO: <title> too long ({$l})."; }

    $desc = $xpath->query('//meta[@name="description"]');
    if ($desc->length === 0 || trim($desc->item(0)->getAttribute('content')) === '') { $issues[] = 'SEO: Missing or empty meta description.'; }
    else { $l = mb_strlen($desc->item(0)->getAttribute('content')); if ($l < 50) $issues[] = "SEO: Meta description too short ({$l})."; if ($l > 165) $issues[] = "SEO: Meta description too long ({$l})."; }

    $h1 = $xpath->query('//h1')->length;
    if ($h1 === 0) $issues[] = 'SEO: Missing <h1>.'; elseif ($h1 > 1) $issues[] = "SEO: Multiple <h1> tags ({$h1}).";

    if ($xpath->query('//meta[@property="og:title"]')->length === 0) $issues[] = 'SEO: Missing og:title.';
    if ($xpath->query('//meta[@property="og:description"]')->length === 0) $issues[] = 'SEO: Missing og:description.';

    return ['issues' => $issues];
}

function checkKeywordConsistency(string $html, array $keywords): array
{
    $issues = []; $dom = parseDom($html); $xpath = new DOMXPath($dom);
    $title   = strtolower(trim($xpath->query('//title')->item(0)?->textContent ?? ''));
    $h1      = strtolower(trim($xpath->query('//h1'  )->item(0)?->textContent ?? ''));
    $metaDesc= strtolower($xpath->query('//meta[@name="description"]')->item(0)?->getAttribute('content') ?? '');
    $firstP  = '';
    $paras   = $xpath->query('//p');
    for ($i = 0; $i < min(3, $paras->length); $i++) {
        $t = trim($paras->item($i)->textContent);
        if (mb_strlen($t) > 40) { $firstP = strtolower($t); break; }
    }
    $results = [];
    foreach ($keywords as $kw) {
        $kw = strtolower(trim($kw));
        if ($kw === '') continue;
        $r = ['keyword' => $kw, 'in_title' => str_contains($title, $kw), 'in_h1' => str_contains($h1, $kw), 'in_desc' => str_contains($metaDesc, $kw), 'in_first_p' => str_contains($firstP, $kw)];
        $results[] = $r;
        if (!$r['in_title']) $issues[] = "KEYWORD: \"{$kw}\" not in <title>.";
        if (!$r['in_h1'])    $issues[] = "KEYWORD: \"{$kw}\" not in <h1>.";
        if (!$r['in_desc'])  $issues[] = "KEYWORD: \"{$kw}\" not in meta description.";
    }
    return ['issues' => $issues, 'results' => $results];
}

function analyzeChangeImpact(array $prev, string $newHtml): array
{
    $issues = []; $impactLevel = 'low'; $changes = [];
    $dom = parseDom($newHtml); $xpath = new DOMXPath($dom);
    $newTitle = trim($xpath->query('//title')->item(0)?->textContent ?? '');
    $newDesc  = trim($xpath->query('//meta[@name="description"]')->item(0)?->getAttribute('content') ?? '');
    $newH1    = trim($xpath->query('//h1')->item(0)?->textContent ?? '');
    $newCanon = trim($xpath->query('//link[@rel="canonical"]')->item(0)?->getAttribute('href') ?? '');

    if (($prev['title'] ?? '') !== '' && $newTitle !== ($prev['title'] ?? '')) { $changes[] = 'title'; $issues[] = "CHANGE: <title> changed → \"{$newTitle}\""; $impactLevel = 'high'; }
    if (($prev['description'] ?? '') !== '' && $newDesc !== ($prev['description'] ?? ''))  { $changes[] = 'meta_description'; $issues[] = 'CHANGE: Meta description changed.'; $impactLevel = max_impact($impactLevel, 'medium'); }
    if (($prev['h1'] ?? '') !== '' && $newH1 !== ($prev['h1'] ?? ''))          { $changes[] = 'h1'; $issues[] = "CHANGE: <h1> changed → \"{$newH1}\""; $impactLevel = max_impact($impactLevel, 'high'); }
    if (($prev['canonical'] ?? '') !== '' && $newCanon !== ($prev['canonical'] ?? ''))     { $changes[] = 'canonical'; $issues[] = "CHANGE: Canonical changed → \"{$newCanon}\""; $impactLevel = max_impact($impactLevel, 'high'); }
    if (empty($changes)) $issues[] = 'CHANGE: HTML changed (no critical SEO tags affected).';
    return ['changes' => $changes, 'issues' => $issues, 'impact_level' => $impactLevel];
}

function max_impact(string $current, string $new): string
{
    $rank = ['low' => 0, 'medium' => 1, 'high' => 2];
    return ($rank[$new] ?? 0) >= ($rank[$current] ?? 0) ? $new : $current;
}

function detectSerpRisks(int $httpStatus, array $indexability, array $changeAnalysis, array $snapshot): array
{
    $risks = [];
    if (in_array($httpStatus, [403, 404, 500, 503], true)) $risks[] = "SERP RISK: HTTP {$httpStatus} — may cause de-indexing.";
    if (!empty($indexability['is_blocked'])) $risks[] = 'SERP RISK: Page blocked from indexing.';
    if (!empty($changeAnalysis['impact_level']) && $changeAnalysis['impact_level'] === 'high') {
        $risks[] = 'SERP RISK: High-impact content changes detected.';
    }
    return $risks;
}

function calculateSeoScore(array $issues, array $vitals, array $indexability, int $httpStatus): int
{
    $score = 100;
    if (in_array($httpStatus, [500, 503], true)) $score -= 40;
    elseif ($httpStatus === 404)                 $score -= 35;
    elseif (in_array($httpStatus, [403, 401], true)) $score -= 20;
    elseif ($httpStatus !== 200)                 $score -= 10;
    if (!empty($indexability['is_blocked']))     $score -= 30;
    $sc = $vitals['speed_class'] ?? 'Fast';
    if ($sc === 'Slow')   $score -= 15; elseif ($sc === 'Medium') $score -= 5;
    foreach ($issues as $i) {
        $score -= match ($i['seo_impact'] ?? 'low') { 'high' => 10, 'medium' => 5, default => 2 };
    }
    return max(0, min(100, $score));
}

function maybeSendTelegram(string $label, string $url, string $group, array $issues, int $ms, int $seoScore, array &$alertState, array $CFG): void
{
    $token  = $CFG['telegram_bot_token'] ?? '';
    $chatId = $CFG['telegram_chat_id']   ?? '';
    if ($token === '' || $token === 'YOUR_BOT_TOKEN_HERE') return;

    $key = md5($url); $now = time();
    $cooldown = (int)($CFG['alert_cooldown_min'] ?? 60) * 60;
    if (isset($alertState[$key]) && ($now - $alertState[$key]) < $cooldown) return;
    $alertState[$key] = $now;

    $lines = '';
    foreach ($issues as $i) {
        $icon = match ($i['seo_impact'] ?? 'low') { 'high' => '🔴', 'medium' => '🟡', default => '⚪' };
        $lines .= "  {$icon} [{$i['type']}] {$i['detail']}\n";
    }
    $scoreEmoji = $seoScore >= 80 ? '🟢' : ($seoScore >= 50 ? '🟡' : '🔴');

    $msg = "🚨 *Monitor Alert v4*\n━━━━━━━━━━━━━━━━━━━━\n"
         . "📁 *{$group}* › {$label}\n🔗 {$url}\n"
         . "⏱ {$ms}ms  {$scoreEmoji} SEO: {$seoScore}/100\n"
         . "🕐 " . date('Y-m-d H:i:s') . "\n\n*Issues:*\n{$lines}";

    $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['chat_id' => $chatId, 'text' => $msg, 'parse_mode' => 'Markdown'], CURLOPT_TIMEOUT => 10]);
    curl_exec($ch); curl_close($ch);
}

function buildLatestSnapshot(array $runResults, array $history, array $urlGroups, string $ts): void
{
    $urls = []; $now = strtotime($ts);
    foreach ($runResults as $r) {
        $key     = md5($r['url']);
        $entries = $history[$key]['entries'] ?? [];
        $spark   = array_map(fn($e) => $e['ms'],        array_slice($entries, -24));
        $sScores = array_map(fn($e) => $e['seo_score'] ?? 100, array_slice($entries, -24));
        $c24h = $c7d = 0;
        foreach ($entries as $e) {
            $age = $now - strtotime($e['ts']);
            if ($e['issue_count'] > 0) { if ($age <= 86400) $c24h++; if ($age <= 604800) $c7d++; }
        }
        $urls[$key] = array_merge($r, ['sparkline' => $spark, 'score_sparkline' => $sScores, 'errors_24h' => $c24h, 'errors_7d' => $c7d, 'last_checked' => $ts]);
    }

    $groups = [];
    foreach ($urls as $u) {
        $g = $u['group'];
        if (!isset($groups[$g])) $groups[$g] = ['name' => $g, 'urls' => [], 'avg_ms' => 0, 'avg_seo' => 0, 'errors_24h' => 0, 'errors_7d' => 0, 'total' => 0, 'ok' => 0];
        $groups[$g]['urls'][] = md5($u['url']);
        $groups[$g]['errors_24h'] += $u['errors_24h'];
        $groups[$g]['errors_7d']  += $u['errors_7d'];
        $groups[$g]['total']++;
        if ($u['error_category'] === 'OK') $groups[$g]['ok']++;
    }
    foreach ($groups as $gName => &$gd) {
        $ms  = array_filter(array_map(fn($k) => $urls[$k]['response_ms'] ?? null, $gd['urls']));
        $seo = array_filter(array_map(fn($k) => $urls[$k]['seo_score']   ?? null, $gd['urls']));
        $gd['avg_ms']  = count($ms)  ? (int) round(array_sum($ms)  / count($ms))  : 0;
        $gd['avg_seo'] = count($seo) ? (int) round(array_sum($seo) / count($seo)) : 0;
    }
    unset($gd);

    saveJson(LOG_DIR . '/latest.json', ['ts' => $ts, 'urls' => $urls, 'groups' => array_values($groups)]);
}

// DOM helpers
function parseDom(string $html): DOMDocument { libxml_use_internal_errors(true); $d = new DOMDocument(); $d->loadHTML('<?xml encoding="UTF-8">' . $html); libxml_clear_errors(); return $d; }
function extractText(string $html, string $xq): string { $x = new DOMXPath(parseDom($html)); $n = $x->query($xq); return $n->length > 0 ? trim($n->item(0)->textContent) : ''; }
function extractMeta(string $html, string $name): string { $x = new DOMXPath(parseDom($html)); $n = $x->query("//meta[@name=\"{$name}\"]"); return $n->length > 0 ? trim($n->item(0)->getAttribute('content')) : ''; }
function extractLinkHref(string $html, string $rel): string { $x = new DOMXPath(parseDom($html)); $n = $x->query("//link[@rel=\"{$rel}\"]"); return $n->length > 0 ? trim($n->item(0)->getAttribute('href')) : ''; }

function loadJson(string $file, mixed $default = []): mixed { if (!file_exists($file)) return $default; $d = json_decode(file_get_contents($file), true); return $d !== null ? $d : $default; }
function saveJson(string $file, mixed $data): void { file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }