#include <Arduino.h>
#include <HTTPClient.h>
#include <SPI.h>
#include <WiFi.h>

#include <Fonts/FreeMonoBold12pt7b.h>
#include <Fonts/FreeMonoBold18pt7b.h>
#include <Fonts/FreeMonoBold9pt7b.h>

#define USE_THREE_COLOR_EPAPER 1

#if USE_THREE_COLOR_EPAPER
#include <GxEPD2_3C.h>
#else
#include <GxEPD2_BW.h>
#endif

// ESP32-C3 SuperMini wiring.
// ePaper 4.2" WeAct Studio, SPI:
constexpr uint8_t EPD_BUSY = 8;
constexpr uint8_t EPD_RST = 3;
constexpr uint8_t EPD_SCK = 4;
constexpr uint8_t EPD_DC = 5;
constexpr uint8_t EPD_MOSI = 6;
constexpr uint8_t EPD_MISO = 20;
constexpr uint8_t EPD_CS = 7;

// Grove - Moisture Sensor V1.4:
// Red -> 3V3, Black -> GND, White -> not connected, Yellow -> GPIO1 / ADC1-1.
constexpr uint8_t SOIL_SENSOR_PIN = 1;

// Battery monitor for a 1S LiPo/Li-Ion through a voltage divider:
// Battery+ -> 100k -> GPIO0 -> 100k -> GND. Battery- must share ESP32 GND.
constexpr uint8_t BATTERY_SENSOR_PIN = 0;
constexpr bool BATTERY_MONITOR_ENABLED = true;
constexpr uint32_t BATTERY_DIVIDER_TOP_OHMS = 100000;
constexpr uint32_t BATTERY_DIVIDER_BOTTOM_OHMS = 100000;
constexpr uint16_t BATTERY_EMPTY_MV = 3300;
constexpr uint16_t BATTERY_FULL_MV = 4200;

// Pump MOSFET gate. GPIO21 is free when USB CDC is used for Serial Monitor.
constexpr uint8_t RELAY_PIN = 21;

// MOSFET pump control is active HIGH: GPIO HIGH -> pump ON, GPIO LOW -> pump OFF.
constexpr bool RELAY_ACTIVE_LOW = false;

// Your measured 10-bit-style Grove readings. Recalibrate after moving to ESP32-C3.
constexpr int DRY_RAW = 0;
constexpr int WET_RAW = 626;

constexpr int START_WATERING_PERCENT = 35;
constexpr int STOP_WATERING_PERCENT = 55;

constexpr unsigned long SENSOR_INTERVAL_MS = 2000;
constexpr unsigned long HISTORY_SAMPLE_INTERVAL_MS = 60000;
constexpr unsigned long DISPLAY_INTERVAL_MS = 600000;
constexpr unsigned long SERVER_INTERVAL_MS = 30000;
constexpr unsigned long COMMAND_INTERVAL_MS = 10000;
constexpr unsigned long MAX_PUMP_RUN_MS = 15000;
constexpr unsigned long PUMP_COOLDOWN_MS = 30000;
constexpr int DISPLAY_MOISTURE_DELTA_PERCENT = 8;
constexpr int DISPLAY_BATTERY_DELTA_PERCENT = 5;
constexpr size_t HISTORY_SIZE = 64;
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

#if USE_THREE_COLOR_EPAPER
GxEPD2_3C<GxEPD2_420c_GDEY042Z98, GxEPD2_420c_GDEY042Z98::HEIGHT> display(
    GxEPD2_420c_GDEY042Z98(EPD_CS, EPD_DC, EPD_RST, EPD_BUSY));
#else
GxEPD2_BW<GxEPD2_420, GxEPD2_420::HEIGHT> display(
    GxEPD2_420(EPD_CS, EPD_DC, EPD_RST, EPD_BUSY));
#endif

