# Immagine Kali di SecOps Hub

La selezione globale dei 53 tool è in `tools.json`: ogni voce è abilitata per default. Impostare `false` per non richiederne l'installazione, poi ricostruire l'immagine dalla radice del repository:

```sh
docker build --progress=plain -t secops-hub/kali:local docker/kali
```

Le modifiche si applicano alle nuove immagini; non modificano i container già creati. Una voce omessa resta abilitata. Valori non booleani, nomi sconosciuti e tool duplicati tra gruppi fanno fallire la build. Le dipendenze possono installare tool disabilitati: per esempio AutoRecon dipende da Nmap e da altri scanner. `false` significa quindi «non installare esplicitamente», non «vietare la presenza».

La base Kali è fissata tramite digest nel Dockerfile. Python, pip, venv, pipx, Go, Ruby e strumenti di compilazione sono sempre disponibili. I pacchetti presenti in Kali vengono installati tramite APT. Volatility 3, Prowler, Scout Suite e kube-hunter usano ambienti Python 3.11 separati gestiti da uv, senza modificare i pacchetti Python del sistema. kube-bench viene compilato con Go; Docker Bench viene installato dal repository ufficiale.

Le versioni APT e Python e le revisioni dei sorgenti risolte durante la build sono registrate in `/opt/secops-image/`. I repository APT e le dipendenze applicative rimangono aggiornabili: il digest della base da solo non rende riproducibile l'intera build. Per distribuire l'immagine, pubblicarla nel proprio registry e configurare `ENVIRONMENTS_APPROVED_IMAGES` con il riferimento e digest risultanti.

## Nomi dei comandi

- `httpx` punta a `httpx-toolkit` di ProjectDiscovery, non alla libreria Python omonima.
- `strings` e `objdump` sono forniti da `binutils`.
- `exiftool` è fornito da `libimage-exiftool-perl`.
- `scout-suite` è disponibile anche come `scout`.
- `volatility3` è disponibile anche come `vol`.
- `kube-bench` trova le proprie configurazioni anche quando eseguito da `/workspace`.

## Installazione e requisiti di esecuzione

L'immagine non avvia scansioni, demoni Docker, desktop o terminali web. Non richiede automaticamente modalità privileged, rete host o socket Docker. Il comando predefinito mantiene il container attivo; il provisioning e il terminale di SecOps Hub restano funzionalità distinte.

- Ghidra può essere usato in modalità headless; la GUI richiede un display configurato separatamente.
- Hashcat richiede un backend di calcolo compatibile; GPU e driver non vengono aggiunti automaticamente al container.
- Prowler, Scout Suite e varie sorgenti OSINT richiedono credenziali o API key fornite al runtime, mai incorporate nell'immagine.
- kube-bench e Docker Bench richiedono accesso al nodo da valutare. Un normale workspace isolato non può verificare l'host. Non montare il socket Docker della piattaforma negli ambienti utente per abilitarli.
- kube-hunter non è più sviluppato attivamente; il progetto upstream consiglia Trivy. CrackMapExec è mantenuto come tool distinto da NetExec per rispettare la selezione richiesta.
- L'installazione dei tool non comprende necessariamente database, template, tabelle rainbow, browser headless o altri dataset scaricati al primo utilizzo.

La build completa occupa diversi GB. Il limite storage del workspace e i limiti CPU/memoria del container sono separati dalle dimensioni dell'immagine. I default minimi degli ambienti non sono adeguati a ogni carico di lavoro.

## Verifiche

I test della selezione vengono eseguiti durante la build. Le installazioni Python devono superare `--help`; kube-bench deve avviarsi con `--help`; Docker Bench viene controllato con `sh -n`, senza eseguire audit durante la build.

```sh
docker run --rm secops-hub/kali:local python3 /opt/secops-image/install.py /opt/secops-image/tools.json --plan
docker run --rm secops-hub/kali:local cat /opt/secops-image/installed-tools.json
```

Riferimenti: [Kali Docker](https://www.kali.org/docs/containers/using-kali-docker-images/), [uv tools](https://docs.astral.sh/uv/guides/tools/), [kube-hunter](https://github.com/aquasecurity/kube-hunter), [kube-bench](https://github.com/aquasecurity/kube-bench), [Docker Bench](https://github.com/docker/docker-bench-security).
