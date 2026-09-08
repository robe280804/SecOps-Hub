# Authentication

SecOps Hub uses Laravel Sanctum to authenticate its React frontend through **session cookies**. It does not use JWTs for browser authentication.

Laravel manages the session on the server. The browser holds a cookie identifying that session and sends it with requests. React keeps the current user's profile in memory; it does not save authentication tokens or passwords in localStorage or sessionStorage.

## Login

1. The user enters their email and password on `/login`.
2. React calls `GET /sanctum/csrf-cookie`. Laravel sets the cookies needed for CSRF protection and the session.
3. React calls `POST /api/v1/session`, sending the credentials and the CSRF value in the `X-XSRF-TOKEN` header.
4. Laravel validates the input and checks the credentials using `Auth::attempt()`.
5. On success, Laravel regenerates the session ID and returns the user's profile as JSON. React opens `/account`.

The session cookie is HttpOnly, so JavaScript cannot read it. The separate `XSRF-TOKEN` cookie is readable by JavaScript so the client can send the CSRF header. These cookies have different purposes: one identifies the session, and the other helps protect requests from forgery.

## Subsequent requests and page refresh

The shared API client uses `credentials: 'include'`, which tells the browser to send the relevant cookies.

For requests from a trusted frontend, Laravel's `statefulApi()` configuration enables session handling on API routes. The `auth:sanctum` middleware checks who is signed in. Policies then check whether that user is allowed to perform the requested operation.

On page refresh, React calls `GET /api/v1/me` to restore the user from the existing session. It shows a loading state while checking. If the session is missing or expired, Laravel returns `401` and protected pages lead back to login. A network or server failure shows an error with a retry button.

Frontend route protection controls navigation only. Backend authentication and policies enforce access even if someone calls the API directly.

## Why both web.php and api.php?

The route file determines which middleware runs. It does not determine whether the response must be HTML or JSON.

| Action | Endpoint | Route file |
| --- | --- | --- |
| Start browser session | `POST /api/v1/session` | `backend/routes/web.php` |
| End browser session | `DELETE /api/v1/session` | `backend/routes/web.php` |
| Get current user | `GET /api/v1/me` | `backend/routes/api.php` |
| List or create users | `GET /api/v1/users`, `POST /api/v1/users` | `backend/routes/api.php` |
| View, update, or delete a user | `GET`, `PUT/PATCH`, `DELETE /api/v1/users/{user}` | `backend/routes/api.php` |

The session endpoints use `web.php` for its session, cookie, and CSRF middleware. They still return JSON (or an empty `204` response for logout). An `/api/` prefix in the URL does not change their middleware.

`SessionController` does not implement a custom session engine. It coordinates Laravel's existing authentication and session methods for login and logout. Sanctum validates authenticated API requests; it does not supply those login/logout actions itself.

## Logout

React refreshes the CSRF cookie, then sends `DELETE /api/v1/session`.

Laravel logs out the user, invalidates the current session, and regenerates the CSRF token. React clears its in-memory user and returns to login. Other devices' sessions are not explicitly revoked by this endpoint.

## Registration and roles

Public registration is disabled. The registration page currently validates input locally and displays an unavailable message; it does not create an account.

An authenticated admin creates accounts through `POST /api/v1/users`. There is no invitation flow yet. Password recovery also remains a frontend placeholder without a backend flow.

Roles are managed with Spatie Permission:

| Role | Allowed operations |
| --- | --- |
| `admin` | List, create, view, and update users; delete other users; change another user's role |
| `user` | View and update their own profile; cannot list, create, or delete users or change roles |

Admins cannot delete their own account or change their own role through the user API. New accounts default to `user` unless an admin explicitly selects another supported role.

## Centralized frontend communication

Pages call the authentication service rather than implementing their own HTTP requests.

