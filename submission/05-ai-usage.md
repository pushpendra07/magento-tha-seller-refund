# 05 - AI Usage

## Summary

Three parties did this work: me, **Claude Code** (Anthropic) and **Codex** (OpenAI). Both are AI coding agents that ran in my terminal with access to the repository and the Docker stack. Claude Code did most of the work; Codex reviewed the module independently and then took over part of the final fixes and the change report.

I owned the direction. I defined the path through the assignment (what to do, in what order, on which branch), the role Claude Code played at each step, the rules it worked under, and what counted as done. The agents did most of the reading, reproducing, drafting, coding and test-running inside that frame. I checked its claims against the running stack, corrected it where it was wrong, and approved every commit and push. I can explain and defend everything submitted.

## Who did what

| | Me | Claude Code | Codex |
| --- | --- | --- | --- |
| Role | Owner and decision-maker: path, scope, branch layout, priorities, acceptance | Main engineer working to my instructions: investigate, reproduce, implement, test, draft | Independent reviewer of the module, then engineer for part of the final round |
| Decides | Which defect Part 3 fixes, what is a bug versus an improvement, what waits for the client, what goes on which branch, when something is finished | Nothing on its own; proposes, then waits for my answer | Nothing on its own; worked under the same rules, from a written handover |
| Writes | Rules, handovers, review comments on their output, corrections, approvals | Code, tests, reproduction scripts, document drafts | A module review; the submission token, stricter ERP ids, Save outcome messages and receipt PDF fixes; the integration merges; the change report PDF |
| Checks | Their findings against the code, the FRD and the browser; every diff before commit | Its own work with tests, phpcs and the browser; Codex's review findings against the code | Its own work with tests and the browser |

Claude Code reached the admin and storefront through its Chrome integration (`localhost:8080`, synthetic accounts from the README), reading console and network errors there.

## The path I defined

