<?php
declare(strict_types=1);

const DEFAULT_API_KEY = 'change-me';
const PHP_CHANNEL_COUNT = 4; // Must match CHANNEL_COUNT in src/main.cpp
const HISTORY_LIMIT = 240;

$dataDir = __DIR__ . '/data';

function loadEnvFile(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $env = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $env[$key] = trim($value, "\"'");
    }
    return $env;
}

$env = loadEnvFile(__DIR__ . '/.env');
$apiKey = getenv('HYDROSENSE_API_KEY') ?: ($env['HYDROSENSE_API_KEY'] ?? DEFAULT_API_KEY);

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

function normalizeChannels(array $payload): array
{
    if (isset($payload['channels']) && is_array($payload['channels'])) {
        $channels = [];
        foreach ($payload['channels'] as $index => $channel) {
            if (!is_array($channel)) {
                continue;
            }
            $channels[] = [
                'index' => (int) ($channel['index'] ?? $index),
                'name' => trim((string) ($channel['name'] ?? '')) ?: 'Pump ' . (((int) ($channel['index'] ?? $index)) + 1),
                'moisture_percent' => numberValue($channel, 'moisture_percent'),
                'soil_raw' => numberValue($channel, 'soil_raw'),
                'soil_raw12' => numberValue($channel, 'soil_raw12'),
                'pump_on' => boolValue($channel, 'pump_on'),
                'pump_mode' => textValue($channel, 'pump_mode', 'auto'),
                'needs_water' => boolValue($channel, 'needs_water'),
            ];
        }
        if ($channels) {
            usort($channels, static fn (array $a, array $b): int => $a['index'] <=> $b['index']);
            return $channels;
        }
    }

    return [[
        'index' => 0,
        'name' => 'Pump 1',
        'moisture_percent' => numberValue($payload, 'moisture_percent'),
        'soil_raw' => numberValue($payload, 'soil_raw'),
        'soil_raw12' => numberValue($payload, 'soil_raw12'),
        'pump_on' => boolValue($payload, 'pump_on'),
        'pump_mode' => textValue($payload, 'pump_mode', 'auto'),
        'needs_water' => boolValue($payload, 'needs_water'),
    ]];
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

function gpioConfigPath(string $dataDir, string $deviceId): string
{
    return $dataDir . '/gpio-' . deviceFileId($deviceId) . '.json';
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
        'channels' => normalizeChannels($payload),
    ];
    $firstChannel = $entry['channels'][0] ?? [];
    $entry['moisture_percent'] = (int) ($firstChannel['moisture_percent'] ?? 0);
    $entry['soil_raw'] = (int) ($firstChannel['soil_raw'] ?? 0);
    $entry['soil_raw12'] = (int) ($firstChannel['soil_raw12'] ?? 0);
    $entry['pump_on'] = (bool) ($firstChannel['pump_on'] ?? false);
    $entry['pump_mode'] = (string) ($firstChannel['pump_mode'] ?? 'auto');
    $entry['needs_water'] = (bool) ($firstChannel['needs_water'] ?? false);
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
        'channel' => (int) ($command['channel'] ?? 0),
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
        if (isset($payload['channels']) && is_array($payload['channels'])) {
            $command['reported_channels'] = normalizeChannels($payload);
        }
        writeJsonFile($path, $command);
    }
    jsonResponse(['ok' => true]);
}

if ($api === 'gpio_config') {
    requireApiKey($apiKey);
    $deviceId = textValue($_GET, 'device_id', 'hydrosense-esp32');
    $path = gpioConfigPath($dataDir, $deviceId);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = requestJson();
        $soilSensorPins = textValue($payload, 'soil_sensor_pins', '');
        $relayPins = textValue($payload, 'relay_pins', '');

        writeJsonFile($path, [
            'soil_sensor_pins' => $soilSensorPins,
            'relay_pins' => $relayPins,
            'updated_at' => gmdate('c'),
        ]);
        jsonResponse(['ok' => true, 'message' => 'GPIO configuration saved.']);
    } else { // GET request
        $config = readJsonFile($path, []);
        jsonResponse([
            'ok' => true,
            // Provide defaults if not found in file (matching src/main.cpp defaults)
            'soil_sensor_pins' => $config['soil_sensor_pins'] ?? '34,35,36,39',
            'relay_pins' => $config['relay_pins'] ?? '26,25,32,33',
            'channel_names' => $config['channel_names'] ?? array_fill(0, PHP_CHANNEL_COUNT, ''),
        ]);
    }
}


