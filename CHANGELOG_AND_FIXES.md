# Comprehensive Technical Report: LocalKit & PetKit Modifications, Debugging, and Security Hardening

This document provides a comprehensive technical record of all diagnostics, system modifications, bug fixes, and security configurations implemented for local integration of **PetKit** hardware (*YumShare Dual D4SH* feeder and *Eversweet 3 Pro W5* Bluetooth fountain) with **LocalKit** (`beta` branch), **Home Assistant**, **Frigate NVR**, and **Scrypted**.

---

## 1. Context & Hardware Architecture

* **LocalKit Branch & Version**: Deployed using the **`beta`** branch and Docker image (`ghcr.io/dwyschka/localkit:beta` in `compose.yml`).
* **Smart Pet Feeder**: PetKit YumShare Dual (`D4SH` Gen 1, Ingenic T31 / MIPS-ARM SoC, firmware `D4SH-261301`, IP `10.0.2.32`, PetKit ID `300023502`, SN `20250805DA0372`).
* **Smart Water Fountain**: PetKit Eversweet 3 Pro (`W5`, Bluetooth BLE MAC `a4c138461e98`, PetKit ID `400058602`, BLE Secret `515c551ef392`, SN `20250411S11573`).
* **Docker Host / LocalKit VM**: VM at `192.168.0.203` (SSH port 354; `localkit` container on MacVLAN `10.0.2.10`, `localkit-broker` on `10.0.2.11`).
* **Home Assistant**: Host `192.168.0.158` (Mosquitto MQTT broker on port 1883).
* **Frigate NVR**: `https://frigate2.aurelienruat.fr` (backend go2rtc on `10.0.5.156:8971` / `10.0.5.156:1984`).
* **Local DNS**: AdGuard Home IoT (`10.0.2.66`).

---

## 2. Issues Diagnosed & Solutions Implemented

### 2.1. Model Mismatch & Flash Script for Feeder (D4SH Gen 1 vs Gen 2)
* **Initial Problem**: The official LocalKit documentation (`localkit.io/devices/yumshare-dual2.html`) instructed users to run:
  ```sh
  wget -qO- http://tool.localkit.io/scripts/d4sh2/2.0.0/install | sh
  ```
  Upon running this on the feeder, it failed immediately with:
  ```
  === device ===
  [FAIL] cannot read firmwareVer from /opt/version -- is /opt mounted?
  ```
* **Root Cause**: The `d4sh2` script is specifically compiled for **Gen 2** hardware (Axera SoC), which mounts its partitions under `/opt/`. The hardware in use is **Gen 1** (Ingenic T31 SoC, firmware `D4SH-261301`), which has no `/opt` partition and stores everything in `/app/` and `/system/`.
* **Working Script Used**: The working script for D4SH Gen 1 is:
  👉 **`wget -qO- http://tool.localkit.io/scripts/d4h/2.0.0/install | sh`**
  This script downloads `app.img` (1.8 MB), installs the `rpd` (pipeline daemon) and `rsd` (RTSP server daemon) binaries, and configures the startup scripts in `/system/localkit/`.

### 2.2. Web UI Provisioning Timeout Bug vs Post-Reboot MQTT Connection
Two separate and independent issues occurred during the initial onboarding:

1. **Web UI Provisioning False Timeout Bug (`TelnetClient.php`)**:
   * *Symptom*: When running the installation via the LocalKit Filament Web UI provisioning wizard, the process threw an error: `Telnet read timed out waiting for one of: #/$`.
   * *Root Cause*: In `App\Petkit\TelnetClient`, the timeout was hardcoded to **5.0 seconds**. Downloading the `app.img` archive (1.8 MB) over the feeder's 2.4 GHz Wi-Fi link takes ~15–25 seconds. The Web UI reported a provisioning failure, even though the script continued running in the background and successfully flashed the device.
   * *Fix*: Increased the default timeout in `TelnetClient` to **60.0 seconds** and enabled an optional dynamic `$timeout` parameter in `exec()`.

