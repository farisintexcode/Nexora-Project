# Nexora

Debt Collection Management System. Laravel 13 modular monolith with Livewire 4, Blade, Tailwind CSS 4 and Livewire's bundled Alpine; PostgreSQL, Redis and private S3 storage. DRMS by Senja Bina is a historical analysis reference, not this product.

## Requirements and traceability

Read these before implementing any feature:

- [Plan.md](Plan.md): architecture, stack, security, integrations, testing and roadmap.
- [Module.md](Module.md): M01–M14 functional contracts and business rules.
- [Prototype.md](Prototype.md): UI01–UI70 screen and interaction contracts.
- [Database.md](Database.md): canonical entities, fields, relationships and constraints.

These four documents remain the single source of truth. Confirmed C scope overrides historical reference O/U evidence. Implementation notes: [review/dependencies](docs/foundation-review.md), [architecture](docs/architecture.md), [all 66 entity dispositions](docs/entity-register.md). Notes are tracking aids, not replacement requirements.

Customer means `debtors` / `Debtor`, created automatically from tasks; Rider means eligible `staff` linked one-to-one through `users.staff_id`. Do not introduce duplicate customers/rider_profiles/task_assignments/attachments/task_verifications tables.

## This increment

- Official Laravel 13 skeleton and included Composer/npm lockfiles.
- Sail PHP 8.4, PostgreSQL 17, Redis 7.4 and a local Mailpit sink configuration.
- Session login/logout, throttling, password broker/recovery/reset, invitation-only activation endpoint and own password changes. Approved terms/privacy configuration is required for activation. Public registration is absent.
- System Admin/Administrative/Rider selectable role bundles, legacy Admin compatibility, granular permissions, active account/staff checks, no role-name bypass. Employment positions are separate reference values.
- Dependency-ordered identity/reference migrations; UUID v7 public references, PostgreSQL checks/partial indexes/FKs; encrypted staff contact casts.
- Append-only audit, outbox/deduplication persistence and notification schema. M04 now publishes only import events through its durable outbox dispatcher.
- Responsive Admin and mobile Rider layouts, reusable Blade inputs/buttons/feedback, Livewire account settings and real customer/task KPIs. Navigation labels remaining modules.
- S3 private disk configuration, separate provider contracts for email, WhatsApp messaging and calling; no selected or enabled provider.
- Pulse package schema and permission-protected access; recording disabled by default. Sentry disabled without a DSN and configured for minimal sanitized incident signals. AWS/CloudWatch infrastructure is later deployment work.
- PostgreSQL Pest/PHPUnit tests, desktop/mobile Playwright tests, GitHub PR template and a GitLab CI template.
- M10 Rider / Staff Management and Attendance: searchable personnel, controlled invitations, versioned work policies, explicit calendars, own clock-in/out/history, scoped Admin register/exports and independently approved corrections. Attendance links are available from staff profiles; performance reporting follows M09.
- M03 Assignment Batch Management: client-scoped active/archive register, metadata creation/editing, seven reference types, schedule/routing fields, version conflict checks, confirmed archival and audited, separately authorized reopening. Task intake, allocation and history link to M04–M07; general exports await M09.

Customer Management (M13) has been removed. `debtors` remains the internal customer record behind tasks, calls and communications, created automatically when a task or uploaded row uses a new customer reference. Customer categories, the customer import type and the customer pages no longer exist.

Assignment batches are available at `/admin/assignments` to active `admin.access` + `assignments.view` users, restricted to current active client scopes. Creation/editing/archival require `assignments.manage`; reopening additionally requires `assignments.reopen`, a reserved grant excluded from ordinary Admin bundles. Archived assignments are read-only and retain their external-reference uniqueness and history. Client identity cannot change. Types cannot change once task/import history exists. Batch status changes check the locked record version and work disposition; terminal imports with unreconciled accepted rows block archival. No tasks, upload results or operational totals are fabricated. Traceability and future writer lock requirements are recorded in [architecture](docs/architecture.md) and [module disposition](docs/foundation-review.md).

- M05 Task Management & Allocation: customer account/property/party foundations, manual task intake/editing, scoped monitoring, immutable source/history, exact single/selected operation previews, queued allocation/reassignment/unassignment/cancellation, reconciliation and redacted result downloads. M06 field execution and M07 independent review are implemented.

## Administration and Human Resources

M01/M10/M12 and UI30/31/32/54 now use three selectable roles: **System Admin**, **Administrative**, **Rider**. Run `php artisan db:seed --class=RolePermissionSeeder` after the normal migration step to add the new bundles. Seeding preserves existing roles, memberships and custom grants. Legacy Admin accounts retain access and are never offered for new registrations; editing their equivalent role selection preserves those grants. The current forward migration makes the historical staff position optional; identity tables and links are unchanged.

| Bundle | Access |
|---|---|
| System Admin | Operational baseline plus roles.manage, users.invite and integrations.manage; existing policies/scopes still apply. Other reserved grants remain explicit. |
| Administrative | Operational baseline excluding reserved grants, Rider-only grants, attendance.configure, monitoring.view and audit.view. No permission-management authority. |
| Rider | rider.access, tasks.view, tasks.submit, attendance.clock, attendance.view_own; staff eligibility and task ownership still apply. |

**Add New Staff** has Staff Information, System Account Setup and Record Information. Required staff ID, first/last name, email, role and staff status are validated. Login creation defaults on and requires users.invite. System Admin grants and explicit role changes require roles.manage. Staff registration, linked invited account, role assignment and audit are transactional. Staff status and account status are separate; no scopes are granted automatically. Explicit role changes replace the role and clear direct grants with audit, while equivalent legacy selections preserve existing access.

Users choose and confirm a personal password through the existing expiring single-use invitation, with approved terms acceptance. Invited accounts cannot sign in, use direct dashboards/Livewire actions or be manually enabled. Successful activation regenerates the session and opens the authorized Admin/Rider dashboard. Recovery and Account Settings continue to use existing mechanisms. No temporary password is displayed. Inactive staff may be provisioned but must be enabled and reinvited before activation. The array mailer does not deliver invitations; local Mailpit or an approved transport is needed. Log transports remain blocked.

The directory shows email, role, separate staff/account status and automatic Created At. Search and role/status/creation-date filters retain pagination. Created At/Updated At are read-only in Asia/Kuala_Lumpur; UTC storage and historical appointment_date are preserved. Availability actions require a deactivation reason and resolved outstanding allocations. Account state is managed separately in Edit Staff. Own access reduction and removal of the last active permission administrator are refused; history is retained.

Manual verification: sign in with authorized grants, open Human Resources → Add New Staff, choose each role and save; inspect linked invited account and audit. Follow the captured invitation, check password confirmation and dashboard redirect, then confirm the link cannot be reused. Try the same privileged grant as Administrative/Rider. Test list filters, creation-date boundaries, status changes and outstanding-task refusal. Verify attendance, assignment and independent task review using existing fixtures. Never use production records for this check.

Validation for this rebuild: 184 relevant regression tests / 970 assertions against PostgreSQL 17; 79 HR/authentication tests / 349 assertions in PHP 8.4 Docker; six desktop/mobile Playwright cases. Pint, Blade compilation and the Vite production build passed. Test runs used isolated disposable databases, synthetic identities and runtime secrets.

Focused HR/account checks use the configured disposable PostgreSQL test database:

```bash
php artisan test --compact tests/Feature/AdminHrAccountTest.php tests/Feature/StaffManagementTest.php tests/Feature/StaffInvitationTest.php tests/Feature/Foundation
npx playwright test tests/Browser/admin-hr.spec.ts
```

Browser checks require the existing guarded `BrowserTestSeeder` against a disposable `*_e2e` database, a runtime `DCMS_E2E_PASSWORD`, and `PLAYWRIGHT_BASE_URL`. No passwords are committed or provisioned by normal seeding.

## HR and Leave Management (8 November 2026)

Employment Position is no longer selectable, required or displayed in staff HR screens. The three System Roles remain authoritative. Historical positions are retained; the forward migration makes position_code nullable without dropping data. Apply with `php artisan migrate --no-interaction`; never reset a populated preview to install this change.

