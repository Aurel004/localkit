# Protocole MQTT & Cycle de Vie des Événements Petkit (Alibaba Cloud IoT)

Ce document détaille le fonctionnement du protocole MQTT utilisé par les appareils Petkit (basé sur le SDK C Alibaba Cloud Link IoT / Alink), la structure des échanges, ainsi que le cycle de vie des événements pour les distributeurs et litières connectés sous Localkit.

---

## 1. Topologie des Topics MQTT

Les appareils communiquent avec le broker selon une arborescence standardisée :

| Type d'échange | Topic | Description |
| :--- | :--- | :--- |
| **Événement émis par l'appareil** | `/sys/{ProductKey}/{DeviceName}/thing/event/{eventName}/post` | L'appareil publie un événement (ex. `feed_start`, `feed_over`, `eat_start`, `eat_over`, `property_post`, `pet_detect`). |
| **Acquittement du serveur** | `/sys/{ProductKey}/{DeviceName}/thing/event/{eventName}/post_reply` | Localkit confirme la réception de l'événement à l'appareil. |
| **Service appelé par le serveur** | `/sys/{ProductKey}/{DeviceName}/thing/service/{serviceName}` | Localkit ordonne une action à l'appareil (ex. `feed_realtime`, `property/set`, `start`). |
| **Acquittement de service** | `/sys/{ProductKey}/{DeviceName}/thing/service/{serviceName}_reply` | L'appareil confirme la prise en compte du service. |

> **Variables :**
> - `{ProductKey}` : clé produit (souvent `localkit` ou un hash hexadécimal Petkit comme `a54dw3gx0nb`).
> - `{DeviceName}` : nom composé de l'appareil (ex. `d_d4sh_20250805DA0372`).

---

## 2. Structure des Messages & Exigences d'Acquittement (ACK)

### A. Message envoyé par l'appareil (`post`)
Lorsqu'un appareil émet un événement, il transmet une charge JSON :
```json
{
  "id": "730",
  "version": "1.0",
  "params": {
    "XDevice": "id=300023502&nonce=...&timestamp=1793132794&type=D4SH&sign=...",
    "event_id": "300023502_1793132789",
    "timestamp": 1793132794,
    "content": "{\"id\":\"r_20261027_4437-1\",\"day\":20261027,\"manual\":0,\"time\":77188,\"online_state\":0,\"eat_video\":1}",
    "state": "{\"bowl\":-1,\"feeding\":1,\"eating\":0,...}"
  },
  "sys": {
    "ack": 1
  }
}
```

### B. Format strict de l'acquittement attendu (`post_reply`)
Le firmware Petkit (écrit en C) utilise le parseur officiel Alink. Tout acquittement doit répondre **strictement** aux critères suivants :

```json
{
  "code": 200,
  "data": {},
  "id": "730",
  "message": "success",
  "method": "thing.event.feed_start.post",
  "version": "1.0"
}
```

> [!CRITICAL] Règles absolues pour l'ACK :
> 1. **`id`** : doit être une chaîne reprenant exactement la valeur `id` du message entrant.
> 2. **`version`** : doit valoir `"1.0"` (et **NON PAS** `"1.0.0"`). Le parseur firmware effectue une comparaison stricte de chaîne (`strcmp(ver, "1.0")`).
> 3. **`method`** : doit correspondre au nom de méthode Alink : `thing.event.<eventName>.post` (ou `thing.service.<serviceName>`).
>    Si le champ `method` est `null` ou omis, l'appareil rejette la réponse comme non conforme.

### C. Conséquence d'un acquittement invalide (File FIFO Flash)
Les microcontrôleurs Petkit disposent d'une file d'attente FIFO persistée en mémoire Flash :
- Si l'ACK reçu est invalide ou absent, l'appareil réémet immédiatement le message jusqu'à 4 fois (toutes les 15 secondes).
- Si les réessais échouent, le message reste bloqué en tête de la file FIFO Flash.
- Lors de toute tentative de synchronisation ultérieure, l'appareil renverra en priorité cet ancien message bloqué, masquant ou retardant tous les événements plus récents.

---

