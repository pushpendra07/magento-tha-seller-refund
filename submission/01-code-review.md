# 01 - Code Review

## Scope and method

| | |
| --- | --- |
| Base | `main` @ `b65f43c` |
| PR 1 | `review/pr-01-partial-refund-presentation` @ `bb5ee19` (3 commits) |
| PR 2 | `review/pr-02-erp-refund-sync` @ `6d5fa01` (3 commits) |

For each branch I read the description, the diff, the callers and collaborators of every changed class, the tests, the schema, the ERP stub contract (`erp-refund-stub/contract.md`) and the relevant history, notably `e91e338` (REF-142, an earlier duplicate-credit-note incident).

Findings marked **Reproduced** were confirmed on the running stack. I checked out the branch, added a temporary integration test (not committed) and ran it with `bin/assignment-test integration` against seed data and the real stub. Coding-standard findings come from `vendor/bin/phpcs --standard=Magento2`, run inside the `web` container on each changed file. Paths are relative to `app/code/Acme/SellerRefund/`, and line numbers refer to the PR branch.

## Ranking criteria

Findings are ranked in this order:

1. **Financial blast radius.** Does it move money wrongly, duplicate a financial obligation, or send a wrong figure to the ERP, the customer or the ledger?
2. **Likelihood.** Does it happen on the normal path, under ordinary retries or timeouts, or only under rare concurrency?
3. **Recoverability.** Can the system or an operator recover it without database editing, or is it permanent divergence?
4. **Operational impact.** Performance at the FRD §12 volumes, operator experience, and maintainability against Magento 2 standards.

| Severity | Meaning |
| --- | --- |
| **P1 — blocks merge** | Wrong money, duplicate or orphan financial action, lost or unrecoverable state, or a false success shown to an operator. |
| **P2 — must fix before release** | Wrong figures on a customer document, or a demonstrated scaling regression. |
| **P3 — should fix** | Standards or maintainability issues with no direct financial effect. |

Style-only issues are not ranked. Both PRs need changes before merge.

## Summary

| # | PR | Sev | Finding | Evidence |
| --- | --- | --- | --- | --- |
| 1 | 2 | P1 | Quantity guard ignores prior refunds, so the order can be over-refunded | Reproduced |
| 2 | 2 | P1 | ERP called inside an uncommitted transaction; failure leaves the ERP and local record permanently out of sync | Code + Reproduced |
| 3 | 2 | P1 | Temporary ERP errors (429/5xx/timeout) become a terminal `failed`; replay cannot recover | Reproduced |
| 4 | 2 | P1 | Retry changes the idempotency key, so a retry opens a second credit note | Reproduced |
| 5 | 2 | P1 | Outbound tax codes are local EAV option IDs, not business codes | Reproduced |
| 6 | 1 | P1 | Screen shows "Refunded" before the server answers; double-submit guard and error handling removed | Code |
| 7 | 1 | P2 | Receipt header uses whole-order figures and disagrees with the other four surfaces | Reproduced |
| 8 | 1 | P2 | New tax-rate groups and ex-tax row never reach the PDF; tests check only the data array | Reproduced |
| 9 | 1 | P2 | "Refunded before" quantity is computed live and includes this refund and later ones | Reproduced |
| 10 | 2 | P2 | Worklist loses pagination and joins (N+1, unbounded); reconciliation index dropped | Code |
| 11 | 1, 2 | P3 | Magento 2 standards: `final` and static factory, orchestration in the controller, layer bypass, duplicated query | phpcs + Code |

---

## PR 2 — Add ERP tax mapping and refund retry handling

### 1. P1 — Quantity validation no longer subtracts prior refunds (over-refund)

**Evidence.** `Model/RefundValidator.php:60–62` now compares the requested quantity with `qty_ordered`. The subtraction of `$priorRefundedByItem` was removed, though `RefundProcessor::submit()` still loads those values under the order lock and passes them in (`Service/RefundProcessor.php:72–73`). Nothing downstream caps the quantity: `qty_refundable_after` is a signed decimal (`etc/db_schema.xml:53`).

**Reproduced.** Seed order `SR-ORD-1003` has 3 × `SELLER-YEL-04` with 2 already refunded. Requesting 2 more is accepted: `qty_refund = 2`, `qty_refundable_after = -1.0000`, `grand_total = 6600`. `main` rejects the same request.

