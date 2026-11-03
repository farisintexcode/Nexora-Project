# DebtOps — Functional and Workflow Blueprint

**Confirmed scope update — 16 October 2026.** The user's confirmed DebtOps requirements take precedence over the earlier DRMS parity baseline. **C** means confirmed DebtOps capability; **P** denotes an implementation choice within that scope; **O/U** continue to describe only observed/unverified DRMS behaviour. Missing DRMS evidence does not defer Customer Management, automated column mapping, Rider workflows, attendance, Call Centre, email or WhatsApp. Provider selection, template wording and business policy values remain configuration/implementation decisions, not omitted features. See the Feature Coverage Matrix in Plan.md.

Terminology: **Customer** is the UI/business term for the existing party record in **debtors** (Eloquent **Debtor**); **Rider** is collection staff in **staff**, linked one-to-one to **users**. **Client** remains the commissioning company. Retain these existing physical names and models: do not add parallel customers/customer_contacts/customer_addresses/rider_profiles/task_assignments/attachments/task_verifications tables for the same facts.

InteXcode (IXC) · 16 October 2026 · Baseline for implementation with discovery gates.

## Current implementation — November 2026

**This section overrides anything older in this document.** Text marked O or U, and IWK labels in particular, remain evidence about the reference system only.

| Area | What is built now |
|---|---|
| Clients | Removed. There is one tenant, so there are no client scopes or client_id columns. |
| Customer Management (M13) | Removed: customer pages, categories, the customer import type, and account editing pages. `debtors` stays as the internal record behind tasks, calls and communications, created automatically from Create Task or Upload Task. |
| Task ID | Replaces the IWK ID. Comes from the workbook or Create Task, must end in a number, and is read by its trailing digits within its assignment: FG001 is 1 and DT2304 is 2304. Numbers are unique per assignment. |
| Upload Task (M04) | Fixed contract with automatic column detection. Missing required columns fail immediately with a message. History lists successful uploads, searched by file name and uploaded date. A blank Excel template can be downloaded. |
| Assign Task (M05) | Choose the assignment, optionally one of its uploaded Excel files (which limits the task list and typed IDs to that file), then Single (12), Selected (12, 15, 18), Range (From 10 - To 20) or Range and Selected (10-20 plus 25). **Assign to Rider** applies straight away, shows a success or failure popup and returns to Manage Task; there is no separate preview page. Unassign and Cancel ask for confirmation first. Tasks ticked on Manage Task still go straight to Assign Task. Create Task can optionally group a manual task with one of the assignment's Excel files, so it appears under that file here. |
| Delete task (M05) | Admins with `tasks.delete` can delete tasks that have no Rider and no recorded work. The record is hidden, not erased, because task history is immutable, and its task ID can be reused. Started work uses Cancel. |
| Amounts owed (M08) | Each task has append-only encrypted balance records: outstanding balance, monthly charge and balance date, from upload or Create Task. Customers pay the client directly. |
| Payment claims (M06, M07) | The Rider sees the balance, enters the amount paid and adds a Payment Evidence photo. A reviewer must confirm the photo matches the amount. An equal amount clears the balance, a lower amount needs a second confirmation as a part payment, and a higher amount cannot be approved. Claims are reviewed one at a time, never in bulk. |
| Reports (M09) | Show total outstanding and verified collected, per task and per Rider. Only approved claims count as collected. Task ID accepts one ID, several, a range or a mix. |
| Proof storage | `PROOF_DISK` and `PROOF_SCAN_REQUIRED` allow local storage without scanning for development. Production always scans. |

## Reading this blueprint

Evidence IDs E01–E24 and outstanding gaps G01–G08 are defined in [Plan.md](Plan.md). **O** means observed, **P** means proposed, **U** means unverified. Confirmed DebtOps behaviours below are marked C; physical implementation choices remain P. Existing O/U statements describe the reference only, not whether confirmed features will be built. A visible Save/Update/Delete control proves availability, not successful server enforcement. No live business records were changed during review.

## Navigation observed in DRMS

```mermaid
flowchart TD
    Login[Login] --> Home[Welcome home]
    Login --> Recovery[Forgot password]
    Login --> First[First time login]
    Home --> Dashboard[Dashboard]
    Dashboard --> Progress[Staff progress]
    Home --> Assignment[Assignment menu]
    Assignment --> Batches[Manage Assignment]
    Batches --> Archive[Archive list]
    Batches --> BatchForm[Add / edit assignment]
    BatchForm --> TaskList[View uploaded tasks]
    TaskList --> Detail[Task info / verification controls]
    TaskList --> Import[Upload Task]
    Assignment --> Import
    Assignment --> Allocate[Assign Task]
    Assignment --> Bulk[Manage Task: Delete]
    Assignment --> Verify[Task Verification]
    Verify --> VerifyList[Filtered verification listing]
    Home --> ReportMenu[Report]
    ReportMenu --> Reports[Generate Report]
    Reports --> Grid[Task grid / image export]
    Home --> HR[Human Resource]
    HR --> Staff[Staff Management]
    Staff --> StaffForm[Add / edit staff]
    HR --> StaffForm
    HR --> Attendance[Attendance: under construction]
    Home --> Bank[Databank search]
    Bank --> BankUpload[Upload data]
    Home --> AccountMenu[Account menu]
    Home --> GlobalSearch[Global IWK search]
    GlobalSearch --> SearchResult[Standalone task results]
    SearchResult --> SearchDetail[Task detail in search context]
    Home --> Layout[Layout customization panel]
    AccountMenu --> Setting[Account setting]
    AccountMenu --> Logout[Log Out]
    Logout --> Login
```

This describes accessible navigation, not proven permission equivalence across roles. Notification/global search/header widgets are shared surfaces, not confirmed standalone business modules.

## Shared module contract (P)

All list screens use scoped server pagination, explicit sort allowlists, persisted filter state, authorized exports and friendly empty/loading/error messages. Every mutation validates on the server, authorizes the specific record and action, executes in a transaction when relational state changes, increments a concurrency version, writes an audit event and returns a clear result. Uploads remain quarantined until validated/scanned. IDs in URLs never grant access. Monetary fields use exact decimals and currency; dates preserve nulls and display in the user's configured zone. Bulk actions show an explicit preview and cannot operate on an implicit full result set.

<a id="m01"></a>

## M01 — Access and account lifecycle

| Requirement | Blueprint |
|---|---|
| Purpose / description | Secure staff access; separate staff identity, login account and debtor identity. O: login/recovery/activation/settings/logout. |
| Roles / entry point | All invited users; `/`, account menu. Other-role permissions U. |
| Submodules / screens | UI01 login, UI02 recovery, UI03 activation, UI04 home, UI05 settings, UI06 logout outcome. |
| Functions / actions / buttons | O: Log In, Remember me, Request Change Password, Register, Update settings, photo browse, Log Out. P: privileged MFA and session management. |
| Forms / fields | Login email/password/remember; recovery email; activation registered email/user name/password/retype/terms; settings read-only staff no/names plus old/new/retype passwords and avatar. |
| Tables / search / filters | No business table observed; P: own active sessions table for privileged session control. |
| Validation / statuses | O: required input browser validation on empty login; activation inputs have required attributes. P: valid email, generic login/recovery errors, strong confirmed password, throttling, token expiry, invited/active/disabled/locked account states. Actual policy U. |
| Business rules / workflow | O: successful login → home; logout → login. P: invitation tied to active staff/email; single-use activation and recovery; prevent privilege acquisition through registration. |
| Dependencies / related modules | M10 staff, M12 permissions/audit/notification; email delivery infrastructure P. |
| Input → processing → output | Credentials/token → validate, authenticate, rotate session → scoped home or neutral error. Settings → validate current password → update credential and revoke sessions per policy. No credential writes tested. |

### Confirmed Admin and Rider access

**C:** Admin Login (UI01) and Rider Login (UI46) share users, Laravel guard and password recovery, with role/permission-aware redirects to UI07 and UI47. Login/logout and every endpoint enforce current account/staff status. Admin capabilities are permission bundles, never hardcoded role-name bypasses. Rider owns current allocation/own attendance and can read a masked history of their own submissions. Field-level policy suppresses unnecessary identifiers/contact details. UI38 manages future roles (Super Admin, Call Centre Agent, Supervisor, Operations Manager, Finance User) without new identity tables. See Plan.md#plan-access for permissions and client scopes.

<a id="m02"></a>

## M02 — Dashboard and staff progress

| Requirement | Blueprint |
|---|---|
| Purpose / description | Assignment-level completion and daily workload overview (E05–06). |
| Roles / entry | Authorized staff/management P; Dashboard menu. |
| Submodules / screens | UI07 dashboard, UI08 staff progress. |
| Functions / user actions | O: inspect batch/date, four metric cards, staff/overall summaries, View All Staff; widget collapse/settings/remove icons. P: explicit batch selector and drill-down filters. |
| Forms / fields / tables | O: assignment name/date, total task, goal completion count/percent, today's task/completion, overall complete/outstanding and today's complete/outstanding; staff list container. Actual staff columns U. |
| Search / filters / buttons | P: assignment, collector, date; metric links open filtered tasks. Global header Search O but result behaviour U. |
| Validation / statuses | P: authorized active/archive batch selection; zero denominator = 0% with tooltip, no divide-by-zero; disclose time zone and as-of timestamp. |
| Business rules / workflow | P: completion KPI based on verified completion; separate submitted count. Confirm DRMS numerator/denominator definition G03; displayed progress indicator must match number. |
| Dependencies / related | M03, M05, M06, M07, M09. |
| Input → processing → output | Batch/date/scope → indexed aggregate or bounded cache → cards, progress and scoped staff/task drill-down. |