Human Resources → **Leave Types** is the configuration entry point (`/admin/leave/types`). It reuses existing tables/actions and requires attendance.configure plus the existing admin/attendance visibility grants. Leave approval separately requires attendance.approve_correction; Administrative does not acquire approval by role name. Normal seeding intentionally supplies no organization's leave policy. For labelled local examples, run `php artisan dcms:seed-leave-samples <authorized-email> --no-interaction`; examples start inactive, with optional explicit `--activate`. Existing configurations are not overwritten and production/staging is refused. These are not statutory leave entitlements.

Active types appear in Apply for Leave. Without active types, the page provides actionable guidance instead of an unusable form. The workflow retains explicit calendars, full working-day duration, validation, exact review, idempotent submission, own history, independent immutable decisions and policy-controlled pending cancellation. Approved leave appears in attendance without creating punches or rewriting schedules; overlapping clock-in and correction of the approved calendar are blocked. Annual balances/accrual/carry-forward, half-day requests and approved withdrawal are unsupported. Private supporting documents retain S3/scanner requirements. Leave notifications are in-app; invitations and password recovery retain their existing email flows.

Panduan Bahasa Melayu: [System Admin](docs/manual-system-admin-ms.md), [Administrative](docs/manual-administrative-ms.md), [Rider](docs/manual-rider-ms.md), [Pembangun, Mailpit dan senarai semak ujian](docs/manual-pembangun-ms.md).

Validation on 8 November 2026: the focused PostgreSQL/Docker run passed 104 tests / 444 assertions (leave policies, requests, staff, linked accounts and invitations). Related authentication, authorization, identity, attendance, task assignment, Rider access, verification and task-flow regressions passed 134 tests / 738 assertions; one existing optional EXIF test was skipped because its decoder is unavailable in the test image. Tests use disposable databases; the populated preview received only the forward nullable-position migration and explicitly activated labelled local sample leave policies.

Browser verification covers desktop/mobile staff creation for all three roles, legacy staff editing, leave-type availability, Rider apply/review/cancel/history, independent approval and rejection, dark mode and responsive overflow. The final combined invocation produced 13 passes and one desktop calendar-interaction timeout; after the helper correction, that desktop rejection journey passed its focused rerun (1/1), leaving all 14 journeys verified. Test helpers wait for Livewire edit state, distinguish the calendar month from its live help text, and select the date range before changing status to avoid racing filter updates. Pint, Blade compilation, migration status, whitespace checks and the Vite production build passed.

## M04 Excel imports and mapping

Open **Excel imports** in the Admin navigation (`/admin/imports`). Upload Task turns workbook rows into tasks under a chosen active assignment, creating customers and accounts for references it has not seen; the Assignment Batch contract remains in the workflow. Imports support encrypted local or S3 storage, optional scan gating, automatic rule-based mapping from row 1 of the first sheet, from header names and the aliases in `config/imports.php`, manual correction, explicit Ignore, reviewed transforms, masked before/after preview, exact confirmation, queued atomic writes, cancellation, retries, reconciliation and an audit timeline. Row outcomes and reasons appear in Row preview. History is restricted to effective client scope.

Permissions: `admin.access` + `imports.view` for history/review; `imports.map` for upload, mapping, profiles and cancellation; `imports.confirm` for commit/retry. Target writes also recheck existing Task/Assignment permissions. Template versions and canonical bindings are immutable; arbitrary executable transforms and workbook-defined database bindings are never accepted.

Enable the approved optional ClamAV service and import worker in a Docker/WSL shell:

```bash
COMPOSE_PROFILES=imports ./vendor/bin/sail up -d
./vendor/bin/sail artisan queue:work redis --queue=imports --timeout=120 --tries=3
# Run in another terminal for ongoing outbox publication:
./vendor/bin/sail artisan schedule:work
# Or publish pending events once:
./vendor/bin/sail artisan imports:dispatch
```

`IMPORT_DISK` selects `local` (default, `storage/app/private`) or `s3` for source workbooks. `IMPORT_SCAN_REQUIRED=false` skips ClamAV outside production and records the document as `skipped`; production always scans. Set `IMPORT_SCANNER_HOST=clamav` and `IMPORT_SCANNER_PORT=3310`; no scanner port is exposed to the host. ClamAV needs sufficient memory and healthy definitions before intake. The optional development image uses `stable`; pin a reviewed image digest before production. Source objects are application-encrypted and SHA-256 checked. Livewire temporary uploads must also remain private, encrypted at rest and subject to temporary-file cleanup. Queue publication failures remain durable and return a nonzero command status. Upload Task disables workbook selection only when `IMPORT_DISK=s3` and the bucket is unconfigured.

Initial configurable limits: 10 MiB source, 2,000 data rows, 100 columns, 10 sheets, 64 MiB expanded XLSX; header selection is rows 1–100. These are implementation bounds, not historical workbook assumptions. XLSX/XLS parsing uses PhpSpreadsheet 5.10. Macro/embedded/external content, executable sheets, formulas, encrypted/unreadable workbooks and exceeded limits are rejected. Dates must be ISO `YYYY-MM-DD`; identifiers should be text. Existing zero-padding number formats are preserved, but precision already lost in Excel cannot be reconstructed.

Task rows match customers by exact reference and accounts by exact number; Batch matching is the exact **required external reference**. Preview exercises the existing save actions inside a rolled-back savepoint; target revisions and accepted row payloads are then frozen. Processing rechecks current permissions/scopes and revisions and commits each target, row disposition and count atomically. Duplicate submissions reuse an existing intake; a failed frozen attempt can be uploaded again as a separate fresh review, preserving the previous attempt. Retrying a stale revision does not silently refresh it. Only mapped optional fields are updated; the first Customer contract covers reference, party type, name, status and optional identity, without replacing contacts, addresses or categories.

Account/task import adapters await approved source contracts, including G02; payment/databank adapters also await their modules and G05. Task/account target columns now have real foreign keys and remain null-constrained until the corresponding adapters are activated. Initial system template definitions have a nullable creator because normal seeding does not invent a user; user-created versions always record the creator. Source retention is not automatically shortened or deleted without the approved retention policy.

Traceability: Plan.md intake/mapping/security/queue contracts; Module.md M04; Prototype.md UI14, UI15, UI57 and UI58; Database.md import_fields, import_templates, import_mapping_profiles, import_batches, import_column_mappings, import_rows, documents and existing audit/outbox/notification entities.

## M05 Task Management & Allocation

Amounts owed and payment claims: every task has append-only encrypted balance records in `financial_snapshots`, from Upload Task or Create Task. Customers pay the client directly. A Rider enters the amount paid and adds a Payment Evidence photo; a reviewer confirms the photo matches before approving. Equal clears the balance, lower needs a part-payment confirmation, and higher cannot be approved. Reports count approved claims only. A balance or claim is never a receipt.

Rider proof storage: `PROOF_DISK` (`s3` or `local`) and `PROOF_SCAN_REQUIRED`. Production always scans.

Deleting a task: admins with `tasks.delete` can delete tasks with no Rider and no recorded work, from Manage Task. The record is hidden and kept, because task history is immutable. Started work uses Cancel.

Open **Task monitoring** at `/admin/tasks`. Create a manual task by entering a customer reference and account number; new references also need a customer name, and the customer and account are created automatically. An existing account must already belong to that customer. Choose the batch, priority, planned visit, due time and encrypted instructions. Account/property data remain separate from contact/billing addresses and financial snapshots. Account writes require `tasks.create`; task pages require active Admin access and `tasks.view`/`tasks.monitor`. Creation/editing additionally require their existing task grants.

Task detail shows current Rider, due/visit times, immutable source provenance, metadata and task/allocation history. Original customer/account/batch/type identity is fixed; cancel and create corrected work to change it. Priority, schedule and instructions remain editable on nonterminal, non-submitted work with an exact version check. Blank due time inherits the end of the batch due day in the configured business timezone. No manual IWK key, payment receipt or completion approval is invented.

**Assign Task** applies straight away: **Assign to Rider** shows a success or failure popup and returns to Manage Task, with no separate preview page. Unassign and Cancel ask for confirmation first. The text below describes the underlying checks, which still run.

