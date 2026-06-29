# StarLink Mini Rental — Product Baseline

This document is the reference for all future development. When in doubt, build to this spec.

---

## 1. Vision

Monetize idle Starlink Mini hardware by renting it to customers who need short-term satellite internet — camping, remote work, events, emergencies.

The platform must scale from **one unit in Edmonton** to **many units across multiple Alberta store locations**, including equipment contributed by friends (shared pool).

---

## 2. Users & Roles

| Role | Who | Can do |
| --- | --- | --- |
| **Customer** | Registered renter (account required in v1) | Browse availability, book dates, manage bookings, pay deposit + rental, sign agreement |
| **Admin** | You (Eric) — operator & primary equipment owner | Full access: all locations, all units, transfers, pricing, costs, reports, partner swaps |
| **Partner** | Friend who contributes a unit to the pool | Login → see **only their unit(s)**; block personal-use dates; view their unit’s revenue & payoff; request personal-use swap |

**Naming:** Eric is the **Admin** and primary owner. Friends are **Partners** (not “Owner”) to avoid confusion with legal ownership of the business.

### Partner personal-use swap

When a Partner needs Starlink for personal use:

1. Partner requests personal-use dates on their unit.
2. **Preferred:** Admin assigns Eric’s unit for the Partner to borrow → Partner’s unit **stays in the rental pool** and keeps earning.
3. **Fallback:** No swap available → Partner uses their own unit → unit is blocked → **no rental revenue** during those dates.

Admin approves or triggers swaps from the admin panel.

---

## 3. Core Features

### 3.1 Customer site (v1)

- **Customer accounts** — sign up / login required to book; booking history, saved contact info
- **Location picker** — Edmonton first; Red Deer & Calgary when inventory exists
- **Fulfillment method** — store pickup, home appointment pickup, or **mail shipping** ($150 CAD)
- **Availability calendar** — open dates per location + fulfillment type (accounts for site placement & 1-day staging)
- **Add-on inventory** — batteries and other accessories (not just Starlink Mini)
- **Booking flow** — dates → add-ons → fulfillment → price breakdown → agreement → **Square** checkout
- **Pricing** — auto-calculated from duration tiers (see §5) + shipping if applicable

Calendar shows **available** vs **unavailable**. Unavailable = booked, blocked, in transit, staging to site, or no unit available.

### 3.2 Pickup, staging & shipping

Equipment is not always at the customer-facing pickup point.

| Storage state | Meaning | Customer options |
| --- | --- | --- |
| **At store** | Unit is at the south-side Edmonton store | Store pickup on start date |
| **At home** | Unit is at Admin home (north Edmonton) | Appointment pickup at home **or** request store pickup (+1 day staging) |
| **In transit** | Moving between home ↔ store ↔ customer | Unavailable |
| **With customer** | Out on rental or mail shipment | Unavailable |

**1-day staging rule:** If the unit is **not at the pickup site** the customer chose, the rental start date must be at least **1 day after** the staging/delivery date. Example: unit at home, customer wants south-side store pickup → Admin needs 1 day to drop it at the store before the rental start.

**Mail shipping (v1):** Available **anywhere in Canada** with a **$150 CAD** flat fee (intentionally high to deter most requests). Shipped to customer address; return by mail or drop-off per booking rules.

**Home appointment pickup (v1):**

1. Customer selects home appointment and submits preferred dates/times in booking notes.
2. Booking stays **`appointment_pending`** until Admin manually confirms.
3. Admin confirms a pickup window, **or** if the customer’s request doesn’t work, Admin sets a **proposed date + time range** for the customer to accept.
4. Customer accepts the proposed window → booking proceeds to payment / confirmed state.
5. Until confirmed, the unit is not committed and calendar holds are soft (or admin confirms before payment — implement confirm-before-pay for home appointments).

**Long-term rentals (30+ days):** Not self-serve. **Admin creates** the booking manually (custom rate, dates, customer account).

### 3.3 Admin site

- **Dashboard** — revenue, active bookings, units out, deposits held
- **Equipment registry** — full unit profile (see §6 & DATA-MODEL)
- **Inventory** — batteries and accessories (rental add-ons, stock counts)
- **Location management** — Edmonton, Red Deer, Calgary
- **Transfer / staging** — move unit home → store → another city; updates `at_pickup_site` and staging dates
- **Owner blocks & partner swaps** — block dates; assign Eric’s unit to partner for personal use
- **Bookings** — fulfillment type, shipping address, status, late fees; **admin-created** long-term (30+ day) bookings
- **Home appointments** — confirm customer request or **propose date + time range** for pickup
- **Financials per unit** — CAPEX, subscription costs, revenue, profit, payoff progress
- **Assignment priority** — when auto-assigning, **Eric’s units first**, then Partner units

### 3.4 Partner portal (v1)

- Login → dashboard scoped to **their equipment only**
- Block personal-use dates
- Request swap (borrow Admin’s unit so theirs stays earning)
- Revenue & payoff for their unit(s) only