### Confirmed operational dashboards

**C Admin UI07:** total customers, active/assigned/completed tasks, pending verification, active Riders, attendance Present/Late/Absent/Leave/Half Day, visit/collection outcome statistics and recent activities. Filters: client/batch/date/Rider/category; every card links to the same scoped list. Completed means approved review; visit statistics count completed visit activities, never assumed cash. Show count basis and as-of/timezone. **C Rider UI47:** own today's/pending/assigned/in-progress/returned/submitted/completed tasks, due follow-ups, today clock status and large Start Task/Clock In/Clock Out actions. Empty/error/loading states are explicit; zero denominators render 0%.

<a id="m03"></a>

## M03 — Assignment batch management

| Requirement | Blueprint |
|---|---|
| Purpose / description | Named client recovery work packages; distinct from collector allocation (E07–10). |
| Roles / entry | Administrative/manager P; Assignment → Manage Assignment. |
| Submodules / screens | UI09 active list, UI10 archive, UI11 create, UI12 edit, UI13 uploaded tasks. |
| Functions / actions | O: list/add/edit/archive status, View Task, Upload Task; Copy/CSV/PDF/Print/column visibility; search/sort/page. No standalone assignment Delete observed. |
| Forms / fields | O: Select Type, Name, Assignment Date, Due Date, Description, DCA Code, DCA Name, LA Name, State, Status. Types: Vacant, Domestic DR, Domestic CTOS, Domestic LOD, Domestic LOD CTOS, COM DR, COM LOD. State options listed in Database.md. |
| Tables / filters | O: #/ID/Name/Date/Type/Upload/Assign/Complete/Payment Update/Image Dir/Status, many responsive-hidden. Task list columns action/IWK ID/SAN/Payment Update/Owner 1/Address/Task Completed/Action By; both plain and responsive table variants observed. P state/client filters. |
| Buttons / validation | O: Save/Update/Back/View Task. P: required type/name/client/start/due; end ≥ start; known state; normalized trimmed DCA metadata; uniqueness by client+external batch reference where supplied. |
| Statuses / business rules | O: Active, Archive. P: archive read-only after work disposition checks; reopening permission/reason; do not cascade delete history. Existing archive enforcement U. |
| Workflow | Create metadata → staged task intake → task list → allocation/execution → review/report → archive. O navigation; full lifecycle P. |
| Dependencies / related | M04 imports, M05 allocation, M06 tasks, M09 report. |
| Input → processing → output | Metadata → authorize/validate and persist → versioned assignment, task count and audit event. |

<a id="m04"></a>

## M04 — Excel Import and Automated Column Mapping

**C — Excel import and automated column mapping.** Reuses existing intake/staging rather than a second importer.

**Current contract:** Upload Task accepts the columns below, in row 1 of the first sheet. Common header names are detected automatically and every match can be changed during review.

| Column | Required | Rule |
|---|---|---|
| task_id | Yes | Ends in a number unique within the assignment |
| account_number | Yes | One open task per account in an assignment; an existing account must belong to the customer |
| customer_reference | Yes | Creates the customer when new |
| outstanding_balance | Yes | Amount in RM, for example 1234.50 |
| customer_name | When new | Needed on the first row of a new customer reference |
| phone, email | No | Saved only for a new customer |
| monthly_charge | No | Amount in RM |
| balance_date | No | YYYY-MM-DD; today if blank |
| address, building, postcode, state_code | No | Saved only for a new account |
| priority_code | No | low, normal, high or urgent; normal if blank |
| instructions | No | Up to 5,000 characters |

| Requirement | Functional contract |
|---|---|
| Purpose / roles / entry | Admin or authorized importer; customer/debt/assignment-batch/task/payment-update/databank data. Entry Upload Excel or context batch/customer screen. |
| Screens / submodules | UI14 upload; UI57 column mapping; UI15 preview/progress/result; UI58 import history/detail; UI39 approved field/alias/template/profile configuration. |
| Upload / header detection | Validate workbook format/content/size; read headers from row 1 of the first sheet; preserve original source names/order and file. Proposed initial accepted .xlsx/.xls via validated parser; reject macros/executables/password-protected unreadable files with useful error. Limits are config, not inferred from DRMS. |
| Mapping / aliases | Normalize case, whitespace, punctuation/BOM; match canonical fields, then unique active aliases in module/client scope. Confidence/method shown. Unmatched/ambiguous columns require explicit target or Ignore. No automatic fuzzy mapping of identities/money/Rider assignment. |
| Review form / actions | Source column/header/sample (masked), proposed canonical field, rule/match basis, target type/required indicator, approved transform; a numbered explanation of each suggestion above the table; Review/Correct/Ignore/Continue. Duplicate targets block unless an explicit approved combine transform exists. Required targets must be mapped. |
| Data validation | Run only after mapping accepted: required/type/date/currency/contact/identifier rules, duplicate source keys, deterministic existing-record match and permitted update policy. Preserve leading zeros; Rider maps unique staff reference and authorized allocation. Header-like rows, malformed values and ambiguous customer matches are rejected. |
| Preview / confirm | Row number, create/update/skip/error, affected record, old/new safe values, counts. Confirmation freezes template/profile/field versions, mapping digest, selection and actor. No silent remapping if aliases change later. |
| Processing / statuses | uploaded → scanning → mapping → validating → ready → processing → completed/failed/cancelled. Required mapping invalidates preview if edited. Per-row staged/accepted/rejected/duplicate/processed/failed states retain granular reasons. |
| Summary / history | All input rows reconciled; successful committed count, rejected count, excluded duplicates, processing failure count and outstanding accepted rows. Failed count = rejected validation + terminal processing failures, shown separately as well as total. Row outcomes and their reasons appear in Row preview, filterable by status; prior job/mapping history and an audit timeline. There is no separate Errors tab or error download (November 2026). |
| Reuse / dependencies | import_fields + column_aliases + versioned import_mapping_profiles + per-import import_column_mappings; existing import_templates/batches/rows, S3, queues, audit/outbox. Mapping edits do not automatically publish global aliases. |
| Input → processing → output | Workbook/type/client → detect/map/review/validate/preview/confirm → scoped records, immutable source provenance, progress/reconciliation/error artifact and notification. |

Alias examples: Customer Name/Cust Name/Nama Pelanggan → customer_name; IC No → identification_no; Mobile/Phone No → phone_number; Outstanding → outstanding_amount; Collector → assigned_rider. Canonical keys map to the existing normalized model fields documented in Database.md; no parallel customer table is created.

```mermaid
flowchart TD
    Upload[Upload Excel and scan] --> Headers[Read row 1 of the first sheet / detect headers]
    Headers --> Auto[Canonical and alias mapping]
    Auto --> Review[User reviews / corrects mapping]
    Review --> Valid{Required and unambiguous?}
    Valid -->|No| Review
    Valid -->|Yes| Rows[Validate rows and duplicates]
    Rows --> Preview[Preview data and error counts]
    Preview --> Confirm[Confirm pinned mapping and selection]
    Confirm --> Queue[Queue idempotent create / update]
    Queue --> Result[Summary / errors / import history]
```

<a id="m05"></a>

## M05 — Allocation and bulk task management

| Requirement | Blueprint |
|---|---|
| Purpose / description | Allocate range-selected work and manage unwanted task rows (E12–13). |
| Roles / entry | Administrative/supervisor P; Assign Task or Manage Task. |
| Submodules / screens | UI16 allocation, UI17 bulk management; P UI18 preview and results. |
| Forms / fields | O allocation: assignment, type/date, staff, operation, From/To IWK ID. Operations New, Replace, Re-assign (Task uncompleted), Un-assign. O management: assignment/type/date, Delete, From/To. |
| Tables / search / filters | O assignment/staff searchable selectors; no affected-record preview observed. P scoped candidate grid with existing collector/state and exclusion reasons. |
| Buttons / actions | O TASK PROCESSING. P Preview, Apply, Cancel, Download result, explicit selected-count confirmation. |
| Validation / statuses | P known active assignment/collector, numeric source ID range if template defines numeric IWK IDs, from ≤ to, limit batch size, reject stale previews. Allocation current/released; bulk operation queued/running/completed/failed. |
| Business rules | Proposed semantics: New only unassigned; Replace replaces current collector; Re-assign only incomplete tasks; Un-assign closes allocation. Actual difference New vs Replace U. One current collector enforced by DB. Delete becomes recoverable cancellation with reason; permanently purge only under retention policy. |
| Workflow | Select batch/operation/range/staff → preview → reauthorize and lock rows → close/open allocations or cancel → per-row result/audit. |
| Dependencies / related | M03 assignment, M06 task state, M10 staff availability, M07 verified work restrictions. |
| Input → processing → output | Range and requested operation → resolve scoped tasks; lock and apply atomic changes → allocation history, counts, rejected rows, audit/outbox events. |

### Confirmed task creation, assignment and monitoring

**C:** UI56 creates or edits a task with its assignment, task ID, customer reference and name, phone, email, account number and address, outstanding balance, monthly charge, balance date, priority, planned visit and due date. New customers and accounts are created automatically; task identity is fixed once created.

