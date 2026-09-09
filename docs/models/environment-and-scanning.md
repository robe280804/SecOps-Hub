# Ambienti, scope e profili di scansione

Stato: **bozza futura da revisionare**, esclusa dalla prima implementazione del progetto. Le proposte qui raccolte non sono decisioni approvate né richiedono migration in questa fase.

Il modello principale è descritto in [project.md](project.md). Un progetto deve poter essere creato e gestito senza ambienti, profili o configurazioni di scansione.

## Relazioni proposte per la fase successiva

```mermaid
erDiagram
    PROJECTS ||--o| PROJECT_SETTINGS : configura
    PROJECTS ||--o{ PROJECT_ENVIRONMENTS : contiene
    PROJECTS ||--o{ PROJECT_SCOPE_ENTRIES : delimita
    PROJECTS ||--o{ PROJECT_SCAN_PROFILES : definisce
```

## Settings generali — `project_settings`

Relazione uno a uno opzionale, introdotta quando verrà attivato il modulo scansioni. `project_id` è sia PK sia FK, evitando righe duplicate. La prima creazione del progetto non richiede questa tabella; in futuro l'esecuzione richiederà impostazioni valide.

| Campo | Significato |
| --- | --- |
| `project_id` | FK verso il progetto |
| `max_concurrent_scans` | Limite positivo complessivo per progetto; default proposto 1 |
| `max_concurrent_scans_per_user` | Limite positivo per utente; default proposto 1 |
| `scan_timeout_seconds` | Durata massima consentita per singola esecuzione |
| `created_at`, `updated_at` | Date |

I limiti sono configurabili dal proprietario entro massimali della piattaforma. Il proprietario ha piena gestione funzionale, ma non può disattivare i vincoli infrastrutturali. Per l'MVP, «quanto possono eseguire» significa permessi e limiti condivisi per utente; quote diverse per ciascun collaboratore sono una possibile estensione da confermare.

## Ambienti — `project_environments`

Relazione progetto uno a molti: un progetto può avere zero o più ambienti, evitando una futura conversione da uno a uno.

| Campo | Significato |
| --- | --- |
| `id`, `project_id` | Identificatore e FK obbligatoria |
| `name` | Nome univoco all'interno del progetto |
| `description` | Testo facoltativo |
| `base_image` | Riferimento immagine, idealmente con digest per riproducibilità |
| `status` | Stato gestito dal backend: `pending`, `provisioning`, `stopped`, `running`, `error`, `deleting` |
| `runtime_reference` | Riferimento interno al runtime, nullable prima del provisioning; non scrivibile dal client |
| `network_configuration` | JSON validato: modalità IP automatica/statica, eventuale IP privato richiesto, resolver DNS, domini di ricerca |
| `resource_limits` | JSON validato: CPU, memoria e storage entro limiti infrastrutturali |
| `created_at`, `updated_at` | Date |

IP effettivo, identificativi container e percorsi dei volumi sono dati operativi assegnati dall'orchestratore, non valori arbitrari scelti via API. Un IP statico richiesto deve appartenere alla rete isolata assegnata all'ambiente. Il DNS dell'ambiente configura la risoluzione dei nomi: non concede l'autorizzazione a scansionare quei nomi.

I JSON sono ammessi qui per piccoli oggetti di configurazione con schema chiuso e versionabile, non per membership, permessi o relazioni. Non contengono password, token o chiavi private. Il futuro modulo secrets conserverà i segreti separatamente e le configurazioni useranno riferimenti autorizzati.

## Target autorizzati — `project_scope_entries`

IP e DNS dei target sono distinti dalla rete degli ambienti. Relazione progetto uno a molti.

