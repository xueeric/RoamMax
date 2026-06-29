# RoamMax (roammax.ca)

Satellite hardware rental platform — Starlink Mini kits and add-ons, starting in Edmonton.

## What this is

A booking platform where **registered customers** pick dates on a calendar and rent available equipment (Starlink Mini + batteries/add-ons). Admin runs operations; Partners (friends) contribute units and see only their own gear.

**Scale:** ~12 units max — kept intentionally simple.

## Current status

**v1 complete** — Phases 0–6: auth, availability, checkout, admin operations, partner financials, cancellations, late fees, and notifications.

Run locally:

```bash
cd website
cp .env.example .env
php bin/migrate.php
php -S localhost:8080 -t public public/index.php
```

Self-test (from repo root):

```bash
cd website && php bin/migrate.php
cd .. && php dev/bin/self-test.php
```

### Key routes

| Route | Purpose |
| --- | --- |
| `/book` | Calendar → checkout |
| `/account/bookings` | Customer bookings, pay, cancel, accept proposals |
| `/admin` | Dashboard, bookings lifecycle, inventory, transfers |
| `/admin/financials` | Payoff reports, payment ledger |
| `/partner/financials` | Partner unit payoff |
| `/booking/mock-pay` | Local mock payment when Square is not configured |

Set `SQUARE_ACCESS_TOKEN` + `SQUARE_LOCATION_ID` in `.env` for real sandbox checkout.

### Seed logins

| Email | Password | Role |
| --- | --- | --- |
| `admin@starlink.local` | `changeme` | Admin |
| `partner@starlink.local` | `changeme` | Partner |

## Documents

| Document | Purpose |
| --- | --- |
| [docs/DEPLOY.md](docs/DEPLOY.md) | **Push to production** — agent runbook for `roammax.ca` |
| [docs/BASELINE.md](docs/BASELINE.md) | Product vision, features, business rules, architecture |
| [docs/DATA-MODEL.md](docs/DATA-MODEL.md) | SQLite schema, availability & assignment logic |
| [docs/ROADMAP.md](docs/ROADMAP.md) | Phased build plan |
| [starlink-mini-equipment-rental-agreement.md](starlink-mini-equipment-rental-agreement.md) | Customer rental agreement |

## Tech stack

| Layer | Choice |
| --- | --- |
| **Language** | PHP 8.4 |
| **Database** | SQLite 3.46 (WAL mode) |
| **Data access** | PDO + raw SQL migrations (no ORM) |
| **Auth** | Custom PHP sessions + `password_hash()` |
| **Payments** | Square API |
| **Hosting** | VPS — deploy `website/`; Nginx → `website/public/` only; SQLite in `website/data/` |

## v1 highlights

| Area | Decision |
| --- | --- |
| **Customer accounts** | Required to book |
| **Payments** | Square API |
| **Inventory** | Starlink Mini + add-ons (batteries, accessories) |
| **Fulfillment** | Store pickup, home appointment, mail ship (**$150**, **all Canada**) |
| **Home appointments** | Admin confirms manually; can propose date + time range |
| **30+ day rentals** | Admin-created only |
| **Unit assignment** | Automatic — **Admin units first** |
| **Partner role** | Friends see only their device; swap keeps their unit earning |
| **Staging** | 1-day lead time if unit not at pickup site |
| **Pricing tiers** | Admin-configurable day ranges + daily rates (launch defaults in agreement) |