UI55 Task Monitoring filters all/unassigned/assigned/in-progress/visited/pending completion/pending verification/completed/rejected-returned/overdue/cancelled by Rider, customer, category, date, priority and batch. Display customer, account, current Rider, due/visit time, work/review badges, latest outcome and task history. Single/selected assignment rechecks versions and scope; all task creation, edits, reallocation and status events are persisted in task_status_histories and audit_logs. M06 defines status dimensions; monitoring does not write arbitrary statuses.

<a id="m06"></a>

## M06 — Account, task execution, property and evidence

| Requirement | Blueprint |
|---|---|
| Purpose / description | Inspect and execute an assignment task tied to client account/property/parties. Observed admin task detail combines imported fields and review controls (E14). Six type samples reviewed; COM DR has no matching active table result. Collector edit forms remain G01/G06. |
| Roles / entry | Assigned collector and scoped reviewer P; task eye action, previous/next, verification/report links where available. |
| Submodules / screens | UI13 listing, UI19 task detail, UI20 Vacant variant, UI21 Domestic/COM variants, UI42 verification-context detail, UI43 global search results, UI44 search-context detail; P UI22 activity/follow-up, UI23 evidence upload/viewer, UI24 party/account detail. |
| Main functions / user actions | O read task info, photos/no-photo state, staff assignment, select Completed/Uncompleted/Payment Update, Update task, previous/next icons. P record visit/call/TPS outcomes, correct contacts with provenance, reminders and submit for review. |
| Forms / fields | O task control form: staff selector, action/status selector; Domestic CTOS Uncomplete remark shown with `*`, hidden when Completed/Payment Update selected. Imported field inventory below. Most imported fields rendered as text rather than editable inputs in this account. |
| Tables / search / filters | Task list IWK ID/SAN; report inventory; P assigned task list filters assignment/status/due date/property/collector and scoped account search. |
| Buttons / validation | O Assign task/Update task. P transition-specific required evidence/outcome, nonnegative counters, valid contacts/dates, upload content limits; no server enforcement claimed from asterisk alone. |
| Statuses / business rules | O Uncomplete and Complete badges, three action options; completed Vacant sample showed updater/time and categorized photo figures. P work status unstarted/in_progress/submitted/completed/cancelled, separate review pending/approved/returned and payment marker; protect optimistic version. CTOS information never automatically changes an external credit record. |
| Workflow | P select allocated task → review source snapshot → record activity/contact/property changes → attach categorized evidence → set reminder/submit → supervisor review → completion or correction. |
| Dependencies / related | M03–05, M07–09, M10, M11; private storage. |
| Input → processing → output | Source account/property/party details and collector observations → append history, validate transition, preserve import values → current task state, activity trail, reminders, evidence and report projection. |

### Observed task field dictionary

Names below are source labels, not assertions about DRMS storage. Preserve them in import adapters; normalized DebtOps mapping appears in Database.md.

| Group | Actual labels / fields |
|---|---|
| Context | Client Company, Task type, Task Update By, Task Remarks, Remarks admin, Last update, assignment and IWK ID |
| Source / routing | exclude, iwkSpecialInstruction, dcaFileType, dcaName, dcaCode, state, costState, uo, laName, bilNo, san, rangeDue |
| Customer / owners | custTelNo, custMobileNo, custFaxNo, custEmail, owner1, owner2, Owner1NRIC, Owner2NRIC |
| Property | propAddr1–5, building, buildingId, roadname, taman, postcode, suburb |
| Billing | mailName1Bill, mailName2Bill, mailAdd1Bill–4, LODTemplate |
| Financial snapshot | LODOverdueAmount, CajPerkhidmatanPembentungan, Caj3pctgUntukPembatalanNamadDalamCTOS, CajUntukSuratTuntutan, JumlahCajYangPerluDibayar, balanceAmountAtDate, balanceAmountAsCopyBill, diffBetweenbalanceAsCopyBillAndbalanceAtDate |
| Visits / reminders | visitDate, agentsName, upDateReminder, numberOfVisitation, NumberOfFollowUp |
| Verified occupier / owner / tenant | occupier, correctOwnerName, ownerName, ownerTelNo, ownerMobileNo, ownerFaxNo, ownerEmail, tenantName, tenantTelNo, tenantMobileNo, tenantFaxNo, tenantEmail, occupierNationality |
| Property/business outcome | propertyUsage, propertyType, nameOfShopCompany, natureOfBusiness, drCode, blackArea, reasonCustomer, BusinessClosedDown |
| LOD / CTOS / evidence | CustomerLODAcknowledgement, LODAcknowledgementRemark, ProofOfVisitation, ctosOrNonctos; photo section |
| Additional report columns | Third Party Search, Source of TPS, TPS Outcome Contact Number, Remarks TPS, Remarks, Staff Id; URL Street (Sign Board), URL Permise 1, URL Permise 2, URL Meter |
| Vacant-specific | san, propAddr, currentClass, occupied, vacant, othersA, othersADesc, commercial, domestic, othersB, othersBDesc, MeterConnectedInd, meterNumber |

Sample `diffBetween...` held a Y marker, so treat this as a difference flag pending template confirmation, not a decimal difference. Fee labels do not establish a currently applicable fee formula. Counter and agent-name fields do not prove a normalized event history exists in DRMS.

### Confirmed mobile Rider work (C)

Entry: UI47 Rider Home → UI48 My Tasks → UI49 task detail/customer tab. Today's tasks use planned visit date (fallback due date), while pending/assigned/in-progress/returned/submitted/completed are explicit filters with query preservation. Reveal only the customer's task-relevant name, address and permitted contact; hide full identification numbers by default. Completed/previous submission history remains read-only.

UI22's mobile visit form records visit date/time, availability, site status, address/location text, contact result, person contacted and outcome. UI50 business section records predefined business type/nature/status with Other requiring explanation. UI22 also provides separate visit/collection/customer/follow-up remarks and due follow-up. These extend collection_activities/property_inspections/follow_ups rather than duplicating site_visits/remarks tables. Call outcomes and actual cash receipts remain distinct.

UI23 supports camera/gallery, multiple images, preview/remove-before-submit, type/size/dimension/count validation, per-image upload/scan state, retry and private S3 metadata/task/uploader/time. Defaults 10 MiB/image and 10 clean images/submission, JPEG/PNG/WebP; override by approved task-type policy. Unsupported browser camera access falls back to file/gallery. EXIF location is not required. Upload failure never completes a task.

UI51 completion review validates required visit, outcome, configured remarks, business nature where applicable and clean proof categories/count. Missing fields link to the exact editor. Confirm creates an immutable task_submissions version with submitter/time and frozen remarks/business/visit/proof references; moves work_status to submitted and review_status to pending. UI52 acknowledges Pending Verification with submission reference; duplicate tap/retry returns same submission. Return exposes Admin reason, enables a new draft and retains previous versions. Only M07 approval sets completed.

| Display / filter | Canonical rule |
|---|---|
| Unassigned / Assigned | work_status=unstarted; no/current task_allocation. |
| In Progress | work_status=in_progress, current review not returned. Start recorded atomically. |
| Visited / Pending Completion | Progress filters within in_progress: completed visit recorded; completion checklist still incomplete or draft awaiting submit. Not extra writable states. |
| Pending Verification | work_status=submitted and review_status=pending. |
| Rejected / Returned to Rider | work_status=in_progress and review_status=returned; start correction clears current review projection to none but leaves decision history. |
| Completed | work_status=completed and review_status=approved. |
| Reassigned | Allocation-history event/badge; does not erase work/review history. |
| Cancelled / Overdue | cancelled is terminal work_status with reason; overdue is derived due_at < now and nonterminal. |

```mermaid
flowchart TD
    Customer[Customer / account] --> Create[Manual or imported task]
    Create --> Assign[Admin assigns Rider]
    Assign --> Rider[My Tasks / Start]
    Rider --> Visit[Site visit]
    Visit --> Business[Business nature and status]
    Visit --> Remarks[Visit / collection / customer remarks]
    Visit --> Proof[Clean proof images]
    Business --> Check[Completion review]
    Remarks --> Check
    Proof --> Check
    Check --> Submit[Submit immutable completion]
    Submit --> Pending[Pending Verification]
    Pending --> Review[Admin reviews]
    Review -->|Approve| Completed[Completed]
    Review -->|Reject with remarks| Returned[Returned to Rider]
    Returned --> Rider
```

<a id="m07"></a>

## M07 — Task verification

| Requirement | Blueprint |
|---|---|
| Purpose / description | Supervisor review of collection work; observed search, empty and populated result, and verification-context detail E15. |
| Roles / entry | Supervisor/manager P; Assignment → Task Verification. |
| Submodules / screens | UI25 verification criteria, UI26 results/review, UI42 detail. Actual decision persistence U. |
| Forms / fields / tables | O assignment, staff, From/To IWK default 1/1000, type/date summary, View Task; result Update and No Record, plus 143-record populated result with search/export/page and eye detail. Full headers #, IWK ID, SAN/sewacc, Payment Update, Address, Street, Permise1, Permise2, Meter, Complete Date, Action By, unlabeled decision column. Each row has Completed/Uncompleted/Payment Update. P independent review decision/reason instead of mixing payment marker. |
| Search / filters / buttons | O listed criteria. Assignment-only attempt did not leave search; adding staff yielded result. Populated list includes Update, Copy/CSV/PDF/Print/Column visibility and pagination. P explicit required fields/errors, selected rows, Approve/Return. Whether bulk Update applies beyond displayed page is U. |
| Validation / statuses | P only submitted task at matching version; reviewer authorized and different from submitter; return reason required; pending/approved/returned/superseded decision states. Actual DRMS states U. |
| Business rules / workflow | P submitted → review evidence → approve/completed or return/in_progress; immutable decision, later correction supersedes rather than overwrites. Bulk decision validates each row; do not silently succeed partially. |
| Dependencies / related | M05 allocation, M06 task/evidence, M09 progress/report, M12 audit. |
| Input → processing → output | Scoped filters and review decision → compare task version and evidence → decision record, task transition and notification. |