2. **Post-Reboot "Disconnected" Badge (MQTT DNS Redirection)**:
   * *Symptom*: After rebooting the feeder, it remained marked as **Disconnected** in the Web UI and was not appearing in Home Assistant.
   * *Root Cause*: HTTP heartbeats were already reaching `localkit` (`10.0.2.10`), but the embedded daemon `ctrl` attempts to reach its MQTT broker at `localkit.iot-as-mqtt.eu-central-1.aliyuncs.com`.
   * *Fix*: Adding a DNS rewrite in AdGuard Home (`10.0.2.66`) pointing `localkit.iot-as-mqtt.eu-central-1.aliyuncs.com` to `10.0.2.11` (`localkit-broker`) immediately established the MQTT session upon reboot (`Client connected: localkit.d_d4sh_20250805DA0372`).

### 2.3. Eversweet 3 Pro (W5) Fountain: BLE ID & Secret Extraction
* **Documentation & Cloud Tool Limitations**:
  * LocalKit documentation (`bluetooth-devices/w5.html`) states that a BLE proxy is required, but never explains how to obtain the BLE `secret`.
  * The cloud tool `tool.localkit.io/cloud` threw an uncaught `Class "PetKit\Exceptions\InvalidHttpResponseException" not found` exception on EU accounts, and even when operational, only displays the `petkit_id`, omitting the BLE `secret`.
  * In Home Assistant, `/config/.storage/core.config_entries` did not store device-level keys.
* **Working Extraction Method**:
  1. Open **Home Assistant** -> **Settings** -> **Devices & Services** -> **Petkit**.
  2. Select the **Water Fountain** (*Eversweet 3 Pro*).
  3. Click the vertical three dots menu (`⋮`) in the upper right -> **Download diagnostics**.
  4. In the downloaded JSON file, inspect the `"device"` dictionary containing the raw Petkit API response:
     * **`"id": 400058602`** (Petkit ID)
     * **`"secret": "515c551ef392"`** (BLE Decryption Key)

