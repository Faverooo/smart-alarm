Ecco un documento di recap e aggiornamento dettagliato (versione 3.0), che integra il tuo file `SPEC.md` originale con tutta la logica avanzata del database che abbiamo appena costruito. È strutturato in modo da essere la tua "Bibbia" per le prossime fasi di sviluppo e perfetto da passare a Claude Code.

---

# 📖 Documento di Specifica e Architettura Avanzata: "Smart Alarm IoT"

## 1. Stato dell'Arte: Dove siamo arrivati

Abbiamo completato l'architettura base del backend (il "cervello" del sistema) lavorando su un ambiente locale (WSL con Ubuntu, PHP, SQLite).
Il database è stato modellato con successo usando la logica delle **"Due Chiavi"** ed è pronto a supportare tutte le funzionalità richieste. Abbiamo inoltre configurato **Laravel Sanctum** per garantire che ogni ESP32 abbia un token di sicurezza univoco per accedere alle API, e stiamo iniziando a vestire l'interfaccia di amministrazione web tramite **Filament PHP**.

---

## 2. L'Esperienza Utente (Casi d'Uso e Funzionalità)

Il sistema è progettato per essere estremamente flessibile, gestendo senza problemi due scenari principali di utilizzo:

* **Modalità Coppia:** L'Utente A gestisce le sveglie (es. deve alzarsi presto), l'Utente B gestisce in segreto le foto e i messaggi che l'Utente A vedrà al risveglio.
* **Modalità Utente Singolo:** Lo stesso utente è assegnato sia come "Gestore Sveglie" che come "Gestore Foto", potendo controllare l'intero dispositivo da un'unica interfaccia.

### ⏰ Gestione Sveglie (Alarms)

L'utente può configurare la sveglia dal pannello web in due modi:

1. **Sveglia Ricorrente:** Seleziona i giorni della settimana (es. Lun, Mar, Mer, Gio, Ven) e un orario. Suonerà regolarmente.
2. **Sveglia Singola ("Una tantum"):** Seleziona una **data specifica** (es. 15 Agosto) e un orario. L'ESP32 suonerà solo in quel giorno. Al momento dello spegnimento tramite il pulsante fisico, la sveglia si disattiverà automaticamente nel database (`is_active = false`), rimanendo come storico ma senza suonare il giorno successivo.

### 🖼️ Gestione Foto e Programmi

Abbiamo separato le "Foto" dai "Programmi" (Playlist) per permettere la massima personalizzazione. Un Programma può essere assegnato a un Dispositivo. Tipi di programmi:

1. **Fisso (Fixed):** Mostra sempre la stessa foto.
2. **Giornaliero ("Usa e getta"):** Ogni giorno l'ESP32 scarica una nuova foto dalla coda. Una volta mostrata, la foto viene marcata nel database come `is_shown = true` e non verrà più riproposta.
* *Feature Speciale:* Si può assegnare una data precisa a una foto (`scheduled_for`). Se oggi è il compleanno del partner, il sistema salta la coda e mostra la foto specifica per quel giorno!


