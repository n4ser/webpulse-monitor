<?php
/**
 * ============================================================
 *  Monitor API v4  (api.php)
 *  All dashboard data + settings management goes through here.
 *
 *  Auth: pass token as GET param or X-Token header.
 *  DASHBOARD_TOKEN is the only hardcoded value in the system.
 * ============================================================
 */

declare(strict_types=1);

// ── Only hardcoded constant in the entire system ──────────────
const DASHBOARD_TOKEN = '024sfsdfs';
const LOG_DIR         = __DIR__ . '/logs';
const SETTINGS_FILE   = LOG_DIR . '/settings.json';

// ── CORS preflight ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: X-Token, X-Monitor-Token, Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    exit;
}

// ── Auth ──────────────────────────────────────────────────────
$token = $_SERVER['HTTP_X_TOKEN']
    ?? $_SERVER['HTTP_X_MONITOR_TOKEN']
    ?? $_GET['token']
    ?? '';

if ($token !== DASHBOARD_TOKEN) {
    http_response_code(401);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Unauthorized']));
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$action = $_GET['action'] ?? 'latest';
$method = $_SERVER['REQUEST_METHOD'];

// ── Route ─────────────────────────────────────────────────────
if ($action === 'history')      { serveHistory(); }
elseif ($action === 'trend')    { serveTrend(); }
elseif ($action === 'seo_scores')  { serveSeoScores(); }
elseif ($action === 'settings') { handleSettings($method); }
elseif ($action === 'groups')   { handleGroups($method); }
elseif ($action === 'delete_url')   { handleDeleteUrl(); }
elseif ($action === 'delete_group') { handleDeleteGroup(); }
else { serveLatest(); }


// ─────────────────────────────────────────────────────────────
//  LATEST  — fast snapshot for dashboard homepage
// ─────────────────────────────────────────────────────────────
function serveLatest(): void
{
    $file = LOG_DIR . '/latest.json';
    echo file_exists($file)
        ? file_get_contents($file)
        : json_encode(['ts' => null, 'urls' => [], 'groups' => []]);
}


// ─────────────────────────────────────────────────────────────
//  HISTORY  ?action=history&key=<md5>&limit=200
// ─────────────────────────────────────────────────────────────
function serveHistory(): void
{
    $key   = $_GET['key']   ?? '';
    $limit = (int)($_GET['limit'] ?? 200);

    if (!$key) { echo json_encode(['error' => 'key required']); return; }

    $history = loadJson(LOG_DIR . '/history.json', []);
    if (!isset($history[$key])) { echo json_encode([]); return; }

    $entries = array_slice($history[$key]['entries'] ?? [], -$limit);
    echo json_encode([
        'url'     => $history[$key]['url']   ?? '',
        'label'   => $history[$key]['label'] ?? '',
        'entries' => $entries,
    ]);
}


// ─────────────────────────────────────────────────────────────
//  TREND  ?action=trend&key=<md5>&days=30
// ─────────────────────────────────────────────────────────────
function serveTrend(): void
{
    $days    = min((int)($_GET['days'] ?? 30), 90);
    $key     = $_GET['key'] ?? '';
    $history = loadJson(LOG_DIR . '/history.json', []);
    $sources = $key
        ? (isset($history[$key]) ? [$key => $history[$key]] : [])
        : $history;

    $now    = time();
    $result = [];

    foreach ($sources as $k => $data) {
        $daily = [];
        foreach ($data['entries'] ?? [] as $e) {
            if ($now - strtotime($e['ts']) > $days * 86400) continue;
            $day = substr($e['ts'], 0, 10);
            if (!isset($daily[$day])) {
                $daily[$day] = ['date' => $day, 'samples' => [], 'seo_samples' => [], 'errors' => 0, 'categories' => []];
            }
            $daily[$day]['samples'][]     = $e['ms'];
            $daily[$day]['seo_samples'][] = $e['seo_score'] ?? 100;
            if ($e['issue_count'] > 0) $daily[$day]['errors']++;
            $cat = $e['category'] ?? 'OK';
            $daily[$day]['categories'][$cat] = ($daily[$day]['categories'][$cat] ?? 0) + 1;
        }

        $aggregated = [];
        foreach ($daily as $d) {
            $aggregated[] = [
                'date'      => $d['date'],
                'avg_ms'    => count($d['samples'])     ? (int)round(array_sum($d['samples'])     / count($d['samples']))     : 0,
                'max_ms'    => count($d['samples'])     ? max($d['samples'])                                                   : 0,
                'avg_seo'   => count($d['seo_samples']) ? (int)round(array_sum($d['seo_samples']) / count($d['seo_samples'])) : 100,
                'errors'    => $d['errors'],
                'checks'    => count($d['samples']),
                'categories'=> $d['categories'],
            ];
        }
        usort($aggregated, fn($a, $b) => strcmp($a['date'], $b['date']));

        $result[$k] = [
            'url'   => $data['url']   ?? '',
            'label' => $data['label'] ?? '',
            'group' => $data['group'] ?? '',
            'trend' => $aggregated,
        ];
    }

    echo json_encode($key ? ($result[$key] ?? []) : $result);
}


// ─────────────────────────────────────────────────────────────
//  SEO SCORES  ?action=seo_scores&key=<md5>
// ─────────────────────────────────────────────────────────────
function serveSeoScores(): void
{
    $key       = $_GET['key'] ?? '';
    $seoScores = loadJson(LOG_DIR . '/seo_scores.json', []);

    if ($key) {
        echo json_encode($seoScores[$key] ?? []);
    } else {
        // Return summary: latest score per URL
        $summary = [];
        foreach ($seoScores as $k => $data) {
            $daily  = $data['daily'] ?? [];
            $latest = end($daily) ?: 0;
            $summary[$k] = ['url' => $data['url'], 'latest_score' => $latest, 'history' => $daily];
        }
        echo json_encode($summary);
    }
}


// ─────────────────────────────────────────────────────────────
//  SETTINGS  GET → read, POST → write
//  Manages all non-token config values
// ─────────────────────────────────────────────────────────────
function handleSettings(string $method): void
{
    if ($method === 'GET') {
        $settings = loadJson(SETTINGS_FILE, defaultSettings());
        // Never expose tokens to the frontend
        unset($settings['dashboard_token']);
        echo json_encode($settings);
        return;
    }

    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) { http_response_code(400); echo json_encode(['error' => 'Invalid JSON']); return; }

        $current  = loadJson(SETTINGS_FILE, defaultSettings());
        $allowed  = ['telegram_bot_token', 'telegram_chat_id', 'slow_threshold_ms', 'ttfb_slow_ms',
                     'max_retries', 'retry_delay_sec', 'request_timeout', 'alert_cooldown_min',
                     'seo_score_drop_pct', 'max_history_entries'];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $body)) {
                $current[$key] = $body[$key];
            }
        }

        // Never let POST overwrite dashboard_token
        unset($current['dashboard_token']);

        saveJson(SETTINGS_FILE, $current);
        echo json_encode(['ok' => true, 'saved' => count($body)]);
        return;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}


