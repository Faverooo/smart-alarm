# Come funziona il progetto (e un po' di Filament)

Guida didattica per capire **cosa fa ogni pezzo** di questo progetto. Presuppone
che tu sappia programmare ma che Filament sia nuovo. Ogni concetto è spiegato
mostrandolo **dentro questo codice**, con i file da aprire.

> Compagno di questo file: `docs/IMPLEMENTATION_GUIDE.md` = la roadmap tecnica
> (API, Raspberry, ESP32). Qui invece spieghiamo **com'è fatto** quello che c'è.

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
| **Laravel 11** (PHP 8.3) | Framework di base: routing, ORM (Eloquent), migrazioni, ecc. |
| **Filament 3** | Costruisce il pannello di amministrazione (CRUD, form, tabelle) **scrivendo classi PHP**, non HTML. |
| **Livewire 3** | Sotto Filament: rende le pagine "reattive" (aggiornamenti senza ricaricare) parlando col server via AJAX. Filament è costruito su Livewire. |
| **Alpine.js** | Micro-interattività lato browser (modali, toggle). Arriva con Livewire. |
| **Tailwind CSS** | Le classi di stile (`flex`, `rounded-lg`…). Filament ha un suo tema Tailwind. |
| **SQLite** | Database su file (`database/database.sqlite`). Zero configurazione, perfetto per il Raspberry. |
| **Sanctum** | Token di autenticazione per l'API del dispositivo. |
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
│   ├── Http/Controllers/Api/V1/    # l'API per l'ESP32
│   ├── Console/Commands/           # comandi artisan custom (prune dei device)
│   └── Providers/Filament/AdminPanelProvider.php   # configurazione del pannello
├── database/migrations/           # schema del DB (le tabelle)
├── resources/
│   ├── views/livewire/            # i template Blade del calendario
│   ├── views/tables/columns/      # il template del lightbox foto
│   └── css/filament/admin/        # il TEMA Filament custom (Tailwind)
├── routes/
│   ├── web.php                    # rotte web (quasi vuoto: il pannello è a root)
│   ├── api.php                    # rotte API dell'ESP32
│   └── console.php                # scheduler (prune device)
└── tests/Feature/                 # test automatici
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

- **`form(Form $form)`** → descrive il modulo di creazione/modifica con dei
  *componenti di form* (`TextInput`, `Select`, `Toggle`, `DateTimePicker`…).
- **`table(Table $table)`** → descrive la tabella elenco con delle *colonne*
  (`TextColumn`, `IconColumn`, `ImageColumn`/`ViewColumn`), filtri e azioni.
- **`getPages()`** → le pagine del Resource: `index` (List), `create`, `edit`.

Esempio mentale (da `AlarmResource.php`):
```php
Forms\Components\TimePicker::make('time')   // un campo orario nel form
    ->label('Alarm Time')
    ->seconds(false);                        // niente secondi
Tables\Columns\IconColumn::make('is_active') // una colonna con icona ✓/✗
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
  classico bottone "Create".

### 4.4 Form components & Table columns
Sono i "mattoncini". Li configuri con metodi a catena (fluent):
```php
Select::make('device_id')
    ->options($listaDispositivi)   // cosa mostrare
    ->searchable()                 // ricerca
    ->required();                  // validazione
```
La validazione (`required`, `maxLength`…) la dichiari sul componente: Filament la
applica sia lato client che server.

### 4.5 Actions — bottoni che "fanno qualcosa"
Un'**Action** è un bottone con (opzionale) un modale/form e una `->action(closure)`
che esegue codice. Esempio in `DeviceResource` la riga "Become Alarm Manager":
```php
Tables\Actions\Action::make('becomeAlarmManager')
    ->requiresConfirmation()
    ->visible(fn (Device $r) => $r->alarm_user_id === null) // quando appare
    ->action(fn (Device $r) => $r->update(['alarm_user_id' => auth()->id()]));
