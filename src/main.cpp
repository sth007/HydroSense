#include <Arduino.h>
#include <HTTPClient.h>
#include <Preferences.h>
#include <WebServer.h>
#include <WiFi.h>

// HydroSense multi-channel controller.
// One ESP32 controls 4 humidity sensors and 4 pump relays/MOSFET channels.
constexpr uint8_t CHANNEL_COUNT = 4;

// ESP32 ADC1 pins. ADC1 keeps working while WiFi is active.
constexpr uint8_t SOIL_SENSOR_PINS[CHANNEL_COUNT] = {34, 35, 36, 39};

// Relay/MOSFET gate pins. Avoid GPIO0, GPIO2, GPIO12, GPIO15, GPIO1, GPIO3,
// and flash pins GPIO6-GPIO11 on classic ESP32 boards.
constexpr uint8_t RELAY_PINS[CHANNEL_COUNT] = {26, 25, 32, 33};

// This relay board is active LOW: GPIO LOW -> relay ON, GPIO HIGH -> relay OFF.
constexpr bool RELAY_ACTIVE_LOW = true;

// Battery measurement is disabled by default because 4 analog sensors use the ADC pins.
// If you add an external ADC or free one ADC pin, enable and adjust this section.
constexpr bool BATTERY_MONITOR_ENABLED = false;
constexpr uint8_t BATTERY_SENSOR_PIN = 4;
constexpr uint32_t BATTERY_DIVIDER_TOP_OHMS = 100000;
constexpr uint32_t BATTERY_DIVIDER_BOTTOM_OHMS = 100000;
constexpr uint16_t BATTERY_EMPTY_MV = 3300;
constexpr uint16_t BATTERY_FULL_MV = 4200;

// Calibrate each sensor with Serial Monitor readings.
constexpr int DRY_RAW[CHANNEL_COUNT] = {0, 0, 0, 0};
constexpr int WET_RAW[CHANNEL_COUNT] = {626, 626, 626, 626};

constexpr int START_WATERING_PERCENT = 35;
constexpr int STOP_WATERING_PERCENT = 55;
constexpr bool AUTO_WATERING_ENABLED = false;

constexpr unsigned long SENSOR_INTERVAL_MS = 2000;
constexpr unsigned long SERVER_INTERVAL_MS = 30000;
constexpr unsigned long COMMAND_INTERVAL_MS = 10000;
constexpr unsigned long MAX_PUMP_RUN_MS = 15000;
constexpr unsigned long PUMP_COOLDOWN_MS = 30000;
constexpr uint8_t ADC_DUMMY_READS = 4;

constexpr unsigned long WIFI_RECONNECT_INTERVAL_MS = 30000;
constexpr char CONFIG_AP_PASSWORD[] = "hydrosense";
constexpr char DEFAULT_API_BASE_URL[] = "";
constexpr char DEFAULT_API_KEY[] = "change-me";
constexpr char DEVICE_ID[] = "hydrosense-esp32";

enum class PumpMode : uint8_t {
  Auto,
  ManualOn,
  ManualOff,
};

struct ChannelState {
  int raw = 0;
  int raw12 = 0;
  int moisturePercent = 0;
  bool pumpOn = false;
  unsigned long pumpStartedMs = 0;
  unsigned long pumpStoppedMs = 0;
  PumpMode mode = PumpMode::Auto;
};

ChannelState channels[CHANNEL_COUNT];
unsigned long lastSensorReadMs = 0;
unsigned long lastServerPostMs = 0;
unsigned long lastCommandPollMs = 0;
uint32_t lastCommandId = 0;
int lastBatteryAdcRaw = 0;
uint16_t lastBatteryPinMilliVolts = 0;
uint16_t batteryMilliVolts = 0;
int batteryPercent = 0;
unsigned long lastWifiAttemptMs = 0;
bool configApActive = false;
String wifiSsid;
String wifiPassword;
String apiBaseUrl;
String apiKey;
String configApSsid;
Preferences preferences;
WebServer configServer(80);

