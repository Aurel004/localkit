# Rapport Technique Complet : Modifications, Débogage et Sécurisation LocalKit & PetKit

Ce document récapitule l'ensemble des interventions, diagnostics, modifications de code et ajustements système réalisés pour l'intégration complète et locale des équipements **PetKit** (Distributeur *YumShare Dual D4SH* et Fontaine *Eversweet 3 Pro W5*) avec **LocalKit** (branche `beta`), **Home Assistant**, **Frigate** et **Scrypted**.

---

## 1. Contexte & Architecture Matérielle

* **Branche & Version LocalKit** : Basé sur la branche et l'image Docker **`beta`** (`ghcr.io/dwyschka/localkit:beta` dans `compose.yml`).
* **Distributeur de croquettes** : PetKit YumShare Dual (`D4SH` Gen 1, processeur Ingenic T31 / MIPS-ARM, firmware `D4SH-261301`, IP `10.0.2.32`, PetKit ID `300023502`, SN `20250805DA0372`).
* **Fontaine à eau** : PetKit Eversweet 3 Pro (`W5`, Bluetooth BLE MAC `a4c138461e98`, PetKit ID `400058602`, Secret BLE `515c551ef392`, SN `20250411S11573`).
* **Serveur Docker / LocalKit** : VM `192.168.0.203` (port SSH 354, conteneurs `localkit` sur MacVLAN `10.0.2.10` et `localkit-broker` sur `10.0.2.11`).
* **Home Assistant** : Hôte `192.168.0.158` (Broker MQTT Mosquitto sur port 1883).
* **NVR Frigate** : `https://frigate2.aurelienruat.fr` (backend go2rtc sur `10.0.5.156:8971` / `10.0.5.156:1984`).
* **DNS Local** : AdGuard Home IoT (`10.0.2.66`).

---

## 2. Diagnostics des Pannes & Solutions Trouvées

### 2.1. Confusion de modèle & Script de Flash du Distributeur (D4SH Gen 1 vs Gen 2)
* **Problème initial** : La documentation officielle de LocalKit (`localkit.io/devices/yumshare-dual2.html`) préconisait la commande :
  ```sh
  wget -qO- http://tool.localkit.io/scripts/d4sh2/2.0.0/install | sh
  ```
  Lors de son exécution, le script échouait immédiatement avec l'erreur :
  ```
  === device ===
  [FAIL] cannot read firmwareVer from /opt/version -- is /opt mounted?
  ```
* **Cause** : Le script `d4sh2` est exclusivement prévu pour le matériel **Gen 2** (SoC Axera), qui monte ses binaires dans `/opt/`. Le distributeur en place est un **Gen 1** (SoC Ingenic T31, firmware `D4SH-261301`), dont l'arborescence utilise `/app/` et `/system/` (le répertoire `/opt` n'existant pas).
* **Script fonctionnel utilisé** : Le script d'installation adapté au D4SH Gen 1 est :
  👉 **`wget -qO- http://tool.localkit.io/scripts/d4h/2.0.0/install | sh`**
  Ce script télécharge `app.img` (1,8 Mo), installe les démons `rpd` (pipeline vidéo), `rsd` (serveur RTSP) et les scripts d'initialisation dans `/system/localkit/`.

### 2.2. Le Bug de Timeout du Provisioning Web UI vs la Connexion MQTT Post-Reboot
Deux blocages successifs et bien distincts sont survenus lors du déploiement :

1. **Le bug de faux échec lors du Provisioning dans l'interface Web (`TelnetClient.php`)** :
   * *Constat* : Dans l'assistant de provisioning Filament de LocalKit, la commande de flash se soldait par une erreur `Telnet read timed out waiting for one of: #/$`.
   * *Cause* : Dans la classe `App\Petkit\TelnetClient`, le timeout était codé en dur à **5.0 secondes**. Or, le téléchargement de l'archive `app.img` (1,8 Mo) via la puce Wi-Fi 2.4 GHz du distributeur prenait entre 15 et 25 secondes. L'assistant web déclarait le provisioning en erreur alors que le script continuait de s'exécuter et flashait parfaitement le firmware en arrière-plan sur la machine !
   * *Correctif* : Augmentation du timeout par défaut de `TelnetClient` à **60.0 secondes** et passage d'un paramètre `$timeout` optionnel dans `exec()`.