Select one task from its detail, or explicit checkboxes in monitoring, then review **New / Replace / Reassign / Unassign / Cancel**. Selection is limited to 200 tasks in one active batch. New excludes already assigned work; Replace/Reassign require an existing current Rider and incomplete work; Unassign closes the current allocation; Cancel retains the task/source/history. Receiving Riders require an active eligible staff record, active Rider login and a current client scope. Selection never grants scope automatically. `tasks.assign` authorizes New, `tasks.reassign` authorizes replacement/reassignment/unassignment, and `tasks.cancel` authorizes cancellation. Submitted/completed/cancelled work is protected. Every preview shows exclusions and exact counts, and confirmation rechecks task/allocation versions.

Operations are processed from a durable outbox on the existing **default Redis queue**:

```bash
./vendor/bin/sail artisan tasks:dispatch
./vendor/bin/sail artisan queue:work redis --queue=default --timeout=120 --tries=3
# Keep the existing scheduler running for ongoing imports/tasks outbox publication.
./vendor/bin/sail artisan schedule:work
```

Queued means accepted for processing, not applied. Client/batch/operation/task locks, eligibility checks, immutable confirmed selections and atomic item outcomes protect replay and concurrency. Interrupted jobs preserve applied changes and fail remaining items; failed items require a new preview. Redacted result CSVs contain only public task IDs and outcome codes. Pending operation items block batch archival.

Assign Task selects by task ID within the chosen assignment: Single (12), Selected (12, 15, 18), Range (From 10 - To 20) or Range and Selected (From 10 - To 20, plus other IDs such as 25). A task ID such as FG001 comes from the workbook or Create Task and is read by its trailing digits, so FG001 is 1 and DT2304 is 2304. M06 supplies Rider task execution, visits/business data/proof and checklist-derived filters; M07 supplies immutable submissions and independent verification. M06/M07 actions are now available subject to their grants and infrastructure gates. General task exports belong to M09; operation-result CSV is available now. Real Redis/Docker execution remains an integration gate on this native host.

Traceability: Plan.md task contracts and Phase 3; Module.md M05 and M06 account prerequisites; Prototype.md UI13/UI16/UI17/UI18/UI19/UI24/UI55/UI56; Database.md properties/debt_accounts/account_parties/tasks/task_allocations/bulk_operations/items/task_status_histories plus existing audit/outbox/deduplication storage. See the updated architecture and entity register for disposition.
## Local setup with Sail

On Windows, install Docker Desktop with WSL2 integration and use a WSL shell. This workspace's initial machine has neither Docker nor WSL; installing them is a host prerequisite, not an application code change.

Use PHP 8.4 + Composer 2 to install dependencies, or bootstrap Composer through Sail's official image from the project root:

```bash
cp .env.example .env
docker run --rm -u "$(id -u):$(id -g)" \
  -v "$PWD:/var/www/html" -w /var/www/html \
  laravelsail/php84-composer:latest composer install
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
# Set DCMS_SEARCH_KEY in .env to a separate base64-encoded random 32-byte secret.
# Generate locally: php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail npm ci --ignore-scripts
./vendor/bin/sail npm run build
./vendor/bin/sail artisan dcms:bootstrap-admin you@example.test --name="Your name"
```

### Setting up on Laragon (no Docker)

These settings work on a development computer without Redis, S3 or ClamAV. They differ from `.env.example`, which targets Sail.

```bash
cp .env.example .env
composer install
php artisan key:generate
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"   # put the result in DCMS_SEARCH_KEY
```

Then set these in `.env`:

```dotenv
APP_ENV=local
DB_HOST=127.0.0.1
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local
MAIL_MAILER=array
IMPORT_DISK=local
IMPORT_SCAN_REQUIRED=false
PROOF_DISK=local
PROOF_SCAN_REQUIRED=false
```

Enable `pdo_pgsql`, `zip`, `gd`, `intl` and the `xml` extensions in `php.ini`, and set `upload_max_filesize` and `post_max_size` to at least `10M`. Then finish the setup:

```bash
php artisan config:clear
php artisan migrate --seed
npm ci --ignore-scripts
npm run build
php artisan dcms:doctor
```

**After every `git pull`,** run `composer install`, `php artisan migrate`, `npm run build`, `php artisan config:clear` and `php artisan dcms:doctor`. `.env` is not shared through git, so a teammate's computer can behave differently even with the same code.

### If something does not work, run `php artisan dcms:doctor`

The doctor checks PHP and its extensions, the secret keys, database, migrations, reference data, role permissions, file storage, the malware scanner, Redis, the queue and page styles. It lists each problem with what to do, without printing secret values. `php artisan dcms:doctor --fix` grants any baseline permissions missing from the Admin and Rider roles, which happens when a role was created before those permissions existed.

| What you see | Cause | Fix |
|---|---|---|
| Upload fails: "The malware scanner is not reachable" | Scanning is on but ClamAV is not running | Start ClamAV, or set `IMPORT_SCAN_REQUIRED=false` and `PROOF_SCAN_REQUIRED=false` on a development computer |
| Upload fails: "DCMS_SEARCH_KEY is not set" | The key is empty in `.env` | Generate a key as shown above |
| "Mapping permission is required to upload workbooks" | The Admin role is missing `imports.map` | `php artisan dcms:doctor --fix`, then sign in again |
| Upload fails: "PHP zip extension is not enabled" | `zip` is off in `php.ini` | Enable it and restart the web server |
| The file picker fails on larger files | PHP upload limit is too low | Raise `upload_max_filesize` and `post_max_size` |
| Column or table "does not exist" errors | Migrations have not been run | `php artisan migrate` |
| Commit or assign stays waiting | A real queue is configured with no worker | Run a worker and `php artisan schedule:work`, or use `QUEUE_CONNECTION=sync` on a development computer |
| The page has no styling | Assets have not been built | `npm ci --ignore-scripts` and `npm run build` |

If `IMPORT_SCAN_REQUIRED`, `PROOF_SCAN_REQUIRED` or `PROOF_DISK` is missing from `.env`, a computer with `APP_ENV=local` skips scanning and stores proof locally. Every other environment scans and uses S3 by default, and production always scans whatever the setting says.

The bootstrap command prompts for a strong password and only runs in `local` before the first user exists. It creates System Admin plus explicit roles.manage/users.invite grants, with audit. No accounts or passwords are seeded by the normal DatabaseSeeder. It grants no client portfolio access. Existing accounts and role bundles do not acquire these reserved grants automatically.

The Staff Management workspace requires admin.access and riders.manage. The Administration and Human Resources section above supersedes the earlier separate-invitation-only flow. New account creation additionally requires users.invite; System Admin grants require roles.manage. Existing account login emails and client scopes remain independent of employment metadata.

The approved policy configuration supplied by the project owner is `DCMS Staff Terms v1.0` and `https://dcms.intexcode.com/privacy-policy`. The URL's live availability was not verified. Invitations use expiring, single-use password-broker tokens and send after the account transaction commits. The native preview uses the array mail transport: preparation is explicitly reported as local capture, not delivery. Configure Mailpit or an approved SMTP transport to receive messages; `MAIL_TIMEOUT` defaults to 15 seconds. Log mail transports, including failover to logs, are blocked for invitations to avoid recording activation secrets. Transport acceptance is not proof of inbox delivery.

Customer search requires `DCMS_SEARCH_KEY`, independent of `APP_KEY`. Keep it stable and private: changing it requires rebuilding all customer HMAC search tokens and identity/contact fingerprints before search resumes. Search text stays out of URLs. Full identifiers require the separate `customers.view_sensitive` grant, excluded from ordinary Admin bundles; masked identifiers are the default.

Open the configured APP_URL at `/login` or `/rider/login`. Mailpit dashboard uses local port 8025. Database/Redis/Mailpit ports bind only loopback. Change local default database credentials before shared use. Keep `.env`, test secrets, vendor and generated assets out of Git.