void connectWifi();
void startConfigAp();

const char *pumpModeValue(PumpMode mode) {
  switch (mode) {
    case PumpMode::ManualOn:
      return "manual_on";
    case PumpMode::ManualOff:
      return "manual_off";
    case PumpMode::Auto:
    default:
      return "auto";
  }
}

void relayWrite(uint8_t channel, bool enabled) {
  if (channel >= CHANNEL_COUNT) {
    return;
  }
  const bool relayLevel = RELAY_ACTIVE_LOW ? !enabled : enabled;
  digitalWrite(RELAY_PINS[channel], relayLevel ? HIGH : LOW);
}

void setPump(uint8_t channel, bool enabled) {
  if (channel >= CHANNEL_COUNT) {
    return;
  }

  ChannelState &state = channels[channel];
  state.pumpOn = enabled;
  relayWrite(channel, enabled);

  if (enabled) {
    state.pumpStartedMs = millis();
  } else {
    state.pumpStoppedMs = millis();
  }
}

void initPumpsOff() {
  for (uint8_t channel = 0; channel < CHANNEL_COUNT; ++channel) {
    channels[channel].pumpOn = false;
    relayWrite(channel, false);
    channels[channel].pumpStoppedMs = millis() - PUMP_COOLDOWN_MS;
  }
}

int rawToMoisturePercent(uint8_t channel, int raw) {
  if (channel >= CHANNEL_COUNT || WET_RAW[channel] == DRY_RAW[channel]) {
    return 0;
  }

  const int percent = (raw - DRY_RAW[channel]) * 100 / (WET_RAW[channel] - DRY_RAW[channel]);
  return constrain(percent, 0, 100);
}

int readSoilRaw(uint8_t channel) {
  constexpr uint8_t samples = 12;
  uint32_t sum = 0;
  const uint8_t pin = SOIL_SENSOR_PINS[channel];

  for (uint8_t i = 0; i < ADC_DUMMY_READS; ++i) {
    analogRead(pin);
    delay(3);
  }

  for (uint8_t i = 0; i < samples; ++i) {
    const int raw12 = analogRead(pin);
    channels[channel].raw12 = raw12;
    sum += raw12 / 4;
    delay(5);
  }

  return static_cast<int>(sum / samples);
}

uint16_t readBatteryMilliVolts() {
  if (!BATTERY_MONITOR_ENABLED) {
    lastBatteryAdcRaw = 0;
    lastBatteryPinMilliVolts = 0;
    return 0;
  }

  constexpr uint8_t samples = 12;
  uint32_t sum = 0;

  for (uint8_t i = 0; i < ADC_DUMMY_READS; ++i) {
    analogRead(BATTERY_SENSOR_PIN);
    delay(3);
  }

  for (uint8_t i = 0; i < samples; ++i) {
    sum += analogRead(BATTERY_SENSOR_PIN);
    delay(3);
  }

  const uint32_t raw = sum / samples;
  lastBatteryAdcRaw = static_cast<int>(raw);
  const uint32_t pinMilliVolts = raw * 3300UL / 4095UL;
  lastBatteryPinMilliVolts = static_cast<uint16_t>(pinMilliVolts);
  const uint32_t dividerMilliVolts =
      pinMilliVolts * (BATTERY_DIVIDER_TOP_OHMS + BATTERY_DIVIDER_BOTTOM_OHMS) /
      BATTERY_DIVIDER_BOTTOM_OHMS;

  return static_cast<uint16_t>(dividerMilliVolts);
}

int batteryMilliVoltsToPercent(uint16_t milliVolts) {
  if (!BATTERY_MONITOR_ENABLED || milliVolts == 0) {
    return 0;
  }
  if (milliVolts <= BATTERY_EMPTY_MV) {
    return 0;
  }
  if (milliVolts >= BATTERY_FULL_MV) {
    return 100;
  }
  return static_cast<int>((milliVolts - BATTERY_EMPTY_MV) * 100UL /
                          (BATTERY_FULL_MV - BATTERY_EMPTY_MV));
}

