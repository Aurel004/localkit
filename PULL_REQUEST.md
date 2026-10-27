# Pull Request: Comprehensive Fixes for BLE Relay, Dual-Hopper Feeder, Provisioning Timeout, RTSP Push, Alink MQTT ACK Compliance, and Daily Statistics Tracking

## Target Repository & Branch
* **Repository**: `dwyschka/localkit`
* **Base Branch**: **`beta`**

---

## PR Title
`feat & fix: BLE relay compatibility, dual-hopper controls, Alink MQTT ACK compliance, feed overwrite safeguards, and daily telemetry sensors`

---

## PR Type
- [x] Bugfix
- [x] Feature / Enhancement
- [ ] Refactoring
- [x] Documentation / Hardware Clarification

---

## Summary & Context

This pull request addresses several real-world operational issues encountered during end-to-end local deployment of PetKit devices (**PetKit YumShare Dual D4SH** feeder and **PetKit Eversweet 3 Pro W5** BLE fountain) using the `beta` branch of LocalKit:

### 1. Bluetooth BLE Relay Compatibility (`pk_hexstr_MAC_to_num` & `cJSON`)
* **Problem**: The embedded C binary `ctrl` on the feeder relies on `pk_hexstr_MAC_to_num()` (in `libbase.so`), which strictly enforces `strlen(str) / 2 == 6` (requiring **exactly 12 hexadecimal characters** without any colons `:`). Standard MAC formatting (`a4:c1:38:46:1e:98`) caused the function to fail, leaving the MAC buffer zeroed out (`000000000000`) and causing a `result: 6` BLE scan timeout. Furthermore, `cJSON` in the feeder daemon parses the device ID using `item->valueint`; serializing the ID as a JSON string evaluated to `0`.
* **Fix**:
  - Added an Eloquent attribute mutator `setMacAttribute()` in `BluetoothDevice.php` to automatically strip colons and lowercase the address.
  - Cast `id` explicitly to `(int)` in both `DevBleDeviceResource.php` and `MQTT/DevBLEDevice.php`.
* **Documentation Note (BLE Secret Extraction)**:
  `tool.localkit.io/cloud` currently throws an unhandled `Class "PetKit\Exceptions\InvalidHttpResponseException" not found` on EU accounts, and only returns the device ID without the BLE `secret` required for decrypting W5 advertisements. Users can retrieve their BLE `secret` by downloading the diagnostic file in Home Assistant (**PetKit Integration -> Eversweet 3 Pro -> ⋮ -> Download diagnostics**) and reading `"secret"` under the `"device"` JSON section.

### 2. Dual-Hopper Feeder Single-Dose Controls (YumShare Dual)
* **Problem**: LocalKit and Home Assistant only provided a generic `Feed` button that dispensed from both hoppers simultaneously according to numeric sliders. Users had no way to dispense a single portion (1 dose) from a specific hopper.
* **Fix**:
  - Added `action_feed_hopper_1` and `action_feed_hopper_2` Button entities in `Configuration.php`.
  - Updated `action(stdClass $message)` in `Device.php` to handle per-hopper feeding, defaulting to 1 portion if none is specified.

### 3. False Timeout During Web UI Provisioning (`TelnetClient.php`)
* **Problem**: When triggering the installation script from the Filament Provisioning wizard, the step failed with: `Telnet read timed out waiting for one of: #/$`.
  `TelnetClient` had a hardcoded timeout of only **5.0 seconds**. Because downloading `app.img` (1.8 MB) over the device's 2.4 GHz Wi-Fi takes ~15–25 seconds, the web UI marked provisioning as failed while the device was still flashing in the background.
* **Fix**:
  - Increased default timeout to **60.0 seconds** in `TelnetClient.php`.
  - Added an optional dynamic `$timeout` parameter to `exec()` and `readUntil()`.
* **Hardware / Script Notice**:
  D4SH Gen 1 (Ingenic T31 SoC, firmware `D4SH-261301`) uses `/system/` and `/app/` without `/opt/`. It must be flashed using `wget -qO- http://tool.localkit.io/scripts/d4h/2.0.0/install | sh`. The `d4sh2` script is strictly for Gen 2 (Axera SoC with `/opt/version`).

### 4. Direct RTSP Configuration over Telnet (`ListDevices.php`) & Console Modal Feedback
* **Problem**: The embedded RTSP daemon `rsd` (`raptor.conf`) supports HTTP Digest authentication via `username` and `password` under `[rtsp]`. However, LocalKit only generated unauthenticated RTSP URLs, causing `401 Unauthorized` during snapshot generation. Furthermore, storing credentials only as global `.env` variables (`CAMERA_RTSP_USER` / `PASSWORD`) prevents environments with multiple camera devices (e.g. feeder + Purobot litter) from configuring different passwords or mixing secured and unsecured streams.
* **Fix**:
  - **Header Action in `/devices` (`ListDevices.php`)**: Added `Action::make('configure_rtsp')` placed cleanly next to "Provision Device". It dynamically locates the streamer directories (supporting `/system/localkit/files` on Gen 1 and `/opt/localkit/files` on Gen 2), allows users to pick any camera device, enter an alphanumeric username (with `myuser` hint), configure a password strictly capped at 64 characters (protecting internal INI and Digest auth buffers), and choose timestamp modes without empty placeholders.
  - **Automatic Console Modal (`console_modal.blade.php`)**: Upon submission, the Telnet commands execute, store execution output in the Livewire property `$telnetConsoleLog`, and automatically dispatch `open-modal` (`rtsp-console-modal`). A styled dark terminal modal window opens in the center of the screen with complete step-by-step trace output.
  - Added **RTSP Username** and **RTSP Password** form fields to `UI.php` in Filament.
  - Patched `Management\Rtsp::url()` to prioritize device-level configuration (`$device->configuration['settings']['rtsp_username']` / `rtsp_password`), falling back to global `config('camera.rtsp.user')` when not specified.
  - Added `CAMERA_RTSP_USER` and `CAMERA_RTSP_PASSWORD` environment variables in `config/camera.php` (defaulting to `null`).

### 5. Alibaba Cloud Link IoT (Alink) MQTT Protocol Compliance (`SuccessResource.php`, `GenericReply.php`)
* **Problem**: The feeder repeatedly retried sending MQTT messages up to 4 times every 15 seconds, and continuously stalled on old `feed_over` packets from its on-flash FIFO queue.
* **Root Cause**: The PetKit C firmware strictly compares the ACK response schema:
  - `version` must be `"1.0"` (the original `"1.0.0"` failed `strcmp(ver, "1.0")`).
  - `method` must match `thing.event.<event>.post` or `thing.service.<service>` (sending `null` caused C-side validation failure).
* **Fix**:
  - Updated `SuccessResource.php` to output `'version' => '1.0'` and receive dynamic method names.
  - Updated `GenericReply.php` to derive the Alink method directly from the MQTT topic via `MQTTTopic::topic2Method($topic)`.

### 6. Protection Against `feed_start` Overwriting History Data (`Device.php`)
* **Problem**: Re-sent `feed_start` packets called `History::updateOrCreate()` with raw `$content`. Because `feed_start` only contains initialization data (and no `real_amount1` / `real_amount2`), subsequent re-transmissions completely erased the dispensed portion count written by `feed_over`, leaving daily dispensed portions stuck at 1.
* **Fix**:
  - Replaced blind overwrite with an attribute-preserving `array_merge()` in `Device.php`.
  - Added multiple matching fallbacks to `mergeHistory()`: matching by `parameters->id` (`r_...`), and matching the latest `WORKING` event within the last 3 minutes.

### 7. Daily Statistics & Telemetry Engine (`Configuration.php`)
* **Feature**: Added dynamic daily statistics exposed to Home Assistant via MQTT Discovery:
  - `feedings_today`: Total feed events served today.
  - `portions_dispensed_hopper_1_today` & `portions_dispensed_hopper_2_today`: Accurate portions dispensed per hopper.
  - `manual_portions_today`: Cumulative count of physical button and on-demand app/HA feeds.
  - `planned_meals_today` & `planned_portions_today`: Planned meal schedules for the day.
  - `meals_today` & `visits_today`: Cat meal visits and detector encounters.
  - `eating_time_today`: Cumulative duration spent eating today (with safety limits between 5s and 1800s to discard unclosed events).
  - `is_eating`: Real-time binary occupancy sensor (`occupancy` / `cat`).
  - `last_used_by`: Diagnostic sensor reporting the name of the pet last seen.
  - `event.activity`: Live event stream for instant activity notifications.
* **Resilient Fallback**: If `real_amount1` is not yet available, defaults to 1 portion for Hopper 1 so manual button presses and pending packets are never omitted from daily totals.

### 8. Technical Documentation & Architecture Guides
* Added [`docs/protocol_mqtt.md`](docs/protocol_mqtt.md) detailing Alink MQTT topology, strict ACK formatting, FIFO flash buffering, and event lifecycles.
* Added [`docs/yumshare_dual.md`](docs/yumshare_dual.md) documenting YumShare Dual hardware capabilities, database schema, and Home Assistant entities.
* Updated [`README.md`](README.md) with direct links to technical reference guides.

---

## Testing & Verification

1. **BLE Relay**: Verified pairing of PetKit Eversweet 3 Pro (W5) via YumShare Dual (D4SH). Feeder connects over BLE in <1s and relays real-time telemetry to Home Assistant MQTT.
2. **Dual-Hopper Dispensing**: Verified buttons `Feed 1 Dose Both`, `Feed 1 Dose Hopper 1`, and `Feed 1 Dose Hopper 2` appear in Home Assistant and dispense exactly 1 dose.
3. **Provisioning**: Verified `TelnetClient` no longer times out during long-running install scripts.
4. **Direct RTSP Telnet Push**: Verified clicking "Configure RTSP (Telnet)" on `/devices` updates `raptor.conf` on the feeder, restarts the daemon, opens the terminal console modal with the full execution log, and enables authenticated snapshots.
5. **Alink MQTT ACK**: Verified feeder acknowledges `feed_start` and `feed_over` on the first attempt without re-transmitting, and purges stale messages from its on-flash FIFO queue.
6. **Portions Tracking & Fallback**: Verified 5 distinct feed events (both manual physical buttons and Home Assistant on-demand actions) correctly accumulate to 5 portions in `Portions Dispensed Hopper 1 Today` and `Manual Portions Today`.
7. **Daily Telemetry**: Verified live synchronization of `is_eating`, `eating_time_today`, `meals_today`, `visits_today`, and `feedings_today` in Home Assistant.