**Impact.** This refunds more than the customer bought, and Finance pays it out offline from this record (BR-03, §12.2). It happens on the normal single-operator path, needs no concurrency, and the money cannot be recovered by the system. It ranks first because it moves cash wrongly on every occurrence.

**Recommendation.** Restore `remaining = qty_ordered − qty_refunded_before`, computed from the prior quantities read under the lock. Add a regression test on `SR-ORD-1003` that asserts rejection and that no refund, item or outbox row is written.

> **Comment to author:** "This change drops the prior-refund subtraction from the quantity check, so a line that has already been partly refunded can be refunded again in full. On SR-ORD-1003 a request for 2 more yellow units passes although only 1 remains, and the item is saved with `qty_refundable_after = -1`. Could we keep comparing against `qty_ordered − qty_refunded_before`, using the values `submit()` already reads under the order lock? A regression test on that seed order would protect it from here on. Happy to pair on it if useful."

### 2. P1 — The ERP is called inside an open transaction, and any ERP failure leaves the two systems permanently out of sync

**Evidence.** `Controller/Adminhtml/Refund/Save.php:48` opens a transaction, then calls `submit()`, then `client->create()` at line 62, then commits at line 74. Magento's PDO adapter counts nested transactions, so `submit()`'s own commit only lowers the nesting level. The refund is not durable when the HTTP call leaves; `Test/Integration/Framework/DbTransactionTestCase.php:13–16` documents this adapter behaviour. On any `ErpException` the controller marks the refund `failed/business_rejected` and **commits** (lines 88–97). The `OP_CREATE` outbox job that `submit()` enqueued is committed with it.

**Reproduced chain.** After a refund is marked `failed`, the queued job runs `process()`, which PR 2 now allows for `failed`. That calls the ERP again, and then the local transition `failed → cash_refund_pending` throws `IllegalTransitionException` because `RefundStateMachine::ALLOWED` has no such edge. The dispatcher dead-letters the job.

**Impact.**
- **Timeout after the ERP accepted:** the credit note exists in the ERP, but locally the refund is `failed` and can never advance.
- **Process killed between the ERP call and commit:** the local refund, items and outbox roll back, but the ERP credit note remains. That orphan has no local record at all, which violates BR-06.
- **Worker exhaustion:** the order lock and a PHP worker are held for the whole 30-second timeout, inside an admin request. §12.1 says interactive requests must not wait on external retries.

**Recommendation.** Keep `main`'s design. Controller: validate, call `submit()`, return the saved state. Service: commit the refund and the outbox job atomically. Outbox worker: make the HTTP call outside any transaction. If an immediate attempt is wanted for the operator's convenience, dispatch it only *after* the physical commit and treat its result as best-effort.

> **Comment to author:** "Thanks for getting the ERP ID back to the operator, that's a real usability gain. One problem: the outer `beginTransaction()` in `Save` means `submit()`'s commit is only a nested one, so the ERP hears about the refund before the database has it. A crash at the wrong moment leaves a credit note with no local refund. And when the ERP call fails, the refund is committed as `failed` while its outbox job is still queued; that job then calls the ERP again and fails on the `failed → cash_refund_pending` transition. Could we drop the controller-level transaction and keep the ERP call in the outbox worker as on `main`? A quick check is that a second DB connection can see the refund before any HTTP request is sent."

### 3. P1 — Temporary ERP errors are treated as permanent business rejections

**Evidence.** `Service/RefundProcessor.php:142` catches the base `ErpException`, which includes `ErpTransientException`, and transitions to `failed` with `create_status = business_rejected`. The exception is not rethrown, so `Model/Outbox/Dispatcher::handle()` marks the job **done**: no backoff, no attempt count, no dead-letter. `Save.php:88` does the same. `replay()` now maps `failed` to `OP_CREATE` (line 292), but the state machine cannot leave `failed`. Separately, `error_code` now stores `$e->getMessage()` (line 153) in a `varchar(64)`, so the field no longer holds a stable code. Under MariaDB's default strict SQL mode, a message over 64 characters makes the insert itself fail inside the error handler; without strict mode it is silently truncated. (Code reading; not reproduced.)

**Reproduced.** With the stub returning one `503`, `process()` leaves the refund at `status = failed`, `create_status = business_rejected`. Replaying throws `IllegalTransitionException` after the ERP has already been called.