2. **Le statut "Disconnected" post-reboot (Résolution DNS MQTT)** :
   * *Constat* : Après le reboot du distributeur, l'appareil restait affiché avec le badge orange **Disconnected** dans la Web UI et n'apparaissait pas dans Home Assistant.
   * *Cause* : Les requêtes HTTP du distributeur arrivaient bien sur `localkit` (`10.0.2.10`), mais le démon système `ctrl` tente de contacter son broker MQTT sur `localkit.iot-as-mqtt.eu-central-1.aliyuncs.com`.
   * *Résolution* : L'enregistrement DNS dans AdGuard Home (`10.0.2.66`) redirigeant `localkit.iot-as-mqtt.eu-central-1.aliyuncs.com` vers `10.0.2.11` (`localkit-broker`) a permis, dès le reboot du distributeur, une connexion MQTT instantanée (`Client connected: localkit.d_d4sh_20250805DA0372`).

### 2.3. Fontaine Eversweet 3 Pro (W5) : Extraction de l'ID & Secret BLE
* **Problème de la documentation & de l'outil cloud** :
  * La documentation officielle de LocalKit (`bluetooth-devices/w5.html`) mentionne la nécessité d'un proxy BLE mais n'explique nulle part comment se procurer la clé secrète (`secret`).
  * L'outil officiel web `tool.localkit.io/cloud` a levé l'exception non gérée `Class "PetKit\Exceptions\InvalidHttpResponseException" not found`, et lorsqu'il répond, il n'expose que le `petkit_id`, **sans le `secret`**.
  * Dans Home Assistant, le stockage local standard `/config/.storage/core.config_entries` ne contenait aucun secret d'appareil.
* **Méthode d'extraction fonctionnelle** :
  1. Aller dans **Home Assistant** -> **Paramètres** -> **Appareils et services** -> **Petkit**.
  2. Cliquer sur la **Fontaine à eau** (*Eversweet 3 Pro*).
  3. Cliquer sur le menu `⋮` (trois points) en haut à droite -> **Télécharger les données de diagnostic** (*Download diagnostics*).
  4. Dans le fichier JSON téléchargé, sous la section `"device"`, se trouvent les données brutes fournies par l'API Petkit :
     * **`"id": 400058602`** (Petkit ID)
     * **`"secret": "515c551ef392"`** (Clé de chiffrement BLE)

### 2.4. Incompatibilité du Relais Bluetooth du Firmware (`libbase.so` / `cJSON`)
Même avec le bon secret, le distributeur renvoyait `result: 6` (timeout BLE) en tentant de relayer la fontaine :
1. **Format de l'adresse MAC** : Le firmware C du distributeur (`pk_hexstr_MAC_to_num()`) calcule `strlen(str) / 2 == 6` et exige strictement **12 caractères hexadécimaux** sans deux-points `:`. L'adresse standard `a4:c1:38:46:1e:98` faisait échouer la fonction et initialisait la cible en `000000000000`.
2. **Type de données JSON de l'ID** : LocalKit envoyait `"id": "400058602"` (chaîne). La fonction `cJSON` du firmware lit `item->valueint`, qui évaluait à `0`.
* *Correctif* : Ajout d'un mutateur `setMacAttribute` dans [`BluetoothDevice.php`](file:///var/www/html/app/Models/BluetoothDevice.php) et cast explicite `(int)` de l'identifiant dans les ressources HTTP et MQTT.

### 2.5. Flux Vidéo : Ralenti x0.5 & Synchronisation dynamique
* **Origine du ralenti x0.5** : En vision nocturne infrarouge (`night: True`), le capteur CMOS Ingenic T31 allonge son temps d'exposition et chute mécaniquement à **~11 fps** (mesuré précisément à 43 images en 4,02 s = 10,75 fps).
* Le démon `rpd` envoyait des horodatages PTS basés sur un framerate fixe de 25 fps, provoquant un étirement vidéo à demi-vitesse sur VLC et Frigate.
* *Correctif* : Configuration de `raptor.conf` sous `[petkit]` :
  ```ini
  [petkit]
  timestamps = arrival
  fps = 0
  ```

