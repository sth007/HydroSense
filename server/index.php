<?php
declare(strict_types=1);

const DEFAULT_API_KEY = 'change-me';
const PHP_CHANNEL_COUNT = 4; // Must match CHANNEL_COUNT in src/main.cpp
const DASHBOARD_REFRESH_SECONDS = 15;
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
    return preg_replace('/[^a-zA-Z0-9_.,:-]/', '-', (string) $value) ?: $fallback;
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
            // Add dry_raw and wet_raw from telemetry
            // These are sent by the ESP32 in buildTelemetryJson
            $channels[] = [
                'index' => (int) ($channel['index'] ?? $index),
                'name' => trim((string) ($channel['name'] ?? '')) ?: 'Pump ' . (((int) ($channel['index'] ?? $index)) + 1),
                'moisture_percent' => numberValue($channel, 'moisture_percent'),
                'soil_raw' => numberValue($channel, 'soil_raw'),
                'soil_raw12' => numberValue($channel, 'soil_raw12'),
                'pump_on' => boolValue($channel, 'pump_on'),
                'dry_raw' => numberValue($channel, 'dry_raw'),
                'wet_raw' => numberValue($channel, 'wet_raw'),
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
        'dry_raw' => 0, // Default if not in telemetry
        'wet_raw' => 0, // Default if not in telemetry
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

function configPath(string $dataDir): string
{
    return $dataDir . '/config.json';
}

$globalConfig = readJsonFile(configPath($dataDir), []);
$refreshInterval = (int) ($globalConfig['refresh_seconds'] ?? DASHBOARD_REFRESH_SECONDS);

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
        $config = readJsonFile($path, []);
        $config['soil_sensor_pins'] = textValue($payload, 'soil_sensor_pins', $config['soil_sensor_pins'] ?? '34,35,36,39');
        $config['relay_pins'] = textValue($payload, 'relay_pins', $config['relay_pins'] ?? '26,25,32,33');
        $config['dry_raw'] = textValue($payload, 'dry_raw', $config['dry_raw'] ?? implode(',', array_fill(0, PHP_CHANNEL_COUNT, 0)));
        $config['wet_raw'] = textValue($payload, 'wet_raw', $config['wet_raw'] ?? implode(',', array_fill(0, PHP_CHANNEL_COUNT, 0)));
        $config['updated_at'] = gmdate('c');
        writeJsonFile($path, $config);
        jsonResponse(['ok' => true, 'message' => 'GPIO configuration saved.']);
    } else { // GET request
        $config = readJsonFile($path, []);
        jsonResponse([
            'ok' => true,
            // Provide defaults if not found in file (matching src/main.cpp defaults)
            'soil_sensor_pins' => $config['soil_sensor_pins'] ?? '34,35,36,39',
            'relay_pins' => $config['relay_pins'] ?? '26,25,32,33',
            'dry_raw' => $config['dry_raw'] ?? implode(',', array_fill(0, PHP_CHANNEL_COUNT, 0)),
            'wet_raw' => $config['wet_raw'] ?? implode(',', array_fill(0, PHP_CHANNEL_COUNT, 0)),
            'channel_names' => $config['channel_names'] ?? array_fill(0, PHP_CHANNEL_COUNT, ''),
        ]);
    }
}


$dashboardError = '';
$dashboardMessage = '';
$dashboardKey = (string) ($_POST['api_key'] ?? $_GET['api_key'] ?? '');

if (($_GET['msg'] ?? '') === 'saved') {
    $dashboardMessage = 'Die Einstellungen wurden erfolgreich gespeichert.';
} elseif (($_GET['msg'] ?? '') === 'cmd') {
    $dashboardMessage = 'Der Befehl wurde an das Gerät übermittelt.';
}

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
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?api_key=' . rawurlencode($dashboardKey) . '&msg=cmd');
        exit;
    }
}

// Handle GPIO config POST from dashboard for a specific channel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_channel_gpio_config') {
    if (!hash_equals($apiKey, $dashboardKey)) {
        $msg = 'API key ist falsch.';
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            jsonResponse(['ok' => false, 'error' => $msg], 401);
        }
        $dashboardError = $msg;
    } else {
        $deviceId = textValue($_POST, 'device_id', 'hydrosense-esp32');
        $channelIndex = numberValue($_POST, 'channel_index');
        $newHumiditySensorGpio = numberValue($_POST, 'humidity_sensor_gpio');
        $newPumpGpio = numberValue($_POST, 'pump_gpio');

        // Load current GPIO config
        $currentGpioConfig = readJsonFile(gpioConfigPath($dataDir, $deviceId), []);

        $soilPinsArray = explode(',', $currentGpioConfig['soil_sensor_pins'] ?? '34,35,36,39');
        $relayPinsArray = explode(',', $currentGpioConfig['relay_pins'] ?? '26,25,32,33');

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
        
        $currentGpioConfig['soil_sensor_pins'] = $updatedSoilPinsStr;
        $currentGpioConfig['relay_pins'] = $updatedRelayPinsStr;
        $currentGpioConfig['updated_at'] = gmdate('c');
        writeJsonFile(gpioConfigPath($dataDir, $deviceId), $currentGpioConfig);

        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            jsonResponse(['ok' => true, 'message' => 'GPIO Konfiguration gespeichert.']);
        }
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?api_key=' . rawurlencode($dashboardKey) . '&msg=saved#device-' . urlencode($deviceId));
        exit;
    }
}

