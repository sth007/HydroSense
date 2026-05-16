# HydroSense ESP32-C3 SuperMini

PlatformIO/Arduino project for an ESP32-C3 SuperMini that reads a Grove - Moisture Sensor V1.4, shows status on a 4.2" WeAct Studio ePaper, and controls a 3-5 V mini water pump through a 1-channel 5 V relay module.

## Quick Installation

Clone the repository from GitHub:

```sh
git clone https://github.com/sth007/HydroSense.git
cd HydroSense
```

Install PlatformIO if it is not already available:

```sh
python3 -m pip install platformio
```

Build the firmware:

```sh
pio run
```

Configure WiFi and server settings in `src/main.cpp`:

```cpp
constexpr bool WIFI_ENABLED = true;
constexpr char WIFI_SSID[] = "YOUR_WIFI_SSID";
constexpr char WIFI_PASSWORD[] = "YOUR_WIFI_PASSWORD";
constexpr char API_BASE_URL[] = "http://YOUR_SERVER_IP:8077/index.php";
constexpr char API_KEY[] = "change-me";
constexpr char DEVICE_ID[] = "hydrosense-esp32";
```

Upload to the ESP32-C3:

```sh
pio run -t upload
```

Start the local PHP dashboard/API server:

```sh
HYDROSENSE_API_KEY=change-me php -S 127.0.0.1:8077 -t server
```

Open the dashboard:

```text
http://127.0.0.1:8077/
```

Use a different `DEVICE_ID` for each ESP32/pump. The dashboard automatically adds every device that sends telemetry.

## Wiring

### Grove - Moisture Sensor V1.4

The Grove - Moisture Sensor V1.4 is an analog resistive moisture sensor. Its output value normally increases when the soil is wetter.

| Grove wire | Function | ESP32-C3 SuperMini |
| --- | --- | --- |
| Red | VCC | 3V3 |
| Black | GND | GND |
| White | NC | Not connected |
| Yellow | Analog output | GPIO1 / ADC1-1 |

Do not power the Grove sensor from 5 V when its analog output is connected directly to the ESP32-C3. ESP32-C3 ADC pins are not 5 V tolerant. Use `3V3`, or use a voltage divider/level shifter if you must power the sensor from 5 V.

### Battery measurement

Your battery is a 103450 3.7 V 2000 mAh lithium-polymer battery with JST-PH 2.0 connector. This is a 1S LiPo cell: about 4.2 V when full, about 3.7 V nominal, and about 3.3 V near empty.

The ESP32-C3 cannot measure battery capacity directly. The firmware estimates battery state from voltage. Connect the battery to `GPIO0 / ADC1-0` through a voltage divider:

```text
Battery + -> 100k resistor -> GPIO0 / ADC1-0 -> 100k resistor -> GND
Battery - -> GND
```

Do not connect a battery directly to an ESP32 GPIO. With the 100k/100k divider, a full 4.2 V LiPo becomes about 2.1 V at GPIO0, which is safe for the ADC.

The firmware currently maps:

```cpp
BATTERY_EMPTY_MV = 3300
BATTERY_FULL_MV = 4200
```

The shown percentage is an estimate from voltage, not a true coulomb-counted capacity measurement. The `2000 mAh` rating affects runtime, but not the ADC wiring.

### 4.2" WeAct Studio ePaper

This follows the wiring in your diagram.

| ePaper pin | ESP32-C3 SuperMini |
| --- | --- |
| VCC | 3V3 |
| GND | GND |
| SDA / DIN / MOSI | GPIO6 |
| SCL / CLK / SCK | GPIO4 |
| CS | GPIO7 |
| D/C | GPIO5 |
| RES | GPIO3 |
| BUSY | GPIO8 |
| MISO | GPIO20 |

### Relay module

| Pump MOSFET control | ESP32-C3 SuperMini / power |
| --- | --- |
| MOSFET gate, through 100 ohm resistor | GPIO21 |
| MOSFET source | GND |
| MOSFET gate pulldown | 100k to GND |

The ESP32-C3 GND and pump power supply negative must be connected together.

### Pump through relay contact

Wire the pump through a separate logic-level N-channel MOSFET, not directly through an ESP32 pin.

```text
Pump supply +  -> pump +
pump -         -> MOSFET drain
MOSFET source  -> pump supply -
ESP32 GPIO21   -> 100 ohm -> MOSFET gate
MOSFET gate    -> 100k -> GND
```

Add a flyback diode across the pump:

```text
Diode stripe/cathode -> pump +
Diode anode          -> pump -
```

The pump is rated DC 3-5 V, so use a suitable external supply or battery for the pump current. Do not power the pump from an ESP32 GPIO.

## Pin Choices

The default code uses:

| Function | ESP32-C3 pin |
| --- | --- |
| Soil sensor ADC | GPIO1 / ADC1-1 |
| Battery ADC | GPIO0 / ADC1-0 |
| Pump MOSFET gate | GPIO21 |
| ePaper MOSI | GPIO6 |
| ePaper MISO | GPIO20 |
| ePaper SCK | GPIO4 |
| ePaper CS | GPIO7 |
| ePaper D/C | GPIO5 |
| ePaper RES | GPIO3 |
| ePaper BUSY | GPIO8 |

If your relay behaves inverted, change `RELAY_ACTIVE_LOW` in `src/main.cpp`.

## Calibration

1. Upload the firmware.
2. Open the serial monitor at `115200`.
3. Read the `raw=` value with the sensor in dry air or dry soil and put it into `DRY_RAW`.
4. Read the `raw=` value with the sensor in water or very wet soil and put it into `WET_RAW`.
5. Upload again.

Defaults for the Grove V1.4 are set for 10-bit-style readings:

```cpp
#define DRY_RAW 0
#define WET_RAW 626
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
#define MAX_PUMP_RUN_MS 15000
#define PUMP_COOLDOWN_MS 30000
```

`MAX_PUMP_RUN_MS` protects against endless pumping if the sensor fails or the reservoir is empty. `PUMP_COOLDOWN_MS` prevents rapid repeated pump starts.

## Display

The ePaper shows:

| Area | Content |
| --- | --- |
| Current moisture | Percent, raw sensor value, OK/WATER state |
| History | Last 64 moisture samples |
| Pump | Current relay/pump state |
| Battery | Voltage estimate and percent |

The firmware has `USE_THREE_COLOR_EPAPER = 1` in `src/main.cpp` and uses `GxEPD2_420c_GDEY042Z98` for a red/black/white 4.2" panel. Low-moisture warning values are drawn with `GxEPD_RED`.

To reduce ePaper flashing, the sensor is still read every 2 seconds, but the display is refreshed only when something important changes or every 10 minutes:

```cpp
#define DISPLAY_INTERVAL_MS 600000
#define HISTORY_SAMPLE_INTERVAL_MS 60000
```

The history graph stores one point per minute.

## Server API and Dashboard

This project includes a small PHP server in `server/index.php`. It stores the latest ESP32 telemetry and a short history as local JSON files under `server/data/`, then renders a simple dashboard with current values, a moisture graph, and pump commands.

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