void readAllSensors() {
  for (uint8_t channel = 0; channel < CHANNEL_COUNT; ++channel) {
    channels[channel].raw = readSoilRaw(channel);
    channels[channel].moisturePercent = rawToMoisturePercent(channel, channels[channel].raw);
  }
  batteryMilliVolts = readBatteryMilliVolts();
  batteryPercent = batteryMilliVoltsToPercent(batteryMilliVolts);
}

void applyPumpCommand(uint8_t channel, const String &command) {
  if (channel >= CHANNEL_COUNT) {
    return;
  }

  if (command == "on") {
    channels[channel].mode = PumpMode::ManualOn;
    setPump(channel, true);
    Serial.print(F("Pump "));
    Serial.print(channel + 1);
    Serial.println(F(" command: manual ON"));
  } else if (command == "off") {
    channels[channel].mode = PumpMode::ManualOff;
    setPump(channel, false);
    Serial.print(F("Pump "));
    Serial.print(channel + 1);
    Serial.println(F(" command: manual OFF"));
  } else if (command == "auto") {
    channels[channel].mode = PumpMode::Auto;
    Serial.print(F("Pump "));
    Serial.print(channel + 1);
    Serial.println(F(" command: AUTO"));
  }
}

void updatePumpAutomation(unsigned long now) {
  for (uint8_t channel = 0; channel < CHANNEL_COUNT; ++channel) {
    ChannelState &state = channels[channel];

    if (state.pumpOn && now - state.pumpStartedMs >= MAX_PUMP_RUN_MS) {
      if (state.mode == PumpMode::ManualOn) {
        state.mode = PumpMode::ManualOff;
      }
      setPump(channel, false);
      Serial.print(F("Pump "));
      Serial.print(channel + 1);
      Serial.println(F(" stopped: max runtime reached"));
      continue;
    }

    if (!AUTO_WATERING_ENABLED) {
      continue;
    }

    if (state.mode != PumpMode::Auto) {
      continue;
    }

    if (!state.pumpOn) {
      const bool cooldownDone = now - state.pumpStoppedMs >= PUMP_COOLDOWN_MS;
      if (cooldownDone && state.moisturePercent <= START_WATERING_PERCENT) {
        setPump(channel, true);
        Serial.print(F("Pump "));
        Serial.print(channel + 1);
        Serial.println(F(" started: soil is dry"));
      }
    } else if (state.moisturePercent >= STOP_WATERING_PERCENT) {
      setPump(channel, false);
      Serial.print(F("Pump "));
      Serial.print(channel + 1);
      Serial.println(F(" stopped: target moisture reached"));
    }
  }
}

void printStatus() {
  Serial.print(F("battery="));
  Serial.print(batteryMilliVolts);
  Serial.print(F("mV "));
  Serial.print(batteryPercent);
  Serial.println(F("%"));

  for (uint8_t channel = 0; channel < CHANNEL_COUNT; ++channel) {
    const ChannelState &state = channels[channel];
    Serial.print(F("ch="));
    Serial.print(channel + 1);
    Serial.print(F(" raw="));
    Serial.print(state.raw);
    Serial.print(F(" raw12="));
    Serial.print(state.raw12);
    Serial.print(F(" moisture="));
    Serial.print(state.moisturePercent);
    Serial.print(F("% pump="));
    Serial.print(state.pumpOn ? F("ON") : F("OFF"));
    Serial.print(F(" mode="));
    Serial.println(pumpModeValue(state.mode));
  }
}

String jsonEscape(const char *value) {
  String escaped;
  for (const char *p = value; *p != '\0'; ++p) {
    if (*p == '"' || *p == '\\') {
      escaped += '\\';
    }
    escaped += *p;
  }
  return escaped;
}