bool pumpOn = false;
unsigned long lastSensorReadMs = 0;
unsigned long lastHistorySampleMs = 0;
unsigned long lastDisplayUpdateMs = 0;
unsigned long lastServerPostMs = 0;
unsigned long lastCommandPollMs = 0;
unsigned long pumpStartedMs = 0;
unsigned long pumpStoppedMs = 0;
int lastRaw = 0;
int lastMoisturePercent = 0;
int lastSoilRaw12 = 0;
int lastBatteryAdcRaw = 0;
uint16_t lastBatteryPinMilliVolts = 0;
int displayedMoisturePercent = -1;
int displayedBatteryPercent = -1;
bool displayedPumpOn = false;
bool displayedNeedsWater = false;
uint16_t batteryMilliVolts = 0;
int batteryPercent = 0;
int moistureHistory[HISTORY_SIZE] = {};
size_t historyNext = 0;
size_t historyCount = 0;
PumpMode pumpMode = PumpMode::Auto;
uint32_t lastCommandId = 0;

void relayWrite(bool enabled) {
  const bool relayLevel = RELAY_ACTIVE_LOW ? !enabled : enabled;
  digitalWrite(RELAY_PIN, relayLevel ? HIGH : LOW);
}

void setPump(bool enabled) {
  pumpOn = enabled;
  relayWrite(enabled);

  if (enabled) {
    pumpStartedMs = millis();
  } else {
    pumpStoppedMs = millis();
  }
}

const __FlashStringHelper *pumpModeLabel() {
  switch (pumpMode) {
    case PumpMode::ManualOn:
      return F("manual_on");
    case PumpMode::ManualOff:
      return F("manual_off");
    case PumpMode::Auto:
    default:
      return F("auto");
  }
}

const char *pumpModeValue() {
  switch (pumpMode) {
    case PumpMode::ManualOn:
      return "manual_on";
    case PumpMode::ManualOff:
      return "manual_off";
    case PumpMode::Auto:
    default:
      return "auto";
  }
}

const __FlashStringHelper *pumpModeShortLabel() {
  switch (pumpMode) {
    case PumpMode::ManualOn:
      return F("M+");
    case PumpMode::ManualOff:
      return F("M-");
    case PumpMode::Auto:
    default:
      return F("A");
  }
}

void applyPumpCommand(const String &command) {
  if (command == "on") {
    pumpMode = PumpMode::ManualOn;
    setPump(true);
    Serial.println(F("Pump command: manual ON"));
  } else if (command == "off") {
    pumpMode = PumpMode::ManualOff;
    setPump(false);
    Serial.println(F("Pump command: manual OFF"));
  } else if (command == "auto") {
    pumpMode = PumpMode::Auto;
    Serial.println(F("Pump command: AUTO"));
  }
}

bool needsWaterNow() {
  return lastMoisturePercent <= START_WATERING_PERCENT;
}

void initPumpOff() {
  pumpOn = false;
  relayWrite(false);
  pumpStoppedMs = millis() - PUMP_COOLDOWN_MS;
}

int readSoilRaw() {
  constexpr uint8_t samples = 12;
  uint32_t sum = 0;

  for (uint8_t i = 0; i < ADC_DUMMY_READS; ++i) {
    analogRead(SOIL_SENSOR_PIN);
    delay(3);
  }

  for (uint8_t i = 0; i < samples; ++i) {
    const int raw12 = analogRead(SOIL_SENSOR_PIN);
    lastSoilRaw12 = raw12;
    sum += raw12 / 4;
    delay(5);
  }

  return static_cast<int>(sum / samples);
}