---

## 4. Multi-Location Design (required from day one)

Even with one Edmonton unit today, the **data model and APIs must support multiple locations** without rework.

### Principles

1. **Location** is a first-class entity — store, home base, or future city.
2. **Equipment** has a **current storage location** and an **`at_pickup_site`** flag (or equivalent).
3. **Customer books at a location + fulfillment method** — system auto-assigns a unit using priority rules.
4. **Adding Red Deer / Calgary** = activate location + add or move equipment. No schema change.

### Unit auto-assignment (v1 — decided)

When a customer books:

1. Find all units available for the date range, location, and fulfillment type (including staging lead time).
2. Sort by **rental priority**: Admin (Eric) units first, then Partner units.
3. Assign the highest-priority available unit automatically. Customer does not pick a unit.

### Availability at a location

A unit is available for a booking if:

```
unit.status == 'active'
AND unit meets staging rule for requested pickup site & start date
AND no customer booking overlaps
AND no owner/partner block overlaps (unless swapped out)
AND not in transit on required dates
AND (if mail) eligible for shipping
```

When multiple units qualify, assignment uses **Admin-first priority**, not customer choice.

---

## 5. Business Rules (from rental agreement + ops)

| Rule | Value |
| --- | --- |
| Minimum rental | 3 days |
| Duration pricing | **Admin-configurable tiers** (min/max days + daily rate). Launch defaults in rental agreement; tier boundaries (e.g. where 7-day rentals fall) can be changed without code changes |
| Rate: 30+ days | Custom — **admin-created booking only** |
| Security deposit | $350 CAD |
| Mail shipping fee | $150 CAD flat — **all Canada** |
| Staging lead time | 1 day if unit not at chosen pickup site |
| Free cancellation | ≥ 72 hours before start → full refund |
| Late cancellation | < 72 hours → forfeit 3-day minimum fee; deposit refunded |
| Late return | $50 CAD / day |
| Damage / loss | Forfeit deposit; charge balance if repair/replace > $350 |

Agreement text: [starlink-mini-equipment-rental-agreement.md](../starlink-mini-equipment-rental-agreement.md)

---

## 6. Equipment Profile (extended fields)

Each Starlink unit tracks operational metadata beyond serial number and cost:

| Field | Purpose |
| --- | --- |
| `purchase_date` | When the kit was bought (CAPEX / depreciation) |
| `starlink_account_email` | Login email on the Starlink service account |
| `data_plan` | e.g. Residential, Roam 50GB, Roam 100GB |
| `billing_cycle_start_day` | Day of month the Starlink bill hits (1–28) |
| `at_pickup_site` | Is the unit physically at the customer-facing pickup location **right now**? |
| `current_storage_location_id` | Home, store, or other (where it actually is) |
| `rental_priority` | Lower number = assigned first (Eric’s units = 0) |
| `owner_type` | `admin` or `partner` |

---

## 7. Add-On Inventory (v1)

Rentals can include items beyond the Starlink Mini kit:

| Example | Notes |
| --- | --- |
| Portable battery | Track stock per location; optional per booking |
| Extra cables / case | Bundled or add-on line items |

Add-ons have their own inventory counts, may be location-scoped, and reduce availability when booked.

---

## 8. Equipment Pool (Admin + Partners)

| Concept | Detail |
| --- | --- |
| **Admin units** | Eric’s kits — rented out **first** |
| **Partner units** | Friend-contributed — rented when Admin units unavailable |
| **Partner visibility** | Partners see only their units |
| **Swap** | Partner personal use may borrow Admin unit so Partner unit keeps earning |
| **Payouts** | Manual in v1; track revenue per unit for later split reports |

---

## 9. Technical Stack

Small business scale (~**12 Starlink Mini units** max). Keep the stack simple, self-hosted, and easy to maintain solo.

| Layer | Choice | Why |
| --- | --- | --- |
| Language | **PHP 8.4** | Your hosting; mature, low overhead |
| Database | **SQLite 3.46** | Single file; plenty for ~12 units; easy backup |
| SQLite mode | **WAL enabled** | Better concurrent reads during bookings |
| Data access | **PDO + raw SQL** | No ORM; SQL migration files in repo |
| Auth | **Custom PHP auth** | Sessions + `password_hash()` / `password_verify()` |
| Payments | **Square API** | Deposit, rental, shipping, late fees |
| Frontend | **Server-rendered PHP** + minimal JS | Calendar/availability via fetch or HTMX optional |
| Hosting | **Your own server** | PHP-FPM or Apache; see §9.1 |

**Not used:** Prisma, PostgreSQL, Next.js, Clerk, NextAuth, or third-party auth SaaS.

### Database conventions

- SQL schema in `database/migrations/*.sql`
- Single SQLite file: `website/data/starlink.db` — not web-exposed
- On every connection: `PRAGMA journal_mode=WAL;` `PRAGMA foreign_keys=ON;`
- Integer primary keys (`INTEGER PRIMARY KEY AUTOINCREMENT`)
- Money stored as **integer cents** (`amount_cents`) to avoid float errors