String buildTelemetryJson() {
  String json = "{";
  json += "\"device_id\":\"";
  json += jsonEscape(DEVICE_ID);
  json += "\",\"uptime_ms\":";
  json += millis();
  json += ",\"battery_mv\":";
  json += batteryMilliVolts;
  json += ",\"battery_percent\":";
  json += batteryPercent;
  json += ",\"battery_adc_raw\":";
  json += lastBatteryAdcRaw;
  json += ",\"channels\":[";

  for (uint8_t channel = 0; channel < CHANNEL_COUNT; ++channel) {
    const ChannelState &state = channels[channel];
    if (channel > 0) {
      json += ",";
    }
    json += "{\"index\":";
    json += channel;
    json += ",\"name\":\"Pump ";
    json += channel + 1;
    json += "\",\"moisture_percent\":";
    json += state.moisturePercent;
    json += ",\"soil_raw\":";
    json += state.raw;
    json += ",\"soil_raw12\":";
    json += state.raw12;
    json += ",\"pump_on\":";
    json += state.pumpOn ? "true" : "false";
    json += ",\"pump_mode\":\"";
    json += pumpModeValue(state.mode);
    json += "\",\"needs_water\":";
    json += state.moisturePercent <= START_WATERING_PERCENT ? "true" : "false";
    json += "}";
  }

  json += "]}";
  return json;
}

String htmlEscape(const String &value) {
  String escaped;
  escaped.reserve(value.length());
  for (size_t i = 0; i < value.length(); ++i) {
    const char c = value[i];
    if (c == '&') {
      escaped += F("&amp;");
    } else if (c == '<') {
      escaped += F("&lt;");
    } else if (c == '>') {
      escaped += F("&gt;");
    } else if (c == '"') {
      escaped += F("&quot;");
    } else {
      escaped += c;
    }
  }
  return escaped;
}

String wifiStatusText() {
  if (WiFi.status() == WL_CONNECTED) {
    return String("connected: ") + WiFi.localIP().toString();
  }
  if (wifiSsid.length() == 0) {
    return "not configured";
  }
  return "not connected";
}

String configPageHtml(const String &message = "") {
  String html = F("<!doctype html><html><head><meta charset='utf-8'>"
                  "<meta name='viewport' content='width=device-width,initial-scale=1'>"
                  "<title>HydroSense WiFi</title><style>"
                  "body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;margin:0;background:#f4f7f6;color:#17211d}"
                  "main{max-width:560px;margin:0 auto;padding:28px 16px}"
                  ".card{background:#fff;border:1px solid #d9e4df;border-radius:8px;padding:18px;box-shadow:0 1px 2px #0001}"
                  "h1{margin:0 0 14px;font-size:30px}.muted{color:#63736c}.msg{font-weight:700;color:#1d6f54}"
                  "label{display:block;font-weight:700;margin-top:14px}input{box-sizing:border-box;width:100%;padding:11px;border:1px solid #c6d5ce;border-radius:6px;font:inherit}"
                  "button,a.btn{display:inline-block;margin-top:16px;border:0;border-radius:6px;padding:11px 14px;background:#1d6f54;color:#fff;font:inherit;font-weight:700;text-decoration:none}"
                  "a.btn.secondary{background:#40534b;margin-left:8px}.kv{display:grid;grid-template-columns:130px 1fr;gap:8px;margin:14px 0}"
                  "</style></head><body><main><div class='card'><h1>HydroSense WiFi</h1>");

  if (message.length() > 0) {
    html += F("<p class='msg'>");
    html += htmlEscape(message);
    html += F("</p>");
  }

  html += F("<div class='kv'><div class='muted'>Status</div><div>");
  html += htmlEscape(wifiStatusText());
  html += F("</div><div class='muted'>Device</div><div>");
  html += DEVICE_ID;
  html += F("</div><div class='muted'>API</div><div>");
  html += apiBaseUrl.length() > 0 ? htmlEscape(apiBaseUrl) : String("not configured");
  html += F("</div><div class='muted'>API key</div><div>");
  html += apiKey.length() > 0 ? String("configured") : String("not configured");
  html += F("</div><div class='muted'>Hotspot</div><div>");
  html += configApActive ? htmlEscape(configApSsid) : String("off");
  html += F("</div></div><form method='post' action='/save'>"
            "<label for='ssid'>WiFi SSID</label><input id='ssid' name='ssid' value='");
  html += htmlEscape(wifiSsid);
  html += F("' autocomplete='off'><label for='password'>WiFi Password</label>"
            "<input id='password' name='password' type='password' value='' autocomplete='new-password'>"
            "<label for='api'>Server API URL</label><input id='api' name='api' value='");
  html += htmlEscape(apiBaseUrl);
  html += F("' autocomplete='off' placeholder='http://server-ip:8077/index.php'>"
            "<label for='key'>Server API Key</label><input id='key' name='key' value='");
  html += htmlEscape(apiKey);
  html += F("' autocomplete='off'>"
            "<button type='submit'>Save and connect</button></form>"
            "<a class='btn secondary' href='/'>Refresh</a><a class='btn secondary' href='/clear'>Clear WiFi</a>"
            "<p class='muted'>If WiFi is not connected, join the HydroSense hotspot and open http://192.168.4.1/.</p>"
            "</div></main></body></html>");
  return html;
}

