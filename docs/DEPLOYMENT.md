# Deploying Notes

## Layout

```
web/          React app (Vite). Builds to web/dist.
server-php/   PHP API. Plain PHP, no build step — deployed as-is.
docs/         this file, plus the auth notes
```

## What lands where on cPanel

| Workflow | Source | Destination | Reachable at |
| --- | --- | --- | --- |
| Deploy Web to cPanel Production | `web/dist/` | `<remote root>/` | https://notes.aicountly.com |
| Deploy API to cPanel Production | `server-php/` | `<remote root>/api/` | https://notes.aicountly.com/api |
| Deploy Web to cPanel Sandbox | `web/dist/` | `<remote root>/` | https://notes.gh.aicountly.com |
| Deploy API to cPanel Sandbox | `server-php/` | `<remote root>/api/` | https://notes.gh.aicountly.com/api |

`<remote root>` is the `*_SSH_REMOTE_ROOT` secret for that environment,
normally `public_html` (or the subdomain's own document root).

Deployment is manual only — **Actions → pick a workflow → Run workflow**.
Nothing deploys on push or merge.

## Four workflows, not two

The frontend and the API deploy independently, so a frontend release cannot
disturb a working API and vice versa. They also have genuinely different jobs:
the web workflow builds with Node and ships a compiled bundle, while the API
workflow ships source and lints it with `php -l`.

### Why the api folder survives a web deploy

The web deploy runs `rsync --delete` against the document root, which would
otherwise remove everything not in the build — including `api/`, since the API
lives inside the document root. The web workflow therefore excludes `api/`
explicitly. **Removing that exclude would delete the entire backend on the next
web deploy.**

### Why the API's .env survives an API deploy

The API deploy also runs `rsync --delete`, this time against `api/`. The API's
`.env` is created once by hand on the server and exists nowhere else, so both
`--exclude='.env'` and `--exclude='.env.*'` are what keep it alive. Removing
them would wipe the live configuration on the next deploy.

Neither `.env` is ever uploaded either: `.gitignore` keeps them out of the
repository, and the API workflow fails the build outright if a committed `.env`
appears under `server-php/`.

## Configuration: two different mechanisms

This is the part worth reading carefully, because the frontend and the backend
behave in opposite ways.

### React (web/) — build time

Vite inlines every `VITE_*` value into the JavaScript bundle when the app is
compiled. The deployed result is plain static files that **never read a `.env`
from disk**. Putting a `.env` in the document root has no effect.

To change a frontend value: change it in the workflow (or in the optional
repository variable), then re-run the workflow. The rebuild is what applies it.

Never put a secret in a `VITE_` variable — anything inlined into the bundle is
public to anyone who views the page source.

The API URL needs no configuration in the normal case: with
`PROD_API_BASE_URL` / `SANDBOX_API_BASE_URL` unset, the app calls its own origin
+ `/api`, which is where the API workflow deploys `server-php`. Those repository
variables exist only to override that — for example if the API moves to its own
domain.

### server-php — runtime

PHP reads its `.env` on **every request**. So the API's `.env` belongs on the
server, and only on the server.

Create it once by hand — cPanel File Manager or SSH — at
`<remote root>/api/.env`, from `server-php/.env.example`:

```
APP_ENV=production
```

That is the whole file for a production deploy; `APP_ENV=sandbox` for the
sandbox. `GET /api/health` reports the value back, which is how you confirm
you are looking at the environment you think you are.

The API has no database yet. When the product needs one, add the credentials to
this same file — and note that cPanel prefixes both database and user with the
account name, so a database entered as `app` becomes `<cpaneluser>_app`. Use the
full prefixed names, add the user to the database with **ALL PRIVILEGES**, and
set `DB_HOST=localhost` (on cPanel the database is on the same machine).

### Protecting the API's .env over HTTP

Because `api/` sits inside the document root, `.env` would be fetchable at
`https://notes.aicountly.com/api/.env` unless Apache is told otherwise.
`server-php/.htaccess` ships the rule that denies it:

```apache
RedirectMatch 404 /\.(?!well-known)
```

The web build does the same for the document root via `web/public/.htaccess`,
but those rules stop applying inside `api/` once the API's own take over.

### The Authorization header

`server-php/.htaccess` also copies the `Authorization` header into the request
environment. Apache does not pass it to PHP under CGI/FastCGI unless told to,
and without it the auth relay forwards no credential — the portal answers 401
and sign-in fails for everyone, with nothing in the logs to explain why.

## Required secrets

Per environment, under Settings → Secrets and variables → Actions → Secrets:

`PROD_SSH_HOST`, `PROD_SSH_PORT`, `PROD_SSH_USER`, `PROD_SSH_PRIVATE_KEY`,
`PROD_SSH_REMOTE_ROOT` — and the same five with a `SANDBOX_` prefix.

All four workflows validate these before building, and verify SSH
authentication before writing anything to the server. Because the deploys run
with `--delete`, a `*_SSH_REMOTE_ROOT` that would resolve to the home directory
itself, a system directory, or anything containing `..` is refused.

## First deploy checklist

1. Create the subdomain in cPanel and note its document root.
2. Add the five SSH secrets for that environment.
3. Run **Deploy API to cPanel …**.
4. Create `api/.env` on the server (see above) — the API is deployed but
   unconfigured until you do.
5. Run **Deploy Web to cPanel …**.
6. Confirm `https://<host>/api/health` returns the right `env`, then open the
   site and sign in. See [auth/AICOUNTLY_AUTH_WORKFLOW.md](auth/AICOUNTLY_AUTH_WORKFLOW.md)
   for what a healthy login looks like.
