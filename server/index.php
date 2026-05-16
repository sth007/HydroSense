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

function deviceFileId(string $deviceId): string
{
    return preg_replace('/[^a-zA-Z0-9_.-]/', '-', $deviceId) ?: 'hydrosense-esp32';
}

function statusPath(string $dataDir, string $deviceId): string
{
    return $dataDir . '/status-' . deviceFileId($deviceId) . '.json';
}

function historyPath(string $dataDir, string $deviceId): string
{
    return $dataDir . '/history-' . deviceFileId($deviceId) . '.jsonl';
}

function deviceStatus(array $status): array
{
    $lastSeen = isset($status['received_at']) ? strtotime((string) $status['received_at']) : 0;
    $age = $lastSeen > 0 ? time() - $lastSeen : PHP_INT_MAX;
    if ($age <= 90) {
        return ['key' => 'online', 'label' => 'online', 'color' => '#10894e'];
    }
    if ($age <= 900) {
        return ['key' => 'recent', 'label' => 'vor 15 Minuten online', 'color' => '#d98220'];
    }
    return ['key' => 'offline', 'label' => 'offline', 'color' => '#a33b2b'];
}

function loadDevices(string $dataDir): array
{
    $devices = [];
    foreach (glob($dataDir . '/status-*.json') ?: [] as $path) {
        $status = readJsonFile($path, []);
        if (!empty($status['device_id'])) {
            $devices[(string) $status['device_id']] = $status;
        }
    }

    $legacyStatus = readJsonFile($dataDir . '/status.json', []);
    if (!empty($legacyStatus['device_id']) && !isset($devices[(string) $legacyStatus['device_id']])) {
        $devices[(string) $legacyStatus['device_id']] = $legacyStatus;
    }

    uasort($devices, static function (array $a, array $b): int {
        return strcmp((string) ($a['device_id'] ?? ''), (string) ($b['device_id'] ?? ''));
    });
    return array_values($devices);
}

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
    writeJsonFile(statusPath($dataDir, $entry['device_id']), $entry);
    appendHistory(historyPath($dataDir, $entry['device_id']), $entry);
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
        $deviceId = textValue($_POST, 'device_id', 'hydrosense-esp32');
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