## 3. Cycle de Vie des Distributions de Nourriture (`WORKING`)

Une distribution implique plusieurs étapes entre l'application/Home Assistant, Localkit et le matériel :

```
[Home Assistant / Utilisateur]             [Localkit]                         [Distributeur]
             │                                 │                                    │
             │── Appui "Distribuer Trémie 1" ──>│                                    │
             │   (ou bouton physique)          │── feed_realtime (amount1=1) ───────>│
             │                                 │   (/thing/service/feed_realtime)   │
             │                                 │                                    │── Moteur tourne
             │                                 │<── feed_start/post ────────────────│
             │                                 │    (event_id, content: id, time)   │
             │                                 │── feed_start/post_reply (code 200)─>│ (Acquitté)
             │                                 │                                    │
             │                                 │                                    │── Fin de dose
             │                                 │<── feed_over/post ─────────────────│
             │                                 │    (real_amount1, result: 0)       │
             │                                 │── feed_over/post_reply (code 200) ─>│ (Acquitté & purgé)
             │<── MQTT State Publish ──────────│
             │    (portions1 = +1)             │
```

### Détail des étapes :

1. **Déclenchement :**
   - **Distant (Home Assistant / App) :** Envoi d'un service `feed_realtime` avec un identifiant préfixé `r_YYYYMMDD_XXXX-1` et les doses demandées (`amount1`, `amount2`).
   - **Manuel (Bouton physique) :** L'utilisateur presse le bouton physique sur l'appareil. Le champ `manual` vaut `1` et `id` est vide.
   - **Planifié (Programme interne) :** Déclenché par le timer matériel de l'appareil selon le planning injecté dans `device_schedule_items`. L'identifiant est préfixé `n_` ou `s_`.

2. **Événement `feed_start` :**
   - Publié dès l'activation du clapet/moteur.
   - Le JSON `content` contient : `day`, `manual`, `time`, `id`, `eat_video`.
   - **Ne contient pas** la quantité réelle distribuée.
   - Crée une ligne `WORKING` dans la table `history` si elle n'existe pas.
   - **Protection contre les réessais :** Si la ligne existe déjà, les paramètres sont fusionnés (`array_merge`) pour ne pas effacer `real_amount1` déjà fourni par `feed_over`.

3. **Événement `feed_over` :**
   - Publié à l'arrêt du moteur une fois le capteur optique/infrarouge ayant validé le passage des croquettes.
   - Le JSON `content` contient : `real_amount1`, `real_amount2`, `completed_at`, `result` (`0` = succès), `err_code`.
   - `mergeHistory()` associe ce résultat à la distribution `WORKING` correspondante :
     1. Par correspondance d'horodatage (`event_id` ou `petkit_id_start_time`).
     2. Par correspondance de préfixe de distribution (`r_...`).
     3. Par repli temporel sur le dernier événement `WORKING` survenu dans les 3 minutes précédentes.

---

## 4. Cycle de Vie des Repas & Détections Chat (`EAT` & `DETECT`)

### A. Repas (`EAT`)
1. **`eat_start` :** L'animal s'approche ou le capteur de charge de la gamelle détecte une baisse/présence.
   - Active le capteur binaire `is_eating: ON` sur Home Assistant.
   - Crée un enregistrement `EAT` avec l'horodatage `start_time`.
2. **`eat_over` :** L'animal quitte la zone.
   - Désactive le capteur binaire `is_eating: OFF`.
   - Calcule la durée du repas (`eventDuration() = event_end - event_start`).
   - **Garde de sécurité :** Seuls les repas d'une durée comprise entre 5 secondes et 1800 secondes (30 minutes) sont comptabilisés dans `eating_time_today` pour éliminer les déconnexions réseau ou événements non clôturés.

### B. Détections (`DETECT`)
- `pet_detect` capture le passage d'un animal (capteur de mouvement / caméra PIR).
- `pet_discern` (optionnel) est envoyé de manière asynchrone par l'analyse vidéo/IA pour identifier le chat spécifique (`pet_id`). Si l'utilisateur n'a configuré qu'un seul animal dans Localkit, celui-ci est automatiquement attribué.