```mermaid
stateDiagram-v2
    [*] --> unstarted
    unstarted --> in_progress: collector starts
    in_progress --> submitted: required evidence recorded
    submitted --> completed: supervisor approves
    submitted --> in_progress: returned with reason
    unstarted --> cancelled: authorized cancellation
    in_progress --> cancelled: authorized cancellation
    completed --> in_progress: authorized reopen with audit
```

This is the confirmed DebtOps lifecycle expressed with existing internal work codes; DRMS's three-option selector remains reference evidence only. Rejection/return and assignment are separate dimensions described in M06.

### Confirmed completion review contract

**C:** Admin (or future reviewer permission) sees UI26 queue and UI42 detail: customer/account, Rider, submission version/time, visit date/time/availability/person/outcome, business type/nature/status, all remark categories, clean proof images, supporting source/task information and completion checklist. UI51 submission is mandatory; draft/partial work cannot be approved. Approve/Verify stores approved + remarks and completes task. Reject requires verification remarks and stores decision=returned (UI Rejected / Returned to Rider); notifies current Rider for correction. Reviewer cannot be submitter. Optional approve remarks, required reject remarks; no hidden Payment Update side effect.

task_submissions retains submitted_by, submitted_at and task version; task_reviews references exactly that submission, reviewed_by (existing reviewer_id), reviewed_at, decision and reason. History shows each submitted/approved/returned cycle, and immutable proof manifest prevents later uploads changing reviewed evidence. Bulk review confirms explicit selection and validates each version; stale or denied rows are reported, never silently approved. UI55/UI07 count submitted pending review separately from verified completed.

<a id="m08"></a>

## M08 — Financial snapshots and payment updates

**C (November 2026).** Customers pay the client directly (online or at a counter). Riders do not handle cash and the system issues no receipts.

| Requirement | Contract |
|---|---|
| Purpose | Show what each customer owes, record what they say they paid, and count it as collected only once verified. |
| Entry | Outstanding balance, monthly charge and balance date come from Upload Task or Create Task, and can be changed on Edit Task. |
| Storage | `financial_snapshots`: append-only and encrypted. Every upload, edit or verified payment adds a row, shown as Balance history on Task Details. |
| Rider | Sees the balance on the task card and task. A payment needs the amount paid and a Payment Evidence photo before submission (outcome Payment Claimed or DR01). |
| Verification | The reviewer compares the photo, the amount entered and the balance, and must tick that the photo matches. Equal clears the balance. Lower needs a second tick and leaves the rest outstanding. Higher cannot be approved and is returned to the Rider. |
| Reports | Total outstanding and verified collected, per task and per Rider. Unreviewed claims never count. |
| Business rules | A balance is not a receipt. A claim is not a payment until approved. Later uploads do not change earlier records. |

Historical reference requirements, not built: payment-update workbooks, the fee breakdown columns and a financial approval queue.

<a id="m09"></a>

## M09 — Reporting and export

| Requirement | Blueprint |
|---|---|
| Purpose / description | Assignment task dataset, progress and photo export E05/E06/E16. |
| Roles / entry | Authorized administrative/supervisor/management P; Report → Generate Report. |
| Submodules / screens | UI27 report criteria/results, UI29 image bundle; dashboard/progress links. |
| Forms / fields | O assignment, IWK From/To default 1–1000, staff, Assign Date, status Task Complete/Task Outstanding, payment Yes/No. Image categories Street (Sign Board), Permise 1, Permise 2, Meter. |
| Tables / search / filters | O 88-column report includes field inventory M06 plus payment marker/staff/photo URLs; search, sorting, pagination and responsive expansion. P named presets with explicit output columns and PII permissions. |
| Buttons / actions | O View Task, Copy/CSV/PDF/Print/Column visibility, Export Images. Actual file generation U. P asynchronous export jobs, progress and expiring download. |
| Validation / statuses | P valid range, optional null date, authorized scope, safe export size/type, category required for image export; queued/running/completed/failed/expired exports. |
| Business rules / workflow | Freeze report criteria and snapshot time; derive totals from same snapshot as exported rows; redact/mask by export permission; spreadsheet-formula injection escaping; record every export and download. |
| Dependencies / related | M03–08, M10 staff, S3 and queues. |
| Input → processing → output | Filters/columns/category → scoped snapshot and streaming generation → report file/image bundle, metadata and audit. |

### Confirmed basic report catalogue

**C UI27:** choose Customer, Task, Rider Assignment, Rider Performance, Completed Task, Verification, Attendance, Collection/Visit, Communication or Import report. Each family supports date range, search and applicable client/category/customer/Rider/status/batch/channel/template/import-kind filters; CSV/PDF actions with approved column sets and queue progress/artifact UI41. Customer reports use customer/category records; Rider performance uses completed visits and approved tasks (not submitted counts); attendance uses schedule-aware statuses and adjustments; communication reports distinguish accepted/delivered/failed/unknown; imports reconcile row/mapping results. UI29 image exports remain supported. Field-level restrictions, snapshot consistency, formula escaping, audit and signed downloads remain unchanged. Do not label outstanding-balance movement as money collected.

<a id="m10"></a>

## M10 — Rider / Staff Management and Attendance

| Requirement | Blueprint |
|---|---|
| Purpose / description | Staff directory and employment status; attendance visible but under construction E17–19. |
| Roles / entry | Authorized HR/admin P; Human Resource menu. |
| Submodules / screens | UI30 Rider/staff list, UI31 create, UI32 edit, UI54 Rider profile, UI33 Admin attendance, UI53 Rider attendance/history, UI59 policy/schedule/correction. |
| Forms / fields | O Staff No (IWK Staff ID), Email, Active Status, First Name, Last Name, Gender, Position, Appointment Date, Phone No, Home Phone No, Address. Position values in Plan.md. |
| Tables / search / buttons | O #/Staff ID/Staff No/Name/Appointment Date/Position/Status; search/sort/pagination, exports, Add/Save/Update/back. |
| Validation / statuses | O Active/Unactive and Select/Male/Female. P unique staff no and account email, valid email/date, bounded names/contact values; availability check before allocation. Keep display code separate from permission role. |
| Business rules / workflow | Create staff → invite account P; edit/disable → review outstanding allocations → reassign under authorization. Do not erase staff referenced by history. DRMS attendance fields remain U; confirmed DebtOps attendance is specified below. Payroll is outside scope. |
| Dependencies / related | M01 accounts, M05 allocation, M09 reports, M12 permissions/audit. |
| Input → processing → output | Staff metadata → validated identity/status update → directory entry, controlled invitation and audit. Attendance uses the confirmed policy/schedule/clock/correction contract below; G08 now concerns policy values, not feature inclusion. |

### Rider management (C)

UI30/31/32 become Rider list/create/edit filters over existing staff; UI54 Rider profile adds contact/employment/status, assigned/completed/returned tasks, attendance/history, activity timeline and scoped performance. Rider eligibility staff.is_rider and active state are independent from Spatie permissions; activation/invitation does not grant Admin. Deactivation requires outstanding task disposition and prevents sign-in/new assignments according to account policy. Additional office staff continue to use the same records/forms.

### Attendance management (C)

<a id="m10-approved-policy"></a>

**Owner-approved initial attendance policy — 18 October 2026.** Versioned configurable rules use Monday–Friday, 09:00–18:00 Asia/Kuala_Lumpur, 60 unpaid break minutes per completed interval, 15 inclusive grace minutes, Present at >=420 worked minutes, Half Day at >=240 and <420, and Incomplete below 240. Worked minutes are the completed effective interval less the pinned unpaid break duration, floored at zero; no separate break punches exist in this increment, and no second deduction is applied. The UI identifies this fixed deduction explicitly. Late is a separate flag: clock-in after start+grace is late; late minutes measure arrival after scheduled start. Open intervals remain Open during their shift and Incomplete after its end. Neither a short/open/unscheduled punch nor a pending correction becomes Absent automatically. Absent is only a completed expected schedule with no raw attendance. Rest Day, Public Holiday and Leave are explicit calendar day types; no fabricated punch rows. Pending Correction is an overlay on the existing result until independently decided.

Admin-authorized schedule assignments materialize explicit Rider/date calendar rows, including weekend rest days; no retroactive or universal assignments are invented. Policy versions also support explicit overnight shifts (end on the next local day), different individual assignments, replacement working days and holiday overrides. No overnight schedule is seeded by default. Schedules retain original authorization and corrections retain before/after history; deactivate via an independently approved cancelled-calendar correction rather than deleting referenced history. Separate `attendance.configure`, `attendance.correct` and `attendance.approve_correction` grants control configuration, requests and decisions; no role-name bypass or self-approval, including Admin's own attendance. Riders with `attendance.correct` may request corrections for their own scheduled records only. No supporting attachment is mandatory under this initial policy; private attachment requirements need a separately approved rule. Unscheduled records may be linked to a future reviewed schedule without inventing raw times; until then they remain Needs review.