void loadConfig() {
  preferences.begin("hydrosense", false);
  wifiSsid = preferences.isKey("wifi_ssid") ? preferences.getString("wifi_ssid", "") : "";
  wifiPassword = preferences.isKey("wifi_pass") ? preferences.getString("wifi_pass", "") : "";
  apiBaseUrl = preferences.isKey("api_url") ? preferences.getString("api_url", DEFAULT_API_BASE_URL) : DEFAULT_API_BASE_URL;
  apiKey = preferences.isKey("api_key") ? preferences.getString("api_key", DEFAULT_API_KEY) : DEFAULT_API_KEY;
}

void saveConfig(const String &ssid, const String &password, const String &apiUrl, const String &key) {
  wifiSsid = ssid;
  wifiPassword = password;
  apiBaseUrl = apiUrl;
  apiKey = key;
  preferences.putString("wifi_ssid", wifiSsid);
  preferences.putString("wifi_pass", wifiPassword);
  preferences.putString("api_url", apiBaseUrl);
  preferences.putString("api_key", apiKey);
}

void clearConfig() {
  wifiSsid = "";
  wifiPassword = "";
  apiBaseUrl = DEFAULT_API_BASE_URL;
  apiKey = DEFAULT_API_KEY;
  preferences.remove("wifi_ssid");
  preferences.remove("wifi_pass");
  preferences.remove("api_url");
  preferences.remove("api_key");
}

void setupConfigServer() {
  configServer.on("/", HTTP_GET, []() {
    configServer.send(200, "text/html", configPageHtml());
  });

  configServer.on("/save", HTTP_POST, []() {
    const String ssid = configServer.arg("ssid");
    const String password = configServer.arg("password");
    const String apiUrl = configServer.arg("api");
    const String key = configServer.arg("key");
    if (ssid.length() == 0) {
      configServer.send(400, "text/html", configPageHtml("SSID is required."));
      return;
    }

    saveConfig(ssid, password, apiUrl, key);
    WiFi.disconnect(false, false);
    lastWifiAttemptMs = 0;
    connectWifi();
    configServer.send(200, "text/html", configPageHtml("Settings saved. Connecting..."));
  });

  configServer.on("/clear", HTTP_GET, []() {
    clearConfig();
    WiFi.disconnect(false, false);
    startConfigAp();
    configServer.send(200, "text/html", configPageHtml("Settings cleared."));
  });

  configServer.onNotFound([]() {
    configServer.send(404, "text/plain", "Not found");
  });

  configServer.begin();
}

