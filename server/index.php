<?php
declare(strict_types=1);

const DEFAULT_API_KEY = 'change-me';
const HISTORY_LIMIT = 240;

$dataDir = __DIR__ . '/data';
$apiKey = getenv('HYDROSENSE_API_KEY') ?: DEFAULT_API_KEY;

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0775, true);
}

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function readJsonFile(string $path, array $fallback = []): array
{
    if (!is_file($path)) {
        return $fallback;
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : $fallback;
}

function writeJsonFile(string $path, array $payload): void
{
    file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function requireApiKey(string $apiKey): void
{
    $headerKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    $requestKey = $_POST['api_key'] ?? $_GET['api_key'] ?? '';
    if (!hash_equals($apiKey, (string) ($headerKey ?: $requestKey))) {
        jsonResponse(['ok' => false, 'error' => 'unauthorized'], 401);
    }
}

function requestJson(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        jsonResponse(['ok' => false, 'error' => 'invalid_json'], 400);
    }
    return $decoded;
}

function textValue(array $payload, string $key, string $fallback = ''): string
{
    $value = $payload[$key] ?? $fallback;
    return preg_replace('/[^a-zA-Z0-9_.:-]/', '-', (string) $value) ?: $fallback;
}

function numberValue(array $payload, string $key): int
{
    return (int) ($payload[$key] ?? 0);
}

function boolValue(array $payload, string $key): bool
{
    return filter_var($payload[$key] ?? false, FILTER_VALIDATE_BOOL);
}

function loadHistory(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $lines = array_slice($lines, -HISTORY_LIMIT);
    $history = [];
    foreach ($lines as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $history[] = $decoded;
        }
    }
    return $history;
}