Raw punches are immutable and server-timestamped, with idempotency keys and one interval per shift; an unresolved raw open interval blocks a new clock-in. Approved effective clock-out closes the operational interval without inventing a raw clock-out. Client-scoped Admin attendance uses a current shared active client grant with the Rider's account; own attendance does not expose client/customer data. CSV and print/PDF views use the same filtered snapshot and safe columns. Payroll, salary deductions, penalties, disciplinary actions, GPS and biometrics are excluded.

| Contract | Behaviour |
|---|---|
| Entry / roles / screens | UI53 Rider today/history; UI33 Admin attendance dashboard/list; UI59 schedule/policy/correction dialog. |
| Rider actions / fields | Clock In, Clock Out, today's server timestamps/status, own date-filtered history and optional remarks. Show business timezone and open shift. |
| Rules / statuses | One current open interval per staff member, idempotent request keys, clock-out after clock-in. Pin policy/schedule per service date; overnight clock-out stays linked to start-date schedule. Present/Half Day/Incomplete derive from net worked minutes, with a separate Late flag/minutes; Absent only expected closed schedule without a punch. Leave/Rest Day/Public Holiday come from authorized calendar entries. Rules follow the approved policy above and remain versioned/configurable. |
| Admin actions | Search/filter Rider/date/status, totals, scoped CSV/PDF; configure schedule/workdays/leave exceptions. Correct request includes reason, original and proposed times/status and a separate authorized approver; reject self-approval. No overwriting raw punches. |
| Input → processing → output | Server clock event → scope/duplicate/open-shift validation → immutable raw times + effective approved adjustments → own/admin attendance and report projections, audit/notifications. |
| Tables / dependencies | attendance_policies, attendance_schedules, attendances, attendance_adjustments; existing staff/users/audit/export jobs. No GPS/biometrics required. Missing schedule shows Unscheduled / Needs review, not Absent. |

```mermaid
flowchart LR
    Schedule[Versioned policy and daily schedule] --> In[Clock In server time]
    In --> Open[Open attendance]
    Open --> Out[Clock Out server time]
    Out --> Calc[Present / Late / Half Day]
    Schedule --> NoPunch[Closed expected schedule without punch]
    NoPunch --> Absent[Absent projection]
    Schedule --> Leave[Approved leave schedule]
    Calc --> Request[Correction request with reason]
    Request --> Approver[Independent authorized approver]
    Approver --> Effective[Effective values plus unchanged raw punches]
```

<a id="m11"></a>

## M11 — Call Centre and Databank

| Requirement | Blueprint |
|---|---|
| Purpose / description | Search independently imported data for call-centre work E20. Match schema and successful results U. |
| Roles / entry | Call Centre plus explicitly authorized staff P; Databank. |
| Submodules / screens | UI34 search/results, UI35 upload; P record detail only after G05. |
| Forms / fields / tables | O one unlabeled search input, Search and Upload Data; upload file chooser with Excel template instruction. No-match table displayed literal `{"status":"No Record"}`. Successful result columns U. |
| Search / filters / buttons | O free-text search; exact/partial/identifier semantics U. P bounded search, client/data-source scope, explicit allowed keys and masked results. |
| Validation / statuses | P require minimum safe query length, rate limiting, provenance, scan/template checks and row errors. Do not persist unknown source fields into public searchable columns. |
| Business rules / workflow | P intake staged like M04; search only entitled source datasets; no bulk scrape/export by default; selected contact evidence records provenance in M06. No external contact initiated. |
| Dependencies / related | M04 common import infrastructure, M06/TPS activities, M12 audit. |
| Input → processing → output | Search term or source workbook → scoped query/staged normalization → masked matches/no-results or intake reconciliation. Schema must be finalized from positive samples. |

### Confirmed Call Centre operations

**M11 implementation contract — 18 October 2026:** the owner approved manual call backdating by at most one Asia/Kuala_Lumpur calendar day, with a mandatory reason; current server time remains the default. Future calls are rejected and the original server entry timestamp retained. Call outcomes are the eight confirmed values below in existing reference_values; labels/contact/PTP context are frozen in an encrypted call_details snapshot on collection_activities. Records remain append-only and request UUID replay cannot duplicate calls or reminders. Callback and Promise to Pay require a due follow-up; PTP details are narrative/optional stated date/amount/currency only, never payment posting. Other requires an explanation. Reference metadata.activity_types separates call-only outcomes from site-visit choices and validation; Promise to Pay remains shared. Existing visit references without this metadata retain their approved visit meaning.

Call Centre access requires active Admin workspace access, calls.record, customers.view and effective client scope, without role-name bypass. Necessary phone contacts are visible for manual work; full identifiers remain restricted. Optional tasks must belong to the selected customer/client and require task-view/monitor permissions. Phone/contact changes require the independent customers.update_contacts grant and an optimistic customer revision; append/supersede the existing observations with actor provenance. Follow-ups default to the recording agent. The scoped queue can show call/visit reminders; only the assigned agent can resolve their own pending call reminders with remarks. Done/cancelled decisions retain actor/time/remarks/version and audit; original reminder context is unchanged. Broader reassignment/escalation authority is a later explicit permission contract. No automatic outbound contact is triggered by recording or resolving a call.

**C UI60:** authorized Admin/Call Centre Agent searches Customer/account/task/category/Rider, views necessary debt/task/contact context, records an outgoing telephone call, call time/person/outcome/remarks and follow-up, and sees prior calls plus email/WhatsApp/visit history. Outcomes: Answered, No Answer, Busy, Invalid Number, Call Back Requested, Promise to Pay, Disputed, Other; Other requires detail, Call Back Requested requires follow-up date. Promise to Pay captures notes/optional stated date or amount in context and follow-up; it is not a posted payment or full repayment-plan engine. Contact edits require customers.update_contacts and retain provenance/version.

Call logs reuse collection_activities(type=call), linked to customer, optional task, performed_by and optional communication/voice record. Task-less calls are valid. Follow-ups reuse follow_ups with required customer and optional task. No new duplicate call_logs/whatsapp_messages tables. Actual telephony automation is optional beyond recording manual calls; WhatsApp voice is the separate confirmed integration M14. UI34/35 remain the legacy databank submodule; unknown DRMS databank schema does not block the customer-based Call Centre.

<a id="m12"></a>

## M12 — Shared tools, roles, reference settings, notifications and audit

| Requirement | Blueprint |
|---|---|
| Purpose / description | Operational governance. O account settings is M01; notification bell and header tools surfaced without business content. Dedicated role/settings/audit screens not found. |
| Roles / entry | All users for own notifications; privileged administrators/auditors for configuration P. |
| Submodules / screens | UI36 shared tools, UI43 global results, UI44 search-context task detail, UI45 layout customization O; P UI37 notification centre, UI38 roles/permissions, UI39 reference/template settings, UI40 audit, UI41 job monitor. |
| Forms / fields / tables | O global IWK search/results columns in E24; customization has 13 layout/text/dark-mode checkboxes and color variant groups. P notification title/message/type/record/time/read; role name/action permissions/scope; versioned reference code/label/active/order; audit actor/action/record/old/new/IP/agent/time; job state/counters/errors. |
| Search / filters / buttons | P read/unread/type, role search, audit date/actor/module/action/record, job state; Mark read, Save role, Publish template, Retry job. Actual DRMS behaviour U. |
| Validation / statuses | P prevent self-escalation/last-admin removal, version configuration, immutable audit, scoped retry and notification ownership checks. |
| Business rules / workflow | Proposed sensitive changes require reason and appropriate approval; notifications use post-commit events; retries preserve idempotency; audit redacts secret fields. |
| Dependencies / related | Cross-cutting M01–11; cache invalidation, queues and private S3. |
| Input → processing → output | Authorized config change/event → validated persistence/audit/outbox → revised permission/reference version, user notice or operational event. |


<a id="m13"></a>

### Confirmed operational administration

Admin/Rider permission bundles, customer categories/reference codes, canonical import fields/aliases/profiles, attendance policies/schedules/correction authority and communication template/provider/capability/quota configuration use granular permissions, versioning and audit. UI70 integration settings stores only provider/sender capability and secret reference, never secret contents. Approved production DebtOps domain, TLS, APP_URL/session scope and signed webhook routes follow Plan.md#plan-deployment; the reference DRMS domain is unchanged. Existing audit/notification/job tools serve every confirmed module, with own-recipient notices for task assignment/return/approval, import completion, attendance correction and communication job action needed. No automatic customer messaging is triggered merely by a task status change unless a later explicitly configured business rule authorizes it.

## M13 — Customer Management and categories

**Removed (November 2026).** There are no customer pages, categories or customer import type.

Customer remains the UI word for the `debtors` record, which tasks, Call Centre and Communications still use. It is created automatically the first time a task uses a new customer reference, with the name, phone and email supplied at that point. An existing customer's details are not changed by later tasks. Contact details can be corrected in the Call Centre workspace.


<a id="m14"></a>

## M14 — Communication Management

**C.** Admin/authorized agents use customer/task context; channel permissions are independent from Rider task privileges. No live sending is performed by this documentation task.

