# HydroSense ESP32-C3 SuperMini

PlatformIO/Arduino project for an ESP32-C3 SuperMini that reads 4 Grove-style analog humidity sensors and controls 4 water pump relays or MOSFET channels. The ESP32 sends all channel values to a PHP dashboard/API server and receives pump commands per channel.

## Quick Installation

Install only the PHP server/dashboard directory:

```sh
git clone --filter=blob:none --no-checkout https://github.com/sth007/HydroSense.git hydrosense-server
cd hydrosense-server
git sparse-checkout init --cone
git sparse-checkout set server
git checkout main
```

Start the local PHP dashboard/API server:

```sh
HYDROSENSE_API_KEY=change-me php -S 127.0.0.1:8077 -t server
```

Open the dashboard:

```text
http://127.0.0.1:8077/
```

Use the server URL in the ESP32 firmware:

```cpp
constexpr char API_BASE_URL[] = "http://YOUR_SERVER_IP:8077/index.php";
constexpr char API_KEY[] = "change-me";
constexpr char DEVICE_ID[] = "hydrosense-esp32";
```

Use the same value for `API_KEY` and `HYDROSENSE_API_KEY`. Use a different `DEVICE_ID` for each ESP32/pump. The dashboard automatically adds every device that sends telemetry.

## Wiring

### Humidity Sensors

The firmware supports 4 analog humidity sensors. The default ESP32-C3 ADC pins are:

| Channel | Sensor analog output | Default ESP32-C3 pin |
| --- | --- | --- |
| 1 | AOUT / yellow | GPIO34 |
| 2 | AOUT / yellow | GPIO35 |
| 3 | AOUT / yellow | GPIO36 |
| 4 | AOUT / yellow | GPIO39 |

Power the sensors from `3V3` and connect all sensor grounds to ESP32 `GND`. Do not power analog sensors from 5 V when their output is connected directly to an ESP32-C3 GPIO. ESP32-C3 ADC pins are not 5 V tolerant.

### Battery measurement

Your battery is a 103450 3.7 V 2000 mAh lithium-polymer battery with JST-PH 2.0 connector. This is a 1S LiPo cell: about 4.2 V when full, about 3.7 V nominal, and about 3.3 V near empty.

Battery measurement is disabled by default because the 4 humidity sensors use the ADC pins. If you add an external ADC or free one ADC pin, enable `BATTERY_MONITOR_ENABLED` in `src/main.cpp` and connect the battery through a voltage divider:

```text
Battery + -> 100k resistor -> ADC pin -> 100k resistor -> GND
Battery - -> GND
```

Do not connect a battery directly to an ESP32 GPIO. With the 100k/100k divider, a full 4.2 V LiPo becomes about 2.1 V at GPIO0, which is safe for the ADC.

The firmware currently maps:

```cpp
BATTERY_EMPTY_MV = 3300
BATTERY_FULL_MV = 4200
```

The shown percentage is an estimate from voltage, not a true coulomb-counted capacity measurement. The `2000 mAh` rating affects runtime, but not the ADC wiring.

### Relay / MOSFET Outputs

The default relay/MOSFET pins are:

| Channel | Default ESP32-C3 pin |
| --- | --- |
| Pump 1 | GPIO26 |
| Pump 2 | GPIO25 |
| Pump 3 | GPIO32 |
| Pump 4 | GPIO33 |

The ESP32-C3 GND and pump power supply negative must be connected together. If your relay board is active-low, change `RELAY_ACTIVE_LOW` in `src/main.cpp`.

For MOSFET switching, wire each pump like this:

```text
Pump supply +  -> pump +
pump -         -> MOSFET drain
MOSFET source  -> pump supply -
ESP32 GPIO     -> 100 ohm -> MOSFET gate
MOSFET gate    -> 100k -> GND
```

Add a flyback diode across the pump:

```text
Diode stripe/cathode -> pump +
Diode anode          -> pump -
```

Use a suitable external supply or battery for pump current. Do not power a pump from an ESP32 GPIO.

## Pin Choices

The default code uses:

| Function | ESP32-C3 pin |
| --- | --- |
| Humidity sensor 1 ADC | GPIO34 |
| Humidity sensor 2 ADC | GPIO35 |
| Humidity sensor 3 ADC | GPIO36 |
| Humidity sensor 4 ADC | GPIO39 |
| Pump 1 relay/MOSFET | GPIO26 |
| Pump 2 relay/MOSFET | GPIO25 |
| Pump 3 relay/MOSFET | GPIO32 |
| Pump 4 relay/MOSFET | GPIO33 |

