# Documentation Technique : Petkit YumShare Dual (`d4sh`)

Le **Petkit YumShare Dual** (identifiant interne `d4sh` / modèle matériel D4SH) est un distributeur automatique de nourriture connecté à double trémie intégrant une caméra Full HD avec vision nocturne, un microphone bidirectionnel, un capteur de poids dans la gamelle et une passerelle Bluetooth Low Energy (BLE).

---

## 1. Caractéristiques & Capacités Matérielles

- **Double Trémie (Hopper 1 & Hopper 2) :** Distribution indépendante ou simultanée depuis deux réservoirs séparés.
  - `amount1` / `factor1` : Dosage et facteur de calibrage pour la trémie 1 (principale).
  - `amount2` / `factor2` : Dosage et facteur de calibrage pour la trémie 2 (secondaire).
  - Bouton physique sur l'appareil : configuré pour distribuer par défaut 1 portion de la trémie 1.
- **Caméra & Multimédia :**
  - Flux vidéo RTSP direct disponible via l'adresse IP locale.
  - Prise d'instantanés (snapshots) et enregistrement d'extraits vidéo lors des repas (`eat_video`).
  - Stockage des médias émulé sur un stockage objet S3 local (ex. Garage / MinIO).
- **Capteurs Intégrés :**
  - Poids résiduel dans la gamelle (`bowl`).
  - Niveaux bas de nourriture dans les trémies (`food1`, `food2`).
  - État du clapet anti-intrusion (`door`).
  - Tensions d'alimentation secteur (`DCV`) et batterie de secours (`batV`).
  - Détection optique et infrarouge du passage effectif des croquettes.
- **Passerelle Bluetooth (BLE Proxy) :**
  - Relaye les trames Bluetooth des accessoires Petkit situés à proximité (ex. fontaine à eau Eversweet W5, purificateur K3 Spray) vers Localkit et Home Assistant.

---

## 2. Entités Exposées dans Home Assistant

Grâce à la découverte MQTT native (`homeassistant-publisher`), Localkit crée et maintient automatiquement l'ensemble des entités ci-dessous pour le YumShare Dual :

### A. Statistiques Journalières (Remise à zéro quotidienne à minuit)

| Entité | Nom affiché | Unité | Description |
| :--- | :--- | :---: | :--- |
| `sensor.*_feedings_today` | Feedings Today | `feedings` | Nombre total de distributions servies aujourd'hui (manuelles + programmées). |
| `sensor.*_portions_dispensed_hopper_1_today` | Portions Dispensed Hopper 1 Today | `portions` | Cumul des portions délivrées aujourd'hui par la trémie 1. |
| `sensor.*_portions_dispensed_hopper_2_today` | Portions Dispensed Hopper 2 Today | `portions` | Cumul des portions délivrées aujourd'hui par la trémie 2. |
| `sensor.*_manual_portions_today` | Manual Portions Today | `portions` | Cumul des portions délivrées manuellement (via bouton physique ou action Home Assistant). |
| `sensor.*_planned_meals_today` | Planned Meals Today | `meals` | Nombre total de repas programmés prévus dans le calendrier pour la journée en cours. |
| `sensor.*_planned_portions_today` | Planned Portions Today | `portions` | Nombre total de portions programmées prévues pour la journée en cours. |
| `sensor.*_meals_today` | Meals Today | `meals` | Nombre de visites repas où le chat est venu manger dans la gamelle aujourd'hui. |
| `sensor.*_visits_today` | Visits Today | `visits` | Nombre total de détections de présence ou passages devant l'appareil aujourd'hui. |
| `sensor.*_eating_time_today` | Eating Time Today | `s` | Durée cumulée passée par le chat à manger aujourd'hui (en secondes). |

### B. Capteurs Temps Réel & Activité

