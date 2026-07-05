# Come funziona il progetto (backend Laravel + Filament)

Guida didattica per capire **cosa fa ogni pezzo** di questo progetto e — soprattutto —
**perché è fatto così**. Presuppone che tu sappia programmare; Filament, Sanctum e i
dettagli dell'API vengono spiegati da zero, sempre mostrandoli **dentro questo codice**,
con i file da aprire.

> Compagni di questo file:
> - `docs/COME_FUNZIONA_FIRMWARE.md` → la stessa guida, per il **firmware ESP32**.
> - `docs/IMPLEMENTATION_GUIDE.md` → il contratto API formale e la roadmap tecnica.

---

## 1. Cosa fa l'app, in una frase

Un **orologio/sveglia smart** da comodino: un dispositivo **ESP32 con schermo TFT**
mostra foto e messaggi e suona le sveglie. Questo backend **Laravel + Filament** è
il pannello web da cui le persone gestiscono sveglie, foto, messaggi e programmi.
Il dispositivo si sincronizza col server via API REST.

Due "ruoli" condividono un dispositivo (modello **two-key**):
- **Alarm manager** → gestisce le sveglie.
- **Photo/Message manager** → carica foto, scrive messaggi, sceglie il programma.
- Stessa persona in entrambi i ruoli = modalità single-user.

---

## 2. Stack tecnologico (e perché)

| Pezzo | A cosa serve |
|---|---|
| **Laravel 11** (PHP 8.2+) | Framework di base: routing, ORM (Eloquent), migrazioni, ecc. |
| **Filament 5** | Costruisce il pannello di amministrazione (CRUD, form, tabelle) **scrivendo classi PHP**, non HTML. |
| **Livewire 3** | Sotto Filament: rende le pagine "reattive" (aggiornamenti senza ricaricare) parlando col server via AJAX. Filament è costruito su Livewire. |
| **Alpine.js** | Micro-interattività lato browser (modali, toggle). Arriva con Livewire. |
| **Tailwind CSS** | Le classi di stile (`flex`, `rounded-lg`…). Filament ha un suo tema Tailwind. |
| **SQLite** | Database su file (`database/database.sqlite`). Zero configurazione, perfetto per il Raspberry. |
| **Sanctum** | Token di autenticazione per l'API del dispositivo (vedi §9 — è la parte interessante). |
| **intervention/image** | Ridimensiona le foto a 320×240 per lo schermo. |

**L'idea chiave di Filament:** descrivi *cosa* vuoi (un campo testo, una colonna
tabella, un'azione) con oggetti PHP fluenti, e Filament genera l'interfaccia +
il comportamento Livewire per te. Tu non scrivi quasi mai HTML/JS.

---

## 3. Mappa del codice (dove sta cosa)

```
smart-alarm-laravel/
├── app/
│   ├── Models/                     # Le entità (Eloquent): Device, Alarm, Photo, Program, Message, User
│   ├── Filament/
│   │   ├── Resources/              # UN "Resource" per entità = tutto il CRUD di quella entità
│   │   │   ├── DeviceResource.php          # form + tabella + regole di accesso
│   │   │   └── DeviceResource/
│   │   │       ├── Pages/                   # le 3 pagine: List / Create / Edit
│   │   │       └── RelationManagers/        # tabelle di relazioni (es. le foto di un programma)
│   │   └── Widgets/                # riquadri della dashboard (le statistiche)
│   ├── Livewire/                   # componenti Livewire "a mano" (il calendario daily)
│   ├── Http/Controllers/
│   │   ├── Api/V1/                 # l'API per l'ESP32 (§9)
│   │   └── PhotoFileController.php # streaming foto private per il pannello (§10)
│   ├── Console/Commands/           # comandi artisan custom (prune device + file orfani)
│   └── Providers/
│       ├── AppServiceProvider.php  # rate limiter API + URL temporanei foto
│       └── Filament/AdminPanelProvider.php   # configurazione del pannello
├── bootstrap/app.php              # trust proxy (Cloudflare!), 401 JSON per /api/*
├── database/migrations/           # schema del DB (le tabelle)
├── resources/
│   ├── views/livewire/            # i template Blade del calendario
│   ├── views/tables/columns/      # il template del lightbox foto
│   └── css/filament/admin/        # il TEMA Filament custom (Tailwind)
├── routes/
│   ├── web.php                    # rotte web (quasi vuoto: il pannello è a root)
│   ├── api.php                    # rotte API dell'ESP32
│   └── console.php                # scheduler (prune device + foto orfane)
└── tests/Feature/                 # test automatici (57+: pagine, API, foto, sync)
```

