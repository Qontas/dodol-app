---
name: verify-browser
description: Launch the dodol-app and verify the real login + multi-tenant access flow in a browser. Use when asked to manually check the app in a browser, confirm role routing/scoping (super_admin/owner/operator), or smoke-test the Livewire login end-to-end.
---

# Browser verification (dodol-app)

Drives the **real app** in a real browser: logs in through the Livewire/Volt
login form (a plain `curl` POST to `/login` returns **405** — login is a
Livewire component, not a form post), follows the role-based redirect, and
checks panel access per role.

No Playwright browser download is needed — we use `playwright-core` pointed at
the **system Chrome/Edge** already installed on this machine.

## One-time / per-run setup

```bash
cd "$CLAUDE_PROJECT_DIR" || cd /c/Users/Qontas/Projects/dodol-app
SKILL=.claude/skills/verify-browser

# 1. (optional) fresh seed if you want the known 3 accounts
php artisan migrate:fresh --seed --force

# 2. playwright-core (driver only, ~no browser download). The skill has its own
#    package.json, so this installs into $SKILL/node_modules — NOT the project deps.
[ -d "$SKILL/node_modules/playwright-core" ] || (cd "$SKILL" && npm install --no-audit --no-fund)
```

## Run it

```bash
cd "$CLAUDE_PROJECT_DIR" || cd /c/Users/Qontas/Projects/dodol-app
SKILL=.claude/skills/verify-browser

# 3. start the dev server in the background
php artisan serve --host=127.0.0.1 --port=8123 > "$SKILL/serve.log" 2>&1 &
SERVER_PID=$!

# 4. wait until it answers, then drive the browser
for i in $(seq 1 15); do
  [ "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8123/login)" = "200" ] && break
  sleep 1
done
node "$SKILL/driver.cjs"; RC=$?

# 5. always clean up
kill $SERVER_PID 2>/dev/null; pkill -f "artisan serve" 2>/dev/null
echo "driver exit: $RC"
```

`driver.cjs` exits non-zero if any role's expectation fails, so `$RC` is a real
pass/fail signal. Screenshots land in `$SKILL/shots/` — **open a couple and
look at them** (a blank/error frame is a failed launch, not a pass).

## What it checks (default expectations)

Seeded accounts, password `password`:

| Role | Login | lands on | `/admin` | `/operator/dashboard` |
|------|-------|----------|----------|-----------------------|
| Super Admin | admin@cemilanqontas.id | `/admin` | 200 | 403 |
| Owner | owner@cemilanqontas.id | `/owner/dashboard` | 200 | 403 |
| Operator | operator@cemilanqontas.id | `/operator/dashboard` | 403 | 200 |

To also **prove tenant isolation** visually, temporarily add a second owner +
a record they own, re-run, confirm each owner sees only their own rows and
super_admin sees both, then delete the demo rows to restore the seeded state.

## Tips / gotchas

- Login selectors: `#email`, `#password`, `button[type=submit]` (Breeze + Volt).
- Role routing lives in `routes/web.php` (`/dashboard` redirect) — super_admin → `/admin`.
- Override base URL with `BASE=http://127.0.0.1:PORT`; override accounts by
  editing the `USERS` array at the top of `driver.cjs`.
- The driver auto-detects Chrome then Edge from the standard Windows paths; if
  both move, set `BROWSER_PATH=/c/path/to/chrome.exe`.
- `node_modules/`, `shots/`, and `serve.log` under this skill are gitignored.