**Impact.** Contradicts FRD §7 and §9.6: "Transient ERP errors never change the main status", and a 429 "should be retried after a backoff". At campaign peak (25 submissions/minute), one short ERP incident would permanently fail every refund in that window, with recovery only by database editing (§12.2 forbids this).

**Recommendation.** Restore `main`'s separate catch blocks:
- **Transient:** hold `calculated`, set `create_status = retryable_error`, record the attempt, rethrow for backoff.
- **Business rejection:** move to `failed`.
- **Payload conflict (409):** `main` also sends this to `failed`; I would rather quarantine it for reconciliation, since the ERP already holds a credit note for that `refund_no` (see `03`, risk 4). That is a pre-existing gap, not something this PR introduced.

Revert the `failed` replay mapping, and test 429, 503 and a timeout through the dispatcher, not only the client.

> **Comment to author:** "The single `catch (ErpException)` also catches timeouts, 429s and 503s, so a brief ERP outage marks refunds as business-rejected, and the worker then records the job as done. I reproduced it with one 503 from the stub. FRD §7/§9.6 want those held at `calculated` with `retryable_error` and retried with backoff, which is what `main`'s separate catch blocks did. Could we bring those back? I'd also suggest reverting the `failed` replay mapping, since the state machine has no way out of `failed` and the replay ends in an exception after calling the ERP."

### 4. P1 — Each retry sends a different idempotency key, so a retry opens a second credit note

**Evidence.** `Model/Erp/RequestKey.php:20–28` returns `refund_no` for attempt 1 and `refund_no-02`, `-03`, and so on, for later attempts. `Model/Erp/PayloadBuilder.php:42` sends that value as the body `refund_no`. The stub contract (*Idempotency model*) deduplicates Create **only** on body `refund_no` plus payload hash. `X-Request-Id` "is never used as a deduplication key". PR 2 also deletes the REF-142 local guard (`hasSucceededCreate()` / `isCreateSucceeded()`), which `e91e338` added after a real duplicate-credit-note incident.

**Reproduced.** For the same refund, attempt 1 (`SR-…-000001`) returns `ERP-8000001`, and attempt 2 (`SR-…-000001-02`) returns `ERP-8000002`. The stub now holds **two** credit notes for one refund.

**Likelihood, stated precisely.** In PR 2 as submitted, attempts above 1 are rare. Because of finding 3, transient errors are swallowed instead of rescheduled, and `Outbox::fail()` is what increments `attempts`. An operator retry does not reach attempt 2 either: `replay()` enqueues a new outbox row whose `attempts` starts at 0, so it sends the unsuffixed key, and the stub replays the original response. That path fails instead on the state transition (finding 3). As soon as finding 3 is fixed, which it must be, every rescheduled Create reaches attempt 2 or later. A response lost after the ERP accepted then produces a duplicate credit note on the seller's ledger. With the local guard gone, nothing else stops it. That is why it stays P1.

**Recommendation.** The business key must be the persisted `refund_no` on every attempt (BR-07, §9.6). Put the attempt identity in transport metadata only (`X-Request-Id: <refund_no>:<attempt>`) and in `mp_refund_event.attempt_no`. That is enough to tell attempts apart on both sides, which was the PR's goal. Keep the REF-142 guard as defence in depth. Add a test using the stub's `timeout_after_accept` mode that asserts one ERP record and the original `erp_refund_id` after a retry.

> **Comment to author:** "Telling attempts apart in the ERP audit is a good goal. The ERP, though, deduplicates on the body `refund_no` and ignores `X-Request-Id`, so `SR-…-02` looks like a brand-new refund. I reproduced it: two attempts for one refund gave `ERP-8000001` and `ERP-8000002`. That's the same failure REF-142 (`e91e338`) fixed. Could we keep `refund_no` constant and put the attempt number only in `X-Request-Id` and the event's `attempt_no`? I'd also keep the REF-142 guard, and add a `timeout_after_accept` test asserting a single credit note."

### 5. P1 — Tax codes sent to the ERP are installation-specific option IDs

