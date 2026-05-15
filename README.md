# HydroSense ESP32-C3 SuperMini

PlatformIO/Arduino project for an ESP32-C3 SuperMini that reads a capacitive soil moisture sensor and controls a 3-5 V mini water pump through a 1-channel 5 V relay module.

## Wiring

### Soil moisture sensor

Use a capacitive analog soil moisture sensor.

| Sensor | ESP32-C3 SuperMini |
| --- | --- |
| VCC | 3V3 |
| GND | GND |
| AOUT / AO | GPIO2 / A0 |

Do not power the sensor with 5 V if its analog output can exceed 3.3 V. ESP32-C3 ADC pins are not 5 V tolerant.

### Relay module

| Relay module | ESP32-C3 SuperMini / power |
| --- | --- |
| VCC | 5V |
| GND | GND |
| IN | GPIO7 / D5 |

The ESP32-C3 GND, relay GND, and pump power supply negative must be connected together.

### Pump through relay contact

Wire the pump power through the relay contacts, not through an ESP32 pin.

```text
Pump supply +  -> relay COM
relay NO       -> pump +
pump -         -> pump supply -
```

Use `NO` so the pump is off when the relay is not energized. The pump is rated DC 3-5 V, so use a suitable external supply or battery for the pump current. Do not power the pump from an ESP32 GPIO.

## Pin Choices

The default code uses:

| Function | ESP32-C3 pin |
| --- | --- |
| Soil sensor ADC | GPIO2 / A0 |
| Relay IN | GPIO7 / D5 |

If your relay behaves inverted, change `RELAY_ACTIVE_LOW` in `src/main.cpp`.

## Calibration

1. Upload the firmware.
2. Open the serial monitor at `115200`.
3. Read the `raw=` value with the sensor in dry air or dry soil and put it into `DRY_RAW`.
4. Read the `raw=` value with the sensor in water or very wet soil and put it into `WET_RAW`.
5. Upload again.

Defaults:

```cpp
constexpr int DRY_RAW = 3000;
constexpr int WET_RAW = 1200;
```

Many capacitive sensors return a higher value when dry and a lower value when wet. If yours behaves the opposite way, swap the two calibration values.

## Watering Logic

The pump starts when moisture is at or below `START_WATERING_PERCENT` and stops when moisture reaches `STOP_WATERING_PERCENT`.

```cpp
constexpr int START_WATERING_PERCENT = 35;
constexpr int STOP_WATERING_PERCENT = 55;
constexpr unsigned long MAX_PUMP_RUN_MS = 15000;
constexpr unsigned long PUMP_COOLDOWN_MS = 30000;
```

`MAX_PUMP_RUN_MS` protects against endless pumping if the sensor fails or the reservoir is empty. `PUMP_COOLDOWN_MS` prevents rapid repeated pump starts.

## Commands

Build:

```sh
pio run
```

Upload:

```sh
pio run -t upload
```

Serial monitor:

```sh
pio device monitor
```
