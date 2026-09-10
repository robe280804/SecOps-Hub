# Project environments

Open a project you own and use its **Environments** section.

- Create an environment using an approved image, resource limits and optional DNS settings. Defaults and limits come from the project's environment options endpoint. After saving, the new environment's details open automatically.
- Browse the paginated list and open details to read configuration and runtime status.
- Edit the name, description, image, resources and network before provisioning. Provisioned environments allow descriptive edits when the API grants update access.
- Delete an eligible environment after confirming permanent deletion. Deleting the last item on a page returns to the previous page.

Archived projects are read-only. Creation respects the project quota and approved image catalog; edit and delete actions respect the capabilities returned by the API. Validation errors appear on the corresponding form controls. Failed reads can be retried, and writes are never automatically replayed. Canceling a dirty form asks whether to discard the changes.

Creation saves configuration only. Container provisioning, start/stop and terminal access are not implemented by these CRUD endpoints.

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