Run `key:generate` only for a new environment; preserve existing encryption keys. Application time is UTC; display/calendar policy uses `DCMS_BUSINESS_TIMEZONE=Asia/Kuala_Lumpur`. Set approved `DCMS_TERMS_VERSION` and HTTPS `DCMS_PRIVACY_URL` before inviting users. Production requires approved domain/TLS, secure session cookies, Secrets Manager/IAM and separate runtime/migrator DB roles.

The ordinary Admin seed excludes role/scope administration and independent template/correction approval. These separate grants require deliberate authorization. Seeders create initial bundles only and preserve subsequent permission edits. Production grants require business sign-off; never infer privileges from position labels.

For local queue work as modules arrive:

```bash
./vendor/bin/sail artisan queue:work redis --queue=default,imports,exports,email,whatsapp,webhooks,media --timeout=120 --tries=3
./vendor/bin/sail artisan schedule:work
```

Queue retry_after is 180 seconds and dispatch uses after_commit. Long-running imports/exports must define bounded job timeouts/backoff/idempotency. Production needs independent fair worker capacity and separate queue/cache Redis services. S3 has no local fake public bucket: configure private AWS storage or use Storage fakes in tests until Document quarantine/scanning and signed access are implemented.

## Verification

```bash
cp .env.testing.example .env.testing
# Supply a fresh test APP_KEY. Sail initializes the separate 'testing' database.
# Also supply a distinct DCMS_SEARCH_KEY in .env.testing.
./vendor/bin/sail artisan key:generate --env=testing
./vendor/bin/sail test
./vendor/bin/sail npm run build
./vendor/bin/sail composer validate --strict
./vendor/bin/sail composer audit
./vendor/bin/sail php vendor/bin/pint --dirty --format agent
```

Tests require PostgreSQL. Do not replace it with SQLite: partial indexes, category FKs, inet/JSONB and immutable audit guards are part of the application contract. Never point tests at a production database. Pest uses PHPUnit underneath; no duplicated suite is required.

Browser smoke checks can run against an already running app. Browser fixtures pace test starts with one worker to respect the real five-logins/minute policy; requests and authentication protections are not intercepted or disabled:

```bash
npm ci --ignore-scripts
npx playwright install chromium
PLAYWRIGHT_BASE_URL=http://localhost npm run test:e2e
```

Credential-based cases skip unless their `DCMS_E2E_ADMIN_EMAIL`, `DCMS_E2E_RIDER_EMAIL` and `DCMS_E2E_PASSWORD` are supplied. For full browser acceptance use a dedicated local/test database ending `_e2e`, generate a temporary runtime password, migrate it, and explicitly run BrowserTestSeeder with that password in the environment. It provisions `admin@example.test` and `rider@example.test`; both accounts use the runtime secret. Start the app against that database, supply those email variables to Playwright and discard fixtures afterwards. The GitLab template demonstrates the full setup. No test sends to real customers.

Verified through 17 October 2026:

- Native PHP 8.3.30 (supported minimum) and portable isolated PostgreSQL 17.11: 169 tests / 710 assertions cover foundation, customers, staff, invitations, assignment batches, M04 imports and M05 tasks/accounts/allocation, including authorization, encryption, stale writes, archive/reopen boundaries, work-disposition guards, constraints and audit rollback. A dashboard fixture now aligns its grant with its frozen test clock rather than PostgreSQL wall-clock time; application scope rules are unchanged.
- Twenty-two Chromium Playwright workflows passed across desktop and mobile (including repeated task acceptance after the multi-account policy and responsive date-control fixes): public entry, workspace access, synthetic login/account/logout, customer create/edit/category/history/search, staff create/edit/availability/activity/search, assignment create/edit/confirmed archive/read-only history/search, import history/mapping/masked preview/CSV download/client alias publication, customer account creation, manual task create/edit, exact allocation preview/queued confirmation/cancellation/result download, and theme/navigation. Navigation checks cover OS/manual preference persistence, menu search/empty feedback, parent/child active states, compact persistence/tooltips, mobile focus/Escape/inertness, retained Account/Sign out locations, and viewport widths from 320px to 1280px. Staff checks also confirm that the account access bundle requires explicit selection.
- Frontend production build, Blade view cache and config cache/clear passed.
- Composer install resolved Laravel 13.36, Livewire 4.4, Spatie Permission 8.3, Sail 1.69 and Pest 4.7. Exact versions are in lockfiles. Composer/npm audits reported no advisories at installation.

The ignored `.env` on the initial machine uses native loopback PostgreSQL port 55432 and file sessions/cache solely for local verification. `.env.example` uses the agreed Sail/Redis services. PHP's pdo_pgsql and zip extensions were enabled per process during native tests; enable it in your own PHP runtime or use Sail. Browser runtime checks used an explicitly enabled extension as `artisan serve` does not forward `php -d` options to its child server.

Pending: Docker Compose validation and PHP 8.4/Sail boot, Redis-backed session/queue/cache integration, configured GitLab runner/mirror execution, actual S3 access/scanning, AWS/Pulse/Sentry operational activation and provider capability tests. Passing native tests does not claim these integrations are operational. Foundation code is ready for the next module; these remain environment/integration acceptance gates.

## GitHub, GitLab and deployment

Local Git uses `main`; this increment does not create a commit, push or deployment. GitHub remains canonical and GitLab receives a read-only pull mirror. Configure a protected trusted `GITHUB_CANONICAL_REPOSITORY`, protected branches and required GitHub reviews before activating `.gitlab-ci.yml`. The template verifies the mirrored SHA against GitHub main, installs locked dependencies, tests PostgreSQL, builds assets and runs browser checks. It has no deployment stage. Verify it on the chosen runner before relying on it.

Local image tags are development baselines. Resolve and commit approved image digests for production during compatibility/deployment work. AWS ECS/ECR/RDS/private S3/KMS/Secrets Manager, CloudWatch, OIDC, scanning/signing, migrations/cutover approvals and restore drills follow Plan.md. The owner supplied the privacy-policy URL on dcms.intexcode.com; domain/TLS provisioning and messaging-provider activation remain deployment work. DRMS's domain must remain untouched.

## Development rules

Nexora uses placement-aware full and icon-only artwork from `public/images/brand`: light and dark SVG lockups, PNG fallbacks, and padded white-backed app/favicon exports. The shared `x-brand` component selects the correct variant for the surface and compact layout. Branding paths live in `config/dcms.php`; centralized theme colors are white #FFFFFF, navy #023047 and orange #FB8500. See `public/images/brand/README.md` for asset usage. Obsolete product-logo assets are removed from public storage.

The owner-approved rebrand updates names in Plan.md, Module.md, Prototype.md and Database.md without changing their functional requirements. Existing internal `dcms` configuration/command names, `DCMS_*` environment variables, database identifiers, browser preference keys and Composer package identifier remain stable. `APP_NAME`, `VITE_APP_NAME` and the mail sender display name present Nexora. Explicit legacy session-cookie/cache/Redis prefixes preserve application continuity when changing the display name. Keep existing prefixes when updating another environment. The previously approved `DCMS Staff Terms v1.0` and privacy-policy URL remain exact approved references; rebranding does not rewrite legal versions or provision a new domain. Implemented reports and communication use the shared Nexora identity; the supported report families and operational activation requirements are listed below.

Historical branding verification was completed on 16 October 2026. The current Nexora assets, appearance checks and rendering guidance supersede that earlier presentation. Local preview continues on port 8000 with its existing database and account controls.

The UI/UX redesign extends this system with `x-page-header`, `x-icon`, `x-badge`, `x-empty-state`, `x-loading` and a native-dialog `x-confirm-action`. Shared surfaces, metrics, tables, form sections, tabs, mobile navigation, password visibility controls and reduced-motion rules live in the same stylesheet/components. The desktop sidebar groups upcoming modules separately from usable navigation. Error pages cover 403/404/419/500 without exposing exception details. Bulk category changes require a confirmation of the explicit selection before calling the existing action; other validation and transaction rules are unchanged.

The navigation redesign uses `x-sidebar`, `x-sidebar-item`, `x-sidebar-group`, `x-sidebar-submenu`, `x-sidebar-tooltip` and `x-theme-toggle`. The rounded desktop sidebar expands by default, remembers compact mode and shows named tooltips on hover/focus. Menu search filters only permission-rendered navigation; Customer management groups Customers and Customer categories with active parent/child states. Unimplemented modules remain non-actionable and explicitly Planned. The Admin header retains Account/user information/Sign out; Rider retains header Sign out and bottom Account. Mobile drawers support focus containment, background inertness, Escape, backdrop dismissal and left swipe to close.