uint16_t readBatteryMilliVolts() {
  if (!BATTERY_MONITOR_ENABLED) {
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
  if (milliVolts <= BATTERY_EMPTY_MV) {
    return 0;
  }
  if (milliVolts >= BATTERY_FULL_MV) {
    return 100;
  }
  return static_cast<int>((milliVolts - BATTERY_EMPTY_MV) * 100UL /
                          (BATTERY_FULL_MV - BATTERY_EMPTY_MV));
}

int rawToMoisturePercent(int raw) {
  if (WET_RAW == DRY_RAW) {
    return 0;
  }

  const int percent = (raw - DRY_RAW) * 100 / (WET_RAW - DRY_RAW);
  return constrain(percent, 0, 100);
}

void addHistoryPoint(int moisturePercent) {
  moistureHistory[historyNext] = moisturePercent;
  historyNext = (historyNext + 1) % HISTORY_SIZE;
  if (historyCount < HISTORY_SIZE) {
    historyCount++;
  }
}

bool shouldRefreshDisplay(unsigned long now) {
  if (displayedMoisturePercent < 0) {
    return true;
  }
  if (now - lastDisplayUpdateMs >= DISPLAY_INTERVAL_MS) {
    return true;
  }
  if (displayedPumpOn != pumpOn) {
    return true;
  }
  if (displayedNeedsWater != needsWaterNow()) {
    return true;
  }
  if (abs(displayedMoisturePercent - lastMoisturePercent) >= DISPLAY_MOISTURE_DELTA_PERCENT) {
    return true;
  }
  if (abs(displayedBatteryPercent - batteryPercent) >= DISPLAY_BATTERY_DELTA_PERCENT) {
    return true;
  }
  return false;
}

void printStatus() {
  Serial.print(F("raw="));
  Serial.print(lastRaw);
  Serial.print(F(" soil_raw12="));
  Serial.print(lastSoilRaw12);
  Serial.print(F(" moisture="));
  Serial.print(lastMoisturePercent);
  Serial.print(F("% pump="));
  Serial.print(pumpOn ? F("ON") : F("OFF"));
  Serial.print(F(" mode="));
  Serial.print(pumpModeLabel());
  Serial.print(F(" battery="));
  Serial.print(batteryMilliVolts);
  Serial.print(F("mV "));
  Serial.print(batteryPercent);
  Serial.print(F("% batt_adc="));
  Serial.print(lastBatteryAdcRaw);
  Serial.print(F(" batt_pin="));
  Serial.print(lastBatteryPinMilliVolts);
  Serial.print(F("mV"));
  Serial.println();
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
  json += ",\"moisture_percent\":";
  json += lastMoisturePercent;
  json += ",\"soil_raw\":";
  json += lastRaw;
  json += ",\"soil_raw12\":";
  json += lastSoilRaw12;
  json += ",\"battery_mv\":";
  json += batteryMilliVolts;
  json += ",\"battery_percent\":";
  json += batteryPercent;
  json += ",\"battery_adc_raw\":";
  json += lastBatteryAdcRaw;
  json += ",\"pump_on\":";
  json += pumpOn ? "true" : "false";
  json += ",\"pump_mode\":\"";
  json += pumpModeValue();
  json += "\",\"needs_water\":";
  json += needsWaterNow() ? "true" : "false";
  json += "}";
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
  body += ",\"pump_on\":";
  body += pumpOn ? "true" : "false";
  body += ",\"pump_mode\":\"";
  body += pumpModeValue();
  body += "\"}";
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
  if (commandId == 0 || commandId == lastCommandId || command.length() == 0) {
    return;
  }

  lastCommandId = commandId;
  applyPumpCommand(command);
  acknowledgeCommand(commandId);
}

void drawBox(int16_t x, int16_t y, int16_t w, int16_t h) {
  display.drawRect(x, y, w, h, GxEPD_BLACK);
}

void drawBatteryIcon(int16_t x, int16_t y, int16_t percent, uint16_t milliVolts) {
  display.drawRect(x, y, 34, 16, GxEPD_BLACK);
  display.fillRect(x + 34, y + 4, 4, 8, GxEPD_BLACK);

  const int fillWidth = constrain(percent, 0, 100) * 30 / 100;
  const uint16_t fillColor = percent <= 20 ? GxEPD_RED : GxEPD_BLACK;
  display.fillRect(x + 2, y + 2, fillWidth, 12, fillColor);

  display.setFont(&FreeMonoBold9pt7b);
  display.setTextColor(GxEPD_BLACK);
  display.setCursor(x + 48, y + 14);
  display.print(percent);
  display.print(F("%"));
  display.setCursor(x, y + 34);
  display.print(milliVolts / 1000.0f, 2);
  display.print(F("V"));
}

void drawMoistureGauge(int16_t x, int16_t y, int16_t w, int16_t h) {
  const bool needsWater = lastMoisturePercent <= START_WATERING_PERCENT;
  const uint16_t valueColor = needsWater ? GxEPD_RED : GxEPD_BLACK;
  const int barHeight = constrain(lastMoisturePercent, 0, 100) * (h - 52) / 100;

  drawBox(x, y, w, h);
  display.setTextColor(GxEPD_BLACK);
  display.setFont(&FreeMonoBold9pt7b);
  display.setCursor(x + 8, y + 20);
  display.print(F("Moisture"));

  display.drawRect(x + w - 28, y + 32, 12, h - 52, GxEPD_BLACK);
  display.fillRect(x + w - 26, y + 32 + (h - 52 - barHeight), 8, barHeight, valueColor);

  display.setTextColor(valueColor);
  display.setFont(&FreeMonoBold18pt7b);
  display.setCursor(x + 12, y + 78);
  display.print(lastMoisturePercent);
  display.print(F("%"));

  display.setTextColor(GxEPD_BLACK);
  display.setFont(&FreeMonoBold9pt7b);
  display.setCursor(x + 12, y + 112);
  display.print(needsWater ? F("WATER") : F("OK"));
  display.setCursor(x + 12, y + 138);
  display.print(F("raw "));
  display.print(lastRaw);
}

void drawHistoryGraph(int16_t x, int16_t y, int16_t w, int16_t h) {
  drawBox(x, y, w, h);
  display.setTextColor(GxEPD_BLACK);
  display.setFont(&FreeMonoBold9pt7b);
  display.setCursor(x + 8, y + 20);
  display.print(F("History"));

  const int16_t gx = x + 12;
  const int16_t gy = y + 34;
  const int16_t gw = w - 24;
  const int16_t gh = h - 48;
  display.drawRect(gx, gy, gw, gh, GxEPD_BLACK);

  for (int p = 25; p <= 75; p += 25) {
    const int16_t py = gy + gh - (p * gh / 100);
    for (int16_t px = gx + 1; px < gx + gw - 1; px += 6) {
      display.drawPixel(px, py, GxEPD_BLACK);
    }
  }

  if (historyCount < 2) {
    display.setCursor(gx + 24, gy + gh / 2);
    display.print(F("collecting..."));
    return;
  }

  const size_t first = historyCount == HISTORY_SIZE ? historyNext : 0;
  int16_t prevX = gx;
  int16_t prevY = gy + gh - (moistureHistory[first] * gh / 100);

  for (size_t i = 1; i < historyCount; ++i) {
    const size_t index = (first + i) % HISTORY_SIZE;
    const int value = constrain(moistureHistory[index], 0, 100);
    const int16_t px = gx + (i * (gw - 1)) / (historyCount - 1);
    const int16_t py = gy + gh - (value * gh / 100);
    const uint16_t lineColor = value <= START_WATERING_PERCENT ? GxEPD_RED : GxEPD_BLACK;
    display.drawLine(prevX, prevY, px, py, lineColor);
    display.fillCircle(px, py, 2, lineColor);
    prevX = px;
    prevY = py;
  }
}

void drawPumpPanel(int16_t x, int16_t y, int16_t w, int16_t h) {
  drawBox(x, y, w, h);
  display.setTextColor(GxEPD_BLACK);
  display.setFont(&FreeMonoBold9pt7b);
  display.setCursor(x + 8, y + 20);
  display.print(F("Pump"));

  display.setFont(&FreeMonoBold18pt7b);
  display.setCursor(x + 14, y + 62);
  display.setTextColor(pumpOn ? GxEPD_RED : GxEPD_BLACK);
  display.print(pumpOn ? F("ON") : F("OFF"));

  display.setFont(&FreeMonoBold9pt7b);
  display.setTextColor(GxEPD_BLACK);
  display.setCursor(x + 82, y + 62);
  display.print(pumpModeShortLabel());
}

void drawStatus() {
  display.setRotation(0);
  display.setFullWindow();
  display.firstPage();

  do {
    display.fillScreen(GxEPD_WHITE);
    display.setTextColor(GxEPD_BLACK);

    display.setFont(&FreeMonoBold12pt7b);
    display.setCursor(10, 24);
    display.print(F("HydroSense"));

    drawBatteryIcon(286, 6, batteryPercent, batteryMilliVolts);
    drawMoistureGauge(8, 42, 116, 176);
    drawHistoryGraph(132, 42, 260, 176);
    drawPumpPanel(8, 226, 116, 66);

    display.setFont(&FreeMonoBold9pt7b);
    display.setTextColor(lastMoisturePercent <= START_WATERING_PERCENT ? GxEPD_RED
                                                                        : GxEPD_BLACK);
    display.setCursor(140, 252);
    display.print(lastMoisturePercent <= START_WATERING_PERCENT ? F("Water needed")
                                                                : F("Soil moisture OK"));
    display.setTextColor(GxEPD_BLACK);
    display.setCursor(140, 280);
    display.print(F("Start "));
    display.print(START_WATERING_PERCENT);
    display.print(F("% Stop "));
    display.print(STOP_WATERING_PERCENT);
    display.print(F("%"));
  } while (display.nextPage());

  displayedMoisturePercent = lastMoisturePercent;
  displayedBatteryPercent = batteryPercent;
  displayedPumpOn = pumpOn;
  displayedNeedsWater = needsWaterNow();
  lastDisplayUpdateMs = millis();
}

void setupDisplay() {
  SPI.begin(EPD_SCK, EPD_MISO, EPD_MOSI, EPD_CS);
  display.init(115200);
  drawStatus();
}

void setup() {
  Serial.begin(115200);
  delay(500);

  analogReadResolution(12);
  analogSetPinAttenuation(SOIL_SENSOR_PIN, ADC_11db);
  analogSetPinAttenuation(BATTERY_SENSOR_PIN, ADC_11db);
  pinMode(SOIL_SENSOR_PIN, INPUT);
  pinMode(BATTERY_SENSOR_PIN, INPUT);
  pinMode(RELAY_PIN, OUTPUT);
  initPumpOff();

  lastRaw = readSoilRaw();
  lastMoisturePercent = rawToMoisturePercent(lastRaw);
  batteryMilliVolts = readBatteryMilliVolts();
  batteryPercent = batteryMilliVoltsToPercent(batteryMilliVolts);
  addHistoryPoint(lastMoisturePercent);
  lastHistorySampleMs = millis();

  setupDisplay();
  connectWifi();

  Serial.println(F("HydroSense ESP32-C3 SuperMini started"));
  Serial.println(F("Grove sensor: red=3V3 black=GND white=NC yellow=GPIO1/ADC1-1"));
  Serial.println(F("Calibrate DRY_RAW and WET_RAW after checking raw values."));
}

void loop() {
  const unsigned long now = millis();

  if (pumpOn && now - pumpStartedMs >= MAX_PUMP_RUN_MS) {
    if (pumpMode == PumpMode::ManualOn) {
      pumpMode = PumpMode::ManualOff;
    }
    setPump(false);
    Serial.println(F("Pump stopped: max runtime reached"));
  }

  if (now - lastSensorReadMs >= SENSOR_INTERVAL_MS) {
    lastSensorReadMs = now;
    lastRaw = readSoilRaw();
    lastMoisturePercent = rawToMoisturePercent(lastRaw);
    batteryMilliVolts = readBatteryMilliVolts();
    batteryPercent = batteryMilliVoltsToPercent(batteryMilliVolts);
    if (now - lastHistorySampleMs >= HISTORY_SAMPLE_INTERVAL_MS) {
      lastHistorySampleMs = now;
      addHistoryPoint(lastMoisturePercent);
    }
    printStatus();

    if (pumpMode == PumpMode::Auto && !pumpOn) {
      const bool cooldownDone = now - pumpStoppedMs >= PUMP_COOLDOWN_MS;
      if (cooldownDone && lastMoisturePercent <= START_WATERING_PERCENT) {
        setPump(true);
        Serial.println(F("Pump started: soil is dry"));
      }
    } else if (pumpMode == PumpMode::Auto && lastMoisturePercent >= STOP_WATERING_PERCENT) {
      setPump(false);
      Serial.println(F("Pump stopped: target moisture reached"));
    }
  }

  if (now - lastServerPostMs >= SERVER_INTERVAL_MS) {
    lastServerPostMs = now;
    postTelemetry();
  }

  if (now - lastCommandPollMs >= COMMAND_INTERVAL_MS) {
    lastCommandPollMs = now;
    pollCommand();
  }

  if (shouldRefreshDisplay(now)) {
    drawStatus();
  }
}