// Handle channel name config POST from dashboard
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_channel_name_config') {
    if (!hash_equals($apiKey, $dashboardKey)) {
        $msg = 'API key ist falsch.';
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            jsonResponse(['ok' => false, 'error' => $msg], 401);
        }
        $dashboardError = $msg;
    } else {
        $deviceId = textValue($_POST, 'device_id', 'hydrosense-esp32');
        $channelIndex = numberValue($_POST, 'channel_index');
        $newChannelName = trim((string) ($_POST['channel_name'] ?? ''));

        // Load current GPIO config (which now includes channel_names)
        $currentGpioConfig = readJsonFile(gpioConfigPath($dataDir, $deviceId), []);

        $channelNamesArray = $currentGpioConfig['channel_names'] ?? array_fill(0, PHP_CHANNEL_COUNT, '');
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

        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            jsonResponse(['ok' => true, 'message' => 'Kanalname gespeichert.']);
        }
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?api_key=' . rawurlencode($dashboardKey) . '&msg=saved#device-' . urlencode($deviceId));
        exit;
    }
}

// Handle channel calibration config POST from dashboard
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_channel_calibration_config') {
    if (!hash_equals($apiKey, $dashboardKey)) {
        $msg = 'API key ist falsch.';
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            jsonResponse(['ok' => false, 'error' => $msg], 401);
        }
        $dashboardError = $msg;
    } else {
        $deviceId = textValue($_POST, 'device_id', 'hydrosense-esp32');
        $channelIndex = numberValue($_POST, 'channel_index');
        $newDryRaw = numberValue($_POST, 'dry_raw_value');
        $newWetRaw = numberValue($_POST, 'wet_raw_value');

        // Load current GPIO config (which now includes calibration values)
        $currentGpioConfig = readJsonFile(gpioConfigPath($dataDir, $deviceId), []);
        
        // Maintain existing structure, only update calibration
        $dryRawArray = explode(',', $currentGpioConfig['dry_raw'] ?? implode(',', array_fill(0, PHP_CHANNEL_COUNT, '0')));
        $wetRawArray = explode(',', $currentGpioConfig['wet_raw'] ?? implode(',', array_fill(0, PHP_CHANNEL_COUNT, '0')));

        // Ensure arrays are PHP_CHANNEL_COUNT long
        $dryRawArray = array_pad($dryRawArray, PHP_CHANNEL_COUNT, '0');
        $wetRawArray = array_pad($wetRawArray, PHP_CHANNEL_COUNT, '0');

        // Update the specific channel's calibration values
        if ($channelIndex >= 0 && $channelIndex < PHP_CHANNEL_COUNT) {
            $dryRawArray[$channelIndex] = (string)$newDryRaw;
            $wetRawArray[$channelIndex] = (string)$newWetRaw;
        } else {
            $dashboardError = 'Ungültiger Kanalindex für Kalibrierungs-Konfiguration.';
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?api_key=' . rawurlencode($dashboardKey) . '#device-' . urlencode($deviceId));
            exit;
        }

        // Reconstruct comma-separated strings
        $updatedDryRawStr = implode(',', $dryRawArray);
        $updatedWetRawStr = implode(',', $wetRawArray);

        // Update the gpioConfig with the new calibration values
        $currentGpioConfig['dry_raw'] = $updatedDryRawStr;
        $currentGpioConfig['wet_raw'] = $updatedWetRawStr;
        $currentGpioConfig['updated_at'] = gmdate('c'); // Update timestamp

        writeJsonFile(gpioConfigPath($dataDir, $deviceId), $currentGpioConfig);

        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            jsonResponse(['ok' => true, 'message' => 'Kalibrierung gespeichert.']);
        }
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?api_key=' . rawurlencode($dashboardKey) . '&msg=saved#device-' . urlencode($deviceId));
        exit;
    }
}

// Handle dashboard config POST (Refresh rate and API Key storage sync)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_dashboard_config') {
    if (!hash_equals($apiKey, $dashboardKey)) {
        $msg = 'API key ist falsch.';
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            jsonResponse(['ok' => false, 'error' => $msg], 401);
        }
        $dashboardError = $msg;
    } else {
        $refresh = max(5, numberValue($_POST, 'refresh_seconds'));
        $config = readJsonFile(configPath($dataDir), []);
        $config['refresh_seconds'] = $refresh;
        writeJsonFile(configPath($dataDir), $config);

        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            jsonResponse(['ok' => true, 'message' => 'Dashboard-Konfiguration gespeichert.', 'refresh_seconds' => $refresh]);
        }
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?api_key=' . rawurlencode($dashboardKey) . '&msg=saved');
        exit;
    }
}