Appearance initialization in `partials/ui-preferences.blade.php` runs before styles render, follows the operating system until a manual light/dark choice, and stores only UI preferences in browser storage (`dcms.theme`, `dcms.sidebar`). Storage failures do not prevent in-page theme switching. Tailwind's class-driven `dark` variant and centralized surface/ink/semantic overrides in `resources/css/app.css` adapt existing forms, cards, tables, authentication and feedback. Yellow stays #FCDE08 with black text on primary actions. Reduced-motion preferences limit transitions. This owner-requested presentation update follows Prototype.md's shared-shell/navigation contract, Module.md permission boundaries and Plan.md's existing frontend stack; routes, role bundles, business actions and database schema are unchanged.

Dashboard traceability: Prototype.md UI04/UI07, Module.md M02/M13, Plan.md scoped dashboard/security contracts and Database.md debtors/clients/user_client_scopes/audit_logs. `CustomerOverview` reuses current customer permission and client scopes for total/active/inactive counts, permitted client count, seven local calendar days of record creation, five latest customers and five recent customer audit events. This is the available customer slice of the dashboard, not completion of M02's task/verification/attendance metrics. The Rider home explains pending field tools rather than supplying simulated tasks or attendance actions. No business write action, permission bundle, schema or provider integration is changed by this redesign.

Redesign verification on 16 October 2026: 71 PostgreSQL-backed tests / 265 assertions, eight Chromium desktop/mobile workflows, production asset build, Blade compilation and Pint passed. Additional browser checks exercised password visibility, 320px/430px forms, mobile menu focus/Escape restoration, native confirmation cancel/submit, immediate bulk-action success feedback and branded 404 rendering. Livewire success/error feedback is rendered inside page components so it updates after server actions. The original local preview database was preserved; acceptance used a separate synthetic database.

Keep Livewire/controllers thin; use validated inputs, explicit action permissions plus record policies/client scope, transactions and locks for critical writes, reusable UI and scoped queries. Every major change must identify Plan section, Module ID, UI ID and Database table/constraint in its PR. Highlight technical conflicts before changing intended behaviour. Read [known findings](docs/foundation-review.md) before implementing bulk selection, legacy imports or databank. Tests accompany each module. Avoid placeholder financial/attendance rules, fabricated KPIs and external messages triggered without a configured business rule.


## M06 Rider Execution & Site Visits

Open **Rider → My Tasks** at /rider/tasks. Current allocation, an active eligible Rider login, tasks.view/tasks.submit and an effective client scope are required. Start records an atomic history event. Save visits with availability, site status, contact result, outcome, separate visit/collection/customer remarks, applicable coded business details and due follow-ups. Customer identification numbers and unrelated records are hidden. Admin task detail and Customer → Visits / Collection show scoped recorded activity.

Owner-approved choices and policies are in reference_values, seeded by RiderReferenceSeeder without overwriting later master-data edits. The seven documented assignment types receive an execution_policy permitting Normal Site Visit, Address Verification, Collection Visit, Payment Verification and Customer Not Found / Invalid Address. These purposes qualify the assignment type. Purpose metadata holds version, backdate_calendar_days, min_images, required_categories, required_remarks, business_required and signboard_when_available. Assignment metadata holds version, purpose_codes and optional per-purpose overrides. New types or malformed/inactive policies fail closed. Master-data administration UI belongs to M12; authorized maintenance can change these records without editing application code. Submissions pin policy versions and lookup labels.

One previous MYT calendar day is allowed, with an encrypted reason for a selected time before the current minute and a redacted backdating audit. Original server entry time is preserved. No future visits or Rider override for older corrections. Payment Made / Payment Claimed always requires Payment Evidence and does not post cash. Address Verification requires signboard proof when declared available. Callbacks, Promise to Pay and Follow-Up Required require a due follow-up. Applicable business visits require all three coded selections; Other requires explanation.

Proof uses private, application-encrypted S3 objects with validated raster content, bounded dimensions/memory, EXIF removal, uploader/server time and exact task/activity linkage. Defaults: 10 MiB/image, 10 clean images/submission, 8192px and 24 megapixels. JPEG/PNG/WebP are supported. HEIC needs a safe decoder unavailable here; convert to JPEG/PNG. Signed temporary browser selections use Livewire's private temporary storage and cleanup; production requires encrypted private temporary volumes and transport. Nothing is published to the public disk.

~~~bash
./vendor/bin/sail artisan tasks:dispatch-proof
./vendor/bin/sail artisan queue:work redis --queue=media --timeout=120 --tries=3
./vendor/bin/sail artisan schedule:work
~~~

The existing optional Sail profile imports supplies ClamAV for workbooks and images. Keep it and the media worker running. Pending/error/rejected proof blocks completion. Retry scan errors after restoring infrastructure; replace rejected images. Draft removal soft-deletes metadata and preserves the object pending approved retention. A new visit save creates an immutable activity version and needs proof linked to that version; submitted images remain frozen.

Completion review shows saved information, anchored missing requirements and a disabled submission control until valid. Confirmation freezes an encrypted TaskSubmission and exact Document manifest, then sets submitted/pending verification. Duplicate retries return the same receipt. Returned work starts a correction draft/new version; only M07 independent review can mark Completed. After reassignment the previous Rider can see only own redacted submission receipts within current client access.

Traceability: Plan.md task/mobile/private storage/security and approved M06 policy; Module.md M06; Prototype.md UI22/23/47–52; Database.md collection_activities, property_inspections, follow_ups, documents, task_submissions, task_submission_documents and task_status_histories. Legacy-only TaskObservation/occupancy/LOD/CTOS mapping remains gated. The native runtime has no active S3/Redis/ClamAV; real infrastructure and physical-device camera checks remain integration gates. M07 independent Admin verification is implemented below.


## M07 Independent Task Verification

Open **Verification** at /admin/verification. Active Admin access, tasks.view/tasks.monitor/tasks.verify and current client scope are required. Review a frozen submission from the queue or the task detail link; filter by submission state, batch, submitting Rider, exact account/customer reference and MYT dates. The detail shows submitted customer/account context, visit/business/typed remarks/follow-ups, original times, exact private proof manifest, pinned policy and prior cycles.

Prepare **Approve / Verify** or **Reject / Return** with required return remarks. The submitter cannot be the reviewer, including dual-role users. Confirmation locks client → batch → task → submission, verifies the current pending version and records an immutable TaskReview. Approval requires the complete frozen policy/clean manifest and sets completed/approved with completed_at. Return can request correction for deficient evidence, sets in_progress/returned, and leaves every previous visit/photo/submission unchanged. Remarks are encrypted; audit events are redacted. In-app database notifications commit atomically, with no external email/WhatsApp delivery.

Each submission is reviewed on its own page, opened with **Review submission** from Task Verification; the bulk review on the list page was removed in November 2026. The review uses a signed 20-minute preview. Confirmation rechecks permissions/scopes/versions, and an invalid submission applies no decision. Retrying the same accepted preview returns the existing review receipt. New correction submissions append reviews linked to the preceding cycle; accepted decisions are never overwritten. Completed work remains read-only; broader reopen/correction authorization is separate policy work.

Riders see verification notices on Home and full correction instructions on their currently assigned task. Starting correction clears the current returned projection while retaining decisions and frozen proof. Dashboard task counts now distinguish active, pending verification, verified completed and returned work; links use the same scope/status. Exports follow M09, and historical DRMS numeric ranges remain gated by G02/G03 source semantics.

Traceability: Plan.md task lifecycle/security/KPIs; Module.md M07 confirmed review contract; Prototype.md UI25/26/42; Database.md task_reviews, task_submissions/manifest, task_status_histories, audit_logs and notifications. Real private storage/scanner/queue and physical-device camera acceptance remain infrastructure gates from M06.

## M10 Attendance

