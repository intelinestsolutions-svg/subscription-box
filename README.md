# Studio Pro Subscription Box Management Platform

An API-first subscription box platform built for **recurring physical products** — the operational
headaches generic e-commerce tools ignore. PHP 8 + MySQL, JSON API, deploys to plain shared hosting
(Hostinger). Zero external fees in demo mode; real carriers/payments/AI plug in via adapters.

## The three portals & nine high-value features

### 🧑 Subscriber — Customer Portal
| Feature | Endpoint | What it does |
|---|---|---|
| **Skip-a-Month** | `api/skips.php` | One click pauses a single cycle before the shipping cutoff. Undo supported. |
| **Box Customization Engine** | `api/customization.php` | Swap variants (flavor, size, roast, skin type…) per plan, before cutoff. Choices persist to every future box. |
| **Add-On Upsell Shop** | `api/addons.php` + `api/checkout.php` | One-off items attached to the next box — **no extra shipping fee**. |

### 🏪 Merchant — Dashboard
| Feature | Endpoint | What it does |
|---|---|---|
| **Churn Prediction Alerts** | `api/churn.php` | AI-scored 0–100 churn risk per subscriber via your **free Colab/Ollama tunnel** (OpenAI-compatible). Deterministic rule-based fallback when the tunnel is down. |
| **Dynamic Inventory Forecasting** | `api/forecast.php` | Projects boxes per plan over N months from active subs, growth (last-90d signups), churn & skip rates, then suggests order quantities with safety stock. |
| **Failed Payment Recovery (Dunning)** | `api/dunning.php` | Automated 3-stage sequence — email (day 2), email (day 5), SMS (day 8) — before the box is skipped. |

### 📦 Operations — Warehouse & Logistics
| Feature | Endpoint | What it does |
|---|---|---|
| **Kitting & Packing Lists** | `api/packing.php` | Per-plan, per-cycle lists: exactly which items go in which box variant + aggregated pick quantities. |
| **Bulk Shipping Label Generation** | `api/shipping.php` + `api/shipping/Providers.php` | One run purchases labels for the whole cycle via **DHL Unified API**, **FedEx Ship API**, or a zero-config print-ready **local courier CSV**. |
| **Address Validation Tool** | `api/address.php` | Screens & normalises addresses at checkout (postal patterns, street-type typos, crammed lines). Loqate & SmartyStreets adapters included. |

## Stack & structure

```
sp-subscription-box/
├── index.html              ← role router (Subscriber / Merchant / Ops)
├── subscriber/  portal.html
├── merchant/    dashboard.html
├── ops/         warehouse.html
├── assets/      css/ js/
├── api/
│   ├── config.example.php  ← copy to config.local.php (ignored by git)
│   ├── bootstrap.php       ← CORS, PDO, JSON helpers, token auth
│   ├── lib.php             ← cycle/cutoff logic, churn feature extraction
│   ├── auth.php            ← register / login / logout / me (role-based)
│   ├── install.php         ← schema + demo seed (guarded by install_key)
│   ├── subscriptions.php   skips.php  customization.php  addons.php
│   ├── checkout.php        address.php  dunning.php  churn.php  forecast.php
│   ├── admin.php           packing.php  shipping.php
│   ├── billing/  Provider.php      (demo provider + Stripe-ready skeleton)
│   ├── shipping/ Providers.php     (local_csv · dhl · fedex)
│   └── notify/   Sender.php        (email/SMS: log mode · Twilio-ready)
├── db/schema.sql
└── storage/                ← runtime logs + label CSVs (gitignored)
```

## Quickstart

1. **Create the database** (MySQL), e.g. `sp_subscriptionbox`.
2. **Configure** — copy `api/config.example.php` → `api/config.local.php` and set:
   - `db_*` (Hostinger credentials)
   - `app_url` → your site URL
   - `ollama_url` → your live Colab tunnel (`https://….trycloudflare.com/v1`) + `ollama_model`
   - `install_key` → pick one, keep it out of production
3. **Install & seed** — open
   `https://your-site/api/install.php?install_key=YOUR_KEY`
   (add `&action=reset` to wipe and reseed).
4. **Log in** with the demo accounts:

| Role | Login |
|---|---|
| Merchant | `merchant@demo.test` / `demo1234` |
| Ops | `ops@demo.test` / `demo1234` |
| Subscriber | `alice@demo.test` / `demo1234` (10 seeded subscribers: healthy · at-risk · dunning cases) |

> Every file uses `require __DIR__.'/bootstrap.php'` — point your web server's document root at the
> project root or a subfolder and the API works as-is. No Composer, no framework.

## Churn AI — free GPU LLM (no cost)

`api/churn.php` builds an engagement feature vector per subscriber
(opens/30d, skips/90d, failed payments, payment-method updates, login recency, tenure) and asks the
remote model for `{"score": 0–100, "risk": "low|medium|high", "reasons": [...]}`.