| Entité | Nom affiché | Type / Classe | Description |
| :--- | :--- | :---: | :--- |
| `binary_sensor.*_is_eating` | Is Eating | `occupancy` | `ON` dès que le chat est devant la gamelle en train de manger, `OFF` dès qu'il part. |
| `sensor.*_last_used_by` | Last Used By | Diagnostic | Affiche le nom du chat identifié lors du dernier événement (détection ou repas). |
| `event.*_activity` | Activity | Event | Flux d'événements en direct (`dispense`, `eat_start`, `eat_over`, `detect`). Fournit les détails de chaque distribution (`real_amount1`, `source`, `success`). |
| `sensor.*_bowl` | Bowl | Diagnostic | Poids de croquettes actuellement présent dans la gamelle (en grammes). |
| `sensor.*_ip_address` | IP Address | Diagnostic | Adresse IPv4 locale de l'appareil sur le réseau. |
| `sensor.*_device_status` | Device Status | Diagnostic | État fonctionnel (`WORKING`, `IDLE`). |
| `sensor.*_error` | Error | Diagnostic | Remonte les alertes matérielles (`Ok`, `food_empty`, `door_closed`, etc.). |

### C. Boutons d'Action Rapide

| Entité | Nom affiché | Action |
| :--- | :--- | :--- |
| `button.*_action_feed` | Feed 1 Dose Both | Distribue 1 dose de la trémie 1 et 1 dose de la trémie 2. |
| `button.*_action_feed_hopper_1` | Feed 1 Dose Hopper 1 | Distribue 1 dose de la trémie 1 seule. |
| `button.*_action_feed_hopper_2` | Feed 1 Dose Hopper 2 | Distribue 1 dose de la trémie 2 seule. |

### D. Contrôles & Réglages (Config / Number / Switch)

- **Dosages par défaut :** `number.*_feed_amount_hopper_1` et `number.*_feed_amount_hopper_2` (1 à 50 doses).
- **Vision & Caméra :** `switch.*_camera`, `switch.*_night` (vision nocturne IR), `switch.*_microphone`.
- **Sensibilité des détections :** `switch.*_pet_visit_detection`, `switch.*_eating_detection`, `switch.*_move_detection`.
- **Sons :** `switch.*_sound_enable`, `switch.*_feed_sound`, `number.*_volume`.

---

## 3. Schéma de la Base de Données (`history`)

Les événements de l'appareil sont stockés dans la table SQLite `history` :

| Colonne | Type | Description |
| :--- | :--- | :--- |
| `id` | INTEGER PRIMARY KEY | Identifiant unique séquentiel de l'événement. |
| `device_id` | INTEGER | Clé étrangère liant l'événement à la table `devices`. |
| `pet_id` | INTEGER (nullable) | Clé étrangère vers la table `pets` si un animal a été identifié. |
| `type` | VARCHAR | Type d'événement : `WORKING` (distribution), `EAT` (repas), `DETECT` (passage), `ERROR` (panne). |
| `messageId` | VARCHAR | Identifiant unique de trame MQTT (ex. `300023502_1793132789`). |
| `parameters` | JSON | Données brutes de l'événement (quantités réelles, horodatages, flags). |
| `created_at` | DATETIME | Date et heure de début de l'événement. |
| `updated_at` | DATETIME | Date et heure de dernière mise à jour / fin d'événement. |

### Structure du JSON `parameters` selon le type :

- **`WORKING` (Distribution) :**
  ```json
  {
    "id": "r_20261027_4437-1",
    "day": 20261027,
    "manual": 0,
    "time": 77188,
    "online_state": 0,
    "eat_video": 1,
    "real_amount1": 1,
    "real_amount2": 0,
    "result": 0
  }
  ```
- **`EAT` (Repas) :**
  ```json
  {
    "start_time": 1793132814,
    "event_start": 1793132814,
    "event_end": 1793133007,
    "percent": 12,
    "eat_video": 1
  }
  ```

---

## 4. Calcul & Algorithmes de Résilience (`computeDailyStats`)

1. **Agrégation des portions trémie 1 :**
   ```php
   $portions1 = (int) $feedEvents->sum(function ($h) {
       $p = $h->parameters ?? [];
       if (isset($p['real_amount1'])) return (int) $p['real_amount1'];
       if (isset($p['real_amount'])) return (int) $p['real_amount'];
       return 1; // Fallback sécurisé en cas de retard de feed_over
   });
   ```
2. **Filtrage des distributions manuelles :**
   Comptabilise tout événement où `manual === 1` (bouton physique) ou dont l'identifiant commence par `r_` (déclenché à la demande depuis Home Assistant / MQTT).
3. **Plafond de durée de repas :**
   La durée d'un repas est bornée entre 5 secondes et 1800 secondes (30 minutes). Si un paquet `eat_over` est manquant ou si un horodatage est aberrant, la durée n'est pas ajoutée afin de ne pas fausser le cumul quotidien.