Open **Attendance** in Admin navigation and the Rider bottom navigation. UI33/UI53/UI59 use the existing staff/users and the four documented attendance entities. The owner-approved initial rules are in Module.md#m10-approved-policy and reflected in all four source documents. `AttendancePolicySeeder` creates only an inactive initial template; it never invents a database approver or staff calendar. An authorized administrator publishes an immutable approved version in **Schedules & policies**, then explicitly assigns dates to staff. Existing policy versions stay pinned. Assignments cover today/future dates, up to 93 at a time; existing dates require an independently approved correction. Rest days, authorized leave, holidays and replacement shifts are explicit calendar entries, not guessed public holidays.

Initial template: Mon–Fri 09:00–18:00 MYT, fixed 60-minute unpaid break per completed interval, inclusive 15-minute grace, Present >=420 net minutes, Half Day >=240, otherwise Incomplete. Late is a separate flag measuring arrival after scheduled start; no penalties/payroll exist. Explicit overnight schedules retain the scheduled start service date. No-punch Absent appears only after an expected shift ends; an open, incomplete or unscheduled record never silently becomes Absent.

Riders use server clock times, one interval per shift, UUID request replay and read-only raw punches. Personal history remains available after client-grant expiry. Staff who already have Admin login access may use `/attendance` with separately granted `attendance.view_own`/`attendance.clock`; Rider task eligibility remains unchanged. Permission bundles are not expanded automatically. Admin views require `attendance.view_all`, active Admin access and current shared client grants with the staff account. Schedules require `attendance.configure`; requests require `attendance.correct`; independent decisions require the reserved `attendance.approve_correction`. A requester or the attendance owner cannot approve their own correction.

Correction requests retain original/proposed values, versions, reason and independent decision history. Stale targets require rejection/re-request. A missed clock-out can be closed in the effective projection without inventing a raw event. Authorized planned-shift corrections preserve the old calendar in the snapshot; raw events remain unchanged. Unscheduled punches require an explicit calendar assignment and approved link correction. Database-only notices and redacted audit commit with the decision; no external message is sent. Supporting attachments are not mandatory under this approved policy.

The register provides date/status/staff/client filters, pagination, truthful totals and matching drill-downs. CSV and printer-friendly **Save as PDF** reports materialize one filtered PostgreSQL snapshot, exclude reasons/private remarks, escape spreadsheet formulas, recheck scope and cap immediate exports at 5,000 rows. General queued reporting remains M09. User-facing times are MYT; persistence remains UTC. Docker/WSL/Sail integration remains a local infrastructure acceptance gate.

M10 validation (18 October 2026): full PostgreSQL regression run passed 233 tests / 1,106 assertions; after final input/identity/constraint hardening, the focused attendance suite passed all 33 tests / 171 assertions. This covers 235 distinct backend cases across the full run and affected reruns, not a single 235-test invocation. Twelve existing desktop/mobile authentication/navigation checks and both end-to-end attendance journeys passed. Rider correction inputs fit 320px and 430px viewports; screenshots were inspected. Asset build and required Pint formatting passed. Both additive migrations and the inactive template were applied to the preserved local preview. No staff calendars, new client scopes or approver grants were automatically seeded there.

## M11 Call Centre and follow-ups

The Admin sidebar now opens Call Centre at `/admin/calls`. Access requires active `admin.access`, `calls.record`, `customers.view` and a current active client scope. Search by exact customer reference, name prefix, exact contact/account or permitted task UUID, with client/category/current Rider filters. Sensitive search values stay in the session rather than URLs. Customer and task screens link to the same workspace; customer Communication history reuses the call/authorized visit timeline.

Record manual outgoing calls at server time, or today/yesterday in Asia/Kuala_Lumpur with a required backdating reason. Eight configured outcomes include required Other details, Callback follow-up and Promise to Pay notes/follow-up. Optional stated promise amounts are context only and do not create payments or receipts. Phone snapshots, outcome labels, remarks and original entry time remain immutable and encrypted. Saving a call sends no external communication.

Contact edits require `customers.update_contacts`, independently of full customer editing, and retain superseded observations with actor provenance and stale-edit checks. `/admin/calls/follow-ups` provides scoped due/status/assignee filters. Only the assigned agent resolves their own pending call reminder, with required remarks and a retained done/cancelled decision. Visit reminders are visible only with task access and retain their source workflow. Legacy databank and real email/WhatsApp/voice history await their approved source/provider modules.

Traceability: Plan.md communication strategy; Module.md M11 implementation contract; Prototype.md UI60/UI64; Database.md debtors, contacts, collection_activities and follow_ups. The additive migration adds encrypted call snapshots, a chronological customer index and immutable versioned reminder decision metadata; it does not replace the canonical entities.

M11 validation (18 October 2026): the full PostgreSQL suite passed 252 tests / 1,204 assertions. After final activity-type/policy/pagination hardening, both affected suites passed 42 tests / 253 assertions, covering 257 distinct backend cases across the full run and affected reruns. Fourteen distinct desktop/mobile browser checks passed across the initial run and reruns (three initially skipped because the Rider fixture email was omitted were rerun successfully). This includes both Call Centre journeys, conditional historical/PTP date fields at 320px/430px, preserved contact history, shared customer timeline and reminder decisions, with no captured page errors. Screenshots were inspected; asset build and required Pint passed. The preserved native preview received the additive M11 migration and call reference seed only. Docker/WSL/Sail and actual external provider integrations remain separate acceptance gates.

## M14 — Template studio (first communication increment)

Communications at `/admin/communications` now provides the three owner-approved initial options: Friendly Payment Reminder, Outstanding Balance Follow-Up and Final Follow-Up Notice. Version 1.0 preserves the exact approved wording and requires all five documented placeholders. Owner approval provenance is recorded explicitly without inventing an Admin approver. Rerunning the seed preserves later edits and approval history.

Template managers create editable replacement drafts. Submitting freezes the exact saved wording; the reserved `templates.approve` grant controls approval/rejection, and rejection requires a reason. The current approved version remains active until a replacement is approved, when it is archived atomically. Rejected and archived records retain immutable content and review metadata. Template names, required-data flags, timestamps and version history are visible; stale edits cannot overwrite another change. No additional approver permission is seeded into existing preview accounts.

Rendering substitutes only the five registry tokens as escaped literal text. Sample previews use prominently labeled illustrative values. Personalized previews recheck current customer/client/account/task access and list missing source data. `company_name` identifies the commissioning Client. Actual outstanding amounts/payment due dates remain unavailable until approved financial provenance exists; task deadlines are never substituted. Customer, task and Call Centre screens link to the same template workspace.

This increment implements UI65 and the saved-content preview slice of UI67. Recipient selection/confirmation, verified email delivery, bulk quotas/receipts, private letter PDF artifacts and WhatsApp conversations/calling remain further M14 increments. No template action sends a message or creates a payment/document. Traceability: Plan.md communication strategy; Module.md M14 and owner-approved wording; Prototype.md UI65/UI67; Database.md integration_accounts/communication_templates. The provider parent is unseeded and no provider is activated.

M14 validation (18 October 2026): the focused template suite passed 17 tests / 89 assertions. The full regression invocation exercised 274 tests: 250 passed, with 24 failures confined to legacy assertions assuming an empty audit table after the newly auditable default approvals. Those assertions now compare the pre-action audit count; the failed-login privacy assertion explicitly targets its event. The affected rerun passed all 25 cases / 129 assertions. This covers 274 distinct backend cases across the full run and affected rerun; it is not a single clean 274-test invocation. Both template browser journeys and all 12 existing desktop/mobile authentication/navigation checks passed (14 total), including 320px/430px template layout checks. Screenshots were inspected; build and required Pint passed. The preserved preview received only the additive schema and owner-approved default seed; no approval grant/provider account was added there. The temporary browser server/database were removed; the preview and PostgreSQL remain running. Docker/WSL/Sail and real external services remain separate integration gates.

## M14 — Service-email evidence and recipient previews

