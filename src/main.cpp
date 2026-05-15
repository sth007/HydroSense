#include <Arduino.h>

// ESP32-C3 SuperMini pin choices:
// - Soil moisture sensor analog output -> GPIO2 / A0
// - Relay module IN -> GPIO7 / D5
constexpr uint8_t SOIL_SENSOR_PIN = 2;
constexpr uint8_t RELAY_PIN = 7;

// Most common relay modules are active LOW. Set to false if your module turns
// on when IN is HIGH.
constexpr bool RELAY_ACTIVE_LOW = true;

// Calibrate these with Serial Monitor:
// dryRaw: reading when the sensor is in dry air/dry soil.
// wetRaw: reading when the sensor is in water/very wet soil.
constexpr int DRY_RAW = 3000;
constexpr int WET_RAW = 1200;

// Pump starts below START_WATERING_PERCENT and stops above STOP_WATERING_PERCENT.
// The gap prevents rapid relay clicking around one threshold.
constexpr int START_WATERING_PERCENT = 35;
constexpr int STOP_WATERING_PERCENT = 55;

constexpr unsigned long SENSOR_INTERVAL_MS = 2000;
constexpr unsigned long MAX_PUMP_RUN_MS = 15000;
constexpr unsigned long PUMP_COOLDOWN_MS = 30000;

bool pumpOn = false;
unsigned long lastSensorReadMs = 0;
unsigned long pumpStartedMs = 0;
unsigned long pumpStoppedMs = 0;

void setPump(bool enabled) {
  pumpOn = enabled;
  const bool relayLevel = RELAY_ACTIVE_LOW ? !enabled : enabled;
  digitalWrite(RELAY_PIN, relayLevel ? HIGH : LOW);

  if (enabled) {
    pumpStartedMs = millis();
  } else {
    pumpStoppedMs = millis();
  }
}

int readSoilRaw() {
  constexpr uint8_t samples = 12;
  uint32_t sum = 0;

  for (uint8_t i = 0; i < samples; ++i) {
    sum += analogRead(SOIL_SENSOR_PIN);
    delay(5);
  }

  return static_cast<int>(sum / samples);
}

int rawToMoisturePercent(int raw) {
  const int percent = map(raw, DRY_RAW, WET_RAW, 0, 100);
  return constrain(percent, 0, 100);
}

void printStatus(int raw, int moisturePercent) {
  Serial.print(F("raw="));
  Serial.print(raw);
  Serial.print(F(" moisture="));
  Serial.print(moisturePercent);
  Serial.print(F("% pump="));
  Serial.println(pumpOn ? F("ON") : F("OFF"));
}

void setup() {
  Serial.begin(115200);
  delay(500);

  analogReadResolution(12);
  pinMode(SOIL_SENSOR_PIN, INPUT);
  pinMode(RELAY_PIN, OUTPUT);
  setPump(false);

  Serial.println(F("HydroSense ESP32-C3 started"));
  Serial.println(F("Calibrate DRY_RAW and WET_RAW after checking raw values."));
}

void loop() {
  const unsigned long now = millis();

  if (pumpOn && now - pumpStartedMs >= MAX_PUMP_RUN_MS) {
    setPump(false);
    Serial.println(F("Pump stopped: max runtime reached"));
  }

  if (now - lastSensorReadMs < SENSOR_INTERVAL_MS) {
    return;
  }
  lastSensorReadMs = now;

  const int raw = readSoilRaw();
  const int moisturePercent = rawToMoisturePercent(raw);
  printStatus(raw, moisturePercent);

  if (!pumpOn) {
    const bool cooldownDone = now - pumpStoppedMs >= PUMP_COOLDOWN_MS;
    if (cooldownDone && moisturePercent <= START_WATERING_PERCENT) {
      setPump(true);
      Serial.println(F("Pump started: soil is dry"));
    }
    return;
  }

  if (moisturePercent >= STOP_WATERING_PERCENT) {
    setPump(false);
    Serial.println(F("Pump stopped: target moisture reached"));
  }
}