**Evidence.** `Model/Erp/PayloadBuilder.php:59` sends `toOptionId($item->getTaxCode())`. `mp_refund_item.tax_code` already holds the business code (`010`/`008`/`999`). `toOptionId()` is the inbound conversion; its own class doc (`Model/Tax/TaxCodeResolver.php:9–13`) says option IDs "must never leave the store". FRD §11 requires the admin option value. `buildTaxes()` also feeds `Model/Export/SettlementAdjustmentExporter.php:36`, so the seller-ledger export breaks too. The updated unit test (`PayloadBuilderTest`) now asserts the wrong values (`'7'`, `'6'`).

**Reproduced.** For `SR-ORD-1002` the payload's tax codes are `["7","6"]` instead of `["010","008"]`. The stub stores tax values without validating them, so the happy-path test passes regardless.

**Impact.** Wrong tax classification sent to the ERP and the settlement ledger on every refund, and the values differ per environment, so staging can pass while production fails. Leading zeros are lost as well.

**Recommendation.** Send the stored business code unchanged. Restore the test expectations, and add a test where local option IDs deliberately differ from the business codes.

> **Comment to author:** "`toOptionId()` converts *into* local option IDs, which is the inbound direction. The item already stores the business code, so the payload (and the settlement export, which shares `buildTaxes()`) now carries `7`/`6` instead of `010`/`008`. Those IDs also vary per installation. Could we send `getTaxCode()` as-is and put the test expectations back to the FRD values? A fixture where option IDs ≠ business codes would catch this kind of regression."

### 10. P2 — Worklist becomes unbounded with per-row loads; the reconciliation index is dropped

**Evidence.**
- **Worklist:** `Block/Adminhtml/Refund/Worklist.php:31–50` removes `joinOrderSummary()`, `addLineSummary()` and `setPageSize(50)`. It then loads an order (`orderRepository->get`, line 37) and the items (line 38) for **every** refund in the table: an unbounded collection with 2N extra queries.
- **Index:** `etc/db_schema.xml` drops `MP_REFUND_STATUS_CREATED_AT`, while `Model/Reconciler.php:14–15` still filters on status and `created_at`, and documents that it relies on that index.

**Impact.** FRD §12.1 needs the first page within 1.5 s for 40 concurrent operators, at about 5,000 refunds a month and growing. The cost of this page now grows with total history, not page size, and the daily settlement scan loses its index. (Structural assessment; I did not run a load test.)

**Recommendation.** Keep the joined collection and pagination, and add `seller_code` and the totals as joined columns. Keep the index. Add an `EXPLAIN` of the worklist and reconciler queries at representative volume to the PR.

> **Comment to author:** "The new seller/SKU/total columns are useful for operators. Could we add them as joined columns on the existing collection, rather than loading each order and its items in the block? As written, the page loads every refund in the table plus two queries per row, which will miss the 1.5 s target as history grows. Separately, `Reconciler` still filters on status and `created_at`, so I'd keep `MP_REFUND_STATUS_CREATED_AT`."

---

## PR 1 — Align partial-refund presentation and validation

### 6. P1 — The screen shows "Refunded" before the server responds, and the double-submit guard is removed

**Evidence.** `view/adminhtml/web/js/refund-form.js:104–124`:
- Sets the state to `Refunded` and unhides the receipt link **before** the AJAX request is sent (lines 107–108).
- Removes `inFlight`, the button disabling, the `response.ok` check and the `.fail()` handler, so a validation error, timeout or network error leaves "Refunded" on screen.
- Points the link at `redirect_url`, which is the admin **view** page (`Save.php:53`), not the receipt controller (`Controller/Adminhtml/Refund/Receipt.php`).

**Impact.**
- **False success:** the operator moves on believing a refund exists when it may not. Customer and Finance are then told the wrong thing.
- **Double submit:** with the guard gone, double-clicks and re-presses send repeated financial requests. `main`'s order lock serializes them but does not deduplicate a repeated browser submission (BR-05).
- **Rules broken:** FRD §14 is explicit that the button stays disabled in flight, the browser never invents a lifecycle state, and a Create timeout shows "Saved; ERP notification pending retry". BR-11 lets the receipt link appear once the record *exists*, which only the server response can tell us.

**Recommendation.** Keep `main`'s `_save` / `_renderServerState` / `_fail` flow. To meet BR-11, show the receipt link from the server's response, using a receipt URL returned by `Save`. Test a rejected save, a network failure and a double-click.

