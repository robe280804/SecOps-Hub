# Project environments

Open a project you own and use its **Environments** section.

- Create an environment using an approved image, resource limits and optional DNS settings. Defaults and limits come from the project's environment options endpoint. After saving, the new environment's details open automatically.
- Browse the paginated list and open details to read configuration and runtime status.
- Edit the name, description, image, resources and network before provisioning. Provisioned environments allow descriptive edits when the API grants update access.
- Delete an eligible environment after confirming permanent deletion. Deleting the last item on a page returns to the previous page.

Archived projects are read-only. Creation respects the project quota and approved image catalog; edit and delete actions respect the capabilities returned by the API. Validation errors appear on the corresponding form controls. Failed reads can be retried, and writes are never automatically replayed. Canceling a dirty form asks whether to discard the changes.

Creation saves configuration only. On an active project, the owner can use **Start environment** when runtime startup is enabled. The separate `POST /api/v1/projects/{project}/environments/{environment}/start` endpoint records an operation and returns 202; repeated pending requests reuse that operation. The details view polls every five seconds while provisioning or starting.

**Running** means the container started. With the terminal gateway enabled, the owner can select **Open terminal**: a 15-minute session opens the bundled ttyd client and attaches to tmux in the existing container. Closing or reconnecting the terminal preserves the tmux shell. Access is checked on every HTTP/WS connection and periodically while connected; logout, loss of access, archive, expiry, stop or a different runtime invalidate it. Checks run every five seconds, with bounded upstream timeouts, so revocation is not instantaneous.

**Stop environment** asks for confirmation because it ends processes and scans. The stop endpoint returns 202 and the UI polls until stopped. **Start environment** then starts the same container, preserving manually installed tools and its workspace. Stop operations are retried and recovered by the scheduler, like starts. Recreation and runtime deletion remain unavailable. Runtime configuration stays immutable after a start attempt.

## Runtime setup

1. Apply backend migrations and build the approved image using `docker/kali/README.md`. The provisioner expects the image to exist on the runtime node; it does not pull or build it.
2. Run the Laravel provisioning worker on Linux, including a Linux environment under Docker Desktop. Native Windows PHP cannot use `/var/run/docker.sock`. The worker needs the same database, Redis and configuration as the API. Keep the Docker socket available only to the trusted worker.
3. Set `ENVIRONMENTS_RUNTIME_ENABLED=true` for API and worker. Use `ENVIRONMENTS_DOCKER_SOCKET` for a local Linux daemon. For remote Docker, clear the socket, set an HTTPS `ENVIRONMENTS_DOCKER_URL`, and configure the TLS CA, client certificate and key. Plain TCP is rejected. Docker API 1.48 or later is required; the client caps negotiation at 1.54.
4. Configure the workspace quota. The built-in `local` driver enforces `ENVIRONMENTS_VOLUME_SIZE_OPTION=size` through XFS project quotas, provided the Docker data-root is on XFS mounted with `prjquota`; `docs/deployment.md` covers that setup for an Ubuntu server. Verify the enforcement on the runtime host before relying on it: without `prjquota` Docker accepts the option and applies no limit. For local development only, `ENVIRONMENTS_ALLOW_UNLIMITED_STORAGE=true` explicitly permits the ordinary persistent Docker volume without storage enforcement. The container writable layer is not covered by the workspace quota.
5. Start Horizon with `php artisan horizon`, or a Linux worker with `php artisan queue:work redis --queue=provisioning --timeout=180 --tries=3`. Use `REDIS_QUEUE_RETRY_AFTER=240` or higher. Run Laravel's scheduler (`php artisan schedule:work` for development) so `environments:recover` can recover missed dispatches and interrupted operations.
6. Activate the project, create its environment and select **Start environment**. Check worker logs if startup fails. Retry recovers resources by deterministic names and ownership labels; missing previously recorded containers or workspaces require operator intervention rather than silent replacement.

On a Windows host with Docker Desktop, `docker/start-local.ps1` performs steps 1 to 5 using `compose.local.yaml`: it builds the approved image and a Linux worker image, writes the runtime settings into `backend/.env`, applies the migrations, runs `php artisan environments:check`, then starts the Horizon worker and the scheduler. Use `-SkipImageBuild` to reuse an image that is already built. The worker container mounts the Docker socket and reaches the host database and Redis through `host.docker.internal`; override `SECOPS_WORKER_DB_HOST` and `SECOPS_WORKER_REDIS_HOST` when they run elsewhere. The API itself keeps running on the host, so it needs the same `ENVIRONMENTS_RUNTIME_ENABLED=true` value that the script writes.

The default network policy is blocked. The API also supports filtered egress: public destinations are reachable, or only the configured public IPv4 targets when an allowlist is present; private/internal destinations stay blocked. The terminal gateway uses Docker exec and does not open ports on the environment or attach it to an application network. Ongoing Docker reconciliation and automatic shutdown on archive remain future work.

## Browser terminal setup

Set `ENVIRONMENTS_TERMINAL_ENABLED=true` in the API configuration. Use the frontend and terminal under the same origin, including the session cookie: the gateway forwards the browser cookie to Laravel for authentication and binds each ticket to the issuing login. The iframe client uses ttyd's protocol for keyboard input, output and resizing.

The local setup script builds and starts the `terminal` service. When `frontend/.env.local` defines `API_PROXY_TARGET` and `API_PROXY_CA_FILE`, it records the gateway route in ignored `docker/terminal/.env` and copies the public CA to ignored `docker/terminal/trust/backend-ca.crt`. TLS verification remains enabled. For subsequent manual commands, use:

```sh
docker compose --env-file docker/terminal/.env -f compose.local.yaml up -d terminal worker scheduler
```

The Vite proxy forwards `/terminal` HTTP and WebSocket requests to `127.0.0.1:7681`. Set `SECOPS_TERMINAL_ORIGIN` to the exact frontend origin if it differs from `http://localhost:5173`. A production reverse-proxy example is in [deployment](../docs/deployment.md#terminale-browser).

Only `/workspace` is on an independent persistent volume. Files elsewhere and manual installations survive stop/start of the same container, but not container removal/recreation. Processes do not survive a stop; volumes are not backups.

## Verification

From `frontend`, run `npm run build`, `npm run lint` and `npm test` (use `npm.cmd` on Windows when PowerShell blocks `npm.ps1`). Tests cover scoped API requests, the full CRUD sequence, CSRF, cancellation, response validation, resource conversion and form validation.

For browser acceptance, use an owner account and an approved image configured on the backend:

1. Create an environment and confirm its details show the submitted values and “Not provisioned”.
2. Edit all configuration fields, save, reopen details and reload the page to confirm persistence.
3. Submit a duplicate name and verify the name error; try resource and DNS values outside the configured limits.
4. Cancel a changed form and check both “Keep editing” and “Discard changes”.
5. Cancel deletion, then confirm deletion and verify the list count updates.
6. Check an archived project, a project at its quota, and a provisioned environment for the appropriate action restrictions.
7. Check the list and forms at a narrow viewport and using keyboard navigation.