| Requirement | Functional contract |
|---|---|
| Purpose / entry / screens | Customer communication with audit/history. UI64 history, UI65 template selection/editor/approval, UI66 recipients, UI67 personalized preview, UI68 batch result, UI69 WhatsApp conversation, UI70 provider-qualified calling and integration status. Customer/task/Call Centre link to same records. |
| Three email / letter options | Exactly three logical email slots with immutable approved versions, configurable name/subject/body. Seed the owner-approved version 1.0 defaults. Select approved version; auto-fill customer_name and optionally account_number/outstanding_amount/due_date/company_name. Allowlisted placeholders only, escaped values; missing required value blocks affected recipient. Preview letter/PDF from same template is a document artifact, not an email delivery. |
| Recipient selection / validation | Scoped customer/category/account/task filters or explicit selected contacts; validate email, current preference/suppression and name/task context; deduplicate canonical addresses within batch by explicit one-customer context. Shared address with conflicting personalization requires review; do not merge balances. Show selected/eligible/duplicate/invalid/suppressed counts and reasons. |
| Preview / confirmation | Preview recipient-specific subject/body and source amount/date/currency, template version and frozen context; review representative and exception rows, view any selected recipient. Confirm exact recipient snapshot/digest; edits invalidate preview. Individual send uses same preview path; no BCC fan-out leaking personal content. |
| Bulk queue / limit | Configurable initial ~1,000 email recipients/day (KL calendar day), plus actual provider rolling quota/rate. Atomic reservations enforce both across workers. At limit, pause remaining queue with resume time. Raising to ~5,000/day requires quota/tier/rate configuration, not redesigned application. Providers remain adapters. |
| Delivery / retries / history | Persist customer/template/recipient/subject/sent_by/sent_at/status/task, rendered snapshot, attempt and receipt history. accepted ≠ delivered. Temporary definitive failures retry with capped backoff; permanent/opt-out invalidation suppresses; ambiguous accepted timeout becomes unknown and reconciles before resend. Resume/retry/cancel privileges audited. |
| WhatsApp messaging / conversation | Separate channel adapter plus generic conversations/communications. Link customer/contact and optional task, inbound/outbound bodies/media, staff owner, timestamp and delivery state; provider-approved template/window rules and preferences checked at dispatch. Three email slots are not WhatsApp template approval. Verify/authenticate/dedupe webhooks, support two-way agent chat and preserve history. |
| WhatsApp voice | Separate VoiceCallingAdapter + call_permissions/voice_sessions and common communication envelope. Verify selected service's sender enablement, supported countries/accounts, explicit permission for business-initiated calls, restrictions/expiry, agent browser/SIP route and events before activating Call. Show disabled reason and retain backlog if service lacks calling; do not claim all messaging APIs can call. No recording/transcription assumed. |
| Inputs / states / outputs | Approved template + frozen scope/recipient/context → reviewed preview → outbox/queue → adapter → signed receipts → history. Batch draft/previewed/queued/sending/paused/completed/completed_with_errors/cancelled. Message draft/queued/accepted/delivered/read/failed/unknown/cancelled/suppressed/received; letter generated and manual call recorded are channel-specific non-email states. Inbound does not require an email template. |
| Dependencies / storage | M13 customers, M06 tasks, M11 call outcomes, M12 roles/audit/notifications, Redis/outbox/S3. Generic communications, communication_batches/attempts/events/preferences, conversations, integration_accounts/events, templates, quota reservations and voice tables; no duplicate email_deliveries/whatsapp_messages facts. |

```mermaid
flowchart TD
    Select[Select scoped customers / contacts] --> Validate[Validate / deduplicate / suppress]
    Validate --> Template[One of three approved email template versions]
    Template --> Preview[Personalized preview and frozen recipients]
    Preview --> Confirm[Confirm exact batch]
    Confirm --> Quota{Application and provider capacity?}
    Quota -->|No| Paused[Paused until quota available]
    Paused --> Quota
    Quota -->|Yes| Queue[Redis delivery jobs]
    Queue --> Adapter[Provider-neutral email adapter]
    Adapter --> Receipt[Signed webhook / status reconciliation]
    Receipt --> History[Customer / task communication history]
    Receipt --> Retry[Retry definitive transient failures]
    Retry --> Quota
    Chat[WhatsApp agent conversation] --> WA[WhatsApp messaging adapter]
    WA --> Receipt
    Call[Voice action with current permission] --> Voice[Separate enabled calling adapter]
    Voice --> Receipt
```

## Implementation acceptance checklist

Every module must have an approved role matrix, agreed field dictionary, tested state transitions, accessible validation/empty/error states, scoped exports and audit evidence. DRMS parity claims still require G01–G08; confirmed DebtOps features proceed under the C contracts and explicit policy/provider activation checks. Prototype.md defines interface structure; Database.md defines proposed storage. Any new evidence that changes import keys, finance rules, task variants or verification must update all four documents together.

### Context and search consistency requirements

O: global header IWK search navigates to a separate result table; a synthetic nonnumeric query returned two source-ID-0 rows. Internal coercion is an inference, not a verified root cause. P: validate identifier syntax, preserve literal query, require permitted scope and return explicit no-match state. Search rows link to task detail without the full search breadcrumb; DebtOps should preserve the query/list state.

O: an uploaded Vacant detail showed occupancy/meter fields, while a verification-context Vacant detail showed the broad recovery dictionary. P: use one type-aware task renderer with contextual review controls. A dummy-looking source row showed header-like strings in amount/contact fields and malformed dates; treat this as a data-quality finding with unknown origin, and reject header rows, shifted columns and malformed numeric/date values during DebtOps intake.

<a id="m06-approved-policy"></a>

### M06 approved initial Rider policy — 17 October 2026 (C)

The owner approved the following configurable initial master data. Codes below are DebtOps codes, not inferred mappings of historical DRMS values. Changes require authorized administration; existing accepted submissions pin the exact policy version and choices.

- Availability: Available; Not Available; Temporarily Away; Premises Closed; Unable to Confirm.
- Site status: Operating; Temporarily Closed; Permanently Closed; Vacant; Relocated; Address Not Found; Access Restricted; Unable to Verify.
- Contact result: Customer Contacted; Representative Contacted; No Answer; Phone Unreachable; Refused to Engage; Wrong Contact Information; Customer Not Present; Call Back Requested; Unable to Contact.
- Outcome: Successful Visit; Promise to Pay; Payment Made / Payment Claimed; Follow-Up Required; Customer Disputes Debt; Customer Refused Payment; Customer Not Available; Premises Closed; Customer Relocated; Address Invalid / Not Found; Unable to Complete Visit; Escalation Required.
- Business type: Sole Proprietorship; Partnership; Private Limited Company; Public Limited Company; Government / Agency; Association / Organization; Individual / Personal; Other.
- Business nature: Retail; Food & Beverage; Services; Manufacturing; Construction; Logistics / Transportation; Automotive; Healthcare; Education; Professional Services; Technology / IT; Property / Real Estate; Agriculture; Wholesale / Distribution; Other.
- Business status: Active; Temporarily Suspended; Closed; Relocated; Under Renovation; Unable to Verify; Other. Any Other business selection requires an explanation. Rider explicitly declares business applicability; applicable business visits require all three codes.
- Proof: Premises / Site Photo; Business Signboard; Customer / Contact Evidence; Supporting Document; Payment Evidence; Other Supporting Evidence. These are new categories alongside preserved legacy street/premise/meter codes.

Visit purpose qualifies the existing assignment type; it does not replace the seven legacy assignment types or invent imported mappings. Initial assignment-type master policy permits the five purposes below; new types require an approved policy. Purpose policies are versioned metadata on reference_values, with authorized configurable per-assignment overrides.

| Visit purpose | Initial mandatory requirements |
|---|---|
| Normal Site Visit | At least one Premises / Site Photo; visit remarks; outcome |
| Address Verification | Premises / Site Photo; site status; signboard photo when available (explicit Rider declaration) |
| Collection Visit | Premises / Site Photo; visit remarks; outcome; Payment Evidence when payment is recorded/claimed |
| Payment Verification | Payment Evidence; visit remarks |
| Customer Not Found / Invalid Address | Premises / surrounding-area photo; site status; detailed visit remarks |

All visits require availability, site status, contact result, outcome and server-recorded submission time. Payment Made / Payment Claimed requires Payment Evidence regardless of purpose and remains an unverified claim, not a receipt or ledger posting. Call Back Requested, Promise to Pay and Follow-Up Required require a due follow-up. Remarks for Collection Visit record the collection discussion separately when supplied; visit remarks remain mandatory.

Time defaults to the current server time. Rider may record actual time today or yesterday in Asia/Kuala_Lumpur, never a future time. A manually selected time before the current local minute requires a Backdating Reason. The limit is one **calendar day**, not a rolling 24 hours. Preserve occurred_at, original created_at and encrypted reason, and append a redacted backdating audit event. Older corrections require a separately authorized future Admin correction workflow; no current Rider override.

Proof images are private S3 objects, linked to both task and exact collection activity, with uploader/server upload timestamp and immutable submitted metadata. Defaults remain 10 MiB/image and ten clean images/submission; validate raster content, decoded dimensions and count, strip unnecessary EXIF and scan fail-closed. JPEG/JPG and PNG are accepted; preserve previously approved WebP support. HEIC is accepted only where a safe decoder is installed; this runtime does not support HEIC and must give clear conversion guidance. No new decoder dependency is implied.