> **Comment to author:** "I see the goal: CS wants the receipt as early as possible, and BR-11 allows that once the refund exists. The catch is that only the server response tells us it exists. Right now the screen shows 'Refunded' before the request is sent and stays that way even if the save fails, and the in-flight guard is gone, so a double-click sends two refund requests. Could we restore the guard and error handling from `main`, and reveal the link in `_renderServerState()` using a receipt URL from the response? `redirect_url` currently points at the admin view page, not the PDF."

### 7. P2 — Receipt header uses whole-order figures and disagrees with the other surfaces

**Evidence.** `Model/Pdf/RefundReceipt.php:50` sums `getAllVisibleItems()`, which includes first-party core lines, instead of using `RefundTotalCalculator::fromSnapshot()`. The other surfaces (order details, history, email, downstream export) still use the calculator, which counts only seller lines. That shared calculator was introduced by REF-142 (`e91e338`) specifically so the receipt would agree with the other surfaces.

**Reproduced.**
- On mixed order `SR-ORD-1002`, the receipt's pre-refund total is **JPY 5,248**, while the shared calculator, and therefore every other surface, shows **JPY 3,048**.
- The 10% tax group reports a taxable amount of 3,200: 1,200 seller + 2,000 core.

**Impact.** The receipt is also the qualified tax invoice (FRD §10.4). It now shows figures that contradict the email, account pages and ledger export for the same refund, and it mixes first-party sales into a seller document. The PR's only test uses a pure-seller order with zero shipping, where the two calculations happen to agree.

**Recommendation.** Keep the calculator as the single source. If a whole-order header is genuinely required, add it to the calculator's output, label it distinctly, and agree the scope with Tax first. Add a mixed-order test comparing all five surfaces.

> **Comment to author:** "On a mixed order the new header counts the first-party lines too. For SR-ORD-1002 the receipt shows 5,248 while the email, account pages and export show 3,048 for the same refund, and the 10% tax group includes the core item. REF-142 routed the receipt through `RefundTotalCalculator` to keep these aligned. Could we keep that, and add anything new to the calculator so every surface gets it? A mixed-order fixture in the test would show the difference immediately."

### 8. P2 — Tax-rate groups and the ex-tax row are computed but never printed

**Evidence.** `buildData()` adds `tax_groups` (line 79), but `render()` (lines 138–186) never prints it, and `totals()` (line 192) has no ex-tax line. The rate is guessed as `tax / row` (`rateFor()`, line 222) instead of reading the stored `tax_rate`, which breaks with rounding or allocated shipping. For example, a JPY 333 line at 10% carries JPY 33 tax after yen rounding, and `33 / 333` gives `0.0990`, so that line would land in a separate group from other 10% lines. The seed amounts divide exactly, which is why the PR's test does not show it. The new test asserts on `buildData()` only.

**Reproduced.** The rendered PDF for a two-rate order contains neither a rate group nor an "ex-tax amount" row.

**Impact.** The PR's stated purpose ("per-rate tax groups the qualified invoice needs", "ex-tax" in the breakdown, §10.4) is not delivered on the customer document, and the tests can't tell.

**Recommendation.** Print the groups and the ex-tax row, grouping on the snapshot `tax_rate`/`tax_code`. Test on the rendered output, or on a structure `render()` demonstrably consumes, with two rates and non-zero shipping.

> **Comment to author:** "The tax groups are built but `render()` never prints them, and the breakdown has no ex-tax row yet, so the PDF looks the same as before for these two items. Could we print both, and group on the stored `tax_rate` rather than dividing tax by row amount? Division drifts once shipping or rounding is involved. A test that checks the rendered receipt would confirm what the customer sees."

### 9. P2 — "Refunded before" changes after later refunds

**Evidence.** The new `Model/ResourceModel/Refund::loadRefundedQtyByOrder()` (line 71) sums *all* non-failed refunds on the order, including this one and any created later. `mp_refund_item.qty_refunded_before` already stores the correct value, captured at submission. The new method also duplicates `Model/ResourceModel/PriorRefundQuantity::sumRefundedByOrderItem()` line for line.

**Reproduced.** On the very first refund of an order, the receipt reports `refunded_before = 1` (its own quantity) instead of 0.

**Impact.** A receipt regenerated later shows different history, which is not acceptable for a tax document. (The field is not printed yet, see finding 8, which is why this is not P1.)

**Recommendation.** Use the item's own `qty_refunded_before` snapshot and remove the new resource method.

