# Square deposit holds — dashboard visibility (deferred)

**Status:** Parked — revisit later  
**Saved:** 2026-05-25  
**Example booking:** DMGBCV (#98)

## Problem

After **Authorize deposit hold** ($350), the hold appears in our admin/API logs but is **hard or impossible to find** in the Square **sandbox** dashboard — including under the linked customer profile.

Rental charge ($105, **COMPLETED**) is easier to find; deposit hold (**APPROVED** / card **AUTHORIZED**) is not shown in the usual Transactions / customer views.

## What we confirmed (API, same credentials as dev site)

| Field | Rental | Deposit hold |
|--------|--------|----------------|
| Square payment ID | `tEp3L26n1ZBCHgboUeeIPtU9IKUZY` | `Pttz787Gq0koTUEhp2vHrEP7x5fZY` |
| Amount | $105 CAD | $350 CAD |
| API `status` | `COMPLETED` | `APPROVED` |
| Card details | `CAPTURED` | `AUTHORIZED` |
| Note | `booking:98 rental` | `booking:98 deposit_hold` |
| Location | `L3KD2P3A4Q4C9` (Default Test Account) | same |
| Customer ID on payment | none | `RR82NRXXP2E1CSTEQ364J38G3G` (test123@xuebao.ca) |

- `GET /v2/payments` lists both at location `L3KD2P3A4Q4C9`.
- App uses **sandbox** (`connect.squareupsandbox.com`, app id `sandbox-sq0idb-…`).
- Our DB: `payment_deposit_scheduled_processed`, ledger deposit row `pending`, `deposit_auth_at` set.

## Working hypothesis (not fully verified in UI)

1. **Wrong dashboard** — sandbox vs production (`squareupsandbox.com` vs `squareup.com`).
2. **Hold vs sale** — authorizations may only appear under **Reports → Payments → Transaction status** (or Transactions filtered to Authorized), not in default completed sales or customer history.
3. **Customer profile gap** — Square may not list **APPROVED** holds on the customer record.
4. **Product UX** — admins expect deposit holds to look like the $105 rental in Payments & Invoices; Square treats them differently.

## Code context (already shipped)

- Short-term flow: rental captured at checkout; deposit = `autocomplete: false` hold via card on file + `customer_id` (fixed May 2025).
- Admin: Authorize hold / Charge now / Capture hold.
- Per-row Square verify in transaction history with persistent checkmark.

## When we pick this up

- [ ] Reproduce in **sandbox dashboard** with exact navigation (document screenshots/steps).
- [ ] Confirm whether **production** behaves the same for holds.
- [ ] Decide if we need admin UI: link to Square payment, show `APPROVED` vs `COMPLETED`, or “hold not visible in default Transactions”.
- [ ] Confirm Square delay/cancel window (`delay_action: CANCEL`, `delayed_until` on hold) vs rental return / extension flows.
- [ ] Optional: Square support doc on where API `APPROVED` payments appear in dashboard.

## Quick re-check commands (local)

```bash
# List recent payments at configured location
php -r "
require 'website/bootstrap.php';
\$c = (require 'website/config/app.php')['square'];
\$ch = curl_init(rtrim(\$c['api_base'],'/').'/v2/payments?location_id='.urlencode(\$c['location_id']).'&limit=5');
curl_setopt_array(\$ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_HTTPHEADER=>['Authorization: Bearer '.\$c['access_token'],'Square-Version: 2024-10-17']]);
echo curl_exec(\$ch);
"
```

Search sandbox dashboard for payment ID: `Pttz787Gq0koTUEhp2vHrEP7x5fZY`.
