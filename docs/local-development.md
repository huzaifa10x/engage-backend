# Local development — 10X Engage

Everything runs with **one command script, `./dev`**. Docker runs the backend quietly in the
background; you never type a Docker command. The frontend runs with Node so UI changes hot-reload.

## 1. Install once (≈10 minutes)

| Tool | Get it | Check |
|---|---|---|
| **Docker Desktop** | https://www.docker.com/products/docker-desktop/ — open it once and leave it running | whale icon in the menu bar says *running* |
| **Node.js 22 LTS** | https://nodejs.org/ (LTS installer) | `node -v` → v22.x |
| **Git** | macOS asks to install it the first time you type `git` | `git --version` |

Apple Silicon and Intel Macs both work. Windows: use WSL 2 (Ubuntu) and run everything inside it.

## 2. Get the code (once)

```bash
mkdir -p ~/engage && cd ~/engage
git clone https://github.com/huzaifa10x/engage-backend.git
git clone https://github.com/huzaifa10x/engage-web.git
```

The two folders must sit **side by side** in the same parent folder.

## 3. Set up (once per computer)

```bash
cd ~/engage/engage-web
./dev setup
```

First run takes 5–10 minutes (it downloads and builds everything). It ends with
**✅ Setup complete** and the addresses below. Safe to run again any time.

## 4. Every day

```bash
cd ~/engage/engage-web
./dev start
```

Open **http://localhost:3000** and sign in with **owner@engage.test / Password123!**.
Edit files in `engage-web/src` — the browser updates instantly.
`Ctrl+C` stops the frontend; `./dev stop` stops the backend at the end of the day.

## Commands

| Command | What it does |
|---|---|
| `./dev setup` | First-time setup (safe to repeat) |
| `./dev start` | Start everything; frontend runs in this window |
| `./dev stop` | Stop the backend (data is kept) |
| `./dev status` | What is running |
| `./dev update` | Pull the latest code of **both** repos and apply it (packages, database) |
| `./dev reset` | Delete all local data and reload the demo data |
| `./dev inbound "Hello!"` | A customer sends you a WhatsApp message (appears live in Team Inbox) |
| `./dev logs` | Backend logs (`./dev logs horizon`, `./dev logs reverb` …) |
| `./dev test` | Backend tests |
| `./dev artisan …` | Any Laravel command |

## Addresses and logins

| What | Address | Login |
|---|---|---|
| Client portal | http://localhost:3000 | owner@engage.test / Password123! |
| Super Admin | http://localhost:8000/admin | admin@engage.test / Password123! (authenticator app on first login) |
| Emails sent by the app | http://localhost:8025 | — |
| Queues (Horizon) | http://localhost:8000/horizon | Super Admin login |
| Database (TablePlus etc.) | localhost:**54320**, db `engage`, user `engage`, password `engage` | — |

## Demo data and fake Meta

The demo workspace has a connected number (**Demo Store, +971 50 000 0001**), 12 contacts and 8
conversations covering every state the UI must handle: unread messages, an open and a closed
24-hour window, a template-only thread, a failed message, an opted-out contact and a WhatsApp
username user without a phone number.

**Fake Meta mode** (local only) means no Meta account is needed: sending a message works and its
ticks move ✓ → ✓✓ → blue ✓✓ within seconds, attachments upload, read receipts work. Use
`./dev inbound "text"` to receive a message. *Connect WhatsApp* is the one screen that needs the
real Meta popup — it only works on the live server.

## When something goes wrong

| Problem | Fix |
|---|---|
| "Docker is installed but not running" | Open Docker Desktop, wait for *Engine running* |
| Login says the session expired / 419 | Use **http://localhost:3000** (not 127.0.0.1) |
| A page shows old data after pulling | `./dev update` |
| Strange data / want a clean slate | `./dev reset` |
| `./dev start` says API did not start | `./dev logs` and send the last lines to the backend developer |
| Port already in use (3000/8000/8080) | Quit the other app using it, or restart the Mac |
