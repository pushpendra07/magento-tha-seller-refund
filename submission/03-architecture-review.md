# 03 - Architecture Review

## Verdict

**Not ready for sign-off.** The overview's central choice is to run the whole flow inside the admin request: a synchronous Create Refund with in-request retries, a synchronous confirmation poll, and the controller as orchestrator. That choice breaks four FRD rules (BR-06 commit before notify, §7 transient errors hold the status, §9.2 batch status read, §12.1 no unbounded retries in interactive requests). Several of the problems it lists as "next phase" are correctness defects, not scale concerns.

One fact changes how the sign-off should be handled: **the code on `main` already departs from this document** in most of the places that matter. It has a DB outbox with leased claims and backoff, a state machine with optimistic locking on `version`, an order-row lock around submission, a shared figures calculator, a paginated and joined worklist, and the `MP_REFUND_STATUS_CREATED_AT` index. Sign-off should be against an updated document that describes the code. The risks below are ranked against the document, with a note where `main` already answers them and where `main` still has a gap.

**Companion document.** [`seller-refund-architecture-review.pdf`](seller-refund-architecture-review.pdf) takes the target state one step further for the business: a full return-and-refund flow (request, manager approval, ERP decision, warehouse check, finance payout) with Magento-style screen designs, then the reliability, code structure and scaling behind it. Its screens are proposals with sample data; nothing in it is implemented. It is reviewed against the code on `submission` at `3e5cbac`.

## Ranking criteria

Blast radius first, then likelihood, then how hard recovery is.

1. **Money:** a wrong, duplicated or orphaned financial action (a cash payout or an ERP credit note).
2. **Ledger divergence:** the store and the ERP disagree in a way the system cannot repair by itself.
3. **Customer and tax documents:** a qualified invoice or customer-facing figure is wrong.
4. **Availability:** operators cannot work, or the stated NFRs are missed.
5. **Operability:** recovery needs a developer or SQL.

## Risks, ranked

