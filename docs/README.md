# Documentazione tecnica

I file in `models/` descrivono entità, relazioni e vincoli. I file in `feature/` spiegano il comportamento completo di ciascuna funzionalità, includendo API, job, runtime, frontend e sicurezza, senza una cartella per ogni componente.

| Documento | Contenuto |
| --- | --- |
| [Modello Project](models/project.md) | Progetti, collaboratori, autorizzazioni e API esistenti |
| [Modelli ambiente e scansioni](models/environment.md) | Proposte per ambienti, scope, profili ed entità operative collegate |
| [Feature ambiente](feature/environment.md) | Docker Engine API, Horizon, provisioning, terminale, eventi, inventario tool e isolamento |
| [Feature execution](feature/execution.md) | Shell libera e avvii gestiti, watcher, eBPF, correlazione, ingestione e parsing |
| [Autenticazione](authentication.md) | Flussi e configurazione dell'autenticazione |
| [Stack infrastrutturale](infrastructure.md) | Elenco sintetico delle tecnologie previste |
| [Deploy del runtime](deployment.md) | Server Ubuntu/Hetzner: quota XFS, immagine approvata, worker e scheduler |

## Aggiornamento: avvio, arresto e shell ambiente

Implementati CRUD, avvio e arresto asincroni, volume workspace, rete isolata o egress filtrato e limiti CPU/memoria/processi. Il frontend offre Start, Stop, polling e terminale ttyd con tmux tramite gateway Docker exec. Le sessioni durano 15 minuti, richiedono la sessione autenticata del proprietario e vengono rivalutate durante il collegamento. Lo stato `running` indica il container avviato: il gateway verifica il runtime anche all'apertura della shell.

Il runtime e il terminale vanno abilitati e configurati esplicitamente. Setup e limiti sono in [frontend/ENVIRONMENTS.md](../frontend/ENVIRONMENTS.md#runtime-setup). Su Windows, `docker/start-local.ps1` avvia worker, scheduler e gateway; il collegamento a Herd può usare la CA locale. Per Ubuntu vedere [deployment.md](deployment.md). I test backend simulano Docker; il gateway verifica il protocollo con ttyd reale e un comando di test. Ricreazione, cancellazione runtime, riconciliazione continua e collector restano da implementare. Il recupero periodico riguarda avvii e arresti interrotti.

## Stato storico prima del CRUD ambiente

La base Project e membership è implementata. Horizon è configurato nel backend, ma il suo supervisor ascolta attualmente soltanto la coda `default`. Non sono presenti modelli ambiente, provisioner Docker, job di scansione, collector eBPF o integrazione terminale.

Sono presenti configurazione broadcasting e un canale utente; `laravel/reverb` non è tra le dipendenze dirette e manca il canale privato di progetto. Non considerare quindi operativo il flusso Reverb/Echo descritto nelle feature.

Le nuove feature sono **proposte progettuali da implementare e validare**. I nomi di job, eventi, endpoint e payload sono contratti proposti, non API già disponibili. Le note integrano la conversazione fornita, correggendone gli esempi dove non garantivano il comportamento descritto. Le fonti tecniche sono collegate vicino ai punti pertinenti.
