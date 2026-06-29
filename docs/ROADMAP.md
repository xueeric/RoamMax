# Roadmap

Phased plan from baseline to multi-location rental platform.

**Stack:** PHP 8.4 · SQLite 3.46 (WAL) · PDO/raw SQL · custom PHP auth · Square · self-hosted

**v1 includes:** customer accounts, add-on inventory, mail shipping (all Canada), Square, Admin/Partner roles, auto-assign (Admin first), staging, home appointment confirm/propose, admin-created 30+ day bookings.

---

## Phase 0 — Baseline ✅

- [x] Rental agreement documented
- [x] Product baseline & data model
- [x] Multi-location + transfer + staging design
- [x] Customer accounts, inventory, shipping, Square — in scope for v1
- [x] Open decisions resolved (Canada shipping, home appointments, admin long-term bookings)
- [x] Tech stack: PHP 8.4 + SQLite WAL, no ORM, custom auth

---

## Phase 1 — Foundation & Auth ✅

**Goal:** PHP project scaffold, SQLite schema, roles, locations seeded.

| Task | Notes |
| --- | --- |
| PHP 8.4 project layout | `website/` as app root; `public/`, `data/`, `src/` under it |
| SQLite + WAL + PDO wrapper | `Database.php`, pragmas on connect |
| Migration runner | `bin/migrate.php` — raw SQL files |
| Custom auth | Register, login, logout, sessions, role middleware |
| Seed locations | Edmonton store, Edmonton home, Red Deer & Calgary (inactive) |
| Seed Admin user + 1 equipment | Extended fields |
| Partner model | Friend login → scoped to their unit |
| Square SDK / REST stub | Sandbox; webhook route |

**Done when:** Admin and Partner can log in; equipment visible in admin.

---

## Phase 2 — Availability, Staging & Calendar ✅

**Goal:** Customer sees real availability with staging rules.

| Task | Notes |
| --- | --- |
| `at_pickup_site` + staging events | 1-day rule home → store |
| Availability endpoint | PHP JSON response; fulfillment-aware |
| Auto-assign service | Admin units first |
| Customer calendar page | Server-rendered + JS date picker |
| Owner/partner blocks | Personal-use dates |
| Partner swap request | Borrow Admin unit |

**Done when:** Calendar blocks correctly when unit at home + store pickup selected.

---

## Phase 3 — Booking, Appointments, Add-Ons & Square ✅

**Goal:** End-to-end customer booking with payment.

| Task | Notes |
| --- | --- |
| Customer booking flow | Logged-in only; self-serve ≤ 30 days |
| Home appointment flow | `awaiting_admin` → confirm or **propose window** → customer accept → pay |
| Admin-created bookings | 30+ days, custom pricing |
| Add-on inventory (batteries) | Per-location stock |
| Price calculator | Tiers + deposit + $150 Canada shipping |
| Agreement acceptance | Rental agreement page |
| Square checkout | Charge deposit + rental + shipping |
| Square webhook | Confirm payment → booking confirmed |

**Done when:** Customer completes store pickup or mail booking; home appointment works via admin confirm/propose.

---

## Phase 4 — Admin Operations ✅

**Goal:** Run the business day-to-day.

| Task | Notes |
| --- | --- |
| Admin dashboard | Bookings, units out, revenue |
| Equipment CRUD | All extended fields |
| Appointment management | Confirm / propose pickup windows |
| Staging workflow | Home → store moves |
| Inter-city transfers | Edmonton ↔ Red Deer ↔ Calgary |
| Inventory management | Add-ons stock |
| Booking lifecycle | staged → picked up/shipped → returned |
| Partner swap approval | |

**Done when:** Full ops without touching the database manually.

---

## Phase 5 — Partner Portal & Financials ✅

**Goal:** Partners see their unit; Admin tracks payoff.

| Task | Notes |
| --- | --- |
| Partner dashboard | Their equipment only |
| Partner blocks & swap requests | |
| Equipment cost entry | Subscription, repairs |
| Payment ledger | Square → SQLite |
| Payoff report | Per unit, cents-safe math |
| Revenue by owner | Admin vs each Partner |

---

## Phase 6 — Operations Polish ✅

| Task | Notes |
| --- | --- |
| Customer booking history | Account page |
| Cancellation + Square refund | 72h rule |
| Late return charges | $50/day |
| Email notifications | |
| Mail shipping workflow | Canada-wide |
| Activate Red Deer / Calgary | When ready |

---

## Suggested folder structure

**`website/`** is the root folder for the app. **`public/`**, **`data/`**, and all application code live **inside** `website/`. Nginx exposes only `website/public/`.

Planning docs and the rental agreement stay in the repo root beside `website/`.

```
StarLink/                           # git repo
├── README.md
├── docs/
├── starlink-mini-equipment-rental-agreement.md
└── website/                        # app root — deploy this to VPS
    ├── public/                     # nginx document root
    │   ├── index.php               # front controller
    │   └── assets/
    │       ├── css/
    │       └── js/
    ├── bootstrap.php               # autoload, config, session
    ├── config/
    │   └── app.php
    ├── src/
    │   ├── Auth/
    │   │   ├── Session.php
    │   │   └── AuthService.php
    │   ├── Database/
    │   │   └── Connection.php
    │   ├── Services/
    │   │   ├── AvailabilityService.php
    │   │   ├── AssignmentService.php
    │   │   ├── StagingService.php
    │   │   ├── PricingService.php
    │   │   ├── AppointmentService.php
    │   │   ├── PayoffService.php
    │   │   └── SquareService.php
    │   ├── Controllers/
    │   └── Middleware/
    │       └── RequireRole.php
    ├── templates/
    │   ├── customer/
    │   ├── admin/
    │   └── partner/
    ├── data/
    │   └── starlink.db             # gitignored
    ├── database/
    │   └── migrations/
    │       └── 001_create_users.sql
    ├── bin/
    │   └── migrate.php
    └── .env                        # gitignored
```

**`public/index.php`** defines `WEBSITE_ROOT` (parent of `public/`) and loads `../bootstrap.php`. Paths to DB, migrations, and templates resolve from `website/` — never from inside `public/`.

---

## Immediate next action

v1 is complete. Run `php bin/self-test.php` after migrations to verify the full stack locally.