| # | Risk (overview section) | Blast radius | Response | On `main` |
| --- | --- | --- | --- | --- |
| 1 | **Duplicate submissions and over-refund** (§2, §7). The UI marks the row Refunded immediately and keeps Submit enabled "so the operator can re-press it"; the one-active-refund invariant is "left to the application layer". Two presses, or two operators, read the same remaining quantity. | Money | Disable Submit in flight; take a row lock on the order and re-read prior quantities inside the transaction; store a submission token so a repeated request returns the existing refund. | Lock and in-flight guard present. **Gap:** no submission token, so a browser resubmit after a timeout creates a second refund for any quantity still remaining. Fixed in this submission: a per-form token stored with a unique index returns the original refund (`06`, issue 42). |
| 2 | **ERP called inside the admin request, before durability is guaranteed** (§1, §3). A crash between the ERP accepting and the local write leaves a credit note with no local record (BR-06). | Money and ledger | Commit the refund and an outbox job atomically; a worker makes the call. | Done (`Outbox::enqueue` in the submit transaction, `Dispatcher`). |
| 3 | **Create retries with no stable-key guarantee** (§4). The client "rebuilds its request from the current record on each attempt", and the operator can re-run from the grid. Nothing states that `refund_no` stays fixed or that a local "already succeeded" check exists. | Money | `refund_no` fixed on every attempt; attempt identity only in a header and the audit row; local guard before calling. Test with the stub's `timeout_after_accept`. | Done (REF-142 guard, `e91e338`). PR 2 removes it: see `01`, finding 4. |
| 4 | **Wrong error taxonomy** (§4 table). 429, 5xx and timeouts end in `failed`, which the FRD makes terminal and which has no exit in the lifecycle. | Ledger and operability | Transient → hold the status, `retryable_error`, backoff honouring `Retry-After`. Business 4xx → `failed`. 409 conflict → quarantine. | Transient and business handling done; PR 2 reverts them (see `01`, finding 3). **Gap:** a 409 goes to `failed`, although it means the ERP already holds a credit note for that `refund_no`. `failed` refunds stop counting as refunded, so the units can be refunded again. A 409 should hold the quantity and go to reconciliation. Fixed in this submission: held at `calculated` with `create_status = conflict` (see `06`). |
| 5 | **Cancellation after the credit note exists.** The lifecycle allows `cancelled` from `cash_refund_pending`, but there is no ERP cancel call, and cancelled refunds stop counting towards the refunded quantity. BR-09 says submitted refunds cannot be cancelled. | Money and ledger | Allow `cancelled` only before any credit note exists (`calculated` with `create_status` not started or business-rejected), or add an ERP void operation first. | **Gap on `main`:** `RefundStateMachine::canCancel()` permitted `cash_refund_pending → cancelled`, and `PriorRefundQuantity` excludes cancelled refunds, so the same units could be refunded again while the first credit note stays open. Fixed in this submission: cancel only from `calculated` with no Create attempt (see `06`). |
| 6 | **Figures computed per surface** (§6). Five builders for the same numbers, one of which is the qualified tax invoice. | Tax documents | One figures object from the stored snapshot, consumed by all five surfaces; one acceptance test across all five. | Done (`RefundTotalCalculator`). PR 1 bypasses it: see `01`, finding 7. |
| 7 | **Synchronous confirmation poll** (§4): read status every 2 s for up to 30 s inside the request, then confirm; "no queue, dead-letter store, or replay path". | Availability and operability | Status check and confirm as outbox jobs, with the not-found bound from §9.2 and a dead-letter state visible on the worklist. | Done. **Gap:** `Reconciler` re-enqueues a status check whenever no job is *pending*, so a dead-lettered job is recreated every 15 minutes indefinitely (seen on the running stack for the seeded `SR-ORD-1006`). Fixed in this submission: dead jobs wait for an operator replay (see `06`). |
| 8 | **Worker exhaustion** (§3, §4). 30 s timeout × 3 attempts inside PHP-FPM requests; at 25 submissions/minute, a slow ERP occupies a worker pool quickly. The sign-off assumes "ERP retries are rare enough". | Availability | No external call in an interactive request; connect and read timeouts bounded separately; retries only in the worker. | Mostly done: retries run only in the worker, with separate connect (2 s) and read (3 s) timeouts. Save still makes one best-effort Create attempt for the refund it just committed, so an operator can wait up to the read timeout (see S3 below). |
| 9 | **Worklist and polling cost** (§2, §5). A 2-second poll × 40 operators is 20 requests a second, each doing an N+1 load of the full working set. | Availability | Paginated query with joins; refresh on demand, or poll the changed rows only, at 15–30 s. | Pagination and joins done. Polling not present. PR 2 removes the pagination: see `01`, finding 10. |
| 10 | **Settlement scan unindexed** (§5). "Relies on the table staying small", which it will not: about 60,000 refunds a year. | Availability | Composite index on `(status, created_at)`; bounded batches; `EXPLAIN` in the PR. | Done. PR 2 drops the index. |
| 11 | **Status column without a transition guard in the database** (§3): read the status, then write the next one. Two workers can both apply a transition. | Ledger | Compare-and-set on `version` in the `UPDATE ... WHERE version = ?`. | Done (`RefundStateMachine::transition`). |
| 12 | **Audit payloads not redacted** (§4 says payloads are "appended"). | Security | Redact before writing (§12.4); restrict raw payloads to the audit ACL. | ACL gate present on the view page. Nested values under a sensitive key were not redacted; fixed in this submission (`06`, issue 33). Which fields count as sensitive still needs an owner (fact 7 below). |
| 13 | **Non-atomic cash registration.** | Ledger | Transition, audit event and STATUS_CHECK job in one transaction. | **Gap:** `registerCashRefund()` writes them separately. The reconciler sweep covers a lost enqueue, so the impact is a delay, not data loss. Fixed in this submission: one transaction, and the same for `erp_confirm_pending` and its Confirm job (`06`, issue 36). |

## Target-state extension

Most of the target state is the code already on `main`. What remains is to close its gaps, then make the system observable and provably correct at the stated volume.

### 1. Correctness gaps (before go-live)

- **Submission idempotency:** the browser sends a token generated when the form loads; it is stored on `mp_refund` with a unique index. A repeat returns the existing refund. *Why:* closes BR-05 for the browser-retry case, which the order lock does not cover. (Done on `submission`.)
- **Cancellation and conflict rules:** restrict `cancelled` to before a credit note exists; send a Create 409 to a held reconciliation state instead of `failed`; count only business-rejected and pre-ERP `cancelled` refunds as released quantity. *Why:* risks 4 and 5. (Done; the 409 resolution process still needs the ERP team.)
- **Dead-letter semantics:** the reconciler skips refunds with a dead job, and the grid's Needs Attention column shows them for an authorised replay (both done in this submission). *Why:* risk 7; replay is a human decision.
- **Atomic cash registration:** one transaction. *Why:* risk 13. (Done.)

### 2. Observability

- Metrics: outbox depth and oldest-job age per operation, dead letters, ERP latency and error rate by class, refunds per status.
- Alerts: oldest CREATE job older than 15 minutes; any dead letter; any 409 conflict.
- A daily reconciliation report pairing local refunds with ERP credit notes by `refund_no` and `erp_refund_id`. *Why:* §12.2 requires every local refund and its credit note to stay traceable; this is how that is proven, not assumed.