---

## 4. I concetti di Filament (spiegati su questo progetto)

Filament organizza tutto intorno al **Panel**. Dentro un panel vivono **Resources**,
**Pages** e **Widgets**.

### 4.1 Panel — `app/Providers/Filament/AdminPanelProvider.php`
È la configurazione globale del pannello: colore primario, dove sta (`->path('')`
= alla root del sito), il login, quali cartelle scansionare per trovare risorse e
widget, e il **tema** (`->viteTheme(...)`). Pensa al panel come "l'app admin".

### 4.2 Resource — il cuore di Filament
Un **Resource** rappresenta UN'entità del DB e ne genera tutto il CRUD. Esempio:
`AlarmResource` gestisce la tabella `alarms`. Un Resource ha tre metodi chiave:

- **`form(Schema $schema)`** → descrive il modulo di creazione/modifica con dei
  *componenti di form* (`TextInput`, `Select`, `Toggle`, `DateTimePicker`…),
  tipicamente raggruppati dentro `Schemas\Components\Section::make(...)`.
  (In Filament 3/4 questo metodo si chiamava `form(Form $form): Form`; dalla v4/v5
  il "contenitore" del form è diventato un concetto più generale, lo `Schema`,
  condiviso anche da altre parti del pannello — da qui il nuovo nome.)
- **`table(Table $table)`** → descrive la tabella elenco con delle *colonne*
  (`TextColumn`, `IconColumn`, `ImageColumn`/`ViewColumn`), filtri e azioni
  (`->recordActions([...])` per le azioni di riga, `->toolbarActions([...])`
  per quelle bulk — si chiamavano `->actions()`/`->bulkActions()` prima della v5).
- **`getPages()`** → le pagine del Resource: `index` (List), `create`, `edit`.

Esempio mentale (da `AlarmResource.php`):
```php
Schemas\Components\Section::make('Schedule')       // raggruppa i campi in una card
    ->schema([
        Forms\Components\TimePicker::make('time')  // un campo orario nel form
            ->label('Alarm Time')
            ->seconds(false),                       // niente secondi
    ]);
Tables\Columns\IconColumn::make('is_active')        // una colonna con icona ✓/✗
    ->boolean();
```
Non scrivi HTML: dici "voglio un TimePicker chiamato `time`" e Filament fa il resto.

### 4.3 Pages — `Resources/XxxResource/Pages/`
Ogni Resource ha 3 pagine generate. Di solito non le tocchi, ma puoi **agganciare
dei "hook"** al loro ciclo di vita. Esempi reali nel progetto:
- `CreatePhoto.php` → `afterCreate()` ridimensiona la foto a 320×240.
- `CreateProgram.php` → `getRedirectUrl()` ti porta alla pagina Edit dopo aver
  creato (così puoi subito aggiungere le foto).
- `ListDevices.php` → header actions custom (Add Device / Join) al posto del
  classico bottone "Create" — è qui che vive il flusso di **claim** del device (§9.2).

### 4.4 Form components & Table columns
Sono i "mattoncini". Li configuri con metodi a catena (fluent):
```php
Select::make('device_id')
    ->options($listaDispositivi)   // cosa mostrare
    ->searchable()                 // ricerca
    ->required();                  // validazione
```
La validazione (`required`, `maxLength`…) la dichiari sul componente: Filament la
applica sia lato client che server. Un esempio meno banale, da `AlarmResource`:
una sveglia senza data né giorni ricorrenti non suonerebbe mai, quindi:
```php
DatePicker::make('scheduled_date')
    ->requiredWithout('days_of_week')   // o la data, o almeno un giorno
```

### 4.5 Actions — bottoni che "fanno qualcosa"
Un'**Action** è un bottone con (opzionale) un modale/form e una `->action(closure)`
che esegue codice. Da Filament 5 tutte le Actions (di tabella, header, bulk, dentro
i form) vivono in un unico namespace `Filament\Actions` — non più sparse tra
`Tables\Actions`/`Forms\Actions` come nelle versioni precedenti. Esempio in
`DeviceResource` la riga "Become Alarm Manager":
```php
use Filament\Actions;
// ...
Actions\Action::make('becomeAlarmManager')
    ->requiresConfirmation()
    ->visible(fn (Device $record) => ! auth()->user()->is_admin
        && $record->photo_user_id === auth()->id()
        && $record->alarm_user_id === null)              // quando appare
    ->action(fn (Device $record) => $record->update(['alarm_user_id' => auth()->id()]));
```
Ci sono Actions di tabella (per riga, in `->recordActions([...])`), header actions
(in alto), e azioni dentro i form. I modali (upload foto, conferme) sono Actions
con un `->form([...])`.

