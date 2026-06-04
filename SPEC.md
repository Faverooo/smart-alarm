# 📖 Documento di Specifica: Progetto "Smart Alarm Clock IoT" (SaaS) v2.0

## 1. Panoramica del Progetto

Creazione di una sveglia IoT basata su ESP32 con schermo TFT, progettata specificamente per le coppie. L'architettura è strutturata come un servizio SaaS ospitato su un Raspberry Pi: un partner gestisce gli orari della sveglia, l'altro invia segretamente una foto che apparirà sullo schermo all'orario previsto. Inoltre è possibile visualizzare messaggi inviati tramite app (programmabili).

---

## 2. Distinta Base Hardware (BOM)

| Componente | Specifiche Richieste | Note |
| --- | --- | --- |
| **Microcontrollore** | ESP32 WROOM 32D | Wi-Fi integrato, logica a 3.3V. Gestisce l'orologio internamente via NTP. |
| **Schermo** | 3.2" pollici IPS TFT (ILI9341) | Connessione diretta SPI. **Il pin LED/BL va collegato a un pin GPIO** per spegnere lo schermo via software. |
| **Buzzer** | Passivo, High Level Trigger | Necessario per suonare melodie (usando PWM/onda quadra). |
| **Pulsante (Multifunzione)** | Momentary self-reset, 1NO 1NC | Ritorno a molla. Usato per Snooze, accensione/spegnimento schermo e Factory Reset. |
| **Alimentazione** | Alimentatore USB 5V (1A o 2A) | Alimentazione continua a parete essenziale per mantenere l'RTC interno sincronizzato. |
| **Prototipazione** | Breadboard 830 punti, Cavi Jumper | Per testare prima di saldare. |
| **Case** | Stampa 3D (PLA/PETG) | Necessiterà di viti M2/M2.5 per il fissaggio dei componenti. |

---

## 3. Infrastruttura Server e Rete

Il sistema deve girare fluidamente su un Raspberry Pi 3B+ (1GB RAM) posizionato in casa dello sviluppatore, superando i limiti del NAT dell'operatore mobile (Very Mobile).

* **Sistema Operativo:** Raspberry Pi OS Lite (64-bit) - Rigorosamente senza interfaccia desktop (GUI).
* **Gestione RAM (Critico):** Creazione obbligatoria di un **File di Swap da 1GB o 2GB** sulla MicroSD per evitare crash di sistema ("Out of Memory") durante l'elaborazione PHP.
* **Stack Web:** Nginx + PHP-FPM (Più leggeri e performanti di Apache per 1GB di RAM).
* **Database:** **SQLite**. Nessun demone MySQL in background per risparmiare RAM.
* **Esposizione su Internet:** **Cloudflare Tunnel** (`cloudflared`). L'infrastruttura espone il server web in HTTPS tramite un dominio economico senza aprire porte sul router.

---

## 4. Architettura Software Backend (Laravel 11 + Filament)

### Obiettivo e Gestione Media

La visualizzazione delle foto deve essere organizzata in programmi, caricabili poi sull'ESP32.

