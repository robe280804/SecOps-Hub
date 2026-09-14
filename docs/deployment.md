# Deploy del runtime su server Ubuntu (Hetzner)

Questo documento copre avvio e arresto degli ambienti, gateway terminale e quota del workspace. API, frontend, database e Redis seguono un deploy Laravel ordinario. Per il contratto degli endpoint vedere [frontend/ENVIRONMENTS.md](../frontend/ENVIRONMENTS.md#runtime-setup); per l'architettura completa [feature/environment.md](feature/environment.md).

Il codice applicativo è identico a quello usato in locale: stesso job, stesso provisioner, stesse chiamate Docker Engine API. Cambiano la configurazione e un vincolo che in locale è disattivato.

## Il vincolo che cambia rispetto al locale

`EnvironmentProvisioner` rifiuta di creare un workspace senza quota:

```php
// backend/app/Services/EnvironmentProvisioner.php
if (! $sizeOption && ! (app()->environment('local', 'testing') && config('environments.runtime.allow_unlimited_storage'))) {
    throw new RuntimeException('A volume driver with workspace quota support must be configured.');
}
```

Con `APP_ENV=production` la deroga locale non si applica. Se `ENVIRONMENTS_VOLUME_SIZE_OPTION` è vuoto **ogni avvio fallisce** e l'ambiente passa in `error`. Configurare la quota è quindi un prerequisito, non un'ottimizzazione.

## Quota del workspace con XFS

Il driver `local` integrato in Docker applica l'opzione `size` tramite le project quota XFS, [dalla 20.10](https://github.com/moby/moby/pull/41330). Il requisito è che la **data-root di Docker** stia su un filesystem XFS montato con `prjquota`. Non serve alcun plugin di volume.

Le immagini Hetzner Cloud usano ext4 sulla partizione di root e non va riformattata: si collega un [Volume Hetzner](https://docs.hetzner.com/cloud/volumes/getting-started/creating-a-volume/) dedicato e si sposta lì `/var/lib/docker`.

```sh
# 1. Individuare il volume collegato (l'ID compare nella console Hetzner).
ls -l /dev/disk/by-id/ | grep HC_Volume

# 2. Formattare XFS. ATTENZIONE: cancella il contenuto del volume.
DEV=/dev/disk/by-id/scsi-0HC_Volume_XXXXXXXX
mkfs.xfs "$DEV"

# 3. Fermare Docker e spostare la data-root esistente.
systemctl stop docker docker.socket
mkdir -p /mnt/docker-data
mount -o prjquota "$DEV" /mnt/docker-data
rsync -aHAX --numeric-ids /var/lib/docker/ /mnt/docker-data/
umount /mnt/docker-data
mv /var/lib/docker /var/lib/docker.bak

# 4. Montare in modo persistente su /var/lib/docker.
mkdir -p /var/lib/docker
echo "$DEV /var/lib/docker xfs defaults,prjquota 0 2" >> /etc/fstab
systemctl daemon-reload
mount /var/lib/docker
systemctl start docker
```

Verificare che la quota sia davvero attiva **prima** di procedere: senza `prjquota` Docker accetta il volume ma non applica alcun limite.

```sh
# Deve elencare prjquota tra le opzioni di mount.
findmnt -no OPTIONS /var/lib/docker

# Deve creare il volume senza errori, poi rimuoverlo.
docker volume create -o size=1G secops-quota-probe && docker volume rm secops-quota-probe
```

Rimuovere `/var/lib/docker.bak` solo dopo aver confermato che i container e le immagini preesistenti sono integri.

Il provisioner invia il limite come intero di byte (`size: "5368709120"`); Docker lo interpreta con `units.RAMInBytes()`, dove il suffisso di unità è opzionale, quindi il valore grezzo è corretto.

## Immagine approvata

Il provisioner **non fa pull e non builda**: l'immagine deve già esistere sul nodo runtime, altrimenti la creazione del container fallisce. `php artisan environments:check` lo verifica in anticipo.

Costruirla secondo [docker/kali/README.md](../docker/kali/README.md), pubblicarla nel proprio registry e configurare `ENVIRONMENTS_APPROVED_IMAGES` con il **digest** risultante, non con un tag mutabile:

```sh
docker build -t registry.example.com/secops-hub/kali:1 docker/kali
docker push registry.example.com/secops-hub/kali:1
docker image inspect --format '{{index .RepoDigests 0}}' registry.example.com/secops-hub/kali:1
```

Il digest ottenuto va sia in `ENVIRONMENTS_APPROVED_IMAGES` sia, se il nodo runtime è distinto, nel `docker pull` eseguito su quel nodo. La build completa occupa diversi GB: dimensionare il volume tenendo conto dell'immagine oltre che dei workspace.

## Configurazione

In `backend/.env`, oltre alla configurazione Laravel di produzione (`APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `FRONTEND_URL` e `SANCTUM_STATEFUL_DOMAINS` con gli host reali):

```dotenv
ENVIRONMENTS_RUNTIME_ENABLED=true
ENVIRONMENTS_APPROVED_IMAGES=registry.example.com/secops-hub/kali@sha256:...
ENVIRONMENTS_DOCKER_SOCKET=/var/run/docker.sock
ENVIRONMENTS_QUEUE_CONNECTION=redis
ENVIRONMENTS_VOLUME_DRIVER=local
ENVIRONMENTS_VOLUME_SIZE_OPTION=size
ENVIRONMENTS_ALLOW_UNLIMITED_STORAGE=false
REDIS_QUEUE_RETRY_AFTER=240
```

`ENVIRONMENTS_RUNTIME_ENABLED=true` serve **anche al processo API**, non solo al worker: la capability `start` esposta al frontend viene calcolata dall'API. Dopo una modifica di `.env` riavviare l'API ed eseguire `php artisan config:clear` se la configurazione è in cache.

Per un daemon Docker remoto lasciare vuoto `ENVIRONMENTS_DOCKER_SOCKET`, impostare un `ENVIRONMENTS_DOCKER_URL` **https** e i tre percorsi TLS: `DockerEngine` rifiuta il TCP in chiaro. Il socket locale resta la scelta più semplice quando worker e runtime stanno sullo stesso nodo.

## Avvio di worker e scheduler

`compose.prod.yaml` esegue Horizon e lo scheduler in container Linux. Richiede `SECOPS_WORKER_DB_HOST` e `SECOPS_WORKER_REDIS_HOST` (il compose fallisce se mancano) e monta `backend/.env` in sola lettura.

```sh
export SECOPS_WORKER_DB_HOST=10.0.0.2
export SECOPS_WORKER_REDIS_HOST=10.0.0.3
export SECOPS_WORKER_IMAGE=registry.example.com/secops-hub/worker:1
export SECOPS_TERMINAL_ORIGIN=https://app.example.com
export SECOPS_TERMINAL_API_URL=https://api.example.com

docker compose -f compose.prod.yaml build worker terminal
docker compose -f compose.prod.yaml run --rm --no-deps worker php artisan migrate --force --no-interaction
docker compose -f compose.prod.yaml run --rm --no-deps worker php artisan environments:check
docker compose -f compose.prod.yaml up -d --wait worker scheduler terminal
```

Il servizio `worker` monta il socket Docker: equivale ad accesso root sull'host. Va tenuto su un nodo fidato e il socket non va mai montato negli ambienti utente. Lo scheduler esegue `environments:recover`, che riaccoda le operazioni di avvio interrotte prima del dispatch; se non gira, un crash tra commit e dispatch lascia l'operazione bloccata in `pending`.

`stop_grace_period` è 210s per non troncare un provisioning in corso: il job ha timeout 180s e lock a 210s. Dopo ogni deploy del codice eseguire `php artisan horizon:terminate` per far ripartire i worker con la versione nuova.

## Verifica

```sh
docker compose -f compose.prod.yaml exec worker php artisan environments:check --worker
```

Poi dal frontend: attivare il progetto, creare l'ambiente e usare **Start environment**. L'ambiente passa a `running` quando il container è avviato; `ready` resta riservato alla readiness del terminale, non ancora implementata. In caso di errore il messaggio all'utente è deliberatamente generico: la diagnosi sta nei log del worker (`docker compose -f compose.prod.yaml logs worker`).

## Terminale browser

Abilitare `ENVIRONMENTS_TERMINAL_ENABLED=true` nell'API. Il servizio `terminal` deve stare sul nodo del runtime e monta il socket Docker, come servizio fidato. La porta 7681 viene pubblicata solo su loopback. `SECOPS_TERMINAL_ORIGIN` deve coincidere esattamente con l'origine del frontend; `SECOPS_TERMINAL_API_URL` indica il backend raggiungibile dal gateway. Usare TLS verificato per il backend remoto.

Il browser deve inviare il cookie di sessione anche a `/terminal/`. Il deploy supportato usa API e terminale dietro il proxy della stessa origine del frontend; con cookie limitati a un sottodominio API l'iframe non può autenticarsi. Il dominio frontend deve essere incluso in `SANCTUM_STATEFUL_DOMAINS`.

Nel virtual host HTTPS del frontend, preservare il percorso e l'upgrade WebSocket:

```nginx
location /terminal/ {
    proxy_pass http://127.0.0.1:7681;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_read_timeout 1000s;
    proxy_buffering off;
    access_log off;
}
```

Il gateway verifica l'utente con Laravel e le label Docker prima del collegamento. Rilegge le autorizzazioni ogni cinque secondi; gli errori chiudono la connessione, con il ritardo dei timeout di rete. Le sessioni scadono dopo 15 minuti e il pulsante Reconnect ne apre una nuova. Non vengono registrati input e output del terminale; Laravel registra l'emissione dell'accesso senza il ticket. Evitare ticket nei log del proxy.

ttyd esegue `docker exec -it ... tmux new-session -A -s secops -c /workspace`. Non servono ttyd, socket Docker o porte pubblicate dentro Kali; tmux è già nell'immagine. Chiudere il terminale lascia la shell in tmux; Stop termina i processi ma mantiene lo stesso container e il volume. Start riusa entrambi.

## Limiti noti di questo deploy

- La quota copre il **volume workspace**, non il layer scrivibile del container: il provisioner non imposta `HostConfig.StorageOpt`. Un ambiente può ancora scrivere fuori da `/workspace` fino a riempire la data-root.
- La policy predefinita `blocked` impedisce l'egress. `filtered` richiede l'helper firewall e consente destinazioni pubbliche secondo la configurazione dell'ambiente.
- Ricreazione e cancellazione runtime restano da implementare. Archiviare un progetto revoca la shell ma non spegne automaticamente i suoi ambienti; il proprietario può usare Stop.
- Non c'è riconciliazione continua tra database e Docker. Le installazioni manuali sopravvivono allo stop ma non a una futura ricreazione; solo il workspace ha un volume indipendente.
- `docs/feature/environment.md` prevede nodi runtime separati da Laravel, database e Redis. Un singolo server Hetzner con tutto sullo stesso host non soddisfa quel requisito di isolamento: adeguato per una prima validazione, non per shell non fidate.
