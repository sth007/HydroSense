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
    :root { color-scheme: light; font-family: system-ui, -apple-system, Segoe UI, sans-serif; background: #f0f4f2; color: #17211d; }
    *, *::before, *::after { box-sizing: border-box; }
    body { margin: 0; }
    main { max-width: 1200px; margin: 0 auto; padding: 12px 12px 28px; }
    .muted { color: #63736c; }

    /* Header */
    .page-header { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; justify-content: space-between; margin-bottom: 8px; }
    .page-title h1 { margin: 0; font-size: clamp(18px, 3.5vw, 26px); line-height: 1; }
    .page-title small { font-size: 11px; color: #63736c; }
    .header-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 5px; }
    .pill { background: #fff; border: 1px solid #d9e4df; border-radius: 999px; padding: 3px 9px; font-size: 12px; font-weight: 700; }
    .refresh-ctrl { display: flex; align-items: center; gap: 5px; font-size: 12px; color: #63736c; }
    .refresh-ctrl input[type=checkbox] { margin: 0; }

    /* API key bar */
    .api-bar { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; background: #fff; border: 1px solid #d9e4df; border-radius: 7px; padding: 6px 11px; }
    .api-bar label { font-size: 11px; font-weight: 700; color: #63736c; white-space: nowrap; }
    .api-bar input { flex: 1; max-width: 260px; border: 1px solid #c6d5ce; border-radius: 5px; padding: 5px 8px; font: inherit; font-size: 13px; }

    /* Device grid */
    .pump-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 10px; align-items: start; }
    .card { background: #fff; border: 1px solid #d9e4df; border-radius: 10px; padding: 12px; box-shadow: 0 1px 3px rgb(20 40 32 / 5%); }

    /* Device header */
    .device-head { display: flex; justify-content: space-between; gap: 8px; align-items: flex-start; margin-bottom: 8px; }
    .device-title h2 { font-size: 14px; margin: 0 0 2px; overflow-wrap: anywhere; font-weight: 800; }
    .device-title .ts { font-size: 10px; color: #63736c; }
    .status { display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; font-size: 11px; font-weight: 700; }
    .status-dot { width: 8px; height: 8px; flex-shrink: 0; border-radius: 50%; background: var(--status-color); box-shadow: 0 0 0 2px color-mix(in srgb, var(--status-color) 22%, transparent); display: inline-block; }

    /* Device metrics */
    .device-metrics { display: flex; gap: 6px; margin-bottom: 8px; }
    .d-metric { flex: 1; border: 1px solid #e1ebe6; border-radius: 5px; padding: 5px 8px; min-width: 0; }
    .d-metric .lbl { font-size: 10px; color: #63736c; margin-bottom: 1px; }
    .d-metric .val { font-size: 16px; font-weight: 800; line-height: 1; }
    .d-metric .sub { font-size: 10px; color: #63736c; }

    /* Chart */
    .history-canvas { width: 100%; height: 80px; display: block; margin-bottom: 8px; border-top: 1px solid #edf3f0; padding-top: 5px; }

    /* Channel */
    .channel-grid { display: grid; gap: 6px; }
    .channel { border: 1px solid #dbe7e1; border-radius: 7px; background: #fafcfb; padding: 0; overflow: hidden; }
    .channel-summary { display: flex; align-items: center; justify-content: space-between; padding: 10px 12px; cursor: pointer; list-style: none; user-select: none; }
    .channel-summary::-webkit-details-marker { display: none; }
    .channel-summary h3 { margin: 0; font-size: 13px; font-weight: 700; flex: 1; display: flex; align-items: center; gap: 8px; }
    .channel-summary h3::before { content: '+'; display: inline-block; width: 14px; height: 14px; line-height: 12px; text-align: center; border: 1px solid #1d6f54; border-radius: 3px; font-size: 12px; color: #1d6f54; font-family: monospace; font-weight: 800; }
    .channel[open] .channel-summary h3::before { content: '−'; }
    .channel[open] .channel-summary { border-bottom: 1px solid #edf3f0; }
    .summary-val { font-size: 16px; font-weight: 800; color: #1d6f54; }
    .channel-content { padding: 10px; }

    .channel-head { display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-bottom: 10px; }
    .channel-head h3 { margin: 0; font-size: 13px; font-weight: 700; overflow-wrap: anywhere; }
    .pump-badge { font-size: 10px; font-weight: 800; padding: 2px 6px; border-radius: 4px; white-space: nowrap; }
    .pump-badge.on { background: #dcf0e8; color: #1d6f54; }
    .pump-badge.off { background: #f0f4f2; color: #63736c; border: 1px solid #d9e4df; }
    .pump-badge.needs { background: #fef3e2; color: #b06c00; }

    /* Channel metrics */
    .ch-metrics { display: flex; gap: 5px; margin-bottom: 6px; }
    .ch-metric { flex: 1; border: 1px solid #e8f0ec; border-radius: 5px; padding: 5px 7px; min-width: 0; }
    .ch-metric .lbl { font-size: 10px; color: #63736c; margin-bottom: 1px; }
    .ch-metric .val { font-size: 17px; font-weight: 800; line-height: 1; }
    .ch-metric .sub { font-size: 10px; color: #63736c; margin-top: 2px; line-height: 1.3; }

    /* Pump control */
    .pump-form { display: flex; gap: 4px; margin-bottom: 4px; }
    .pump-form button { flex: 1; border: 0; border-radius: 5px; padding: 6px 2px; font: inherit; font-size: 12px; font-weight: 700; cursor: pointer; transition: opacity 0.15s; color: #fff; }
    .btn-on { background: #1d6f54; }
    .btn-off { background: #c04836; }
    .btn-auto { background: #5a7066; }
    .pump-form button:hover { opacity: 0.85; }
    .mode-line { font-size: 10px; color: #63736c; margin: 0 0 4px; }
    .danger-zone { border-top: 1px solid #fee2e2; margin-top: 10px; padding-top: 8px; }

    /* Settings accordion */
    .ch-settings { border-top: 1px solid #edf3f0; margin-top: 4px; }
    .ch-settings > summary { font-size: 11px; font-weight: 700; color: #1d6f54; cursor: pointer; padding: 5px 0 1px; list-style: none; display: flex; align-items: center; gap: 4px; user-select: none; }
    .ch-settings > summary::-webkit-details-marker { display: none; }
    .ch-settings > summary::before { content: '▶'; font-size: 8px; transition: transform 0.12s; display: inline-block; }
    .ch-settings[open] > summary::before { transform: rotate(90deg); }
    .ch-settings > summary:hover { text-decoration: underline; }
    .settings-body { padding: 8px 0 2px; display: grid; gap: 10px; }
    .settings-sec h4 { font-size: 10px; font-weight: 700; color: #63736c; margin: 0 0 5px; text-transform: uppercase; letter-spacing: 0.05em; }
    .settings-sec form { display: grid; gap: 4px; }
    .settings-sec label { font-size: 11px; color: #63736c; }
    .settings-sec input[type=text], .settings-sec input[type=number] { width: 100%; border: 1px solid #c6d5ce; border-radius: 5px; padding: 5px 7px; font: inherit; font-size: 12px; }
    .settings-sec button[type=submit] { border: 0; border-radius: 5px; padding: 6px 10px; font: inherit; font-size: 12px; font-weight: 700; cursor: pointer; background: #1d6f54; color: #fff; transition: opacity 0.15s; }
    .settings-sec button[type=submit]:hover { opacity: 0.85; }
    .settings-sec button[type=submit]:disabled { opacity: 0.4; cursor: not-allowed; }
    .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
    .offline-note { font-size: 10px; color: #a33b2b; margin: 3px 0 0; }

    /* Device footer */
    .device-footer { font-size: 10px; color: #63736c; margin-top: 6px; }

    /* Messages */
    .success { color: #1d6f54; background: #eef7f3; border: 1px solid #c8e5d7; border-radius: 6px; padding: 8px 12px; margin-bottom: 8px; font-size: 13px; font-weight: 700; }
    .error { color: #a43d2d; font-weight: 700; font-size: 13px; margin-bottom: 6px; }
    .empty { padding: 20px; text-align: center; color: #63736c; }

    @media (max-width: 540px) {
      .pump-grid { grid-template-columns: 1fr; }
      .api-bar input { max-width: none; }
      .header-meta { width: 100%; }
    }
    @media (max-width: 360px) {
      main { padding: 8px 8px 20px; }
      .device-metrics, .ch-metrics { flex-wrap: wrap; }
    }
  </style>
</head>
<body>
<main>
  <div class="page-header">
    <div class="page-title">
      <h1>HydroSense</h1>
      <small>Pumpen Dashboard</small>
    </div>
    <div class="header-meta">
      <span class="pill"><?= $deviceCount ?> Geräte</span>
      <span class="pill" style="color:#10894e"><?= $onlineCount ?> online</span>
      <?php if ($recentCount): ?><span class="pill" style="color:#d98220"><?= $recentCount ?> vor 15 Min.</span><?php endif; ?>
      <span class="refresh-ctrl">
        <input type="checkbox" id="autoRefreshToggle" checked>
        <label for="autoRefreshToggle">Auto</label>
        <span id="refreshCountdown">15</span>s
      </span>
    </div>
  </div>

  <div class="api-bar">
    <label for="globalApiKey">API Key</label>
    <input id="globalApiKey" type="password" value="<?= htmlspecialchars($dashboardKey) ?>" placeholder="API key eingeben…" autocomplete="current-password">
  </div>

  <?php if ($dashboardError): ?><p class="error"><?= htmlspecialchars($dashboardError) ?></p><?php endif; ?>
  <?php if ($dashboardMessage): ?><div class="success" id="successMsg"><?= htmlspecialchars($dashboardMessage) ?></div><?php endif; ?>

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
        $gpioConfig = readJsonFile(gpioConfigPath($dataDir, $deviceId), []);
        
        // Ensure all keys exist for UI rendering
        $gpioConfig['soil_sensor_pins'] = $gpioConfig['soil_sensor_pins'] ?? '34,35,36,39';
        $gpioConfig['relay_pins'] = $gpioConfig['relay_pins'] ?? '26,25,32,33';
        $gpioConfig['dry_raw'] = $gpioConfig['dry_raw'] ?? implode(',', array_fill(0, PHP_CHANNEL_COUNT, '0'));
        $gpioConfig['wet_raw'] = $gpioConfig['wet_raw'] ?? implode(',', array_fill(0, PHP_CHANNEL_COUNT, '0'));
        $gpioConfig['channel_names'] = $gpioConfig['channel_names'] ?? array_fill(0, PHP_CHANNEL_COUNT, '');

        $channelNamesArray = $gpioConfig['channel_names'] ?? array_fill(0, PHP_CHANNEL_COUNT, '');
        $channelNamesArray = array_pad($channelNamesArray, PHP_CHANNEL_COUNT, '');

        $soilPinsArray = explode(',', $gpioConfig['soil_sensor_pins']);
        $relayPinsArray = explode(',', $gpioConfig['relay_pins']);
        $dryRawArray = explode(',', $gpioConfig['dry_raw']);
        $wetRawArray = explode(',', $gpioConfig['wet_raw']);
        $soilPinsArray = array_pad($soilPinsArray, PHP_CHANNEL_COUNT, '0');
        $relayPinsArray = array_pad($relayPinsArray, PHP_CHANNEL_COUNT, '0');
        $canvasId = 'history-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $deviceId);
        $isOffline = $state['key'] === 'offline';
      ?>
      <article class="card" id="device-<?= htmlspecialchars($deviceId) ?>">
        <div class="device-head">
          <div class="device-title">
            <h2><?= htmlspecialchars($deviceId) ?></h2>
            <div class="ts"><?= htmlspecialchars($device['received_at'] ?? 'unbekannt') ?></div>
          </div>
          <div class="status" style="--status-color: <?= htmlspecialchars($state['color']) ?>">
            <span class="status-dot" aria-hidden="true"></span>
            <?= htmlspecialchars($state['label']) ?>
          </div>
        </div>

        <div class="device-metrics">
          <div class="d-metric">
            <div class="lbl">Batterie</div>
            <div class="val"><?= (int) ($device['battery_percent'] ?? 0) ?>%</div>
            <div class="sub"><?= number_format(((int) ($device['battery_mv'] ?? 0)) / 1000, 2) ?> V</div>
          </div>
          <div class="d-metric">
            <div class="lbl">Kanäle</div>
            <div class="val"><?= count($channels) ?></div>
            <div class="sub">aktiv</div>
          </div>
        </div>

        <canvas class="history-canvas" id="<?= htmlspecialchars($canvasId) ?>" width="520" height="80"
          data-history="<?= htmlspecialchars(json_encode($history, JSON_UNESCAPED_SLASHES)) ?>"></canvas>

        <div class="channel-grid">
          <?php foreach ($channels as $channel): ?>
            <?php
              $ci = (int) ($channel['index'] ?? 0);
              $displayName = !empty($channelNamesArray[$ci]) ? $channelNamesArray[$ci] : ($channel['name'] ?? ('Pump ' . ($ci + 1)));
              $pumpOn = !empty($channel['pump_on']);
              $needsWater = !empty($channel['needs_water']);
            ?>
            <details class="channel">
              <summary class="channel-summary">
                <h3><?= htmlspecialchars($displayName) ?></h3>
                <span class="summary-val"><?= (int) ($channel['moisture_percent'] ?? 0) ?>%</span>
              </summary>
              <div class="channel-content">
                <div class="channel-head">
                  <span class="pump-badge <?= $pumpOn ? 'on' : ($needsWater ? 'needs' : 'off') ?>">
                    <?= $pumpOn ? 'AN' : ($needsWater ? '~ Wasser' : 'AUS') ?>
                  </span>
                  <p class="mode-line" style="margin:0">Modus: <?= htmlspecialchars($channel['pump_mode'] ?? 'auto') ?></p>
                </div>

                <div class="ch-metrics">
                  <div class="ch-metric">
                    <div class="lbl">Sensor raw</div>
                    <div class="val"><?= (int) ($channel['soil_raw'] ?? 0) ?></div>
                    <div class="sub">ADC12: <?= (int) ($channel['soil_raw12'] ?? 0) ?><br>Tr: <?= (int) ($channel['dry_raw'] ?? 0) ?> · Na: <?= (int) ($channel['wet_raw'] ?? 0) ?></div>
                  </div>
                </div>

              <form method="post" class="pump-form">
                <input name="api_key" type="hidden" value="<?= htmlspecialchars($dashboardKey) ?>">
                <input type="hidden" name="device_id" value="<?= htmlspecialchars($deviceId) ?>">
                <input type="hidden" name="channel" value="<?= $ci ?>">
                <button class="btn-on" name="pump" value="on">An</button>
                <button class="btn-off" name="pump" value="off">Aus</button>
                <button class="btn-auto" name="pump" value="auto">Auto</button>
              </form>

              <details class="ch-settings">
                <summary>Einstellungen</summary>
                <div class="settings-body">

                  <div class="settings-sec">
                    <h4>Kanalname</h4>
                    <form method="post" class="ajax-settings-form">
                      <input name="api_key" type="hidden" value="<?= htmlspecialchars($dashboardKey) ?>">
                      <input type="hidden" name="device_id" value="<?= htmlspecialchars($deviceId) ?>">
                      <input type="hidden" name="action" value="save_channel_name_config">
                      <input type="hidden" name="channel_index" value="<?= $ci ?>">
                      <div class="row2">
                        <input name="channel_name" type="text" value="<?= htmlspecialchars($channelNamesArray[$ci] ?? '') ?>" placeholder="z.B. Tomaten">
                        <button type="submit">Speichern</button>
                      </div>
                    </form>
                  </div>

                  <div class="settings-sec">
                    <h4>Kalibrierung</h4>
                    <form method="post" class="ajax-settings-form">
                      <input name="api_key" type="hidden" value="<?= htmlspecialchars($dashboardKey) ?>">
                      <input type="hidden" name="device_id" value="<?= htmlspecialchars($deviceId) ?>">
                      <input type="hidden" name="action" value="save_channel_calibration_config">
                      <input type="hidden" name="channel_index" value="<?= $ci ?>">
                      <div class="row2">
                        <div>
                          <label>Trocken (Luft)</label>
                          <input name="dry_raw_value" type="number" value="<?= htmlspecialchars($dryRawArray[$ci] ?? '') ?>" <?= $isOffline ? 'disabled' : '' ?>>
                        </div>
                        <div>
                          <label>Nass (Wasser)</label>
                          <input name="wet_raw_value" type="number" value="<?= htmlspecialchars($wetRawArray[$ci] ?? '') ?>" <?= $isOffline ? 'disabled' : '' ?>>
                        </div>
                      </div>
                      <button type="submit" <?= $isOffline ? 'disabled' : '' ?>>Kalibrierung speichern</button>
                    </form>
                    <?php if ($isOffline): ?><p class="offline-note">Offline – Kalibrierung gesperrt.</p><?php endif; ?>
                  </div>

                  <div class="settings-sec">
                    <h4>GPIO</h4>
                    <form method="post" class="ajax-settings-form">
                      <input name="api_key" type="hidden" value="<?= htmlspecialchars($dashboardKey) ?>">
                      <input type="hidden" name="device_id" value="<?= htmlspecialchars($deviceId) ?>">
                      <input type="hidden" name="action" value="save_channel_gpio_config">
                      <input type="hidden" name="channel_index" value="<?= $ci ?>">
                      <div class="row2">
                        <div>
                          <label>Sensor GPIO</label>
                          <input name="humidity_sensor_gpio" type="number" value="<?= htmlspecialchars($soilPinsArray[$ci] ?? '') ?>" <?= $isOffline ? 'disabled' : '' ?>>
                        </div>
                        <div>
                          <label>Pumpen GPIO</label>
                          <input name="pump_gpio" type="number" value="<?= htmlspecialchars($relayPinsArray[$ci] ?? '') ?>" <?= $isOffline ? 'disabled' : '' ?>>
                        </div>
                      </div>
                      <button type="submit" <?= $isOffline ? 'disabled' : '' ?>>GPIO speichern</button>
                    </form>
                    <?php if ($isOffline): ?><p class="offline-note">Offline – GPIO Einstellungen gesperrt.</p><?php endif; ?>
                  </div>

                </div>
              </details>
              </div>
            </details>
          <?php endforeach; ?>
        </div>
        <p class="device-footer">
          Letzter Befehl: Kanal <?= (int) (($command['channel'] ?? 0) + 1) ?> · <?= htmlspecialchars($command['pump'] ?? 'keiner') ?><?= empty($command['acked_at']) ? '' : ' · bestätigt' ?>
        </p>
        
        <details class="ch-settings danger-zone">
          <summary style="color: #a33b2b;">Geräte-Optionen</summary>
          <form method="post" style="padding-top: 8px;" onsubmit="return confirm('Möchten Sie wirklich alle Einstellungen für dieses Gerät (Pins, Namen, Kalibrierung) auf die Standardwerte zurücksetzen?');">
            <input name="api_key" type="hidden" value="<?= htmlspecialchars($dashboardKey) ?>">
            <input type="hidden" name="device_id" value="<?= htmlspecialchars($deviceId) ?>">
            <input type="hidden" name="action" value="reset_device_settings">
            <button type="submit" class="btn-off" style="width: 100%; border: 0; border-radius: 5px; padding: 8px; color: #fff; font-weight: 700; cursor: pointer;">Gerät auf Standardwerte zurücksetzen</button>
          </form>
        </details>
      </article>
    <?php endforeach; ?>
  </section>
</main>

<script>
$(function() {
  function syncApiKey(val) {
    const v = val !== undefined ? val : $('#globalApiKey').val();
    $('input[name="api_key"]').val(v);
  }

  $('#globalApiKey').on('input change blur', function() {
    syncApiKey($(this).val());
  });

  syncApiKey(); // Initialer Sync beim Laden

  function initCharts() {
    $('.history-canvas').each(function() {
      const historyData = $(this).data('history') || [];
      const ctx = this.getContext('2d');
      const w = this.width, h = this.height, pad = 22;
      ctx.clearRect(0, 0, w, h);
      ctx.strokeStyle = '#d7e1dc';
      ctx.lineWidth = 1;
      ctx.font = '10px system-ui';
      ctx.fillStyle = '#63736c';
      for (const p of [0, 50, 100]) {
        const y = h - pad - (p / 100) * (h - pad * 2);
        ctx.beginPath(); ctx.moveTo(pad, y); ctx.lineTo(w - 4, y); ctx.stroke();
        ctx.fillText(`${p}%`, 0, y + 4);
      }
      if (historyData.length > 1) {
        ctx.strokeStyle = '#1d6f54';
        ctx.lineWidth = 2;
        ctx.beginPath();
        historyData.forEach((item, i) => {
          const x = pad + i * (w - pad - 4) / (historyData.length - 1);
          const y = h - pad - Math.max(0, Math.min(100, item.moisture_percent || 0)) / 100 * (h - pad * 2);
          i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
        });
        ctx.stroke();
      }
    });
  }

  initCharts();

  // Handle configuration forms via AJAX (Name, Calibration, GPIO)
  $(document).on('submit', '.ajax-settings-form', function(e) {
    e.preventDefault();
    const $form = $(this);
    
    // Vor dem Absenden sicherstellen, dass der Key aus dem globalen Feld übernommen wird
    const masterKey = $('#globalApiKey').val();
    $form.find('input[name="api_key"]').val(masterKey);

    const formData = $form.serialize();
    const action = $form.find('input[name="action"]').val();
    
    console.group('HydroSense: Settings Save');
    console.log('[Time]', new Date().toLocaleTimeString());
    console.log('[Action]', action);
    console.log('[Payload]', $form.serializeArray().reduce((acc, item) => { acc[item.name] = item.value; return acc; }, {}));

    $.post(window.location.href, formData)
      .done(function(res) {
        console.log('[Response]', res);
        console.log('Status: Success');
        
        const msgText = res.message || 'Einstellung gespeichert.';
        if ($('#successMsg').length === 0) {
          $('<div class="success" id="successMsg"></div>').text(msgText).insertAfter('.api-bar');
        } else {
          $('#successMsg').text(msgText).show();
        }
        setTimeout(() => { $('#successMsg').fadeOut(400); }, 4000);
      })
      .fail(function(xhr) {
        const error = xhr.responseJSON ? xhr.responseJSON.error : xhr.statusText;
        console.error('Status: Error', xhr.status, error);
        alert('Fehler beim Speichern: ' + error);
      })
      .always(() => { console.groupEnd(); });
  });

  let timeLeft = 15;
  const $countdown = $('#refreshCountdown');
  const $toggle = $('#autoRefreshToggle');

  setInterval(() => {
    if (!$toggle.is(':checked')) { $countdown.text(15); return; }
    if (--timeLeft <= 0) { timeLeft = 15; refreshData(); }
    $countdown.text(timeLeft);
  }, 1000);

  function refreshData() {
    $.get(window.location.href, function(data) {
      const $new = $($.parseHTML(data));
      $('.header-meta .pill').each(function(i) {
        $(this).html($new.find('.header-meta .pill').eq(i).html());
      });
      if (!$(document.activeElement).is('input, textarea')) {
        $('.pump-grid').html($new.find('.pump-grid').html());
        initCharts();
        syncApiKey();
      }
    }).fail(function(e) { console.error('Refresh failed:', e); });
  }

  if ($('#successMsg').length) {
    setTimeout(() => {
      $('#successMsg').fadeOut(400);
      const url = new URL(window.location.href);
      url.searchParams.delete('msg');
      window.history.replaceState({}, '', url);
    }, 4000);
  }
});
</script>
</body>
</html>