The next communication increment implements explicit client-scoped recipient selection, account/task context, evidence requests and reserved-permission review at `/admin/communications/recipients`. The five `communication-permission.*` grants are defined but are not automatically assigned to existing Admin/Rider accounts. Authorized reviewers retain the actual service basis, source/reference, email source, consent or collection-authority details where applicable, effective period and reasons. Sensitive evidence and frozen messages are encrypted; audit events retain safe status/basis/customer identifiers and evidence fingerprints alongside actor/request metadata.

Canonical `communication_preferences` stores immutable evidence/review versions; it is not duplicated as a second permission entity. Pending stop requests suppress immediately. Unknown, incomplete, blocked, withdrawn, invalid, hard-bounced and under-review destinations cannot qualify. Restoring restricted eligibility or rejecting a stop request requires override authority. These service records confer no marketing authority; production jurisdiction/legal review remains separate.

The composer selects explicit email contacts within one accessible client, up to the configured 250-contact preview bound, with no implicit all-page selection. It freezes per-recipient wording, template/source versions, exclusions and reconciled counts into canonical `communication_batches`/`communications`. Duplicate destinations are deduplicated; conflicting contexts sharing an address exclude the whole group. Rechecking detects changed sources without rewriting the original preview. Cancellation retains history. PostgreSQL guards enforce context, evidence lineage and frozen content. `conversations` is only the canonical parent schema for later channel work.

Saving a preview sends nothing and creates no queue/outbox/provider operation. Confirmation, provider delivery, quotas, attempts, receipts, private letters and WhatsApp remain subsequent M14 increments. The three approved default templates still require approved financial amount/payment-date sources; a missing source remains an exclusion, never an inferred task deadline or fabricated balance. Traceability: Plan.md communication strategy and owner eligibility policy; Module.md M14; Prototype.md UI66–68; Database.md communication_preferences/communication_batches/communications/conversations and the 18 October 2026 policy addendum.

M14 recipient increment validation (18 October 2026): final recipient suite passed 14 tests / 69 assertions, including Livewire evidence/review/preview/cancellation, encrypted evidence, redacted audit, immediate restrictions, override enforcement, client isolation, duplicate/conflicting addresses, source changes and database history/context guards. The broader recipient/template/Call Centre/authorization run passed 62 of 63 cases; its sole failure exposed JSONB key-order instability in reloaded source checks. That normalization was fixed and all 14 affected recipient cases passed on rerun, covering all 63 distinct selected backend cases across those runs. Both final desktop/mobile Playwright journeys passed, including 320px/430px evidence-form bounds and no browser exceptions. Screenshots inspected; frontend build and required Pint passed. The preserved preview received only the additive migration and permission definitions, with no permission grants, eligibility evidence or provider activation added. Default financial placeholders remain blocked without approved source data. Docker/WSL/Sail and real integrations remain unverified local acceptance gates.

The disposable recipient browser server/database were removed after verification; the preserved preview on port 8000 and local PostgreSQL remain running.

## M14 — SES queue, rolling quotas and recipient history

The owner selected Amazon SES in ap-southeast-1 and replaced the old KL calendar-day cap with 1,000 recipient deliveries per rolling 24 hours, plus a conservative 2/second application rate. Both are configurable. Provider entitlement/rate remains a separate verified lower ceiling; moving to 5,000 requires business/provider approval. The actual production domain, dedicated sending subdomain, sender/reply-to, SES account quotas and approved test-recipient addresses remain unverified and are not fabricated.

Exact-digest confirmation rechecks scope, grants, current source/eligibility, verified account and sandbox allowlist, then atomically freezes confirmation provenance and creates canonical queued envelopes plus durable outbox events. HTTP confirmation never sends email. `communications:dispatch` publishes IDs to the Redis email queue and repairs lost publications; it also marks overdue pending attempts unknown. The job uses the shared Redis application rate limiter and PostgreSQL global admission locks. Per-attempt reservations enforce the rolling business cap, provider window and per-second limits across workers. Capacity shortages retain queued recipients and pause/resume checks; provider deferrals do not consume the capped definitive-failure retry allowance.

The SES v2 adapter checks current account sending/quota information before sending one personalized plain-text recipient per request. SDK request retries are disabled for sending; correlation tags identify the durable attempt, not a claimed SES idempotency guarantee. Definite pre-acceptance transient failures release reservations and use capped backoff. Ambiguous exceptions/timeouts/interruption retain unknown reservations and cannot automatically resend/fail over. Acceptance is never displayed as delivery. Canonical attempts/events and paginated recipient history retain provider IDs, acceptance times, failures and retry history without credentials. Owner cancellation affects pending recipients only; accepted, unknown and in-flight evidence remains retained.

Sending remains disabled by default (`MAIL_LIVE_SENDING_ENABLED=false`, no `MAIL_SES_ACCOUNT`, empty `MAIL_SANDBOX_RECIPIENTS`). Local/DEV/UAT always enforce the exact allowlist even if a sandbox flag is changed. No SES account or provider grant was provisioned, no DNS was changed and no customer email was sent. Production is additionally hard-gated in code until authenticated SES receipts, bounce/complaint suppression and explicit production approval are implemented and tested. Those are the next M14 integration slice; the existing M08 financial-source prerequisite also remains for default template values.

Nonsecret configuration lives in `config/communications.php` and `.env.example`: `MAIL_DAILY_LIMIT`, `MAIL_RATE_LIMIT_PER_SECOND`, `MAIL_SES_ACCOUNT`, `MAIL_SANDBOX_ENABLED`, `MAIL_SANDBOX_RECIPIENTS`, `MAIL_LIVE_SENDING_ENABLED`, configurable AWS region. AWS credentials are resolved through the SDK's secure environment/role chain and never entered in the UI/history. Provider capability provisioning must verify the credential account/domain and actual regional SES quotas; no invented values are seeded. Existing preview users/grants are preserved.

Traceability: Plan.md communication strategy and owner SES policy addendum; Module.md M14; Prototype.md UI67/UI68; Database.md communications/communication_batches/communication_attempts/communication_events/communication_quota_reservations plus outbox_events/processed_events. Financial data, authenticated webhook inbox/receipts, private letters and WhatsApp remain separate dependencies.

M14 SES queue increment validation (18 October 2026): the final affected PostgreSQL invocation passed **103 tests / 465 assertions** across delivery, recipients, templates, Call Centre and foundation. Coverage includes exact/replayed confirmation, global advisory admission across independent connections, rolling limits through midnight and rollover, configurable 5000 cap, lower provider limits/global rate, sandbox/production blocks, durable publication failure/replay, SES SDK fake requests/quota probing, deferred capacity versus bounded transient retry, encrypted immutable content/history, stop/permission rechecks, cancellation and interrupted/ambiguous attempts. A prior flaky scope-revocation test now binds its timestamp with microsecond precision; production behavior was unchanged by that test correction. Two desktop/mobile Playwright recipient journeys passed with queue-capacity text and disabled confirmation, plus 320px/430px evidence layout checks and no page exceptions. Screenshots were inspected; build and required Pint passed. Real SES, Redis worker/middleware, DNS, Docker/WSL/Sail and signed receipt/bounce/complaint acceptance remain unverified integration gates. No production performance or real delivery claim is made.

