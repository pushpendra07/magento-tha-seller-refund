# Acme_SellerRefund

Seller return and refund workflow for Magento Open Source 2.4.7-p10.

Customer Support issues a full or partial refund against a delivered seller order. The module
registers it with the ERP as a pending credit note. Finance pays the customer offline, the
operator records the bank transfer, and the module confirms the settlement back to the ERP.
The refunded amounts are then shown to the customer.

| | |
|---|---|
| Package | `acme/module-seller-refund` |
| Requires | PHP 8.3, `Magento_Sales`, `Magento_Catalog`, `Magento_Eav`, `Magento_Email` |
| Database | `mp_refund`, `mp_refund_item`, `mp_refund_event`, `mp_refund_outbox`, `mp_refund_sequence`; columns on `sales_order` and `sales_order_item` |
| Specification | `docs/candidate/return-refund-frd.md` (the FRD; section numbers below refer to it) |

## Installation

```bash
bin/magento module:enable Acme_SellerRefund
bin/magento setup:upgrade
bin/magento setup:di:compile                         # production mode
bin/magento setup:static-content:deploy en_US        # production mode
bin/magento cache:flush
```

The module adds these order attributes, which the seller order import must fill:

| Column | Meaning |
|---|---|
| `sales_order.mp_seller_order_id` | Seller order ID, sent to the ERP as `seller_order_id` |
| `sales_order.mp_delivered_at` | Delivery date; the refund window starts here (BR-01, BR-02) |
| `sales_order_item.mp_seller_code` | Marks the line as a seller item |
| `sales_order_item.mp_tax_class` | Business tax code `999` / `010` / `008` (FRD 11) |

## Database

Declared in `etc/db_schema.xml` (declarative schema) with `etc/db_schema_whitelist.json`.

| Table | Holds | Main indexes |
|---|---|---|
| `mp_refund` | One row per refund: status, sub-statuses, money snapshot, ERP id, bank transfer | unique `refund_no`; `order_id`; `status, created_at` |
| `mp_refund_item` | Refunded lines (snapshot, never edited) | `refund_id`; `order_item_id` |
| `mp_refund_event` | Append-only audit log with redacted request/response | `refund_id, created_at` |
| `mp_refund_outbox` | Queued ERP calls (`pending`, `done`, `dead`, `cancelled`) | `state, available_at`; `refund_id, operation, state` |
| `mp_refund_sequence` | Daily counter behind `SR-YYYYMMDD-NNNNNN` | `date_key` |

`Setup/Patch/Schema/DropImplicitOutboxRefundIndex` removes an older, undeclared `refund_id`
index that MySQL/MariaDB created for the outbox foreign key on installs from before
`MP_REFUND_OUTBOX_REFUND_ID_OPERATION_STATE` was declared. It does nothing on a fresh install.

## Configuration

**Stores > Configuration > Seller Refund > Seller Refund**

| Group | Setting | Default | Scope |
|---|---|---|---|
| General | Refund Window (Days) | 14 | store view |
| ERP Refund API | Base URL | `http://erp-refund-stub:8081` | global |
| | Connect Timeout (Seconds) | 2 | global |
| | Response Timeout (Seconds) | 3 | global |
| | Max Attempts | 8 | global |
| | Retry Backoff Base (Seconds) | 5 | global |
| | Retry Backoff Cap (Seconds) | 300 | global |
| Refund Confirmation Email | Email Template | Seller Refund Confirmed | store view |
| | Email Sender | General Contact | store view |

The retry delay is `base ^ attempt` seconds, capped (5, 25, 125, 300, …). A `Retry-After`
header from the ERP is honoured when it is longer.

In the Docker stack `app/etc/env.php` pins the Base URL, so it is locked in admin.

The FRD's contract note says 7 days while the default here is 14. This is an open question for
the client and is listed in `submission/06-questions-and-next-steps.md`.

## Admin

| Screen | Where | ACL resource |
|---|---|---|
| Seller Refunds grid | Sales > Seller Refunds | `Acme_SellerRefund::view` |
| Create Seller Refund | "Create Seller Refund" button on an eligible order | `Acme_SellerRefund::create` |
| Refund detail | "View" in the grid | `Acme_SellerRefund::view` |
| Register cash refund | Action panel on the detail page | `Acme_SellerRefund::cash_register` |
| Retry / replay a dead job | Action panel | `Acme_SellerRefund::retry` |
| Cancel (only before any ERP Create attempt, BR-09) | Action panel | `Acme_SellerRefund::cancel` |
| Audit log with request/response popups | Detail page | `Acme_SellerRefund::audit` |
| Reissued receipt PDF | Detail page, and the link shown after submit | `Acme_SellerRefund::receipt` |

**Grid.** The grid is a standard UI component listing (`acme_refund_listing`). It has paging,
page size, sorting, column controls and saved views, and lists the newest refunds first by
default. You can filter by refund number, order number, seller order, status, ERP Create /
Status Check / Confirm status, type, refund total range, created date range and **Needs
Attention**. Needs Attention is Yes when an ERP call was rejected, conflicted, failed the local
payload check or ran out of retries, or when an outbox job was dead-lettered and not replayed
since; a call the outbox is still retrying is not flagged. Rows load in one query: the order and
the line summary are joined, not loaded per row.

**Refund detail.** Refund, order and ERP panels, the totals, the action panel and the audit
log. Each integration error in the log shows its correlation id ("Ref: …"), the
`X-Correlation-Id` the ERP received, so an operator can quote it to the ERP team (FRD 14). The
confirmation email's outcome is logged too (Succeeded, Not sent or Skipped).