| Campo | Significato |
| --- | --- |
| `id`, `project_id` | Identificatore e FK |
| `target_type` | `ip`, `cidr`, `domain`, `url` |
| `value` | Target validato e normalizzato secondo il tipo |
| `is_in_scope` | Inclusione (`true`) o esclusione (`false`) |
| `include_subdomains` | Applicabile solo a `domain`, default false |
| `notes` | Restrizioni o informazioni descrittive |
| `created_at`, `updated_at` | Date |

Vincolo unico proposto `(project_id, target_type, value)`: una stessa voce non può essere contemporaneamente inclusa ed esclusa. Le esclusioni prevalgono quando regole diverse si sovrappongono. Scope vuoto significa nessun target autorizzato. Solo il proprietario modifica lo scope.

Prima del modulo scansioni andranno definite precisamente le regole di matching di URL, sottodomini, redirect e risoluzione DNS. Le note testuali non diventano automaticamente vincoli eseguibili. Scope applicativo e isolamento di rete devono entrambi essere applicati: una riga nel database non protegge da sola la rete di produzione.

## Profili di scansione — `project_scan_profiles`

Relazione progetto uno a molti. Il proprietario definisce configurazioni riutilizzabili che i contributor autorizzati possono eseguire.

| Campo | Significato |
| --- | --- |
| `id`, `project_id` | Identificatore e FK |
| `name` | Nome univoco nel progetto |
| `tool_key` | Identificatore di un'integrazione supportata per esecuzioni controllate |
| `configuration` | JSON con schema specifico del tool: porte, timeout, concorrenza, limite richieste e opzioni ammesse |
| `enabled_for_members` | Default false; abilita il profilo per contributor con `scans.execute` |
| `created_at`, `updated_at` | Date |

Non contiene stringhe shell, argomenti liberi o credenziali. La possibilità del proprietario di installare tool liberamente resta distinta dalle integrazioni controllate delegabili ai collaboratori.

All'avvio si selezionano un ambiente e target appartenenti allo stesso progetto. Per questa prima proposta il permesso vale su tutti gli ambienti già avviati del progetto, usando solo profili abilitati; restrizioni per singolo ambiente o profilo richiederebbero ulteriori relazioni da concordare.

La futura entità di esecuzione conserverà autore, ambiente, target e copia della configurazione effettivamente usata: modificare un profilo non deve riscrivere lo storico. Il limite effettivo è il più restrittivo tra piattaforma, progetto e profilo.

## Accesso e ciclo di vita delle esecuzioni

Il proprietario gestisce ambienti, rete, installazioni e profili. I collaboratori non possono modificare gli ambienti. La shell libera permette anche di modificarli: per questo si propone di riservarla al proprietario e delegare ai contributor soltanto esecuzioni controllate.

Permessi futuri proposti: `scans.read` per risultati e log, `scans.execute` per avviare profili approvati e annullare proprie esecuzioni; il secondo richiede il primo. Un contributor non può avviare implicitamente un ambiente fermo né annullare le esecuzioni di altri utenti.

Solo i progetti attivi possono avviare esecuzioni. Passare a inattivo o archiviato impedisce nuovi avvii, annulla le esecuzioni in coda e richiede lo stop di quelle in corso. Lo stato del progetto non certifica che il container sia già fermo.

Revoche e autorizzazioni devono essere ricontrollate dai worker prima dell'avvio; per le esecuzioni già avviate interessate da una revoca viene richiesto lo stop. Quote e prenotazioni degli slot devono essere atomiche. Il purge futuro richiederà cleanup verificato di container, volumi e file: cancellare una riga SQL non rimuove queste risorse.

## Decisioni rimandate

- Limiti condivisi per utente oppure quote individuali per collaboratore.
- Accesso a tutti gli ambienti già avviati oppure assegnazioni per singolo ambiente/profilo.
- Schema dei parametri supportati da ciascun tool e regole precise di matching dello scope.
- Orchestrazione, isolamento di rete e gestione delle esecuzioni e dei relativi stati.

Questo documento conserva le proposte per una revisione successiva; non amplia il perimetro del modello Project corrente.