- Runs on **qwen2.5-coder:7b** (or any model) served by **Ollama on a free Colab T4** via
  cloudflared tunnel — see `OLLAMA-COLAB-CHEATSHEET.md` (sibling project file) for the zero-cost setup.
- `ollama_timeout` (default 15 s) → on timeout/unreachable, scoring silently falls back to the
  deterministic `rules_churn_score()` so the dashboard never breaks when the Colab session dies.

## Going live (adapter switches)

| Concern | Demo (zero keys) | Live |
|---|---|---|
| Payments | `billing_provider = demo` (failures via `force_fail`) | Stripe (`StripeProvider` skeleton + webhook) |
| Carriers | `default_carrier = local_csv` (CSV labels) | `dhl_api_key/secret` · `fedex_api_key/secret` |
| Address | `address_provider = demo` (built-in rules) | `loqate_api_key` · `smartystreets_key` |
| Email/SMS | `mail_mode/sms_mode = log` (writes `storage/logs/`) | SMTP via PHP `mail()`, Twilio (`twilio_sid/token/from`) |

Add the matching keys to `api/config.local.php` and flip one line per concern.

## Deploying to Hostinger (test.prostudio.my and beyond)

`test.prostudio.my` is a subdomain whose document root is `prostudio.my/public_html/test`, inside the
same hosting account as `prostudio.my`. The repo is public, so Hostinger can pull it with no tokens.

### A. Recommended: Git deploy in hPanel (one-time setup, instant redeploys)

1. hPanel → **Websites** → click **Manage** on `prostudio.my` → **Git** (Files section).
2. Click **+ Create** / **Add repository**:
   - Repository URL: `https://github.com/intelinestsolutions-svg/subscription-box.git`
   - Branch: `main`
   - Deployment path: `public_html/test`   ← the subdomain's document root
3. Hit **Deploy**. The project lands under `public_html/test/` with the API at
   `https://test.prostudio.my/api/...`.
4. After any future `git push`, redeploy = open the Git entry → **Deploy** (or enable auto-deploy).

> `api/config.local.php` is intentionally **not** in the repo (no secrets in git). It's created once
> on the server (step B) and survives subsequent Git pulls because Hostinger doesn't wipe
> untracked files. `storage/` ships with `.htaccess` (`Require all denied`) and is self-healing.

### B. Database + server config (do once)

1. hPanel → **Databases → MySQL**: create a database + user, note host/user/pass.
2. File Manager → `public_html/test/api/` → **create `config.local.php`** and paste:

```php
<?php return [
    'db_host'      => 'localhost',              // as shown in hPanel
    'db_name'      => 'u123456789_sp',         // ← your created DB
    'db_user'      => 'u123456789_sp',         // ← your DB user
    'db_pass'      => 'YOUR-DB-PASSWORD',       // ← your DB password

    'app_url'      => 'https://test.prostudio.my',
    'demo_mode'    => true,
    'timezone'     => 'Asia/Kuala_Lumpur',
    'auth_token_ttl' => 2592000,
    'cutoff_day'   => 15,

    'ollama_enabled' => true,
    'ollama_url'      => 'https://YOUR-LIVE-TUNNEL.trycloudflare.com/v1', // while Colab notebook runs
    'ollama_model'    => 'qwen2.5-coder:7b',
    'ollama_timeout'  => 15,

    'mail_mode' => 'log', 'sms_mode' => 'log',
    'twilio_sid' => '', 'twilio_token' => '', 'twilio_from' => '',

    'default_carrier' => 'local_csv',
    'dhl_api_key' => '', 'dhl_secret' => '', 'dhl_api_url' => 'https://api-eu.dhl.com',
    'fedex_api_key' => '', 'fedex_secret' => '', 'fedex_api_url' => 'https://apis.fedex.com',

    'address_provider' => 'demo',
    'loqate_api_key' => '', 'smartystreets_key' => '',

    'install_key' => 'demo-install-2026',
    'storage_dir' => __DIR__ . '/../storage',
];
```

3. Visit **once**: `https://test.prostudio.my/api/install.php?install_key=demo-install-2026`
   → expect `{"ok":true,...}` (creates tables + seeds 12 demo users).
4. Log in with password `demo1234`: `merchant@demo.test` (dashboard), `ops@demo.test` (warehouse),
   `alice@demo.test` (subscriber). In the **Ops console → Churn** tab, run **Score all** once to
   populate the at-risk board.
5. For production: delete `api/install.php`, set `demo_mode = false`, rotate `install_key`, and
   paste real SMTP/Twilio/Stripe/carrier keys as needed.

### C. Fallback: manual File Manager upload

If your plan has no Git tool: File Manager → `public_html/test/`, upload the **contents** of this
project (not the folder) so the API lands at `https://test.prostudio.my/api/...`, then follow B2–B5.

## Staying safe

- `api/config.local.php` is gitignored — never commit DB creds, tunnel URLs, or carrier/Stripe keys.
- Colab tunnels are public URLs: keep sessions short, don't put real customer data through the tunnel.
- `storage/` blocks public web access via `.htaccess` (Apache). On nginx, deny it in your server block.