### 4.6 Relation Manager — `Resources/.../RelationManagers/`
Quando una pagina Edit deve gestire una **relazione**, usi un Relation Manager: è
una mini-tabella dentro la pagina. Qui `PhotosRelationManager` mostra/gestisce le
foto di un Programma (relazione molti-a-molti `programs ↔ photos`). Ha i suoi
header actions (Upload / Attach Existing) e azioni di riga (Edit / Remove).

### 4.7 Widget — `app/Filament/Widgets/`
Riquadri della dashboard. Qui due `StatsOverviewWidget`:
`AlarmDashboardWidget` (sveglie attive) e `PhotoDashboardWidget` (foto/messaggi).
Ognuno ha `canView()` per mostrarsi solo al ruolo giusto.

### 4.8 Le tabelle responsive (layout `Split` / `Stack`)
Le tabelle dei Resource non usano colonne "piatte" ma un layout responsive:
```php
Split::make([
    IconColumn::make('is_active')->grow(false),  // a sinistra, larghezza minima
    Stack::make([ /* le altre colonne, impilate */ ]),
])->from('md'),   // sotto il breakpoint 'md' diventa una card verticale
```
Su desktop la riga è orizzontale; su mobile diventa una **card impilata**. Prezzo da
pagare: quando TUTTE le colonne stanno dentro layout component, Filament **nasconde
la riga di intestazione** → i `->sortable()` non hanno più UI (resta il
`->defaultSort(...)`). La ricerca (`->searchable()`) continua a funzionare.

### 4.9 Sotto al cofano: Livewire
Ogni pagina/tabella/azione Filament è in realtà un **componente Livewire**. Quando
clicchi un bottone, Livewire manda una richiesta AJAX al server, esegue il metodo
PHP, e ri-renderizza solo il pezzo cambiato. Per questo "sembra" una SPA pur
scrivendo solo PHP. (Vedi §8 per le trappole che ne derivano.)

---

## 5. Il modello dati (le tabelle e le relazioni)

File: `database/migrations/`, `app/Models/`.

```
users ──< devices >── users        (un device ha alarm_user e photo_user)
              │
              ├──< alarms           (sveglie del device)
              ├──< messages         (messaggi del device)
              └── program           (il device punta a UN programma attivo)

programs ──< photo_program >── photos   (molti-a-molti, con dati extra sul "ponte")
users ──< photos                         (chi ha caricato la foto)
users ──< programs
```

- **Device** (`mac_address`, `pairing_code`, `paired_at`, `alarm_user_id`,
  `photo_user_id`, `program_id` + telemetria: `last_status_at`, `rssi`, `free_heap`,
  `firmware_version`…): il "two-key" sono i due `*_user_id`.
- **Alarm** (`time` locale, `scheduled_date` per una-tantum, `days_of_week` JSON
  per le ricorrenti, `is_active`).