function appendHistory(string $path, array $entry): void
{
    $history = loadHistory($path);
    $history[] = $entry;
    $history = array_slice($history, -HISTORY_LIMIT);
    $body = '';
    foreach ($history as $item) {
        $body .= json_encode($item, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
    file_put_contents($path, $body, LOCK_EX);
}

function commandPath(string $dataDir, string $deviceId): string
{
    return $dataDir . '/command-' . preg_replace('/[^a-zA-Z0-9_.-]/', '-', $deviceId) . '.json';
}

$statusPath = $dataDir . '/status.json';
$historyPath = $dataDir . '/history.jsonl';
$api = $_GET['api'] ?? '';

if ($api === 'telemetry') {
    requireApiKey($apiKey);
    $payload = requestJson();
    $entry = [
        'received_at' => gmdate('c'),
        'device_id' => textValue($payload, 'device_id', 'hydrosense-esp32'),
        'uptime_ms' => numberValue($payload, 'uptime_ms'),
        'moisture_percent' => numberValue($payload, 'moisture_percent'),
        'soil_raw' => numberValue($payload, 'soil_raw'),
        'soil_raw12' => numberValue($payload, 'soil_raw12'),
        'battery_mv' => numberValue($payload, 'battery_mv'),
        'battery_percent' => numberValue($payload, 'battery_percent'),
        'battery_adc_raw' => numberValue($payload, 'battery_adc_raw'),
        'pump_on' => boolValue($payload, 'pump_on'),
        'pump_mode' => textValue($payload, 'pump_mode', 'auto'),
        'needs_water' => boolValue($payload, 'needs_water'),
    ];
    writeJsonFile($statusPath, $entry);
    appendHistory($historyPath, $entry);
    jsonResponse(['ok' => true]);
}

if ($api === 'command') {
    requireApiKey($apiKey);
    $deviceId = textValue($_GET, 'device_id', 'hydrosense-esp32');
    $command = readJsonFile(commandPath($dataDir, $deviceId), ['command_id' => 0]);
    if (($command['acked_at'] ?? null) !== null) {
        jsonResponse(['ok' => true, 'command_id' => 0, 'pump' => null]);
    }
    jsonResponse([
        'ok' => true,
        'command_id' => (int) ($command['command_id'] ?? 0),
        'pump' => $command['pump'] ?? null,
    ]);
}

if ($api === 'ack') {
    requireApiKey($apiKey);
    $payload = requestJson();
    $deviceId = textValue($payload, 'device_id', 'hydrosense-esp32');
    $path = commandPath($dataDir, $deviceId);
    $command = readJsonFile($path, []);
    if ((int) ($command['command_id'] ?? 0) === numberValue($payload, 'command_id')) {
        $command['acked_at'] = gmdate('c');
        $command['reported_pump_on'] = boolValue($payload, 'pump_on');
        $command['reported_pump_mode'] = textValue($payload, 'pump_mode', '');
        writeJsonFile($path, $command);
    }
    jsonResponse(['ok' => true]);
}

$dashboardError = '';
$dashboardKey = (string) ($_POST['api_key'] ?? $_GET['api_key'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pump'])) {
    if (!hash_equals($apiKey, $dashboardKey)) {
        $dashboardError = 'API key ist falsch.';
    } else {
        $status = readJsonFile($statusPath, ['device_id' => 'hydrosense-esp32']);
        $deviceId = textValue($status, 'device_id', 'hydrosense-esp32');
        $pump = in_array($_POST['pump'], ['on', 'off', 'auto'], true) ? $_POST['pump'] : 'auto';
        $previous = readJsonFile(commandPath($dataDir, $deviceId), ['command_id' => 0]);
        writeJsonFile(commandPath($dataDir, $deviceId), [
            'command_id' => ((int) ($previous['command_id'] ?? 0)) + 1,
            'device_id' => $deviceId,
            'pump' => $pump,
            'created_at' => gmdate('c'),
            'acked_at' => null,
        ]);
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?api_key=' . rawurlencode($dashboardKey));
        exit;
    }
}

$status = readJsonFile($statusPath, []);
$history = loadHistory($historyPath);
$lastSeen = isset($status['received_at']) ? strtotime((string) $status['received_at']) : 0;
$isOnline = $lastSeen > 0 && time() - $lastSeen < 90;
$deviceId = textValue($status, 'device_id', 'hydrosense-esp32');
$command = readJsonFile(commandPath($dataDir, $deviceId), []);
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="refresh" content="15">
  <title>HydroSense</title>
  <style>
    :root { color-scheme: light; font-family: system-ui, -apple-system, Segoe UI, sans-serif; background: #f4f7f6; color: #17211d; }
    body { margin: 0; }
    main { max-width: 1120px; margin: 0 auto; padding: 28px 18px 40px; }
    header { display: flex; align-items: end; justify-content: space-between; gap: 16px; margin-bottom: 22px; }
    h1 { margin: 0; font-size: clamp(28px, 5vw, 46px); line-height: 1; letter-spacing: 0; }
    .muted { color: #63736c; }
    .status-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: <?= $isOnline ? '#10894e' : '#a33b2b' ?>; margin-right: 8px; }
    .grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
    .card { background: #fff; border: 1px solid #d9e4df; border-radius: 8px; padding: 16px; box-shadow: 0 1px 2px rgb(20 40 32 / 5%); }
    .label { font-size: 13px; color: #63736c; margin-bottom: 8px; }
    .value { font-size: clamp(28px, 5vw, 44px); font-weight: 750; line-height: 1; }
    .wide { grid-column: span 3; }
    .controls { grid-column: span 1; }
    canvas { width: 100%; height: 320px; display: block; }
    form { display: grid; gap: 10px; }
    input { width: 100%; box-sizing: border-box; border: 1px solid #c6d5ce; border-radius: 6px; padding: 10px 12px; font: inherit; }
    button { border: 0; border-radius: 6px; padding: 12px 14px; font: inherit; font-weight: 700; cursor: pointer; background: #1d6f54; color: #fff; }
    button.secondary { background: #40534b; }
    button.danger { background: #a43d2d; }
    .error { color: #a43d2d; font-weight: 700; }
    @media (max-width: 820px) { header { display: block; } .grid { grid-template-columns: 1fr 1fr; } .wide, .controls { grid-column: span 2; } }
    @media (max-width: 520px) { .grid { grid-template-columns: 1fr; } .wide, .controls { grid-column: span 1; } }
  </style>
</head>
<body>
<main>
  <header>
    <div>
      <h1>HydroSense</h1>
      <div class="muted"><span class="status-dot"></span><?= $isOnline ? 'online' : 'offline' ?> · <?= htmlspecialchars($deviceId) ?></div>
    </div>
    <div class="muted">Letztes Update: <?= htmlspecialchars($status['received_at'] ?? 'noch keine Daten') ?></div>
  </header>

  <?php if ($dashboardError): ?><p class="error"><?= htmlspecialchars($dashboardError) ?></p><?php endif; ?>

  <section class="grid">
    <div class="card">
      <div class="label">Feuchtigkeit</div>
      <div class="value"><?= (int) ($status['moisture_percent'] ?? 0) ?>%</div>
    </div>
    <div class="card">
      <div class="label">Batterie</div>
      <div class="value"><?= (int) ($status['battery_percent'] ?? 0) ?>%</div>
      <div class="muted"><?= number_format(((int) ($status['battery_mv'] ?? 0)) / 1000, 2) ?> V</div>
    </div>
    <div class="card">
      <div class="label">Pumpe</div>
      <div class="value"><?= !empty($status['pump_on']) ? 'AN' : 'AUS' ?></div>
      <div class="muted"><?= htmlspecialchars($status['pump_mode'] ?? 'auto') ?></div>
    </div>
    <div class="card">
      <div class="label">Sensor raw</div>
      <div class="value"><?= (int) ($status['soil_raw'] ?? 0) ?></div>
      <div class="muted">ADC12 <?= (int) ($status['soil_raw12'] ?? 0) ?></div>
    </div>

    <div class="card wide">
      <div class="label">Verlauf</div>
      <canvas id="history" width="900" height="320"></canvas>
    </div>

    <div class="card controls">
      <div class="label">Befehl senden</div>
      <form method="post">
        <input name="api_key" type="password" value="<?= htmlspecialchars($dashboardKey) ?>" placeholder="API key">
        <button name="pump" value="on">Pumpe an</button>
        <button class="danger" name="pump" value="off">Pumpe aus</button>
        <button class="secondary" name="pump" value="auto">Auto</button>
      </form>
      <p class="muted">Letzter Befehl: <?= htmlspecialchars($command['pump'] ?? 'keiner') ?> <?= empty($command['acked_at']) ? '' : '· bestaetigt' ?></p>
    </div>
  </section>
</main>

<script>
const historyData = <?= json_encode($history, JSON_UNESCAPED_SLASHES) ?>;
const canvas = document.getElementById('history');
const ctx = canvas.getContext('2d');
const w = canvas.width;
const h = canvas.height;
const pad = 36;
ctx.clearRect(0, 0, w, h);
ctx.strokeStyle = '#d7e1dc';
ctx.lineWidth = 1;
ctx.font = '14px system-ui';
ctx.fillStyle = '#63736c';
for (const p of [0, 25, 50, 75, 100]) {
  const y = h - pad - (p / 100) * (h - pad * 2);
  ctx.beginPath();
  ctx.moveTo(pad, y);
  ctx.lineTo(w - pad, y);
  ctx.stroke();
  ctx.fillText(`${p}%`, 6, y + 4);
}
if (historyData.length > 1) {
  ctx.strokeStyle = '#1d6f54';
  ctx.lineWidth = 3;
  ctx.beginPath();
  historyData.forEach((item, index) => {
    const x = pad + index * (w - pad * 2) / (historyData.length - 1);
    const y = h - pad - Math.max(0, Math.min(100, item.moisture_percent || 0)) / 100 * (h - pad * 2);
    if (index === 0) ctx.moveTo(x, y);
    else ctx.lineTo(x, y);
  });
  ctx.stroke();
}
</script>
</body>
</html>