Completion controls stay disabled while required fields, applicable business details, remarks, category counts, signboard declaration or clean scans are missing. Show anchored editor links and the exact saved draft before confirmation. Submit freezes a policy/visit/remarks/business/proof manifest and moves to submitted/pending verification. Retries reuse the same receipt. Only M07 independent approval completes work; return preserves all previous evidence/submissions and enables a new correction draft.

M14 increment 1 implementation contract (18 October 2026): three global logical email slots use stable email_1/email_2/email_3 codes and append-only version history. Draft edits compare lock_version; publishing requires the existing reserved templates.approve grant, preserves approver/time and atomically retires the previous approved version. Editing approved/archived wording creates a new draft version; one draft or pending-review version per slot prevents conflicting parallel draft tracks. Approval permissions follow the documented separate-grant contract; no new role-name shortcut or implicit approver grant is introduced. Template syntax is literal plain text with {{placeholder}} tokens from the five-key registry, never Blade/HTML execution. Required flags may refer only to used tokens. Seeded names/subjects/bodies are the owner-approved version 1.0 defaults below. Generic template access is organization-wide because templates have no client owner; personalized preview additionally requires communications.compose, customers.view and current client scope. company_name explicitly identifies the commissioning Client name; account_number comes from a selected current linked account (or authorized task context). outstanding_amount and payment due_date are unavailable until their approved financial source exists. Missing required data blocks preview and optional missing fields are explicitly listed. UI65 previews saved versions, labels drafts/samples, and records safe preview audit without recipient/body PII. Recipient batches, actual sends, private generated PDFs and WhatsApp services remain subsequent M14 increments, visibly unavailable.

M14 owner-approved implementation contract (18 October 2026): initial defaults are Friendly Payment Reminder, Outstanding Balance Follow-Up, and Final Follow-Up Notice, using the exact subjects/bodies supplied by the owner in this session. All five registry placeholders are required in these version 1.0 defaults. Preserve text and names in the initial-default seed; no manual client/payment data is invented. Statuses are Draft, Pending Approval, Approved, Rejected, Archived. Drafts are editable with a lock version; submission freezes content for reserved-permission review. Rejection requires a retained reason/reviewer/time; revision creates a new draft. The existing approved version remains active while a replacement is draft, pending or rejected; replacement approval atomically archives the previous approved version. Published and rejected history cannot be rewritten. Generic template approval follows the separate templates.approve grant; no additional self-approval rule is inferred.

Initial approved defaults record approval_source=owner_default and an approval_reference identifying the 18 October 2026 owner authorization, with no fabricated user FK; approved_at records application of this authorized default. Subsequent approvals record the authorized user's approved_by/time. Add name, submitted_by/at, rejected_by/at, encrypted rejection_reason_ciphertext and retained approval-source/reference metadata on the canonical communication_templates table. Seed only missing initial versions and preserve later business edits. Display the initial integer version as 1.0. Explicit synthetic previews may use labeled illustrative amount/date values; those values never enter actual-customer context or any delivery. Actual-customer previews continue to block required financial amount/payment date without approved provenance. Only Approved versions may enter the later recipient/send workflow; current template preview creates no communication or letter artifact.

### M14 initial approved wording — owner authorization, 18 October 2026

All five placeholders are required for each initial version 1.0. Subjects and bodies below are literal templates, not executable Blade. Future wording remains configurable through draft → pending approval → approved/rejected, with archived history.

**Option 1 — Friendly Payment Reminder**

Subject: `Payment Reminder for Account {{account_number}}`

```text
Dear {{customer_name}},
We hope you are well.
This is a friendly reminder regarding your account {{account_number}} with {{company_name}}. Our records currently show an outstanding amount of {{outstanding_amount}}, with a due date of {{due_date}}.
If payment has already been made, please disregard this reminder. Otherwise, we kindly request that you arrange payment or contact us if you require further clarification regarding your account.
Thank you for your attention to this matter.
Regards,
{{company_name}}
```

**Option 2 — Outstanding Balance Follow-Up**

Subject: `Follow-Up on Outstanding Balance — Account {{account_number}}`

```text
Dear {{customer_name}},
We are following up regarding the outstanding balance associated with account {{account_number}} under {{company_name}}.
As of this notice, the outstanding amount is {{outstanding_amount}} and the recorded due date is {{due_date}}.
Please review the account details and arrange payment as soon as possible. If you have already made payment or believe the information is incorrect, please contact the appropriate representative so that the account can be reviewed.
We appreciate your cooperation and prompt attention.
Regards,
{{company_name}}
```

**Option 3 — Final Follow-Up Notice** (Final Follow-Up / Action Required)

Subject: `Action Required: Outstanding Account {{account_number}}`

```text
Dear {{customer_name}},
This message is a further follow-up regarding account {{account_number}} with {{company_name}}.
The account currently reflects an outstanding amount of {{outstanding_amount}}, with a due date of {{due_date}}.
Please arrange payment or contact us promptly to discuss the account status. If payment has already been completed or the account details require clarification, please notify us so that the matter can be reviewed accordingly.
This notice is intended to ensure that the account receives the necessary attention and follow-up.
Regards,
{{company_name}}
```

### M14 recipient and service-email eligibility increment — owner policy, 18 October 2026

Use canonical communication_preferences (not a duplicate communication_permissions table) for versioned service-collection email authority. Approved basis codes: EXISTING_ACCOUNT_RELATIONSHIP, CLIENT_AUTHORIZED_COLLECTION, CUSTOMER_PROVIDED_CONTACT, EXPLICIT_SERVICE_CONSENT, LEGAL_OR_REGULATORY_BASIS. States: allowed, blocked, unknown, withdrawn, invalid_address, hard_bounced, under_review. No marketing authority or force-send exemption is implied. Unknown/incomplete, any active blocking restriction, pending stop request, withdrawn/invalid/hard-bounced/under-review records are ineligible.

Extend preference records with client/account/optional task scope; encrypted evidence (source/reference, email/email-source, notes, consent notice/method/granted-at where relevant, client-assignment/authority details where relevant); encrypted reason; submitted actor/time, review state/actor/time, superseded record and UUID replay key. An allowed proposal requires a current linked account/contact and a documented basis/source/reference. CLIENT_AUTHORIZED_COLLECTION additionally requires a matching active assignment/task context and authority reference; explicit consent requires notice version, method and nonfuture grant time. Legal basis remains subject to jurisdiction-specific production review. Restriction requests may be customer-wide or contact/account-specific and suppress immediately while pending. Approved changes append a version and retire the previous current record; rejected requests and evidence remain immutable. Block dominates allow, including a matching destination fingerprint across duplicate contacts; account-specific allows cannot erase wider restrictions.

Spatie permissions: communication-permission.create/update/block/approve/override. All are initially reserved, with no automatic grant to existing Admin/Rider accounts. Create/update grants submit proposals; block grant may record immediate restrictive decisions. Approve reviews pending proposals; changing an active restrictive state to allowed or rejecting a pending restrictive request also requires override. No role-name bypass and no inferred legal-notice exception. Audit retains customer reference, previous/new status/basis, actor/time/IP/user-agent and an evidence fingerprint; sensitive reason/source/email remain encrypted in the linked immutable domain record rather than exposed in audit JSON.

UI66 selects explicit scoped contacts within one client, approved template and optional current linked account/task per recipient. Filters narrow the candidate list; they never implicitly select other pages. Selection/preview bounds are configurable (initial maximum 250 rows per preview, independent of the future 1,000/day sending limit). Validate email/context, preferences, required source data, dedupe exact canonical destination/context and exclude every conflicting shared-address group until explicitly corrected and previewed again. Persist all selected rows and safe exclusion codes in canonical communications, under communication_batches, with encrypted recipient/rendering/context and a frozen digest. Counts reconcile exactly; no BCC fan-out. Missing financial provenance is ineligible, never a zero balance or task-derived payment date.

UI67/UI68 provide saved recipient-specific previews, exclusion reasons, history, current-source freshness checks and creator-only cancellation of unsent previews. Source/template/permission/preference changes make a saved preview stale; rebuilding creates a separate immutable preview. Saving a preview is not confirmation of sending: no queued/sent/delivered claims, provider reservation or outbox send occurs in this increment. Actual send confirmation remains unavailable until provider capability, recipient eligibility, quotas/attempts/receipts and authorization rechecks exist. Existing provider/financial/storage integration gates remain.

### M14 queue/quota implementation increment — 18 October 2026

Continue UI67/UI68 with exact-digest confirmation, durable email outbox/Redis jobs, canonical attempts/events/quota reservations and scoped delivery history. No vendor is selected or activated by this increment. The default transport supports no account and performs no network operation; production confirmation requires a selected enabled capability-verified account and an explicitly installed supporting transport. Nonproduction additionally requires an explicit recipient allowlist; no sandbox bypass. Provider configuration/credentials, signed receipt ingestion/reconciliation and production legal approval remain activation dependencies.

Keep frozen preview counters/context immutable and derive live delivery counters from canonical envelopes. Confirm only currently eligible rows after fresh digest/permissions/client-scope checks, with replay-safe confirmation and audited actor/time. Dispatch rechecks actor permissions, client scope, contact/account authority, current recipient/template/context and sandbox eligibility. Application cap defaults to 1000/Kuala Lumpur calendar day via centralized environment-backed configuration (system_settings administration remains future); verified provider rolling quota and per-second admission may lower it. PostgreSQL advisory locks serialize global/provider reservations, Redis middleware also limits queue calls, and quota shortages persist next-attempt time instead of discarding recipients. A 5000 cap is configuration plus verified provider entitlement, not a schema change.