1. **Set up and baseline.** Get the stack running, confirm `main` matches Cidroy's original, open the two supplied PRs on my private fork. `main` never changes.
2. **Part 1, review first.** Read both PRs against the FRD, the stub contract and `main`; reproduce every finding that can be reproduced before writing it down; rank by money, then likelihood, then recoverability.
3. **Part 2, design and architecture,** from the FRD and the code on `main`, not from the overview alone.
4. **Part 3, one fix,** the top-ranked defect, test first, on its own branch off PR 2 (`fix/pr-02-over-refund-guard`, PR #3).
5. **Then the remaining review findings,** each on a branch built from the PR it fixes (`fix/pr-02-review-fixes`, `fix/pr-01-review-fixes`).
6. **Then the module end to end.** Use it as an operator and a customer, and run every ERP stub failure mode. Every defect found goes on `fix/main-issues-e2e`, one commit per fix, with a test that fails before and passes after.
7. **A final review round** over the whole module, with each finding verified against the code before it was fixed (issues 32–43 in `06`).
8. **One integration branch, `submission`,** merging all of the above, so the whole result can be run and reviewed in one place.

## The rules I set for Claude Code

- Follow the Magento 2 coding standard strictly; run `phpcs --standard=Magento2` before proposing code; no new warnings in a changed file.
- Never commit, push, open a PR or add a collaborator without my explicit confirmation for that action. One approval covers one action.
- Nothing on `main`. It stays the baseline the review PRs are compared against.
- No AI attribution in commits or PRs, and no AI tooling files in the repository. Commits are authored by me after review.
- Never use or store the access token from the assignment email.
- Prove, don't assert: a failing-then-passing test for every fix, and in documents say whether a claim comes from a test, the browser or code reading.
- Do not change anything that needs a client decision (window length, `refund_no` ownership, 409 resolution, ERP void).

## Part by part

| Part | Claude Code did | I did |
| --- | --- | --- |
| Setup | Diagnosed containers pointing at a deleted folder and recreated them; found that the seed data had aged out of the 14-day window | Created the private fork and opened PRs #1 and #2; set the branch rules |
| Part 1, review | Read the diffs, callers, tests and git history; wrote temporary tests to reproduce findings against the running stack and the ERP stub, then deleted them; drafted findings and author comments | Set the ranking criteria, reviewed each finding and the ranking, and chose the Part 3 defect |
| Part 2A, design | Drafted the sequence diagram, failure boundaries and epic plan from the FRD | Decided how each FRD contradiction is handled, and what is a proposal versus required |
| Part 2B, architecture | Compared the overview with the code on `main`; drafted the ranked risks, target state and scaling section | Reviewed the ranking and the scaling stages |
| Part 3, fix | Wrote the tests first, showed them failing on PR 2, then wrote the fix | Approved the approach, the branch, the commit and the push (PR #3) |
| Review fixes | Fixed the other PR findings on their own branches | Decided they stay off the Part 3 branch so Part 3 stays small |
| Module end to end | Drove the admin and storefront and the full refund lifecycle against every stub failure mode; fixed each defect with a test | Asked for the end-to-end run, classified each finding as bug or improvement, and declined changes I did not want (for example failure notification emails) |
| Final review round | Claude Code verified each Codex finding against the code and fixed most of them with tests; Codex fixed the rest (the submission token, stricter ERP ids, the Save outcome messages, the receipt PDF) and updated the change report | Asked Codex for an independent review, decided which findings to fix now and which go to next steps, and handed work between the two agents with written handovers |
| Documents | Drafted all six files and the change report | Reviewed and corrected them against the brief, the templates and the setup email |

## How I checked the output

- **Part 1 findings marked *Reproduced* in `01` were reproduced on the running stack**, with actual values quoted (for example `qty_refundable_after = -1`, tax codes `["7","6"]`, two ERP credit notes from one refund). Findings 6, 10 and 11 are marked *Code* or *phpcs* because they come from reading the code or the coding standard, not from a run.
- **Every fix has a test that fails before and passes after,** except browser-only behaviour, which is marked as verified in the browser. The refund form's Save result and submission token also have JavaScript tests (`node --test app/code/Acme/SellerRefund/Test/JavaScript/refund-form.test.cjs`, 8 pass).
- **Suites were run on each fix branch** as its commits were made (`04` and `06` give the before-and-after results).
- **phpcs (Magento2 standard)** on the 82 PHP and template files that `submission` adds or changes against `main`: no errors; new files have no warnings; every changed file has the same or fewer warnings than on `main`, except `Test/Unit/Service/RefundProcessorTest.php` (7 → 9 docblock warnings).

## Where AI was wrong, and how it was caught

1. **An early second-opinion reading of PR 2 got the duplicate-credit-note path wrong.** It said an operator retry opens a second credit note. Reproduction showed an operator retry starts a new outbox job at attempt 1 and sends the original key; the real failure on that path is a crash on an illegal state transition. The duplicate is real only once transient errors are retried, which is why `01` calls finding 4 latent and ranks the over-refund first.
2. **Claude Code overstated `main`.** It first wrote that `main` quarantines an ERP 409 conflict. Reading the code showed `main` marks the refund `failed`, which releases the quantity. Corrected in `01` and `03`, and fixed as issue 9 in `06`.
3. **Claude Code put the first additional fixes on `main`.** I questioned this against the setup instructions, which never ask for `main` to change. They were moved to `fix/main-issues-e2e` before anything was pushed, so the review PRs stay conflict-free.
4. **Claude Code introduced a fatal error** by injecting a form key into a block whose parent already declares one. The browser check caught it within minutes.
5. **Claude Code briefly broke the admin's JavaScript** while forcing a static-file refresh (a trailing newline in `deployed_version.txt`). Found from the console errors and fixed.
6. **Claude Code edited the assignment's Docker tooling** to fix a cache-flush bug. That script is baked into the image, so the edit was reverted and the bug is documented in `06` instead.
7. **The documents were first written without the submission templates.** After I pointed to the setup email, the headings were aligned with `docs/candidate/submission-template/`.
8. **An earlier second review pass of the module was only half right.** Four of its findings held up in the code and were fixed with tests (`06` issues 12–15). Others did not: it claimed ERP creation was not idempotent, which the ERP's `refund_no` deduplication and our stub test disprove, and it proposed native credit memos, which the FRD rules out of scope.

9. **A Codex merge dropped a file.** Merging the fix branches into `submission` deleted the module README. It was found while checking the docs against the branch and restored in a separate commit.

## What I would do differently

Read the submission instructions and templates before writing anything, and decide the branch layout, including the final integration branch, before the first commit. Both cost rework here.