> **Comment to author:** "`loadRefundedQtyByOrder()` includes the current refund and any later ones, so the first receipt for an order already shows its own quantity as 'refunded before', and the number changes every time the PDF is regenerated. Each refund item stores `qty_refunded_before` at submission. Could we read that instead? It also lets us drop the new query, which duplicates `PriorRefundQuantity`."

---

## 11. P3 — Magento 2 standards (both PRs)

| Where | Issue | Standard / reason |
| --- | --- | --- |
| PR 2 `Model/Erp/RequestKey.php:13` | `final class` | phpcs **error** `Magento2.PHP.FinalImplementation`: final classes cannot be intercepted by plugins or proxies. |
| PR 2 `Model/Erp/RequestKey.php:20` | `public static function forAttempt()` | phpcs warning `Magento2.Functions.StaticFunction`: logic should be in an injectable class so it can be replaced via DI and mocked. |
| PR 2 `Controller/Adminhtml/Refund/Save.php` | Controller injects `ResourceConnection`, the payload builder, the ERP client and the state machine, and manages transactions | Magento controllers should delegate to a service class; transaction boundaries belong to the service or resource layer. The new design moves the orchestration back from `RefundProcessor` into the controller. |
| PR 2 `Block/Adminhtml/Refund/Worklist.php` | Block injects repositories and loads data per row | Blocks and view models should read a prepared collection; data access belongs to the resource model (see finding 10). |
| PR 1 `Model/Pdf/RefundReceipt.php` | Presentation class injects a concrete `ResourceModel` | Depend on a repository or service contract, not on a resource model, from a presentation class. |
| PR 1 `Model/ResourceModel/Refund.php:71` | Copy of `PriorRefundQuantity::sumRefundedByOrderItem()` | Duplicated query; reuse the existing class. |

Pre-existing on `main` and not introduced by either PR: `@iconv` in `RefundReceipt::ascii()` (phpcs `Generic.PHP.NoSilencedErrors`) and missing docblocks. Worth a follow-up, but not attributed to these authors.

## What is good

- **PR 2:** recording an attempt identity per Create (`request_key`, `X-Request-Id`) is the right instinct for ERP-side tracing; it only needs to move out of the idempotency key. The new seller/SKU worklist columns answer a real operator need.
- **PR 1:** the partial-refund label and refund date belong on the receipt, and `refundSection()` correctly sums from the stored snapshot.

## Test coverage observation

Both PRs' tests confirm the new behaviour on the happy path only:
- PR 1 uses a pure-seller, zero-shipping order and inspects `buildData()`, not the PDF.
- PR 2 deletes the REF-142 guard tests and changes `PayloadBuilderTest` to assert the wrong tax codes.

None of the defects above would be caught by the branches' own suites. Each recommendation includes the test I'd expect alongside the fix.

## Selection for Part 3

Part 3 takes the most serious defect from this review. By the criteria above, that is **finding 1** (over-refund), with **finding 4** (unstable idempotency key) as the alternative. The choice and its reasoning are recorded in `04-build-impact-note.md`.

## Fix status

After the review, every finding was fixed on a branch of its own. `main` and the two review
branches are unchanged. The fixes have tests that fail on the PR's code and pass with the fix,
with two exceptions: finding 6 is browser behaviour and was verified by hand when it was fixed;
and finding 2's `SaveTest` fails on PR 2's code only because the controller's dependencies
differ, so it guards the corrected behaviour going forward rather than proving the old one wrong.

All of these branches are merged, with the fixes for the issues found on `main`, into the
`submission` branch. There, the form from finding 6 also has JavaScript tests for its Save
result and its submission token (`06`, issues 42 and 43).

| Finding | Branch | Commit |
| --- | --- | --- |
| 1 (Part 3) | `fix/pr-02-over-refund-guard` (PR #3) | `c1343a7` |
| 2 | `fix/pr-02-review-fixes` | `136d97a` |
| 3, 4 (and `error_code`, `RequestKey` standards) | `fix/pr-02-review-fixes` | `0c6ddde` |
| 5 | `fix/pr-02-review-fixes` | `7ff47c1` |
| 10 | `fix/pr-02-review-fixes` | `f1d98d2` |
| 6 | `fix/pr-01-review-fixes` | `435788f` |
| 7, 8, 9 (and the PR 1 standards items) | `fix/pr-01-review-fixes` | `b949f0b` |