Adjust `SOIL_SENSOR_PINS` and `RELAY_PINS` in `src/main.cpp` to match your wiring.

## Calibration

1. Upload the firmware.
2. Open the serial monitor at `115200`.
3. Read the `ch=... raw=...` values with each sensor in dry air or dry soil and put them into `DRY_RAW`.
4. Read the values with each sensor in water or very wet soil and put them into `WET_RAW`.
5. Upload again.

Defaults are set per channel for 10-bit-style readings:

```cpp
constexpr int DRY_RAW[CHANNEL_COUNT] = {0, 0, 0, 0};
constexpr int WET_RAW[CHANNEL_COUNT] = {626, 626, 626, 626};
```

Typical Grove V1.4 ranges from Seeed are:

| Condition | Raw value |
| --- | --- |
| Dry soil | 0-300 |
| Humid soil | 300-700 |
| In water | 700-950 |

The ESP32-C3 ADC reads at 12-bit width, and the firmware scales the value down to 10-bit-style `0-1023` readings. With the Grove sensor powered from `3V3`, readings may be much lower than Seeed's 5 V examples. This project currently uses your measured dry-air value, `DRY_RAW = 0`, and stable ESP32-C3 in-water value, `WET_RAW = 626`.

## Watering Logic

The pump starts when moisture is at or below `START_WATERING_PERCENT` and stops when moisture reaches `STOP_WATERING_PERCENT`.

```cpp
#define START_WATERING_PERCENT 35
#define STOP_WATERING_PERCENT 55
#define AUTO_WATERING_ENABLED false
#define MAX_PUMP_RUN_MS 15000
#define PUMP_COOLDOWN_MS 30000
```

`AUTO_WATERING_ENABLED` is disabled by default so uncalibrated sensor values cannot start pumps unexpectedly. Server commands can still switch pumps on/off. `MAX_PUMP_RUN_MS` protects against endless pumping if the sensor fails or the reservoir is empty. `PUMP_COOLDOWN_MS` prevents rapid repeated pump starts.

## Server API and Dashboard

This project includes a small PHP server in `server/index.php`. It stores the latest ESP32 telemetry and a short history as local JSON files under `server/data/`, then renders a responsive dashboard with one ESP32 card and four pump/sensor channel controls.

Run it locally for testing:

```sh
HYDROSENSE_API_KEY=change-me php -S 127.0.0.1:8077 -t server
```

Open:

```text
http://127.0.0.1:8077/
```

For the ESP32, set these constants in `src/main.cpp` before uploading:

```cpp
constexpr bool WIFI_ENABLED = true;
constexpr char WIFI_SSID[] = "YOUR_WIFI_SSID";
constexpr char WIFI_PASSWORD[] = "YOUR_WIFI_PASSWORD";
constexpr char API_BASE_URL[] = "http://YOUR_SERVER_IP:8077/index.php";
constexpr char API_KEY[] = "change-me";
constexpr char DEVICE_ID[] = "hydrosense-esp32";
```

Use the same `API_KEY` as `HYDROSENSE_API_KEY`. If you upload the PHP file to a web server, change `API_BASE_URL` to that public or local URL.

Each ESP32/pump needs a unique `DEVICE_ID`. The dashboard automatically creates one card per device as soon as it receives telemetry from that ID. Device status is shown by color:

| Color | Meaning |
| --- | --- |
| Green | telemetry received within 90 seconds |
| Orange | last telemetry was within 15 minutes |
| Red | no telemetry for more than 15 minutes |

### API endpoints

Telemetry upload:

```text
POST /index.php?api=telemetry
Header: X-Api-Key: change-me
Content-Type: application/json
```

Command polling:

```text
GET /index.php?api=command&device_id=hydrosense-esp32
Header: X-Api-Key: change-me
```

Command acknowledgement:

```text
POST /index.php?api=ack
Header: X-Api-Key: change-me
Content-Type: application/json
```

Dashboard commands:

| Command | ESP32 behavior |
| --- | --- |
| `on` | switches to manual mode and starts the pump |
| `off` | switches to manual mode and stops the pump |
| `auto` | returns control to the local automatic moisture logic |

`MAX_PUMP_RUN_MS` still limits pump runtime even after a server `on` command.

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