// Handle device reset to defaults
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_device_settings') {
    if (!hash_equals($apiKey, $dashboardKey)) {
        $dashboardError = 'API key ist falsch.';
    } else {
        $deviceId = textValue($_POST, 'device_id', 'hydrosense-esp32');
        $gpioFile = gpioConfigPath($dataDir, $deviceId);
        $cmdFile = commandPath($dataDir, $deviceId);
        
        if (is_file($gpioFile)) @unlink($gpioFile);
        if (is_file($cmdFile)) @unlink($cmdFile);
        
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?api_key=' . rawurlencode($dashboardKey) . '&msg=saved');
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
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg:        #f1f5f9;
      --surface:   #ffffff;
      --border:    #e2e8f0;
      --border-lt: #f1f5f9;
      --text:      #0f172a;
      --text2:     #475569;
      --text3:     #94a3b8;
      --primary:       #059669;
      --primary-dk:    #047857;
      --primary-bg:    #ecfdf5;
      --primary-bdr:   #a7f3d0;
      --danger:        #dc2626;
      --danger-bg:     #fef2f2;
      --warning:       #d97706;
      --c-online:  #10b981;
      --c-recent:  #f59e0b;
      --c-offline: #ef4444;
      --sh:   0 1px 3px rgba(0,0,0,.10),0 1px 2px rgba(0,0,0,.06);
      --sh-sm:0 1px 2px rgba(0,0,0,.05);
      --r:  13px;
      --ri:  8px;
      --rb:  7px;
      font-family: system-ui,-apple-system,"Segoe UI",sans-serif;
      color: var(--text);
      background: var(--bg);
    }
    html, body { min-height: 100dvh; }

    /* ── Topbar ───────────────────────────────────────── */
    .topbar {
      position: sticky; top: 0; z-index: 100;
      background: var(--surface); border-bottom: 1px solid var(--border);
      box-shadow: var(--sh-sm);
      display: flex; align-items: center; gap: 12px;
      padding: 0 20px; height: 56px;
    }
    .brand { display: flex; align-items: center; gap: 9px; flex-shrink: 0; text-decoration: none; color: inherit; }
    .brand-icon { width: 32px; height: 32px; border-radius: 8px; background: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 17px; line-height: 1; }
    .brand-name { font-size: 15px; font-weight: 800; letter-spacing: -.3px; }
    .brand-sub  { font-size: 10px; color: var(--text3); display: block; line-height: 1; }
    .vr   { width: 1px; height: 24px; background: var(--border); flex-shrink: 0; }
    .sp   { flex: 1; }
    .tb-pills { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
    .tb-pill {
      display: inline-flex; align-items: center; gap: 5px;
      background: var(--bg); border: 1px solid var(--border);
      border-radius: 999px; padding: 3px 10px;
      font-size: 12px; font-weight: 600; color: var(--text2);
    }
    .tb-pill .dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
    .tb-right { display: flex; align-items: center; gap: 10px; }
    .rc-row { display: flex; align-items: center; gap: 5px; font-size: 12px; color: var(--text3); white-space: nowrap; }
    .rc-row input[type=checkbox] { accent-color: var(--primary); width: 14px; height: 14px; cursor: pointer; margin: 0; }
    #refreshCountdown { font-variant-numeric: tabular-nums; min-width: 18px; display: inline-block; text-align: right; }
    .api-form { display: flex; align-items: center; gap: 6px; }
    .api-form label { font-size: 11px; font-weight: 600; color: var(--text3); white-space: nowrap; }
    .api-form input[type=password] {
      border: 1px solid var(--border); border-radius: var(--rb);
      padding: 5px 10px; font: inherit; font-size: 12px;
      background: var(--bg); color: var(--text); width: 150px;
      transition: border-color .15s, box-shadow .15s;
    }
    .api-form input[type=password]:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(5,150,105,.12); }
    .api-form input[type=number] {
      border: 1px solid var(--border); border-radius: var(--rb);
      padding: 5px 6px; font: inherit; font-size: 12px;
      background: var(--bg); color: var(--text); width: 52px;
      transition: border-color .15s, box-shadow .15s;
    }
    .api-form input[type=number]:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(5,150,105,.12); }
    .btn-tb {
      border: 1px solid var(--border); border-radius: var(--rb);
      background: var(--bg); color: var(--text2);
      padding: 5px 12px; font: inherit; font-size: 12px; font-weight: 600;
      cursor: pointer; white-space: nowrap; transition: background .12s;
    }
    .btn-tb:hover { background: var(--border); }

    /* ── Content ──────────────────────────────────────── */
    .wrap { max-width: 1280px; margin: 0 auto; padding: 20px 20px 48px; }

    /* ── Alerts ───────────────────────────────────────── */
    .alert { display: flex; align-items: center; gap: 8px; border-radius: var(--ri); padding: 11px 14px; margin-bottom: 16px; font-size: 13px; font-weight: 600; }
    .alert-ok  { background: var(--primary-bg);  border: 1px solid var(--primary-bdr); color: var(--primary-dk); }
    .alert-err { background: var(--danger-bg); border: 1px solid #fecaca; color: var(--danger); }

    /* ── Device grid ──────────────────────────────────── */
    .pump-grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fill, minmax(340px,1fr)); align-items: start; }

    /* ── Device card ──────────────────────────────────── */
    .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r); box-shadow: var(--sh); overflow: hidden; }
    .card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 16px 16px 12px; border-bottom: 1px solid var(--border-lt); }
    .dev-name { font-size: 15px; font-weight: 800; letter-spacing: -.2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .dev-ts   { font-size: 11px; color: var(--text3); margin-top: 2px; }
    .status-badge { display: inline-flex; align-items: center; gap: 5px; border-radius: 999px; padding: 4px 11px; font-size: 11px; font-weight: 700; white-space: nowrap; flex-shrink: 0; }
    .status-badge .dot { width: 7px; height: 7px; border-radius: 50%; }
    .sb-online  { background: #d1fae5; color: #065f46; }
    .sb-online .dot  { background: var(--c-online); }
    .sb-recent  { background: #fef3c7; color: #92400e; }
    .sb-recent .dot  { background: var(--c-recent); }
    .sb-offline { background: #fee2e2; color: #991b1b; }
    .sb-offline .dot { background: var(--c-offline); }

    /* ── Stats strip ──────────────────────────────────── */
    .stats-strip { display: flex; padding: 12px 16px; border-bottom: 1px solid var(--border-lt); }
    .dstat { flex: 1; }
    .dstat + .dstat { border-left: 1px solid var(--border); padding-left: 14px; margin-left: 14px; }
    .dstat-lbl { font-size: 10px; text-transform: uppercase; letter-spacing: .07em; color: var(--text3); font-weight: 600; }
    .dstat-val { font-size: 20px; font-weight: 800; line-height: 1.2; margin-top: 2px; letter-spacing: -.5px; }
    .dstat-sub { font-size: 11px; color: var(--text3); margin-top: 1px; }

    /* ── History chart ────────────────────────────────── */
    .chart-wrap { padding: 10px 16px 2px; }
    .history-canvas { width: 100%; height: 70px; display: block; }

    /* ── Channel list ─────────────────────────────────── */
    .ch-list { padding: 12px 16px 16px; display: flex; flex-direction: column; gap: 8px; }
    .channel { border: 1px solid var(--border); border-radius: var(--ri); background: var(--bg); overflow: hidden; }
    .channel-summary { display: flex; align-items: center; gap: 10px; padding: 11px 12px; cursor: pointer; list-style: none; user-select: none; }
    .channel-summary::-webkit-details-marker { display: none; }
    .ch-toggle { width: 18px; height: 18px; flex-shrink: 0; border: 1.5px solid var(--primary); border-radius: 5px; display: flex; align-items: center; justify-content: center; color: var(--primary); font-size: 13px; font-family: monospace; font-weight: 800; transition: background .12s, color .12s; }
    .channel[open] .ch-toggle { background: var(--primary); color: #fff; }
    .channel[open] .channel-summary { border-bottom: 1px solid var(--border); }
    .ch-name { font-size: 13px; font-weight: 700; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
    .ch-bar-wrap { width: 64px; flex-shrink: 0; }
    .ch-bar-track { height: 5px; background: var(--border); border-radius: 3px; overflow: hidden; }
    .ch-bar-fill  { height: 100%; border-radius: 3px; transition: width .4s; }
    .ch-pct { font-size: 13px; font-weight: 800; min-width: 34px; text-align: right; flex-shrink: 0; }
    .ch-body { padding: 12px; display: flex; flex-direction: column; gap: 10px; }

    /* ── Channel metrics ──────────────────────────────── */
    .ch-metrics { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .ch-metric { background: var(--surface); border: 1px solid var(--border); border-radius: 7px; padding: 8px 10px; }
    .ch-metric .lbl { font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: var(--text3); font-weight: 600; }
    .ch-metric .val { font-size: 18px; font-weight: 800; line-height: 1.2; margin-top: 3px; }
    .ch-metric .sub { font-size: 10px; color: var(--text3); margin-top: 3px; line-height: 1.4; }

    /* ── Pump status row ──────────────────────────────── */
    .pump-status-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
    .pump-pill { display: inline-flex; align-items: center; gap: 5px; border-radius: 999px; padding: 4px 10px; font-size: 11px; font-weight: 700; }
    .pp-on    { background: #d1fae5; color: #065f46; }
    .pp-needs { background: #fef3c7; color: #92400e; }
    .pp-off   { background: var(--bg); color: var(--text3); border: 1px solid var(--border); }
    .mode-lbl { font-size: 10px; color: var(--text3); font-weight: 600; }

    /* ── Pump buttons ─────────────────────────────────── */
    .pump-btns { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 6px; }
    .pump-btns button { border: none; border-radius: var(--rb); padding: 9px 4px; font: inherit; font-size: 12px; font-weight: 700; cursor: pointer; color: #fff; transition: opacity .15s, transform .1s; }
    .pump-btns button:hover  { opacity: .86; }
    .pump-btns button:active { transform: scale(.97); }
    .btn-on   { background: var(--primary); }
    .btn-off  { background: var(--danger); }
    .btn-auto { background: #64748b; }

    /* ── Settings accordion ───────────────────────────── */
    .ch-settings { border-top: 1px solid var(--border-lt); }
    .ch-settings > summary { display: flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 700; color: var(--primary); cursor: pointer; padding: 7px 0 2px; list-style: none; user-select: none; }
    .ch-settings > summary::-webkit-details-marker { display: none; }
    .ch-settings > summary::before { content: '▶'; font-size: 8px; display: inline-block; transition: transform .12s; }
    .ch-settings[open] > summary::before { transform: rotate(90deg); }
    .settings-body { padding: 10px 0 2px; display: flex; flex-direction: column; gap: 12px; }
    .settings-sec-title { font-size: 10px; font-weight: 700; color: var(--text3); text-transform: uppercase; letter-spacing: .07em; margin-bottom: 6px; }
    .settings-form { display: grid; gap: 6px; }
    .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
    .field { display: flex; flex-direction: column; gap: 3px; }
    .field label { font-size: 11px; color: var(--text3); font-weight: 500; }
    .field input[type=text], .field input[type=number] { border: 1px solid var(--border); border-radius: 6px; padding: 6px 8px; font: inherit; font-size: 12px; background: var(--surface); color: var(--text); transition: border-color .15s, box-shadow .15s; }
    .field input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(5,150,105,.1); }
    .field input:disabled { opacity: .4; cursor: not-allowed; background: var(--bg); }
    .btn-save { border: none; border-radius: var(--rb); align-self: start; background: var(--primary); color: #fff; padding: 7px 12px; font: inherit; font-size: 12px; font-weight: 700; cursor: pointer; transition: opacity .15s; }
    .btn-save:hover { opacity: .86; }
    .btn-save:disabled { opacity: .35; cursor: not-allowed; }
    .offline-note { font-size: 10px; color: var(--danger); margin-top: 2px; }

    /* ── Card footer ──────────────────────────────────── */
    .card-footer { padding: 10px 16px; border-top: 1px solid var(--border-lt); font-size: 11px; color: var(--text3); }

    /* ── Danger zone ──────────────────────────────────── */
    .danger-zone { border-top: 1px solid #fee2e2; }
    .danger-zone > summary { display: flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 700; color: var(--danger); cursor: pointer; padding: 10px 16px; list-style: none; user-select: none; }
    .danger-zone > summary::-webkit-details-marker { display: none; }
    .danger-zone > summary::before { content: '▶'; font-size: 8px; display: inline-block; transition: transform .12s; }
    .danger-zone[open] > summary::before { transform: rotate(90deg); }
    .danger-body { padding: 0 16px 14px; }
    .btn-reset { width: 100%; border: none; border-radius: var(--rb); background: var(--danger); color: #fff; padding: 9px; font: inherit; font-size: 13px; font-weight: 700; cursor: pointer; transition: opacity .15s; }
    .btn-reset:hover { opacity: .86; }

    /* ── Empty state ──────────────────────────────────── */
    .empty-state { grid-column: 1/-1; text-align: center; padding: 60px 20px; color: var(--text3); }
    .empty-icon  { font-size: 40px; margin-bottom: 12px; }
    .empty-state p { font-size: 14px; font-weight: 600; color: var(--text2); }
    .empty-state small { font-size: 12px; display: block; margin-top: 4px; }

    /* ── Responsive ───────────────────────────────────── */
    @media (max-width: 640px) {
      .topbar { flex-wrap: wrap; height: auto; padding: 10px 14px; gap: 8px; }
      .sp { display: none; }
      .tb-right { width: 100%; flex-wrap: wrap; gap: 8px; }
      .api-form input[type=password] { width: 100%; flex: 1; }
      .wrap { padding: 14px 12px 32px; }
      .pump-grid { grid-template-columns: 1fr; gap: 12px; }
    }
    @media (max-width: 380px) {
      .stats-strip { flex-wrap: wrap; gap: 10px; }
      .dstat + .dstat { border-left: none; padding-left: 0; margin-left: 0; border-top: 1px solid var(--border); padding-top: 8px; }
    }
  </style>
</head>
<body>

<nav class="topbar">
  <a class="brand" href="?">
    <div class="brand-icon">💧</div>
    <div>
      <span class="brand-name">HydroSense</span>
      <span class="brand-sub">Pumpen Dashboard</span>
    </div>
  </a>
  <div class="vr"></div>
  <div class="tb-pills">
    <span class="tb-pill"><span class="dot" style="background:#64748b"></span><?= $deviceCount ?> Geräte</span>
    <span class="tb-pill"><span class="dot" style="background:var(--c-online)"></span><?= $onlineCount ?> online</span>
    <?php if ($recentCount): ?><span class="tb-pill"><span class="dot" style="background:var(--c-recent)"></span><?= $recentCount ?> vor 15 Min.</span><?php endif; ?>
  </div>
  <div class="sp"></div>
  <div class="tb-right">
    <div class="rc-row">
      <input type="checkbox" id="autoRefreshToggle" checked>
      <label for="autoRefreshToggle">Auto</label>
      <span id="refreshCountdown"><?= $refreshInterval ?></span>s
    </div>
    <div class="vr"></div>
    <form class="api-form ajax-settings-form">
      <input name="api_key" type="hidden" value="<?= htmlspecialchars($dashboardKey) ?>">
      <input type="hidden" name="action" value="save_dashboard_config">
      <label for="globalApiKey">API Key</label>
      <input id="globalApiKey" type="password" value="<?= htmlspecialchars($dashboardKey) ?>" placeholder="API Key eingeben…" autocomplete="current-password">
      <label for="refreshIntervalInput" style="margin-left:4px">Refresh</label>
      <input id="refreshIntervalInput" name="refresh_seconds" type="number" value="<?= $refreshInterval ?>" min="5">
      <span style="font-size:11px;color:var(--text3)">s</span>
      <button type="submit" class="btn-tb">Speichern</button>
    </form>
  </div>
</nav>

<div class="wrap">
  <?php if ($dashboardError): ?>
    <div class="alert alert-err" id="alertMsg">⚠ <?= htmlspecialchars($dashboardError) ?></div>
  <?php endif; ?>
  <?php if ($dashboardMessage): ?>
    <div class="alert alert-ok" id="alertMsg">✓ <?= htmlspecialchars($dashboardMessage) ?></div>
  <?php endif; ?>

  <div class="pump-grid">
    <?php if (empty($devices)): ?>
      <div class="empty-state">
        <div class="empty-icon">📡</div>
        <p>Noch keine Pumpe hat sich per API gemeldet.</p>
        <small>Sobald ein ESP32 Telemetrie sendet, erscheint hier eine Gerätekarte.</small>
      </div>
    <?php endif; ?>

    <?php foreach ($devices as $device): ?>
    <?php
      $deviceId   = textValue($device, 'device_id', 'hydrosense-esp32');
      $state      = deviceStatus($device);
      $channels   = normalizeChannels($device);
      $history    = loadHistory(historyPath($dataDir, $deviceId));
      $command    = readJsonFile(commandPath($dataDir, $deviceId), []);
      $gpioConfig = readJsonFile(gpioConfigPath($dataDir, $deviceId), []);

      $gpioConfig['soil_sensor_pins'] = $gpioConfig['soil_sensor_pins'] ?? '34,35,36,39';
      $gpioConfig['relay_pins']       = $gpioConfig['relay_pins'] ?? '26,25,32,33';
      $gpioConfig['dry_raw']          = $gpioConfig['dry_raw'] ?? implode(',', array_fill(0, PHP_CHANNEL_COUNT, '0'));
      $gpioConfig['wet_raw']          = $gpioConfig['wet_raw'] ?? implode(',', array_fill(0, PHP_CHANNEL_COUNT, '0'));
      $gpioConfig['channel_names']    = $gpioConfig['channel_names'] ?? array_fill(0, PHP_CHANNEL_COUNT, '');

      $channelNamesArray = array_pad((array)$gpioConfig['channel_names'], PHP_CHANNEL_COUNT, '');
      $soilPinsArray     = array_pad(explode(',', $gpioConfig['soil_sensor_pins']), PHP_CHANNEL_COUNT, '0');
      $relayPinsArray    = array_pad(explode(',', $gpioConfig['relay_pins']), PHP_CHANNEL_COUNT, '0');
      $dryRawArray       = array_pad(explode(',', $gpioConfig['dry_raw']), PHP_CHANNEL_COUNT, '0');
      $wetRawArray       = array_pad(explode(',', $gpioConfig['wet_raw']), PHP_CHANNEL_COUNT, '0');

      $canvasId    = 'hist-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $deviceId);
      $isOffline   = $state['key'] === 'offline';
      $sbClass     = 'sb-' . $state['key'];
    ?>
    <article class="card" id="device-<?= htmlspecialchars($deviceId) ?>">

      <div class="card-head">
        <div>
          <div class="dev-name"><?= htmlspecialchars($deviceId) ?></div>
          <div class="dev-ts"><?= htmlspecialchars($device['received_at'] ?? 'Kein Kontakt') ?></div>
        </div>
        <span class="status-badge <?= $sbClass ?>">
          <span class="dot"></span><?= htmlspecialchars($state['label']) ?>
        </span>
      </div>

      <div class="stats-strip">
        <div class="dstat">
          <div class="dstat-lbl">Batterie</div>
          <div class="dstat-val"><?= (int)($device['battery_percent'] ?? 0) ?>%</div>
          <div class="dstat-sub"><?= number_format(((int)($device['battery_mv'] ?? 0)) / 1000, 2) ?> V</div>
        </div>
        <div class="dstat">
          <div class="dstat-lbl">Kanäle</div>
          <div class="dstat-val"><?= count($channels) ?></div>
          <div class="dstat-sub">aktiv</div>
        </div>
        <div class="dstat">
          <div class="dstat-lbl">Uptime</div>
          <div class="dstat-val" style="font-size:16px"><?= gmdate('H:i', (int)(($device['uptime_ms'] ?? 0) / 1000)) ?></div>
          <div class="dstat-sub">h:min</div>
        </div>
      </div>

      <div class="chart-wrap">
        <canvas class="history-canvas" id="<?= htmlspecialchars($canvasId) ?>" width="600" height="70"
          data-history="<?= htmlspecialchars(json_encode($history, JSON_UNESCAPED_SLASHES)) ?>"></canvas>
      </div>

      <div class="ch-list">
        <?php foreach ($channels as $channel): ?>
        <?php
          $ci          = (int)($channel['index'] ?? 0);
          $displayName = !empty($channelNamesArray[$ci]) ? $channelNamesArray[$ci] : ($channel['name'] ?? ('Kanal ' . ($ci + 1)));
          $pumpOn      = !empty($channel['pump_on']);
          $needsWater  = !empty($channel['needs_water']);
          $moisture    = max(0, min(100, (int)($channel['moisture_percent'] ?? 0)));
          $barColor    = $moisture < 30 ? '#ef4444' : ($moisture < 55 ? '#f59e0b' : '#10b981');
          $ppClass     = $pumpOn ? 'pp-on' : ($needsWater ? 'pp-needs' : 'pp-off');
          $ppLabel     = $pumpOn ? '● AN' : ($needsWater ? '≈ Wasser benötigt' : '○ AUS');
        ?>
        <details class="channel" data-index="<?= $ci ?>">
          <summary class="channel-summary">
            <span class="ch-toggle" aria-hidden="true">+</span>
            <span class="ch-name"><?= htmlspecialchars($displayName) ?></span>
            <div class="ch-bar-wrap">
              <div class="ch-bar-track">
                <div class="ch-bar-fill" style="width:<?= $moisture ?>%;background:<?= $barColor ?>"></div>
              </div>
            </div>
            <span class="ch-pct" style="color:<?= $barColor ?>"><?= $moisture ?>%</span>
          </summary>
          <div class="ch-body">

            <div class="ch-metrics">
              <div class="ch-metric">
                <div class="lbl">Feuchte</div>
                <div class="val" style="color:<?= $barColor ?>"><?= $moisture ?>%</div>
                <div class="sub">Raw: <?= (int)($channel['soil_raw'] ?? 0) ?><br>ADC12: <?= (int)($channel['soil_raw12'] ?? 0) ?></div>
              </div>
              <div class="ch-metric">
                <div class="lbl">Kalibrierung</div>
                <div class="val" style="font-size:13px;line-height:1.5"><?= (int)($channel['dry_raw'] ?? 0) ?> → <?= (int)($channel['wet_raw'] ?? 0) ?></div>
                <div class="sub">Trocken → Nass</div>
              </div>
            </div>

            <div class="pump-status-row">
              <span class="pump-pill <?= $ppClass ?>"><?= $ppLabel ?></span>
              <span class="mode-lbl">Modus: <?= htmlspecialchars($channel['pump_mode'] ?? 'auto') ?></span>
            </div>

            <form method="post" class="pump-btns">
              <input name="api_key" type="hidden" value="<?= htmlspecialchars($dashboardKey) ?>">
              <input type="hidden" name="device_id" value="<?= htmlspecialchars($deviceId) ?>">
              <input type="hidden" name="channel" value="<?= $ci ?>">
              <button class="btn-on"   name="pump" value="on">An</button>
              <button class="btn-off"  name="pump" value="off">Aus</button>
              <button class="btn-auto" name="pump" value="auto">Auto</button>
            </form>

            <details class="ch-settings" data-type="settings">
              <summary>Einstellungen</summary>
              <div class="settings-body">

                <div>
                  <div class="settings-sec-title">Kanalname</div>
                  <form method="post" class="settings-form ajax-settings-form">
                    <input name="api_key" type="hidden" value="<?= htmlspecialchars($dashboardKey) ?>">
                    <input type="hidden" name="device_id" value="<?= htmlspecialchars($deviceId) ?>">
                    <input type="hidden" name="action" value="save_channel_name_config">
                    <input type="hidden" name="channel_index" value="<?= $ci ?>">
                    <div class="field">
                      <label>Name</label>
                      <input name="channel_name" type="text" value="<?= htmlspecialchars($channelNamesArray[$ci] ?? '') ?>" placeholder="z.B. Tomaten">
                    </div>
                    <button type="submit" class="btn-save">Speichern</button>
                  </form>
                </div>

                <div>
                  <div class="settings-sec-title">Kalibrierung</div>
                  <form method="post" class="settings-form ajax-settings-form">
                    <input name="api_key" type="hidden" value="<?= htmlspecialchars($dashboardKey) ?>">
                    <input type="hidden" name="device_id" value="<?= htmlspecialchars($deviceId) ?>">
                    <input type="hidden" name="action" value="save_channel_calibration_config">
                    <input type="hidden" name="channel_index" value="<?= $ci ?>">
                    <div class="row2">
                      <div class="field">
                        <label>Trocken (Luft)</label>
                        <input name="dry_raw_value" type="number" value="<?= htmlspecialchars($dryRawArray[$ci] ?? '') ?>" <?= $isOffline ? 'disabled' : '' ?>>
                      </div>
                      <div class="field">
                        <label>Nass (Wasser)</label>
                        <input name="wet_raw_value" type="number" value="<?= htmlspecialchars($wetRawArray[$ci] ?? '') ?>" <?= $isOffline ? 'disabled' : '' ?>>
                      </div>
                    </div>
                    <button type="submit" class="btn-save" <?= $isOffline ? 'disabled' : '' ?>>Kalibrierung speichern</button>
                    <?php if ($isOffline): ?><p class="offline-note">Offline – Kalibrierung gesperrt.</p><?php endif; ?>
                  </form>
                </div>

                <div>
                  <div class="settings-sec-title">GPIO-Pins</div>
                  <form method="post" class="settings-form ajax-settings-form">
                    <input name="api_key" type="hidden" value="<?= htmlspecialchars($dashboardKey) ?>">
                    <input type="hidden" name="device_id" value="<?= htmlspecialchars($deviceId) ?>">
                    <input type="hidden" name="action" value="save_channel_gpio_config">
                    <input type="hidden" name="channel_index" value="<?= $ci ?>">
                    <div class="row2">
                      <div class="field">
                        <label>Sensor GPIO</label>
                        <input name="humidity_sensor_gpio" type="number" value="<?= htmlspecialchars($soilPinsArray[$ci] ?? '') ?>" <?= $isOffline ? 'disabled' : '' ?>>
                      </div>
                      <div class="field">
                        <label>Pumpen GPIO</label>
                        <input name="pump_gpio" type="number" value="<?= htmlspecialchars($relayPinsArray[$ci] ?? '') ?>" <?= $isOffline ? 'disabled' : '' ?>>
                      </div>
                    </div>
                    <button type="submit" class="btn-save" <?= $isOffline ? 'disabled' : '' ?>>GPIO speichern</button>
                    <?php if ($isOffline): ?><p class="offline-note">Offline – GPIO Einstellungen gesperrt.</p><?php endif; ?>
                  </form>
                </div>

              </div>
            </details>
          </div>
        </details>
        <?php endforeach; ?>
      </div>

      <div class="card-footer">
        Letzter Befehl: Kanal <?= (int)(($command['channel'] ?? 0) + 1) ?> · <?= htmlspecialchars($command['pump'] ?? 'keiner') ?><?= empty($command['acked_at']) ? '' : ' · ✓ bestätigt' ?>
      </div>

      <details class="danger-zone" data-type="danger">
        <summary>⚠ Geräte-Optionen</summary>
        <div class="danger-body">
          <form method="post" onsubmit="return confirm('Möchten Sie wirklich alle Einstellungen für dieses Gerät zurücksetzen?');">
            <input name="api_key" type="hidden" value="<?= htmlspecialchars($dashboardKey) ?>">
            <input type="hidden" name="device_id" value="<?= htmlspecialchars($deviceId) ?>">
            <input type="hidden" name="action" value="reset_device_settings">
            <button type="submit" class="btn-reset">Gerät auf Standardwerte zurücksetzen</button>
          </form>
        </div>
      </details>

    </article>
    <?php endforeach; ?>
  </div>
</div>

<script>
$(function () {

  /* ── API key sync ──────────────────────────── */
  function syncApiKey(v) {
    const val = v !== undefined ? v : $('#globalApiKey').val();
    $('input[name="api_key"]').val(val);
  }
  const urlParams = new URLSearchParams(window.location.search);
  const savedKey  = localStorage.getItem('hydrosense_api_key');
  if (!urlParams.has('api_key') && savedKey) {
    const u = new URL(window.location.href);
    u.searchParams.set('api_key', savedKey);
    window.location.replace(u.href);
  }
  $('#globalApiKey').on('input change blur', function () { syncApiKey($(this).val()); });
  syncApiKey();

  /* ── History charts ────────────────────────── */
  function initCharts() {
    $('.history-canvas').each(function () {
      const data = $(this).data('history') || [];
      const ctx  = this.getContext('2d');
      const W = this.width, H = this.height, pL = 26, pB = 3, pT = 3;
      ctx.clearRect(0, 0, W, H);

      ctx.font = '9px system-ui'; ctx.textBaseline = 'middle';
      for (const p of [0, 50, 100]) {
        const y = H - pB - (p / 100) * (H - pB - pT);
        ctx.strokeStyle = '#e2e8f0'; ctx.lineWidth = 1;
        ctx.beginPath(); ctx.moveTo(pL, y); ctx.lineTo(W, y); ctx.stroke();
        ctx.fillStyle = '#94a3b8'; ctx.fillText(p + '%', 0, y);
      }
      if (data.length < 2) return;

      const xs = (i) => pL + i * (W - pL) / (data.length - 1);
      const ys = (v) => H - pB - Math.max(0, Math.min(100, v || 0)) / 100 * (H - pB - pT);

      const grad = ctx.createLinearGradient(0, pT, 0, H - pB);
      grad.addColorStop(0, 'rgba(5,150,105,.22)');
      grad.addColorStop(1, 'rgba(5,150,105,.01)');
      ctx.fillStyle = grad; ctx.beginPath();
      data.forEach((d, i) => i === 0 ? ctx.moveTo(xs(i), ys(d.moisture_percent)) : ctx.lineTo(xs(i), ys(d.moisture_percent)));
      ctx.lineTo(xs(data.length - 1), H - pB); ctx.lineTo(pL, H - pB); ctx.closePath(); ctx.fill();

      ctx.strokeStyle = '#059669'; ctx.lineWidth = 1.5; ctx.lineJoin = 'round';
      ctx.beginPath();
      data.forEach((d, i) => i === 0 ? ctx.moveTo(xs(i), ys(d.moisture_percent)) : ctx.lineTo(xs(i), ys(d.moisture_percent)));
      ctx.stroke();
    });
  }
  initCharts();

  /* ── Expand icon toggle ────────────────────── */
  $(document).on('toggle', '.channel', function () {
    $(this).find('> .channel-summary .ch-toggle').first().text(this.open ? '−' : '+');
  });

  /* ── AJAX settings forms ───────────────────── */
  function showAlert(msg, type) {
    const cls  = type === 'err' ? 'alert-err' : 'alert-ok';
    const icon = type === 'err' ? '⚠ ' : '✓ ';
    let $el = $('#alertMsg');
    if (!$el.length) $el = $('<div id="alertMsg" class="alert"></div>').prependTo('.wrap');
    $el.removeClass('alert-ok alert-err').addClass(cls).text(icon + msg).show();
    clearTimeout($el.data('t'));
    $el.data('t', setTimeout(() => $el.fadeOut(300), 5000));
  }

  $(document).on('submit', '.ajax-settings-form', function (e) {
    e.preventDefault();
    const $f   = $(this);
    const action = $f.find('input[name="action"]').val();
    $f.find('input[name="api_key"]').val($('#globalApiKey').val());

    $.post(window.location.href, $f.serialize())
      .done(function (res) {
        if (action === 'save_dashboard_config') {
          const newKey = $('#globalApiKey').val();
          localStorage.setItem('hydrosense_api_key', newKey);
          if (res.refresh_seconds) {
            globalRefreshInterval = parseInt(res.refresh_seconds);
            timeLeft = globalRefreshInterval;
            $('#refreshCountdown').text(timeLeft);
          }
          const u = new URL(window.location.href);
          u.searchParams.set('api_key', newKey);
          window.history.replaceState({}, '', u);
        }
        showAlert(res.message || 'Einstellung gespeichert.', 'ok');
      })
      .fail(function (xhr) {
        showAlert('Fehler: ' + (xhr.responseJSON ? xhr.responseJSON.error : xhr.statusText), 'err');
      });
  });

  /* ── Auto-refresh ──────────────────────────── */
  let globalRefreshInterval = <?= $refreshInterval ?>;
  let timeLeft = globalRefreshInterval;
  const $cd  = $('#refreshCountdown');
  const $tog = $('#autoRefreshToggle');

  setInterval(() => {
    if (!$tog.is(':checked')) { $cd.text(globalRefreshInterval); timeLeft = globalRefreshInterval; return; }
    $cd.text(--timeLeft);
    if (timeLeft <= 0) { timeLeft = globalRefreshInterval; doRefresh(); }
  }, 1000);

  function doRefresh() {
    const paths = [];
    $('.pump-grid details[open]').each(function () {
      const $d  = $(this);
      const did = $d.closest('.card').attr('id');
      if (!did) return;
      if ($d.hasClass('channel'))
        paths.push(`#${did} .channel[data-index="${$d.data('index')}"]`);
      else if ($d.data('type') === 'settings')
        paths.push(`#${did} .channel[data-index="${$d.closest('.channel').data('index')}"] .ch-settings[data-type="settings"]`);
      else if ($d.data('type') === 'danger')
        paths.push(`#${did} .danger-zone[data-type="danger"]`);
    });

    $.get(window.location.href, function (html) {
      const $new = $($.parseHTML(html));
      $('.tb-pills').html($new.find('.tb-pills').html());
      if ($(document.activeElement).is('input,textarea,select')) return;
      $('.pump-grid').html($new.find('.pump-grid').html());
      paths.forEach(p => $(p).attr('open', true));
      $('.channel[open] > .channel-summary .ch-toggle').text('−');
      initCharts(); syncApiKey();
    }).fail(() => console.warn('HydroSense: refresh failed'));
  }

  /* ── Auto-hide page-load alert ─────────────── */
  if ($('#alertMsg').length) {
    setTimeout(() => {
      $('#alertMsg').fadeOut(300);
      const u = new URL(window.location.href);
      u.searchParams.delete('msg');
      window.history.replaceState({}, '', u);
    }, 4000);
  }
});
</script>
</body>
</html>