### 3. Scale

At the FRD's volumes (25 submissions a minute at peak) the database outbox needs no broker. The next section sets out what changes when orders and returns arrive at hundreds or thousands a minute.

### 4. How I would validate it

| Guarantee | Test |
| --- | --- |
| No duplicate credit note | Stub `timeout_after_accept`, then a worker kill between the ERP call and the local write: one credit note, original `erp_refund_id`. |
| No over-refund under concurrency | 20 parallel submissions on one order: total refunded never exceeds the ordered quantity. |
| Transient errors recover | `server_error_5xx` and `rate_limit_429`: status stays `calculated`, the job backs off and succeeds when the stub recovers. |
| Surfaces agree | Mixed order: receipt, order details, history, email and export show identical figures. |
| Worklist NFR | 40 concurrent users against about 60,000 refunds: first page p95 under 1.5 s. Plus `EXPLAIN` on the worklist and settlement queries. |
| Recovery without SQL | Every dead-letter scenario is recoverable from the admin by a user with the retry permission. |

## Scaling to high volume

The FRD sizes the system at 5,000 refunds a month and 25 submissions a minute at peak. A grocery or quick-commerce operator running promotions across many stores can see returns arrive in bursts far above that, on top of heavy order traffic. This section designs for **10× and 100× the FRD peak**, says what breaks first in the current code, and gives a staged path so nothing is built before the numbers justify it.

### Capacity model

Each refund creates three ERP calls (Create, Read Status, Confirm), one email and one export record. Status and Confirm follow Finance's offline payment, so they arrive later and more smoothly than Create.

| Load | Submissions/min | ERP calls/s at peak (Create-heavy) | Workers needed at 300 ms ERP latency* |
| --- | --- | --- | --- |
| FRD peak | 25 | ~0.5 | 1 |
| 10× | 250 | ~5 | 2 |
| 100× | 2,500 | ~45 | 14+ |

\*Workers ≈ calls per second × latency, plus headroom. The real ceiling is usually the ERP's rate limit, not our worker count, which is why the ERP limit is the first fact to verify below.

### What breaks first in the current code (verified)

