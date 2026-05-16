#include <Arduino.h>
#include <HTTPClient.h>
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

// Fill these in before uploading if you want server sync.
constexpr bool WIFI_ENABLED = false;
constexpr char WIFI_SSID[] = "YOUR_WIFI_SSID";
constexpr char WIFI_PASSWORD[] = "YOUR_WIFI_PASSWORD";
constexpr char API_BASE_URL[] = "http://192.168.1.10/hydrosense/server/index.php";
constexpr char API_KEY[] = "change-me";
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

bool httpPostJson(const String &url, const String &body, String *response = nullptr) {
  WiFiClient client;
  HTTPClient http;
  if (!http.begin(client, url)) {
    return false;
  }

  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-Api-Key", API_KEY);
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
  if (!WIFI_ENABLED || WiFi.status() == WL_CONNECTED) {
    return;
  }

  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
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
  } else {
    Serial.println(F("WiFi connection failed"));
  }
}

void postTelemetry() {
  if (!WIFI_ENABLED) {
    return;
  }
  connectWifi();
  if (WiFi.status() != WL_CONNECTED) {
    return;
  }

  const String url = String(API_BASE_URL) + "?api=telemetry";
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
  httpPostJson(String(API_BASE_URL) + "?api=ack", body);
}

void pollCommand() {
  if (!WIFI_ENABLED) {
    return;
  }
  connectWifi();
  if (WiFi.status() != WL_CONNECTED) {
    return;
  }

  WiFiClient client;
  HTTPClient http;
  String url = String(API_BASE_URL) + "?api=command&device_id=" + DEVICE_ID;
  if (!http.begin(client, url)) {
    return;
  }
  http.addHeader("X-Api-Key", API_KEY);
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

  readAllSensors();
  printStatus();
  connectWifi();

  Serial.println(F("HydroSense ESP32 multi-channel controller started"));
  Serial.println(F("Configure SOIL_SENSOR_PINS and RELAY_PINS for your board before upload."));
}

void loop() {
  const unsigned long now = millis();

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