**Create form.** The form lists the order's seller lines with the ordered quantity, the
quantity already refunded and the quantity remaining. Totals are recalculated on the server as
quantities change, and only the latest response is shown. Before the confirmation dialog, the
form checks the selection in the browser: at least one line, whole numbers, and no more than
remains. The server checks everything again (BR-03), under an order lock.

## Lifecycle

```
draft -> calculated -> cash_refund_pending -> cash_refunded -> erp_confirm_pending -> erp_confirmed
              |                 (Create)        (cash registered)   (Read Status)        (Confirm)
              +-> failed (ERP business reject)
              +-> cancelled (before any Create attempt)
```

The main status moves through `RefundStateMachine` only, with an optimistic `version` check.
Each ERP call also has a sub-status (`create_status`, `status_check_status`,
`confirm_status`):

| Sub-status | Meaning |
|---|---|
| `not_started`, `pending`, `succeeded` | Normal progress |
| `retryable_error` | Timeout, 429 or 5xx; the outbox retries with backoff |
| `business_rejected` | The ERP refused the request; the refund becomes `failed` |
| `conflict` | 409: the ERP already has this `refund_no` with a different payload. The refund stays `calculated`, keeps its quantity and waits for reconciliation |
| `invalid_payload` | The request failed the local check in `Model/Erp/PayloadValidator` and was never sent |
| `retries_exhausted` | The outbox used its last allowed attempt and dead-lettered the job; an operator replay sets it back to `pending` |

## ERP integration

| Call | Endpoint | When |
|---|---|---|
| Create Refund | `POST /erp-api/v1/refunds` | After save; the idempotency key is the stored `refund_no` (BR-07) |
| Read Refund Status | `GET /erp-api/v1/refunds/{erp_refund_id}` | After the cash refund is registered; it must report `refund-pending` |
| Confirm Refund | `POST /erp-api/v1/refunds/{erp_refund_id}/confirm` | After the status check passes |

Every call goes through the database outbox (`mp_refund_outbox`). Workers claim rows with
`SELECT … FOR UPDATE SKIP LOCKED`, so more than one worker can run safely. After saving, the
admin request tries the new refund's Create once, with the short timeouts above. If the ERP is
slow or down, the refund is already saved and the cron worker retries it.

Each attempt's request and response are written to `mp_refund_event`, with credentials, bank
details beyond the transaction reference, and personal data redacted (FRD 12.2).

## Cron and CLI

| Cron job | Schedule | Does |
|---|---|---|
| `acme_refund_outbox_work` | every minute | Runs due outbox jobs |
| `acme_refund_reconcile` | every 15 minutes | Queues a status check for settled cash refunds that have none pending (skips dead-lettered ones) |

| Command | Does |
|---|---|
| `bin/magento acme:refund:outbox:work` | Runs due outbox jobs now |
| `bin/magento acme:refund:reconcile` | Runs the reconciler now |
| `bin/magento acme:refund:sync-export <refund_no>` | Prints the downstream core-system export for one refund |
| `bin/magento acme:refund:settlement-export <refund_no>` | Prints the settlement adjustment export for one refund |

## Storefront

Customers see their refunds in two places, built from the stored refund snapshot (FRD 10.4):

- **Order details** (`sales_order_view`): for each refund, the partial-refund label, the
  pre-refund total and a separate refund section (refund date, product subtotal, shipping,
  ex-tax amount, consumption tax, total).
- **My Orders** (`sales_order_history`): a "Refunded Orders" list showing each refund, its total
  and the refunded items, newest order first like the My Orders list above it.

Draft, failed and cancelled refunds are not shown, because no money will be paid for them
(`Model/Presentation/CustomerVisibleRefunds`).

The confirmation email (`acme_seller_refund_email_confirmation_template`) is sent after the
ERP confirms. It is rendered in the frontend area, so it also works when the cron worker sends
it. Its outcome is written to the refund's audit log; a mail failure never affects the
confirmed refund.

The event `acme_seller_refund_confirmed` is dispatched when a refund reaches `erp_confirmed`.

## Calculation rules

- Amounts use bcmath with 4 decimals; JPY is shown without decimals.
- Tax uses the rate the order actually charged (`sales_order_item.tax_percent`). The product's
  tax class rate is used only when that is missing. The ERP still receives the business tax
  code (FRD 11).
- Shipping on a mixed order: the seller's share is the order shipping × seller ex-tax rows ÷
  order subtotal, capped at the order's shipping.
- A line can be refunded only up to the quantity remaining after earlier refunds that have not
  failed or been cancelled (BR-03).

## Tests

```bash
bin/assignment-test unit           # 73 tests
bin/assignment-test integration    # 21 tests, each in a rolled-back transaction
bin/assignment-test smoke          # storefront, admin, ERP stub, Create happy path
bin/assignment-test all
bin/assignment-test unit --filter RefundValidatorTest
```

Unit tests are in `Test/Unit`, integration tests in `Test/Integration`. The integration
tests move the seed orders' delivery dates to inside the refund window within their
transaction, so they do not depend on today's date.

## Troubleshooting

| Symptom | Check |
|---|---|
| Admin JavaScript stops working after a deploy | `pub/static/deployed_version.txt` must not end with a newline |
| A refund stays `calculated` with `retryable_error` | Is the ERP reachable? Is cron running? The outbox row shows `attempts`, `available_at` and `last_error` |
| A refund shows `conflict` | The ERP already holds this `refund_no` with a different payload; resolve it with the ERP team before replaying |
| A refund shows "Retries exhausted, needs replay" | The outbox gave up after Max Attempts. Fix the cause, then use Retry on the detail page; the reconciler does not re-queue dead jobs |
| The audit log shows "Email confirmation: Not sent" | The mail transport failed; the reason is in `var/log/system.log` and `var/log/exception.log` |
| The grid ignores the default sort | Your saved grid view keeps your last sort; choose **Default View** |