// ─────────────────────────────────────────────────────────────
//  GROUPS / URL MANAGEMENT
//  GET  → list all groups & URLs
//  POST → add or update a URL (body: {group, url, label, ...flags})
// ─────────────────────────────────────────────────────────────
function handleGroups(string $method): void
{
    $settings = loadJson(SETTINGS_FILE, defaultSettings());

    if ($method === 'GET') {
        echo json_encode($settings['url_groups'] ?? []);
        return;
    }

    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body) || empty($body['url']) || empty($body['group'])) {
            http_response_code(400);
            echo json_encode(['error' => 'url and group are required']);
            return;
        }

        $group = (string)$body['group'];
        if (!isset($settings['url_groups'][$group])) {
            $settings['url_groups'][$group] = [];
        }

        // Check if URL already exists in this group (update) or add new
        $found = false;
        foreach ($settings['url_groups'][$group] as &$entry) {
            if ($entry['url'] === $body['url']) {
                $entry  = sanitizeUrlEntry($body);
                $found  = true;
                break;
            }
        }
        unset($entry);

        if (!$found) {
            $settings['url_groups'][$group][] = sanitizeUrlEntry($body);
        }

        saveJson(SETTINGS_FILE, $settings);
        echo json_encode(['ok' => true, 'group' => $group, 'url' => $body['url'], 'action' => $found ? 'updated' : 'added']);
        return;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}