| File | Responsibility |
| --- | --- |
| [`client.ts`](../frontend/src/shared/api/client.ts) | HTTP requests, credentials, CSRF headers, 15-second timeout, cancellation, and normalized errors |
| [`http.ts`](../frontend/src/shared/api/http.ts) | Configured API origin and session-expiry notifications |
| [`formErrors.ts`](../frontend/src/shared/api/formErrors.ts) | Maps backend validation errors to known form fields |
| [`authApi.ts`](../frontend/src/features/auth/api/authApi.ts) | Login, current-user lookup, logout, and validation of returned user data with Zod |
| [`AuthProvider.tsx`](../frontend/src/features/auth/model/AuthProvider.tsx) | Current user, startup session check, login, and logout state |

New API features should reuse the shared HTTP client and validate their response shapes in their feature service. Failed writes are not automatically retried, to avoid duplicate operations.

## Errors and rate limits

Laravel renders API errors as JSON through its central exception handler. Controllers do not need a generic `try/catch` around every operation. Unexpected API server errors return a generic message rather than exception details.

The frontend normalizes failures into `ApiError`:

| Status | Meaning and handling |
| --- | --- |
| `401` | Not authenticated; clear the frontend authentication state |
| `403` | Authenticated but not allowed to perform the operation |
| `404` | Resource not found |
| `419` | CSRF/session mismatch; clear authentication state and ask the user to sign in again |
| `422` | Invalid input or incorrect login credentials; show field errors |
| `429` | Rate limit reached; show a wait message and retain `Retry-After` metadata |
| `5xx` | Server failure; show a generic message |
| Network failure or timeout | Show a connection or timeout message |

A `419` does not necessarily mean the server session has expired; it can also mean the CSRF value is missing or mismatched.

Rate limits are defined in [`AppServiceProvider.php`](../backend/app/Providers/AppServiceProvider.php):

- Browser and token login: **5 requests per minute per email + IP**, including successful requests.
- Authenticated API routes and browser logout: **60 requests per minute per user**.

The framework-provided CSRF cookie endpoint does not currently have a custom application rate limit. The login limiter is not a separate global per-IP limit.

## Existing token endpoints

The older `POST /api/v1/login` endpoint still issues Sanctum API tokens, and `DELETE /api/v1/logout` revokes the current bearer token. These are opaque database-backed tokens, not JWTs.

The React frontend uses `/api/v1/session` instead. Its logout must use the session endpoint to invalidate the browser session. The token endpoint is a separate supported mechanism, not part of browser session renewal.

## Local development and deployment

In local development, React calls relative paths. Vite forwards `/api` and `/sanctum` requests to Herd. Configure the backend target in `frontend/.env.local` using [`frontend/.env.example`](../frontend/.env.example):

- `API_PROXY_TARGET`: the backend URL served by Herd.
- `API_PROXY_CA_FILE`: optional path to Herd's public CA certificate for local HTTPS verification.
- `VITE_API_ORIGIN`: leave empty when using the same-origin proxy.

Restart Vite after changing these values. The local file is git-ignored; its settings are machine-specific. The Vite development proxy is not included in the production build.

For production, use HTTPS and either route `/api` and `/sanctum` through the frontend's reverse proxy or deploy on sibling subdomains such as `app.example.com` and `api.example.com`.

For sibling subdomains, set `VITE_API_ORIGIN` to the API origin, `FRONTEND_URL` to the exact frontend origin, `SANCTUM_STATEFUL_DOMAINS` to the frontend host (and port if needed), and `SESSION_DOMAIN` to the shared domain. See [`backend/.env.example`](../backend/.env.example). CORS allows credentials only for configured origins; CORS itself does not replace authentication or CSRF protection.

Set `APP_DEBUG=false` and `SESSION_SECURE_COOKIE=true` in production. Sessions default to database storage and a 120-minute lifetime, subject to environment overrides. Multiple backend instances must share the session and rate-limit stores.

## Verification

Backend authentication tests cover session login/logout, session-ID rotation, CSRF enforcement, CORS, throttling, and error redaction. The frontend client tests cover cookie/header handling, errors, timeouts, cancellation, and restrictions on request destinations.

```bash
# From backend (with Herd PHP available)
php artisan test --compact

# From frontend (Node 22.6+ for the current test command)
npm test
npm run build
npm run lint
```