* Una foto può essere collegata a più programmi.
* Un programma può prevedere una nuova foto al giorno, una rotazione tra più foto ogni X minuti (scaricate e lette dalla cache dell'ESP), o una foto fissa.
* I messaggi testuali vengono programmati per il futuro. Quando il messaggio appare sull'ESP, l'utente deve premere il bottone per rimuoverlo, dopodiché il messaggio sparisce dallo schermo (e si disattiva nel DB).
* Gli allarmi rimangono memorizzati e si stoppano dall'ESP premendo il pulsante.

### Struttura del Database (La regola delle "Due Chiavi")

Il core del sistema isola i ruoli della coppia collegandoli allo stesso dispositivo hardware.

* **Tabella `Users`:** Dati standard di Laravel (Nome, Email, Password).
* **Tabella `Devices`:**
* `mac_address`: (String, Unique) - Identificativo univoco dell'ESP32.
* `alarm_user_id`: (Foreign Key) - Proprietario delle sveglie.
* `photo_user_id`: (Foreign Key) - Proprietario delle foto.



### Sicurezza API e Fusi Orari

* **Laravel Sanctum:** Le API saranno protette. Dopo il pairing, Laravel genererà un API Token associato al dispositivo per autorizzare le richieste successive.
* **Timezones (UTC):** Tutti i timestamp e gli orari delle sveglie nel database devono essere salvati rigorosamente in **UTC**. Il calcolo del fuso orario locale è demandato all'interfaccia Filament o all'ESP32 via API.

### Ottimizzazione Immagini

Uso del pacchetto `intervention/image`. Quando l'utente carica un'immagine, Laravel la ridimensiona **istantaneamente a 320x240 pixel** e la comprime prima di salvarla.

### Logica del Pannello Filament (Multi-Tenancy)

* Tramite il metodo `getEloquentQuery()`, gli utenti vedono solo i dati associati al loro account.
* Se `auth()->id() == alarm_user_id`, l'utente gestisce solo le sveglie.
* Se `auth()->id() == photo_user_id`, l'utente gestisce solo le foto/messaggi.

---

## 5. Flusso di Abbinamento (Pairing UX)

1. L'utente accende la sveglia. Lo schermo mostra il proprio **CODICE MAC** (es. `A1B2C3D4`).
2. L'utente 1 crea un account sul sito web, inserisce il codice e diventa in automatico `photo_user`.
3. Il pannello genera un **Codice di Coppia** temporaneo a 6 cifre (es. `XYZ123`).
4. L'utente 2 crea un account, inserisce il codice `XYZ123` e viene assegnato come `alarm_user`.
5. Il collegamento è completato. Laravel genera un **API Token (Sanctum)** che viene restituito all'ESP32 e salvato nella sua memoria non volatile.

---

## 6. Firmware ESP32 (C++ / Arduino IDE)

### Gestione Rete e Sicurezza

* **WiFiManager:** Gestisce il "Captive Portal" alla prima accensione per configurare la rete Wi-Fi di casa.
* **WiFiClientSecure:** Necessario per effettuare chiamate in HTTPS (Cloudflare Tunnel).
* **LittleFS (File System):** Sfruttato per salvare l'API Token di Sanctum e per fare **caching locale delle immagini** (evitando di riscaricare la stessa foto dal server ad ogni rotazione).

### Display e Orologio

* **TFT_eSPI / TJpg_Decoder:** Per gestire lo schermo hardware e decodificare velocemente le foto in memoria locale/RAM.
* **Sincronizzazione Ora:** Assente il modulo RTC esterno, all'avvio l'ESP32 usa la funzione nativa `configTime()` per interrogare un server NTP (o la tua API). Il timer interno manterrà l'ora esatta finché il dispositivo è alimentato.

### Logica Interna (State Machine)

Il ciclo `loop()` non usa mai `delay()`, ma `millis()` per gestire in parallelo:

* Aggiornamento orologio in background.
* Polling API (es. ogni 10 minuti, usando l'header `Authorization: Bearer <token>`).
* **Lettura Pulsante (Multifunzione):**
* *Click Singolo:* Ferma la sveglia, chiude un messaggio o fa da **Toggle (Accende/Spegne lo schermo)** spegnendo fisicamente il pin GPIO dedicato al LED/BL del TFT.
* *Pressione Lunga (10 secondi):* **Factory Reset**. Se tenuto premuto per 10s, l'ESP32 formatta le credenziali di WiFiManager, elimina il token Sanctum da LittleFS, notifica il server (chiamata di Unpair) e si riavvia.



---

## 7. Fasi di Sviluppo Consigliate (Roadmap)

1. **Fase Server:** Setup Raspberry Pi OS Lite, installazione Nginx, PHP, SQLite e Swap. Configurazione Cloudflare Tunnel.
2. **Fase Backend:** Setup Laravel 11, Sanctum e configurazione UTC. Migrazioni (Due Chiavi) e Filament.
3. **Fase API:** Creazione rotte API JSON (protette da token) e ridimensionamento foto con Intervention Image.
4. **Fase Hardware:** Cablaggio ESP32, TFT (LED su GPIO), pulsante e buzzer su breadboard.
5. **Fase Firmware (Core):** Implementazione `WiFiManager`, sync `configTime()`, LittleFS e state machine del pulsante (Snooze/Toggle/Reset).
6. **Fase Firmware (Integrazione):** Handshake Sanctum, lettura JSON via HTTPS, caching e visualizzazione immagini sul TFT.
7. **Fase CAD e Chiusura:** Modellazione 3D del case, saldatura finale e assemblaggio.