void startConfigAp() {
  if (configApSsid.length() == 0) {
    uint64_t mac = ESP.getEfuseMac();
    char suffix[7] = {};
    snprintf(suffix, sizeof(suffix), "%06X", static_cast<unsigned int>(mac & 0xFFFFFF));
    configApSsid = String("HydroSense-") + suffix;
  }

  WiFi.mode(WIFI_AP_STA);
  const bool wasActive = configApActive;
  configApActive = WiFi.softAP(configApSsid.c_str(), CONFIG_AP_PASSWORD);

  if (!configApActive) {
    Serial.println(F("Config hotspot failed to start"));
    return;
  }

  if (wasActive) {
    return;
  }

  Serial.print(F("Config hotspot started: "));
  Serial.print(configApSsid);
  Serial.print(F(" password="));
  Serial.print(CONFIG_AP_PASSWORD);
  Serial.print(F(" ip="));
  Serial.println(WiFi.softAPIP());
}

void stopConfigApIfConnected() {
  if (!configApActive || WiFi.status() != WL_CONNECTED) {
    return;
  }

  WiFi.softAPdisconnect(true);
  configApActive = false;
  WiFi.mode(WIFI_STA);
  Serial.println(F("Config hotspot stopped"));
}

bool httpPostJson(const String &url, const String &body, String *response = nullptr) {
  WiFiClient client;
  HTTPClient http;
  if (!http.begin(client, url)) {
    return false;
  }

  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-Api-Key", apiKey);
  const int status = http.POST(body);
  if (response != nullptr) {
    *response = http.getString();
  }
  http.end();
  return status >= 200 && status < 300;
}

String extractJsonString(const String &json, const char *key) {
  String pattern = "\"";
  pattern += key;
  pattern += "\":\"";
  const int start = json.indexOf(pattern);
  if (start < 0) {
    return "";
  }
  const int valueStart = start + pattern.length();
  const int valueEnd = json.indexOf('"', valueStart);
  if (valueEnd < 0) {
    return "";
  }
  return json.substring(valueStart, valueEnd);
}

uint32_t extractJsonUint(const String &json, const char *key) {
  String pattern = "\"";
  pattern += key;
  pattern += "\":";
  const int start = json.indexOf(pattern);
  if (start < 0) {
    return 0;
  }
  const int valueStart = start + pattern.length();
  return static_cast<uint32_t>(json.substring(valueStart).toInt());
}

int extractJsonInt(const String &json, const char *key, int fallback) {
  String pattern = "\"";
  pattern += key;
  pattern += "\":";
  const int start = json.indexOf(pattern);
  if (start < 0) {
    return fallback;
  }
  const int valueStart = start + pattern.length();
  return json.substring(valueStart).toInt();
}

void connectWifi() {
  if (WiFi.status() == WL_CONNECTED) {
    stopConfigApIfConnected();
    return;
  }

  startConfigAp();

  if (wifiSsid.length() == 0) {
    return;
  }

  const unsigned long now = millis();
  if (lastWifiAttemptMs != 0 && now - lastWifiAttemptMs < WIFI_RECONNECT_INTERVAL_MS) {
    return;
  }
  lastWifiAttemptMs = now;

  WiFi.mode(WIFI_AP_STA);
  WiFi.begin(wifiSsid.c_str(), wifiPassword.c_str());
  Serial.print(F("WiFi connecting"));

  const unsigned long startMs = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - startMs < 15000) {
    delay(300);
    Serial.print(F("."));
  }
  Serial.println();

  if (WiFi.status() == WL_CONNECTED) {
    Serial.print(F("WiFi connected: "));
    Serial.println(WiFi.localIP());
    stopConfigApIfConnected();
  } else {
    Serial.println(F("WiFi connection failed"));
  }
}

void postTelemetry() {
  connectWifi();
  if (WiFi.status() != WL_CONNECTED || apiBaseUrl.length() == 0) {
    return;
  }

  const String url = apiBaseUrl + "?api=telemetry";
  if (!httpPostJson(url, buildTelemetryJson())) {
    Serial.println(F("Telemetry post failed"));
  }
}