SES API contract references: [SendEmail v2](https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_SendEmail.html) and [GetAccount v2](https://docs.aws.amazon.com/ses/latest/APIReference-V2/API_GetAccount.html). No dependency changes were required; the installed AWS SDK was reused.

## M14 — Authenticated SES receipts, suppression and reconciliation

The disabled-by-default SES/SNS endpoint verifies registered account/topic/region and publishing-policy provenance, strict trusted regional certificate URLs, RSA SNS version 1/2 signatures and bounded request/certificate data. Topic version 2 is preferred. Redirects, arbitrary certificate destinations, unsigned changes and conflicting replay keys are rejected before durable processing. Production requests require HTTPS. Subscription control is retained for a separate authorized AWS action; no subscription URL is followed. Existing Sentry settings exclude request bodies.

Canonical `integration_events` retains encrypted minimal verified fields, signature-verification metadata and the signed-content digest, omitting raw email headers/body and subscription tokens. Durable `ses.receipt` outbox events feed Redis webhook jobs through `communications:dispatch-receipts`. Duplicate SNS IDs and duplicate provider feedback identities cannot apply twice. Correlation requires the exact SES message ID and/or durable attempt tag, account, approved sender and the single frozen recipient. An early unmatched receipt remains `needs_resolution`; the permission-controlled inbox allows a safe recheck, never a manual customer mapping or status override. Malformed evidence is retained as failed validation. No elapsed-time assumption marks a message delivered.

Delivery, soft/hard bounce and complaint results/timestamps appear in the existing paginated recipient history and counters. Out-of-order Send events cannot regress delivered outcomes. Hard bounce and complaint/undetermined bounce create immediate pending restrictive observations linked to the verified source event. System records have no fabricated human recorder/reviewer; existing review/override permissions govern subsequent decisions. Canonical duplicate destinations remain suppressed. Soft bounce/delay remains distinct and does not trigger another send. Provider feedback is retained when the address is corrected or eligibility is reviewed.

A signed receipt may reconcile held unknown quota to consumed and update the derived envelope, with source-event provenance. It never releases unknown capacity for resend or rewrites the original terminal attempt. A receipt arriving before the transport response wins; the later response is retained without undoing delivery/suppression or consumed capacity. The receipt inbox requires `integrations.manage` plus administrative/communication access; it displays safe metadata only. Existing preview users receive no new grants.

Configuration: `MAIL_SES_RECEIPTS_ENABLED=false`, empty `MAIL_SES_SNS_TOPICS`, optional verified `MAIL_SES_CONFIGURATION_SET`. The approved account capability record must identify the actual AWS account, region/topic, sender and verified SNS resource policy restricting publishing to the approved SES source. No real values, topic, configuration set, DNS, subscription or credentials were invented/provisioned. Production sending remains hard-gated until real SES/SNS/DNS/Redis/suppression acceptance and explicit approval. Native fake-certificate tests do not replace that acceptance.

Traceability: Plan.md communication/security strategy and receipt addendum; Module.md M14; Prototype.md UI68/UI70; Database.md integration_events, communication_events, communications, communication_preferences and quota reservation reconciliation. The four authority documents include the nullable provider-recorder/source-event extension and authenticated-only reconciliation contract.

M14 SES receipts validation (18 October 2026): the final affected PostgreSQL run passed **133 tests / 585 assertions** across receipts, delivery, recipients, templates, Call Centre and foundation. Coverage includes real RSA canonical signature verification with synthetic keys/fake public-certificate HTTP; both SNS signature versions; tampering, certificate URL/redirect/size/invalid-certificate defenses; topic/publishing-policy/HTTPS gates; replay collision and semantic deduplication; encrypted minimized inbox data; delivery/out-of-order reduction, soft/hard bounce and complaint suppression; nullable provider recording with human review/override; unknown-quota provenance; callbacks before transport completion; late/tagless correlation; durable dispatch/replay; and permission-protected operator rechecking. All four desktop/mobile Playwright recipient/inbox journeys passed, including 320px/430px responsive checks and no page exceptions. Screenshots inspected; frontend build, required Pint and whitespace checks passed. The preserved preview received only the additive migration, with zero inbox fixtures, enabled accounts or new integration role grants. Real AWS SNS signatures/TLS/resource policy, subscription/domain/SES quota setup, Redis workers, Docker/WSL/Sail and end-to-end production suppression remain separate acceptance gates. No real email was sent or receipt listener activated.

Primary integration contracts: [AWS SNS signature verification](https://docs.aws.amazon.com/sns/latest/dg/sns-verify-signature-of-message-verify-message-signature.html), [SES SNS event contents](https://docs.aws.amazon.com/ses/latest/dg/event-publishing-retrieving-sns-contents.html). Existing AWS SDK/native OpenSSL were reused; no dependency changes were required.

## M14 — Private letters and PDF artifacts

Private letters now use the same three approved template slots through Communications → Private letters, or Prepare private letter in an approved template. The workspace freezes personalized content and source provenance, confirms a digest, queues private PDF generation, and retains generated/failed/cancelled history. It requires the separately reserved `communications.generate_letter` grant for generation; existing roles and user grants are preserved. Current customer/client/task permissions are checked again at confirmation, execution and download. Required financial data remains unavailable until approved M08 sources exist; no sample balance or scheduling date enters a customer letter.

Dompdf 3.1.6 and its four supporting packages were installed with explicit owner approval. Renderer input is escaped plain text with a bundled Nexora logo; remote resources, PHP and JavaScript are disabled. Canonical communication envelopes/events/documents retain content and metadata without email attempts, email quota use or dispatch claims. Generated PDFs are encrypted before private S3 storage, verified by digest on retrieval, and downloaded through authenticated user-bound five-minute application signatures. Generated documents cannot be replaced. This is trusted server-generated PDF content, not an assertion that ClamAV scanned an uploaded file. Interrupted uploads may leave orphaned private objects; production storage reconciliation/retention remains an operational acceptance item.

Run the scheduler and the Redis `exports` worker (`php artisan queue:work redis --queue=exports --timeout=90`); the scheduler runs `communications:dispatch-letters` every minute. Queue reservation is 180 seconds. Durable outbox recovery and idempotent finalization retain one artifact under duplicate execution; failures retain safe codes and an explicitly reviewed retry. No web request renders or sends customer email.

Validation on 18 October 2026: the affected PostgreSQL regression passed 116 tests / 600 assertions; the final expanded letter suite passed 15 tests / 77 assertions after history/stale-source/failure-callback changes. Two desktop/mobile Playwright journeys passed, including default financial-source blocking, approval, exact preview/confirmation and 320px/430px bounds. A four-page synthetic Unicode/literal-markup PDF was rasterized and inspected; frontend build, required Pint and whitespace checks passed. Preserved preview received only additive schema and reserved permission definitions, with no broadened grants or enabled services. Real Redis/S3 and Docker/WSL/Sail remain required integration gates. WhatsApp messaging/provider-qualified calling and approved M08 financial sources remain subsequent work.

Traceability: Plan.md communication/security/queue strategy and private-letter addendum; Module.md M14; Prototype.md UI64/UI65/UI67; Database.md communications.requested_by, immutable envelopes/events, Document owner=communication and outbox/processed events.

## M09 operational reports (initial increment)

Open **Task Management → Report** (`/admin/reports`). Available families: Task, Rider Assignment, Rider Performance, Completed Task and Verification. The latter requires task verification permission. All require existing Admin, report-view and task-monitor/view permissions and current client scope. Export additionally requires `reports.export`; no role grants were broadened.

Choose the task-created date range in Asia/Kuala_Lumpur, client, batch, current Rider staff number, status, exact task ID and an operational column set. Preview totals and rows come from one bounded PostgreSQL snapshot. Performance uses current allocations and verified completion, not historical allocation/visit rates or money collected. Accounts are masked, and contact details, source workbooks and financial amounts are excluded. Exports create a **new**, explicitly timed immutable snapshot, up to 5,000 source tasks.

The Report page downloads CSV and PDF files immediately with **Download CSV** and **Download PDF**. Nothing is queued or stored. Each download rechecks the user's permissions, including verification access for the Verification family, escapes spreadsheet formulas and is recorded in the audit log without row content. Very large PDFs are built during the request, so narrow the filters if one fails.

Follow-on M09 scope: Customer/Attendance/Collection-Visit/Communication/Import report families, historical Rider performance, legacy 88-column parity, presets and proof-image bundles. These are not represented by fabricated data or placeholder exports. Real Redis/S3, large-PDF load checks and production deployment remain acceptance gates; local tests use synthetic data and fake private storage.

## Leave balances and compact filters (9 November 2026)

Human Resources > Leave Management > Leave Types & Entitlements configures descriptions, tracked/non-deductible policies, yearly entitlement and audited staff adjustments. Pending reserves charged days; approval consumes the same reservation; rejection/cancellation releases it. Historical requests retain their snapshots. Attendance > Schedules & policies now accepts effective recurring Rider assignments and policy-specific holidays. Missing assignments fail closed. Attendance, Leave Management and Staff use compact responsive filters with advanced fields, Apply filters and Clear filters. Tasks/Assignments share the toggle and retain their draft behavior. See the four Bahasa Melayu manuals for setup and acceptance steps.