$devices = loadDevices($dataDir);
$deviceCount = count($devices);
$onlineCount = 0;
$recentCount = 0;
foreach ($devices as $device) {
    $state = deviceStatus($device);
    if ($state['key'] === 'online') {
        $onlineCount++;
    } elseif ($state['key'] === 'recent') {
        $recentCount++;
    }
}
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
    main { max-width: 1280px; margin: 0 auto; padding: 28px 18px 40px; }
    header { display: flex; align-items: end; justify-content: space-between; gap: 16px; margin-bottom: 22px; }
    h1 { margin: 0; font-size: clamp(28px, 5vw, 46px); line-height: 1; letter-spacing: 0; }
    .muted { color: #63736c; }
    .summary { display: flex; flex-wrap: wrap; gap: 8px; justify-content: flex-end; }
    .pill { background: #fff; border: 1px solid #d9e4df; border-radius: 999px; padding: 8px 12px; font-weight: 700; }
    .pump-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(310px, 1fr)); gap: 14px; align-items: start; }
    .card { background: #fff; border: 1px solid #d9e4df; border-radius: 8px; padding: 16px; box-shadow: 0 1px 2px rgb(20 40 32 / 5%); }
    .pump-head { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; margin-bottom: 16px; }
    .pump-title { min-width: 0; }
    .pump-title h2 { font-size: 20px; line-height: 1.1; margin: 0 0 5px; overflow-wrap: anywhere; }
    .status { display: inline-flex; align-items: center; gap: 7px; white-space: nowrap; font-size: 14px; font-weight: 750; }
    .status-dot { display: inline-block; width: 12px; height: 12px; border-radius: 50%; background: var(--status-color); box-shadow: 0 0 0 3px color-mix(in srgb, var(--status-color) 18%, transparent); }
    .metrics { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
    .metric { border: 1px solid #e1ebe6; border-radius: 7px; padding: 12px; min-width: 0; }
    .label { font-size: 13px; color: #63736c; margin-bottom: 8px; }
    .value { font-size: clamp(26px, 6vw, 40px); font-weight: 750; line-height: 1; overflow-wrap: anywhere; }
    canvas { width: 100%; height: 160px; display: block; margin-top: 12px; border-top: 1px solid #edf3f0; }
    form { display: grid; gap: 10px; }
    input { width: 100%; box-sizing: border-box; border: 1px solid #c6d5ce; border-radius: 6px; padding: 10px 12px; font: inherit; }
    .button-row { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
    button { border: 0; border-radius: 6px; padding: 12px 14px; font: inherit; font-weight: 700; cursor: pointer; background: #1d6f54; color: #fff; }
    button.secondary { background: #40534b; }
    button.danger { background: #a43d2d; }
    .error { color: #a43d2d; font-weight: 700; }
    .empty { padding: 28px; text-align: center; }
    @media (max-width: 720px) { header { display: block; } .summary { justify-content: flex-start; margin-top: 14px; } .pump-grid { grid-template-columns: 1fr; } }
    @media (max-width: 420px) { main { padding: 20px 10px 32px; } .card { padding: 13px; } .metrics { grid-template-columns: 1fr; } .button-row { grid-template-columns: 1fr; } .pump-head { display: block; } .status { margin-top: 10px; } }
  </style>
</head>
<body>
<main>
  <header>
    <div>
      <h1>HydroSense</h1>
      <div class="muted">Pumpen Dashboard</div>
    </div>
    <div class="summary">
      <div class="pill"><?= $deviceCount ?> Pumpen</div>
      <div class="pill"><?= $onlineCount ?> online</div>
      <div class="pill"><?= $recentCount ?> vor 15 Min.</div>
    </div>
  </header>

  <?php if ($dashboardError): ?><p class="error"><?= htmlspecialchars($dashboardError) ?></p><?php endif; ?>

  <section class="pump-grid">
    <?php if (!$devices): ?>
      <div class="card empty">Noch keine Pumpe hat sich per API gemeldet.</div>
    <?php endif; ?>
    <?php foreach ($devices as $device): ?>
      <?php
        $deviceId = textValue($device, 'device_id', 'hydrosense-esp32');
        $state = deviceStatus($device);
        $history = loadHistory(historyPath($dataDir, $deviceId));
        $command = readJsonFile(commandPath($dataDir, $deviceId), []);
        $canvasId = 'history-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $deviceId);
      ?>
      <article class="card">
        <div class="pump-head">
          <div class="pump-title">
            <h2><?= htmlspecialchars($deviceId) ?></h2>
            <div class="muted">Letztes Update: <?= htmlspecialchars($device['received_at'] ?? 'unbekannt') ?></div>
          </div>
          <div class="status" style="--status-color: <?= htmlspecialchars($state['color']) ?>">
            <span class="status-dot" aria-hidden="true"></span>
            <?= htmlspecialchars($state['label']) ?>
          </div>
        </div>

        <div class="metrics">
          <div class="metric">
            <div class="label">Feuchtigkeit</div>
            <div class="value"><?= (int) ($device['moisture_percent'] ?? 0) ?>%</div>
          </div>
          <div class="metric">
            <div class="label">Batterie</div>
            <div class="value"><?= (int) ($device['battery_percent'] ?? 0) ?>%</div>
            <div class="muted"><?= number_format(((int) ($device['battery_mv'] ?? 0)) / 1000, 2) ?> V</div>
          </div>
          <div class="metric">
            <div class="label">Pumpe</div>
            <div class="value"><?= !empty($device['pump_on']) ? 'AN' : 'AUS' ?></div>
            <div class="muted"><?= htmlspecialchars($device['pump_mode'] ?? 'auto') ?></div>
          </div>
          <div class="metric">
            <div class="label">Sensor raw</div>
            <div class="value"><?= (int) ($device['soil_raw'] ?? 0) ?></div>
            <div class="muted">ADC12 <?= (int) ($device['soil_raw12'] ?? 0) ?></div>
          </div>
        </div>

        <canvas class="history-canvas" id="<?= htmlspecialchars($canvasId) ?>" width="520" height="160" data-history="<?= htmlspecialchars(json_encode($history, JSON_UNESCAPED_SLASHES)) ?>"></canvas>

        <form method="post">
          <input name="api_key" type="password" value="<?= htmlspecialchars($dashboardKey) ?>" placeholder="API key">
          <input type="hidden" name="device_id" value="<?= htmlspecialchars($deviceId) ?>">
          <div class="button-row">
            <button name="pump" value="on">An</button>
            <button class="danger" name="pump" value="off">Aus</button>
            <button class="secondary" name="pump" value="auto">Auto</button>
          </div>
        </form>
        <p class="muted">Letzter Befehl: <?= htmlspecialchars($command['pump'] ?? 'keiner') ?> <?= empty($command['acked_at']) ? '' : '· bestaetigt' ?></p>
      </article>
    <?php endforeach; ?>
  </section>
</main>

<script>
for (const canvas of document.querySelectorAll('.history-canvas')) {
  const historyData = JSON.parse(canvas.dataset.history || '[]');
  const ctx = canvas.getContext('2d');
  const w = canvas.width;
  const h = canvas.height;
  const pad = 28;
  ctx.clearRect(0, 0, w, h);
  ctx.strokeStyle = '#d7e1dc';
  ctx.lineWidth = 1;
  ctx.font = '12px system-ui';
  ctx.fillStyle = '#63736c';
  for (const p of [0, 50, 100]) {
    const y = h - pad - (p / 100) * (h - pad * 2);
    ctx.beginPath();
    ctx.moveTo(pad, y);
    ctx.lineTo(w - 8, y);
    ctx.stroke();
    ctx.fillText(`${p}%`, 2, y + 4);
  }
  if (historyData.length > 1) {
    ctx.strokeStyle = '#1d6f54';
    ctx.lineWidth = 3;
    ctx.beginPath();
    historyData.forEach((item, index) => {
      const x = pad + index * (w - pad - 8) / (historyData.length - 1);
      const y = h - pad - Math.max(0, Math.min(100, item.moisture_percent || 0)) / 100 * (h - pad * 2);
      if (index === 0) ctx.moveTo(x, y);
      else ctx.lineTo(x, y);
    });
    ctx.stroke();
  }
}
</script>
</body>
</html>