### 2.6. Bouton « Configure RTSP (Telnet) » & Fenêtre Console Modale Automatique
Pour une ergonomie optimale et éviter d'encombrer la page d'édition individuelle d'un appareil, la fonctionnalité a été intégrée sur la page principale des appareils ([`ListDevices.php`](file:///var/www/html/app/Filament/Resources/DeviceResource/Pages/ListDevices.php)), aux côtés du bouton officiel « Provision Device » :
* **Suppression du bouton parasite « RTSP Console »** : Un bouton isolé « RTSP Console » apparaissait auparavant dans le header car déclaré comme action indépendante. Il a été totalement éliminé de `getHeaderActions()`.
* **Formulaire de configuration** :
  * **Sélecteur d'appareil** : Déroulant automatique des équipements caméra compatibles (`isNextGen()`).
  * **RTSP Username** : Sans valeur par défaut imposée, avec hint `myuser`, filtré en alphanumérique (`[A-Za-z0-9_-]`).
  * **RTSP Password** : Masqué et révélable, limité strictement à 64 caractères (`maxLength(64)`) et validé par regex (`^[A-Za-z0-9_.-]*$`) pour empêcher tout caractère corrupteur pour le parseur INI (`#`, `;`, `:`, guillemets, espaces).
  * **Timestamp Mode** : Menu propre sans option vide sélectionnable (`selectablePlaceholder(false)`), avec la mention claire `none (default: firmware PTS ticks - causes 0.5x slow-motion)` et `arrival (recommended: dynamic arrival timestamps - fixes 0.5x slow-motion)`.
* **Fenêtre Console Modale Automatique (`console_modal.blade.php`)** :
  * Lors de la soumission du formulaire, LocalKit exécute les commandes via Telnet, enregistre la trace d'exécution dans la propriété Livewire `$this->telnetConsoleLog`, et déclenche l'événement d'ouverture de la modale (`open-modal` avec l'ID `rtsp-console-modal`).
  * La fenêtre modale s'ouvre automatiquement au centre de l'écran avec une interface de terminal sombre (pastilles de couleur macOS/Linux, texte vert monospace `bg-gray-950 text-green-400 font-mono`) affichant le déroulé complet pas à pas : connexion, injection sécurisée des identifiants dans `raptor.conf`, redémarrage du démon `raptor.sh` et mise à jour de la base de données.
  * Un bouton **Close** permet de fermer la console une fois la lecture terminée.

### 2.7. Statistiques Journalières & Entités Avancées pour YumShare Dual (`Configuration.php`)
* **Problème initial** : Par rapport à l'intégration officielle PetKit Cloud, Localkit ne remontait aucune statistique journalière (nombre de repas servis, nombre de visites du chat, temps passé à manger, répartition par trémie). De plus, l'état "en train de manger" n'était pas exposé en direct.
* **Nouvelles entités intégrées à Home Assistant** :
  1. `sensor.distributeur_de_croquettes_feedings_today` : Nombre total de distributions servies aujourd'hui (manuelles + programmées).
  2. `sensor.distributeur_de_croquettes_portions_dispensed_hopper_1_today` : Portions distribuées aujourd'hui par la trémie 1.
  3. `sensor.distributeur_de_croquettes_portions_dispensed_hopper_2_today` : Portions distribuées aujourd'hui par la trémie 2.
  4. `sensor.distributeur_de_croquettes_manual_portions_today` : Portions distribuées manuellement (bouton physique ou déclenchement à distance Home Assistant).
  5. `sensor.distributeur_de_croquettes_planned_meals_today` : Repas planifiés prévus aujourd'hui selon le planning.
  6. `sensor.distributeur_de_croquettes_planned_portions_today` : Portions planifiées prévues aujourd'hui.
  7. `sensor.distributeur_de_croquettes_meals_today` : Nombre de visites repas où le chat est venu manger (`EAT`).
  8. `sensor.distributeur_de_croquettes_visits_today` : Nombre total de détections de présence devant l'appareil (`DETECT`).
  9. `sensor.distributeur_de_croquettes_eating_time_today` : Temps cumulé passé par le chat à manger aujourd'hui (en secondes).
  10. `binary_sensor.distributeur_de_croquettes_is_eating` : Capteur binaire temps réel (`occupancy` / `cat`), passant à `ON` dès que le chat mange et `OFF` dès qu'il part.
  11. `sensor.distributeur_de_croquettes_last_used_by` : Nom du chat ayant utilisé l'appareil en dernier (avec repli automatique sur le chat configuré si le profil est unique).
  12. `event.distributeur_de_croquettes_activity` : Flux d'activité temps réel (`dispense`, `eat_start`, `eat_over`, `detect`).
