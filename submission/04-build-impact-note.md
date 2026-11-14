# 04 - Build Impact Note

## What was fixed

**Finding 1 from `01-code-review.md`: PR 2 lets a seller line be refunded beyond what remains (BR-03).**

PR 2 changed `RefundValidator::validate()` to compare the requested quantity with `qty_ordered`. The prior refunded quantities were still passed in, but no longer used. On seed order `SR-ORD-1003` (`SELLER-YEL-04`: 3 ordered, 2 already refunded), a request for 2 more was accepted and saved with `qty_refundable_after = -1` and a refund of JPY 6,600.

## Why this one

The ranking criteria in `01-code-review.md` put financial blast radius first, then likelihood, then recoverability.

- **Blast radius:** cash is paid out offline from this record, so every occurrence is money that should not leave.
- **Likelihood:** it happens on the normal single-operator path for any order that already has a partial refund. No failure, timeout or concurrency is needed.
- **Recoverability:** once Finance has paid, the system cannot get it back.

Finding 4 (the idempotency key changes on retry) is the alternative P1. In PR 2 as submitted, it needs a retry above attempt 1 on the same outbox row, which PR 2's own error handling prevents (finding 3). It becomes live once that is corrected, so it is the first follow-up below.

## Where the fix lives

`main` already enforces BR-03; the defect exists only in PR 2. The fix is commit `c1343a7` on branch `fix/pr-02-over-refund-guard`, created from `review/pr-02-erp-refund-sync` @ `6d5fa01`, so it can be merged into the PR or reviewed as a follow-up commit on it.

## The change

`Model/RefundValidator.php`: the cap is now `qty_ordered − qty_refunded_before`. The prior quantities come from `RefundProcessor::submit()`, which reads them under the order lock, so two operators cannot both pass the check for the same remaining unit. The error message now states the remaining quantity.

No schema, DI, API or UI change. The rest of PR 2 is untouched.

## Tests

| Test | Type | Before fix | After fix |
| --- | --- | --- | --- |
| `RefundValidatorTest::testQuantityExceedingRemainingAfterPriorRefundsIsRejected` (3 ordered, 2 refunded, 2 requested) | Unit | Fails | Passes |
| `RefundValidatorTest::testFullyRefundedLineIsRejected` (2 ordered, 2 refunded, 1 requested) | Unit | Fails | Passes |
| `OverRefundGuardTest::testRequestAboveRemainingQuantityIsRejectedAndNothingIsWritten` (`SR-ORD-1003`, real DB, asserts no refund, item or outbox row) | Integration | Fails: "A request for 2 units with only 1 remaining must be rejected." | Passes |
| `OverRefundGuardTest::testRequestAtRemainingQuantityIsAccepted` (guards against over-correcting) | Integration | Passes | Passes |

Full suites after the fix: unit 30 / 30, integration 9 / 9, smoke 6 / 6.

`phpcs --standard=Magento2`: 0 errors. The new test file has no warnings; the two edited files keep the same 7 docblock warnings they had on PR 2.

**Run:**

```bash
bin/assignment-cache-flush --all
bin/assignment-test all
# just this fix:
bin/assignment-test unit --filter RefundValidatorTest
bin/assignment-test integration --filter OverRefundGuardTest
```

## Impact and risk

- **Who sees a difference:** an operator who asks for more than remains now gets a validation error at submit instead of a saved refund. Normal requests are unaffected.
- **Data:** refunds already saved with a negative `qty_refundable_after` are not corrected by this change. Before release, run `SELECT refund_id, order_item_id, qty_refundable_after FROM mp_refund_item WHERE qty_refundable_after < 0;` and hand any rows to Finance.
- **Rollback:** a one-file revert.

## Not fixed here (follow-ups on PR 2)

In priority order, from `01-code-review.md`:

1. Findings 3 and 4 together: restore transient vs business vs conflict handling, keep `refund_no` constant on every attempt, and put the attempt number in `X-Request-Id` and `attempt_no` only. These must ship together, because fixing 3 alone makes the duplicate credit note in 4 reachable.
2. Finding 2: remove the controller-level transaction and keep the ERP call in the outbox worker.
3. Finding 5: send the stored business tax code, not the option ID.
4. Finding 10: restore pagination, joins and the `MP_REFUND_STATUS_CREATED_AT` index.