```
Ci sono Actions di tabella (per riga), header actions (in alto), e azioni dentro i
form. I modali (upload foto, conferme) sono Actions con un `->form([...])`.

### 4.6 Relation Manager — `Resources/.../RelationManagers/`
Quando una pagina Edit deve gestire una **relazione**, usi un Relation Manager: è
una mini-tabella dentro la pagina. Qui `PhotosRelationManager` mostra/gestisce le
foto di un Programma (relazione molti-a-molti `programs ↔ photos`). Ha i suoi
header actions (Upload / Attach Existing) e azioni di riga (Edit / Remove).

### 4.7 Widget — `app/Filament/Widgets/`
Riquadri della dashboard. Qui due `StatsOverviewWidget`:
`AlarmDashboardWidget` (sveglie attive) e `PhotoDashboardWidget` (foto/messaggi).
Ognuno ha `canView()` per mostrarsi solo al ruolo giusto.

### 4.8 Sotto al cofano: Livewire
Ogni pagina/tabella/azione Filament è in realtà un **componente Livewire**. Quando
clicchi un bottone, Livewire manda una richiesta AJAX al server, esegue il metodo
PHP, e ri-renderizza solo il pezzo cambiato. Per questo "sembra" una SPA pur
scrivendo solo PHP. (Vedi §8 per una trappola che ne deriva.)

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
  `photo_user_id`, `program_id`): il "two-key" sono i due `*_user_id`.
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

Risultato: lo **stesso codice** mostra interfacce diverse a persone diverse.

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
componente `Forms\Components\Livewire::make(DailyPhotoCalendar::class, ...)`.

---

## 8. Tre "storie" che insegnano le insidie di Filament

Queste sono cose che abbiamo scoperto debuggando: ti fanno capire il funzionamento.

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

---

## 9. L'API per l'ESP32 (riassunto)

File: `app/Http/Controllers/Api/V1/`, `routes/api.php`.
Spiegata a fondo in `docs/IMPLEMENTATION_GUIDE.md`. In breve:
- `POST /api/v1/pair/init {mac}` → il device si registra, riceve un **token** e un
  **codice** da mostrare a schermo (finestra di pairing 1h).
- `GET /api/v1/sync` → il device scarica il minimo indispensabile (sveglie attive,
  1 foto se *daily*, messaggi non letti) con cache **ETag/304**.
- `POST .../dismissed`, `.../read`, `.../shown` → il device comunica le interazioni.

L'autenticazione del device usa **Sanctum**: il token nell'header
`Authorization: Bearer …` identifica il `Device` (il modello usa `HasApiTokens`).

---

## 10. Far girare il progetto (comandi quotidiani)

```bash
# avvia tutto (server + vite + queue + log) — alias comodo del progetto
composer run dev
# oppure, separati:
php artisan serve            # backend su http://127.0.0.1:8000
npm run dev                  # asset/tema in watch

# database da zero + dati di prova
php artisan migrate:fresh --seed

# test
php artisan test

# se l'interfaccia "non aggiorna" in dev
php artisan filament:clear-cached-components
php artisan view:clear
```
Utenti di prova (password `password`): `admin@…`, `marco@…` (alarm),
`angelica@…` (photo). Il sito è alla **root** (`/`), il login è `/login`.

---

## 11. Glossario lampo

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

---

## 12. Percorso consigliato per imparare leggendo questo repo

1. Apri `app/Models/Device.php` → capisci entità e relazioni.
2. Apri `app/Filament/Resources/AlarmResource.php` → è il Resource più "classico":
   guarda `form()`, `table()`, `getEloquentQuery()`.
3. Apri `app/Filament/Resources/MessageResource.php` → variante più semplice.
4. Apri `PhotoResource` + `Pages/CreatePhoto.php` → upload + hook `afterCreate`.
5. Apri `ProgramResource` + `RelationManagers/PhotosRelationManager.php` → relazioni.
6. Apri `app/Livewire/DailyPhotoCalendar.php` → Livewire "a mano".
7. Apri `app/Http/Controllers/Api/V1/SyncController.php` → l'API.
8. Per ogni dubbio, la doc ufficiale Filament è ottima: filamentphp.com/docs.