$dashboardError = '';
$dashboardKey = (string) ($_POST['api_key'] ?? $_GET['api_key'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pump'])) {
    if (!hash_equals($apiKey, $dashboardKey)) {
        $dashboardError = 'API key ist falsch.';
    } else {
        $deviceId = textValue($_POST, 'device_id', 'hydrosense-esp32');
        $channel = max(0, (int) ($_POST['channel'] ?? 0));
        $pump = in_array($_POST['pump'], ['on', 'off', 'auto'], true) ? $_POST['pump'] : 'auto';
        $previous = readJsonFile(commandPath($dataDir, $deviceId), ['command_id' => 0]);
        writeJsonFile(commandPath($dataDir, $deviceId), [
            'command_id' => ((int) ($previous['command_id'] ?? 0)) + 1,
            'device_id' => $deviceId,
            'channel' => $channel,
            'pump' => $pump,
            'created_at' => gmdate('c'),
            'acked_at' => null,
        ]);
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?api_key=' . rawurlencode($dashboardKey));
        exit;
    }
}

// Handle GPIO config POST from dashboard for a specific channel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_channel_gpio_config') {
    if (!hash_equals($apiKey, $dashboardKey)) {
        $dashboardError = 'API key ist falsch.';
    } else {
        $deviceId = textValue($_POST, 'device_id', 'hydrosense-esp32');
        $channelIndex = numberValue($_POST, 'channel_index');
        $newHumiditySensorGpio = numberValue($_POST, 'humidity_sensor_gpio');
        $newPumpGpio = numberValue($_POST, 'pump_gpio');

        // Load current GPIO config
        $currentGpioConfig = readJsonFile(gpioConfigPath($dataDir, $deviceId), [
            'soil_sensor_pins' => implode(',', array_fill(0, PHP_CHANNEL_COUNT, 0)), // Default to 0 for all channels
            'relay_pins' => implode(',', array_fill(0, PHP_CHANNEL_COUNT, 0)),     // Default to 0 for all channels
        ]);

        $soilPinsArray = explode(',', $currentGpioConfig['soil_sensor_pins']);
        $relayPinsArray = explode(',', $currentGpioConfig['relay_pins']);

        // Ensure arrays are exactly PHP_CHANNEL_COUNT long
        $soilPinsArray = array_pad($soilPinsArray, PHP_CHANNEL_COUNT, '0');
        $relayPinsArray = array_pad($relayPinsArray, PHP_CHANNEL_COUNT, '0');

        // Update the specific channel's pins
        if ($channelIndex >= 0 && $channelIndex < PHP_CHANNEL_COUNT) {
            $soilPinsArray[$channelIndex] = (string)$newHumiditySensorGpio; // Store as string to match explode/implode
            $relayPinsArray[$channelIndex] = (string)$newPumpGpio;       // Store as string
        } else {
            $dashboardError = 'Ungültiger Kanalindex für GPIO-Konfiguration.';
            // Don't save if invalid
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?api_key=' . rawurlencode($dashboardKey) . '#device-' . urlencode($deviceId));
            exit;
        }

        // Reconstruct comma-separated strings
        $updatedSoilPinsStr = implode(',', $soilPinsArray);
        $updatedRelayPinsStr = implode(',', $relayPinsArray);
        
        writeJsonFile(gpioConfigPath($dataDir, $deviceId), [
            'soil_sensor_pins' => $updatedSoilPinsStr,
            'relay_pins' => $updatedRelayPinsStr,
            'updated_at' => gmdate('c'),
        ]);
        // Redirect to refresh the page and show updated values, anchor to the device card
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?api_key=' . rawurlencode($dashboardKey) . '#device-' . urlencode($deviceId));
        exit;
    }
}