Persist attempts/reservations before external transport without holding a database transaction across the network. Accepted is not delivered. Only an explicit definitive pre-acceptance transient result releases capacity and retries, with configurable capped backoff. Generic exceptions, worker interruption or ambiguous timeouts become unknown, retain reservations and never automatically resend or fail over. Overdue pending attempts are reconciled to unknown by the dispatcher; provider receipts are a later authenticated integration, never fabricated. Batch completed means processing finished, not delivery confirmation. Authorized owner cancellation cancels only unattempted queued work, retaining accepted/unknown/in-flight history. Resume is for paused pending work under current authority and audited permissions; exhausted definitive failures require a new reviewed preview in this increment. Existing default financial placeholders remain unavailable without approved M08 sources.

#### Owner-approved SES configuration update — 18 October 2026 (supersedes earlier quota/provider assumptions)

Amazon SES is selected as the primary email provider; preferred configurable region ap-southeast-1. The actual production domain, dedicated sending subdomain, role-based sender, reply-to, DNS ownership/SES verification and SES account quotas are NOT supplied or verified. Examples such as mail.debtops.intexcode.com are not configured as real verified senders. Credentials remain in environment/AWS secret management and never in source, documentation or communication history.

The owner replaces the earlier KL-calendar-day application allowance with **1000 recipient deliveries per rolling 24 hours**, configurable via MAIL_DAILY_LIMIT; initial application rate is **2/second**, configurable via MAIL_RATE_LIMIT_PER_SECOND. Effective daily capacity and rate are the lower of business configuration and verified SES account entitlement. Shared global admission must cover all DebtOps email workers/accounts; provider account rolling usage/rate is separate. Unknown/reserved attempts remain held until reconciliation. Increasing to 5000 requires business approval and verified SES quota/rate/reputation; no schema redesign.

Local/DEV/UAT always enforce exact approved test-recipient allowlists, regardless of a UI or sandbox-flag toggle. MAIL_SANDBOX_ENABLED defaults true. MAIL_LIVE_SENDING_ENABLED defaults false; no verified account/domain/quota/test addresses are provisioned. Production activation additionally requires SES production access, verified domain/DKIM/SPF/DMARC/MAIL FROM and sender, approved wording and evidence, tested queue/throttling, bounce/complaint/suppression handling and explicit approval. This increment must not enable production or live confirmation against an unverified account. Signed bounce/complaint receipt integration remains required before production activation; hard bounce suppresses and complaint blocks pending review, while soft bounce is distinct and does not prove delivery. No SMTP credential, AWS key or secret is requested.

Recipient history retains batch/customer/account/recipient/template version/subject, queue and acceptance timestamps, provider/message ID, attempts/retry count and sanitized failures. Acceptance/sent is not delivery. Receipt/bounce/complaint states must derive from authenticated provider evidence, never local inference. Queue/quota/sandbox/history development proceeds with fake transport tests and the SES adapter behind the verification/activation gates.

### M14 authenticated SES receipts and suppression increment — 18 October 2026

Implement a disabled-by-default Amazon SNS endpoint for SES event notifications, with strict registered account/topic/region checks, bounded request/certificate sizes, trusted regional HTTPS SNS certificate URLs only, no redirects and native OpenSSL validation of SNS SignatureVersion 1/2 canonical bytes (prefer topic version 2). Verify before durable processing. Do not follow subscription confirmation URLs, provision SNS topics or activate accounts. No actual topic ARN, AWS account ID, domain or public callback has been verified. Old signed retries are accepted through durable inbox deduplication; future/skewed or mismatched source/context events require review, not guessed assignment.

Use canonical integration_events for encrypted minimal verified payloads, content digests and pending/processed/needs_resolution state, with durable outbox/Redis receipt workers. Correlate by the exact SES message ID and/or this application's durable attempt tag, always checking account, approved sender and the single frozen recipient. Unmatched early receipts remain retryable for resolution; conflicting IDs/tags, mismatched destinations and unsupported events never attach to a guessed customer. Preserve normalized communication_events with authenticated source IDs. Send acceptance is not delivery. Out-of-order acceptance cannot regress delivery; complaint/hard bounce retain stronger suppression evidence, and soft bounce/delay does not imply delivery or authorize resend.

For Permanent bounce record hard_bounced; complaint and undetermined bounce record under_review restrictions. Provider evidence creates a pending restrictive canonical communication_preferences observation with nullable human recorder and an explicit source integration event; pending restrictions suppress immediately, across canonical duplicate email destinations. No human approval or consent is fabricated. Existing reviewer/override controls govern later review; preferences/history are retained. Email address correction does not silently erase the original address suppression.

Authenticated provider evidence may resolve an unknown envelope to accepted/delivered/bounced/complained/failed and convert held unknown quota to consumed, never release it for resend. Original terminal attempt ambiguity stays immutable; the new receipt event records reconciliation provenance. Receipts are safe before/after the sending worker returns; worker completion must not overwrite already reconciled outcomes. No manual force-delivered or blind resend control exists. Display derived delivery/bounce/complaint timestamps and paginated receipt provenance. Keep production sending hard-gated until real SES/SNS/DNS/queue/suppression acceptance and explicit approval; local tests use synthetic signatures and fake HTTP/transport, never customer email.

SES receipt activation additionally requires a verified SNS topic resource policy restricting publishing to the approved SES source/account/configuration set. Signature verification proves SNS delivery, not independently which AWS principal published the content. Record sns_policy_verified only after this resource-policy check; no such verification is seeded. Sending may select a verified nonsecret configuration_set / MAIL_SES_CONFIGURATION_SET to publish attempt tags and events through that registered SNS destination. No topic, configuration set, subscription, AWS account or public HTTPS endpoint is provisioned by development. Production receipt requests require HTTPS. The existing Sentry policy excludes request bodies; do not log SNS/SES payloads, recipient addresses or raw headers.

M14 private letters increment (18 October 2026): UI65/UI67 uses a currently approved email-slot version with an actual scoped customer/account/task context. Freeze escaped literal subject/body, placeholder provenance, requester and a confirmation digest in the canonical encrypted communication envelope (channel=letter). Sample previews cannot generate customer artifacts; missing approved financial source data blocks generation. Require the separate reserved communications.generate_letter permission plus communications.compose/view and customer/task scope; no existing grants are broadened. Confirm exact saved content; changed sources invalidate preview. Durable outbox and Redis exports work generate one private, encrypted S3 PDF via the approved Dompdf renderer, with external resources, PHP and JavaScript disabled. Recheck requester authority and template approval at execution. Failed work retains a safe error and supports explicit authorized retry; duplicate jobs cannot replace a generated artifact. Generated means a file exists, not email delivery or postal dispatch. Reauthorize task/customer access at signed, user-bound, short-lived application downloads and verify content digest. Audit preview/confirmation/generation/retry/download without storing message text in logs. Reuse communications, communication_events, documents and outbox_events; no letter_templates or parallel delivery table. Existing email-only preferences do not authorize or record postal dispatch. Real S3/Redis and deployment integration remain acceptance gates.

Private-letter implementation note: communications.requested_by is a nullable FK to users, mandatory and immutable for letter envelopes and null for other implemented channels. Letter states are draft → queued → generated/failed/cancelled, with explicit failed → queued retry. Current template approval, source revisions and permissions are rechecked at execution. Clean letter PDF means trusted server-generated escaped content, not an uploaded-file scanner attestation. Provider send fields remain null. See README.md private-letter validation and operational notes.

Nexora branding amendment (27 October 2026): The official product name is Nexora, using the supplied full wordmark and icon artwork with white #FFFFFF, navy #023047 and orange #FB8500. Earlier DCMS/DebtOps product-name references are historical. This presentation-only amendment does not rename schema, routes, permission keys, integration tags, approved legal notices, or existing records.

M09 operational task-report increment (27 October 2026): implement UI27/UI41 for task, rider_assignment, rider_performance, completed_task and verification families using canonical TaskAccess portfolio scope plus reports.view/tasks.view/tasks.monitor; verification additionally requires tasks.verify. CSV/PDF require reports.export. Filters: task-created MYT date range, client, assignment batch, current Rider staff number, work status and exact IWK reference. Approved initial columns are operational identifiers/status/dates/flags, masked account reference and staff number, without names, contacts, addresses, financial amounts or source workbook contents. Rider performance counts currently allocated tasks and verified/completed tasks only, never submissions as completed or balances as receipts. Full customer/attendance/visit/communication/import families, historical allocation performance, legacy 88-column parity, image bundles and presets remain explicit follow-on scope, not fabricated outputs.
Freeze bounded (maximum 5,000 tasks) source IDs and projected values together in a repeatable-read transaction; exports create a new clearly timed snapshot. Store encrypted snapshot data in canonical export_jobs, publish via durable outbox to Redis exports, generate escaped CSV or trusted Dompdf PDF, and store encrypted private S3 artifacts through documents. Recheck current requester permissions and every source task's portfolio at execution and signed user-bound download. Artifact expiry is 24 hours, configurable, with five-minute links and audited request/generation/download. Denied or changed scope cancels output; lost storage/retries never report success or replace completed files. No role grants or existing workflow changes.
