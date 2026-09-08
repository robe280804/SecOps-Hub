# SecOps Hub

Piattaforma personale per centralizzare attività di cybersecurity: bug bounty, penetration test, CTF e security assessment. Un unico posto dove definire progetti, lanciare tool in ambienti isolati e dedicati, tracciare findings, e farsi assistere da agenti AI configurabili per progetto.

## Perché esiste

Oggi il lavoro di test (Kali VM, HexStrike AI, script sparsi, appunti su bug bounty e assessment) è distribuito su ambienti e note diverse, senza uno storico interrogabile né un modo semplice per far collaborare un'AI sulle attività in corso. SecOps Hub nasce per risolvere questo: un progetto = un ambiente isolato + uno storico completo + un layer AI opzionale.

## Cosa permette di fare

- **Gestire progetti**: ogni bug bounty program, assessment o CTF è un progetto con il proprio scope (target in/out of scope), le proprie note e la propria timeline.
- **Ambiente dedicato per progetto**: container isolato con i tool che preferisci — nessuna whitelist, installi quello che ti serve (apt, pip, go, cargo, gem) esattamente come faresti su una VM Kali, ma tracciato e persistente.
- **Terminale integrato nel browser**: apri uno o più terminali direttamente nel container del progetto, senza uscire dalla piattaforma. Ogni sessione viene registrata (comandi + output) per audit e per costruire i report senza riscrivere tutto a mano.
- **Snapshot degli ambienti**: quando un ambiente è pronto, lo puoi "congelare" in una versione riutilizzabile o clonabile su un altro progetto.
- **Findings tracciati**: ogni vulnerabilità trovata ha stato (da verificare / confermato / reportato), severità, evidence allegate — niente più appunti sparsi.
- **AI e MCP configurabili per progetto**: scegli quali strumenti l'AI può usare (scan, lettura findings, ecc.) e quanta autonomia le dai, dal semplice "propone" al "esegue in autonomia" da tenere stretto su target reali.
- **Notifiche**: eventi importanti (finding critico, scan completato) arrivano su Gmail e Mattermost.
- **Report finale**: generazione documento formale a partire da findings ed evidence raccolte.

## Isolamento e sicurezza

Ogni ambiente di progetto gira isolato dalla rete di produzione. Non è un dettaglio opzionale: uno strumento offensivo lanciato per errore contro il target sbagliato è un rischio reale, non solo un bug. Questo aspetto è descritto in dettaglio in `README-CREATION.md`.

## Stato del progetto

In fase di design. Le decisioni architetturali principali (terminale integrato, gestione tool, livello di autonomia AI) sono documentate e in corso di definizione — vedi `README-CREATION.md` per il dettaglio tecnico e le roadmap di implementazione.

## Opzioni aggiuntive

- L'ai sarà a carico dell'utente, ovvero questo nei settings potrà eseguire l'accesso o collegarlo via api e spawnarla nel terminale dell'ambiente (da vedere come vincolarla a quell'ambiente come una sandbox)