- **Message** (`text_content`, `scheduled_at` locale, `is_read`).
- **Photo** (`image_path`, `caption`).
- **Program** (`type`: `fixed`/`rotation`/`daily`, `interval_minutes`).
- **photo_program** (pivot): `scheduled_for` (giorno per i programmi *daily*) e
  `is_shown` (lo setta l'ESP). Il "pivot" è la tabella-ponte di una relazione
  molti-a-molti, e può avere colonne sue.

In Eloquent le relazioni sono metodi sul Model, es. in `Program.php`:
```php
public function photos() {
    return $this->belongsToMany(Photo::class, 'photo_program')
        ->withPivot(['scheduled_for', 'is_shown']);   // porta con sé le colonne extra
}
```

**⚠ Scelta di design fondamentale: il fuso orario.** Tutti gli orari (sveglie,
messaggi, `/time`) sono **wall-clock locale** (Europe/Rome), NON UTC. Il motivo: una
sveglia alle 07:00 deve suonare alle 07:00 *anche quando scatta l'ora legale*; se
fosse salvata in UTC andrebbe ricalcolata due volte l'anno. Conseguenza: il
`APP_TIMEZONE` del server e il `TZ_INFO` del firmware devono descrivere **lo stesso
fuso**. È il contratto su cui si regge tutto il timing.

---

## 6. Permessi e ruoli (come l'app "filtra" cosa vedi)

Tre tipi di utente: **admin** (vede tutto), **alarm manager**, **photo manager**.
Niente pacchetti di permessi: la logica è dentro i Resource con questi metodi.

- **`canViewAny()`** → se l'utente può vedere il Resource (e quindi la voce di menu).
  Es. `AlarmResource::canViewAny()` ritorna `isAlarmManager()`.
- **`getEloquentQuery()`** → **filtra le righe**: un photo manager vede solo le sue
  foto, un alarm manager solo le sveglie dei suoi device. È il punto in cui lo
  *scoping* per utente avviene.
- **`canCreate()` / `canDelete()`** → permessi su singole operazioni (es. solo
  l'admin crea device dal form classico).

I metodi `isAlarmManager()` / `isPhotoManager()` stanno in `app/Models/User.php` e
controllano se l'utente è admin o è collegato ad almeno un device in quel ruolo.

Risultato: lo **stesso codice** mostra interfacce diverse a persone diverse. Nota
che la sicurezza NON è (solo) nascondere voci di menu: è `getEloquentQuery()` che
impedisce a un utente di raggiungere righe altrui anche scrivendo l'URL a mano.

---

## 7. Il calendario "daily" — un componente Livewire scritto a mano

File: `app/Livewire/DailyPhotoCalendar.php` + `resources/views/livewire/`.

Non tutto si fa con i mattoncini di Filament. Il calendario (clicca un giorno →
imposti la foto di quel giorno) è troppo custom, quindi è un **componente Livewire
puro** che però usa le Filament Actions per i modali (upload/attach).

Come funziona:
- Proprietà pubbliche `view` (month/week) e `cursor` (data mostrata): Livewire le
  tiene sincronizzate tra browser e server.
- Metodi `previous()/next()/setView()` → cambiano stato e la vista si ri-renderizza.
- *Computed properties* `getWeeksProperty()/getDaysProperty()` → costruiscono la
  griglia; nel Blade le usi come `$this->weeks`.
- `setDayAction()` / `removeDayAction()` → Filament Actions con modale per
  aggiungere/togliere la foto del giorno (salva sul pivot `scheduled_for`).

È incastonato nel form del ProgramResource (solo per i programmi `daily`) con il
componente `Schemas\Components\Livewire::make(DailyPhotoCalendar::class, fn ($record) => ['program' => $record])`
(in Filament 3 questo componente stava nel namespace `Forms\Components`; dalla v5
il form è uno `Schema`, quindi anche i suoi "mattoncini" custom sono in `Schemas\Components`).

**Dettaglio di performance interessante:** `buildCell()` viene chiamata una volta
per cella (≈35 volte in vista mese) e ognuna chiedeva le foto del programma → ~35
query identiche per render. La soluzione è una proprietà-memo:
```php
protected ?Collection $photosByDateMemo = null;          // protected → Livewire NON la serializza

protected function photosByDate() {
    return $this->photosByDateMemo ??= $this->program->photos()->...;
}
```
Due cose da capire: (1) `??=` calcola la query **una sola volta per richiesta**;
(2) la proprietà è `protected`, quindi Livewire non prova a serializzarla tra una
richiesta e l'altra (le proprietà *pubbliche* invece viaggiano col componente).
Quando un'azione modifica il pivot, il memo va azzerato a mano
(`$this->photosByDateMemo = null`) o la stessa richiesta renderizzerebbe dati vecchi.

---

## 8. Quattro "storie" che insegnano le insidie di Filament

Queste sono cose scoperte debuggando: ti fanno capire il funzionamento vero.

1. **Il CSS di Filament è precompilato.** Le classi Tailwind "arbitrarie" che
   Filament non usa per sé (`aspect-square`, `bg-gray-950/80`…) **non esistono** nel
   suo CSS → non fanno nulla nei tuoi Blade. Soluzione: abbiamo creato un **tema
   custom** (`resources/css/filament/admin/`) con un build Tailwind che scansiona
   anche i nostri Blade. **Dopo aver cambiato classi → `npm run build`.**

2. **Modali = `<form>` annidati.** I modali delle Actions generano un `<form>`. Se
   il componente sta *dentro* il form della pagina, quel `<form>` annidato fa
   "chiudere" il form della pagina e il bottone *Save changes* finisce fuori → non
   salva più. Soluzione nel calendario: `<template x-teleport="body">` sposta i
   modali in fondo alla pagina, fuori dal form.

3. **Cache dei componenti.** In sviluppo, se una modifica non appare, è la cache:
   `php artisan filament:clear-cached-components`. **Non** lanciare
   `filament:cache-components` in dev (è per la produzione).

4. **`formatStateUsing` e le colonne-array.** `days_of_week` è un cast `array`,
   ma il formatter della TextColumn NON riceve l'array: per le colonne inline
   Filament **prima implode l'array con `', '`** e poi passa la stringa risultante
   (`"Monday, Wednesday, Friday"`) al formatter. Il vecchio codice faceva
   `array_map(..., $state)` → `TypeError: $array must be of type array, string given`
   appena una sveglia ha avuto giorni ricorrenti. Il fix (in `AlarmResource`)
   ri-splitta la stringa. Solo con `->badge()` o `->listWithLineBreaks()` gli
   elementi restano separati. Morale: verifica sempre COSA arriva davvero a un
   callback Filament (il sorgente in `vendor/filament/.../text-column.blade.php`
   è leggibilissimo). C'è un test di regressione: `tests/Feature/AlarmListTest.php`.

---

## 9. L'API per l'ESP32 — la parte più "di sistema" del progetto

File: `routes/api.php`, `app/Http/Controllers/Api/V1/`, `app/Providers/AppServiceProvider.php`.
Questa è la sezione da leggere con calma: tocca autenticazione, caching HTTP e
sicurezza. Il punto di partenza è `routes/api.php` — meno di 50 righe (commentate)
che raccontano tutto:

```php
Route::post('pair/init', ...)->middleware('throttle:pair');     // SENZA auth
Route::middleware(['auth:sanctum', 'throttle:device'])->group(function () {
    // pair/status, sync, time, device/status, device/forget,
    // photos/{id}/render, firmware/latest, alarms/.../dismissed,
    // messages/.../read, programs/photos/.../shown — tutto il resto.
});
```

Oltre a `/sync` (§9.3, il grosso del traffico) ci sono due endpoint "di contorno"
con lo stesso schema di autenticazione: `GET /time` (`TimeController`) — un
orologio leggero da interrogare spesso se l'NTP del device fallisce — e
`GET /firmware/latest` (`FirmwareController`) — il check OTA, guidato dalle
variabili `FIRMWARE_VERSION`/`FIRMWARE_URL`/`FIRMWARE_MD5` in `.env`.

### 9.1 Il token del device: Sanctum su un Model che NON è un utente

Il trucco centrale: **`Device` usa `HasApiTokens` pur non essendo un
`Authenticatable`** (`app/Models/Device.php`). Sanctum non richiede che il
"proprietario" di un token sia un utente: la tabella `personal_access_tokens` ha
una relazione polimorfica (`tokenable_type` + `tokenable_id`), quindi un token può
appartenere a *qualsiasi* model.

Il flusso completo:

1. `PairController::init()` crea/ritrova il Device dal MAC e fa:
   ```php
   $device->tokens()->delete();                                  // revoca i vecchi
   $token = $device->createToken('device', ['device'])->plainTextToken;
   ```
   Il token in chiaro ha la forma `"{id}|{40 char casuali}"`. **Nel DB Sanctum salva
   solo lo SHA-256** della parte casuale: se qualcuno ruba il database non può
   ricostruire i token. Il token in chiaro esiste solo nella risposta HTTP a questa
   chiamata — il device lo salva nella sua flash e non lo rivedrà mai più dal server.

2. Da lì in poi il device manda `Authorization: Bearer 5|abcd…` su ogni richiesta.
   Il middleware `auth:sanctum` spezza il token sull'`|`, trova la riga per id,
   confronta gli hash, e popola **`$request->user()` con il Device** (sì: "user()"
   ma è un Device — è il nome del metodo a essere fuorviante). È così che ogni
   controller API fa `$device = $request->user();`.

3. **Rotazione**: ogni `pair/init` e ogni `device/forget` cancellano i token vecchi
   e ne emettono uno nuovo. Un token rubato muore al primo re-pair. Il reset di
   fabbrica (`forget`) è autenticato *dal token stesso*: solo chi possiede
   fisicamente il device può resettarlo.

4. **Cosa succede su 401**: `bootstrap/app.php` forza risposte JSON per `/api/*`
   (`shouldRenderJsonWhen`) — senza questo, Laravel risponderebbe con un redirect
   HTML al login, che il firmware non capirebbe. Il firmware, ricevuto 401 su
   `/sync`, butta il token e ricomincia dal pairing. Il "logout remoto" di un
   device è quindi: cancellargli i token (o il device stesso) dal pannello.

### 9.2 Il ciclo di vita del pairing code

Stati di un device, governati da `Device::booted()` (hook `saving`) +
`PairController` + `ListDevices::claimRole()`:

```
ESP accende → POST pair/init {mac}
   → riga Device + token + pairing_code (6 char, UNICO — Device::generatePairingCode)
   → finestra di 1h (PAIRING_TTL_MINUTES); scaduta, devices:prune-unpaired la pulisce
     (scheduler in routes/console.php)

Umano nel pannello → "Add Device" col codice → claimRole() assegna photo_user_id
   → device "claimed": il pairing NON è più possibile via API (init → 409)
   → il codice RESTA finché manca l'alarm manager (per farsi raggiungere dal 2° umano)

Secondo umano → "Join as Alarm Manager" stesso codice → alarm_user_id
   → entrambi i ruoli pieni → l'hook saving() AZZERA pairing_code
     (un device completo non ha segreti di ingresso in giro)

Rimozione di un manager (azione di tabella) → slot vuoto
   → l'hook saving() RIGENERA un codice fresco (quello vecchio è morto: chi era
     stato rimosso non può rientrare con un codice che conosceva)
```

Dettagli da notare nel codice:
- `generatePairingCode()` rigenera finché il codice non è unico nel DB: con 6
  caratteri le collisioni sono rare ma possibili, e `claimRole()` cerca per codice —
  due device con lo stesso codice sarebbero un disastro.
- `/sync` e `/device/status` salvano con `saveQuietly()` (niente eventi Eloquent):
  così il loro salvataggio di telemetria **non** fa scattare l'hook del pairing code.
- Il poll `pair/status` del device (ogni 5 s) risponde `paired: true/false` e i
  secondi rimanenti della finestra: è quello che fa uscire l'ESP dalla schermata
  col codice.

### 9.3 `/sync`: un solo endpoint, con ETag "content-based"

`SyncController` è il cuore della comunicazione. Il device lo chiama **ogni 60
secondi per sempre**, quindi l'obiettivo è: quasi sempre **non trasferire nulla**.

Come è costruito:

1. Si compone il payload minimo: nome device, settings (luminosità, volume…),
   sveglie **attive** (max 16), il programma *risolto per tipo* (daily → solo la
   foto di oggi; rotation → max 10; fixed → 1), messaggi non letti in scadenza
   (max 8). I limiti rispecchiano i cap di parsing del firmware (`config.h`).

2. **La revision è l'hash del contenuto**:
   ```php
   $revision = substr(sha1(json_encode($content)), 0, 16);
   ```
   Non c'è nessuna colonna "version" da incrementare e nessun rischio di
   dimenticarsene: *qualsiasi* cambiamento (anche indiretto: a mezzanotte la "foto
   di oggi" di un programma daily cambia da sola) produce una revision diversa.
   I campi volatili (`server_time`) stanno **fuori** dal contenuto hashato,
   altrimenti la revision cambierebbe a ogni secondo.

3. Il device manda `If-None-Match: "<revision>"`. Se coincide → risposta `304`
   vuota (≈100 byte). Se no → payload completo + header `ETag` nuovo.

4. **La trappola Cloudflare** (questa è sottile): Cloudflare ricomprime il JSON e
   quando lo fa **indebolisce l'ETag** in `W/"abc…"`. Il device rimanda indietro
   l'header *così com'è*. Un confronto ingenuo (`trim($header, '"')`) non
   matcherebbe mai → ogni sync sarebbe un 200 pieno, in silenzio, per sempre.
   Per questo esiste `Controller::ifNoneMatchMatches()` che normalizza prefisso
   `W/` e virgolette. C'è un test apposta: `SyncApiTest::test_sync_returns_304_for_weak_etag_from_proxy`.

5. Perché *content-based* e non "304 con header standard di Laravel"? Perché il
   middleware HTTP cache di Laravel lavora sulla risposta già generata; qui invece
   la revision si calcola **prima** di serializzare, e in futuro permetterebbe di
   saltare anche le query. Ed è lo stesso identico schema che il firmware usa
   dall'altra parte (vedi la guida firmware, §5).

### 9.4 Le interazioni device → server

Tre piccoli endpoint in `InteractionController`, tutti col medesimo pattern di
autorizzazione "questa risorsa è davvero tua?":

- `POST alarms/{alarm}/dismissed` → se la sveglia è una-tantum la disattiva.
  Guard: `alarm->device_id === $request->user()->getKey()`.
- `POST messages/{message}/read` → marca letto.
- `POST programs/photos/{photo}/shown` → bookkeeping sul pivot. Guard diversa:
  la foto deve appartenere **al programma attivo del device**.

Nota il route-model-binding (`{alarm}` → istanza `Alarm` automatica) e il fatto che
l'autorizzazione è SEMPRE ri-verificata server-side: il device è un client non
fidato come qualsiasi altro.

### 9.5 Rate limiting (e perché è custom)

In `AppServiceProvider::boot()`:
```php
RateLimiter::for('pair',   fn ($r) => Limit::perMinute(20)->by($r->ip()));
RateLimiter::for('device', fn ($r) => Limit::perMinute(120)->by($r->bearerToken() ?: $r->ip()));
```
Il `throttle:60,1` di default chiava sul `user()->getAuthIdentifier()` — ma il
nostro Device non è un Authenticatable, quindi serviva una chiave custom: il
**token stesso** (per-device) o l'IP per `pair/init` (che è l'unico endpoint senza
auth, e quindi il più abusabile: crea righe + token a ogni chiamata).

Collegato: in `bootstrap/app.php` c'è `trustProxies(at: '*')`. Senza, dietro il
tunnel Cloudflare **tutte** le richieste sembrerebbero arrivare da 127.0.0.1: il
rate limit per IP diventerebbe globale, e `url()` genererebbe link `http://` che
il firmware (solo TLS) rifiuterebbe. Se l'API "si rompe in produzione ma in locale
va", l'header `X-Forwarded-*` è il primo posto dove guardare.

---

## 10. Le foto: private per design (tre vie d'accesso, zero URL pubblici)

`Photo::DISK = 'local'` → i file stanno in `storage/app/private/photos/`, che il
web server **non serve mai direttamente**. Non esiste `storage:link`. Chiunque
indovini un path ottiene 404. Le tre vie legittime:

1. **Il pannello** → `GET /photo-file?path=…` (`PhotoFileController`, rotta `web`
   con middleware `auth`): valida il path con una regex anti-traversal
   (`#^photos/[A-Za-z0-9._-]+$#`), poi autorizza (owner o admin) e fa streaming.
   Tutte le preview/lightbox del pannello passano di qui. Trucco collegato in
   `AppServiceProvider`: `buildTemporaryUrlsUsing()` reindirizza su questa rotta
   anche le anteprime che Filament `FileUpload` chiederebbe al disco.

2. **L'ESP** → `GET /api/v1/photos/{id}/render` (`ImageController`): token Sanctum
   + la foto deve stare nel programma attivo del device. Ridimensiona a 320×240
   (`cover`: riempie e ritaglia), JPEG qualità 80, con ETag/304 suo.
   **Render cache**: il JPEG renderizzato viene salvato in
   `storage/app/private/render-cache/{photoId}-{WxH}-{etag}.jpg` — il Raspberry
   decodifica ogni originale UNA volta, non a ogni richiesta. L'invalidazione è
   "by name": se il file cambia, cambia l'etag → nome nuovo (i vecchi vengono
   spazzati al render successivo e alla cancellazione della foto).

3. **Nessun'altra.** Il `render_url` dentro `/sync` punta alla via 2.

Il ciclo di vita dei FILE è agganciato agli eventi Eloquent in `Photo::booted()`:
`deleted` → cancella file + render cache; `updated` con `image_path` cambiato →
cancella il file vecchio. Il comando schedulato `photos:prune-files` è la rete di
sicurezza per gli orfani (upload abbandonati in `livewire-tmp/`, file senza riga).

---

## 11. Parti custom: come si esce dai binari di Filament

Ricetta generale usata in questo progetto, dal meno al più invasivo:

1. **`ViewColumn`** → una colonna con un Blade tuo. Es: il lightbox foto
   (`resources/views/tables/columns/photo-lightbox.blade.php`) — Alpine.js per
   l'overlay, dentro una normale colonna di tabella.
2. **Hook sulle Pages** (`afterCreate`, `mutateFormDataBeforeSave`…) → logica
   extra senza toccare la UI. Es: il resize a 320×240 in `CreatePhoto`.
3. **Actions con form** → qualsiasi flusso modale custom (claim device, upload
   col crop 4:3 — `FileUpload::imageEditor()` con aspect ratio bloccato).
4. **Componente Livewire incastonato** → quando serve una UI completamente nuova
   (il calendario, §7): `Schemas\Components\Livewire::make(...)` dentro un form
   Filament. Le Filament Actions restano disponibili dentro il componente
   (`InteractsWithActions`), quindi i modali rimangono "gratis".
5. **Tema Tailwind custom** → quando servono classi che il CSS di Filament non
   contiene (§8.1).

La regola pratica: **resta sui mattoncini finché puoi** (li mantiene Filament),
scendi di livello solo per il pezzo che davvero non esiste.

---

## 12. Far girare il progetto

```bash
# sviluppo (server + vite + queue + log)
composer run dev
# oppure separati:
php artisan serve            # backend su http://127.0.0.1:8000
npm run dev                  # tema in watch

# database da zero + dati di prova
php artisan migrate:fresh --seed

# test (57+: pagine Filament, foto, API sync, regressioni)
php artisan test

# se l'interfaccia "non aggiorna" in dev
php artisan filament:clear-cached-components
php artisan view:clear
```
Utenti di prova (password `password`): `admin@…`, `marco@…` (alarm),
`angelica@…` (photo). Il sito è alla **root** (`/`), il login è `/login`.

**Deploy sul Raspberry** (vedi anche il README principale):
```bash
git pull && composer install --no-dev
npm run build                    # SOLO se è cambiato il tema/le classi nei Blade
php artisan migrate --force
php artisan optimize             # config+route+view+event cache
php artisan filament:optimize    # cache componenti+icone (sul Pi si sente)
sudo systemctl reload php8.2-fpm # svuota OPcache
```
Due promemoria: (1) il pannello usa un tema Vite custom (§8.1) e `public/build/`
è in `.gitignore` → `npm run build` va eseguito almeno una volta sul server, e
ri-eseguito quando cambi `theme.css` o classi Tailwind nei Blade; (2) con
`optimize` la config è congelata — dopo ogni modifica al `.env` (es. pubblicare
un firmware OTA) va rilanciato.

---

## 13. Glossario lampo

- **Eloquent / Model**: l'ORM di Laravel; una classe = una tabella.
- **Migration**: un file che descrive/crea una tabella.
- **Resource (Filament)**: la classe che genera il CRUD di un Model.
- **Form component / Table column**: i mattoncini di campi e colonne.
- **Action**: un bottone con logica (e spesso un modale).
- **Relation Manager**: tabella di una relazione dentro una pagina Edit.
- **Widget**: riquadro della dashboard.
- **Livewire component**: classe PHP + Blade che si aggiorna via AJAX.
- **Pivot**: la tabella-ponte di una relazione molti-a-molti (può avere colonne).
- **Scoping**: filtrare i dati per utente (qui in `getEloquentQuery()`).
- **Sanctum / personal access token**: token API; qui appartiene a un Device.
- **ETag / If-None-Match / 304**: caching HTTP — "ho la versione X" / "è ancora X, non ti mando nulla".
- **Route-model-binding**: `{alarm}` nell'URL → Laravel inietta l'istanza `Alarm`.
- **saveQuietly()**: salva senza scatenare gli eventi del Model.

---

## 14. Percorso consigliato per leggere questo repo

1. `app/Models/Device.php` → entità, relazioni, l'hook del pairing code.
2. `routes/api.php` → la mappa dell'API in 30 righe commentate.
3. `app/Http/Controllers/Api/V1/PairController.php` → token e pairing (§9.1–9.2).
4. `app/Http/Controllers/Api/V1/SyncController.php` → l'ETag content-based (§9.3).
5. `app/Filament/Resources/AlarmResource.php` → il Resource più "classico":
   `form()`, `table()`, `getEloquentQuery()`, e la storia n°4 del §8.
6. `app/Filament/Resources/DeviceResource.php` + `Pages/ListDevices.php` →
   ruoli, azioni custom, flusso di claim.
7. `app/Livewire/DailyPhotoCalendar.php` → Livewire "a mano" (§7).
8. `app/Http/Controllers/PhotoFileController.php` + `ImageController.php` → foto private (§10).
9. `tests/Feature/` → ogni test racconta un comportamento voluto.
10. Poi passa al firmware: `docs/COME_FUNZIONA_FIRMWARE.md`.

Per ogni dubbio, la doc ufficiale Filament è ottima: filamentphp.com/docs.