### Auth conventions

- PHP session cookies (`session_start()`), httponly + secure flags in production
- Passwords: `password_hash($password, PASSWORD_DEFAULT)` (PHP 8.4)
- Roles stored on `users.role`: `customer`, `admin`, `partner`
- Login rate limiting on failed attempts (session or DB counter)
- Optional `remember_token` hash for “stay logged in”

### Key routes (conceptual)

```
GET  /                          # customer home / calendar
GET  /availability              # JSON: location, fulfillment, date range
POST /bookings                  # customer create (not 30+ day)
GET  /account/bookings          # customer history

GET  /admin/…                   # dashboard, equipment, bookings
POST /admin/bookings            # admin-created long-term bookings
POST /admin/appointments/…      # confirm or propose home pickup window

GET  /partner/…                 # scoped to partner’s units

POST /webhooks/square           # payment confirmations
```

### 9.1 Self-hosted deployment

| Component | Recommendation |
| --- | --- |
| Web server | **Nginx** or **Apache** → PHP-FPM 8.4 |
| Database | **SQLite file** on disk (`data/starlink.db`) |
| TLS | Let’s Encrypt via Certbot or Caddy |
| Secrets | `.env` or server env vars (Square keys, session secret) |
| Backups | Copy `starlink.db` (+ `-wal`/`-shm` if hot backup) daily |

**Typical VPS layout:**

**`website/`** is the project root for the app. Nginx `root` points at **`website/public/`** only. Everything else lives under `website/` and is not web-exposed.

```
StarLink/                           # git repo (planning + app)
├── docs/
├── starlink-mini-equipment-rental-agreement.md
└── website/                        # deploy this folder — app root
    ├── public/                     # nginx document root
    │   ├── index.php               # front controller
    │   └── assets/                 # css, js, images
    ├── src/                        # PHP application code
    ├── templates/
    ├── config/
    ├── bootstrap.php
    ├── data/
    │   └── starlink.db             # SQLite (WAL); gitignored
    ├── database/migrations/
    ├── bin/migrate.php
    └── .env                        # gitignored
```

**On VPS:** deploy `website/` to e.g. `/var/www/starlink/`, then `root /var/www/starlink/public;`

**Nginx:** PHP-FPM runs `public/index.php`, which bootstraps from `../bootstrap.php`. Block any URL that tries to reach `data/`, `src/`, `config/`, etc.

**Square webhooks:** Public HTTPS URL → `POST /webhooks/square`.

**Why SQLite fits:** ~12 units, single operator, low concurrent write volume. WAL handles multiple readers (calendar + admin) while one booking writes. Revisit PostgreSQL only if you outgrow this or need multi-server deploy.

---

## 10. v1 Scope Boundaries

### In v1

- Customer accounts (required to book)
- Starlink Mini + add-on inventory (batteries, accessories)
- Store pickup, home appointment pickup, mail shipping ($150)
- Multi-location data model (Edmonton live; other cities seeded inactive)
- Equipment staging (1-day rule) & `at_pickup_site`
- Admin + Partner roles with scoped access
- Auto-assign units (Admin units first)
- Partner swap workflow
- Square payments
- Extended equipment fields (billing, data plan, account email, purchase date)
- Home appointment confirm / propose pickup window
- Admin-created bookings for 30+ day rentals
- Mail shipping to all Canada

### Not in v1

- Mobile app
- Automated partner payouts / revenue split
- Real-time GPS tracking
- SMS notifications (email OK for v1)

---

## 11. Decided (formerly open)

| Question | Decision |
| --- | --- |
| Mail shipping geography | **All Canada** |
| Home appointment scheduling | **Admin confirms manually**; can **propose date + time range** if customer request doesn’t work |
| Long-term (30+ day) bookings | **Admin-created** only |
| Battery inventory | Per-location stock (default unless changed during build) |

---

## 12. Success Metrics

- **Utilization** — % of days rented per unit per month
- **Payoff time** — months until unit CAPEX recovered
- **Admin vs Partner revenue** — confirm priority routing works
- **Shipping vs pickup mix** — validate $150 fee deters as intended
- **Late return rate** — operational health

---

## 13. Glossary

| Term | Meaning |
| --- | --- |
| **Unit / Equipment** | One Starlink Mini kit |
| **Partner** | Friend who contributes a unit; limited portal access |
| **Admin** | Eric — operator, primary owner, full access |
| **Block** | Reserved dates — personal use, maintenance, staging |
| **Swap** | Partner borrows Admin unit; Partner unit stays in rental pool |
| **Staging** | 1-day move to get unit to pickup site before rental |
| **At pickup site** | Unit is physically at the customer-facing pickup location |
| **Add-on** | Battery or accessory rented with a kit |
| **Payoff** | Cumulative profit reaching purchase cost |
| **Location** | Store, home base, or city pickup point |
| **Proposed window** | Admin-set date/time range when customer request doesn’t work |