// Handle channel name config POST from dashboard
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_channel_name_config') {
    if (!hash_equals($apiKey, $dashboardKey)) {
        $dashboardError = 'API key ist falsch.';
    } else {
        $deviceId = textValue($_POST, 'device_id', 'hydrosense-esp32');
        $channelIndex = numberValue($_POST, 'channel_index');
        $newChannelName = trim((string) ($_POST['channel_name'] ?? ''));

        // Load current GPIO config (which now includes channel_names)
        $currentGpioConfig = readJsonFile(gpioConfigPath($dataDir, $deviceId), [
            'soil_sensor_pins' => implode(',', array_fill(0, PHP_CHANNEL_COUNT, '0')),
            'relay_pins' => implode(',', array_fill(0, PHP_CHANNEL_COUNT, '0')),
            'channel_names' => array_fill(0, PHP_CHANNEL_COUNT, ''),
        ]);

        $channelNamesArray = $currentGpioConfig['channel_names'];
        // Ensure array is PHP_CHANNEL_COUNT long
        $channelNamesArray = array_pad($channelNamesArray, PHP_CHANNEL_COUNT, '');

        // Update the specific channel's name
        if ($channelIndex >= 0 && $channelIndex < PHP_CHANNEL_COUNT) {
            $channelNamesArray[$channelIndex] = $newChannelName;
        } else {
            $dashboardError = 'Ungültiger Kanalindex für Kanalnamen-Konfiguration.';
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?api_key=' . rawurlencode($dashboardKey) . '#device-' . urlencode($deviceId));
            exit;
        }

        // Update the gpioConfig with the new channel names
        $currentGpioConfig['channel_names'] = $channelNamesArray;
        $currentGpioConfig['updated_at'] = gmdate('c'); // Update timestamp

        writeJsonFile(gpioConfigPath($dataDir, $deviceId), $currentGpioConfig);
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?api_key=' . rawurlencode($dashboardKey) . '#device-' . urlencode($deviceId));
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
  <title>HydroSense</title>
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <style>
    :root { color-scheme: light; font-family: system-ui, -apple-system, Segoe UI, sans-serif; background: #f4f7f6; color: #17211d; }
    body { margin: 0; }
    main { max-width: 1280px; margin: 0 auto; padding: 28px 18px 40px; }
    header { display: flex; align-items: end; justify-content: space-between; gap: 16px; margin-bottom: 22px; }
    h1 { margin: 0; font-size: clamp(28px, 5vw, 46px); line-height: 1; letter-spacing: 0; }
    .muted { color: #63736c; }
    .summary { display: flex; flex-wrap: wrap; gap: 8px; justify-content: flex-end; }
    .refresh-control { display: flex; align-items: center; gap: 8px; justify-content: flex-end; margin-top: 8px; font-size: 13px; color: #63736c; width: 100%; }
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
    .channel-grid { display: grid; gap: 12px; }
    .channel { border: 1px solid #dbe7e1; border-radius: 8px; padding: 12px; }
    .channel-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 10px; }
    .channel-head h3 { margin: 0; font-size: 16px; line-height: 1.2; overflow-wrap: anywhere; }
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
      <div class="refresh-control">
        <input type="checkbox" id="autoRefreshToggle" checked>
        <label for="autoRefreshToggle">Auto-Update</label>
        <span id="refreshCountdown" style="min-width: 2ch; text-align: right;">15</span>s
      </div>
    </div>
  </header>

  <?php if ($dashboardError): ?><p class="error"><?= htmlspecialchars($dashboardError) ?></p><?php endif; ?>

  <section class="pump-grid">
    <?php if (empty($devices)): ?>
      <div class="card empty">Noch keine Pumpe hat sich per API gemeldet.</div>
    <?php endif; ?>
    <?php foreach ($devices as $device): ?>
      <?php
        $deviceId = textValue($device, 'device_id', 'hydrosense-esp32');
        $state = deviceStatus($device);
        $channels = normalizeChannels($device);
        $history = loadHistory(historyPath($dataDir, $deviceId));
        $command = readJsonFile(commandPath($dataDir, $deviceId), []);
        $gpioConfig = readJsonFile(gpioConfigPath($dataDir, $deviceId), [
            'soil_sensor_pins' => '34,35,36,39', // Default from src/main.cpp
            'relay_pins' => '26,25,32,33',     // Default from src/main.cpp
        ]);
        $soilPinsArray = explode(',', $gpioConfig['soil_sensor_pins']);
        $relayPinsArray = explode(',', $gpioConfig['relay_pins']);
        $soilPinsArray = array_pad($soilPinsArray, PHP_CHANNEL_COUNT, '0');
        $relayPinsArray = array_pad($relayPinsArray, PHP_CHANNEL_COUNT, '0');
        $canvasId = 'history-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $deviceId);
      ?>
      <article class="card" id="device-<?= htmlspecialchars($deviceId) ?>">
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

        <div class="metrics" style="margin-bottom: 12px;">
            <div class="metric">
              <div class="label">Batterie</div>
              <div class="value"><?= (int) ($device['battery_percent'] ?? 0) ?>%</div>
              <div class="muted"><?= number_format(((int) ($device['battery_mv'] ?? 0)) / 1000, 2) ?> V</div>
            </div>
            <div class="metric">
              <div class="label">Kanaele</div>
              <div class="value"><?= count($channels) ?></div>
              <div class="muted">Relays / Sensoren</div>
            </div>
        </div>

        <canvas class="history-canvas" id="<?= htmlspecialchars($canvasId) ?>" width="520" height="160" data-history="<?= htmlspecialchars(json_encode($history, JSON_UNESCAPED_SLASHES)) ?>"></canvas>

        <div class="channel-grid">
          <?php foreach ($channels as $channel): ?>
            <?php $channelIndex = (int) ($channel['index'] ?? 0); ?>
            <section class="channel">
              <div class="channel-head">
                <h3><?= htmlspecialchars($channel['name'] ?? ('Pump ' . ($channelIndex + 1))) ?></h3>
                <strong><?= !empty($channel['pump_on']) ? 'AN' : 'AUS' ?></strong>
              </div>
              <div class="metrics">
                <div class="metric">
                  <div class="label">Feuchtigkeit</div>
                  <div class="value"><?= (int) ($channel['moisture_percent'] ?? 0) ?>%</div>
                </div>
                <div class="metric">
                  <div class="label">Sensor raw</div>
                  <div class="value"><?= (int) ($channel['soil_raw'] ?? 0) ?></div>
                  <div class="muted">ADC12 <?= (int) ($channel['soil_raw12'] ?? 0) ?></div>
                </div>
              </div>
              <form method="post">
                <input name="api_key" type="password" value="<?= htmlspecialchars($dashboardKey) ?>" placeholder="API key">
                <input type="hidden" name="device_id" value="<?= htmlspecialchars($deviceId) ?>">
                <input type="hidden" name="channel" value="<?= $channelIndex ?>">
                <div class="button-row">
                  <button name="pump" value="on">An</button>
                  <button class="danger" name="pump" value="off">Aus</button>
                  <button class="secondary" name="pump" value="auto">Auto</button>
                </div>
              </form>
              <p class="muted">Modus: <?= htmlspecialchars($channel['pump_mode'] ?? 'auto') ?></p>

              <hr style="margin: 15px 0; border: 0; border-top: 1px solid #eee;">

              <section class="gpio-config">
                <h4>GPIO Einstellungen für Kanal <?= $channelIndex + 1 ?></h4>
                <form method="post">
                  <input name="api_key" type="password" value="<?= htmlspecialchars($dashboardKey) ?>" placeholder="API key">
                  <input type="hidden" name="device_id" value="<?= htmlspecialchars($deviceId) ?>">
                  <input type="hidden" name="action" value="save_channel_gpio_config">
                  <input type="hidden" name="channel_index" value="<?= $channelIndex ?>">

                  <label for="humidity_sensor_gpio_<?= htmlspecialchars($deviceId) ?>_<?= $channelIndex ?>">Feuchtigkeitssensor GPIO</label>
                  <input id="humidity_sensor_gpio_<?= htmlspecialchars($deviceId) ?>_<?= $channelIndex ?>" name="humidity_sensor_gpio" type="number" value="<?= htmlspecialchars($soilPinsArray[$channelIndex] ?? '') ?>" <?= $state['key'] === 'offline' ? 'disabled' : '' ?>>

                  <label for="pump_gpio_<?= htmlspecialchars($deviceId) ?>_<?= $channelIndex ?>">Pumpen GPIO</label>
                  <input id="pump_gpio_<?= htmlspecialchars($deviceId) ?>_<?= $channelIndex ?>" name="pump_gpio" type="number" value="<?= htmlspecialchars($relayPinsArray[$channelIndex] ?? '') ?>" <?= $state['key'] === 'offline' ? 'disabled' : '' ?>>

                  <button type="submit" <?= $state['key'] === 'offline' ? 'disabled' : '' ?>>GPIO für Kanal speichern</button>
                </form>
                <?php if ($state['key'] === 'offline'): ?>
                  <p class="muted" style="margin-top: 10px;">Gerät ist offline. GPIO Einstellungen können nicht geändert werden.</p>
                <?php endif; ?>
              </section>
            </section>
          <?php endforeach; ?>
        </div>
        <p class="muted">Letzter Befehl: Kanal <?= (int) (($command['channel'] ?? 0) + 1) ?> · <?= htmlspecialchars($command['pump'] ?? 'keiner') ?> <?= empty($command['acked_at']) ? '' : '· bestaetigt' ?></p>
      </article>
    <?php endforeach; ?>
  </section>
</main>

<script>
$(function() {
  function initCharts() {
    $('.history-canvas').each(function() {
      const historyData = $(this).data('history') || [];
      const ctx = this.getContext('2d');
      const w = this.width;
      const h = this.height;
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
    });
  }

  initCharts();

  let timeLeft = 15;
  const $countdown = $('#refreshCountdown');
  const $toggle = $('#autoRefreshToggle');

  setInterval(() => {
    if (!$toggle.is(':checked')) return;
    timeLeft--;
    if (timeLeft <= 0) {
      timeLeft = 15;
      refreshData();
    }
    $countdown.text(timeLeft);
  }, 1000);

  function refreshData() {
    $.get(window.location.href, function(data) {
      const $newDoc = $($.parseHTML(data));
      
      // Update summary metrics
      $('.summary .pill').each(function(i) {
        $(this).html($newDoc.find('.summary .pill').eq(i).html());
      });

      // Update pump cards if user isn't typing
      if (!$(document.activeElement).is('input, textarea')) {
        $('.pump-grid').html($newDoc.find('.pump-grid').html());
        initCharts();
      }
    }).fail(function(err) {
      console.error('Auto-refresh failed:', err);
    });
  }
});
</script>
</body>
</html>
