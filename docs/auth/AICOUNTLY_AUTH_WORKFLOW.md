# AICOUNTLY auth workflow (Notes)

How Notes signs a user in. This is the shared AICOUNTLY SaaS flow — the same
one Smart Books and the other products use — reduced to what a blank app needs.
The canonical implementation lives on **my.aicountly.com**; nothing here mints,
signs or stores a credential of its own.

## Tokens

| Token | Lifetime | Storage | Use |
|-------|----------|---------|-----|
| `auth_token` | Long-lived | `localStorage` + a `.aicountly.com` cookie | Mint / refresh a `ses_key` |
| `ses_key` | ~15 minutes | **Memory only** | `Authorization: Bearer` on product APIs |

`ses_key` must **never** be written to `localStorage` or `sessionStorage`. In
this app it lives in a module variable in `web/src/auth/tokens.ts` and dies with
the page.

The `auth_token` cookie is scoped to `.aicountly.com` on purpose:
`localStorage` is origin-scoped, so without the cookie a user arriving from
another AICOUNTLY product would have to sign in again.

## Login flow

1. User opens `notes.aicountly.com` (or `notes.gh.aicountly.com`).
2. No `auth_token` → redirect to
   `{portal}/login/authentication_jump/notes?returnUrl={origin}/auth/callback`.
   The portal reuses an existing portal web session — this is what makes moving
   between AICOUNTLY products seamless. With no session it shows its login form.
3. Portal redirects back to `/auth/callback?auth_token=…`. The SPA history
   fallback in `web/public/.htaccess` serves the app at that path; there is no
   router, so `AuthProvider` reads the token at boot and clears it from the URL.
4. App stores `auth_token`, then `POST /api/global/seskey` with
   `Bearer auth_token` → `ses_key`.
5. Dashboard.

Logout clears both tokens and the shared cookie, tells the portal to invalidate
the `auth_token`, and navigates to `{portal}/login/logout` so the portal's own
session cookie goes too. Skipping that last step leaves the portal session
alive and the next visit signs the user straight back in.

## Host mapping

| Notes host | Login redirect | Auth API | Product API |
|---|---|---|---|
| `notes.aicountly.com` | `my.aicountly.com` | `my.aicountly.com` | `notes.aicountly.com/api` |
| `notes.gh.aicountly.com` | `sandbox.aicountly.com` | `my.aicountly.com` | `notes.gh.aicountly.com/api` |

**`sandbox.aicountly.com` is for the login redirect only.** `seskey`,
`seskey/refresh` and `validatesession` always answer on `my.aicountly.com`, in
sandbox as well as production. Pointing a sandbox build at
`sandbox.aicountly.com` for those calls is the usual way to break sandbox
sign-in.

One build serves both environments: `resolveProductKeyFromHost()` reads
`notes` out of either hostname, and `isSandboxHost()` picks the portal.

## Why the calls go through this product's own API

The browser calls `/api/global/seskey` on its **own origin**, and `server-php`
relays that to `my.aicountly.com` server-to-server.

A brand-new product domain is not in the portal's CORS allowlist on day one, so
a direct browser call would fail with nothing but a CORS message to show for it.
The relay sidesteps that entirely. The app still falls back to calling the
portal directly if the relay is missing — useful before the API is deployed.

The relay is an **allowlist** (`RELAYED_PATHS` in `server-php/index.php`):
`seskey`, `seskey/refresh`, `refresh_authtoken`. Forwarding arbitrary paths
would turn this host into an open proxy for the portal's whole auth surface,
with the portal seeing this server's IP instead of the caller's.

## This product's backend

- Does **not** issue `auth_token` or `ses_key`.
- Does **not** implement `/api/seskey`, `/api/seskey/refresh` or `/api/logout` —
  it only relays the first two.
- Validates a caller by `POST https://my.aicountly.com/api/validatesession` with
  the Bearer `ses_key`; `status: 1` means the session is live. A transport
  failure counts as *not* authenticated, so a portal outage denies access rather
  than granting it.

`GET /api/session` is the one protected endpoint, and exists so the flow can be
verified end to end in each environment.

## The portal must know about `notes` first

Everything above assumes the portal has this product registered. It is a
prerequisite, not a detail, and its failure mode looks like a bug in Notes.

Registered on **`my.aicountly.com`**, not here:

| | |
|---|---|
| App id / jumpKey | `notes` |
| Production host | `notes.aicountly.com` |
| Sandbox host | `notes.gh.aicountly.com` |
| Auth callbacks | `https://notes.aicountly.com/auth/callback`, `https://notes.gh.aicountly.com/auth/callback`, `http://localhost:5173/auth/callback` |

The callback URL must be in the portal's `PRODUCT_CALLBACKS`. Without it the jump
returns **no `auth_token`**, the SPA cannot mint a `ses_key`, and every `/api/*`
call fails — while Notes itself is deployed correctly and has nothing in its logs
to show for it.

**A blank page at `my.aicountly.com/login/authentication_jump/notes` means the
portal does not handle this jumpKey yet.** The redirect from Notes is correct;
`authentication_jump()` is falling through with an empty body. That is fixed on
the portal, not here. Until it is deployed, the login form can be opened
directly, which confirms the rest of the chain works:

```
https://my.aicountly.com/login?returnUrl=https%3A%2F%2Fnotes.aicountly.com%2Fauth%2Fcallback
```

You will still not receive an `auth_token` back until `authentication_jump/notes`
is registered.

(Confirmed against `docs/MY_AICOUNTLY_REGISTRATION.md` in `aicountly/pulse-aicountly`,
which hit exactly this on its own rename.)

## Verifying an environment

```bash
# 1. The API is up and says which environment it is
curl https://notes.gh.aicountly.com/api/health

# 2. The relay reaches the portal (401 without a token is the correct answer —
#    a 404 means the API is not deployed, a 504 means it cannot reach the portal)
curl -i -X POST https://notes.gh.aicountly.com/api/global/seskey

# 3. Unrelayed paths are refused
curl -i -X POST https://notes.gh.aicountly.com/api/global/login   # expect 404
```

In the browser: open the site, expect a jump to the portal, sign in, expect to
land back on the dashboard. Then press **Log out** and confirm that reopening
the site does **not** sign you straight back in.
