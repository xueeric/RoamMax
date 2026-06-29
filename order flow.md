# RoamMax Order Flow — Current Logic & All Possibilities

> **Purpose:** Document legacy behaviour (§1–§20), define the **target spec** (§21–§27), and provide an implementation checklist (§25). **§27 = locked decisions. Build from §24–§27.**
>
> **Codebase:** Production logic lives in `website/` (PHP + SQLite). The `design/` folder is a disconnected React prototype — not production.

---

## Table of Contents

1. [The Bug You Hit (RJM6BU example)](#1-the-bug-you-hit-rjm6bu-example)
2. [Booking Statuses](#2-booking-statuses)
3. [Fulfillment Types](#3-fulfillment-types)
4. [Payment Methods Overview](#4-payment-methods-overview)
5. [End-to-End Flow Diagram](#5-end-to-end-flow-diagram)
6. [Path A — Customer Self-Serve Booking](#6-path-a--customer-self-serve-booking)
7. [Path B — Interac e-Transfer Payment](#7-path-b--interac-e-transfer-payment)
8. [Path C — Square Card Payment (Short-Term ≤ 4 days)](#8-path-c--square-card-payment-short-term--4-days)
9. [Path D — Square Card Payment (Long-Term 5–30 days)](#9-path-d--square-card-payment-long-term-530-days)
10. [Path E — Admin-Created Booking](#10-path-e--admin-created-booking)
11. [Path F — Long-Term Request (> 30 days)](#11-path-f--long-term-request--30-days)
12. [Path G — Pickup Appointment Sub-Flow](#12-path-g--pickup-appointment-sub-flow)
13. [Path H — Admin Fulfillment (After Payment)](#13-path-h--admin-fulfillment-after-payment)
14. [Path I — Return & Deposit Release](#14-path-i--return--deposit-release)
15. [Path J — Late Returns](#15-path-j--late-returns)
16. [Path K — Cancellation (All Cases)](#16-path-k--cancellation-all-cases)
17. [Side Effects Matrix](#17-side-effects-matrix)
18. [Known Bugs & Gaps (Current Logic)](#18-known-bugs--gaps-current-logic)
19. [What Correct Logic Should Be](#19-what-correct-logic-should-be)
20. [Key Source Files](#20-key-source-files)
21. [Proposed Status Model — Validation](#21-proposed-status-model--validation)
22. [Return Inspection & Square Short-Term Limits](#22-return-inspection--square-short-term-limits)
23. [Availability & Unit Assignment Philosophy](#23-availability--unit-assignment-philosophy)
24. [Final Spec — Locked Status Enums](#24-final-spec--locked-status-enums)
25. [Implementation Checklist (fix codebase from this doc)](#25-implementation-checklist-fix-codebase-from-this-doc)
26. [Notification Rules — Admin Panel (email & Telegram)](#26-notification-rules--admin-panel-email--telegram)
27. [Decisions Locked](#27-decisions-locked)

---

## 1. The Bug You Hit (RJM6BU example)

**Scenario:** Booking `RJM6BU`, dates 2026-06-14 → 2026-06-17, pickup, status shows `cancelled`, total $455.00.

**What the customer sees:** Press Cancel → booking immediately shows `cancelled`. Done.

**What actually happens in the database:**
- `bookings.status` → `cancelled`
- `cancelled_at` timestamp set
- Add-on inventory released
- Staging events cancelled
- Ledger rows written (`cancellation_fee`, `deposit_refund`) — **ledger only, no real money movement**
- Customer notification logged (`bookingCancelled`)

**What does NOT happen (the problem):**
- ❌ No admin notification (email/alert)
- ❌ No Square refund if rental was already charged
- ❌ No Square deposit hold cancellation
- ❌ Equipment assignment not explicitly freed (depends on prior status)
- ❌ Admin must manually filter to "Cancelled" to notice — no proactive alert
- ❌ Customer message says "Full refund issued" even when no refund API call was made

**Root cause:** `BookingService::cancel()` is a **database-only** operation. Customer and admin use the same cancel function; `$byAdmin` only skips the ownership check.

---

## 2. Booking Statuses

| Status | Meaning | Who sets it |
|--------|---------|-------------|
| `pending_payment` | Booking created, awaiting payment | Booking create (customer or admin) |
| `booked_deposit_pending` | Square short-term: rental charged, deposit auth scheduled T-24h | `SquareDepositService::processShortTermCheckout` |
| `deposit_failed` | Deposit authorization failed | `SquareDepositService::markDepositFailed` |
| `ready_for_pickup` | Paid + deposit OK (Square long-term OR short-term after auth) | Square checkout or deposit cron |
| `confirmed` | Paid via e-Transfer (admin confirmed) OR legacy `markPaid` | Admin confirm e-Transfer / webhook |
| `staged` | Hardware staged for handoff | Admin action: Stage |
| `picked_up` | Customer has unit (pickup/delivery/home) | Admin action: Picked up |
| `shipped` | Mail shipment dispatched | Admin action: Shipped |
| `late` | Overdue return; late fees applied | Admin action: Late fee |
| `returned` | Rental closed | Admin action: Returned |
| `cancelled` | Booking cancelled | Customer or admin cancel |

**Dead status:** `appointment_pending` — appears in UI labels but **no code path ever sets it**.

**Appointment sub-status** (`appointment_status`) — pickup appointment only:

| Value | When |
|-------|------|
| `n/a` | All non–pickup-appointment bookings |
| `awaiting_admin` | Customer pickup appointment after booking |
| `proposed` | Admin proposed alternate pickup window |
| `confirmed` | Admin confirmed or customer accepted proposal |

---

## 3. Fulfillment Types

| Type | Description | Equipment assigned at | Admin handoff action |
|------|-------------|----------------------|---------------------|
| `pickup` | Customer picks up at store | Booking create | Picked up |
| `pickup_appointment` | Admin delivers to customer home (scheduled appointment) | Booking create (or on appointment confirm) | Picked up |
| `mail_ship` | Shipped via mail | Booking create (from storage location) | Shipped |
| `city_delivery` | Delivered within city | Booking create | Picked up |

**Rename from current code (implement in Phase 1):**

| Old value | New value |
|-----------|-----------|
| `store_pickup` | `pickup` |
| `home_appointment` | `pickup_appointment` |

`mail_ship` and `city_delivery` unchanged.

**Return location rules:**
- `pickup`, `city_delivery` → return to booking location
- `pickup_appointment` → return to store in same city
- `mail_ship` → no auto return location

---

## 4. Payment Methods Overview

Only **two** methods are valid (`PaymentService::normalizeMethod`):

| Method | Code | When chosen |
|--------|------|-------------|
| Square (card) | `square` | Checkout form |
| Interac e-Transfer | `etransfer` | Checkout form |

**Amount charged:**

```
Total due = rental + shipping + add-ons + tax + deposit
Rental charge only (Square short-term) = rental + shipping + add-ons + tax  (deposit held later)
```

**Short-term vs long-term split (Square payment flow only):**

| Rental length | Square payment flow | Max at checkout |
|---------------|---------------------|-----------------|
| 1–4 days | Short-term (rental charged, deposit T-1) | **4 days max** |
| 5–30 days | Long-term (rental + deposit upfront) | 30 days (self-serve) |
| 31+ days | Long-term request → admin booking | — |

e-Transfer has **no** 4-day cap. See §27 for locked decisions.

---

## 5. End-to-End Flow Diagram

```
CUSTOMER BOOKING
       │
       ▼
 pending_payment ─────────────────────────────────────────────┐
       │                                                        │
       ├─── e-Transfer ──► customer clicks "I sent" ──► wait ──┤
       │         (etransfer_notified_at set, status unchanged)  │
       │                        │                               │
       │                        ▼                               │
       │              admin confirms e-Transfer                 │
       │                        │                               │
       │                        ▼                               │
       │                   confirmed ◄──────────────────────────┤
       │                                                        │
       ├─── Square short (≤6d) ──► rental charged              │
       │         │                                                │
       │         ▼                                                │
       │   booked_deposit_pending                                 │
       │         │                                                │
       │         ├── cron T-24h ──► deposit auth OK ──► ready_for_pickup
       │         │                                                │
       │         └── deposit auth fail ──► deposit_failed        │
       │                   │                                      │
       │                   └── customer updates card ──► ready_for_pickup
       │                                                        │
       └─── Square long (5–30d) ──► rental+deposit charged ──► ready_for_pickup
                                                                │
                    ┌───────────────────────────────────────────┘
                    ▼
         confirmed OR ready_for_pickup
                    │
                    ▼
                 staged
                    │
          ┌─────────┴─────────┐
          ▼                   ▼
     picked_up            shipped (mail only)
          │                   │
          └─────────┬─────────┘
                    ▼
              returned (+ deposit release)
                    │
                    ▼
                  [closed]

Any active status ──► cancelled (customer or admin)
returned / cancelled ──► [closed, no further actions]
```

---

## 6. Path A — Customer Self-Serve Booking

**Trigger:** Customer completes checkout (`POST /checkout`)

**Preconditions:**
- Dates available
- Rental agreement accepted
- Home + billing addresses valid
- Shipping/delivery address if mail or city delivery
- Payment method selected (`square` or `etransfer`)
- Rental ≤ 30 days (self-serve max)

**What happens on create (`BookingService::createCustomerBooking`):**

| Step | Action |
|------|--------|
| 1 | Check availability for date range |
| 2 | Assign equipment unit |
| 3 | Calculate quote (rental, shipping, add-ons, tax, deposit) |
| 4 | Generate reference code (e.g. `RJM6BU`) |
| 5 | Insert booking with status `pending_payment` |
| 6 | Persist add-ons + reserve add-on inventory |
| 7 | Schedule staging event if needed |
| 8 | Set `appointment_status = awaiting_admin` if pickup appointment |

**Redirect:** Payment checkout page based on method (`PaymentService::startCheckout`)

**Customer next actions:**
- Pay (Square) → card page
- Pay (e-Transfer) → instructions page
- Change payment method → `POST /account/bookings/pay`
- Cancel → only if NOT `pending_payment` (UI hides cancel for unpaid; API allows it)

---

## 7. Path B — Interac e-Transfer Payment

### B1 — Customer selects e-Transfer at checkout

```
pending_payment  (payment_method = etransfer)
```

Customer redirected to `/booking/etransfer?booking_id=X`

**Page shows:**
- e-Transfer email: `payments@roammax.ca` (configurable)
- Amount: full total (rental + shipping + add-ons + tax + deposit)
- Memo/reference: booking reference code (e.g. `RJM6BU`)

### B2 — Customer sends transfer externally, clicks "I have sent the e-Transfer"

**Route:** `POST /booking/etransfer`  
**Handler:** `BookingService::acknowledgeEtransfer`

| Field updated | Value |
|---------------|-------|
| `etransfer_notified_at` | current timestamp |
| `status` | **unchanged** — still `pending_payment` |

**Customer sees:** Still awaiting payment (admin must confirm)

**Admin sees:** Booking in "Awaiting payment" filter, badges: `e-Transfer`, `sent` (if notified)

### B3 — Admin confirms payment received

**Route:** `POST /admin/bookings/action` (action = `confirm_etransfer`)  
**Handler:** `BookingService::markPaid(..., 'etransfer')`

| Field updated | Value |
|---------------|-------|
| `status` | `confirmed` |
| `square_payment_id` | `etransfer_{bookingId}` (reference string, not Square) |
| Payment row | type `checkout`, full amount, status `completed` |

**Notification:** `bookingConfirmed` → customer

**Admin next:** Can Stage → Picked up / Shipped

### B4 — Customer never sends e-Transfer

Booking stays `pending_payment` indefinitely until:
- Customer pays via different method (change to Square)
- Customer cancels (allowed while `booking_pending_payment` — §27)
- Admin cancels

### B5 — Admin never confirms / payment not received

Customer clicked "I sent" but admin doesn't see payment in bank.

**Action:** Admin **cancels with reason** (e.g. “Payment not received”) — required `cancellation_reason` text. Customer notified per §26 rules.

| Party | State |
|-------|-------|
| Customer | Notified of cancellation + reason |
| Admin | Enters reason at cancel time |
| Booking | `booking_cancelled`, equipment released |

### B6 — Partial e-Transfer amount (§27 #15)

Admin confirms **partial** payment when amount ≠ total due:

| Case | Action |
|------|--------|
| **Underpaid** | `payment_etransfer_partial_confirmed` — notify customer to pay remainder; booking stays `booking_pending_payment` until rest received or admin cancels |
| **Overpaid** | Confirm booking; excess recorded — refund difference at deposit return / close |
| **Exact** | Normal `payment_etransfer_confirmed` |

---

## 8. Path C — Square Card Payment (Short-Term ≤ 4 days)

**Applies when:** rental day count **1–4** (Square short-term payment flow — §27)

### C1 — Customer pays with card

**Route:** `POST /booking/pay-card`  
**Handler:** `SquareDepositService::processShortTermCheckout`

| Step | Action |
|------|--------|
| 1 | Charge rental only (no deposit) via Square |
| 2 | Create Square customer profile |
| 3 | Save card on file from payment |
| 4 | Set status → `booked_deposit_pending` |
| 5 | Set `square_payment_flow = short_term_auth` |
| 6 | Insert payment row (rental amount) |

**Notification:** `bookingDepositPending` → customer ("deposit will authorize 24h before start")

### C2 — Deposit authorization (cron, day before start)

**Cron:** `bin/process-deposit-holds.php`  
**Logic:** Finds `booked_deposit_pending` where `start_date = tomorrow`, calls `authorizeDepositHold`

**Success:**
| Field | Value |
|-------|-------|
| `status` | `ready_for_pickup` |
| `square_deposit_payment_id` | auth hold ID |
| `deposit_auth_at` | timestamp |
| Payment row | type `deposit`, status `pending` |

**Notification:** `bookingReadyForPickup` → customer

**Failure:**
| Field | Value |
|-------|-------|
| `status` | `deposit_failed` |

**Notifications:**
- Admin email: "Deposit authorization failed — do not release hardware"
- Customer: `depositAuthorizationFailed` with update-card link

### C3 — Customer updates card after deposit failure

**Route:** `POST /booking/update-card`  
**Handler:** `SquareDepositService::updateCardAndRetryDeposit`

- Saves new card on Square customer
- Retries deposit authorization immediately
- Success → `ready_for_pickup`
- Failure → stays `deposit_failed`

### C4 — Admin cannot release hardware until deposit OK

`OperationsService::transition` blocks Stage/Pickup/Ship if status is `booked_deposit_pending` or `deposit_failed`.

---

## 9. Path D — Square Card Payment (Long-Term 5–30 days)

**Applies when:** rental day count **5–30** (self-serve checkout — same as today)

### D1 — Customer pays with card

**Handler:** `SquareDepositService::processLongTermCheckout`

| Step | Action |
|------|--------|
| 1 | Charge full amount (rental + deposit) in one payment |
| 2 | Set status → `ready_for_pickup` immediately |
| 3 | Set `square_payment_flow = long_term_capture` |
| 4 | Insert payment row (full amount) |

**Notification:** `bookingConfirmed` → customer

**No deposit cron needed** — deposit already captured.

### D2 — Admin fulfillment

Same as e-Transfer after payment: Stage → Picked up / Shipped → Returned

### D3 — Deposit refund on return

On admin "Returned": `SquareDepositService::releaseDepositOnReturn` → partial Square refund of deposit amount.

---

## 10. Path E — Admin-Created Booking

**Trigger:** Admin creates booking (`POST /admin/bookings/new`)

**Differences from customer booking:**

| Field | Value |
|-------|-------|
| `is_admin_created` | 1 |
| `status` | `pending_payment` |
| `payment_method` | **NOT SET** — customer must choose later |
| `appointment_status` | `confirmed` if pickup appointment (pre-confirmed) |

**Customer next steps:**
1. Log in → My Bookings
2. Choose payment method (`POST /account/bookings/pay`)
3. Pay via Square or e-Transfer (same paths B/C/D)

---

## 11. Path F — Long-Term Request (> 30 days)

**Trigger:** Customer submits long-term request form (`POST /book/long-term-request`)

**Flow:**
1. Request stored in `long_term_requests` table
2. Admin reviews in admin panel
3. Admin manually creates booking via admin form (Path E)
4. Customer pays normally

**Not auto-checkout** — requires admin involvement for pricing/dates.

---

## 12. Path G — Pickup Appointment Sub-Flow

Runs **in parallel** with payment status. Only applies when `fulfillment_type = pickup_appointment`.

### G1 — Customer books pickup appointment

```
appointment_status = awaiting_admin
status = pending_payment (until paid)
```

Customer sees: "Pickup time pending"

### G2 — Admin confirms pickup window

**Route:** `POST /admin/appointments/confirm`  
**Handler:** `BookingService::confirmAppointment`

```
appointment_status = confirmed
confirmed_pickup_date / time set
equipment assigned if not already
```

**Notification:** `appointmentUpdate` → customer

### G3 — Admin proposes alternate window

**Route:** `POST /admin/appointments/propose`  
**Handler:** `BookingService::proposeAppointmentWindow`

```
appointment_status = proposed
proposed_pickup_date / time set
```

**Notification:** customer told to accept from bookings page

### G4 — Customer accepts proposal

**Route:** `POST /account/bookings/accept`  
**Handler:** `BookingService::acceptProposal`

```
appointment_status = confirmed
confirmed dates = proposed dates
```

### G5 — Admin-created pickup appointment

Skips queue — starts at `appointment_status = confirmed` immediately.

**Important:** Appointment confirmation does NOT change payment status. Customer can have confirmed pickup time while still `pending_payment`.

---

## 13. Path H — Admin Fulfillment (After Payment)

**Precondition:** Status is `confirmed` OR `ready_for_pickup` (and NOT `booked_deposit_pending` / `deposit_failed`)

| Admin action | From status | To status | Side effects |
|--------------|-------------|-----------|--------------|
| **Stage** | `confirmed`, `ready_for_pickup` | `staged` | Complete staging event |
| **Picked up** | `confirmed`, `ready_for_pickup`, `staged` | `picked_up` | Equipment → `with_customer` (not for mail) |
| **Shipped** | `confirmed`, `ready_for_pickup`, `staged` | `shipped` | Equipment → `with_customer`, customer notified (mail only) |
| **Returned** | `picked_up`, `shipped`, `late` | `returned` | Deposit release, equipment → `active`, add-ons released |
| **Late fee** | `picked_up`, `shipped`, `late` | `late` | Fee payment row, customer notified |
| **Cancel** | any except `cancelled`, `returned` | `cancelled` | See Path K |

**Mail vs pickup guard:**
- Mail bookings: cannot "Picked up", must "Shipped"
- Non-mail bookings: cannot "Shipped", must "Picked up"

---

## 14. Path I — Return & Deposit Release

**Trigger:** Admin clicks "Returned"

### Square short-term (`short_term_auth`)
- Cancel deposit hold via Square API (`cancelPayment`)
- Record `deposit_refund` in payments ledger

### Square long-term (`long_term_capture`)
- Partial refund of deposit via Square API (`refundPayment`)
- Record `deposit_refund` in payments ledger

### e-Transfer
- Record `deposit_refund` in ledger only (**manual bank refund required**)

### All methods
- Equipment status → `active`
- Equipment location updated (store in same city for pickup appointments)
- Add-on inventory released

---

## 15. Path J — Late Returns

**Trigger:** Admin clicks "Late fee" after end date has passed

**Preconditions:**
- Status: `picked_up`, `shipped`, or already `late`
- Today > end_date

**Calculation:**
```
days_late = days from (end_date + 1) to today
fee = days_late × late_fee_cents_per_day  (default $50/day)
```

**Effects:**
- Status → `late`
- Payment row: type `late_fee`
- Customer notification

Admin can still mark "Returned" from `late` status.

---

## 16. Path K — Cancellation (All Cases)

**Single code path:** `BookingService::cancel($bookingId, $actorUserId, $byAdmin)`

### Who can cancel

| Actor | Condition |
|-------|-----------|
| Customer | Owns booking, any status except `cancelled` / `returned` |
| Admin | Any booking except `cancelled` / `returned` |

**UI difference:** Customer cancel button hidden for `pending_payment`, `returned`, `cancelled`. Admin always sees Cancel button.

### Cancellation fee logic

```
hours_until_start = start_date - now
free_cancel = hours_until_start >= 72 hours  (config: cancellation_free_hours)

if free_cancel:
    cancellation_fee = $0
else:
    cancellation_fee = min(rental_total, daily_rate × minimum_rental_days)
    // minimum_rental_days default = 3
```

### What cancel DOES today

| Effect | Happens? |
|--------|----------|
| Set `status = cancelled` | ✅ |
| Set `cancelled_at` | ✅ |
| Set `cancellation_fee_cents` | ✅ |
| Insert `cancellation_fee` payment row (if fee > 0) | ✅ ledger only |
| Insert `deposit_refund` payment row (if deposit > 0) | ✅ ledger only |
| Release add-on inventory | ✅ |
| Cancel staging events | ✅ |
| Notify customer | ✅ |
| Notify admin | ❌ |
| Square rental refund | ❌ |
| Square deposit hold cancel | ❌ |
| Free equipment for rebooking | ❌ (not explicitly reset) |

### Cancel at each status — what SHOULD happen vs what DOES happen

| Status at cancel | Customer paid? | Should happen | Actually happens |
|------------------|---------------|---------------|------------------|
| `pending_payment` | No | Free cancel, release inventory | DB cancel only, no admin alert |
| `booked_deposit_pending` | Rental charged | Refund rental, release inventory, notify admin | DB cancel only, **rental NOT refunded** |
| `deposit_failed` | Rental charged | Refund rental, notify admin | DB cancel only, **rental NOT refunded** |
| `confirmed` (e-Transfer) | Full amount | Refund minus fee, notify admin | DB cancel only, **manual refund needed** |
| `ready_for_pickup` | Full amount / rental+deposit | Refund per policy, cancel deposit hold | DB cancel only |
| `staged` | Paid | Refund per policy, un-stage | DB cancel only |
| `picked_up` / `shipped` | Paid, unit out | Should NOT allow free cancel — unit with customer | **Cancel IS allowed** — no equipment reset |
| `late` | Paid + late fees | Complex partial refund | DB cancel only |

---

## 17. Side Effects Matrix

| Event | Equipment | Add-on inventory | Staging | Square | Customer notify | Admin notify |
|-------|-----------|-----------------|---------|--------|----------------|--------------|
| Create booking | Assigned | Reserved | Scheduled | — | — | — |
| Square short pay | — | — | — | Rental charged | deposit pending | — |
| Square long pay | — | — | — | Full charged | confirmed | — |
| Deposit auth OK | — | — | — | Hold placed | ready for pickup | — |
| Deposit auth fail | — | — | — | — | failed + link | **email** |
| e-Transfer ack | — | — | — | — | — | — |
| e-Transfer confirm | — | — | — | — | confirmed | — |
| Stage | — | — | Completed | — | — | — |
| Pickup/Ship | → with_customer | — | — | — | shipped (mail) | — |
| Return | → active | Released | — | Deposit release | — | — |
| Late fee | — | — | — | — | late fee | — |
| Cancel | **not reset** | Released | Cancelled | **no refund** | cancelled | **none** |

---

## 18. Known Bugs & Gaps (Current Logic)

| # | Issue | Impact |
|---|-------|--------|
| 1 | **Cancel = DB only, no payment reversal** | Customer told "refund issued" but Square never called |
| 2 | **No admin notification on customer cancel** | Admin doesn't know booking cancelled until they check list |
| 3 | **Customer can cancel after pickup** | Unit marked `with_customer` but booking `cancelled` |
| 4 | **`confirmed` vs `ready_for_pickup` inconsistency** | e-Transfer → `confirmed`; Square long → `ready_for_pickup`; same fulfillment step |
| 5 | **e-Transfer has no admin queue/alert** | Admin must manually scan "Awaiting payment" list |
| 6 | **Webhook bypasses deposit flow** | Square webhook `markPaid` → `confirmed`, skips short/long-term logic |
| 7 | **Webhook signature not verified** | Security gap on `/webhooks/square` |
| 8 | **`deposit_auth_lead_hours` config unused** | Cron hardcodes `start_date = tomorrow` |
| 9 | **Admin booking has no payment_method** | Extra step for customer |
| 10 | **`appointment_pending` status never set** | Dead code in labels |
| 11 | **Cancel on `booked_deposit_pending` leaves rental charged** | Money captured, booking cancelled, no refund |
| 12 | **Equipment not freed on cancel** | Unit may stay assigned to cancelled booking |
| 13 | **Customer cancel hidden for `pending_payment` in UI but API allows it** | Inconsistent UX |
| 14 | **Notifications are log-only** | Written to DB + file, not real SMTP |

---

## 19. What Correct Logic Should Be

This section defines the **target behavior** to implement after clearing current logic.

### 19.1 Master Flow

```
Customer Booking
       │
       ▼
  Choose Payment Method
       │
       ├─── e-Transfer ──────────────────────────────────────┐
       │                                                      │
       └─── Square Card ─────────────────────────────────────┤
                                                              │
                                                              ▼
                                                    Payment Complete
                                                    (status: paid / ready)
                                                              │
                                                              ▼
                                                    Admin Fulfillment
                                                    (stage → handoff → return)
                                                              │
                    ┌─────────────────────────────────────────┤
                    │                                         │
                    ▼                                         ▼
              Cancelled                                    Returned
           (with refunds)                                  (closed)
```

### 19.2 e-Transfer — Correct Flow

```
1. Customer books → pending_payment (method = etransfer)
2. Customer sees e-Transfer instructions (email, amount, reference memo)
3. Customer sends money via their bank app
4. Customer clicks "I have sent the e-Transfer"
   → etransfer_notified_at set
   → status stays pending_payment
   → ADMIN NOTIFIED (new: email/alert/dashboard badge)
5. Admin verifies bank account received payment
6. Admin clicks "Confirm e-Transfer received"
   → status = confirmed (or unified "ready_for_pickup")
   → payment row created
   → CUSTOMER NOTIFIED: booking confirmed
7. Admin proceeds with fulfillment (stage → pickup/ship)
```

**If admin rejects / payment not found:**
- Admin should be able to mark "Payment not received" or contact customer
- Booking stays pending or admin cancels with reason

### 19.3 Square Short-Term — Correct Flow

```
1. Customer books → pending_payment (method = square)
2. Customer pays card → rental charged
   → status = booked_deposit_pending
   → card saved on file
   → CUSTOMER NOTIFIED: rental paid, deposit will authorize before start
3. T-24h before start: cron authorizes deposit hold
   → success: status = ready_for_pickup, CUSTOMER NOTIFIED
   → failure: status = deposit_failed, ADMIN + CUSTOMER NOTIFIED
4. If deposit_failed: customer updates card → retry
5. Admin stages and releases hardware
6. On return: cancel deposit hold, refund if needed
7. On cancel BEFORE start:
   → refund rental (Square API)
   → cancel deposit hold if already authorized
   → release inventory
   → ADMIN NOTIFIED
```

### 19.4 Square Long-Term — Correct Flow

```
1. Customer books → pending_payment
2. Customer pays card → rental + deposit charged in one payment
   → status = ready_for_pickup
   → CUSTOMER NOTIFIED: booking confirmed
3. Admin stages and releases hardware
4. On return: partial refund of deposit (Square API)
5. On cancel BEFORE pickup:
   → full refund minus cancellation fee (Square API)
   → release inventory
   → ADMIN NOTIFIED
6. On cancel AFTER pickup:
   → should be blocked or require admin-only with damage assessment
```

### 19.5 Cancellation — Correct Rules

| When | Who | Fee | Refund | Admin notify |
|------|-----|-----|--------|--------------|
| Before payment | Customer | $0 | N/A | Optional |
| Before payment | Admin | $0 | N/A | Customer notify |
| Paid, > 72h before start | Customer | $0 | Full refund via payment method | **Yes** |
| Paid, < 72h before start | Customer | min(rental, 3× daily rate) | Remainder refunded | **Yes** |
| Paid, after pickup | Customer | **Blocked** | N/A | N/A |
| Paid, after pickup | Admin | Case-by-case | Manual | Customer notify |
| Deposit failed | Customer | $0 | Rental refunded | **Yes** |
| Any cancel | — | — | Square API refund OR e-Transfer manual refund flag | **Yes** |

### 19.6 Unified "Paid" Status

Consider merging `confirmed` and `ready_for_pickup` into one status (e.g. `paid` or keep `ready_for_pickup` for all paid bookings) to simplify admin UI and fulfillment guards.

### 19.7 Admin Dashboard Alerts (Missing Today)

Admin should see proactive alerts for:
- e-Transfer sent, awaiting confirmation
- Customer cancelled booking
- Deposit authorization failed
- Booking starting in 24h without deposit auth (short-term)
- Late returns

---

## 20. Key Source Files

| File | Responsibility |
|------|---------------|
| `website/src/Services/BookingService.php` | Create, cancel, markPaid, appointments, e-Transfer ack |
| `website/src/Services/PaymentService.php` | Payment method routing, e-Transfer memo |
| `website/src/Services/SquareDepositService.php` | Square short/long checkout, deposit auth, release |
| `website/src/Services/SquareService.php` | Square API calls, webhooks |
| `website/src/Services/OperationsService.php` | Admin fulfillment (stage/pickup/ship/return) |
| `website/src/Services/NotificationService.php` | All notifications (log-only today) |
| `website/src/Services/StagingService.php` | Staging event scheduling |
| `website/src/Controllers/AdminOpsController.php` | Admin booking actions |
| `website/src/Controllers/AccountController.php` | Customer cancel, accept proposal |
| `website/src/Controllers/BookingController.php` | Checkout, pay-card, e-Transfer |
| `website/templates/customer/bookings.php` | Customer booking list + actions |
| `website/templates/admin/bookings.php` | Admin booking list + actions |
| `website/bin/process-deposit-holds.php` | Cron: deposit authorization |
| `website/config/app.php` | Fees, thresholds, Square config |

---

## Appendix: Decision Tree for "What status should this booking be?"

```
Is booking cancelled or returned?
  YES → stop
  NO ↓

Is payment received?
  NO → pending_payment
  YES ↓

Payment method = etransfer?
  YES → confirmed (today) / ready_for_pickup (proposed)
  NO ↓

Square flow = short_term_auth?
  YES ↓
    Is deposit authorized?
      NO, rental paid → booked_deposit_pending
      NO, auth failed → deposit_failed
      YES → ready_for_pickup
  NO ↓

Square flow = long_term_capture?
  YES → ready_for_pickup
  NO ↓

Is hardware staged?
  YES → staged
  NO ↓

Is hardware with customer?
  YES ↓
    Mail? → shipped : picked_up
  NO ↓

Is rental overdue?
  YES → late
  NO → ready_for_pickup / confirmed
```

---

## 21. Proposed Status Model — Validation

You proposed splitting state into **three independent tracks** instead of one mixed `bookings.status` field. That is the right direction. Below: validate each name, fix typos, flag gaps, and recommend a complete set.

### 21.1 Design principle — three axes

| Axis | Column name (suggested) | Answers |
|------|-------------------------|---------|
| **Payment** | `payment_status` | Has money been collected? Deposit hold? Refunds? |
| **Booking** | `booking_status` | Is the rental active, pending, closed? (customer-facing lifecycle) |
| **Fulfillment** | `fulfillment_status` | Where is the hardware? (depends on `fulfillment_type`) |

One booking always has all three. Example: pickup, Square short-term, unit staged:

```
payment_status     = payment_deposit_scheduled_processed
booking_status     = booking_confirmed
fulfillment_status = fulfillment_staged        ← not a shipping_* state
```

**Rule:** `shipping_*` applies only when `fulfillment_type = mail_ship`. Pickup and pickup appointment need `fulfillment_*` (or `pickup_*` / `delivery_*`) instead.

---

### 21.2 Your payment statuses — validated

| Your name | Verdict | Notes / suggested fix |
|-----------|---------|----------------------|
| `payment_pending_square` | ✅ Valid | Customer chose Square, checkout not completed |
| `payment_pending_etransfer` | ✅ Valid | Booking created, e-Transfer not sent yet |
| `payment_deposit_schedule` | ⚠️ Rename | → `payment_deposit_scheduled` (Square short-term: rental paid, deposit auth queued for T-24h) |
| `payment_deposit_schedule_failed` | ⚠️ Rename | → `payment_deposit_scheduled_failed` |
| `payment_deposit_schedule_processed` | ⚠️ Rename | → `payment_deposit_scheduled_processed` (hold active on card) |
| `payment_deposit_schedule_released` | ⚠️ Rename | → `payment_deposit_scheduled_released` (hold cancelled on return/cancel) |
| `payment_book_confirmed` | ❌ Ambiguous | Unclear: rental only? full amount? Use split statuses below |
| `payment_book_failed` | ⚠️ Too vague | → `payment_checkout_failed` (card declined at pay time) |
| `payment_etransfer_confirmed` | ✅ Valid | Admin verified bank received full amount |
| `payment_etransfer_refunded` | ✅ Valid | Full/partial refund on cancel (manual bank send) |
| `payment_etransfer_deposit_returned` | ⚠️ Merge? | Deposit is part of one e-Transfer — consider one `payment_etransfer_refunded` with amount in ledger, not separate deposit status |

**Typos in your list:** `depsosit` → `deposit`, `proccesed` → `processed`, `relesed` → `released`, `refouned` → `refunded`.

---

### 21.3 Payment statuses you're missing

| Missing status | When | Why needed |
|----------------|------|------------|
| `payment_etransfer_sent` | Customer clicked "I have sent" | Between pending and confirmed — admin queue |
| `payment_square_rental_captured` | Square short-term checkout OK | Rental charged, deposit not yet scheduled/processed |
| `payment_square_full_captured` | Square long-term checkout OK | Rental + deposit in one charge (no deposit schedule track) |
| `payment_deposit_scheduled_skipped` | Long-term or zero deposit | Explicit: no T-24h cron path |
| `payment_square_refunded` | Cancel/return via Square API | Mirror e-Transfer refund |
| `payment_square_partial_refunded` | Late cancel fee retained | Rental refunded minus cancellation fee |
| `payment_cancellation_fee_retained` | Late cancel | Fee kept, rest refunded |
| `payment_late_fee_charged` | Overdue return | Separate from booking `late` |
| `payment_disputed` | Chargeback / manual review | Optional but useful |

**Square long-term does not use deposit schedule.** Deposit schedule states apply only to Square short-term (**1–4 days**). Long-term jumps straight to `payment_square_full_captured`.

**Recommended payment flow (simplified):**

```
                    ┌─ payment_pending_square ──► payment_checkout_failed
                    │         │
                    │         ├─ short ──► payment_square_rental_captured
                    │         │              │
                    │         │              ▼
                    │         │         payment_deposit_scheduled
                    │         │              ├─► payment_deposit_scheduled_failed
                    │         │              ├─► payment_deposit_scheduled_processed
                    │         │              └─► payment_deposit_scheduled_released
                    │         │
                    │         └─ long ──► payment_square_full_captured
                    │
                    └─ payment_pending_etransfer
                              │
                              ▼
                         payment_etransfer_sent
                              │
                              ▼
                         payment_etransfer_confirmed
                              │
                    cancel/return ──► payment_etransfer_refunded
```

**Paid gate for fulfillment:** booking may proceed when payment is one of:
- `payment_etransfer_confirmed`
- `payment_square_full_captured`
- `payment_deposit_scheduled_processed`

---

### 21.4 Your booking statuses — validated

| Your name | Verdict | Notes |
|-----------|---------|-------|
| `booking_confirmed` | ✅ Valid | Paid + rental dates reserved; ops can stage/ship |
| `booking_pending` | ⚠️ Too broad | Split into clearer states below |

**Customer should see simple labels; admin sees detail.** Map internal → customer:

| Internal `booking_status` | Customer sees |
|---------------------------|---------------|
| `booking_pending_payment` | Pending |
| `booking_confirmed` | Confirmed |
| `booking_active` | Confirmed (rental in progress) |
| `booking_late` | Confirmed — overdue |
| `booking_cancelled` | Cancelled |
| `booking_closed` | Completed |

---

### 21.5 Booking statuses you're missing

| Missing status | When | Maps from current code |
|----------------|------|------------------------|
| `booking_pending_payment` | Created, not paid | `pending_payment` |
| `booking_active` | Unit with customer, within rental dates | `picked_up`, `shipped` |
| `booking_late` | Past end_date, fees may apply | `late` |
| `booking_cancelled` | Cancelled before or during rental | `cancelled` |
| `booking_closed` | Returned, QC passed, deposit settled | `returned` (today skips QC) |

**`booking_confirmed` vs `booking_active`:**
- `booking_confirmed` = paid, hardware not yet with customer (can still stage/ship)
- `booking_active` = customer has the unit (pickup/delivery/mail received)

You do **not** need separate customer-only status names in the DB — use `booking_status` + a display mapper in the UI.

---

### 21.6 Your shipping statuses — validated

| Your name | Verdict | Notes |
|-----------|---------|-------|
| `shipping_pending` | ✅ Valid | Paid, label/pack not done |
| `shipping_scheduled` | ✅ Valid | Ship date set / label created |
| `shipping_intransit` | ✅ Valid | Carrier has package (outbound) |
| `shipping_received` | ✅ Valid | Customer received kit |
| `shipping_return_intransit` | ✅ Valid | Customer shipped unit back |
| `shipping_return_received` | ✅ Valid | Warehouse received return |

**Only for `fulfillment_type = mail_ship`.**

---

### 21.7 Fulfillment statuses you're missing (non-mail)

Pickup, pickup appointment, and city delivery cannot use `shipping_*`. Use **`fulfillment_status`** (one column, values depend on type):

#### Pickup & city delivery

| Status | Meaning |
|--------|---------|
| `fulfillment_pending` | Paid, not staged yet |
| `fulfillment_staged` | Hardware ready at location | ← **your “staged”** |
| `fulfillment_ready` | Customer can pick up / delivery scheduled |
| `fulfillment_with_customer` | Handed off | ← maps to old `picked_up` |
| `fulfillment_return_pending` | Rental ended, unit not yet back | Customer still has it or return not received |
| `fulfillment_return_received` | Unit physically back at warehouse/store | Admin marked received — **inspection not done** |
| `fulfillment_return_confirmed` | Admin QC pass — equipment working | Closes this booking’s fulfillment track |

#### Pickup appointment (extra appointment track)

Keep **`appointment_status`** separate (or prefix `appointment_*`):

| Status | Meaning |
|--------|---------|
| `appointment_awaiting_admin` | Customer booked, no window yet |
| `appointment_proposed` | Admin proposed time |
| `appointment_confirmed` | Pickup window locked |
| `fulfillment_with_customer` | Kit delivered at appointment |
| `fulfillment_return_pending` | Rental ended, awaiting return |
| `fulfillment_return_received` | Unit back — pending QC |
| `fulfillment_return_confirmed` | QC pass — ready to rent again |

#### Mail ship — use your `shipping_*` as `fulfillment_status`

| fulfillment_status | Same as your |
|---------------------|--------------|
| `shipping_pending` | ✓ |
| `shipping_scheduled` | ✓ |
| `shipping_intransit` | ✓ |
| `shipping_received` | ✓ |
| `shipping_return_intransit` | ✓ |
| `shipping_return_received` | ✓ — unit back, **inspection not done** |
| `fulfillment_return_confirmed` | ✓ — QC pass (all fulfillment types) |

---

### 21.8 Cross-cutting statuses easy to forget

| Concern | Where it lives | Status / flag |
|---------|----------------|---------------|
| **Staged** | Fulfillment | `fulfillment_staged` — not payment, not booking |
| **Return QC** | Fulfillment (ops only) | `fulfillment_return_received` → admin inspects → `fulfillment_return_confirmed` — **does not block calendar** |
| **Late** | Booking (+ payment) | `booking_late` + optional `payment_late_fee_charged` |
| **Cancelled** | Booking (+ payment) | `booking_cancelled` + refund payment status |
| **Deposit failed** | Payment | `payment_deposit_scheduled_failed` — blocks fulfillment |
| **Admin must not release** | Derived rule | `payment_status` not in paid set OR `payment_deposit_scheduled_failed` |
| **Equipment assignment** | Separate or derived | `equipment_id` + equipment.status `with_customer` |
| **Long-term request** | Pre-booking | Not a booking status — stays in `long_term_requests` table |

---

### 21.9 Complete recommended status sets

#### Payment (`payment_status`) — 16 states

```
payment_pending_square
payment_pending_etransfer
payment_etransfer_sent
payment_checkout_failed
payment_square_rental_captured          # short-term step 1
payment_square_full_captured            # long-term one-shot
payment_deposit_scheduled
payment_deposit_scheduled_failed
payment_deposit_scheduled_processed
payment_deposit_scheduled_released
payment_etransfer_confirmed
payment_etransfer_refunded
payment_square_refunded
payment_square_partial_refunded
payment_cancellation_fee_retained
payment_late_fee_charged
```

#### Booking (`booking_status`) — 7 states

```
booking_pending_payment
booking_confirmed                       # paid, pre-handoff
booking_active                          # unit with customer
booking_late                            # overdue
booking_cancelled
booking_closed                          # after fulfillment_return_confirmed + deposit release
```

#### Fulfillment (`fulfillment_status`) — 14 states

```
fulfillment_pending
fulfillment_staged
fulfillment_ready
fulfillment_with_customer
fulfillment_return_pending
fulfillment_return_received          # physically back, QC not done
fulfillment_return_confirmed           # admin confirmed equipment OK — can rent again

# mail_ship only:
shipping_pending
shipping_scheduled
shipping_intransit
shipping_received
shipping_return_intransit
shipping_return_received               # physically back, QC not done
# then → fulfillment_return_confirmed (same QC step as pickup)
```

**Return close sequence (all fulfillment types):**

```
fulfillment_with_customer
        │
        ▼ (rental end / admin receives unit)
fulfillment_return_pending  ──►  fulfillment_return_received  (or shipping_return_received for mail)
        │
        ▼ (admin runs QC checklist)
fulfillment_return_confirmed
        │
        ▼ (deposit released, booking closed)
booking_closed
payment_deposit_scheduled_released   (Square short) OR deposit refund recorded (long / e-Transfer)
```

#### Appointment (`appointment_status`) — 4 states (pickup_appointment only)

```
appointment_na
appointment_awaiting_admin
appointment_proposed
appointment_confirmed
```

---

### 21.10 Example: RJM6BU pickup (3-day rental, Square short)

| Step | payment_status | booking_status | fulfillment_status |
|------|----------------|----------------|-------------------|
| Checkout submitted | `payment_pending_square` | `booking_pending_payment` | `fulfillment_pending` |
| Card paid | `payment_square_rental_captured` | `booking_confirmed` | `fulfillment_pending` |
| T-24h deposit OK | `payment_deposit_scheduled_processed` | `booking_confirmed` | `fulfillment_pending` |
| Admin stages | (unchanged) | `booking_confirmed` | `fulfillment_staged` |
| Customer picks up | (unchanged) | `booking_active` | `fulfillment_with_customer` |
| Rental ends, unit returned | (unchanged) | `booking_active` | `fulfillment_return_received` |
| Admin QC pass | deposit release | `booking_closed` | `fulfillment_return_confirmed` |
| Customer cancels ❌ | Should refund + `payment_square_refunded` | `booking_cancelled` | `fulfillment_pending` + release inventory |

Today cancel only flips one field to `cancelled` — your model makes it obvious that payment and fulfillment must update too.

---

### 21.11 Validation summary

| Area | Your count | Verdict |
|------|------------|---------|
| Payment | 11 proposed | **8 good**, 3 ambiguous — add 6 missing, rename 4 typos |
| Booking | 2 proposed | **Too few** — need at least 7 including `late`, `cancelled`, `closed`, `active` |
| Shipping | 6 proposed | **Good for mail only** — rename column to `fulfillment_status` and add pickup/delivery states |
| Staged | — | **Missing** → `fulfillment_staged` |
| Late | — | **Missing** → `booking_late` + `payment_late_fee_charged` |
| Cancelled | — | **Missing** → `booking_cancelled` + refund payment states |
| Appointment | — | **Missing** → keep `appointment_*` track for pickup appointment |

**Bottom line:** Your payment + shipping lists cover the happy path well. Add e-Transfer “sent”, Square long-term vs short-term split, refund/fee states, and expand booking + fulfillment so pickup and pickup appointment are first-class — not forced into `shipping_*`.

---

## 22. Return Inspection & Square Short-Term Limits

### 22.1 Why `fulfillment_return_confirmed` exists

Return is tracked in **three fulfillment steps** for admin workflow and payment release — **not** for blocking the customer calendar.

| Status | Meaning | Blocks customer calendar? |
|--------|---------|---------------------------|
| `fulfillment_return_pending` | Rental ended, unit not yet back | **No** |
| `fulfillment_return_received` | Unit physically back, QC not done | **No** |
| `fulfillment_return_confirmed` | Admin confirmed equipment OK | **No** |
| `booking_closed` | Rental complete, deposit handled | **No** |

**Admin action:** “Confirm equipment OK” moves `fulfillment_return_received` → `fulfillment_return_confirmed`, then release deposit and set `booking_closed`.

QC statuses are **internal ops tracking only**. A customer booking a unit today for 3 days must **not** make that unit unavailable for bookings a month later (or any non-overlapping dates).

**QC failure path (v1):** `fulfillment_return_failed` — admin triggers damage charge from deposit; customer notified. Does not block unrelated future calendar dates.

### 22.2 Equipment inspection checklist (admin, before `fulfillment_return_confirmed`)

Minimum checks before closing the booking:

- Powers on, connects to network
- No physical damage (dish, cable, router)
- All accessories/add-ons accounted for
- Serial matches booking record

Inspection result is recorded on the **booking** (`fulfillment_return_confirmed`). Equipment stays in the pool — no global “unit frozen until QC” flag that hides future dates.

### 22.3 Square short-term — max rental length (checkout rule only)

Square short-term uses **deposit authorization 1 day before rental start** (T-24h cron). Operationally you also want **1 day after return** to inspect before re-renting the same physical unit — but that is **staff scheduling**, not calendar blocking.

**Checkout validation (Square short-term only):**

```
IF payment_method = square AND rental_days <= 4:
    use Square short-term payment flow (rental + deposit T-1)
ELSE IF payment_method = square AND rental_days >= 5:
    use Square long-term payment flow (full capture)
```

Hard cap: **maximum 4 rental days** for Square short-term checkout.

| Setting | Default | Purpose |
|---------|---------|---------|
| `square_short_max_rental_days` | 4 | Max rental days for Square short-term checkout |
| `square_long_term_min_days` | 5 | Min rental days for Square long-term checkout |
| `deposit_lead_days` | 1 | When cron runs deposit auth (T-1 before start) |
| `post_return_inspection_days` | 1 | Ops scheduling only — **not** on calendar |
| `staging_lead_days` | 2 | Same-city: earliest start days from today |
| `inter_city_staging_lead_days` | 7 | Other city (Red Deer, Calgary): earliest start days |

**What this does NOT do:** extend blocked dates on the calendar before `start_date` or after `end_date`.

### 22.4 Payment + fulfillment at return (Square short-term)

Deposit hold releases **after** `fulfillment_return_confirmed` (not when unit is merely received):

| Step | fulfillment_status | payment_status |
|------|-------------------|----------------|
| Unit received | `fulfillment_return_received` | `payment_deposit_scheduled_processed` (hold still active) |
| QC pass | `fulfillment_return_confirmed` | → `payment_deposit_scheduled_released` |
| Booking done | `booking_closed` | deposit settled |

If QC fails: hold may convert to damage charge — separate flow.

### 22.5 Summary

| Item | Decision |
|------|----------|
| `fulfillment_return_confirmed` | ✅ Admin QC pass — closes fulfillment, triggers deposit release |
| QC statuses block calendar | ❌ **No** — ops tracking only |
| Square short-term max rental | **4 days** at checkout (payment-method rule) |
| Calendar extra buffers (T-1, T+1) | ❌ **No** — do not block dates outside rental range |
| Unit conflicts | Admin resolves at assignment / handoff time (see §23) |

---

## 23. Availability & Unit Assignment Philosophy

### 23.1 Core rules (locked — see §27)

| Rule | Behaviour |
|------|-----------|
| **Hard reject** | Checkout fails if zero units can serve the date range |
| **No overlap** | Calendar shows dates unavailable when every unit is booked or blocked for that range |
| **No extra buffers on calendar** | Only rental dates + staging lead + unit-out-with-customer block availability — not QC/deposit days |
| **Single pool** | With one unit, the calendar reflects that one unit’s real availability |

Customer-facing question: “Is **a** kit available for these dates at this location?” → **No** if no unit can fulfill; dates greyed out on calendar.

### 23.2 Earliest start — staging lead (configurable, already in admin)

When the unit is **not already at the pickup site**, earliest bookable start = today + lead days:

| Situation | Config key | Your target default | Meaning |
|-----------|------------|---------------------|---------|
| Unit at **home** location (`location_type = home`) | *(special rule)* | **+1 day** | Customer can book starting **tomorrow** |
| Unit in **same city** as pickup (e.g. Edmonton → Edmonton South) | `staging_lead_days` | **2 days** | 1 day to move unit to store + buffer |
| Unit in **another city** (e.g. Red Deer, Calgary) | `inter_city_staging_lead_days` | **7 days** | Inter-city transfer time |

Already editable in **Admin → Staging** (`staging_lead_days`, `inter_city_staging_lead_days`). Set defaults to **2** and **7** on migrate if not already.

Unit **with customer** (`equipment.status = with_customer`): not in available pool until returned — overlapping rental dates block the calendar (hard).

Unit **already at pickup site** (`at_pickup_site = 1` at that location): lead = 0 — can start today if not booked.

### 23.3 Assignment model

```
Customer picks dates + location + fulfillment type
        │
        ▼
AvailabilityService: any unit free for range? ──NO──► calendar unavailable / checkout rejected
        │
       YES
        ▼
Assign equipment_id at booking (best unit)
        │
        ▼
Payment → booking_confirmed
        │
        ▼
Admin stages / ships / delivers
        │
        ├── Assigned unit ready? → use it
        │
        └── Unit not ready (QC fail, damage)?
              → admin assigns different unit if one exists
              → if none → contact customer (delay / cancel / refund)
```

**No overlapping bookings** on the same unit for the same dates. Admin may still **swap** which serial goes to a booking before handoff if ops require it.

### 23.4 Cancel unpaid booking

Customer **may cancel** while `booking_pending_payment` → **release equipment assignment immediately** (§27 #3, #14).

### 23.5 Deposit cron (T-1)

Deposit authorization runs **1 day before `start_date`** for Square short-term. Payment cron only — **does not** add an extra blocked day on the customer calendar beyond staging lead rules.

---

## 24. Final Spec — Locked Status Enums

Three columns on `bookings` (+ `appointment_status` for pickup appointment):

### 24.1 `payment_status` (16 values)

| Value | Set when |
|-------|----------|
| `payment_pending_square` | Booking created, Square selected, not paid |
| `payment_pending_etransfer` | Booking created, e-Transfer selected, not paid |
| `payment_etransfer_sent` | Customer clicked “I have sent” |
| `payment_checkout_failed` | Square card declined |
| `payment_square_rental_captured` | Short-term: rental charged, deposit pending |
| `payment_square_full_captured` | Long-term: rental + deposit charged |
| `payment_deposit_scheduled` | Short-term: rental paid, deposit cron queued |
| `payment_deposit_scheduled_failed` | Deposit auth failed |
| `payment_deposit_scheduled_processed` | Deposit hold active |
| `payment_deposit_scheduled_released` | Hold cancelled / released on close |
| `payment_etransfer_confirmed` | Admin confirmed full e-Transfer received |
| `payment_etransfer_partial_confirmed` | Admin confirmed partial amount; underpaid or overpaid tracked |
| `payment_etransfer_refunded` | e-Transfer refund recorded / sent |
| `payment_square_refunded` | Square full refund |
| `payment_square_partial_refunded` | Square refund minus cancellation fee |
| `payment_cancellation_fee_retained` | Late cancel fee kept |
| `payment_late_fee_charged` | Overdue daily fee applied |

**Paid gate (may hand out hardware):**  
`payment_etransfer_confirmed` OR `payment_square_full_captured` OR `payment_deposit_scheduled_processed`

### 24.2 `booking_status` (7 values)

| Value | Customer label | Meaning |
|-------|----------------|---------|
| `booking_pending_payment` | Pending | Awaiting payment |
| `booking_confirmed` | Confirmed | Paid, not yet with customer |
| `booking_active` | Confirmed | Customer has unit |
| `booking_late` | Confirmed | Past end date, overdue |
| `booking_cancelled` | Cancelled | Closed — cancelled |
| `booking_closed` | Completed | Returned, QC done, deposit settled |

### 24.3 `fulfillment_status` (14 values)

| Value | Applies to |
|-------|------------|
| `fulfillment_pending` | All — paid, prep not started |
| `fulfillment_staged` | All — hardware ready |
| `fulfillment_ready` | Pickup/delivery — ready for handoff |
| `fulfillment_with_customer` | All — unit out |
| `fulfillment_return_pending` | All — awaiting physical return |
| `fulfillment_return_received` | All — unit back, QC pending |
| `fulfillment_return_confirmed` | All — QC pass |
| `fulfillment_return_failed` | All — QC fail; admin triggers damage charge from deposit |
| `shipping_pending` | mail_ship |
| `shipping_scheduled` | mail_ship |
| `shipping_intransit` | mail_ship |
| `shipping_received` | mail_ship — customer has kit |
| `shipping_return_intransit` | mail_ship — return shipment |
| `shipping_return_received` | mail_ship — unit back, QC pending |

Mail return path: `shipping_return_received` → `fulfillment_return_confirmed` → `booking_closed`

### 24.4 `appointment_status` (4 values, `pickup_appointment` only)

| Value | Meaning |
|-------|---------|
| `appointment_na` | Not a pickup appointment |
| `appointment_awaiting_admin` | Waiting for admin to set window |
| `appointment_proposed` | Admin proposed, customer must accept |
| `appointment_confirmed` | Pickup window locked |

### 24.5 Migration from current single `status`

| Old `status` | New mapping |
|--------------|-------------|
| `pending_payment` | `booking_pending_payment` + payment pending variant |
| `booked_deposit_pending` | `booking_confirmed` + `payment_deposit_scheduled` |
| `deposit_failed` | `booking_confirmed` + `payment_deposit_scheduled_failed` |
| `ready_for_pickup` | `booking_confirmed` + `payment_deposit_scheduled_processed` OR `payment_square_full_captured` |
| `confirmed` | `booking_confirmed` + `payment_etransfer_confirmed` |
| `staged` | `booking_confirmed` + `fulfillment_staged` |
| `picked_up` | `booking_active` + `fulfillment_with_customer` |
| `shipped` | `booking_active` + `shipping_received` or `fulfillment_with_customer` |
| `late` | `booking_late` + `fulfillment_with_customer` |
| `returned` | `booking_closed` + `fulfillment_return_confirmed` |
| `cancelled` | `booking_cancelled` + appropriate payment refund status |

**Fulfillment type rename:**

| Old `fulfillment_type` | New `fulfillment_type` |
|------------------------|------------------------|
| `store_pickup` | `pickup` |
| `home_appointment` | `pickup_appointment` |

---

## 25. Implementation Checklist (fix codebase from this doc)

Use this order when rewriting `website/`:

### Phase 1 — Schema & enums
- [ ] Add columns: `payment_status`, `booking_status`, `fulfillment_status` (keep old `status` during migration)
- [ ] Rename `fulfillment_type`: `store_pickup` → `pickup`, `home_appointment` → `pickup_appointment` (§3)
- [ ] Migration script: map existing rows using §24.5
- [ ] PHP enums/constants for all status values
- [ ] Config: `square_short_max_rental_days` (4), `square_long_term_min_days` (5), `deposit_lead_days`, `post_return_inspection_days`; staging defaults **2** / **7**

### Phase 2 — Payment flows
- [ ] e-Transfer: `pending` → `sent` → admin confirm → `confirmed`; notify admin on `sent`
- [ ] Square short: rental capture → `deposit_scheduled` → cron T-1 → `processed` / `failed`
- [ ] Square long: full capture → `payment_square_full_captured`
- [ ] Checkout: enforce max **4 rental days** for Square short-term only
- [ ] Fix Square webhook to respect short/long deposit flow (§27 #13)
- [ ] `cancellation_reason` on admin cancel (required when e-Transfer not received)
- [ ] e-Transfer partial confirm flow (§27 #15)
- [ ] `fulfillment_return_failed` + admin damage charge from deposit (§27 #9)
- [ ] Deposit release only on `fulfillment_return_confirmed`

### Phase 3 — Booking & cancel
- [ ] Block **customer** cancel after `fulfillment_with_customer` (§27 #4)
- [ ] Allow customer cancel on `booking_pending_payment`; release equipment immediately (§27 #3, #14)
- [ ] Customer labels from `booking_status` only (simple UI)

### Phase 4 — Fulfillment & QC
- [ ] Admin actions: Stage, Pickup/Ship, Return received, **Confirm equipment OK**
- [ ] `fulfillment_return_confirmed` triggers `booking_closed` + deposit release
- [ ] Allow admin to change `equipment_id` at stage/handoff
- [ ] Do **not** tie QC status to calendar availability

### Phase 5 — Availability
- [ ] Hard reject checkout when zero units (§27 #6–#7)
- [ ] No overlapping bookings on same unit — calendar reflects real availability
- [ ] Staging lead: home +1 day, same-city (`staging_lead_days` = 2), inter-city (`inter_city_staging_lead_days` = 7)
- [ ] Do **not** add QC/deposit buffer days to calendar

### Phase 6 — Notifications
- [ ] `notification_rules` table + admin settings UI (§26)
- [ ] Per-event toggles: customer email / admin email / admin Telegram
- [ ] `NotificationService` reads rules before send; log-only until SMTP + Telegram wired
- [ ] Default rules seeded on migrate (§26.4)
- [ ] Admin global config: `MAIL_FROM`, single `ADMIN_EMAIL`, single `TELEGRAM_ADMIN_CHAT_ID`
- [ ] Message templates in **DB** (editable in admin) — §27 #17

### Phase 7 — UI
- [ ] Customer bookings: simple status + actions per `booking_status` / `payment_status`
- [ ] Admin bookings: three status columns + payment summary + QC actions
- [ ] Filter admin list by `booking_status` and `payment_status`

### Phase 8 — Cleanup
- [ ] Drop old single `status` column after migration verified
- [ ] Remove dead `appointment_pending` label
- [ ] Verify webhook path does not bypass deposit flow
- [ ] Verify Square webhook signature

### Phase 9 — External integrations (later)
- [ ] SMTP or transactional email (SendGrid, SES, etc.) — replace log-only customer/admin email
- [ ] Telegram Bot API — admin channel from §26.3
- [ ] Optional: customer SMS for critical events (not in v1)

---

## 26. Notification Rules — Admin Panel (email & Telegram)

Each **status transition** (event) can independently notify:

| Channel | Recipient | Integration today | Integration later |
|---------|-----------|-------------------|-------------------|
| **Customer email** | Booking customer | Log to `notification_log` | SMTP / SendGrid / SES |
| **Admin email** | `payments.admin_email` or configurable list | Log to `notification_log` | Same SMTP |
| **Admin Telegram** | Configured chat ID / group | Log only (mark `channel=telegram`) | Telegram Bot API |

**Design rule:** Code always calls `NotificationService::dispatch(event, booking)`. The service looks up admin-configured rules and sends (or logs) per channel. Hard-coded notify calls are removed.

### 26.1 Admin panel UI

**Location:** Admin → Settings → **Notification rules**

For each event row, three checkboxes:

```
Event                          │ Customer email │ Admin email │ Admin Telegram
───────────────────────────────┼────────────────┼─────────────┼─────────────────
Booking created (unpaid)       │      ☐         │     ☑       │       ☐
e-Transfer marked sent         │      ☐         │     ☑       │       ☑
e-Transfer confirmed           │      ☑         │     ☐       │       ☐
Square rental captured         │      ☑         │     ☐       │       ☐
Deposit auth failed            │      ☑         │     ☑       │       ☑
…                              │                │             │
```

**Behaviours:**
- Admin saves rules → `notification_rules` table (no deploy needed)
- If all channels off for an event → no-op (still log transition in booking audit if desired)
- Customer email uses booking’s `customer_email`; never send if empty
- Admin Telegram requires `TELEGRAM_BOT_TOKEN` + `TELEGRAM_ADMIN_CHAT_ID` in env (when integrated)
- Test button per channel: “Send test to admin email / Telegram”

### 26.2 Event catalog (one row per transition)

Events are keyed by **`event_key`** (stable string), not display label. Grouped by axis:

#### Booking / lifecycle

| event_key | Trigger | Suggested customer email | Suggested admin email | Suggested Telegram |
|-----------|---------|--------------------------|----------------------|-------------------|
| `booking_created` | Customer completes checkout | ☐ | ☑ | ☐ |
| `booking_admin_created` | Admin creates booking for customer | ☑ | ☐ | ☐ |
| `booking_cancelled_by_customer` | Customer cancels | ☑ | ☑ | ☑ |
| `booking_cancelled_by_admin` | Admin cancels | ☑ | ☐ | ☐ |
| `booking_closed` | QC pass + deposit settled | ☑ | ☐ | ☐ |

#### Payment

| event_key | Trigger | Customer | Admin email | Telegram |
|-----------|---------|----------|-------------|----------|
| `payment_checkout_failed` | Square card declined | ☑ | ☐ | ☐ |
| `payment_etransfer_sent` | Customer clicked “I have sent” | ☐ | ☑ | ☑ |
| `payment_etransfer_confirmed` | Admin confirmed e-Transfer | ☑ | ☐ | ☐ |
| `payment_square_rental_captured` | Short-term rental charged | ☑ | ☐ | ☐ |
| `payment_square_full_captured` | Long-term full payment | ☑ | ☐ | ☐ |
| `payment_deposit_scheduled` | Rental paid, deposit queued | ☐ | ☐ | ☐ |
| `payment_deposit_scheduled_processed` | Deposit hold OK | ☑ | ☐ | ☐ |
| `payment_deposit_scheduled_failed` | Deposit auth failed | ☑ | ☑ | ☑ |
| `payment_deposit_scheduled_released` | Deposit released on close | ☐ | ☐ | ☐ |
| `payment_refunded` | Cancel/return refund issued | ☑ | ☐ | ☐ |
| `payment_cancellation_fee_retained` | Late cancel fee kept | ☑ | ☐ | ☐ |
| `payment_late_fee_charged` | Overdue fee applied | ☑ | ☑ | ☐ |

#### Fulfillment

| event_key | Trigger | Customer | Admin email | Telegram |
|-----------|---------|----------|-------------|----------|
| `fulfillment_staged` | Admin staged hardware | ☐ | ☐ | ☐ |
| `fulfillment_ready` | Ready for pickup / delivery scheduled | ☑ | ☐ | ☐ |
| `fulfillment_with_customer` | Picked up / delivered / mail received | ☑ | ☐ | ☐ |
| `fulfillment_shipped` | Mail outbound dispatched | ☑ | ☐ | ☐ |
| `fulfillment_return_pending` | Rental ended, awaiting return | ☐ | ☑ | ☐ |
| `fulfillment_return_received` | Unit back, QC pending | ☐ | ☑ | ☐ |
| `fulfillment_return_confirmed` | QC pass | ☐ | ☐ | ☐ |
| `fulfillment_return_failed` | QC fail / damage | ☐ | ☑ | ☑ |

#### Appointment (`pickup_appointment`)

| event_key | Trigger | Customer | Admin email | Telegram |
|-----------|---------|----------|-------------|----------|
| `appointment_awaiting_admin` | Home booking created | ☐ | ☑ | ☑ |
| `appointment_proposed` | Admin proposed window | ☑ | ☐ | ☐ |
| `appointment_confirmed` | Window confirmed / accepted | ☑ | ☐ | ☐ |

**Suggested defaults** (☑ above) are starting values seeded on install; admin overrides in panel.

### 26.3 Database schema (future-proof)

```sql
CREATE TABLE notification_rules (
    event_key              TEXT PRIMARY KEY,
    label                  TEXT NOT NULL,
    notify_customer_email  INTEGER NOT NULL DEFAULT 0,
    notify_admin_email     INTEGER NOT NULL DEFAULT 0,
    notify_admin_telegram  INTEGER NOT NULL DEFAULT 0,
    updated_at             TEXT NOT NULL
);

CREATE TABLE notification_log (
    -- existing columns +
    channel                TEXT NOT NULL DEFAULT 'email',  -- email | telegram
    event_key              TEXT,
    delivery_status        TEXT NOT NULL DEFAULT 'logged', -- logged | sent | failed
    error_message          TEXT
);
```

**Global config (env / admin settings page, not per-event):**

| Key | Purpose |
|-----|---------|
| `MAIL_FROM` | From address for customer email |
| `ADMIN_EMAIL` | **Single** admin email recipient |
| `TELEGRAM_BOT_TOKEN` | Bot token (when integrated) |
| `TELEGRAM_ADMIN_CHAT_ID` | **Single** admin chat/group for alerts |

**Templates:** stored in DB table `notification_templates` (`event_key`, `subject`, `body`, `channel`) — editable in admin (§27 #17).

### 26.4 Service interface (implement now, wire later)

```php
// NotificationService — called from booking/payment/fulfillment code
public function dispatch(string $eventKey, array $booking, array $context = []): void
{
    $rules = $this->rules->forEvent($eventKey);
    $template = $this->templates->render($eventKey, $booking, $context);

    if ($rules->notify_customer_email) {
        $this->sendCustomerEmail($booking, $template);
    }
    if ($rules->notify_admin_email) {
        $this->sendAdminEmail($template);
    }
    if ($rules->notify_admin_telegram) {
        $this->sendAdminTelegram($template);
    }
}

// Today: all channels write to notification_log with delivery_status = 'logged'
// Later: swap send* methods to SMTP / Telegram HTTP API
```

**Template per event:** subject + body with placeholders `{reference}`, `{customer_name}`, `{start_date}`, `{amount}`, `{action_url}`.

### 26.5 What not to notify

- Internal status changes with no customer/admin meaning (e.g. `payment_deposit_scheduled` cron queue entry) — default all off unless admin enables
- Duplicate events: if `payment_square_full_captured` and `booking_confirmed` fire together, prefer **one** customer email (config: suppress duplicate within N minutes)

### 26.6 Implementation checklist (notifications)

- [ ] Migration: `notification_rules` + extend `notification_log`
- [ ] Seed all `event_key` rows from §26.2 with suggested defaults
- [ ] Admin UI: Settings → Notification rules (checkbox grid)
- [ ] Refactor all `NotificationService::bookingConfirmed()` etc. → `dispatch(event_key, ...)`
- [ ] Message templates in DB table `notification_templates` (editable in admin)
- [ ] Log-only transport (current behaviour) respects rules toggles
- [ ] Phase 9: plug in real email + Telegram transports without changing call sites

---

## 27. Decisions Locked

All open questions resolved **2026-05-24**. Implement from this section when §24/§25 conflict with older sections (§1–§20 describe legacy code).

| # | Topic | Decision |
|---|-------|----------|
| **1** | Square “short term” | **Max 4 rental days** at checkout. Square short flow = 1–4 days (rental charge + deposit T-1). Not the old 6/7-day split. |
| **2** | Square short max rental | **Yes — 4 days hard cap** for Square short-term checkout. |
| **3** | Cancel while unpaid | **Allow.** Customer may cancel `booking_pending_payment`. |
| **4** | Cancel after handoff | **No.** Customer cannot cancel once `fulfillment_with_customer` / `booking_active`. Admin only. |
| **5** | e-Transfer not received | Admin **cancels with reason** (required text, e.g. “Payment not received”). Customer notified. |
| **6** | Availability (1 unit today) | **Hard reject** if zero units. Calendar unavailable when no unit can serve. Staging leads: unit at **home** → earliest **+1 day**; **same city** → `staging_lead_days` **2**; **Red Deer / Calgary** → `inter_city_staging_lead_days` **7** (configurable in admin). |
| **7** | Overlapping bookings | **No overlap.** Same unit cannot double-book; calendar reflects this. |
| **8** | Self-serve 5–30 days | **Same checkout as today** (Square long-term full capture). 31+ → long-term request. |
| **9** | QC failure | **v1 now.** `fulfillment_return_failed`. Admin triggers **damage charge from deposit**. Customer notified. |
| **10** | Pickup appointment + payment | **Parallel** — appointment scheduling does not block payment. |
| **11** | Appointment null value | Standardize on **`appointment_na`** (replace `n/a`). |
| **12** | Return QC | **One unified admin action** for all fulfillment types → `fulfillment_return_confirmed`. |
| **13** | Square webhook | **Fix** to respect short/long deposit flow (do not bypass with plain `markPaid`). |
| **14** | Cancel unpaid + equipment | **Release `equipment_id` immediately** on cancel while pending payment. |
| **15** | e-Transfer partial amount | Admin **partial-confirm** → notify customer. **Underpaid:** ask for rest. **Overpaid:** refund excess at deposit return/close. |
| **16** | Admin notification targets | **One** admin email + **one** Telegram chat (env config). |
| **17** | Notification templates | Store in **DB**, editable in admin (`notification_templates` table). |

### 27.1 New fields / statuses from these decisions

| Item | Detail |
|------|--------|
| `bookings.cancellation_reason` | Required when admin cancels for payment not received (optional for other admin cancels) |
| `payment_etransfer_partial_confirmed` | Partial e-Transfer accepted; tracks amount vs `total_due` |
| `fulfillment_return_failed` | QC failed; triggers damage charge workflow |
| `appointment_na` | Replaces `n/a` in `appointment_status` |

### 27.2 QC failure flow (v1)

```
fulfillment_return_received
        │
        ├── Admin: Confirm equipment OK → fulfillment_return_confirmed → deposit release → booking_closed
        │
        └── Admin: QC failed → fulfillment_return_failed
                    → capture damage from deposit (Square hold or e-Transfer ledger)
                    → notify customer (event: fulfillment_return_failed)
                    → admin resolves remainder manually
```

### 27.3 e-Transfer partial confirm flow

```
Customer sends e-Transfer (any amount)
        │
        ▼
Admin reviews bank amount
        │
        ├── Exact → payment_etransfer_confirmed → booking_confirmed
        │
        ├── Under → payment_etransfer_partial_confirmed → notify customer to pay rest
        │           booking stays booking_pending_payment until topped up or cancelled
        │
        └── Over → payment_etransfer_confirmed (or partial flag + overpaid_cents)
                    → excess refunded at booking_closed / deposit return
```

### 27.4 Square payment flow split (final)

| Rental days | Payment method | Flow |
|-------------|----------------|------|
| 1–4 | Square | Short: rental charged, deposit T-1, max 4 days |
| 5–30 | Square | Long: rental + deposit upfront |
| Any | e-Transfer | Full amount expected; partial confirm allowed |
| 31+ | Any | Long-term request → admin booking |

### 27.5 Doc authority

| Sections | Content |
|----------|---------|
| §1–§20 | Legacy behaviour + bugs (reference only) |
| §21–§27 | **Target spec — build this** |

**No remaining open product questions.** Ready for Phase 1 implementation.

---

*Last updated: 2026-05-24 — all decisions locked in §27.*