* **Calcul dynamique `computeDailyStats()`** :
  Agrège les événements du jour depuis minuit dans la table `history` selon le fuseau horaire local (`Europe/Paris`), rafraîchi à chaque mise à jour d'état ou événement.

### 2.8. Le Bug Critique de la Conformité d'Acquittement MQTT (Alink / Alibaba Cloud Link IoT)
* **Constat & Symptômes** :
  - Le distributeur réémettait ses messages en boucle toutes les 15 secondes (jusqu'à 4 tentatives consécutives).
  - À chaque cycle de distribution, le distributeur renvoyait systématiquement un ancien paquet `feed_over` datant du 25 octobre (`r_20261025_1191-1`), bloquant l'envoi des fins de distribution ultérieures.
* **Cause profonde** :
  - Le firmware PetKit (basé sur le SDK C Alibaba Cloud Link IoT) exige une réponse d'acquittement strictement conforme :
    ```json
    {"code":200,"data":{},"id":"<id>","message":"success","method":"thing.event.<event>.post","version":"1.0"}
    ```
  - Dans Localkit d'origine :
    1. Le champ `method` valait `null` car non extrait du message entrant.
    2. Le champ `version` valait `"1.0.0"` au lieu de `"1.0"`. Le firmware effectue un `strcmp(version, "1.0")` qui échouait.
  - Rejetant l'acquittement, le microcontrôleur conservait le message non acquitté dans sa file FIFO persistée en mémoire Flash, bloquant le traitement normal.
* **Correctif apporté** :
  - Refonte de `app/Http/Resources/MQTT/SuccessResource.php` : forçage de `'version' => '1.0'` et acceptation du paramètre explicite `$method`.
  - Refonte de `app/MQTT/GenericReply.php` : résolution dynamique de la méthode via `MQTTTopic::topic2Method($topic)` préfixée de `thing.` (`thing.event.<event>.post` ou `thing.service.<service>`).

### 2.9. Bug de l'Écrasement des Paramètres de Distribution (`feed_start` vs `feed_over`)
* **Constat** :
  - Dans Home Assistant, `Portions Dispensed Hopper 1 Today` restait bloqué à `1` et `Manual Portions Today` à `0`, alors que 5 distributions avaient été effectuées dans la journée (01:02, 07:07, 12:34, 19:05, 21:26).
* **Cause profonde** :
  1. Lors d'une distribution, `feed_over` arrivait et écrivait `real_amount1: 1`. Mais les renvois successifs de `feed_start` exécutaient `History::updateOrCreate(['messageId' => $event_id], ['parameters' => $content])`.
  2. Le payload de `feed_start` ne contenant pas `real_amount1`, cette écriture **effaçait brutalement** la quantité réelle enregistrée par `feed_over` !
  3. Dans `computeDailyStats()`, la somme des portions manquantes évaluait à `0` par défaut, ne comptabilisant que le seul événement (01:02) n'ayant pas subi de réémission ultérieure.
  4. Pour `manualPortionsToday`, les distributions à distance déclenchées via Home Assistant ou l'App utilisaient le préfixe `r_` avec `manual: 0`, et n'étaient donc pas incluses par le filtre `manual === 1`.
* **Correctif apporté** :
  - Dans `app/Petkit/Devices/YumshareDual/Device.php` : `feed_start` effectue désormais une fusion sécurisée (`array_merge`) avec les paramètres déjà existants, interdisant la suppression de `real_amount1` ou `result`.
  - Dans `mergeHistory()` : ajout d'un matching par préfixe de feed (`parameters->id == $content['id']`) et d'un repli temporel associant `feed_over` au dernier événement `WORKING` survenu dans les 3 dernières minutes.
  - Dans `app/Petkit/Devices/YumshareDual/Configuration.php` :
    - Fallback sécurisé : si `real_amount1` n'est pas encore renseigné, 1 portion est comptabilisée par défaut pour la trémie 1 (car toute distribution sur le D4SH délivre au moins 1 dose).
    - Inclusion des distributions manuelles physiques (`manual === 1`) et distantes (`str_starts_with($id, 'r_')`) dans `manualPortionsToday`.
  - Rattrapage en base SQLite des 4 distributions du 27 octobre (07:07, 12:34, 19:05, 21:26) avec `real_amount1 = 1`.

### 2.10. Intégrité des Horodatages de Repas & Plafond de Sécurité
* **Constat** : Le temps cumulé passé à manger (`eating_time_today`) affichait une valeur aberrante (1h47 pour un seul chat).
* **Cause profonde** :
  - Un `update(['pet_id' => 1])` global via Eloquent avait mis à jour la colonne `updated_at` à l'heure courante sur l'ensemble des 44 lignes de la table `history`.
  - La méthode `eventDuration()` calculait `created_at->diffInSeconds(updated_at)`, ce qui mesurait le temps écoulé entre la création de chaque ligne et l'heure de la mise à jour massive !
* **Correctif apporté** :
  - Restauration des horodatages `updated_at` réels depuis l'archive de sauvegarde.
  - Stockage explicite de `event_start` et `event_end` dans les paramètres JSON lors de `eat_over`.
  - Désactivation systématique des timestamps automatiques (`$timestamps = false`) lors de toute mise à jour corrective.
  - Ajout d'un garde-fou dans `computeDailyStats()` : un repas doit être compris entre 5 secondes et 1800 secondes (30 minutes) pour être pris en compte, filtrant ainsi tout événement corrompu ou non clôturé.

---

## 3. Synthèse des Fichiers Modifiés dans LocalKit

| Fichier | Modification apportée |
| :--- | :--- |
| [`app/Filament/Resources/DeviceResource/Pages/ListDevices.php`](file:///var/www/html/app/Filament/Resources/DeviceResource/Pages/ListDevices.php) | Action header `Configure RTSP (Telnet)` avec formulaire validé, suppression du bouton header orphelin et affichage automatique du terminal en modale de page. |
| [`resources/views/filament/partials/console_modal.blade.php`](file:///var/www/html/resources/views/filament/partials/console_modal.blade.php) | Composant Blade modale `<x-filament::modal>` pour le rendu plein écran du terminal d'exécution Telnet. |
| [`app/Petkit/TelnetClient.php`](file:///var/www/html/app/Petkit/TelnetClient.php) | Timeout par défaut porté à 60s et support du timeout dynamique dans `exec()` pour fiabiliser le flash. |
| [`app/Models/BluetoothDevice.php`](file:///var/www/html/app/Models/BluetoothDevice.php) | Mutateur `setMacAttribute` nettoyant les `:` pour conformité avec `pk_hexstr_MAC_to_num()`. |
| [`app/Http/Resources/DevBleDeviceResource.php`](file:///var/www/html/app/Http/Resources/DevBleDeviceResource.php) | Cast de `id` en `(int)` pour le parseur `cJSON` du firmware. |
| [`app/Http/Resources/MQTT/DevBLEDevice.php`](file:///var/www/html/app/Http/Resources/MQTT/DevBLEDevice.php) | Cast de `id` en `(int)` pour les trames MQTT de découverte BLE. |
| [`app/Http/Resources/MQTT/SuccessResource.php`](file:///var/www/html/app/Http/Resources/MQTT/SuccessResource.php) | Formatage strict de l'acquittement Alink (`version: '1.0'`, `method` dynamique). |
| [`app/MQTT/GenericReply.php`](file:///var/www/html/app/MQTT/GenericReply.php) | Résolution automatique de la méthode Alink depuis le topic MQTT (`thing.event...` / `thing.service...`). |
| [`app/Petkit/Devices/YumshareDual/Configuration.php`](file:///var/www/html/app/Petkit/Devices/YumshareDual/Configuration.php) | Ajout des boutons individuels de trémie, des 9 capteurs de statistiques journalières, du capteur binaire `is_eating` et du fallback résilient sur les portions. |
| [`app/Petkit/Devices/YumshareDual/Device.php`](file:///var/www/html/app/Petkit/Devices/YumshareDual/Device.php) | Prise en charge des doses simples par trémie, fusion sécurisée `feed_start` sans écrasement, association avancée `feed_over` et attribution de pet_id unique. |
| [`app/Petkit/Devices/YumshareDual/UI.php`](file:///var/www/html/app/Petkit/Devices/YumshareDual/UI.php) | Ajout des champs de saisie RTSP Username et RTSP Password dans la section Caméra de la Web UI. |
| [`app/Management/Rtsp.php`](file:///var/www/html/app/Management/Rtsp.php) | Support d'identifiants RTSP par appareil avec repli sur la configuration globale. |
| [`config/camera.php`](file:///var/www/html/config/camera.php) | Déclaration des clés d'environnement `CAMERA_RTSP_USER` et `CAMERA_RTSP_PASSWORD`. |
| [`docs/protocol_mqtt.md`](file:///var/www/html/docs/protocol_mqtt.md) | Guide technique de référence du protocole MQTT Alink / Alibaba Cloud Link IoT et du cycle de vie des événements. |
| [`docs/yumshare_dual.md`](file:///var/www/html/docs/yumshare_dual.md) | Spécification complète du distributeur YumShare Dual, architecture matérielle, entités Home Assistant et schéma de base de données. |
| [`README.md`](file:///var/www/html/README.md) | Mise à jour des liens de documentation et des capacités d'intégration du distributeur. |

---

## 4. Audit Cybersécurité : Sécurisation de Telnet sur le VLAN IoT

Le protocole Telnet tourne en écoute sur le port 23 avec l'utilisateur `root` et le mot de passe d'usine Petkit `while(&P` (lancé par `/etc/init.d/rcS` dans la partition SquashFS en lecture seule).

### Analyse du risque & Limites de l'isolation d'équipement :
L'isolation d'équipement au niveau point d'accès Wi-Fi (Client Isolation) présente des effets de bord majeurs :
* Elle empêche toute communication directe entre équipements IoT légitimes au sein du même VLAN.
* Elle nécessite la création d'exceptions complexes pour le serveur DNS local, le serveur LocalKit, le serveur RTSP/NVR et les éventuels ponts domotiques.

### Stratégie de sécurisation retenue & validée :

1. **Règle de Pare-Feu Routeur (Solution Privilégiée & Recommandée)** :
   * Créer une règle de pare-feu sur le routeur bloquant tout trafic entrant vers le port 23 (`10.0.2.32:23`).
   * **Fonctionnement à la demande** : Laisser cette règle active en permanence. Le port Telnet est ainsi totalement inaccessible 99,9 % du temps par tout appareil du réseau ou du VLAN.
   * Lorsqu'une opération de maintenance ou de reconfiguration Telnet est requise, il suffit de désactiver temporairement la règle ou d'autoriser sélectivement l'IP d'administration (`192.168.0.x`) et LocalKit (`10.0.2.10`).
   * *Avantages* : Zéro risque de blocage matériel accidentel (soft-brick), aucun fichier modifié sur le firmware d'origine et gestion centralisée immédiate depuis l'interface du routeur.

2. **Suggestions optionnelles pour la communauté (Pull Request)** :
   * Documenter en suggestion optionnelle pour les utilisateurs avertis la possibilité de surcharger le mot de passe root en RAM via `mount --bind /system/localkit/files/shadow /etc/shadow` dans `07_start_app.sh`, ou de modifier le port d'écoute de `telnetd`, sans appliquer ces changements par défaut afin de préserver la compatibilité ascendante.
