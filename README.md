# Seller Return & Refund - Take-Home Assignment

A pre-built Magento Open Source 2.4.7-p10 store plus a local ERP Refund API
stub, supplied for the Technical Architect "Seller Return & Refund" take-home.
Everything runs locally in containers by pulling four pre-baked, digest-pinned
images; no build or Composer step is required. The images are private to this
assignment, so you sign in to the registry once with the credentials provided
with your assignment before starting (see Quick start). Begin with the assignment
itself in
[`docs/candidate/candidate-brief.md`](docs/candidate/candidate-brief.md).

## Submission

Written work is in [`submission/`](submission/): code review (01), refund design (02),
architecture review (03), build impact note (04), AI usage (05), and questions and next
steps (06). Two PDFs go with them: the change report with before-and-after screenshots
([`07-change-report.pdf`](submission/07-change-report.pdf)) and the business flow and
architecture proposal
([`seller-refund-architecture-review.pdf`](submission/seller-refund-architecture-review.pdf),
referenced from 03).

`main` is unchanged: it is the baseline the two review pull requests are compared against.
All work is on the branches below.

**Part 3 fix** (over-refund guard on PR 2, finding 1 in `01`): commit
[`c1343a7`](https://github.com/pushpendra07/magento-tha-seller-refund/commit/c1343a7be2e37cd95d8321b2e9658775506420ee)
on branch `fix/pr-02-over-refund-guard`, opened as PR #3 into
`review/pr-02-erp-refund-sync`. Test command:

```bash
git checkout fix/pr-02-over-refund-guard
bin/assignment-cache-flush --all
bin/assignment-test all
```

**Further work, beyond Part 3** (each branch has its own tests):

| Branch | Based on | Contents |
| --- | --- | --- |
| `fix/pr-02-review-fixes` | `fix/pr-02-over-refund-guard` | PR 2 findings 2, 3, 4, 5, 10 and 11 from `01` |
| `fix/pr-01-review-fixes` | `review/pr-01-partial-refund-presentation` | PR 1 findings 6, 7, 8 and 9, and the standards finding |
| `fix/main-issues-e2e` | `main` | Issues 1-41 found on `main` while using and reviewing the running module (listed in `06`), plus improvements to the admin and storefront refund pages |
| `submission` | `fix/pr-02-review-fixes` | Everything together: merges `fix/pr-01-review-fixes` and `fix/main-issues-e2e`, then adds issues 42-43 from `06` (submission token, ERP outcome after Save), stricter ERP credit-note ids and an ID column on the refund grid. This is the branch to run to see the finished module. |

Test command for any of them (replace the branch name):

```bash
git checkout submission
bin/assignment-cache-flush --all
bin/assignment-test all
node --test app/code/Acme/SellerRefund/Test/JavaScript/refund-form.test.cjs   # submission only; Node 18+
```

The integration suite needs unrefunded quantity on the seed orders; run it on a freshly
seeded stack (`bin/reset-assignment --yes`).

## Prerequisites

- Docker Desktop (or a Docker engine) with Compose v2;
- Git; and
- at least 8 GB of RAM available to Docker.

## Quick start

The four images are private to this assignment. First sign in to the registry
with the username and access token provided with your assignment:

```bash
docker login ghcr.io -u <USERNAME> --password <TOKEN>
```

Then start the stack:

```bash
cp .env.example .env
docker compose up -d --wait
```

`up --wait` returns when the whole stack is healthy (the `assignment-ready`
service prints a banner with the URLs). Then:

- Storefront: http://localhost:8080
- Admin: http://localhost:8080/admin
- ERP Refund API stub: http://localhost:8081/health

### Synthetic credentials

All credentials are synthetic, non-production, and for this local assignment
only. Passwords are documented in cleartext on purpose so the exercise can be
run; the platform stores them hashed.

Admin (http://localhost:8080/admin):

| Username | Password | Role |
|---|---|---|
| `admin` | `Assignment7Admin` | Full store administrator |
| `cs_agent` | `CsAgent#2026` | CS operator: view, create, cash-register |
| `finance_user` | `FinanceUser#2026` | Finance: the above plus retry and audit |
| `refund_admin` | `RefundAdmin#2026` | Refund administrator: all refund privileges |

Storefront customer (http://localhost:8080):

| Email | Password |
|---|---|
| `refund.customer@example.com` | `RefundCustomer#2026` |

The graduated operator accounts exist so you can see how the refund controls
are gated by ACL; sign in as whichever role fits what you are exercising.

## Helper scripts

Bash scripts are in `bin/` with PowerShell (`.ps1`) twins for Windows.

- `bin/assignment-status` - report stack health: service states, the web health
  JSON, the stub's recorded-refund count, logs for anything unhealthy, and the
  OpenSearch preflight if search is down.
- `bin/assignment-test [unit|integration|smoke|all] [--filter X]` - run the
  module's bounded test suites in the web container.
- `bin/reset-assignment [--yes] [--data-only]` - reset to a clean starting
  point; `--data-only` re-seeds data and clears the stub without a full teardown.
- `bin/assignment-cache-flush [flags]` - clear Magento caches / generated code /
  static after editing the module (see the matrix below).

### Edit / rebuild matrix

The refund module is bind-mounted into the web container and the image runs in
developer mode, so edits are visible without rebuilding. How much you flush
depends on what you changed:

| You changed | Command |
|---|---|
| PHP logic | nothing (developer mode picks it up) |
| `etc/*.xml`, `.phtml` templates, email templates | `bin/assignment-cache-flush` |
| JS / LESS | `bin/assignment-cache-flush --static` |
| `di.xml`, constructors, plugins | `bin/assignment-cache-flush --generated` |
| `db_schema.xml` | `bin/assignment-cache-flush --upgrade` |
| Switching branches | `bin/assignment-cache-flush --all` |

After a JS/LESS change, do a hard reload in the browser.

## Repository layout

| Path | What it is |
|---|---|
| `docs/candidate/` | The assignment: brief, functional requirements (FRD), architecture overview, and starter files for your written work. |
| `docs/pull-requests/` | Descriptions of the two supplied review branches you assess in Part 1. |
| `docs/build-notes/` | Runtime provenance, licenses, troubleshooting, and the calibration log. |
| `submission/` | Where you place your completed written work. |
| `app/code/Acme/SellerRefund/` | The refund module you review and extend. |
| `erp-refund-stub/` | The local Node.js ERP Refund API stub. |
| `seed/` | The assignment data seeder (`Acme_AssignmentSeed`). |
| `bin/` | Helper scripts (see above). |
| `compose.yaml` | The container stack definition. |
| `build/` | The image build/bake pipeline (candidates never need this). |

## The assignment

Read [`docs/candidate/`](docs/candidate/) for the full brief, functional
requirements, and architecture overview. Start with
[`docs/candidate/candidate-brief.md`](docs/candidate/candidate-brief.md), review
the two branches described in
[`docs/pull-requests/`](docs/pull-requests/), and place your written work in
[`submission/`](submission/) (starter files are in
[`docs/candidate/submission-template/`](docs/candidate/submission-template/)).

## Runtime details and troubleshooting

See [`docs/build-notes/`](docs/build-notes/): component versions and image
digests in
[`runtime-and-licenses.md`](docs/build-notes/runtime-and-licenses.md), and
common issues and remedies in
[`troubleshooting.md`](docs/build-notes/troubleshooting.md).