// ─────────────────────────────────────────────────────────────
//  DELETE URL  POST ?action=delete_url  body: {group, url}
// ─────────────────────────────────────────────────────────────
function handleDeleteUrl(): void
{
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || empty($body['url']) || empty($body['group'])) {
        http_response_code(400); echo json_encode(['error' => 'url and group required']); return;
    }

    $settings = loadJson(SETTINGS_FILE, defaultSettings());
    $group    = (string)$body['group'];

    if (!isset($settings['url_groups'][$group])) {
        echo json_encode(['error' => 'Group not found']); return;
    }

    $before = count($settings['url_groups'][$group]);
    $settings['url_groups'][$group] = array_values(
        array_filter($settings['url_groups'][$group], fn($e) => $e['url'] !== $body['url'])
    );
    $after = count($settings['url_groups'][$group]);

    // Remove empty group
    if (empty($settings['url_groups'][$group])) {
        unset($settings['url_groups'][$group]);
    }

    saveJson(SETTINGS_FILE, $settings);
    echo json_encode(['ok' => true, 'removed' => $before - $after]);
}


// ─────────────────────────────────────────────────────────────
//  DELETE GROUP  POST ?action=delete_group  body: {group}
// ─────────────────────────────────────────────────────────────
function handleDeleteGroup(): void
{
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || empty($body['group'])) {
        http_response_code(400); echo json_encode(['error' => 'group required']); return;
    }

    $settings = loadJson(SETTINGS_FILE, defaultSettings());
    $group    = (string)$body['group'];

    if (!isset($settings['url_groups'][$group])) {
        echo json_encode(['error' => 'Group not found']); return;
    }

    unset($settings['url_groups'][$group]);
    saveJson(SETTINGS_FILE, $settings);
    echo json_encode(['ok' => true, 'deleted_group' => $group]);
}


// ─────────────────────────────────────────────────────────────
//  HELPERS
// ─────────────────────────────────────────────────────────────
function sanitizeUrlEntry(array $raw): array
{
    return [
        'url'                 => filter_var(trim($raw['url']), FILTER_SANITIZE_URL),
        'label'               => htmlspecialchars(trim($raw['label'] ?? $raw['url']), ENT_QUOTES, 'UTF-8'),
        'expected_status'     => (int)($raw['expected_status'] ?? 200),
        'check_seo'           => (bool)($raw['check_seo']           ?? false),
        'check_content'       => (bool)($raw['check_content']       ?? false),
        'check_indexability'  => (bool)($raw['check_indexability']  ?? false),
        'check_vitals'        => (bool)($raw['check_vitals']        ?? false),
        'check_keywords'      => (bool)($raw['check_keywords']      ?? false),
        'check_serp_risk'     => (bool)($raw['check_serp_risk']     ?? false),
        'check_change_impact' => (bool)($raw['check_change_impact'] ?? false),
        'keyword_present'     => trim($raw['keyword_present'] ?? ''),
        'target_keywords'     => array_values(array_filter(array_map('trim', (array)($raw['target_keywords'] ?? [])))),
    ];
}

function defaultSettings(): array
{
    return [
        'telegram_bot_token'  => '',
        'telegram_chat_id'    => '',
        'slow_threshold_ms'   => 3000,
        'ttfb_slow_ms'        => 600,
        'max_retries'         => 3,
        'retry_delay_sec'     => 5,
        'request_timeout'     => 20,
        'alert_cooldown_min'  => 60,
        'seo_score_drop_pct'  => 10,
        'max_history_entries' => 2000,
        'url_groups'          => [],
    ];
}

function loadJson(string $file, mixed $default = []): mixed
{
    if (!file_exists($file)) return $default;
    $d = json_decode(file_get_contents($file), true);
    return $d !== null ? $d : $default;
}

function saveJson(string $file, mixed $data): void
{
    @mkdir(dirname($file), 0755, true);
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}