| # | Bottleneck | Evidence | Breaks at | Fix |
| --- | --- | --- | --- | --- |
| S1 | **One hot row serialises every submission in the store.** `refund_no` comes from a single per-day row in `mp_refund_sequence`, updated inside the submit transaction, so its lock is held until commit. | `ResourceModel/Sequence::next()` (`INSERT … ON DUPLICATE KEY UPDATE`), called from `RefundProcessor::submit()` | Roughly 10× | Allocate from an auto-increment sequence table (the pattern Magento's own `sequence_*` tables use), which does not hold a row lock to commit; or allocate the number after commit. The order-row lock stays per order, so unrelated orders never wait on each other. |
| S2 | **One worker, sequential HTTP.** The worker runs inside the shared cron process (`use_separate_process = 0`) and calls the ERP one job at a time; a slow ERP stalls the whole queue. | `etc/cron_groups.xml`, `Dispatcher::run()` | Roughly 10× | Run N consumer processes under a supervisor (`acme:refund:outbox:work` in a loop, or Magento queue consumers). `SKIP LOCKED` already lets them claim in parallel without coordination. |
| S3 | **Live ERP call in the admin request.** Save makes a best-effort Create inside the operator's request, up to the 3-second timeout. | `Controller/Adminhtml/Refund/Save.php` | Bursts of submissions | Make the immediate attempt switchable; at volume, return as soon as the refund commits and let workers do all calls. |
| S4 | **Unbounded table growth.** Done outbox rows and audit events are never purged or archived. | No retention job for `mp_refund_outbox` or `mp_refund_event` | Months at 100× | Purge `done` outbox rows after N days; partition `mp_refund_event` by month and archive old partitions to cold storage (it is append-only, so this is safe). |
| S5 | **Email inside the worker.** The confirmation email is sent synchronously when Confirm succeeds. | `Observer/SendConfirmationEmail` on `acme_seller_refund_confirmed` | 10×, and whenever SMTP is slow | Enqueue the email as its own outbox operation, so mail problems never slow ERP work. |
| S6 | **Outbox claim index does not include the operation.** The claim filters on state, operation and due time. | Index `MP_REFUND_OUTBOX_STATE_AVAILABLE_AT` | 100× | Index `(state, operation, available_at)`. |

### Design levers, by stage of the flow

**Submit (write path).**
- Keep the transaction short: lock the order row, read prior quantities, insert refund, items, event and outbox row, commit. No HTTP, no email, no PDF.
- Fix S1 so the only lock contention is between refunds on the *same* order, which is the contention BR-05 actually wants.
- A submission token (see risk 1) makes browser retries and double clicks idempotent at any volume.

**Queue (asynchronous work).**
- Separate queues per operation (Create, Status, Confirm, Email, Export), each with its own concurrency, so a backlog in one never starves another. This is the separation that kept ERP and operations-dashboard sync independent on the grocery platform referenced in `02`.
- Exponential backoff with jitter, honouring `Retry-After`; a hard attempt limit; poison messages straight to the dead letter.
- **Rate limiter in front of the ERP** (a token bucket sized to the ERP's agreed limit), so adding workers can never turn into a 429 storm.
- **Circuit breaker:** when the ERP error rate crosses a threshold, pause that operation's consumers for a cooling period instead of burning attempts; jobs wait in the retry state and resume automatically.
- Ordering per refund comes from the state machine (Status cannot run before Create succeeded), so the queue does not need global ordering; consumers can be scaled freely.

**Broker, when the numbers justify it.** At 100× or with several consumers of the same events, move transport to a broker with the same guarantees: a relay publishes committed outbox rows (the outbox stays the source of truth, so BR-06 still holds); work queues per operation; delayed retry queues (TTL plus dead-letter exchange back to work); a dead-letter queue; durable queues, persistent messages, publisher confirms, and acknowledgement only after the outcome is recorded. Magento's `queue_topology.xml` / `queue_consumer.xml` support both a database and an AMQP connection, so the switch is configuration plus consumers, not a rewrite. Idempotency keys make redelivery harmless.

**Reads (worklist and customer surfaces).**
- Worklist: keyset (cursor) pagination instead of `OFFSET`; covering indexes for the default filters; avoid `COUNT(*)` on the full table (show "more than N").
- At 100×, keep a small denormalised worklist projection (one row per refund: status, sub-statuses, totals, seller, last error), updated by the same transaction that changes the refund. The grid reads one table instead of joining orders and items.
- Customer surfaces: tag refund output with the order's cache identities so a refund invalidates only that order's pages, never broad catalogue or listing caches.
- Reporting and settlement scans read from a replica where the deployment has one, in bounded, cursor-based batches.

**Isolation from checkout.** The refund module must never slow order placement: no observers on checkout events, no shared hot rows with sales tables beyond the per-order lock, and its cron group and consumers sized separately from Magento's own.

### Staged path

| Stage | Trigger | Changes | Proof before moving on |
| --- | --- | --- | --- |
| 1. Tune what exists | Before go-live | S1 sequence fix, S3 switch, S5 email job, S6 index, retention (S4) | Load test at 2× FRD peak: p95 submit < 500 ms; queue lag < 1 min |
| 2. Scale out on the database | Sustained > 100 submissions/min, or queue lag > 2 min | N supervised consumers per operation, rate limiter, circuit breaker, worklist projection | Load test at 10×: no lock waits on `mp_refund_sequence`; ERP 429s stay at zero |
| 3. Move transport to a broker | > 1,000/min, or several systems consuming refund events | Outbox relay to broker; per-operation, retry and dead-letter queues | Load test at 100× with ERP latency and failures injected; zero duplicate credit notes, zero lost jobs |

### Service levels to monitor

- Submit p95 latency; lock-wait time on order rows and the sequence.
- Queue lag per operation (age of the oldest due job) and depth.
- ERP calls per second, latency and error rate by class; circuit-breaker state.
- Dead letters (alert on any); daily reconciliation mismatches (alert on any).

## Facts to verify before signing off

1. Which document is authoritative: this overview or the code on `main`? They describe different systems.
2. Does the ERP deduplicate Create on `refund_no` alone, or on `refund_no` plus payload hash? The stub uses both; the behaviour on a changed payload (409) decides whether quarantine or retry is right.
3. Is there an ERP operation to void a credit note? Without one, cancellation after Create cannot be supported at all.
4. What does a 404 from Read Refund Status mean after the eventual-consistency bound: lost credit note, or wrong environment? The answer decides between dead-letter and a business alert.
5. **ERP rate limits and p99 latency.** The sign-off assumes a 5-second normal response. These numbers set the worker count, the rate limiter and the circuit breaker for every stage above; does the ERP offer a bulk Create endpoint for bursts?
6. The refund window: 14 days (BR-02) or 7 days (the contract note).
7. Who owns redaction rules for `mp_refund_event` payloads, and which fields are personal data under the customer agreement.
8. The downstream core-system export contract: format, delivery guarantee and who acknowledges it (§9.4 calls it authoritative).
9. **The real peak.** What return volume do promotions and multi-store campaigns actually produce (per minute, not per month), and how fast must a refund reach the ERP? That decides which scaling stage is needed at go-live.