void acknowledgeCommand(uint32_t commandId) {
  String body = "{\"device_id\":\"";
  body += jsonEscape(DEVICE_ID);
  body += "\",\"command_id\":";
  body += commandId;
  body += ",\"channels\":[";
  for (uint8_t channel = 0; channel < CHANNEL_COUNT; ++channel) {
    if (channel > 0) {
      body += ",";
    }
    body += "{\"index\":";
    body += channel;
    body += ",\"pump_on\":";
    body += channels[channel].pumpOn ? "true" : "false";
    body += ",\"pump_mode\":\"";
    body += pumpModeValue(channels[channel].mode);
    body += "\"}";
  }
  body += "]}";
  if (apiBaseUrl.length() > 0) {
    httpPostJson(apiBaseUrl + "?api=ack", body);
  }
}

void pollCommand() {
  connectWifi();
  if (WiFi.status() != WL_CONNECTED || apiBaseUrl.length() == 0) {
    return;
  }

  WiFiClient client;
  HTTPClient http;
  String url = apiBaseUrl + "?api=command&device_id=" + DEVICE_ID;
  if (!http.begin(client, url)) {
    return;
  }
  http.addHeader("X-Api-Key", apiKey);
  const int status = http.GET();
  const String response = http.getString();
  http.end();

  if (status < 200 || status >= 300) {
    Serial.println(F("Command poll failed"));
    return;
  }

  const uint32_t commandId = extractJsonUint(response, "command_id");
  const String command = extractJsonString(response, "pump");
  const int channel = extractJsonInt(response, "channel", -1);
  if (commandId == 0 || commandId == lastCommandId || command.length() == 0) {
    return;
  }

  lastCommandId = commandId;
  if (channel >= 0 && channel < CHANNEL_COUNT) {
    applyPumpCommand(static_cast<uint8_t>(channel), command);
  }
  acknowledgeCommand(commandId);
}

void setup() {
  Serial.begin(115200);
  delay(500);

  analogReadResolution(12);
  for (uint8_t channel = 0; channel < CHANNEL_COUNT; ++channel) {
    analogSetPinAttenuation(SOIL_SENSOR_PINS[channel], ADC_11db);
    pinMode(SOIL_SENSOR_PINS[channel], INPUT);
    relayWrite(channel, false);
    pinMode(RELAY_PINS[channel], OUTPUT);
    relayWrite(channel, false);
  }
  if (BATTERY_MONITOR_ENABLED) {
    analogSetPinAttenuation(BATTERY_SENSOR_PIN, ADC_11db);
    pinMode(BATTERY_SENSOR_PIN, INPUT);
  }
  initPumpsOff();

  loadConfig();
  connectWifi();
  setupConfigServer();

  readAllSensors();
  printStatus();

  Serial.println(F("HydroSense ESP32 multi-channel controller started"));
  Serial.println(F("Configure SOIL_SENSOR_PINS and RELAY_PINS for your board before upload."));
}

void loop() {
  const unsigned long now = millis();
  configServer.handleClient();

  if (WiFi.status() != WL_CONNECTED) {
    startConfigAp();
    if (now - lastWifiAttemptMs >= WIFI_RECONNECT_INTERVAL_MS) {
      connectWifi();
    }
  } else if (WiFi.status() == WL_CONNECTED) {
    stopConfigApIfConnected();
  }

  updatePumpAutomation(now);

  if (now - lastSensorReadMs >= SENSOR_INTERVAL_MS) {
    lastSensorReadMs = now;
    readAllSensors();
    updatePumpAutomation(now);
    printStatus();
  }

  if (now - lastServerPostMs >= SERVER_INTERVAL_MS) {
    lastServerPostMs = now;
    postTelemetry();
  }

  if (now - lastCommandPollMs >= COMMAND_INTERVAL_MS) {
    lastCommandPollMs = now;
    pollCommand();
  }
}