3. **Rotazione:** Mostra a schermo una serie di foto, cambiandole ogni X minuti. (Richiederà che l'ESP32 le scarichi nella sua memoria flash interna `LittleFS` per non saturare la rete).

### 💬 Messaggi Testuali

I messaggi (es. "Oggi piove, ricordati l'ombrello!") sono totalmente indipendenti dalle foto. Possono essere programmati per un giorno/ora specifici. Quando appaiono a schermo, rimangono fissi finché l'utente non preme il pulsante sull'ESP32. A quel punto l'API lo marca come `is_read = true` e il messaggio scompare.

---

## 3. Funzionamento Hardware: La Logica del Pulsante

La sveglia fisica è concepita per essere minimale: un TFT IPS da 3.2", un buzzer per il suono e **un solo pulsante multifunzione**.

Tutta l'interazione si basa sullo stato in cui si trova il dispositivo:

* **Se la sveglia sta suonando** ➔ Un *Click Singolo* ferma il buzzer e notifica il server ("Sveglia disattivata"). Non c'è Snooze in questa versione, bisogna alzarsi!
* **Se c'è un messaggio testuale a schermo** ➔ Un *Click Singolo* chiude il messaggio e pulisce lo schermo.
* **Se il dispositivo è in Standby (a riposo)** ➔ Un *Click Singolo* funge da interruttore (Toggle) per accendere o spegnere lo schermo. (Questo si ottiene fisicamente togliendo la corrente al pin del Backlight / LED del display).
* **Pressione Lunga (10 secondi)** ➔ Esegue un **Factory Reset**. Il dispositivo formatta il filesystem `LittleFS`, cancella le credenziali del Wi-Fi di casa, invalida il Token Sanctum avvisando il server, e si riavvia in modalità Access Point (pronto per essere accoppiato di nuovo).

---

## 4. Architettura di Rete: Raspberry Pi ↔ ESP32

Questo è il piano esatto di come i pezzi comunicheranno tra loro in modo sicuro ed efficiente, senza dover aprire le porte del tuo router di casa (Port Forwarding):

1. **Il Server (Raspberry Pi 3B+):**
* Sopra ci installeremo Ubuntu/Raspberry OS Lite con **Nginx, PHP-FPM e SQLite**.
* Farà girare la nostra applicazione Laravel e il pannello Filament.
* Utilizzeremo **Cloudflare Tunnel (cloudflared)**: un piccolo demone sul Raspberry creerà un tunnel sicuro verso i server di Cloudflare. Questo ci permetterà di avere un dominio pubblico (es. `https://sveglia.tuodominio.com`) con certificato SSL HTTPS gratuito.


2. **Il Dispositivo Client (ESP32):**
* Alla prima accensione, creerà una sua rete Wi-Fi ("Sveglia_Setup"). Tramite smartphone ti collegherai, inserirai la password del tuo Wi-Fi di casa e il codice `MAC_ADDRESS` ti servirà per registrarlo sul sito.
* In background (es. ogni 5-10 minuti), usando la funzione `millis()` e non `delay()`, farà delle chiamate HTTP (REST API in formato JSON) verso il dominio Cloudflare.
* Invierà sempre l'header `Authorization: Bearer [SUO_TOKEN_SANCTUM]`.
* Il backend risponderà con i dati: "Non ci sono nuove sveglie. Mostra questa immagine JPEG".



---

## 5. Il Piano Operativo: Prossimi Step (Roadmap)

Ora che il quadro è completo, ecco l'ordine esatto in cui procederemo con lo sviluppo (perfetto da seguire step-by-step):

### Fase 1: Completamento del Backend Web (In corso)

* Finire le interfacce **Filament** (`AlarmResource`, `PhotoResource`, `ProgramResource`, `MessageResource`) per permettere il caricamento delle immagini sul disco del server e la selezione visiva dei giorni della settimana/orari per le sveglie.
* Gestire le *Policies*: fare in modo che chi fa il login veda **solo** i propri dispositivi e le proprie foto, non quelli di altri ipotetici utenti del server.

### Fase 2: Sviluppo delle API (Il linguaggio per l'hardware)

* Creare i Controller API.
* **Rotta di Pairing:** endpoint per generare e rilasciare il token iniziale all'ESP32.
* **Rotta Sync:** Un endpoint "mastodontico" ottimizzato in cui l'ESP32 fa una singola chiamata `GET /api/v1/sync` e il server gli risponde con un JSON contenente l'orario UTC esatto, i prossimi 3 allarmi previsti, l'URL dell'immagine attiva oggi e eventuali messaggi pendenti.
* **Rotta Interazioni:** Endpoint per fare il POST di avvenuta lettura messaggio o avvenuto spegnimento sveglia singola.

### Fase 3: Trasferimento (Deployment) sul Raspberry Pi

* Preparare il Raspberry Pi fisicamente.
* Fare il `git clone` del nostro codice (che nel frattempo avremo caricato su GitHub o GitLab).
* Installare e avviare il Cloudflare Tunnel per rendere tutto online.

### Fase 4: Firmware ESP32 (Codice C++)

* Quando il backend è 100% online sul Raspberry e ti arriva l'hardware a casa, passeremo a PlatformIO/Arduino IDE.
* Integreremo librerie specifiche come:
* `TFT_eSPI` per la gestione ultra-veloce del display ILI9341.
* `WiFiManager` per la connessione.
* `ArduinoJson` per leggere le risposte del server.
* `TJpg_Decoder` per decomprimere e mostrare le immagini scaricate.



Se questo recap ti soddisfa ed è in linea con la tua visione, possiamo riprendere i lavori esattamente dalla **Fase 1 (Completamento Filament)**. Dicci quale risorsa vuoi creare ora!