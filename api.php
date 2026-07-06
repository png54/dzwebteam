<?php
/**
 * DZ Web Team — Gemini AI Secure Proxy
 * =====================================================================
 * Proxies chat requests to Google Gemini API. The key never reaches
 * the browser. Works on InfinityFree, cPanel, and any shared host.
 *
 * SETUP:
 * 1. Get a free key at https://aistudio.google.com/
 * 2. Replace YOUR_GEMINI_API_KEY_HERE below with your real key.
 *    — OR — use the ⚙️ icon in the chat widget to paste it directly
 *    (it is stored only in your own browser, not on the server).
 */

// ─── User configuration ───────────────────────────────────────────────
define('GEMINI_API_KEY',    'YOUR_GEMINI_API_KEY_HERE');
define('GEMINI_MODEL',      'gemini-2.5-flash');
define('RATE_LIMIT_MAX',    20);       // max requests per IP per window
define('RATE_LIMIT_WINDOW', 60);       // window length in seconds
define('MAX_MSG_CHARS',     2000);     // max user-message length (UTF-8 chars)
define('CURL_TIMEOUT',      25);       // Gemini call timeout in seconds
// ──────────────────────────────────────────────────────────────────────

// ─── Headers ──────────────────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Gemini-Key');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed']));
}

// ─── Helper ───────────────────────────────────────────────────────────
function respond(string $text, int $code = 200): never {
    http_response_code($code);
    echo json_encode(['text' => $text], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ─── Rate limiting (file-based — works everywhere) ────────────────────
function isRateLimited(string $ip): bool {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dzwt_rl';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false; // skip if can't create
    $f   = $dir . DIRECTORY_SEPARATOR . md5($ip) . '.json';
    $now = time();
    $d   = ['n' => 0, 't' => $now];
    if (is_file($f) && ($raw = @file_get_contents($f))) {
        $d = json_decode($raw, true) ?: $d;
    }
    if ($now - $d['t'] >= RATE_LIMIT_WINDOW) { $d = ['n' => 0, 't' => $now]; }
    $d['n']++;
    @file_put_contents($f, json_encode($d), LOCK_EX);
    return $d['n'] > RATE_LIMIT_MAX;
}

$ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')[0]);
if (isRateLimited($ip)) {
    respond('لقد تجاوزت الحد المسموح به من الرسائل. يرجى الانتظار دقيقة ثم المحاولة مجدداً.', 429);
}

// ─── Resolve API key ──────────────────────────────────────────────────
$apiKey = GEMINI_API_KEY;

if ($apiKey === 'YOUR_GEMINI_API_KEY_HERE' || $apiKey === '') {
    // Fallback: client-supplied key stored in browser
    foreach (getallheaders() as $k => $v) {
        if (strtolower($k) === 'x-gemini-key' && trim($v) !== '') {
            $apiKey = trim($v);
            break;
        }
    }
}

if ($apiKey === 'YOUR_GEMINI_API_KEY_HERE' || $apiKey === '') {
    $guide =
        "مرحباً بك! لتفعيل المستشار الذكي:\n\n" .
        "1️⃣ افتح ملف `api.php` في مدير الملفات واستبدل `YOUR_GEMINI_API_KEY_HERE` بمفتاحك.\n" .
        "2️⃣ أو اضغط ⚙️ في أعلى نافذة الدردشة والصق مفتاحك مباشرة (يُحفظ على جهازك فقط).\n\n" .
        "💡 احصل على مفتاح مجاني من: https://aistudio.google.com/";
    respond($guide);
}

// Basic key sanity check (no spaces, reasonable length, printable ASCII)
if (strlen($apiKey) < 8 || strlen($apiKey) > 512 || preg_match('/\s/', $apiKey)) {
    respond('مفتاح API يبدو غير صحيح. يرجى التحقق منه وإعادة المحاولة.', 400);
}

// ─── Parse & validate input ───────────────────────────────────────────
$raw = file_get_contents('php://input', false, null, 0, 16384); // max 16 KB
if (!$raw) { respond('طلب فارغ.', 400); }

$body = json_decode($raw, true);
$msg  = isset($body['message']) ? trim((string)$body['message']) : '';

if ($msg === '') { respond('الرجاء كتابة رسالة للبدء.', 400); }

if (mb_strlen($msg, 'UTF-8') > MAX_MSG_CHARS) {
    respond('رسالتك طويلة جداً. يرجى اختصارها في أقل من ' . MAX_MSG_CHARS . ' حرف.', 400);
}

// ─── Build Gemini request (system_instruction kept separate) ──────────
$systemText =
    "You are the Elite Smart Assistant for 'DZ Web Team', an ultra-premium Algerian web design and " .
    "development agency specializing in high-performance websites built with React, Tailwind CSS, and Figma. " .
    "Respond elegantly and persuasively in the same language the user writes in (Arabic, French, or English). " .
    "Guide clients toward the Portfolio, Services page, and interactive Cost Calculator in the navigation bar. " .
    "Encourage them to click the WhatsApp button for direct consultation. " .
    "For technical or pricing questions, give precise, premium-tier details.";

$payload = json_encode([
    'system_instruction' => ['parts' => [['text' => $systemText]]],
    'contents'           => [['role' => 'user', 'parts' => [['text' => $msg]]]],
    'generationConfig'   => ['maxOutputTokens' => 1024, 'temperature' => 0.75],
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

$url = sprintf(
    'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
    rawurlencode(GEMINI_MODEL),
    rawurlencode($apiKey)
);

// ─── cURL call ────────────────────────────────────────────────────────
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => CURL_TIMEOUT,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_MAXREDIRS      => 0,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false || $curlErr !== '') {
    respond('عذراً، حدث خطأ في الاتصال بخوادم الذكاء الاصطناعي. يرجى المحاولة مجدداً.', 502);
}

$data = json_decode($response, true);

if ($httpCode !== 200) {
    $errMsg = $data['error']['message'] ?? 'خطأ غير محدد من خوادم Google.';
    respond('خطأ من Google Gemini: ' . $errMsg, 502);
}

$aiText = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

if ($aiText === null || $aiText === '') {
    respond('عذراً، لم أتمكن من توليد رد. يرجى المحاولة مجدداً أو التحقق من صلاحية مفتاح API.', 502);
}

respond($aiText);