### 2.4. Feeder Bluetooth Relay Firmware Incompatibility (`libbase.so` / `cJSON`)
Even with the valid secret, the feeder returned `result: 6` (BLE timeout) when scanning for the fountain:
1. **MAC Address Format**: The feeder's C binary (`pk_hexstr_MAC_to_num()`) requires `strlen(str) / 2 == 6` (**exactly 12 hexadecimal characters** without colons `:`). Standard MAC formatting (`a4:c1:38:46:1e:98`) caused parsing failure, resulting in an all-zero target address (`000000000000`).
2. **Device ID JSON Type**: LocalKit serialized the ID as a string (`"id": "400058602"`). The embedded `cJSON` library reads `item->valueint`, which evaluated to `0`.
* *Fix*: Added the `setMacAttribute` mutator in [`BluetoothDevice.php`](file:///var/www/html/app/Models/BluetoothDevice.php) and cast `petkit_id` to `(int)` in HTTP and MQTT resources.

### 2.5. Video Stream: 0.5x Slow Motion & PTS Dynamic Synchronization
* **Slow-Motion Mechanism**: In infrared night vision (`night: True`), the Ingenic T31 sensor doubles exposure time, reducing physical output to **~11 fps** (measured: 43 frames in 4.02 s = 10.75 fps).
* The `rpd` daemon was generating PTS timestamps assuming a fixed 25 fps timeline, stretching the video to half playback speed in VLC and Frigate.
* *Fix*: Configured `raptor.conf` under `[petkit]`:
  ```ini
  [petkit]
  timestamps = arrival
  fps = 0
  ```

### 2.6. "Configure RTSP (Telnet)" Action with Dedicated Terminal Console Modal
To provide clean UX without cluttering individual device pages, the action is integrated into the primary device listing page ([`ListDevices.php`](file:///var/www/html/app/Filament/Resources/DeviceResource/Pages/ListDevices.php)), adjacent to the official "Provision Device" button:
* **Removal of the Spurious Standalone "RTSP Console" Button**: An unwanted standalone button labeled "RTSP Console" previously appeared in the top header bar because it had been declared as a secondary header action. It was completely eliminated from `getHeaderActions()`.
* **Configuration Form**:
  * **Device Selector**: Dropdown listing all camera-equipped models (`isNextGen()`).
  * **RTSP Username**: Placeholder set to `myuser` (no hardcoded prefill), constrained to alphanumeric characters (`^[A-Za-z0-9_-]*$`) up to 64 chars.
  * **RTSP Password**: Password field with reveal toggle, strictly limited to 64 characters (`maxLength(64)`) and validated with regex `^[A-Za-z0-9_.-]*$` to prevent special characters (`#`, `;`, `:`, quotes, whitespace) from corrupting the INI file.
  * **Timestamp Mode**: Dropdown with `selectablePlaceholder(false)` featuring descriptive labels: `none (default: firmware PTS ticks - causes 0.5x slow-motion)` and `arrival (recommended: dynamic arrival timestamps - fixes 0.5x slow-motion)`.
* **Automatic Terminal Console Modal (`console_modal.blade.php`)**:
  * Upon form submission, LocalKit connects over Telnet, modifies `raptor.conf`, restarts `raptor.sh`, updates the local database, stores the entire execution log in the Livewire property `$this->telnetConsoleLog`, and automatically dispatches the `open-modal` event (`id: 'rtsp-console-modal'`).
  * A styled dark terminal modal window opens centered on screen (macOS/Linux terminal style with colored indicators and green monospace text `bg-gray-950 text-green-400 font-mono`) displaying the complete step-by-step Telnet execution trace.
  * A **Close** button allows dismissing the terminal modal once reviewed.

### 2.7. Daily Statistics & Advanced Telemetry for YumShare Dual (`Configuration.php`)
* **Initial State**: Unlike the PetKit cloud integration, LocalKit originally lacked daily statistical counters (feedings served, cat visits, eating duration, per-hopper breakdowns). Furthermore, real-time eating state was not exposed.
* **New Home Assistant Entities Implemented**:
  1. `sensor.distributeur_de_croquettes_feedings_today`: Total number of feeding events served today (manual + scheduled).
  2. `sensor.distributeur_de_croquettes_portions_dispensed_hopper_1_today`: Cumulative portions dispensed from Hopper 1 today.
  3. `sensor.distributeur_de_croquettes_portions_dispensed_hopper_2_today`: Cumulative portions dispensed from Hopper 2 today.
  4. `sensor.distributeur_de_croquettes_manual_portions_today`: Cumulative portions dispensed manually (physical button or Home Assistant on-demand action).
  5. `sensor.distributeur_de_croquettes_planned_meals_today`: Total scheduled meals planned for today per active schedules.
  6. `sensor.distributeur_de_croquettes_planned_portions_today`: Total scheduled portions planned for today.
  7. `sensor.distributeur_de_croquettes_meals_today`: Total number of cat meal visits recorded today (`EAT` events).
  8. `sensor.distributeur_de_croquettes_visits_today`: Total cat approach/detection events recorded today (`DETECT` events).
  9. `sensor.distributeur_de_croquettes_eating_time_today`: Cumulative time spent eating today (in seconds).
  10. `binary_sensor.distributeur_de_croquettes_is_eating`: Real-time eating sensor (`occupancy` / `cat`), switching `ON` when eating starts and `OFF` upon departure.
  11. `sensor.distributeur_de_croquettes_last_used_by`: Name of the pet last identified at the device (with automatic single-pet fallback).
  12. `event.distributeur_de_croquettes_activity`: Real-time activity event stream (`dispense`, `eat_start`, `eat_over`, `detect`).
* **Dynamic Daily Statistics Engine (`computeDailyStats()`)**:
  Aggregates daily records since local midnight (`Europe/Paris`) from the `history` SQLite table, dynamically evaluated on device state changes and MQTT events.

### 2.8. Critical MQTT Protocol ACK Compliance Bug (Alink / Alibaba Cloud Link IoT)
* **Symptom**:
  - The feeder repeatedly retried sending messages every 15 seconds (up to 4 consecutive attempts per event).
  - On every feeding sequence, the feeder continuously re-sent a stale `feed_over` event from October 25 (`r_20261025_1191-1`), blocking subsequent feed completions.
* **Root Cause**:
  - PetKit firmware (C SDK for Alibaba Cloud Link IoT) enforces strict schema checking on `post_reply` acknowledgement packets:
    ```json
    {"code":200,"data":{},"id":"<id>","message":"success","method":"thing.event.<event>.post","version":"1.0"}
    ```
  - In original LocalKit code:
    1. The `method` attribute was sent as `null`.
    2. The `version` attribute was hardcoded to `"1.0.0"` instead of `"1.0"`. The embedded C client performs a literal string comparison (`strcmp(version, "1.0")`) which failed.
  - Due to the invalid ACK, the microcontroller retained the unacknowledged message at the head of its on-flash FIFO queue, continuously re-broadcasting it and stalling newer events.
* **Fix Applied**:
  - Refactored `app/Http/Resources/MQTT/SuccessResource.php`: Forced `'version' => '1.0'` and added dynamic `$method` injection.
  - Refactored `app/MQTT/GenericReply.php`: Automatically derives the Alink method from the incoming topic via `MQTTTopic::topic2Method($topic)` (`thing.event.<event>.post` or `thing.service.<service>`).

### 2.9. Feed Parameter Overwrite Bug (`feed_start` vs `feed_over`)
* **Symptom**:
  - In Home Assistant, `Portions Dispensed Hopper 1 Today` remained stuck at `1` and `Manual Portions Today` at `0`, despite 5 meals having been dispensed today (01:02, 07:07, 12:34, 19:05, 21:26).
* **Root Cause**:
  1. During a feed, `feed_over` arrived and wrote `real_amount1: 1`. However, subsequent `feed_start` retries called `History::updateOrCreate(['messageId' => $event_id], ['parameters' => $content])`.
  2. Because `feed_start` does not contain `real_amount1`, this overwrite **completely erased** the dispensed portion count written by `feed_over`!
  3. In `computeDailyStats()`, missing `real_amount1` defaulted to `0`, leaving only the very first meal (01:02) uncorrupted.
  4. For `manualPortionsToday`, on-demand feeds from Home Assistant had prefix `r_` with `manual: 0`, and were excluded by the `manual === 1` filter.
* **Fix Applied**:
  - In `app/Petkit/Devices/YumshareDual/Device.php`: `feed_start` now safely merges parameters (`array_merge`) with existing database values without overwriting existing keys.
  - In `mergeHistory()`: Added feed ID prefix matching (`parameters->id == $content['id']`) and temporal fallback to the most recent `WORKING` feed within the last 3 minutes.
  - In `app/Petkit/Devices/YumshareDual/Configuration.php`:
    - Safe fallback: If `real_amount1` is not yet available, defaults to 1 portion for Hopper 1 (since any recorded feed dispenses at least 1 portion).
    - Added both physical buttons (`manual === 1`) and on-demand feeds (`str_starts_with($id, 'r_')`) into `manualPortionsToday`.
  - Backfilled SQLite records for the 4 earlier feeds (07:07, 12:34, 19:05, 21:26) with `real_amount1 = 1`.

### 2.10. Meal Duration Calculation & Safety Threshold Guard
* **Symptom**: Cumulative eating time (`eating_time_today`) displayed an unrealistic value (1h47 for a single cat).
* **Root Cause**:
  - An Eloquent mass update (`update(['pet_id' => 1])`) had touched `updated_at` across all 44 rows to the current execution time.
  - `eventDuration()` calculated `created_at->diffInSeconds(updated_at)`, measuring the difference between historical row creation and the current script timestamp!
* **Fix Applied**:
  - Restored pristine historical `updated_at` timestamps from backup archive.
  - Explicitly wrote `event_start` and `event_end` timestamps directly into `parameters` upon `eat_over`.
  - Disabled automatic timestamps (`$timestamps = false`) on administrative data updates.
  - Added a safety threshold in `computeDailyStats()`: Only meal durations between 5 seconds and 1800 seconds (30 minutes) are included, filtering out disconnected or unclosed sessions.

---

## 3. Summary of Code Modifications in LocalKit

| File | Change Applied |
| :--- | :--- |
| [`app/Filament/Resources/DeviceResource/Pages/ListDevices.php`](file:///var/www/html/app/Filament/Resources/DeviceResource/Pages/ListDevices.php) | Added `Configure RTSP (Telnet)` header action with camera selector, validated inputs, and automatic terminal modal window on page footer. |
| [`resources/views/filament/partials/console_modal.blade.php`](file:///var/www/html/resources/views/filament/partials/console_modal.blade.php) | Dedicated Blade `<x-filament::modal>` component for rendering the full Telnet execution terminal log. |
| [`app/Petkit/TelnetClient.php`](file:///var/www/html/app/Petkit/TelnetClient.php) | Increased default timeout to 60s and added optional timeout parameter to `exec()` to prevent false provisioning errors. |
| [`app/Models/BluetoothDevice.php`](file:///var/www/html/app/Models/BluetoothDevice.php) | Added `setMacAttribute` mutator stripping colons to satisfy `pk_hexstr_MAC_to_num()`. |
| [`app/Http/Resources/DevBleDeviceResource.php`](file:///var/www/html/app/Http/Resources/DevBleDeviceResource.php) | Cast `id` to `(int)` for embedded `cJSON` parser compatibility. |
| [`app/Http/Resources/MQTT/DevBLEDevice.php`](file:///var/www/html/app/Http/Resources/MQTT/DevBLEDevice.php) | Cast `id` to `(int)` in BLE MQTT discovery payload. |
| [`app/Http/Resources/MQTT/SuccessResource.php`](file:///var/www/html/app/Http/Resources/MQTT/SuccessResource.php) | Enforced strict Alink protocol ACK response (`version: '1.0'`, dynamic method resolution). |
| [`app/MQTT/GenericReply.php`](file:///var/www/html/app/MQTT/GenericReply.php) | Automatic derivation of Alink method from MQTT topic (`thing.event...` / `thing.service...`). |
| [`app/Petkit/Devices/YumshareDual/Configuration.php`](file:///var/www/html/app/Petkit/Devices/YumshareDual/Configuration.php) | Added per-hopper feed buttons, 9 daily statistics sensors, real-time `is_eating` binary sensor, and resilient portion fallbacks. |
| [`app/Petkit/Devices/YumshareDual/Device.php`](file:///var/www/html/app/Petkit/Devices/YumshareDual/Device.php) | Independent hopper dispensing, safe `feed_start` parameter merging, advanced `feed_over` correlation fallbacks, and single-pet resolution. |
| [`app/Petkit/Devices/YumshareDual/UI.php`](file:///var/www/html/app/Petkit/Devices/YumshareDual/UI.php) | Added RTSP Username and RTSP Password form fields in camera section of Web UI. |
| [`app/Management/Rtsp.php`](file:///var/www/html/app/Management/Rtsp.php) | Added per-device RTSP authentication support with fallback to global config. |
| [`config/camera.php`](file:///var/www/html/config/camera.php) | Declared `CAMERA_RTSP_USER` and `CAMERA_RTSP_PASSWORD` environment variables. |
| [`docs/protocol_mqtt.md`](file:///var/www/html/docs/protocol_mqtt.md) | Technical reference guide on Alink MQTT architecture, ACK requirements, and event lifecycles. |
| [`docs/yumshare_dual.md`](file:///var/www/html/docs/yumshare_dual.md) | Comprehensive specification for YumShare Dual, hardware capabilities, Home Assistant entities, and database schema. |
| [`README.md`](file:///var/www/html/README.md) | Updated documentation links and hardware integration notes. |

---

## 4. Cybersecurity Audit: Hardening Telnet on the IoT VLAN

The Telnet daemon listens on port 23 with username `root` and Petkit factory password `while(&P` (spawned by `/etc/init.d/rcS` in the read-only SquashFS partition).

### Risk Analysis & Limitations of Device Isolation:
Client Isolation at the Wi-Fi AP level creates significant collateral breakage:
* It blocks legitimate intra-VLAN peer-to-peer traffic between smart devices.
* It requires maintaining complex exception lists for local DNS, the LocalKit server, RTSP/NVR pipelines, and home automation bridges.

### Adopted & Verified Hardening Strategy:

1. **Router Firewall Rule (Recommended & Adopted Solution)**:
   * Create a firewall rule on the router dropping all incoming traffic to port 23 (`10.0.2.32:23`).
   * **On-Demand Access**: Keep this rule enabled by default. Telnet is therefore completely blocked and inaccessible 99.9% of the time from any device on the network or IoT VLAN.
   * When maintenance, re-flashing, or Telnet reconfiguration is needed, simply toggle the firewall rule off temporarily, or restrict access exclusively to your admin workstation (`192.168.0.x`) and LocalKit (`10.0.2.10`).
   * *Advantages*: Zero risk of bricking the device, no modifications to read-only factory files, and immediate centralized control directly in the router interface.

2. **Optional Suggestions for the Community (Pull Request)**:
   * Document as optional suggestions for advanced users the possibility of mounting a custom shadow file (`mount --bind /system/localkit/files/shadow /etc/shadow`) in `07_start_app.sh`, or shifting `telnetd` to an alternative port, leaving default repository behavior untouched to preserve full backward compatibility.