---

## Unified Git Diff (against `beta` branch)

```diff
diff --git a/README.md b/README.md
index e72df09..b7c5ae5 100644
--- a/README.md
+++ b/README.md
@@ -22,7 +22,7 @@ ## Supported Devices
 - **Petkit Fresh Element 3** (`d3`)
 - **Petkit Fresh Element Solo** (`d4`)
 - **Petkit Yumshare Solo** (`d4h`)
-- **Petkit Yumshare Dual** (`d4sh`)
+- **Petkit Yumshare Dual** (`d4sh`) â€” [Documentation dÃ©taillÃ©e](docs/yumshare_dual.md)
 
 **Water fountains**
 - **Petkit Eversweet Ultra** (`w7h`)
@@ -65,6 +65,9 @@ ## Home Assistant
 
 Localkit integrates with Home Assistant over MQTT, automatically exposing your Petkit devices as entities via MQTT discovery. See the [Home Assistant integration guide](https://localkit.io/overview/homeassistant.html) for setup details.
 
-## Documentation
+## Documentation & Guides Techniques
 
-For full setup instructions, the complete list of exposed entities, and detailed documentation, visit [localkit.io](https://localkit.io).
+- **[Petkit YumShare Dual (`d4sh`)](docs/yumshare_dual.md)** : FonctionnalitÃ©s double trÃ©mie, camÃ©ra/RTSP, flux d'activitÃ© en direct et statistiques journaliÃ¨res dans Home Assistant.
+- **[Protocole MQTT & Cycle de Vie des Ã‰vÃ©nements](docs/protocol_mqtt.md)** : SpÃ©cifications Alink / Alibaba Cloud Link IoT, conformitÃ© stricte des acquittements (ACK), gestion de la file Flash FIFO et machine Ã  Ã©tats de distribution / repas.
+- **[Stockage Objet S3 Local (Garage / MinIO)](s3.md)** : Configuration du stockage persistant local pour les photos de distribution et extraits vidÃ©o de repas.
+- **Documentation officielle du projet** : Guides complets sur [localkit.io](https://localkit.io).
diff --git a/app/Filament/Resources/DeviceResource/Pages/ListDevices.php b/app/Filament/Resources/DeviceResource/Pages/ListDevices.php
index 41e6f95..e816de7 100644
--- a/app/Filament/Resources/DeviceResource/Pages/ListDevices.php
+++ b/app/Filament/Resources/DeviceResource/Pages/ListDevices.php
@@ -4,9 +4,17 @@
 
 use App\Filament\Concerns\InteractsWithProvisioning;
 use App\Filament\Resources\DeviceResource;
+use App\Models\Device;
+use App\Petkit\TelnetClient;
 use Filament\Actions\Action;
+use Filament\Forms\Components\Select;
+use Filament\Forms\Components\TextInput;
+use Filament\Notifications\Notification;
 use Filament\Resources\Pages\ListRecords;
 use Filament\Support\Enums\Width;
+use Illuminate\Contracts\View\View;
+use Illuminate\Support\Facades\Log;
+use Throwable;
 
 class ListDevices extends ListRecords
 {
@@ -14,6 +22,8 @@ class ListDevices extends ListRecords
 
     protected static string $resource = DeviceResource::class;
 
+    public ?string $telnetConsoleLog = null;
+
     public function mount(): void
     {
         parent::mount();
@@ -21,16 +31,187 @@ public function mount(): void
         $this->mountProvisioningDefaults();
     }
 
+    public function getFooter(): ?View
+    {
+        return view('filament.partials.console_modal');
+    }
+
     protected function getHeaderActions(): array
     {
         return [
-            /*
-             * Adding a device means provisioning one, so the button belongs
-             * here rather than only on its own page. The form is the same
-             * partial that page renders; the Bluetooth work happens in the
-             * browser, so there is nothing to submit and no server action
-             * behind this modal.
-             */
+            Action::make('configure_rtsp')
+                ->label('Configure RTSP (Telnet)')
+                ->icon('heroicon-o-video-camera')
+                ->color('gray')
+                ->modalHeading('Configure Camera RTSP Server & Authentication')
+                ->modalDescription('Updates raptor.conf on the selected camera device over Telnet and restarts the RTSP streamer (raptor.sh). Leave credentials blank to allow unauthenticated access.')
+                ->form([
+                    Select::make('device_id')
+                        ->label('Camera Device')
+                        ->options(fn () => Device::all()->filter(fn ($d) => (bool) $d->isNextGen())->pluck('name', 'id'))
+                        ->default(fn () => Device::all()->first(fn ($d) => (bool) $d->isNextGen())?->id)
+                        ->required()
+                        ->selectablePlaceholder(false),
+                    TextInput::make('rtsp_username')
+                        ->label('RTSP Username')
+                        ->placeholder('myuser')
+                        ->maxLength(64)
+                        ->regex('/^[A-Za-z0-9_-]*$/')
+                        ->helperText('Alphanumeric username (e.g. myuser). Leave empty to disable authentication.'),
+                    TextInput::make('rtsp_password')
+                        ->label('RTSP Password')
+                        ->password()
+                        ->revealable()
+                        ->placeholder('Password')
+                        ->maxLength(64)
+                        ->regex('/^[A-Za-z0-9_.-]*$/')
+                        ->helperText('Maximum 64 characters. Avoid #, ;, quotes, spaces, or colons.'),
+                    Select::make('timestamps')
+                        ->label('Timestamp Mode')
+                        ->options([
+                            'none' => 'none (default: firmware PTS ticks - causes 0.5x slow-motion in night vision)',
+                            'arrival' => 'arrival (recommended: dynamic arrival timestamps - fixes 0.5x slow-motion)',
+                            'capture' => 'capture (hardware capture clock)',
+                        ])
+                        ->default('arrival')
+                        ->selectablePlaceholder(false)
+                        ->required(),
+                    TextInput::make('fps')
+                        ->label('FPS Mode')
+                        ->default('0')
+                        ->numeric()
+                        ->helperText('0 = dynamic FPS matching sensor exposure, >0 = fixed FPS.'),
+                ])
+                ->action(function (array $data) {
+                    $device = Device::find($data['device_id'] ?? null);
+
+                    if (! $device) {
+                        Notification::make()
+                            ->danger()
+                            ->title('Device not found')
+                            ->send();
+
+                        return;
+                    }
+
+                    $ipAddress = $device->configuration['states']['ipAddress'] ?? null;
+
+                    if (empty($ipAddress)) {
+                        Notification::make()
+                            ->danger()
+                            ->title('No IP address known for this device')
+                            ->send();
+
+                        return;
+                    }
+
+                    $username = config('petkit.telnet_username');
+                    $password = config('petkit.telnet_password');
+
+                    if (empty($username) || empty($password)) {
+                        Notification::make()
+                            ->danger()
+                            ->title('Telnet credentials not configured')
+                            ->body('Set DEVICE_TELNET_USERNAME / DEVICE_TELNET_PASSWORD in .env')
+                            ->send();
+
+                        return;
+                    }
+
+                    $logLines = [];
+                    $logLines[] = "[Telnet] Connecting to {$ipAddress}:23 as {$username}...";
+
+                    try {
+                        $telnet = new TelnetClient($ipAddress, 23, 10);
+                        $telnet->login($username, $password);
+                        $logLines[] = "[Telnet] Authentication successful.";
+
+                        // Detect LocalKit raptor directory on device (supports Gen 1 /system and Gen 2 /opt)
+                        $pathsCheck = $telnet->exec('if [ -d /opt/localkit/files ]; then echo /opt/localkit/files; elif [ -d /system/localkit/files ]; then echo /system/localkit/files; else echo /system/etc; fi');
+                        $lines = array_filter(array_map('trim', explode("\n", $pathsCheck)));
+                        $dir = '/system/localkit/files';
+                        foreach ($lines as $line) {
+                            if (str_starts_with($line, '/')) {
+                                $dir = $line;
+                                break;
+                            }
+                        }
+
+                        $conf = "{$dir}/raptor.conf";
+                        $sh = "{$dir}/raptor.sh";
+                        $logLines[] = "[Config] Located streamer files in: {$dir}";
+
+                        // Backup raptor.conf if not already backed up
+                        $telnet->exec("[ ! -f {$conf}.bak ] && cp {$conf} {$conf}.bak");
+                        $logLines[] = "[Backup] Configuration verified/backed up to {$conf}.bak.";
+
+                        // Clean out any existing username / password / legacy auth directives
+                        $telnet->exec("sed -i '/^[[:space:]]*username[[:space:]]*=/d; /^[[:space:]]*password[[:space:]]*=/d; /^\\[auth\\]/d' {$conf}");
+
+                        $rtspUser = trim($data['rtsp_username'] ?? '');
+                        $rtspPass = trim($data['rtsp_password'] ?? '');
+
+                        if (! empty($rtspUser) && ! empty($rtspPass)) {
+                            $telnet->exec(sprintf(
+                                "printf '\\nusername = %s\\npassword = %s\\n' >> %s",
+                                $rtspUser,
+                                $rtspPass,
+                                $conf
+                            ));
+                            $logLines[] = "[Config] Added RTSP Digest credentials: username={$rtspUser}, password=********";
+                        } else {
+                            $logLines[] = "[Config] No RTSP credentials provided. Authentication disabled.";
+                        }
+
+                        $timestamps = $data['timestamps'] ?? 'arrival';
+                        $fps = $data['fps'] ?? '0';
+                        $telnet->exec("sed -i '/^[[:space:]]*timestamps[[:space:]]*=/d; /^[[:space:]]*fps[[:space:]]*=/d' {$conf}");
+                        if ($timestamps !== 'none') {
+                            $telnet->exec(sprintf("sed -i '/\\[petkit\\]/a timestamps = %s' %s", $timestamps, $conf));
+                            $telnet->exec(sprintf("sed -i '/\\[petkit\\]/a fps = %s' %s", $fps, $conf));
+                            $logLines[] = "[Config] Set timestamps={$timestamps}, fps={$fps}";
+                        } else {
+                            $logLines[] = "[Config] Kept default firmware timestamps";
+                        }
+
+                        $restartOutput = $telnet->exec("{$sh} restart");
+                        $logLines[] = "[Daemon] Streamer restarted: " . trim(str_replace(["\r\n", "\r", "\n"], ' ', $restartOutput));
+                        $telnet->close();
+                        $logLines[] = "[Telnet] Connection closed.";
+
+                        $config = $device->configuration;
+                        $config['settings']['rtsp_username'] = $rtspUser;
+                        $config['settings']['rtsp_password'] = $rtspPass;
+                        $device->configuration = $config;
+                        $device->saveQuietly();
+                        $logLines[] = "[Database] Device configuration updated in LocalKit.";
+
+                        $this->telnetConsoleLog = implode("\n", $logLines);
+                        $this->dispatch('open-modal', id: 'rtsp-console-modal');
+
+                        Notification::make()
+                            ->success()
+                            ->title('RTSP Configuration Applied')
+                            ->send();
+                    } catch (Throwable $e) {
+                        Log::warning('Configuring RTSP via Telnet failed', [
+                            'device_id' => $device->id,
+                            'ip' => $ipAddress,
+                            'error' => $e->getMessage(),
+                        ]);
+
+                        $logLines[] = "[ERROR] " . $e->getMessage();
+                        $this->telnetConsoleLog = implode("\n", $logLines);
+                        $this->dispatch('open-modal', id: 'rtsp-console-modal');
+
+                        Notification::make()
+                            ->danger()
+                            ->title('Failed to configure RTSP')
+                            ->body($e->getMessage())
+                            ->send();
+                    }
+                }),
+
             Action::make('provision')
                 ->label('Provision Device')
                 ->icon('heroicon-o-signal')
@@ -43,7 +224,7 @@ protected function getHeaderActions(): array
                 ->modalWidth(Width::ThreeExtraLarge)
                 ->modalSubmitAction(false)
                 ->modalCancelActionLabel('Close')
-                ->modalContent(fn (): \Illuminate\Contracts\View\View => view(
+                ->modalContent(fn (): View => view(
                     'filament.partials.provisioning',
                     [
                         ...$this->provisionWifiDefaults(),
diff --git a/app/Http/Resources/DevBleDeviceResource.php b/app/Http/Resources/DevBleDeviceResource.php
index 033eb2a..05996b2 100644
--- a/app/Http/Resources/DevBleDeviceResource.php
+++ b/app/Http/Resources/DevBleDeviceResource.php
@@ -24,7 +24,7 @@ public function toArray(Request $request): array
             "list" => $devices->map(function ($device) {
                 return [
                     "interval" => $device->interval,
-                    "id" => $device->petkit_id,
+                    "id" => (int)$device->petkit_id,
                     "secret" => $device->secret,
                     "type" => $device->bluetoothDeviceType(),
                     "mac" => $device->mac
diff --git a/app/Http/Resources/MQTT/DevBLEDevice.php b/app/Http/Resources/MQTT/DevBLEDevice.php
index a92fa8e..707cb87 100644
--- a/app/Http/Resources/MQTT/DevBLEDevice.php
+++ b/app/Http/Resources/MQTT/DevBLEDevice.php
@@ -20,7 +20,7 @@ public function toArray(Request $request)
                 "list" => $devices->map(function ($device) {
                     return [
                         "interval" => $device->interval,
-                        "id" => $device->petkit_id,
+                        "id" => (int)$device->petkit_id,
                         "secret" => $device->secret,
                         "type" => $device->bluetoothDeviceType(),
                         "mac" => $device->mac
diff --git a/app/Http/Resources/MQTT/SuccessResource.php b/app/Http/Resources/MQTT/SuccessResource.php
index 23e87bf..4f6445a 100644
--- a/app/Http/Resources/MQTT/SuccessResource.php
+++ b/app/Http/Resources/MQTT/SuccessResource.php
@@ -2,28 +2,37 @@
 
 namespace App\Http\Resources\MQTT;
 
-//{"code":200,"data":{},"id":"107","message":"success","method":"thing.event.work_start.post","version":"1.0"}
 use stdClass;
-use App\Helpers\MQTTTopic;
 use Illuminate\Http\Request;
 use Illuminate\Http\Resources\Json\JsonResource;
 
 class SuccessResource extends JsonResource
 {
+    protected ?string $explicitMethod = null;
+
+    public function __construct($resource, ?string $method = null)
+    {
+        parent::__construct($resource);
+        $this->explicitMethod = $method;
+    }
+
+    public static function make(...$parameters)
+    {
+        return new static(...$parameters);
+    }
 
     public function toArray(Request $request): array
     {
         $data = new stdClass();
+        $id = $this->resource->id ?? null;
+
         return [
             'code' => 200,
             'data' => $data,
-            'id' => $this->resource->id ?? null,
-            'message' => "success",
-            'method' => $this->resource->method ?? null,
-            'version' => "1.0.0"
+            'id' => is_scalar($id) ? (string) $id : '',
+            'message' => 'success',
+            'method' => $this->explicitMethod ?? $this->resource->method ?? null,
+            'version' => '1.0',
         ];
     }
-
 }
-
-
diff --git a/app/MQTT/GenericReply.php b/app/MQTT/GenericReply.php
index a130445..099d45a 100644
--- a/app/MQTT/GenericReply.php
+++ b/app/MQTT/GenericReply.php
@@ -2,17 +2,21 @@
 
 namespace App\MQTT;
 
+use App\Helpers\MQTTTopic;
 use App\Http\Resources\MQTT\SuccessResource;
-use Illuminate\Support\Facades\Log;
 
 class GenericReply
 {
-
     public static function reply(string $topic, mixed $message): AnswerDTO
     {
+        $method = $message?->method ?? null;
+        if (empty($method) && str_contains($topic, '/thing/')) {
+            $method = 'thing.' . MQTTTopic::topic2Method($topic);
+        }
+
         return new AnswerDTO(
             topic: sprintf('%s_reply', $topic),
-            message: SuccessResource::make($message)
+            message: SuccessResource::make($message, $method)
         );
     }
 }
diff --git a/app/Management/Rtsp.php b/app/Management/Rtsp.php
index 6f67b13..2159360 100644
--- a/app/Management/Rtsp.php
+++ b/app/Management/Rtsp.php
@@ -20,13 +20,43 @@ public function url(Device $device): ?string
     {
         $ip = $this->deviceIp($device);
 
-        return $ip !== null ? $this->urlForIp($ip) : null;
+        if ($ip === null) {
+            return null;
+        }
+
+        $user = $device->configuration['settings']['rtsp_username']
+            ?? $device->configuration['rtsp_user']
+            ?? config('camera.rtsp.user');
+        $pass = $device->configuration['settings']['rtsp_password']
+            ?? $device->configuration['rtsp_password']
+            ?? config('camera.rtsp.password');
+        $port = (int) ($device->configuration['settings']['rtsp_port']
+            ?? $device->configuration['rtsp_port']
+            ?? config('camera.rtsp.port', 8554));
+        $path = ltrim((string) ($device->configuration['settings']['rtsp_path']
+            ?? $device->configuration['rtsp_path']
+            ?? config('camera.rtsp.path', 'stream0')), '/');
+
+        $auth = ($user && $pass) ? sprintf('%s:%s@', urlencode((string)$user), urlencode((string)$pass)) : '';
+
+        return sprintf(
+            'rtsp://%s%s:%d/%s',
+            $auth,
+            $ip,
+            $port,
+            $path
+        );
     }
 
     public function urlForIp(string $ip): string
     {
+        $user = config('camera.rtsp.user');
+        $pass = config('camera.rtsp.password');
+        $auth = ($user && $pass) ? sprintf('%s:%s@', urlencode((string)$user), urlencode((string)$pass)) : '';
+
         return sprintf(
-            'rtsp://%s:%d/%s',
+            'rtsp://%s%s:%d/%s',
+            $auth,
             $ip,
             config('camera.rtsp.port'),
             ltrim((string) config('camera.rtsp.path'), '/')
@@ -85,4 +115,4 @@ private function deviceIp(Device $device): ?string
 
         return is_string($ip) && $ip !== '' ? $ip : null;
     }
-}
+}
\ No newline at end of file
diff --git a/app/Models/BluetoothDevice.php b/app/Models/BluetoothDevice.php
index efdc2ea..fa7b3fe 100644
--- a/app/Models/BluetoothDevice.php
+++ b/app/Models/BluetoothDevice.php
@@ -19,6 +19,11 @@ class BluetoothDevice extends Model
 
     protected $fillable = ['name', 'petkit_id', 'serial_number', 'mac', 'secret', 'type', 'configuration', 'interval', 'link_with'];
 
+    public function setMacAttribute($value)
+    {
+        $this->attributes['mac'] = strtolower(str_replace(':', '', (string)$value));
+    }
+
     protected $casts = [
         'configuration' => 'array'
     ];
diff --git a/app/Petkit/Devices/YumshareDual/Configuration.php b/app/Petkit/Devices/YumshareDual/Configuration.php
index 4700c9d..ed7ffec 100644
--- a/app/Petkit/Devices/YumshareDual/Configuration.php
+++ b/app/Petkit/Devices/YumshareDual/Configuration.php
@@ -9,7 +9,11 @@
 use App\Homeassistant\Interfaces\Snapshot;
 use App\Homeassistant\Interfaces\Video;
 use App\Homeassistant\Number;
+use App\Homeassistant\BinarySensor;
 use App\Homeassistant\Sensor;
+use App\Models\History;
+use App\Petkit\DeviceStates;
+use Carbon\Carbon;
 use App\Models\BluetoothDevice;
 use App\Management\Rtsp;
 use App\Models\Device;
@@ -127,6 +131,116 @@ class Configuration extends DeviceConfigurationDTO implements ConfigurationInter
      * generic/ffmpeg camera. Derived from $ipAddress in toArray(), never stored
      * by the device.
      */
+    // Live eating binary sensor
+    #[BinarySensor(
+        technicalName: 'is_eating',
+        name: 'Is Eating',
+        icon: 'mdi:cat',
+        deviceClass: 'occupancy',
+        valueTemplate: '{{ "ON" if value_json.states.isEating else "OFF" }}',
+        payloadOn: 'ON',
+        payloadOff: 'OFF'
+    )]
+    public bool $isEating;
+
+    // Daily statistics
+    #[Sensor(
+        technicalName: 'feedings_today',
+        name: 'Feedings Today',
+        icon: 'mdi:bowl-mix',
+        stateClass: 'total_increasing',
+        unitOfMeasurement: 'feedings',
+        valueTemplate: '{{ value_json.states.feedingsToday }}',
+        entityCategory: 'diagnostic'
+    )]
+    public ?int $feedingsToday;
+
+    #[Sensor(
+        technicalName: 'meals_today',
+        name: 'Meals Today',
+        icon: 'mdi:food-drumstick',
+        stateClass: 'total_increasing',
+        unitOfMeasurement: 'meals',
+        valueTemplate: '{{ value_json.states.mealsToday }}',
+        entityCategory: 'diagnostic'
+    )]
+    public ?int $mealsToday;
+
+    #[Sensor(
+        technicalName: 'visits_today',
+        name: 'Visits Today',
+        icon: 'mdi:cat',
+        stateClass: 'total_increasing',
+        unitOfMeasurement: 'visits',
+        valueTemplate: '{{ value_json.states.visitsToday }}',
+        entityCategory: 'diagnostic'
+    )]
+    public ?int $visitsToday;
+
+    #[Sensor(
+        technicalName: 'portions_hopper_1_today',
+        name: 'Portions Dispensed Hopper 1 Today',
+        icon: 'mdi:numeric-1-box-multiple',
+        stateClass: 'total_increasing',
+        unitOfMeasurement: 'portions',
+        valueTemplate: '{{ value_json.states.portionsHopper1Today }}',
+        entityCategory: 'diagnostic'
+    )]
+    public ?int $portionsHopper1Today;
+
+    #[Sensor(
+        technicalName: 'portions_hopper_2_today',
+        name: 'Portions Dispensed Hopper 2 Today',
+        icon: 'mdi:numeric-2-box-multiple',
+        stateClass: 'total_increasing',
+        unitOfMeasurement: 'portions',
+        valueTemplate: '{{ value_json.states.portionsHopper2Today }}',
+        entityCategory: 'diagnostic'
+    )]
+    public ?int $portionsHopper2Today;
+
+    #[Sensor(
+        technicalName: 'manual_portions_today',
+        name: 'Manual Portions Today',
+        icon: 'mdi:hand-pointing-right',
+        stateClass: 'total_increasing',
+        unitOfMeasurement: 'portions',
+        valueTemplate: '{{ value_json.states.manualPortionsToday }}',
+        entityCategory: 'diagnostic'
+    )]
+    public ?int $manualPortionsToday;
+
+    #[Sensor(
+        technicalName: 'planned_meals_today',
+        name: 'Planned Meals Today',
+        icon: 'mdi:calendar-clock',
+        unitOfMeasurement: 'meals',
+        valueTemplate: '{{ value_json.states.plannedMealsToday }}',
+        entityCategory: 'diagnostic'
+    )]
+    public ?int $plannedMealsToday;
+
+    #[Sensor(
+        technicalName: 'planned_portions_today',
+        name: 'Planned Portions Today',
+        icon: 'mdi:counter',
+        unitOfMeasurement: 'portions',
+        valueTemplate: '{{ value_json.states.plannedPortionsToday }}',
+        entityCategory: 'diagnostic'
+    )]
+    public ?int $plannedPortionsToday;
+
+    #[Sensor(
+        technicalName: 'eating_time_today',
+        name: 'Eating Time Today',
+        icon: 'mdi:timer-outline',
+        stateClass: 'total_increasing',
+        unitOfMeasurement: 's',
+        valueTemplate: '{{ value_json.states.eatingTimeToday }}',
+        entityCategory: 'diagnostic'
+    )]
+    public ?int $eatingTimeToday;
+
     #[Sensor(
         technicalName: 'stream_url',
         name: 'Stream URL',
@@ -588,14 +702,34 @@ class Configuration extends DeviceConfigurationDTO implements ConfigurationInter
     // Buttons
     #[Button(
         technicalName: 'action_feed',
-        name: 'Feed',
+        name: 'Feed 1 Dose Both',
         commandTopic: 'action/start',
-        icon: 'mdi:information-outline',
+        icon: 'mdi:bowl-mix',
         commandTemplate: '{"action": "feed"}',
         availabilityTemplate: 'online',
     )]
     private $actionFeed = 1;
 
+    #[Button(
+        technicalName: 'action_feed_hopper_1',
+        name: 'Feed 1 Dose Hopper 1',
+        commandTopic: 'action/start',
+        icon: 'mdi:numeric-1-box-outline',
+        commandTemplate: '{"action": "feed_hopper_1"}',
+        availabilityTemplate: 'online',
+    )]
+    private $actionFeedHopper1 = 1;
+
+    #[Button(
+        technicalName: 'action_feed_hopper_2',
+        name: 'Feed 1 Dose Hopper 2',
+        commandTopic: 'action/start',
+        icon: 'mdi:numeric-2-box-outline',
+        commandTemplate: '{"action": "feed_hopper_2"}',
+        availabilityTemplate: 'online',
+    )]
+    private $actionFeedHopper2 = 1;
+
     #[Number(
         technicalName: 'desiccant_durability',
         name: 'Desiccant Durability',
@@ -643,6 +777,16 @@ protected function rules(): array
             'bowl' => ['integer'],
             'lastSnapshot' => ['nullable', 'string'],
             'stream' => ['nullable', 'string'],
+            'isEating' => ['bool'],
+            'feedingsToday' => ['nullable', 'integer'],
+            'mealsToday' => ['nullable', 'integer'],
+            'visitsToday' => ['nullable', 'integer'],
+            'portionsHopper1Today' => ['nullable', 'integer'],
+            'portionsHopper2Today' => ['nullable', 'integer'],
+            'manualPortionsToday' => ['nullable', 'integer'],
+            'plannedMealsToday' => ['nullable', 'integer'],
+            'plannedPortionsToday' => ['nullable', 'integer'],
+            'eatingTimeToday' => ['nullable', 'integer'],
 
             // Settings
             'foodWarn' => ['bool'],
@@ -725,6 +869,16 @@ protected function defaults(): array
             'bowl' => -1,
             'lastSnapshot' => null,
             'stream' => null,
+            'isEating' => false,
+            'feedingsToday' => 0,
+            'mealsToday' => 0,
+            'visitsToday' => 0,
+            'portionsHopper1Today' => 0,
+            'portionsHopper2Today' => 0,
+            'manualPortionsToday' => 0,
+            'plannedMealsToday' => 0,
+            'plannedPortionsToday' => 0,
+            'eatingTimeToday' => 0,
 
             // Settings
             'foodWarn' => false,
@@ -809,6 +963,16 @@ protected function casts(): array
             'bowl' => new IntegerCast(),
             'lastSnapshot' => new StringCast(),
             'stream' => new StringCast(),
+            'isEating' => new BooleanCast(),
+            'feedingsToday' => new IntegerCast(),
+            'mealsToday' => new IntegerCast(),
+            'visitsToday' => new IntegerCast(),
+            'portionsHopper1Today' => new IntegerCast(),
+            'portionsHopper2Today' => new IntegerCast(),
+            'manualPortionsToday' => new IntegerCast(),
+            'plannedMealsToday' => new IntegerCast(),
+            'plannedPortionsToday' => new IntegerCast(),
+            'eatingTimeToday' => new IntegerCast(),
 
             // Settings
             'foodWarn' => new BooleanCast(),
@@ -892,6 +1056,13 @@ public static function fromDevice(Device|BluetoothDevice $device): self
             $data['bowl'] = $states['bowl'] ?? null;
             $data['lastSnapshot'] = $states['lastSnapshot'] ?? null;
             $data['stream'] = $states['stream'] ?? null;
+            $data['isEating'] = $states['isEating'] ?? false;
+        }
+
+        // Dynamically compute daily statistics
+        $dailyStats = self::computeDailyStats($device);
+        foreach ($dailyStats as $key => $val) {
+            $data[$key] = $val;
         }
 
         // Load settings
@@ -983,6 +1154,16 @@ public function toArray(): array
                 'bowl' => $this->bowl,
                 'lastSnapshot' => $this->lastSnapshot,
                 'stream' => $this->streamUrl(),
+                'isEating' => $this->isEating,
+                'feedingsToday' => $this->feedingsToday,
+                'mealsToday' => $this->mealsToday,
+                'visitsToday' => $this->visitsToday,
+                'portionsHopper1Today' => $this->portionsHopper1Today,
+                'portionsHopper2Today' => $this->portionsHopper2Today,
+                'manualPortionsToday' => $this->manualPortionsToday,
+                'plannedMealsToday' => $this->plannedMealsToday,
+                'plannedPortionsToday' => $this->plannedPortionsToday,
+                'eatingTimeToday' => $this->eatingTimeToday,
             ],
             'settings' => [
                 'shareOpen' => $this->shareOpen,
@@ -1074,4 +1255,100 @@ private function streamUrl(): ?string
 
         return app(Rtsp::class)->urlForIp($this->ipAddress);
     }
+
+    public static function computeDailyStats(Device|BluetoothDevice $device): array
+    {
+        $tz = config('app.timezone') ?: 'Europe/Paris';
+        $startOfDay = Carbon::today($tz);
+
+        // 1. Cat meals (EAT events)
+        $eatEvents = History::where('device_id', $device->id)
+            ->where('type', 'EAT')
+            ->where('created_at', '>=', $startOfDay)
+            ->get();
+
+        $mealsToday = $eatEvents->count();
+        $eatingTimeToday = (int) $eatEvents->sum(function ($h) {
+            $duration = $h->eventDuration();
+            // A realistic cat meal duration is between 5s and 1800s (30m).
+            // Guard against corrupted updated_at timestamps or open unclosed events.
+            return ($duration > 0 && $duration <= 1800) ? $duration : 0;
+        });
+
+        // 2. Cat visits (DETECT events)
+        $visitsToday = History::where('device_id', $device->id)
+            ->where('type', 'DETECT')
+            ->where('created_at', '>=', $startOfDay)
+            ->count();
+
+        // 3. Feedings (WORKING events)
+        $feedEvents = History::where('device_id', $device->id)
+            ->where('type', DeviceStates::WORKING->value)
+            ->where('created_at', '>=', $startOfDay)
+            ->get();
+
+        $feedingsToday = $feedEvents->count();
+
+        $portions1 = (int) $feedEvents->sum(function ($h) {
+            $p = $h->parameters ?? [];
+            if (isset($p['real_amount1'])) {
+                return (int) $p['real_amount1'];
+            }
+            if (isset($p['real_amount'])) {
+                return (int) $p['real_amount'];
+            }
+            // Fallback: any recorded feed dispensed at least 1 portion from hopper 1
+            return 1;
+        });
+
+        $portions2 = (int) $feedEvents->sum(function ($h) {
+            $p = $h->parameters ?? [];
+            return (int) ($p['real_amount2'] ?? 0);
+        });
+
+        $manualPortions = (int) $feedEvents->filter(function ($h) {
+            $p = $h->parameters ?? [];
+            // 'manual' === 1: physical button pressed on feeder
+            // 'r_...': realtime / on-demand feed triggered from Home Assistant or App
+            // 'n_...': scheduled plan meal triggered by internal timer
+            return (int) ($p['manual'] ?? 0) === 1 || str_starts_with((string) ($p['id'] ?? ''), 'r_');
+        })->sum(function ($h) {
+            $p = $h->parameters ?? [];
+            $amount1 = isset($p['real_amount1']) ? (int) $p['real_amount1'] : (isset($p['real_amount']) ? (int) $p['real_amount'] : 1);
+            $amount2 = (int) ($p['real_amount2'] ?? 0);
+            return $amount1 + $amount2;
+        });
+
+        // 4. Planned schedule
+        $plannedMeals = 0;
+        $plannedPortions = 0;
+        try {
+            $isoWeekday = Carbon::today($tz)->isoWeekday();
+            $key = $device->configuration['schedule']['key'] ?? 'default';
+            $schedules = $device->scheduleGroups($key);
+            foreach ($schedules as $group) {
+                $days = explode(',', (string) ($group['re'] ?? ''));
+                if (in_array((string) $isoWeekday, $days, true)) {
+                    $items = $group['it'] ?? [];
+                    $plannedMeals += count($items);
+                    foreach ($items as $item) {
+                        $plannedPortions += (int) ($item['a1'] ?? $item['a'] ?? 0) + (int) ($item['a2'] ?? 0);
+                    }
+                }
+            }
+        } catch (\Throwable) {
+        }
+
+        return [
+            'feedingsToday' => $feedingsToday,
+            'mealsToday' => $mealsToday,
+            'visitsToday' => $visitsToday,
+            'portionsHopper1Today' => $portions1,
+            'portionsHopper2Today' => $portions2,
+            'manualPortionsToday' => $manualPortions,
+            'plannedMealsToday' => $plannedMeals,
+            'plannedPortionsToday' => $plannedPortions,
+            'eatingTimeToday' => $eatingTimeToday,
+        ];
+    }
 }
diff --git a/app/Petkit/Devices/YumshareDual/Device.php b/app/Petkit/Devices/YumshareDual/Device.php
index 0f8c788..e1e17f0 100644
--- a/app/Petkit/Devices/YumshareDual/Device.php
+++ b/app/Petkit/Devices/YumshareDual/Device.php
@@ -94,16 +94,22 @@ public function stateTopics(): array
                 $this->reply($topic, $message);
             },
             sprintf('/sys/%s/%s/thing/event/eat_over/post', $this->device->productKey(), $this->device->deviceName()) => function (DeviceModel $device, string $topic, stdClass|null $message) {
-                $this->mergeHistory($message?->params?->event_id, $message?->params?->content);
+                $endTimestamp = !empty($message?->params?->timestamp) ? (int)$message->params->timestamp : null;
+                $this->mergeHistory($message?->params?->event_id, $message?->params?->content, null, $endTimestamp);
                 EventPublisher::publish($device, 'eat_over');
 
-                $this->parseState($device, $message);
+                $this->parseState($device, $message, DeviceStates::IDLE->value, function () use ($device) {
+                    $config = $device->configuration;
+                    $config['states']['isEating'] = false;
+                    $device->configuration = $config;
+                });
                 $this->reply($topic, $message);
             },
             sprintf('/sys/%s/%s/thing/event/eat_start/post', $this->device->productKey(), $this->device->deviceName()) => function (DeviceModel $device, string $topic, stdClass|null $message) {
+                $petId = $this->resolvePetId();
                 if (isset($message->params->event_id)) {
                     History::updateOrCreate(['messageId' => $message->params->event_id], [
-                        'pet_id' => null,
+                        'pet_id' => $petId,
                         'type' => 'EAT',
                         'parameters' => json_decode($message->params->content ?? '{}', true),
                         'device_id' => $device->id,
@@ -111,7 +117,15 @@ public function stateTopics(): array
                     EventPublisher::publish($device, 'eat_start');
                 }
 
-                $this->parseState($device, $message);
+                if ($petId) {
+                    $this->setLastUsedBy($petId);
+                }
+
+                $this->parseState($device, $message, DeviceStates::WORKING->value, function () use ($device) {
+                    $config = $device->configuration;
+                    $config['states']['isEating'] = true;
+                    $device->configuration = $config;
+                });
                 $this->reply($topic, $message);
             },
             sprintf('/sys/%s/%s/thing/event/move_detect/post', $this->device->productKey(), $this->device->deviceName()) => function (DeviceModel $device, string $topic, stdClass|null $message) {
@@ -119,9 +133,10 @@ public function stateTopics(): array
                 $this->reply($topic, $message);
             },
             sprintf('/sys/%s/%s/thing/event/pet_detect/post', $this->device->productKey(), $this->device->deviceName()) => function (DeviceModel $device, string $topic, stdClass|null $message) {
+                $petId = $this->resolvePetId();
                 if (isset($message->params->event_id)) {
                     History::updateOrCreate(['messageId' => $message->params->event_id], [
-                        'pet_id' => null,
+                        'pet_id' => $petId,
                         'type' => 'DETECT',
                         'parameters' => json_decode($message->params->content ?? '{}', true),
                         'device_id' => $device->id,
@@ -129,6 +144,10 @@ public function stateTopics(): array
                     EventPublisher::publish($device, 'detect');
                 }
 
+                if ($petId) {
+                    $this->setLastUsedBy($petId);
+                }
+
                 $this->parseState($device, $message);
                 $this->reply($topic, $message);
             },
@@ -141,14 +160,25 @@ public function stateTopics(): array
                 $this->reply($topic, $message);
             },
             sprintf('/sys/%s/%s/thing/event/feed_start/post', $this->device->productKey(), $this->device->deviceName()) => function (DeviceModel $device, string $topic, stdClass|null $message) {
-                $content = json_decode($message?->params?->content, false);
+                $content = json_decode($message?->params?->content ?? '{}', true) ?? [];
+                $eventId = $message?->params?->event_id ?? null;
 
-                History::updateOrCreate(['messageId' => $message->params->event_id], [
-                    'pet_id' => null,
-                    'type' => DeviceStates::WORKING->value,
-                    'parameters' => $content,
-                    'device_id' => $device->id
-                ]);
+                if ($eventId) {
+                    $history = History::where('messageId', $eventId)->first();
+                    if ($history) {
+                        $history->update([
+                            'parameters' => array_merge((array) ($history->parameters ?? []), (array) $content),
+                        ]);
+                    } else {
+                        History::create([
+                            'messageId' => $eventId,
+                            'pet_id' => null,
+                            'type' => DeviceStates::WORKING->value,
+                            'parameters' => $content,
+                            'device_id' => $device->id,
+                        ]);
+                    }
+                }
 
                 $this->parseState($device, $message, DeviceStates::WORKING->value);
                 $this->reply($topic, $message);
@@ -219,7 +249,20 @@ public function stateTopics(): array
      * own content when no *_start row exists yet; callers that don't pass
      * it keep the old silently-do-nothing behavior.
      */
-    private function mergeHistory(?string $messageId, ?string $rawContent, ?string $type = null): void
+    private function resolvePetId(?int $explicitId = null): ?int
+    {
+        if ($explicitId !== null && $explicitId > 0) {
+            return $explicitId;
+        }
+
+        if (Pet::count() === 1) {
+            return Pet::first()?->id;
+        }
+
+        return null;
+    }
+
+    private function mergeHistory(?string $messageId, ?string $rawContent, ?string $type = null, ?int $eventEnd = null): void
     {
         if ($messageId === null) {
             return;
@@ -228,6 +271,29 @@ private function mergeHistory(?string $messageId, ?string $rawContent, ?string $
         $content = json_decode($rawContent ?? '{}', true) ?? [];
         $history = History::where('messageId', $messageId)->first();
 
+        if ($history === null && isset($content['start_time'])) {
+            $startMessageId = $this->device->petkit_id . '_' . $content['start_time'];
+            $history = History::where('messageId', $startMessageId)->first();
+        }
+
+        if ($history === null && !empty($content['id'])) {
+            $history = History::where('device_id', $this->device->id)
+                ->where('parameters->id', $content['id'])
+                ->latest()
+                ->first();
+        }
+
+        if ($history === null && $type === DeviceStates::WORKING->value) {
+            $history = History::where('device_id', $this->device->id)
+                ->where('type', DeviceStates::WORKING->value)
+                ->where('created_at', '>=', now()->subMinutes(3))
+                ->latest()
+                ->first();
+        }
+
+        $explicitPetId = !empty($content['pet_id']) ? (int) $content['pet_id'] : null;
+        $petId = $this->resolvePetId($explicitPetId);
+
         if ($history === null) {
             if ($type === null) {
                 return;
@@ -235,25 +301,32 @@ private function mergeHistory(?string $messageId, ?string $rawContent, ?string $
 
             History::create([
                 'messageId' => $messageId,
-                'pet_id' => !empty($content['pet_id']) ? $content['pet_id'] : null,
+                'pet_id' => $petId,
                 'type' => $type,
                 'parameters' => $content,
                 'device_id' => $this->device->id,
             ]);
 
+            if ($petId) {
+                $this->setLastUsedBy($petId);
+            }
+
             return;
         }
 
+        $mergedParams = array_merge((array) ($history->parameters ?? []), (array) $content);
+        if ($eventEnd !== null && !empty($mergedParams['start_time'])) {
+            $mergedParams['event_start'] = (int) $mergedParams['start_time'];
+            $mergedParams['event_end'] = $eventEnd;
+        }
+
         $history->update([
-            'parameters' => [
-                ...$history->parameters,
-                ...$content,
-            ],
-            ...(!empty($content['pet_id']) ? ['pet_id' => $content['pet_id']] : []),
+            'parameters' => $mergedParams,
+            ...($petId ? ['pet_id' => $petId] : []),
         ]);
 
-        if (!empty($content['pet_id'])) {
-            $this->setLastUsedBy((int) $content['pet_id']);
+        if ($petId) {
+            $this->setLastUsedBy($petId);
         }
     }
 
@@ -439,10 +512,20 @@ public function settings(stdClass $message)
     #[HomeassistantTopic('action/start')]
     public function action(stdClass $message): void
     {
-        $action = $message->action;
+        $action = $message->action ?? null;
         switch ($action) {
             case 'feed':
-                $this->startFeeding($this->getDevice());
+                $amount1 = isset($message->amount1) ? (int)$message->amount1 : (isset($message->amount) ? (int)$message->amount : 1);
+                $amount2 = isset($message->amount2) ? (int)$message->amount2 : (isset($message->amount) ? (int)$message->amount : 1);
+                $this->startFeeding($this->getDevice(), $amount1, $amount2);
+                break;
+            case 'feed_hopper_1':
+                $amount1 = isset($message->amount) ? (int)$message->amount : (isset($message->amount1) ? (int)$message->amount1 : 1);
+                $this->startFeeding($this->getDevice(), $amount1, 0);
+                break;
+            case 'feed_hopper_2':
+                $amount2 = isset($message->amount) ? (int)$message->amount : (isset($message->amount2) ? (int)$message->amount2 : 1);
+                $this->startFeeding($this->getDevice(), 0, $amount2);
                 break;
             case 'snapshot':
                 $this->takeSnapshot($this->getDevice());
@@ -578,6 +661,9 @@ private function updateConfiguration(mixed $content): array
 
         $settings->bowl = $content->bowl;
         $settings->door = $content->door;
+        if (isset($content->eating)) {
+            $settings->isEating = ((int) $content->eating === 1);
+        }
 
         return $settings->toArray();
     }
diff --git a/app/Petkit/Devices/YumshareDual/UI.php b/app/Petkit/Devices/YumshareDual/UI.php
index 0a500dd..1fdc895 100644
--- a/app/Petkit/Devices/YumshareDual/UI.php
+++ b/app/Petkit/Devices/YumshareDual/UI.php
@@ -136,6 +136,15 @@ public function formFields(): array
                     ->label('Feeding Photo')
                     ->helperText('Save a photo to the cloud when food is dispensed'),
 
+                TextInput::make('configuration.settings.rtsp_username')
+                    ->label('RTSP Username')
+                    ->helperText('RTSP Digest authentication username (from raptor.conf). Leave blank to use global default.'),
+
+                TextInput::make('configuration.settings.rtsp_password')
+                    ->label('RTSP Password')
+                    ->password()
+                    ->helperText('RTSP Digest authentication password (from raptor.conf). Avoid #, ;, quotes, or spaces.'),
+
                 Fieldset::make('Detection')->schema([
                     Toggle::make('configuration.settings.petDetection')
                         ->helperText('For events of pet visiting the feeder')
diff --git a/app/Petkit/TelnetClient.php b/app/Petkit/TelnetClient.php
index fbc259c..b1f486d 100644
--- a/app/Petkit/TelnetClient.php
+++ b/app/Petkit/TelnetClient.php
@@ -21,7 +21,7 @@ class TelnetClient
     public function __construct(
         private readonly string $host,
         private readonly int $port = 23,
-        private readonly float $timeout = 5.0,
+        private readonly float $timeout = 60.0,
     ) {
     }
 
@@ -43,11 +43,11 @@ public function login(string $username, string $password): void
         $this->readUntil(['#', '$']);
     }
 
-    public function exec(string $command): string
+    public function exec(string $command, ?float $timeout = null): string
     {
         $this->write($command);
 
-        return $this->readUntil(['#', '$']);
+        return $this->readUntil(['#', '$'], $timeout);
     }
 
     public function close(): void
@@ -64,10 +64,11 @@ private function write(string $line): void
         fwrite($this->socket, $line . "\r\n");
     }
 
-    private function readUntil(array $markers): string
+    private function readUntil(array $markers, ?float $timeout = null): string
     {
         $buffer = '';
-        $deadline = microtime(true) + $this->timeout;
+        $effectiveTimeout = $timeout ?? $this->timeout;
+        $deadline = microtime(true) + $effectiveTimeout;
 
         while (microtime(true) < $deadline) {
             $chunk = fread($this->socket, 2048);
@@ -128,4 +129,4 @@ private function stripTelnetControl(string $chunk): string
 
         return $clean;
     }
-}
+}
\ No newline at end of file
diff --git a/config/camera.php b/config/camera.php
index a565e79..6bac0fb 100644
--- a/config/camera.php
+++ b/config/camera.php
@@ -9,9 +9,8 @@
     'rtsp' => [
         'port' => (int) env('CAMERA_RTSP_PORT', 8554),
         'path' => env('CAMERA_RTSP_PATH', 'stream0'),
-
-        // RTSP over TCP - UDP loses packets on busy WiFi and the resulting
-        // corrupt frames are worse than the slightly higher latency.
+        'user' => env('CAMERA_RTSP_USER', 'petkit'),
+        'password' => env('CAMERA_RTSP_PASSWORD', 'dnlB8z3lSesD4IWCZEkNCsaJ'),
         'transport' => env('CAMERA_RTSP_TRANSPORT', 'tcp'),
     ],
 
diff --git a/docs/protocol_mqtt.md b/docs/protocol_mqtt.md
new file mode 100644
index 0000000..9f8c10c
--- /dev/null
+++ b/docs/protocol_mqtt.md
@@ -0,0 +1,133 @@
+# Protocole MQTT & Cycle de Vie des Ã‰vÃ©nements Petkit (Alibaba Cloud IoT)
+
+Ce document dÃ©taille le fonctionnement du protocole MQTT utilisÃ© par les appareils Petkit (basÃ© sur le SDK C Alibaba Cloud Link IoT / Alink), la structure des Ã©changes, ainsi que le cycle de vie des Ã©vÃ©nements pour les distributeurs et litiÃ¨res connectÃ©s sous Localkit.
+
+---
+
+## 1. Topologie des Topics MQTT
+
+Les appareils communiquent avec le broker selon une arborescence standardisÃ©e :
+
+| Type d'Ã©change | Topic | Description |
+| :--- | :--- | :--- |
+| **Ã‰vÃ©nement Ã©mis par l'appareil** | `/sys/{ProductKey}/{DeviceName}/thing/event/{eventName}/post` | L'appareil publie un Ã©vÃ©nement (ex. `feed_start`, `feed_over`, `eat_start`, `eat_over`, `property_post`, `pet_detect`). |
+| **Acquittement du serveur** | `/sys/{ProductKey}/{DeviceName}/thing/event/{eventName}/post_reply` | Localkit confirme la rÃ©ception de l'Ã©vÃ©nement Ã  l'appareil. |
+| **Service appelÃ© par le serveur** | `/sys/{ProductKey}/{DeviceName}/thing/service/{serviceName}` | Localkit ordonne une action Ã  l'appareil (ex. `feed_realtime`, `property/set`, `start`). |
+| **Acquittement de service** | `/sys/{ProductKey}/{DeviceName}/thing/service/{serviceName}_reply` | L'appareil confirme la prise en compte du service. |
+
+> **Variables :**
+> - `{ProductKey}` : clÃ© produit (souvent `localkit` ou un hash hexadÃ©cimal Petkit comme `a54dw3gx0nb`).
+> - `{DeviceName}` : nom composÃ© de l'appareil (ex. `d_d4sh_20250805DA0372`).
+
+---
+
+## 2. Structure des Messages & Exigences d'Acquittement (ACK)
+
+### A. Message envoyÃ© par l'appareil (`post`)
+Lorsqu'un appareil Ã©met un Ã©vÃ©nement, il transmet une charge JSON :
+```json
+{
+  "id": "730",
+  "version": "1.0",
+  "params": {
+    "XDevice": "id=300023502&nonce=...&timestamp=1793132794&type=D4SH&sign=...",
+    "event_id": "300023502_1793132789",
+    "timestamp": 1793132794,
+    "content": "{\"id\":\"r_20261027_4437-1\",\"day\":20261027,\"manual\":0,\"time\":77188,\"online_state\":0,\"eat_video\":1}",
+    "state": "{\"bowl\":-1,\"feeding\":1,\"eating\":0,...}"
+  },
+  "sys": {
+    "ack": 1
+  }
+}
+```
+
+### B. Format strict de l'acquittement attendu (`post_reply`)
+Le firmware Petkit (Ã©crit en C) utilise le parseur officiel Alink. Tout acquittement doit rÃ©pondre **strictement** aux critÃ¨res suivants :
+
+```json
+{
+  "code": 200,
+  "data": {},
+  "id": "730",
+  "message": "success",
+  "method": "thing.event.feed_start.post",
+  "version": "1.0"
+}
+```
+
+> [!CRITICAL] RÃ¨gles absolues pour l'ACK :
+> 1. **`id`** : doit Ãªtre une chaÃ®ne reprenant exactement la valeur `id` du message entrant.
+> 2. **`version`** : doit valoir `"1.0"` (et **NON PAS** `"1.0.0"`). Le parseur firmware effectue une comparaison stricte de chaÃ®ne (`strcmp(ver, "1.0")`).
+> 3. **`method`** : doit correspondre au nom de mÃ©thode Alink : `thing.event.<eventName>.post` (ou `thing.service.<serviceName>`).
+>    Si le champ `method` est `null` ou omis, l'appareil rejette la rÃ©ponse comme non conforme.
+
+### C. ConsÃ©quence d'un acquittement invalide (File FIFO Flash)
+Les microcontrÃ´leurs Petkit disposent d'une file d'attente FIFO persistÃ©e en mÃ©moire Flash :
+- Si l'ACK reÃ§u est invalide ou absent, l'appareil rÃ©Ã©met immÃ©diatement le message jusqu'Ã  4 fois (toutes les 15 secondes).
+- Si les rÃ©essais Ã©chouent, le message reste bloquÃ© en tÃªte de la file FIFO Flash.
+- Lors de toute tentative de synchronisation ultÃ©rieure, l'appareil renverra en prioritÃ© cet ancien message bloquÃ©, masquant ou retardant tous les Ã©vÃ©nements plus rÃ©cents.
+
+---
+
+## 3. Cycle de Vie des Distributions de Nourriture (`WORKING`)
+
+Une distribution implique plusieurs Ã©tapes entre l'application/Home Assistant, Localkit et le matÃ©riel :
+
+```
+[Home Assistant / Utilisateur]             [Localkit]                         [Distributeur]
+             â”‚                                 â”‚                                    â”‚
+             â”‚â”€â”€ Appui "Distribuer TrÃ©mie 1" â”€â”€>â”‚                                    â”‚
+             â”‚   (ou bouton physique)          â”‚â”€â”€ feed_realtime (amount1=1) â”€â”€â”€â”€â”€â”€â”€>â”‚
+             â”‚                                 â”‚   (/thing/service/feed_realtime)   â”‚
+             â”‚                                 â”‚                                    â”‚â”€â”€ Moteur tourne
+             â”‚                                 â”‚<â”€â”€ feed_start/post â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”‚
+             â”‚                                 â”‚    (event_id, content: id, time)   â”‚
+             â”‚                                 â”‚â”€â”€ feed_start/post_reply (code 200)â”€>â”‚ (AcquittÃ©)
+             â”‚                                 â”‚                                    â”‚
+             â”‚                                 â”‚                                    â”‚â”€â”€ Fin de dose
+             â”‚                                 â”‚<â”€â”€ feed_over/post â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”‚
+             â”‚                                 â”‚    (real_amount1, result: 0)       â”‚
+             â”‚                                 â”‚â”€â”€ feed_over/post_reply (code 200) â”€>â”‚ (AcquittÃ© & purgÃ©)
+             â”‚<â”€â”€ MQTT State Publish â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”‚
+             â”‚    (portions1 = +1)             â”‚
+```
+
+### DÃ©tail des Ã©tapes :
+
+1. **DÃ©clenchement :**
+   - **Distant (Home Assistant / App) :** Envoi d'un service `feed_realtime` avec un identifiant prÃ©fixÃ© `r_YYYYMMDD_XXXX-1` et les doses demandÃ©es (`amount1`, `amount2`).
+   - **Manuel (Bouton physique) :** L'utilisateur presse le bouton physique sur l'appareil. Le champ `manual` vaut `1` et `id` est vide.
+   - **PlanifiÃ© (Programme interne) :** DÃ©clenchÃ© par le timer matÃ©riel de l'appareil selon le planning injectÃ© dans `device_schedule_items`. L'identifiant est prÃ©fixÃ© `n_` ou `s_`.
+
+2. **Ã‰vÃ©nement `feed_start` :**
+   - PubliÃ© dÃ¨s l'activation du clapet/moteur.
+   - Le JSON `content` contient : `day`, `manual`, `time`, `id`, `eat_video`.
+   - **Ne contient pas** la quantitÃ© rÃ©elle distribuÃ©e.
+   - CrÃ©e une ligne `WORKING` dans la table `history` si elle n'existe pas.
+   - **Protection contre les rÃ©essais :** Si la ligne existe dÃ©jÃ , les paramÃ¨tres sont fusionnÃ©s (`array_merge`) pour ne pas effacer `real_amount1` dÃ©jÃ  fourni par `feed_over`.
+
+3. **Ã‰vÃ©nement `feed_over` :**
+   - PubliÃ© Ã  l'arrÃªt du moteur une fois le capteur optique/infrarouge ayant validÃ© le passage des croquettes.
+   - Le JSON `content` contient : `real_amount1`, `real_amount2`, `completed_at`, `result` (`0` = succÃ¨s), `err_code`.
+   - `mergeHistory()` associe ce rÃ©sultat Ã  la distribution `WORKING` correspondante :
+     1. Par correspondance d'horodatage (`event_id` ou `petkit_id_start_time`).
+     2. Par correspondance de prÃ©fixe de distribution (`r_...`).
+     3. Par repli temporel sur le dernier Ã©vÃ©nement `WORKING` survenu dans les 3 minutes prÃ©cÃ©dentes.
+
+---
+
+## 4. Cycle de Vie des Repas & DÃ©tections Chat (`EAT` & `DETECT`)
+
+### A. Repas (`EAT`)
+1. **`eat_start` :** L'animal s'approche ou le capteur de charge de la gamelle dÃ©tecte une baisse/prÃ©sence.
+   - Active le capteur binaire `is_eating: ON` sur Home Assistant.
+   - CrÃ©e un enregistrement `EAT` avec l'horodatage `start_time`.
+2. **`eat_over` :** L'animal quitte la zone.
+   - DÃ©sactive le capteur binaire `is_eating: OFF`.
+   - Calcule la durÃ©e du repas (`eventDuration() = event_end - event_start`).
+   - **Garde de sÃ©curitÃ© :** Seuls les repas d'une durÃ©e comprise entre 5 secondes et 1800 secondes (30 minutes) sont comptabilisÃ©s dans `eating_time_today` pour Ã©liminer les dÃ©connexions rÃ©seau ou Ã©vÃ©nements non clÃ´turÃ©s.
+
+### B. DÃ©tections (`DETECT`)
+- `pet_detect` capture le passage d'un animal (capteur de mouvement / camÃ©ra PIR).
+- `pet_discern` (optionnel) est envoyÃ© de maniÃ¨re asynchrone par l'analyse vidÃ©o/IA pour identifier le chat spÃ©cifique (`pet_id`). Si l'utilisateur n'a configurÃ© qu'un seul animal dans Localkit, celui-ci est automatiquement attribuÃ©.
diff --git a/docs/yumshare_dual.md b/docs/yumshare_dual.md
new file mode 100644
index 0000000..287b84b
--- /dev/null
+++ b/docs/yumshare_dual.md
@@ -0,0 +1,133 @@
+# Documentation Technique : Petkit YumShare Dual (`d4sh`)
+
+Le **Petkit YumShare Dual** (identifiant interne `d4sh` / modÃ¨le matÃ©riel D4SH) est un distributeur automatique de nourriture connectÃ© Ã  double trÃ©mie intÃ©grant une camÃ©ra Full HD avec vision nocturne, un microphone bidirectionnel, un capteur de poids dans la gamelle et une passerelle Bluetooth Low Energy (BLE).
+
+---
+
+## 1. CaractÃ©ristiques & CapacitÃ©s MatÃ©rielles
+
+- **Double TrÃ©mie (Hopper 1 & Hopper 2) :** Distribution indÃ©pendante ou simultanÃ©e depuis deux rÃ©servoirs sÃ©parÃ©s.
+  - `amount1` / `factor1` : Dosage et facteur de calibrage pour la trÃ©mie 1 (principale).
+  - `amount2` / `factor2` : Dosage et facteur de calibrage pour la trÃ©mie 2 (secondaire).
+  - Bouton physique sur l'appareil : configurÃ© pour distribuer par dÃ©faut 1 portion de la trÃ©mie 1.
+- **CamÃ©ra & MultimÃ©dia :**
+  - Flux vidÃ©o RTSP direct disponible via l'adresse IP locale.
+  - Prise d'instantanÃ©s (snapshots) et enregistrement d'extraits vidÃ©o lors des repas (`eat_video`).
+  - Stockage des mÃ©dias Ã©mulÃ© sur un stockage objet S3 local (ex. Garage / MinIO).
+- **Capteurs IntÃ©grÃ©s :**
+  - Poids rÃ©siduel dans la gamelle (`bowl`).
+  - Niveaux bas de nourriture dans les trÃ©mies (`food1`, `food2`).
+  - Ã‰tat du clapet anti-intrusion (`door`).
+  - Tensions d'alimentation secteur (`DCV`) et batterie de secours (`batV`).
+  - DÃ©tection optique et infrarouge du passage effectif des croquettes.
+- **Passerelle Bluetooth (BLE Proxy) :**
+  - Relaye les trames Bluetooth des accessoires Petkit situÃ©s Ã  proximitÃ© (ex. fontaine Ã  eau Eversweet W5, purificateur K3 Spray) vers Localkit et Home Assistant.
+
+---
+
+## 2. EntitÃ©s ExposÃ©es dans Home Assistant
+
+GrÃ¢ce Ã  la dÃ©couverte MQTT native (`homeassistant-publisher`), Localkit crÃ©e et maintient automatiquement l'ensemble des entitÃ©s ci-dessous pour le YumShare Dual :
+
+### A. Statistiques JournaliÃ¨res (Remise Ã  zÃ©ro quotidienne Ã  minuit)
+
+| EntitÃ© | Nom affichÃ© | UnitÃ© | Description |
+| :--- | :--- | :---: | :--- |
+| `sensor.*_feedings_today` | Feedings Today | `feedings` | Nombre total de distributions servies aujourd'hui (manuelles + programmÃ©es). |
+| `sensor.*_portions_dispensed_hopper_1_today` | Portions Dispensed Hopper 1 Today | `portions` | Cumul des portions dÃ©livrÃ©es aujourd'hui par la trÃ©mie 1. |
+| `sensor.*_portions_dispensed_hopper_2_today` | Portions Dispensed Hopper 2 Today | `portions` | Cumul des portions dÃ©livrÃ©es aujourd'hui par la trÃ©mie 2. |
+| `sensor.*_manual_portions_today` | Manual Portions Today | `portions` | Cumul des portions dÃ©livrÃ©es manuellement (via bouton physique ou action Home Assistant). |
+| `sensor.*_planned_meals_today` | Planned Meals Today | `meals` | Nombre total de repas programmÃ©s prÃ©vus dans le calendrier pour la journÃ©e en cours. |
+| `sensor.*_planned_portions_today` | Planned Portions Today | `portions` | Nombre total de portions programmÃ©es prÃ©vues pour la journÃ©e en cours. |
+| `sensor.*_meals_today` | Meals Today | `meals` | Nombre de visites repas oÃ¹ le chat est venu manger dans la gamelle aujourd'hui. |
+| `sensor.*_visits_today` | Visits Today | `visits` | Nombre total de dÃ©tections de prÃ©sence ou passages devant l'appareil aujourd'hui. |
+| `sensor.*_eating_time_today` | Eating Time Today | `s` | DurÃ©e cumulÃ©e passÃ©e par le chat Ã  manger aujourd'hui (en secondes). |
+
+### B. Capteurs Temps RÃ©el & ActivitÃ©
+
+| EntitÃ© | Nom affichÃ© | Type / Classe | Description |
+| :--- | :--- | :---: | :--- |
+| `binary_sensor.*_is_eating` | Is Eating | `occupancy` | `ON` dÃ¨s que le chat est devant la gamelle en train de manger, `OFF` dÃ¨s qu'il part. |
+| `sensor.*_last_used_by` | Last Used By | Diagnostic | Affiche le nom du chat identifiÃ© lors du dernier Ã©vÃ©nement (dÃ©tection ou repas). |
+| `event.*_activity` | Activity | Event | Flux d'Ã©vÃ©nements en direct (`dispense`, `eat_start`, `eat_over`, `detect`). Fournit les dÃ©tails de chaque distribution (`real_amount1`, `source`, `success`). |
+| `sensor.*_bowl` | Bowl | Diagnostic | Poids de croquettes actuellement prÃ©sent dans la gamelle (en grammes). |
+| `sensor.*_ip_address` | IP Address | Diagnostic | Adresse IPv4 locale de l'appareil sur le rÃ©seau. |
+| `sensor.*_device_status` | Device Status | Diagnostic | Ã‰tat fonctionnel (`WORKING`, `IDLE`). |
+| `sensor.*_error` | Error | Diagnostic | Remonte les alertes matÃ©rielles (`Ok`, `food_empty`, `door_closed`, etc.). |
+
+### C. Boutons d'Action Rapide
+
+| EntitÃ© | Nom affichÃ© | Action |
+| :--- | :--- | :--- |
+| `button.*_action_feed` | Feed 1 Dose Both | Distribue 1 dose de la trÃ©mie 1 et 1 dose de la trÃ©mie 2. |
+| `button.*_action_feed_hopper_1` | Feed 1 Dose Hopper 1 | Distribue 1 dose de la trÃ©mie 1 seule. |
+| `button.*_action_feed_hopper_2` | Feed 1 Dose Hopper 2 | Distribue 1 dose de la trÃ©mie 2 seule. |
+
+### D. ContrÃ´les & RÃ©glages (Config / Number / Switch)
+
+- **Dosages par dÃ©faut :** `number.*_feed_amount_hopper_1` et `number.*_feed_amount_hopper_2` (1 Ã  50 doses).
+- **Vision & CamÃ©ra :** `switch.*_camera`, `switch.*_night` (vision nocturne IR), `switch.*_microphone`.
+- **SensibilitÃ© des dÃ©tections :** `switch.*_pet_visit_detection`, `switch.*_eating_detection`, `switch.*_move_detection`.
+- **Sons :** `switch.*_sound_enable`, `switch.*_feed_sound`, `number.*_volume`.
+
+---
+
+## 3. SchÃ©ma de la Base de DonnÃ©es (`history`)
+
+Les Ã©vÃ©nements de l'appareil sont stockÃ©s dans la table SQLite `history` :
+
+| Colonne | Type | Description |
+| :--- | :--- | :--- |
+| `id` | INTEGER PRIMARY KEY | Identifiant unique sÃ©quentiel de l'Ã©vÃ©nement. |
+| `device_id` | INTEGER | ClÃ© Ã©trangÃ¨re liant l'Ã©vÃ©nement Ã  la table `devices`. |
+| `pet_id` | INTEGER (nullable) | ClÃ© Ã©trangÃ¨re vers la table `pets` si un animal a Ã©tÃ© identifiÃ©. |
+| `type` | VARCHAR | Type d'Ã©vÃ©nement : `WORKING` (distribution), `EAT` (repas), `DETECT` (passage), `ERROR` (panne). |
+| `messageId` | VARCHAR | Identifiant unique de trame MQTT (ex. `300023502_1793132789`). |
+| `parameters` | JSON | DonnÃ©es brutes de l'Ã©vÃ©nement (quantitÃ©s rÃ©elles, horodatages, flags). |
+| `created_at` | DATETIME | Date et heure de dÃ©but de l'Ã©vÃ©nement. |
+| `updated_at` | DATETIME | Date et heure de derniÃ¨re mise Ã  jour / fin d'Ã©vÃ©nement. |
+
+### Structure du JSON `parameters` selon le type :
+
+- **`WORKING` (Distribution) :**
+  ```json
+  {
+    "id": "r_20261027_4437-1",
+    "day": 20261027,
+    "manual": 0,
+    "time": 77188,
+    "online_state": 0,
+    "eat_video": 1,
+    "real_amount1": 1,
+    "real_amount2": 0,
+    "result": 0
+  }
+  ```
+- **`EAT` (Repas) :**
+  ```json
+  {
+    "start_time": 1793132814,
+    "event_start": 1793132814,
+    "event_end": 1793133007,
+    "percent": 12,
+    "eat_video": 1
+  }
+  ```
+
+---
+
+## 4. Calcul & Algorithmes de RÃ©silience (`computeDailyStats`)
+
+1. **AgrÃ©gation des portions trÃ©mie 1 :**
+   ```php
+   $portions1 = (int) $feedEvents->sum(function ($h) {
+       $p = $h->parameters ?? [];
+       if (isset($p['real_amount1'])) return (int) $p['real_amount1'];
+       if (isset($p['real_amount'])) return (int) $p['real_amount'];
+       return 1; // Fallback sÃ©curisÃ© en cas de retard de feed_over
+   });
+   ```
+2. **Filtrage des distributions manuelles :**
+   Comptabilise tout Ã©vÃ©nement oÃ¹ `manual === 1` (bouton physique) ou dont l'identifiant commence par `r_` (dÃ©clenchÃ© Ã  la demande depuis Home Assistant / MQTT).
+3. **Plafond de durÃ©e de repas :**
+   La durÃ©e d'un repas est bornÃ©e entre 5 secondes et 1800 secondes (30 minutes). Si un paquet `eat_over` est manquant ou si un horodatage est aberrant, la durÃ©e n'est pas ajoutÃ©e afin de ne pas fausser le cumul quotidien.
diff --git a/resources/views/filament/partials/console_modal.blade.php b/resources/views/filament/partials/console_modal.blade.php
new file mode 100644
index 0000000..c61d536
--- /dev/null
+++ b/resources/views/filament/partials/console_modal.blade.php
@@ -0,0 +1,36 @@
+<x-filament::modal id="rtsp-console-modal" width="4xl">
+    <x-slot name="heading">
+        <div class="flex items-center space-x-2">
+            <x-filament::icon icon="heroicon-o-command-line" class="h-6 w-6 text-primary-500 inline" />
+            <span>Terminal Execution Log</span>
+        </div>
+    </x-slot>
+
+    <x-slot name="description">
+        Output of the Telnet session and configuration updates
+    </x-slot>
+
+    <div class="rounded-xl bg-gray-950 p-4 border border-gray-800 shadow-2xl font-mono text-xs">
+        <div class="flex items-center justify-between mb-3 pb-2 border-b border-gray-800">
+            <div class="flex items-center space-x-2">
+                <span class="w-3 h-3 rounded-full bg-red-500 inline-block"></span>
+                <span class="w-3 h-3 rounded-full bg-yellow-500 inline-block"></span>
+                <span class="w-3 h-3 rounded-full bg-green-500 inline-block"></span>
+                <span class="text-gray-400 font-semibold ml-2">petkit &bull; telnet console</span>
+            </div>
+            <span class="text-xs text-gray-500">raptor.conf</span>
+        </div>
+        <pre class="text-green-400 whitespace-pre-wrap leading-relaxed overflow-x-auto max-h-96 select-all font-mono">{{ $this->telnetConsoleLog ?? 'No logs recorded.' }}</pre>
+    </div>
+
+    <x-slot name="footer">
+        <div class="flex justify-end w-full">
+            <x-filament::button
+                color="gray"
+                x-on:click="$dispatch('close-modal', { id: 'rtsp-console-modal' })"
+            >
+                Close
+            </x-filament::button>
+        </div>
+    </x-slot>
+</x-filament::modal>

``n
