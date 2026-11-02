# DebtOps — Interface Inventory and Prototype Specification

**Confirmed scope update — 16 October 2026.** The user's confirmed DebtOps requirements take precedence over the earlier DRMS parity baseline. **C** means confirmed DebtOps capability; **P** denotes an implementation choice within that scope; **O/U** continue to describe only observed/unverified DRMS behaviour. Missing DRMS evidence does not defer Customer Management, automated column mapping, Rider workflows, attendance, Call Centre, email or WhatsApp. Provider selection, template wording and business policy values remain configuration/implementation decisions, not omitted features. See the Feature Coverage Matrix in Plan.md.

Terminology: **Customer** is the UI/business term for the existing party record in **debtors** (Eloquent **Debtor**); **Rider** is collection staff in **staff**, linked one-to-one to **users**. **Client** remains the commissioning company. Retain these existing physical names and models: do not add parallel customers/customer_contacts/customer_addresses/rider_profiles/task_assignments/attachments/task_verifications tables for the same facts.

InteXcode (IXC) · 16 October 2026 · Structural specification, not a visual copy of DRMS.

## Current implementation — November 2026

**This section overrides anything older in this document.** Text marked O or U, and IWK labels in particular, remain evidence about the reference system only.

| Area | What is built now |
|---|---|
| Clients | Removed. There is one tenant, so there are no client scopes or client_id columns. |
| Customer Management (M13) | Removed: customer pages, categories, the customer import type, and account editing pages. `debtors` stays as the internal record behind tasks, calls and communications, created automatically from Create Task or Upload Task. |
| Task ID | Replaces the IWK ID. Comes from the workbook or Create Task, must end in a number, and is read by its trailing digits within its assignment: FG001 is 1 and DT2304 is 2304. Numbers are unique per assignment. |
| Upload Task (M04) | Fixed contract with automatic column detection. Missing required columns fail immediately with a message. History lists successful uploads, searched by file name and uploaded date. A blank Excel template can be downloaded. |
| Assign Task (M05) | Choose the assignment, then Single (12), Selected (12, 15, 18), Range (From 10 - To 20) or Range and Selected (10-20 plus 25). Tasks ticked on Manage Task still go straight to Assign Task. |
| Delete task (M05) | Admins with `tasks.delete` can delete tasks that have no Rider and no recorded work. The record is hidden, not erased, because task history is immutable, and its task ID can be reused. Started work uses Cancel. |
| Amounts owed (M08) | Each task has append-only encrypted balance records: outstanding balance, monthly charge and balance date, from upload or Create Task. Customers pay the client directly. |
| Payment claims (M06, M07) | The Rider sees the balance, enters the amount paid and adds a Payment Evidence photo. A reviewer must confirm the photo matches the amount. An equal amount clears the balance, a lower amount needs a second confirmation as a part payment, and a higher amount cannot be approved. Claims are reviewed one at a time, never in bulk. |
| Reports (M09) | Show total outstanding and verified collected, per task and per Rider. Only approved claims count as collected. Task ID accepts one ID, several, a range or a mix. |
| Proof storage | `PROOF_DISK` and `PROOF_SCAN_REQUIRED` allow local storage without scanning for development. Production always scans. |

## Scope and traceability

Read [Plan.md](Plan.md) for evidence E01–E24 and unresolved G01–G08, [Module.md](Module.md) for field dictionaries and business flows, and [Database.md](Database.md) for persistence. O = observed; P = proposed; U = unverified. The inventory includes observed pages, page states and contextual variants, followed by proposed screens needed to implement improvements. Proposed rows must not be counted as discovered DRMS pages. All requested interface attributes are resolved through the common contract below plus the per-screen overrides. A route link alone is not an exercised page; such limits are stated.

## Common interface contract (P unless noted)

| Attribute | Default for every inventory entry unless explicitly overridden |
|---|---|
| Related module / user role / purpose | Supplied by each entry and its M01–M12 module; role grants proposed until role demonstrations. |
| Page layout | Shared shell: fixed header, collapsible sidebar, breadcrumb/title, main responsive content and footer. Standalone access cards and separate settings layout are explicitly identified. |
| Header | DebtOps / InteXcode identity, scoped search, own notifications and account menu. Show environment in nonproduction. |
| Sidebar / navigation | Dashboard; Assignments (batches/import/allocation/tasks/verification); Reports; Staff; Databank; authorized Administration. Permission-aware. The observed menu graph is in Module.md. |
| Breadcrumb | Home → module → list → record/action; access cards have no breadcrumb. List/filter state preserved on Back. |
| Cards | None unless listed; metric/progress/error cards use text labels as well as colour. |
| Tables | None unless listed; all proposed tables use semantic headers, accessible sort, explicit column selection, scoped query and stable ordering. |
| Forms / fields / dropdowns | Entry's Fields are the complete baseline; source field detail is inherited explicitly from M06 for task variants. Required marks, help, labels and field errors; no password autofill into data fields. |
| Buttons / actions | Entry's Controls; distinguish submit/cancel/destructive actions; disable during processing and prevent duplicate submits. |
| Filters / search | None unless listed. List search has debounce, clear/reset and active filter chips. Global search does not replace module search. |
| Modals | None unless listed. P confirmations for sensitive/bulk changes, accessible focus trap and escape/cancel. Never hide mandatory errors behind a dialog. |
| Tabs | None unless listed. Task tabs and reference tabs are proposed; DRMS used collapsible sections in reviewed task page. |
| Status indicators | Text badges for applicable entity state; include submitted/review/payment markers separately. Do not convey state through colour alone. |
| Notifications | P success/error feedback after actions; queued operation shows persisted progress and later own notification. O only where expressly stated. |
| Validation messages | P field-specific, server-backed; summary links to errors. Network/stale session/permission/conflict errors preserve safe input. Existing server validation mostly U. |
| Empty states | P explain no records vs no matches vs no access; offer permitted action/reset. Observed empty states explicitly listed. |
| Pagination | None without table. P server pagination 25/50/100 for regular lists, stable ID tie-break, cursor pagination for large history. O DRMS sampled lists show 10 rows and numbered Previous/Next. |
| Expected interaction / previous-next | Each entry's Navigation. Previous/next task must retain authorized filtered order; first/last controls disabled. |
| Loading / errors / accessibility | P loading skeleton, progress for jobs, actionable retry/correlation ID, keyboard labels, visible focus, 320px mobile usability; no raw exception paths or full financial/identity data in toast. |

## Inventory index

| ID | Interface / state | Module | Evidence |
|---|---|---|---|
| UI01 | [Admin Login](#ui01) | M01 | O E01 |
| UI02 | [Password recovery](#ui02) | M01 | O E02 |
| UI03 | [First-time activation](#ui03) | M01 | O E03 |
| UI04 | [Welcome home](#ui04) | M01 | O E04 |
| UI05 | [Account settings](#ui05) | M01 | O E21 |
| UI06 | [Logout outcome](#ui06) | M01 | O E22 |
| UI07 | [Assignment dashboard](#ui07) | M02 | O E05 |
| UI08 | [Staff progress summary](#ui08) | M02 | O E06 |
| UI09 | [Active assignment register](#ui09) | M03 | O E07 |
| UI10 | [Archived assignments](#ui10) | M03 | O E08 |
| UI11 | [Create assignment](#ui11) | M03 | O E09 |
| UI12 | [Edit assignment](#ui12) | M03 | O E09 |
| UI13 | [Uploaded / assigned task list](#ui13) | M03/M06 | O E10; assigned list P |
| UI14 | [Excel Upload](#ui14) | M04/M08 | O E11 |
| UI15 | [Import preview, errors and progress](#ui15) | M04 | P; addresses G02 |
| UI16 | [Allocate tasks](#ui16) | M05 | O E12 |
| UI17 | [Bulk task management](#ui17) | M05 | O E13 |
| UI18 | [Bulk operation preview and result](#ui18) | M05 | P |
| UI19 | [Task information and review controls](#ui19) | M06/M07/M08 | O E14 |
| UI20 | [Vacant task detail variant](#ui20) | M06 | O E14 variant |
| UI21 | [Domestic / commercial recovery variants](#ui21) | M06 | O six types sampled including Vacant; COM DR detail U |
| UI22 | [Activity, visit, TPS and follow-up editor](#ui22) | M06 | P derived from observed outcome/reminder fields |
| UI23 | [Evidence uploader and viewer](#ui23) | M06 | O empty and populated categorized photo panel; uploader P |
| UI24 | [Customer Details and Account History](#ui24) | M06 | P normalized navigation |
| UI25 | [Verification search](#ui25) | M07 | O E15 |
| UI26 | [Verification listing and decision](#ui26) | M07 | O E15 empty and populated list; independent review design P |
| UI27 | [Basic Reports, Filters and Exports](#ui27) | M09 | O E16 |
| UI28 | [Financial update exception review](#ui28) | M08 | P; G04 |
| UI29 | [Image export selection and download](#ui29) | M09 | O category/button; job UI P |
| UI30 | [Rider / Staff Directory](#ui30) | M10 | O E17 |
| UI31 | [Create Rider / Staff](#ui31) | M10 | O E18 |
| UI32 | [Edit Rider / Staff](#ui32) | M10 | O E18 |
| UI33 | [Admin Attendance Dashboard/List](#ui33) | M10 | C; O E19 construction page is reference only |
| UI34 | [Databank search and results](#ui34) | M11 | O E20 |
| UI35 | [Databank upload](#ui35) | M11 | O E20 |
| UI36 | [Header tools and notification dropdown](#ui36) | M12 | O E23 shell |
| UI37 | [Notification centre](#ui37) | M12 | P |
| UI38 | [Roles, permissions and access review](#ui38) | M12 | P |
| UI39 | [Reference and template configuration](#ui39) | M12 | P |
| UI40 | [Audit explorer](#ui40) | M12 | P |
| UI41 | [Import/export/operation job monitor](#ui41) | M12 | P |
| UI42 | [Verification-context task detail](#ui42) | M06/M07 | O E15 |
| UI43 | [Global IWK search results](#ui43) | M06/M12 | O E24 |
| UI44 | [Search-context task detail](#ui44) | M06/M12 | O E24 |
| UI45 | [Layout customization panel](#ui45) | M12 | O E23 |
| UI46 | [Rider Login](#ui46) | M01 | C confirmed scope; P implementation structure |
| UI47 | [Rider Home / Dashboard](#ui47) | M02/M06/M10 | C confirmed scope; P implementation structure |
| UI48 | [My Tasks](#ui48) | M06 | C confirmed scope; P implementation structure |
| UI49 | [Rider Task Details and Customer](#ui49) | M06/M13 | C confirmed scope; P implementation structure |
| UI50 | [Business Nature Form](#ui50) | M06 | C confirmed scope; P implementation structure |
| UI51 | [Completion Review](#ui51) | M06/M07 | C confirmed scope; P implementation structure |
| UI52 | [Completion Confirmation](#ui52) | M06/M07 | C confirmed scope; P implementation structure |
| UI53 | [Rider Attendance Today and History](#ui53) | M10 | C confirmed scope; P implementation structure |
| UI54 | [Rider Profile and Performance](#ui54) | M10/M05 | C confirmed scope; P implementation structure |
| UI55 | [Admin Task Monitoring](#ui55) | M05/M07 | C confirmed scope; P implementation structure |
| UI56 | [Manual Task Create / Edit](#ui56) | M05 | C confirmed scope; P implementation structure |
| UI57 | [Excel Column Mapping Review](#ui57) | M04 | C confirmed scope; P implementation structure |
| UI58 | [Excel Import History and Result Detail](#ui58) | M04 | C confirmed scope; P implementation structure |
| UI59 | [Attendance Schedule, Policy and Correction](#ui59) | M10 | C confirmed scope; P implementation structure |
| UI60 | [Call Centre Workspace](#ui60) | M11/M13 | C confirmed scope; P implementation structure |
| UI61 | [Customer Categories and Membership](#ui61) | M13 | C confirmed scope; P implementation structure |
| UI62 | [Customer List](#ui62) | M13 | C confirmed scope; P implementation structure |
| UI63 | [Customer Create / Edit](#ui63) | M13 | C confirmed scope; P implementation structure |
| UI64 | [Communication History](#ui64) | M14/M13/M11 | C confirmed scope; P implementation structure |
| UI65 | [Three Email Templates / Letter Options](#ui65) | M14 | C confirmed scope; P implementation structure |
| UI66 | [Bulk Email Recipient Selection](#ui66) | M14 | C confirmed scope; P implementation structure |
| UI67 | [Email Personalized Preview and Send Confirmation](#ui67) | M14 | C confirmed scope; P implementation structure |
| UI68 | [Bulk Email Result and Sending History](#ui68) | M14 | C confirmed scope; P implementation structure |
| UI69 | [WhatsApp Conversation](#ui69) | M14 | C confirmed scope; P implementation structure |
| UI70 | [WhatsApp Voice and Provider Capability Settings](#ui70) | M14/M12 | C confirmed scope; P implementation structure |

<a id="ui01"></a>

## UI01 — Admin Login

| Attribute / override | Specification |
|---|---|
| Module / evidence | M01 · O E01 |
| User role | Admin / authorized office staff; Rider entry UI46 shares authentication and permissions. |
| Purpose | Admin Login; successful sign-in redirects to authorized Admin dashboard UI07, never grants permissions from entry route. |
| Layout / header / sidebar / breadcrumb | Standalone access card; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | Email; password; remember me |
| Buttons / actions | Log In; Forgot password; First time login |
| Cards / tables / modals / tabs / status / empty state | No cards/table/filter/modal/tabs. Required browser message observed on empty submit. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | Valid credentials → UI04; recovery → UI02; first login → UI03. Generic invalid-credential response and throttle P. |

<a id="ui02"></a>

## UI02 — Password recovery

| Attribute / override | Specification |
|---|---|
| Module / evidence | M01 · O E02 |
| User role | Public staff |
| Purpose | Password recovery for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Standalone access card; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | Email |
| Buttons / actions | Request Change Password; Login |
| Cards / tables / modals / tabs / status / empty state | Instruction above email; no table/cards/filters/tabs. Email delivery and response U. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | UI01 → UI02 → neutral acknowledgement P → UI01. Never reveal whether an email exists. |

<a id="ui03"></a>

## UI03 — First-time activation

| Attribute / override | Specification |
|---|---|
| Module / evidence | M01 · O E03 |
| User role | Invited staff |
| Purpose | First-time activation for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Standalone access card; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | Registered email; user name; password; retype password; required terms checkbox with PDPA link |
| Buttons / actions | Register; Login |
| Cards / tables / modals / tabs / status / empty state | Administrator-registered email instruction. All inputs required in markup; no table/cards/tabs. Actual activation outcome U. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | UI01 → UI03 → activate using expiring invitation P → UI01/UI04. Terms version retained P; agreement not submitted during review. |

<a id="ui04"></a>

## UI04 — Welcome home

| Attribute / override | Specification |
|---|---|
| Module / evidence | M01 · O E04 |
| User role | Authenticated user |
| Purpose | Welcome home for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Shared shell, welcome panel; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | None |
| Buttons / actions | Sidebar menus; account menu |
| Cards / tables / modals / tabs / status / empty state | Welcome text and logo; no business metrics/table/forms/filter. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | Successful login → welcome; menu → dashboard, assignments, reports, HR, databank; account → UI05/UI06. |

<a id="ui05"></a>

## UI05 — Account settings

| Attribute / override | Specification |
|---|---|
| Module / evidence | M01 · O E21 |
| User role | Own account |
| Purpose | Account settings for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Separate account page, avatar/profile and password form; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | Read-only staff no/first/last name; old password; new password; retype; avatar file P validation |
| Buttons / actions | Photo browse icon; Update; dashboard link |
| Cards / tables / modals / tabs / status / empty state | No sidebar observed on this page. No table/search/cards/tabs. Photo chooser exact workflow U. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | Account menu → settings → success notice P; return UI07. Password change never exercised. |

<a id="ui06"></a>

## UI06 — Logout outcome

| Attribute / override | Specification |
|---|---|
| Module / evidence | M01 · O E22 |
| User role | Authenticated user |
| Purpose | Logout outcome for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Redirect to standalone login; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | None |
| Buttons / actions | Log Out in account menu; then login controls |
| Cards / tables / modals / tabs / status / empty state | No separate logout form observed. P session-expired/logged-out notice. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | Account menu → logout → UI01 observed; protected access/session revocation regression test P. |

<a id="ui07"></a>

## UI07 — Assignment dashboard

| Attribute / override | Specification |
|---|---|
| Module / evidence | M02 · O E05 |
| User role | Scoped management / staff P |
| Purpose | Assignment dashboard for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Shared shell, batch header, four metric cards, progress panel; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | Client/batch/date/Rider/customer-category filters; explicit business timezone and as-of timestamp. |
| Buttons / actions | View All Staff; widget collapse/settings/remove icons |
| Cards / tables / modals / tabs / status / empty state | Total customers; active/assigned/approved-completed/pending-verification tasks; active Riders; attendance by status; visit/outcome statistics; recent activities. Cards drill into matching scoped lists; no cash collection KPI inferred from balances. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | Sidebar → dashboard; staff progress → UI08; metric drill-down UI13 P. Define numerator/time zone before implementation. |

<a id="ui08"></a>

## UI08 — Staff progress summary

| Attribute / override | Specification |
|---|---|
| Module / evidence | M02 · O E06 |
| User role | Scoped supervisor/manager P |
| Purpose | Staff progress summary for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Shared shell, batch/date header, staff list panel; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | P batch/date filters |
| Buttons / actions | Dashboard breadcrumb; collapse/remove panel |
| Cards / tables / modals / tabs / status / empty state | Sample staff list empty; actual row columns U. P table collector/allocated/submitted/verified/outstanding/today. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | UI07 → UI08 → collector tasks UI13 P; empty message offers clear filters. |

<a id="ui09"></a>

## UI09 — Active assignment register

| Attribute / override | Specification |
|---|---|
| Module / evidence | M03 · O E07 |
| User role | Administrative / management P |
| Purpose | Active assignment register for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Shared shell and full-width register; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | Table search; P client/type/state/date filters |
| Buttons / actions | Add New Assignment; Archive link; Copy/CSV/PDF/Print/Column visibility; row name link |
| Cards / tables / modals / tabs / status / empty state | O #, ID, Name, Date, Type, Upload, Assign, Complete, Payment Update, Image Dir, Status; many responsive-hidden. Sort/search/page/export and plus expansion. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | Assignment menu → register → UI11/UI12; archive → UI10; row editing preserves filters P. |

<a id="ui10"></a>

## UI10 — Archived assignments

| Attribute / override | Specification |
|---|---|
| Module / evidence | M03 · O E08 |
| User role | Administrative / management P |
| Purpose | Archived assignments for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Same register layout as UI09; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | P table search and archive filters |
| Buttons / actions | Active list link; Add New Assignment; P reopen with reason |
| Cards / tables / modals / tabs / status / empty state | Observed No record and PHP notice. Table only when records exist P; error details must be private. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | UI09 ↔ UI10; archived row → read-only UI12 P. No restore semantics verified. |

<a id="ui11"></a>

## UI11 — Create assignment

| Attribute / override | Specification |
|---|---|
| Module / evidence | M03 · O E09 |
| User role | Administrative P |
| Purpose | Create assignment for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Shared shell, single metadata form; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | Type; name; start date; due date; description; DCA code/name; LA name; state; Active/Archive |
| Buttons / actions | Save; back |
| Cards / tables / modals / tabs / status / empty state | Seven type options; 14 state options; no table/search/cards/modal/tabs. P grouped identity/schedule/client fields. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | UI09 → create → validation or saved UI12/UI13 P. Required fields/date ordering P. |

<a id="ui12"></a>

## UI12 — Edit assignment

| Attribute / override | Specification |
|---|---|
| Module / evidence | M03 · O E09 |
| User role | Administrative P |
| Purpose | Edit assignment for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Same metadata layout as UI11; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | UI11 fields populated from assignment |
| Buttons / actions | Update; View Task; back |
| Cards / tables / modals / tabs / status / empty state | Active/Archive dropdown; P version conflict banner and archive impact dialog. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | UI09 → edit; View Task → UI13; successful save returns with filters P. |

<a id="ui13"></a>

## UI13 — Uploaded / assigned task list

| Attribute / override | Specification |
|---|---|
| Module / evidence | M03/M06 · O E10; assigned list P |
| User role | Scoped admin / collector P |
| Purpose | Uploaded / assigned task list for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Shared shell, batch/date header, task table; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | O table search; P status/collector/due filters |
| Buttons / actions | Eye detail; Upload Task; table exports/column visibility; paging |
| Cards / tables / modals / tabs / status / empty state | Full observed headers # action, IWK ID, SAN/sewacc, Payment Update, Owner 1, Address, Task Completed, Action By. Both plain and responsive export table variants; pagination where enabled. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | UI12 → tasks → UI19/20/21; Upload → UI14. P separate collector tab defaults to own tasks. |

<a id="ui14"></a>

## UI14 — Excel Upload

| Attribute / override | Specification |
|---|---|
| Module / evidence | M04/M08 · O E11 |
| User role | Administrative / finance P |
| Purpose | Excel upload for customer, debt_account, assignment_batch, task (assignment), payment_update or databank import; C automated mapping follows. |
| Layout / header / sidebar / breadcrumb | Shared shell, intake form and instruction; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | Client/import kind; optional batch/type/date; workbook; sheet/header-row selection; reusable mapping profile/version. |
| Buttons / actions | Choose File; Upload |
| Cards / tables / modals / tabs / status / empty state | No preview table observed. Template instruction, no visible template download. P template download/version and upload limits. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | Upload and scan → UI57 mapping → UI15 preview/confirm/result; mapping edits invalidate preview. Contextual DRMS batch preselection retained. |

<a id="ui15"></a>

## UI15 — Import preview, errors and progress

| Attribute / override | Specification |
|---|---|
| Module / evidence | M04 · P; addresses G02 |
| User role | Authorized importer |
| Purpose | Import preview, errors and progress for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Shared shell, reconciliation cards, row preview and progress; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | Pinned mapping/template/profile; row filters valid/rejected/duplicate/processing failed; safe old/new preview; immutable confirmed selection. |
| Buttons / actions | Commit; Cancel; download rejected rows; Retry failed rows |
| Cards / tables / modals / tabs / status / empty state | Input/accepted/rejected/duplicates/committed/failed/unprocessed counts; row/source key/action/errors; Confirm exact accepted rows; persisted progress/result with Download error report. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | UI57 → preview → confirm → queued processing → reconciliation/result → UI58 history or relevant customer/task/account list. Invalid mapping returns UI57 without writes. |

<a id="ui16"></a>

## UI16 — Allocate tasks

| Attribute / override | Specification |
|---|---|
| Module / evidence | M05 · O E12 |
| User role | Administrative / supervisor P |
| Purpose | Allocate tasks for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Shared shell, batch/staff/operation form; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | Existing batch/staff/operation/From-To IWK; add source mode single task / selected task IDs / range. Active eligible Rider picker; priority/customer/status preview filters. |
| Buttons / actions | TASK PROCESSING; P Preview |
| Cards / tables / modals / tabs / status / empty state | No candidate table observed. P operation help and required-staff explanation; unassign excludes target staff requirement. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | UI55/UI19 single or selected tasks, or menu range → UI18 preview → Apply → per-task results. Reassignment closes old allocation, preserves history. |

<a id="ui17"></a>

## UI17 — Bulk task management

| Attribute / override | Specification |
|---|---|
| Module / evidence | M05 · O E13 |
| User role | Authorized manager P |
| Purpose | Bulk task management for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Shared shell, batch/operation/range form; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | Assignment; type/date; manage type Delete; IWK From/To |
| Buttons / actions | TASK PROCESSING; P Preview cancellation |
| Cards / tables / modals / tabs / status / empty state | No table/undo observed. P reason input and affected-record preview; destructive control separated from allocation. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | Assignment menu → UI17 → UI18 P; cancellation keeps audit/snapshots. DRMS deletion semantics U. |

<a id="ui18"></a>

## UI18 — Bulk operation preview and result

| Attribute / override | Specification |
|---|---|
| Module / evidence | M05 · P |
| User role | Authorized allocator / manager |
| Purpose | Bulk operation preview and result for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Shared shell, count cards, candidate table; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | Frozen criteria; reason; selected candidates |
| Buttons / actions | Apply selected; Cancel; download result |
| Cards / tables / modals / tabs / status / empty state | Table task/IWK/current staff/new staff/status/exclusion. Dialog includes operation and exact counts. Version mismatch returns to preview. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | UI16/UI17 → preview → apply → results → UI13. Idempotent retry references operation ID. |

<a id="ui19"></a>

## UI19 — Task information and review controls

| Attribute / override | Specification |
|---|---|
| Module / evidence | M06/M07/M08 · O E14 |
| User role | Scoped administrative reviewer; collector access P |
| Purpose | Task information and review controls for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Shared shell, batch/IWK/status header, review controls, photo and task panels; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | Staff selector when unassigned; Completed/Uncompleted/Payment Update; conditional Uncomplete remark for Domestic CTOS |
| Buttons / actions | Assign task; Update task; previous/next icons; expandable Photo uploaded/Task info panels |
| Cards / tables / modals / tabs / status / empty state | O Complete/Uncomplete badges, updater/time and photo/no-photo states. P tabs Overview/Activities/Evidence/Review/Financial snapshot. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | UI13/UI55 → task detail with customer/current Rider/priority/visit/due/history; UI56 edit; UI16 assign; UI42 review. Rider uses UI49 with reduced fields and large actions. |

<a id="ui20"></a>

## UI20 — Vacant task detail variant

| Attribute / override | Specification |
|---|---|
| Module / evidence | M06 · O E14 variant |
| User role | Assigned collector / reviewer P |
| Purpose | Vacant task detail variant for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | UI19 shell, occupancy/classification/meter groups; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | SAN; propAddr; currentClass; occupied; vacant; othersA/description; commercial; domestic; othersB/description; MeterConnectedInd; meterNumber |
| Buttons / actions | UI19 review controls; P save inspection/submit |
| Cards / tables / modals / tabs / status / empty state | Observed fields rendered text. P radio groups for mutually exclusive outcomes only after template confirmation; Other requires explanation P. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | UI13 → Vacant detail → activity/evidence P → review. Required evidence and options G06. |

<a id="ui21"></a>

## UI21 — Domestic / commercial recovery variants

| Attribute / override | Specification |
|---|---|
| Module / evidence | M06 · O six types sampled including Vacant; COM DR detail U |
| User role | Assigned collector / reviewer P |
| Purpose | Domestic / commercial recovery variants for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | UI19 shell, grouped account/parties/property/visit/LOD/CTOS/financial sections; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | M06 source dictionary; P versioned form by seven task types |
| Buttons / actions | UI19 controls; P Save draft/Submit |
| Cards / tables / modals / tabs / status / empty state | Exact collector edit widgets U. Six type review details inspected; COM DR remains selectable without active search match. P conditional LOD/CTOS fields and source-vs-verified values. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | UI13 → appropriate type detail → UI22/UI23 P → submit/review. Do not assume COM and Domestic rules identical. |

<a id="ui22"></a>

## UI22 — Activity, visit, TPS and follow-up editor

| Attribute / override | Specification |
|---|---|
| Module / evidence | M06/M11 · C site visit and typed remarks; O reference outcome fields only. |
| User role | Assigned collector / scoped call centre |
| Purpose | Activity, visit, TPS and follow-up editor for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Task context, activity timeline and editor; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | Visit date/time, customer availability, site status/address, person contacted, contact result/outcome; separate visit/collection/customer/follow-up remarks and follow-up due. Call form uses approved call outcomes and optional task. |
| Buttons / actions | Save draft; add follow-up; submit task |
| Cards / tables / modals / tabs / status / empty state | Timeline table/cards with provenance. Validation for required outcome, source, dates. P counters derive from events; imported counts retained separately. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | UI49 → visit/remarks editor → save server draft → UI50 business/UI23 proof → UI51 checklist. Mobile single-column sections, sticky Save and clear error anchors; success confirms persisted timestamp. |

<a id="ui23"></a>

## UI23 — Evidence uploader and viewer

| Attribute / override | Specification |
|---|---|
| Module / evidence | M06 · O empty and populated categorized photo panel; uploader P |
| User role | Assigned collector / authorized reviewer |
| Purpose | Evidence uploader and viewer for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Task context, categorized photo grid, upload and viewer dialog; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | Camera/gallery or file chooser; multiple JPEG/PNG/WebP; category/caption; previews and upload/scan progress. Config defaults 10 MiB/image, 10/submission; server content/dimension checks. |
| Buttons / actions | Take photo / Choose images; preview; remove draft image; upload/retry failed file; view; authorized download. Submitted proof is immutable. |
| Cards / tables / modals / tabs / status / empty state | Per-image pending/uploading/scanning/clean/rejected/error; count/limit help and retry; No proof yet action. Only clean task-linked images count toward completion. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | UI49/UI51 → evidence → persist private S3/task/uploader/time → return checklist. Mobile responsive thumbnails/large controls; unavailable camera falls back to gallery; failed network preserves draft, never false success. |

<a id="ui24"></a>

## UI24 — Customer Details and Account History

> **Removed (November 2026).** This screen no longer exists. Customer Management was removed. Customer details now appear on Task Details and in the Call Centre workspace. The text below is kept as history only.


| Attribute / override | Specification |
|---|---|
| Module / evidence | M06 · P normalized navigation |
| User role | Scoped collector / admin / reviewer |
| Purpose | Consolidated Customer Details over existing Debtor/contacts/addresses/account relationships; no duplicate customer entity. |
| Layout / header / sidebar / breadcrumb | Account header, source references and grouped tabs; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | Customer identity/reference/client/status/category, masked identifiers, contacts/address provenance, related account/task/date filters. |
| Buttons / actions | Edit verified contact; view source snapshot; related tasks |
| Cards / tables / modals / tabs / status / empty state | Overview, Accounts, Tasks, Visits/Collection, Communication and Change History tabs; edit/customer-category actions permission-gated; empty history CTA where allowed. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | UI62 → customer → UI63 edit/UI61 categories/UI55 tasks/UI64 communications/UI60 call centre. Rider UI49 customer tab exposes necessary subset only. |

<a id="ui25"></a>

## UI25 — Verification search

| Attribute / override | Specification |
|---|---|
| Module / evidence | M07 · O E15 |
| User role | Supervisor / manager P |
| Purpose | Verification search for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Shared shell, criteria form, project summary; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | Assignment; staff; IWK From/To defaults 1/1000; derived type/date |
| Buttons / actions | View Task |
| Cards / tables / modals / tabs / status / empty state | No table before search. P inline required-filter errors; do not silently stay on form when staff missing. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | Assignment → verification → UI26; return restores search. Populated decision workflow G03. |

<a id="ui26"></a>

## UI26 — Verification listing and decision

| Attribute / override | Specification |
|---|---|
| Module / evidence | M07 · O E15 empty and populated list; independent review design P |
| User role | Admin / future scoped reviewer with tasks.verify; independent from submitting Rider. |
| Purpose | Verification listing and decision for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Batch/date header, review grid and evidence drawer; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | O per-row Completed/Uncompleted/Payment Update; P explicit selected rows, independent review decision and mandatory return reason |
| Buttons / actions | View submission/evidence; Approve / Verify; Reject / Return with remarks; explicit selected-row bulk action; search/pagination/export where allowed. DRMS Payment Update selector remains reference evidence only. |
| Cards / tables / modals / tabs / status / empty state | Pending verification queue; customer/Rider/submission time/version/visit/outcome/evidence/current review; details UI42; required reject reason dialog; stale-version rows cannot approve. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | UI07/UI55 → queue → UI42 → approve Completed or reject Returned to Rider with notification/history; bulk shows per-row outcome and exact selection. |

<a id="ui27"></a>

## UI27 — Basic Reports, Filters and Exports

| Attribute / override | Specification |
|---|---|
| Module / evidence | M09 · O E16 |
| User role | Authorized report viewer/exporter |
| Purpose | Report criteria and task dataset for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Shared shell, criteria section then wide results table; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | Report family: Customer/Task/Rider Assignment/Rider Performance/Completed Task/Verification/Attendance/Collection-Visit/Communication/Import; date range, search and applicable client/category/customer/Rider/status/batch/channel/template/import kind. |
| Buttons / actions | View; filter/reset; select permitted columns; CSV/PDF Export; image bundle UI29 for task evidence; asynchronous job progress/download UI41. |
| Cards / tables / modals / tabs / status / empty state | O search/sort/page, 88 report headers; visible IWK ID/Payment Update/Exclude. P preset/column selection and active filter chips. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | Reports → choose family/filter → preview matching dataset → CSV/PDF job → authorized expiring artifact. Family-specific totals and as-of visible; Rider reports own scope only. |

<a id="ui28"></a>

## UI28 — Financial update exception review

| Attribute / override | Specification |
|---|---|
| Module / evidence | M08 · P; G04 |
| User role | Finance reviewer with approval permission |
| Purpose | Financial update exception review for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Source/job header, reconciliation cards, matched-row table; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | Source reference/date/currency; account match; amounts/fee components; approval/rejection reason |
| Buttons / actions | Inspect source; approve selected; reject; rematch |
| Cards / tables / modals / tabs / status / empty state | Table account/before snapshot/imported snapshot/delta warning/match/validation. No receipt or payment-method form in baseline. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | UI15 Payment Update → UI28 → approved snapshot → UI19/UI27. Ambiguous matches block posting. |

<a id="ui29"></a>

## UI29 — Image export selection and download

| Attribute / override | Specification |
|---|---|
| Module / evidence | M09 · O category/button; job UI P |
| User role | Authorized exporter |
| Purpose | Image export selection and download for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Report criteria summary, image category and job panel; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | Street (Sign Board); Permise 1; Permise 2; Meter; P export scope |
| Buttons / actions | Export Images; P download/expire/retry |
| Cards / tables / modals / tabs / status / empty state | Four categories observed. P count/size estimate, manifest, missing-image count and expiring bundle link. No images exported in review. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | UI27 → UI29 → UI41 job → download; reauthorize download. Correct spelling to Premise in DebtOps labels. |

<a id="ui30"></a>

## UI30 — Rider / Staff Directory

| Attribute / override | Specification |
|---|---|
| Module / evidence | M10 · O E17 |
| User role | HR / authorized admin P |
| Purpose | Rider / staff directory reusing existing staff records; default Rider filter with optional office-staff view. |
| Layout / header / sidebar / breadcrumb | Shared shell and staff table; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | Table search; P position/status/date filters |
| Buttons / actions | Add New Staff; staff ID link; Copy/CSV/PDF/Print/column visibility |
| Cards / tables / modals / tabs / status / empty state | O #/staff ID/staff no/name/appointment date/position/status; sorting/pagination. Sensitive contact exports controlled P. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | Admin Riders → list/filter/search → UI54 Rider profile or UI31/UI32 create/edit. No separate rider_profiles directory. |

<a id="ui31"></a>

## UI31 — Create Rider / Staff

| Attribute / override | Specification |
|---|---|
| Module / evidence | M10 · O E18 |
| User role | HR / authorized admin P |
| Purpose | Create staff for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Shared shell, identity/employment/contact form; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | Existing staff no/email/name/gender/position/appointment/contact/address/active fields; add Rider eligibility checkbox; account invitation and permissions remain controlled separate actions. |
| Buttons / actions | Save; back |
| Cards / tables / modals / tabs / status / empty state | No password input observed. P separate invitation after staff saved and permission granted; field errors and uniqueness feedback. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | UI30 or HR Add New Staff → UI31 → UI32 P; create does not automatically grant admin permissions. |

<a id="ui32"></a>

## UI32 — Edit Rider / Staff

| Attribute / override | Specification |
|---|---|
| Module / evidence | M10 · O E18 |
| User role | HR / authorized admin P |
| Purpose | Edit staff for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Same form as UI31 populated; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | UI31 fields plus explicit active/inactive and Rider eligibility; read-only history links; version conflict handling. |
| Buttons / actions | Update; back; P invite/deactivate workflow |
| Cards / tables / modals / tabs / status / empty state | No delete/reset-password action observed. P outstanding-task warning before disabling; role editor separate from position. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | UI54/UI30 → edit → saved profile. Deactivate previews outstanding allocations; preserves historical tasks/attendance; no automatic Admin grant. |

<a id="ui33"></a>

## UI33 — Admin Attendance Dashboard/List

| Attribute / override | Specification |
|---|---|
| Module / evidence | M10 · C operational Attendance; O E19 construction page preserved as reference only. |
| User role | HR P |
| Purpose | Admin Attendance Dashboard/List; replaces the deferred DebtOps placeholder with confirmed functionality. |
| Layout / header / sidebar / breadcrumb | Shared Admin shell, operational attendance metrics/filter/table; responsive labelled cards on small screens. DRMS construction page is historical evidence only. |
| Forms / fields / dropdowns / search / filters | Rider/date range/status/client-scope filters; schedule policy/as-of/timezone; correction reason and proposed values in UI59. |
| Buttons / actions | Search/reset; Rider/date detail; request/review correction; CSV/PDF export; schedule/policy configuration with permission. |
| Cards / tables / modals / tabs / status / empty state | Present/Late/Absent/Leave/Half Day totals; Rider/service date/scheduled shift/raw clock-in/out/effective times/status/remarks/correction badge; Unscheduled is separate from Absent. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | Admin dashboard/Riders → attendance → UI59 correction/schedule or UI54 Rider; empty expected schedule explains missing setup; loading/errors/retry and approved correction audit shown. |

<a id="ui34"></a>

## UI34 — Databank search and results

| Attribute / override | Specification |
|---|---|
| Module / evidence | M11 · O E20 |
| User role | Authorized call centre / admin P |
| Purpose | Databank search and results for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Shared shell, query card and results area; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | One query input; P source/client scope |
| Buttons / actions | Search; Upload Data |
| Cards / tables / modals / tabs / status / empty state | O List of record and raw No Record response. Positive columns U. P semantic no-results, masked matches, source provenance, no bulk export default. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | Databank → search; upload → UI35; result detail postponed until G05. |

<a id="ui35"></a>

## UI35 — Databank upload

| Attribute / override | Specification |
|---|---|
| Module / evidence | M11 · O E20 |
| User role | Authorized importer P |
| Purpose | Databank upload for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Shared shell, Excel instruction and file form; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | Workbook file; P source and template version |
| Buttons / actions | Choose File; Upload; Search return |
| Cards / tables / modals / tabs / status / empty state | No preview/errors observed. P use UI15 intake with databank-specific mapping after positive sample. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | UI34 → UI35 → staged preview P → UI34; source schema must be confirmed. |

<a id="ui36"></a>

## UI36 — Header tools and notification dropdown

| Attribute / override | Specification |
|---|---|
| Module / evidence | M12 · O E23 shell |
| User role | Authenticated user |
| Purpose | Header tools and notification dropdown for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Shared header with search/bell/avatar/fullscreen/tiles; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | Global Search input |
| Buttons / actions | Search icon; bell; avatar; fullscreen; tiles; sidebar toggle |
| Cards / tables / modals / tabs / status / empty state | Bell expanded without content. Global IWK search reaches UI43. Tiles opens UI45 customization. Fullscreen and dashboard widget side effects U; UI customization not changed. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | Any shell page → own notification centre UI37 P or account settings/logout; no hidden feature assumed. |

<a id="ui37"></a>

## UI37 — Notification centre

| Attribute / override | Specification |
|---|---|
| Module / evidence | M12 · P |
| User role | Own recipient |
| Purpose | Notification centre for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Shared shell, unread count and notification list; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | Unread/all/type/date filters |
| Buttons / actions | Open related record; Mark read/all read |
| Cards / tables / modals / tabs / status / empty state | Row title/message/type/time/read; empty and inaccessible-record messages. No sensitive debt values in email subject/notification payload. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | Bell → centre → authorized task/import/export; return centre retains filters. |

<a id="ui38"></a>

## UI38 — Roles, permissions and access review

| Attribute / override | Specification |
|---|---|
| Module / evidence | M12 · P |
| User role | Privileged system administrator |
| Purpose | Roles, permissions and access review for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Shared shell, roles table and permission editor; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | Admin and Rider default role bundles, future roles; action permissions/client scopes, assigned users and change reason; separate template/calling/correction privileges. |
| Buttons / actions | Create/edit role; assign; review change; Save |
| Cards / tables / modals / tabs / status / empty state | Permission grid grouped by module, change summary modal, last-admin/self-escalation protection; reason and audit. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | System Management → permissions/scopes → preview change → save/audit/cache invalidation; prevents self-escalation and last-authorized-admin removal. |

<a id="ui39"></a>

## UI39 — Reference and template configuration

| Attribute / override | Specification |
|---|---|
| Module / evidence | M12 · P |
| User role | Authorized administrator |
| Purpose | Reference and template configuration for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Shared shell, reference tabs and versioned template editor; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | Category/code/label/active/order; template type/version/column mappings |
| Buttons / actions | Create version; validate; publish/deactivate |
| Cards / tables / modals / tabs / status / empty state | Tabs states/types/outcomes/document categories/templates; old versions read-only; no deleting referenced codes. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | Administration → references/templates → preview compatibility → publish; in-flight jobs retain pinned version. |

<a id="ui40"></a>

## UI40 — Audit explorer

| Attribute / override | Specification |
|---|---|
| Module / evidence | M12 · P |
| User role | Authorized auditor / security admin |
| Purpose | Audit explorer for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Shared shell, filter bar, immutable event table and detail drawer; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | Date; actor; module; action; entity; record; correlation ID |
| Buttons / actions | View diff; approved export |
| Cards / tables / modals / tabs / status / empty state | Actor/action/entity/time/IP; redacted before/after drawer. Passwords/tokens/full PII absent. Pagination cursor P. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | Administration → audit → record context if authorized; no edit/delete controls. |

<a id="ui41"></a>

## UI41 — Import/export/operation job monitor

| Attribute / override | Specification |
|---|---|
| Module / evidence | M12 · P |
| User role | Job owner / authorized operator |
| Purpose | Import/export/operation job monitor for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Shared shell, job summary, counters, failures and artifact list; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | Job kind/state/date/owner; failed-row filter |
| Buttons / actions | View progress; safe Retry; cancel pending; download authorized artifact |
| Cards / tables / modals / tabs / status / empty state | Status queued/running/completed/failed/cancelled/expired; correlation ID and sanitized errors. Partial results explicit. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | UI15/UI18/UI27/UI29 → job → result/artifact; durable job/outbox references support recovery. |

<a id="ui42"></a>

## UI42 — Verification-context task detail

| Attribute / override | Specification |
|---|---|
| Module / evidence | M06/M07 · O E15 |
| User role | Scoped reviewer P |
| Purpose | Verification-context task detail for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Shared task shell, verification/listing breadcrumbs; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | Immutable customer/Rider/submission/time/version, visit/outcome/person, business type/nature/status, all remarks, clean proof manifest/supporting info; verification remarks. |
| Buttons / actions | Approve / Verify; Reject / Return (required remarks); previous/next pending submission; compare prior submission/decision; back to queue. |
| Cards / tables / modals / tabs / status / empty state | Read-only submitted sections and image viewer; history timeline; permission/stale/own-submission block; approve/reject dialog. DebtOps uses type-aware fields; reference-context dictionary inconsistency is not copied. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | UI26 eye → verification details → filtered Listing; state writes not submitted. |

<a id="ui43"></a>

## UI43 — Global IWK search results

| Attribute / override | Specification |
|---|---|
| Module / evidence | M06/M12 · O E24 |
| User role | Authenticated authorized searcher P |
| Purpose | Global IWK search results for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Standalone heading, breadcrumb and task table; no full shell observed; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | Header query; result columns IWK ID/SAN/Assignment Name/Type/Assignment Date/Owner Namee/Address/Complete Date/Staff Id/Task Id |
| Buttons / actions | Home/Dashboard breadcrumbs; each result cell is a button |
| Cards / tables / modals / tabs / status / empty state | Synthetic nonnumeric query returned two ID-0 rows. No explicit no-result message, sort/filter/pagination observed. P strict syntax, scoped search and friendly no-match. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | Any shared header → /search/result/{query} → result button UI44; preserve query state in DebtOps. |

<a id="ui44"></a>

## UI44 — Search-context task detail

| Attribute / override | Specification |
|---|---|
| Module / evidence | M06/M12 · O E24 |
| User role | Scoped task viewer/reviewer P |
| Purpose | Search-context task detail for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Shared task shell, dashboard/assignment/task breadcrumbs; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | UI19 task fields and review/assignment controls |
| Buttons / actions | Assign task; Update task; previous/next |
| Cards / tables / modals / tabs / status / empty state | Same detail structure; search query not retained in observed breadcrumb. Dummy-looking sample had label-like amounts/contacts and malformed dates. P source validation and typed rendering. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | UI43 → /search/info/{task}/{iwk} → task; P Back to search retains query/filter. |

<a id="ui45"></a>

## UI45 — Layout customization panel

| Attribute / override | Specification |
|---|---|
| Module / evidence | M12 · O E23 |
| User role | Authenticated user |
| Purpose | Layout customization panel for the corresponding module workflow; evidence status above limits what is established. |
| Layout / header / sidebar / breadcrumb | Right-hand Customize AdminLTE drawer over current page; common shell rules apply unless standalone/separate. |
| Forms / fields / dropdowns / search / filters | 13 checkboxes: No Navbar border; Body/Navbar/Sidebar nav/Footer small text; Sidebar nav flat/legacy/compact/child indent/child hide on collapse; Main Sidebar disable hover/focus auto expand; Brand small text; Dark Mode |
| Buttons / actions | Tiles button opens/closes drawer; variant swatches; clear |
| Cards / tables / modals / tabs / status / empty state | Navbar/Accent Color/Dark Sidebar/Light Sidebar/Brand Logo variant groups. No preferences changed. P retain only useful accessible compact/dark options in local browser storage; no business table needed. |
| Notifications / validation / pagination | Common contract; module rules in Module.md; actual DRMS enforcement U unless specifically observed above. |
| Expected interaction / previous-next navigation | Shared header tiles → drawer → same page; no data-changing action tested. |


<a id="ui46"></a>

## UI46 — Rider Login

| Attribute | Specification |
|---|---|
| Module / scope / role | M01; C; Public Rider. |
| Layout / components / mobile | Mobile standalone login with DebtOps identity. Shared labels/focus/keyboard/320px rules apply; Admin tables become labelled cards where useful. |
| Fields / filters | Email/password/remember; recovery link. |
| Buttons / actions | Sign in; Logout through account menu. |
| Validation / states | Shared users/guard; active staff/account + Rider permissions; generic errors/throttle. |
| Empty / loading / success / error | Unless detailed above: scoped empty-state explanation and allowed CTA; loading/progress disables duplicate submits; success confirms persisted state/reference; validation/permission/network/stale conflicts preserve safe input with retry or review. Never infer external delivery from queued/accepted. |
| Navigation / expected interaction | UI01 equivalent with mobile layout → UI47; invalid credentials stay with neutral error. |

<a id="ui47"></a>

## UI47 — Rider Home / Dashboard

| Attribute | Specification |
|---|---|
| Module / scope / role | M02/M06/M10; C; Rider own scope. |
| Layout / components / mobile | Single-column mobile cards and bottom nav Home / My Tasks / Attendance / Account. Shared labels/focus/keyboard/320px rules apply; Admin tables become labelled cards where useful. |
| Fields / filters | Today date, own work counts, attendance clock state. |
| Buttons / actions | View tasks; Start next task; Clock In / Clock Out. |
| Validation / states | Today/pending/returned/submitted/completed counts; no others data; empty work/closed shift state. |
| Empty / loading / success / error | Unless detailed above: scoped empty-state explanation and allowed CTA; loading/progress disables duplicate submits; success confirms persisted state/reference; validation/permission/network/stale conflicts preserve safe input with retry or review. Never infer external delivery from queued/accepted. |
| Navigation / expected interaction | Login UI46 → home → UI48 tasks or UI53 attendance; large touch targets. |

<a id="ui48"></a>

## UI48 — My Tasks

| Attribute | Specification |
|---|---|
| Module / scope / role | M06; C; Rider current allocation and own history. |
| Layout / components / mobile | Mobile task cards; tablet responsive list; sticky filters. Shared labels/focus/keyboard/320px rules apply; Admin tables become labelled cards where useful. |
| Fields / filters | Today/pending/assigned/in-progress/returned/pending verification/completed; search/batch/due/date. |
| Buttons / actions | View Task; Start Task; clear filters. |
| Validation / states | Customer masked name/address, planned/due time/priority, status and return reason; pagination/load-more preserves filters. |
| Empty / loading / success / error | Unless detailed above: scoped empty-state explanation and allowed CTA; loading/progress disables duplicate submits; success confirms persisted state/reference; validation/permission/network/stale conflicts preserve safe input with retry or review. Never infer external delivery from queued/accepted. |
| Navigation / expected interaction | UI47 → tasks → UI49; complete/submitted rows read-only; loading skeleton, no-task and retry states. |

<a id="ui49"></a>

## UI49 — Rider Task Details and Customer

| Attribute | Specification |
|---|---|
| Module / scope / role | M06/M13; C; Assigned Rider; own submission history masked. |
| Layout / components / mobile | Mobile context header; Overview / Customer / Visit / Proof / History sections; sticky next action. Shared labels/focus/keyboard/320px rules apply; Admin tables become labelled cards where useful. |
| Fields / filters | Necessary customer name/address/permitted contact, account/due/priority; current submission/review. |
| Buttons / actions | Start Task; Add Remarks; Record Visit; Business Nature; Upload Proof; Complete Task. |
| Validation / states | Assignment/state/version checks, overdue/returned badges; show rejection remarks and prior history; no full identifier by default. |
| Empty / loading / success / error | Unless detailed above: scoped empty-state explanation and allowed CTA; loading/progress disables duplicate submits; success confirms persisted state/reference; validation/permission/network/stale conflicts preserve safe input with retry or review. Never infer external delivery from queued/accepted. |
| Navigation / expected interaction | UI48 → detail → UI22/UI50/UI23 → UI51; reassigned task blocks edits, offers own read-only history. |

<a id="ui50"></a>

## UI50 — Business Nature Form

| Attribute | Specification |
|---|---|
| Module / scope / role | M06; C; Assigned Rider. |
| Layout / components / mobile | Mobile single-column section within task draft. Shared labels/focus/keyboard/320px rules apply; Admin tables become labelled cards where useful. |
| Fields / filters | Business type/nature/status dropdowns; Other text; discovered information; existing business name/nature field. |
| Buttons / actions | Save draft; Next to proof/completion; Back. |
| Validation / states | Other requires explanation; task-type required rules; searchable predefined codes, no free-form unknown status. |
| Empty / loading / success / error | Unless detailed above: scoped empty-state explanation and allowed CTA; loading/progress disables duplicate submits; success confirms persisted state/reference; validation/permission/network/stale conflicts preserve safe input with retry or review. Never infer external delivery from queued/accepted. |
| Navigation / expected interaction | UI49/UI22 → business → saved draft → UI23/UI51; changes versioned, no loss after validation error. |

<a id="ui51"></a>

## UI51 — Completion Review

| Attribute | Specification |
|---|---|
| Module / scope / role | M06/M07; C; Assigned Rider. |
| Layout / components / mobile | Mobile checklist and read-only preview with anchored errors. Shared labels/focus/keyboard/320px rules apply; Admin tables become labelled cards where useful. |
| Fields / filters | Visit/time/outcome; typed remarks; business where required; clean image categories/count; submission version. |
| Buttons / actions | Edit missing section; preview image; Submit Completion; Cancel/back. |
| Validation / states | Block if required visit/remarks/business/proof missing or scan pending; prevent double submit; confirm frozen data. |
| Empty / loading / success / error | Unless detailed above: scoped empty-state explanation and allowed CTA; loading/progress disables duplicate submits; success confirms persisted state/reference; validation/permission/network/stale conflicts preserve safe input with retry or review. Never infer external delivery from queued/accepted. |
| Navigation / expected interaction | UI49 editors → checklist → valid submit → UI52; failed network shows retry using same idempotency key. |

<a id="ui52"></a>

## UI52 — Completion Confirmation

| Attribute | Specification |
|---|---|
| Module / scope / role | M06/M07; C; Submitting Rider. |
| Layout / components / mobile | Mobile success receipt/status panel. Shared labels/focus/keyboard/320px rules apply; Admin tables become labelled cards where useful. |
| Fields / filters | Submission reference/time, Pending Verification, task/customer safe summary. |
| Buttons / actions | Back to My Tasks; View submission. |
| Validation / states | Success means submitted, not verified completed; unknown response recovers via request ID before retry. |
| Empty / loading / success / error | Unless detailed above: scoped empty-state explanation and allowed CTA; loading/progress disables duplicate submits; success confirms persisted state/reference; validation/permission/network/stale conflicts preserve safe input with retry or review. Never infer external delivery from queued/accepted. |
| Navigation / expected interaction | UI51 → confirmation → UI48; rejection notification returns UI49 with correction reason. |

<a id="ui53"></a>

## UI53 — Rider Attendance Today and History

| Attribute | Specification |
|---|---|
| Module / scope / role | M10; C; Rider own attendance. |
| Layout / components / mobile | Mobile today clock card plus History tab/calendar/date filter. Shared labels/focus/keyboard/320px rules apply; Admin tables become labelled cards where useful. |
| Fields / filters | Service date, schedule/timezone, raw/effective in-out, status/remarks; history date range. |
| Buttons / actions | Clock In; Clock Out; View history; add optional remark. |
| Validation / states | One open shift; disabled invalid action, server timestamps; missing schedule Needs review, not Absent; duplicate taps safe. |
| Empty / loading / success / error | Unless detailed above: scoped empty-state explanation and allowed CTA; loading/progress disables duplicate submits; success confirms persisted state/reference; validation/permission/network/stale conflicts preserve safe input with retry or review. Never infer external delivery from queued/accepted. |
| Navigation / expected interaction | UI47/bottom Attendance → today/history; success confirms saved time; offline/error keeps action unconfirmed with retry. |

<a id="ui54"></a>

## UI54 — Rider Profile and Performance

| Attribute | Specification |
|---|---|
| Module / scope / role | M10/M05; C; Admin / scoped staff manager. |
| Layout / components / mobile | Desktop profile header, tabs and summary cards; stacked tablet/mobile. Shared labels/focus/keyboard/320px rules apply; Admin tables become labelled cards where useful. |
| Fields / filters | Employment/contact/active/Rider eligibility; task/date/status/attendance filters. |
| Buttons / actions | Edit Rider; deactivate with disposition; view tasks/attendance/history; CSV/PDF if permitted. |
| Validation / states | Tabs Profile/Assigned/Completed/Activity/Attendance/Performance; metrics approved completion vs submitted separately. |
| Empty / loading / success / error | Unless detailed above: scoped empty-state explanation and allowed CTA; loading/progress disables duplicate submits; success confirms persisted state/reference; validation/permission/network/stale conflicts preserve safe input with retry or review. Never infer external delivery from queued/accepted. |
| Navigation / expected interaction | UI30 → profile → UI32/UI55/UI33; empty tabs explain period/no work; async errors retry per tab. |

<a id="ui55"></a>

## UI55 — Admin Task Monitoring

| Attribute | Specification |
|---|---|
| Module / scope / role | M05/M07; C; Admin / authorized operations user. |
| Layout / components / mobile | Desktop KPI/filter bar and table; compact cards on small screens. Shared labels/focus/keyboard/320px rules apply; Admin tables become labelled cards where useful. |
| Fields / filters | All/unassigned/assigned/in-progress/visited/pending completion/pending verification/completed/returned/overdue/cancelled; Rider/customer/category/date/batch/priority. |
| Buttons / actions | Create; view/edit; assign one/selected; reassign; cancel with reason; verify; export. |
| Validation / states | Columns customer/account/current Rider/status/review/priority/visit/due/latest event; selection count and conflict/result panel. |
| Empty / loading / success / error | Unless detailed above: scoped empty-state explanation and allowed CTA; loading/progress disables duplicate submits; success confirms persisted state/reference; validation/permission/network/stale conflicts preserve safe input with retry or review. Never infer external delivery from queued/accepted. |
| Navigation / expected interaction | UI07/sidebar/customer/Rider → monitor → UI56/UI16/UI18/UI19/UI42; filters persist; no implicit all-page selection. |

<a id="ui56"></a>

## UI56 — Manual Task Create / Edit

| Attribute | Specification |
|---|---|
| Module / scope / role | M05; C; Admin with tasks.create/update. |
| Layout / components / mobile | Desktop grouped form; responsive single-column. Shared labels/focus/keyboard/320px rules apply; Admin tables become labelled cards where useful. |
| Fields / filters | Customer/account/client-consistent batch/type, priority, planned visit/due date-time, instructions and optional Rider. |
| Buttons / actions | Save; Save and assign via preview; Cancel. |
| Validation / states | Required customer/account/batch/type; valid date-time/order; no fake IWK ID; active Rider scoped if selected. |
| Empty / loading / success / error | Unless detailed above: scoped empty-state explanation and allowed CTA; loading/progress disables duplicate submits; success confirms persisted state/reference; validation/permission/network/stale conflicts preserve safe input with retry or review. Never infer external delivery from queued/accepted. |
| Navigation / expected interaction | UI55/UI24 → form → saved UI19/UI16; stale edit conflict preserves safe inputs and requires reload/review. |

<a id="ui57"></a>

## UI57 — Excel Column Mapping Review

| Attribute | Specification |
|---|---|
| Module / scope / role | M04; C; Authorized importer. |
| Layout / components / mobile | Desktop source-to-target grid, sticky summary; mobile horizontally labelled field cards. Shared labels/focus/keyboard/320px rules apply; Admin tables become labelled cards where useful. |
| Fields / filters | Sheet/header row/profile; source ordinal/header/masked samples; target canonical field; match method/ambiguity; approved transform; Ignore. |
| Buttons / actions | Auto-map; correct dropdown; Save profile version; Continue; Back upload. |
| Validation / states | Required-target checklist, duplicate-target/ambiguous/unmapped warning; unique alias match auto-selected, fuzzy never silent; edits invalidate preview. |
| Empty / loading / success / error | Unless detailed above: scoped empty-state explanation and allowed CTA; loading/progress disables duplicate submits; success confirms persisted state/reference; validation/permission/network/stale conflicts preserve safe input with retry or review. Never infer external delivery from queued/accepted. |
| Navigation / expected interaction | UI14 → mapping → valid reviewed UI15; no header reordering required; loading detection, no headers and parser error states. |

<a id="ui58"></a>

## UI58 — Excel Import History and Result Detail

| Attribute | Specification |
|---|---|
| Module / scope / role | M04; C; Importer own/scoped Admin. |
| Layout / components / mobile | Desktop history table + job detail tabs Summary/Mapping/Rows/Errors/Audit. Shared labels/focus/keyboard/320px rules apply; Admin tables become labelled cards where useful. |
| Fields / filters | Date/kind/client/batch/uploader/state/profile; row outcome/error filters. |
| Buttons / actions | View mapping; Download error report; open results; idempotent retry failed rows. |
| Validation / states | Counts input/committed/rejected/duplicate/failed/outstanding, queued/running/partial/terminal states; retained source permission checks. |
| Empty / loading / success / error | Unless detailed above: scoped empty-state explanation and allowed CTA; loading/progress disables duplicate submits; success confirms persisted state/reference; validation/permission/network/stale conflicts preserve safe input with retry or review. Never infer external delivery from queued/accepted. |
| Navigation / expected interaction | UI14/UI15 → history/detail → relevant target list; artifacts can expire with explanation, no false zero-row success. |

<a id="ui59"></a>

## UI59 — Attendance Schedule, Policy and Correction

**M10 approved policy (18 October 2026):** use Module.md#m10-approved-policy. UI33/UI53 show main status plus a separate Late flag/minutes, fixed unpaid break deduction, worked minutes, open/incomplete state and pending-correction indicator. Raw and effective times are visibly distinct. UI59 provides versioned workdays/start/end/break/grace/Present/Half Day/overnight controls, explicit Rider/date-range assignment and day overrides (working/rest/holiday/leave). Correction forms show original/proposed times or calendar type, required justification and independent approve/reject with remarks. No direct punch edit or self-approval. Export uses the identical filters; a printer-friendly report supports Save as PDF without a new PDF dependency. Mobile controls remain >=44px; failed/duplicate punches never show an unpersisted success.

| Attribute | Specification |
|---|---|
| Module / scope / role | M10; C; Authorized Admin; independent correction approver. |
| Layout / components / mobile | Admin schedule/policy tabs and correction dialog. Shared labels/focus/keyboard/320px rules apply; Admin tables become labelled cards where useful. |
| Fields / filters | Policy version/timezone/start/end/workdays/grace/half-day; Rider/date/day-type; original/proposed in-out/status; mandatory reason. |
| Buttons / actions | Create schedule/policy version; request correction; approve/reject; view audit. |
| Validation / states | No guessed holiday absence; raw punches read-only; invalid order/self-approval/conflict blocked; corrections effective only after approved. |
| Empty / loading / success / error | Unless detailed above: scoped empty-state explanation and allowed CTA; loading/progress disables duplicate submits; success confirms persisted state/reference; validation/permission/network/stale conflicts preserve safe input with retry or review. Never infer external delivery from queued/accepted. |
| Navigation / expected interaction | UI33/UI54 → schedule/correction → audited result → attendance; responsive stacked fields, loading/empty/error/success feedback. |

<a id="ui60"></a>

## UI60 — Call Centre Workspace

**M11 increment:** sidebar/searchable customer register with customer/name/contact/account/task/category/current Rider filters; selected customer workspace with current phone contacts, optional task picker, current/server vs historical MYT time choice, required historical reason, eight outcomes, Other explanation, PTP context and due follow-up. Show explicit save confirmation, loading/validation/stale/retry states and immutable paginated call/visit timeline. Separate scoped follow-up queue shows due/overdue/completed/cancelled, assignee and source; assigned agents resolve pending call reminders with required remarks/confirmation. Contact-only editor uses the same validation/observation history as customer maintenance and its own permission, without enabling unrelated customer edits. Email/WhatsApp actions remain clearly unavailable until M14; no fabricated message history. Links from customer/task lead to this same workspace. Follow Module.md's M11 backdating/authorization contract.

| Attribute | Specification |
|---|---|
| Module / scope / role | M11/M13; C; Admin / Call Centre Agent permission. |
| Layout / components / mobile | Desktop customer search/summary, call form and chronological history; responsive panels. Shared labels/focus/keyboard/320px rules apply; Admin tables become labelled cards where useful. |
| Fields / filters | Customer/account/task optional; contact/person; call time/outcome; remarks; follow-up date; authorized contact edit. |
| Buttons / actions | Search; Record outgoing call; Save outcome; create follow-up; open history/WhatsApp/email. |
| Validation / states | Outcomes Answered/No Answer/Busy/Invalid Number/Call Back Requested/Promise to Pay/Disputed/Other; Other detail, callback due required; no payment posting. |
| Empty / loading / success / error | Unless detailed above: scoped empty-state explanation and allowed CTA; loading/progress disables duplicate submits; success confirms persisted state/reference; validation/permission/network/stale conflicts preserve safe input with retry or review. Never infer external delivery from queued/accepted. |
| Navigation / expected interaction | Customer/sidebar → Call Centre → saved activity/history/UI64/UI69; taskless call valid; no automatic dialing claimed. |

<a id="ui61"></a>

## UI61 — Customer Categories and Membership

> **Removed (November 2026).** This screen no longer exists. Customer Management was removed. Customer details now appear on Task Details and in the Call Centre workspace. The text below is kept as history only.


| Attribute | Specification |
|---|---|
| Module / scope / role | M13; C; Admin customers.categorize/manage_categories. |
| Layout / components / mobile | Desktop category table/editor and customer membership picker. Shared labels/focus/keyboard/320px rules apply; Admin tables become labelled cards where useful. |
| Fields / filters | Code/name/description/active; customer multi-select assignment/revocation; filter/status. |
| Buttons / actions | Create/edit; activate/deactivate; assign/unassign; save. |
| Validation / states | Unique client+code; no duplicate current membership; inactive cannot newly assign; history retained. |
| Empty / loading / success / error | Unless detailed above: scoped empty-state explanation and allowed CTA; loading/progress disables duplicate submits; success confirms persisted state/reference; validation/permission/network/stale conflicts preserve safe input with retry or review. Never infer external delivery from queued/accepted. |
| Navigation / expected interaction | Customer list/detail → categories → saved list/membership; empty CTA and per-field/conflict errors; responsive form. |

<a id="ui62"></a>

## UI62 — Customer List

> **Removed (November 2026).** This screen no longer exists. Customer Management was removed. Customer details now appear on Task Details and in the Call Centre workspace. The text below is kept as history only.


| Attribute | Specification |
|---|---|
| Module / scope / role | M13; C; Admin scoped customers.view. |
| Layout / components / mobile | Desktop search/filter table; responsive cards. Shared labels/focus/keyboard/320px rules apply; Admin tables become labelled cards where useful. |
| Fields / filters | Reference/name-token/identifier/contact query; client/category/type/status. |
| Buttons / actions | View; Create; Edit; assign category; view tasks/history; authorized export. |
| Validation / states | Masked name/contact/identifier rules; stable page; no matches vs no access; category inactive historical label retained. |
| Empty / loading / success / error | Unless detailed above: scoped empty-state explanation and allowed CTA; loading/progress disables duplicate submits; success confirms persisted state/reference; validation/permission/network/stale conflicts preserve safe input with retry or review. Never infer external delivery from queued/accepted. |
| Navigation / expected interaction | Sidebar → list → UI24/UI63/UI61; filters retained; loading skeleton and reset/retry states. |

<a id="ui63"></a>

## UI63 — Customer Create / Edit

> **Removed (November 2026).** This screen no longer exists. Customer Management was removed. Customer details now appear on Task Details and in the Call Centre workspace. The text below is kept as history only.


| Attribute | Specification |
|---|---|
| Module / scope / role | M13; C; Admin customers.create/update. |
| Layout / components / mobile | Desktop identity/contact/address/category sections; mobile stacked. Shared labels/focus/keyboard/320px rules apply; Admin tables become labelled cards where useful. |
| Fields / filters | Customer reference/client/person-company/name/optional identifier, contacts/address/status/categories. |
| Buttons / actions | Save; Add contact/address; Cancel; view history. |
| Validation / states | Client+reference unique; required name/type; contact validation; duplicate identity warning review; encrypted sensitive values and masked redisplay. |
| Empty / loading / success / error | Unless detailed above: scoped empty-state explanation and allowed CTA; loading/progress disables duplicate submits; success confirms persisted state/reference; validation/permission/network/stale conflicts preserve safe input with retry or review. Never infer external delivery from queued/accepted. |
| Navigation / expected interaction | UI62/UI24 → form → saved UI24; permission/stale/validation errors preserve safe draft; no cascade debt creation. |

<a id="ui64"></a>

## UI64 — Communication History

| Attribute | Specification |
|---|---|
| Module / scope / role | M14/M13/M11; C; Authorized scoped agent; Rider only if explicit permission. |
| Layout / components / mobile | Customer/task chronological timeline plus desktop filters. Shared labels/focus/keyboard/320px rules apply; Admin tables become labelled cards where useful. |
| Fields / filters | Customer/task/date/channel/template/direction/delivery status/agent; masked recipient. |
| Buttons / actions | View rendered snapshot/attempts/receipts; open conversation; compose via approved template; letter PDF. |
| Validation / states | Incoming/outgoing email/WhatsApp/manual call/voice entries, accepted vs delivered/unknown explicit; no editable historic body. |
| Empty / loading / success / error | Unless detailed above: scoped empty-state explanation and allowed CTA; loading/progress disables duplicate submits; success confirms persisted state/reference; validation/permission/network/stale conflicts preserve safe input with retry or review. Never infer external delivery from queued/accepted. |
| Navigation / expected interaction | UI24/UI60/task → history → UI65/UI69/UI70; paginate; no history CTA, expiry/permission/error states. |

<a id="ui65"></a>

## UI65 — Three Email Templates / Letter Options

| Attribute | Specification |
|---|---|
| Module / scope / role | M14; C; Compose user; template edit/approve separate grants. |
| Layout / components / mobile | Three option cards and versioned editor/preview; customer context side panel. Shared labels/focus/keyboard/320px rules apply; Admin tables become labelled cards where useful. |
| Fields / filters | Slots 1–3, subject/body, approved placeholder picker, version/state/submission/approval/rejection, selected customer/task context. |
| Buttons / actions | Select approved; preview; generate letter PDF; save draft/new version; submit/approve/reject/archive with permission. |
| Validation / states | Exactly three logical email options; draft wording blocks sends; allowlisted escaped placeholders; required data errors; no arbitrary Blade execution. |
| Empty / loading / success / error | Unless detailed above: scoped empty-state explanation and allowed CTA; loading/progress disables duplicate submits; success confirms persisted state/reference; validation/permission/network/stale conflicts preserve safe input with retry or review. Never infer external delivery from queued/accepted. |
| Navigation / expected interaction | Customer/history → choose → individual preview UI67 or recipients UI66; letter artifact separate from delivery; mobile cards stack. |

<a id="ui66"></a>

## UI66 — Bulk Email Recipient Selection

| Attribute | Specification |
|---|---|
| Module / scope / role | M14; C; communications.bulk_send. |
| Layout / components / mobile | Desktop scoped filters, selectable customers/contacts and eligibility counts. Shared labels/focus/keyboard/320px rules apply; Admin tables become labelled cards where useful. |
| Fields / filters | Customer/category/client/task/date filters; approved template version; contacts and exclusions. |
| Buttons / actions | Select explicit recipients; validate/deduplicate; next preview; cancel. |
| Validation / states | Eligible/duplicate/invalid/suppressed counts; shared-address conflicting personalization review; selections frozen and no hidden all-page recipient fan-out. |
| Empty / loading / success / error | Unless detailed above: scoped empty-state explanation and allowed CTA; loading/progress disables duplicate submits; success confirms persisted state/reference; validation/permission/network/stale conflicts preserve safe input with retry or review. Never infer external delivery from queued/accepted. |
| Navigation / expected interaction | UI65 → recipients → UI67; pagination/selection summary, empty/no-eligible help and error reasons. |

<a id="ui67"></a>

## UI67 — Email Personalized Preview and Send Confirmation

| Attribute | Specification |
|---|---|
| Module / scope / role | M14; C; communications.send/bulk_send. |
| Layout / components / mobile | Desktop recipient sample/list and rendered subject/body; mobile stacked. Shared labels/focus/keyboard/320px rules apply; Admin tables become labelled cards where useful. |
| Fields / filters | Frozen template/version, individual recipient/context, safe amount/source date/currency; exact selected count; quota availability. |
| Buttons / actions | View any recipient; back/edit invalidates preview; Confirm exact batch; Cancel. |
| Validation / states | Missing required placeholder blocks recipient; 1,000/day application setting and actual provider capacity shown; no send before confirm; letter not delivered email. |
| Empty / loading / success / error | Unless detailed above: scoped empty-state explanation and allowed CTA; loading/progress disables duplicate submits; success confirms persisted state/reference; validation/permission/network/stale conflicts preserve safe input with retry or review. Never infer external delivery from queued/accepted. |
| Navigation / expected interaction | UI65 single/UI66 bulk → preview → confirm → UI68 queued results; double click safe idempotency, stale eligibility rechecked. |

<a id="ui68"></a>

## UI68 — Bulk Email Result and Sending History

| Attribute | Specification |
|---|---|
| Module / scope / role | M14; C; Batch owner / authorized Admin. |
| Layout / components / mobile | Progress cards, recipient results grid, attempts/events drawer. Shared labels/focus/keyboard/320px rules apply; Admin tables become labelled cards where useful. |
| Fields / filters | Batch/date/recipient state; sanitized failure code; eligible/queued/accepted/delivered/failed/unknown/suppressed. |
| Buttons / actions | View receipt/attempt; permitted retry definitive transient failure; pause/resume/cancel pending; export. |
| Validation / states | Quota-paused shows resume condition; ambiguous unknown cannot blind resend; completed_with_errors explicit; recipient/body privacy and audit. |
| Empty / loading / success / error | Unless detailed above: scoped empty-state explanation and allowed CTA; loading/progress disables duplicate submits; success confirms persisted state/reference; validation/permission/network/stale conflicts preserve safe input with retry or review. Never infer external delivery from queued/accepted. |
| Navigation / expected interaction | UI67 → batch progress/history → UI64 customer history; queued/pending partial successes visible, realtime polling bounded. |

<a id="ui69"></a>

## UI69 — WhatsApp Conversation

| Attribute | Specification |
|---|---|
| Module / scope / role | M14; C; whatsapp.message scoped agent. |
| Layout / components / mobile | Desktop inbox/conversation/context panes; mobile single pane/back navigation. Shared labels/focus/keyboard/320px rules apply; Admin tables become labelled cards where useful. |
| Fields / filters | Customer/contact/task optional, inbound/outbound messages/media, approved template or permitted free text, preference/window state. |
| Buttons / actions | Select conversation; preview/send; reply; attach clean permitted file; link task; optional enabled Voice action. |
| Validation / states | Provider/customer eligibility and permitted window/template errors; accepted/delivered/read/failed; incoming signature/dedupe processing; no assumed email-template approval. |
| Empty / loading / success / error | Unless detailed above: scoped empty-state explanation and allowed CTA; loading/progress disables duplicate submits; success confirms persisted state/reference; validation/permission/network/stale conflicts preserve safe input with retry or review. Never infer external delivery from queued/accepted. |
| Navigation / expected interaction | UI60/UI64/customer → conversation → UI70 voice if enabled; history retained; reconnect/pending/empty/error states. |

<a id="ui70"></a>

## UI70 — WhatsApp Voice and Provider Capability Settings

| Attribute | Specification |
|---|---|
| Module / scope / role | M14/M12; C; whatsapp.call; integration configuration separate Admin grant. |
| Layout / components / mobile | Call controls/status panel plus privileged integration capability settings. Shared labels/focus/keyboard/320px rules apply; Admin tables become labelled cards where useful. |
| Fields / filters | Provider/sender verified capability/date, permission requested/granted/expiry, customer/contact/task, agent route, direction/call status/times. |
| Buttons / actions | Request permitted call permission; Start/Answer/End call when supported; refresh capability; inspect call history. |
| Validation / states | Unsupported/disabled/country/permission-expired states explain reason; actual provider setup/browser microphone permission required; no audio recording default. |
| Empty / loading / success / error | Unless detailed above: scoped empty-state explanation and allowed CTA; loading/progress disables duplicate submits; success confirms persisted state/reference; validation/permission/network/stale conflicts preserve safe input with retry or review. Never infer external delivery from queued/accepted. |
| Navigation / expected interaction | UI69 → voice panel → ended history UI64; disabled service retains scope backlog. Provider credentials via Secrets Manager reference, never visible in form/log. |

## Structural wireframes (P)

### Access and dashboard

```text
+--------------------- DebtOps / InteXcode ---------------------+
|                    Sign in                                |
| Email       [_________________________________]           |
| Password    [_________________________________] [show]    |
| [ ] Remember me     [Sign in]                             |
| Recover account       Activate invited account            |
| [field error / neutral status message]                    |
+-----------------------------------------------------------+

+ DebtOps ----- scoped search -------- notifications - account +
| Dashboard   | Home > Dashboard          Assignment [v]     |
| Assignments | [Total] [Submitted] [Verified] [Outstanding] |
|  Batches    | Date scope [____]   As of [time / zone]      |
|  Import     | Staff progress                              |
|  Allocate   | Collector | Allocated | Verified | Due      |
|  Tasks      | -----------------------------------------   |
|  Verify     | [filtered task drill-down]                 |
| Reports     |                                            |
| Staff       |                                            |
| Databank    |                                            |
+-------------+--------------------------------------------+
```

### Task execution and review

```text
+ Header --------------------------------------------------+
| Sidebar | Assignments > Batch > Tasks > Task             |
|         | IWK ref / account (masked)   [Work] [Review]    |
|         | [Previous] [Next]    Current collector         |
|         | Overview | Activities | Evidence | Review      |
|         | Source snapshot        Verified observations  |
|         | Client / account       Owner / tenant changes |
|         | Property / address     Visit / TPS / reminder  |
|         | Financial as-of        LOD / CTOS outcome     |
|         | [Save draft] [Submit for review]               |
|         | Evidence cards (scan status / category)        |
|         | Reviewer: [Approve] [Return] [Reason________]  |
+---------+-----------------------------------------------+
```

Reviewer controls appear only with permission and valid lifecycle state. Domestic and commercial variants use approved versioned field groups; Vacant emphasizes occupancy/classification/meter. Financial values remain source snapshots, not editable cash receipts.

### Import, allocation and reporting

```text
+ Header --------------------------------------------------+
| Sidebar | Import > Preview                               |
|         | Batch [v] Type [read-only] Template [version]   |
|         | [Choose workbook] [Validate]                   |
|         | [Input] [Accepted] [Duplicate] [Rejected]      |
|         | Row | Source key | Match | Action | Errors     |
|         | [Error only] [Download rejected]               |
|         | [Commit exact accepted count] [Cancel]         |
+---------+-----------------------------------------------+
| Sidebar | Allocation > Preview                           |
|         | Batch / staff / operation / source IWK range   |
|         | Task | Current staff | Target | Exclusion      |
|         | Reason [____________] [Apply selected]         |
+---------+-----------------------------------------------+
| Sidebar | Reports                                       |
|         | Batch [v] Staff [v] Date [optional]            |
|         | IWK From [___] To [___] Work [v] Payment [v]   |
|         | [View] [Columns] [CSV] [PDF] [Image bundle]    |
|         | IWK | Payment marker | Source fields ...       |
|         | [Prev] [page] [Next]       As of [snapshot]    |
+---------+-----------------------------------------------+
```

## Interaction and state acceptance

Every proposed screen needs normal, loading, empty, invalid input, denied, stale session, network failure and concurrency-conflict designs where applicable. Preserve values on failed writes; leave financial operations pending until acknowledged. Save and Submit differ: Save retains draft; Submit requests review. An export request creates a job with a stable snapshot, then produces a separately authorized download.

Confirmed DebtOps screens above are implementation scope independently of DRMS. G01–G08 remain reference follow-ups; positive legacy databank, COM DR source detail and source-workbook rules require fixtures, while attendance thresholds, three template contents, domain and provider-qualified calling need activation decisions. A runnable visual prototype is a later implementation artifact; this requested file is the interface inventory and functional prototype specification.

### Rider mobile wireframe (C)

```text
+ DebtOps -- My Tasks ---------------------------+
| Today [v]  Search [________]  Filters [v]     |
| Customer (necessary details only)            |
| Address / visit time / due / priority        |
| [Assigned] [Returned: review reason]         |
| [View Task]          [Start Task]            |
+---------------------------------------------+
| Task: Visit > Business > Remarks > Proof     |
| [Take photo] [Gallery]  upload/scan status    |
| Checklist: visit [ok] remarks [!] proof [ok] |
| [Save Draft]       [Review Completion]       |
+---------------------------------------------+
| Home       My Tasks       Attendance        |
+---------------------------------------------+
| Attendance: schedule/timezone, today status |
| [Clock In] / [Clock Out]       [History]     |
+---------------------------------------------+
```

Use at least 44px touch targets, single-column data entry, sticky primary action clear of keyboard/safe area, accessible status text and resumable server drafts. No silent offline completion: show connection state, allow retry and verify server receipt. Admin remains desktop/laptop/tablet optimized with responsive smaller-screen fallback.

### M06 approved initial Rider policy — 17 October 2026 (C)

UI22/UI49/UI50 use the owner-approved [M06 master data and purpose policies](Module.md#m06-approved-policy). Add visit purpose, explicit business applicability, signboard-availability declaration and Backdating Reason. All visits require availability/site status/contact result/outcome; applicable business fields and Other explanation are conditional. Typed remarks and due follow-ups stay separate. Display MYT, server-time default and one previous calendar day limit. Preserve safe form input after validation errors.

Keep mobile forms single column with 44px touch targets, grouped optional business/follow-up sections, current task/schedule/context and clear Start/Save/Proof/Checklist actions. UI23 provides camera or gallery, multiple selections, preview/removal, category/caption, progress, scan status and retry. Show HEIC conversion guidance where unsupported; use JPEG/PNG/WebP here. Every proof belongs to the exact saved visit. New visit versions need their own proof; submitted images remain read-only.

UI51 shows the saved visit, typed remarks/business, exact clean images and conditional purpose checks. Link missing requirements to their editor; keep Submit Completion disabled until valid. Confirm frozen data; UI52 displays Pending Verification and immutable receipt/reference. Returned work shows Admin reason and starts a new draft/version. UI48 preserves filters/search, current assignment access and own redacted receipts after reassignment; no customer/proof disclosure to former assignees.

M14 increment 1 / UI65 (18 October 2026): Communications shows three named owner-approved version 1.0 options and draft/pending working-version links. The editor shows name, subject/body, required-token flags, creator/submission/approval/rejection provenance, history and stale-edit feedback. Save draft, submit exact saved content, approve/reject with a reason, create a replacement draft and archive approved wording are permission-controlled. Approved wording stays active until replacement approval. Sample previews label every value as illustrative; actual-customer previews use current scope and approved source data, with explicit missing financial-data errors. Delivery and private letter artifacts remain unavailable pending their recipient/provider/storage workflows.

M14 owner-approved implementation contract (18 October 2026): initial defaults are Friendly Payment Reminder, Outstanding Balance Follow-Up, and Final Follow-Up Notice, using the exact subjects/bodies supplied by the owner in this session. All five registry placeholders are required in these version 1.0 defaults. Preserve text and names in the initial-default seed; no manual client/payment data is invented. Statuses are Draft, Pending Approval, Approved, Rejected, Archived. Drafts are editable with a lock version; submission freezes content for reserved-permission review. Rejection requires a retained reason/reviewer/time; revision creates a new draft. The existing approved version remains active while a replacement is draft, pending or rejected; replacement approval atomically archives the previous approved version. Published and rejected history cannot be rewritten. Generic template approval follows the separate templates.approve grant; no additional self-approval rule is inferred.

Initial approved defaults record approval_source=owner_default and an approval_reference identifying the 18 October 2026 owner authorization, with no fabricated user FK; approved_at records application of this authorized default. Subsequent approvals record the authorized user's approved_by/time. Add name, submitted_by/at, rejected_by/at, encrypted rejection_reason_ciphertext and retained approval-source/reference metadata on the canonical communication_templates table. Seed only missing initial versions and preserve later business edits. Display the initial integer version as 1.0. Explicit synthetic previews may use labeled illustrative amount/date values; those values never enter actual-customer context or any delivery. Actual-customer previews continue to block required financial amount/payment date without approved provenance. Only Approved versions may enter the later recipient/send workflow; current template preview creates no communication or letter artifact.

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

### Rider presentation and navigation — 27 October 2026

The Rider workspace uses a persistent bottom navigation with exactly Home, Assignment, My Task and Attendance. Profile is accessed through the top-right avatar; notifications use the adjacent header bell. The side menu contains secondary navigation, appearance preference and the existing Sign out action. Preserve authorization, keyboard focus handling and safe-area spacing on mobile and desktop.

Use white (#FFFFFF), navy (#023047) and restrained orange (#FB8500) through centralized Rider components and theme styles. Home presents existing allocated-work metrics, the next task and attendance state. Assignment lists and details are read-only views of existing batches and only the current Rider's authorized task allocations; they do not introduce a new assignment or acceptance workflow. My Task, task detail, visit/proof submission, history, attendance and profile retain their existing actions and validation. Notifications show the Rider's existing authorized operational notifications; task links require current allocation access. Keep large touch targets, clear status labels, visible validation and loading feedback, and responsive layouts without horizontal overflow.

Nexora branding amendment (27 October 2026): The official product name is Nexora, using the supplied full wordmark and icon artwork with white #FFFFFF, navy #023047 and orange #FB8500. Earlier DCMS/DebtOps product-name references are historical. This presentation-only amendment does not rename schema, routes, permission keys, integration tags, approved legal notices, or existing records.

Nexora production asset amendment: Use compact transparent full-wordmark and icon SVG variants from config/dcms.php, switching to white/orange artwork on dark surfaces. Browser and app icons use centered white-backed 16–512px exports. Keep natural proportions, clear space, and print/PDF-compatible light PNG artwork. No navigation or workflow changes.

Welcome carousel presentation: Guest authentication screens share four Nexora feature slides (customer records, field teams, task monitoring, controlled access), with a five-second loop and subtle fade. Manual dots, desktop previous/next and mobile horizontal swipe preserve login routing and forms. Hover/focus pauses rotation; interaction defers resumption by ten seconds, and a persistent pause control is available. Hidden tabs do not rotate. Reduced-motion preference disables autoplay and transitions. The mobile hero remains compact.

Nexora login-card presentation: Admin and Rider sign-in screens place the lock, welcome heading, description, validation feedback, unchanged form, recovery/activation and portal links inside one centered responsive card (28rem maximum width). Use a white surface, rounded corners, subtle navy-tinted border/shadow and light blue-grey surrounding panel; retain the existing dark-theme variant. Other guest flows keep their existing form container. No authentication or routing behavior changes.

Sign-in reference composition: Admin and Rider login use a single rounded navy outer panel with the feature carousel on the left and an inset white card on the right. Rider welcome copy highlights field assignments with the bicycle icon. Keep all original login, recovery, activation and portal-switch controls; stack the panel and card on mobile.

Full-screen login amendment: Admin and Rider sign-in use an edge-to-edge viewport canvas with no outer frame, margin, rounding or shadow. At desktop widths use an equal 50% branding/carousel and 50% login split, vertically centered content and an independent bounded form card. Maintain a subtle divider, stacked mobile sections and natural vertical scrolling where content needs it. Authentication, carousel controls and dark-mode behavior remain unchanged.

Rider header presentation: Use a full-width #023047 navbar with white controls/brand text, a light secondary workspace label, the white/orange compact Nexora icon, and an orange-bordered profile avatar. Preserve menu, notification and profile behavior in both themes.

Admin navigation amendment: Dashboard remains a direct link. Assignment expands to Manage Assignment (admin.assignments.index, including existing Active/Archived tabs) and Add Assignment (admin.assignments.create). Task Management expands to Upload Task (admin.imports.index), Assign Task (admin.tasks.allocate), Manage Task (admin.tasks.index), Task Verification (admin.verification.index) and Report (admin.reports.index; current initial M09 families). Human Resources expands to Staff Management (admin.staff.index), Add New Staff (admin.staff.create), Attendance (admin.attendance.index) and the existing My attendance entry where authorized. Customer Management, Call Centre, Communications and the planned Administration item remain under Supporting modules. Preserve existing permission gates, current-page/parent highlighting, search, compact sidebar behavior, keyboard support and mobile drawer controls. Each implemented destination appears only once in the navigation. No workflow or permission changes.

M09 operational task-report increment (27 October 2026): implement UI27/UI41 for task, rider_assignment, rider_performance, completed_task and verification families using canonical TaskAccess portfolio scope plus reports.view/tasks.view/tasks.monitor; verification additionally requires tasks.verify. CSV/PDF require reports.export. Filters: task-created MYT date range, client, assignment batch, current Rider staff number, work status and exact IWK reference. Approved initial columns are operational identifiers/status/dates/flags, masked account reference and staff number, without names, contacts, addresses, financial amounts or source workbook contents. Rider performance counts currently allocated tasks and verified/completed tasks only, never submissions as completed or balances as receipts. Full customer/attendance/visit/communication/import families, historical allocation performance, legacy 88-column parity, image bundles and presets remain explicit follow-on scope, not fabricated outputs.
Freeze bounded (maximum 5,000 tasks) source IDs and projected values together in a repeatable-read transaction; exports create a new clearly timed snapshot. Store encrypted snapshot data in canonical export_jobs, publish via durable outbox to Redis exports, generate escaped CSV or trusted Dompdf PDF, and store encrypted private S3 artifacts through documents. Recheck current requester permissions and every source task's portfolio at execution and signed user-bound download. Artifact expiry is 24 hours, configurable, with five-minute links and audited request/generation/download. Denied or changed scope cancels output; lost storage/retries never report success or replace completed files. No role grants or existing workflow changes.

Rider attendance presentation amendment: UI53 retains the existing server-recorded punches, Asia/Kuala_Lumpur dates, scheduled/overnight shift logic and backend status labels. Present a Rider welcome, server date/time card, current-shift status and supported Clock In/Out controls, actual scheduled start/end dates, saved raw timestamps, and policy/confirmation notices. No location source exists; do not show a map or fabricate location. Keep Attendance History on the same page with unchanged History from/through bindings, inclusive backend filtering, validation, ordering and pagination. Responsive history cards summarize effective times and worked minutes, with raw/effective timestamps, unpaid break and schedule details available in an expandable section. Keep existing correction permission, form, review workflow and pending status; Admin register rows are unchanged. Rider navbar and four-item bottom navigation retain existing behavior.

Shared Rider sizing amendment: Dashboard is the sizing baseline for all Rider routes, including Account Settings. Central Rider CSS tokens own the 64rem outer container, 16/24px responsive gutters, 24/32px top spacing, 20/24px section gaps and primary card padding, 24px page headings and common bottom-navigation clearance. No page-level narrower outer container is permitted. Attendance uses the same full content width, a two-column current-shift layout and two-column history cards at desktop widths, stacking on mobile. Compact KPI cards remain the existing deliberate Dashboard variant. Header and bottom navigation remain shared. All attendance controls, filters, timestamps and correction bindings are unchanged.

Current-shift card color amendment: Use #023047 with white primary text and pale-blue secondary labels, orange status/icon accents and the existing orange clock action. Remarks retain a white field with navy text; inactive and saved controls use subtle light tints. Limit this treatment to the current-shift card, including dark mode, without changing history, sizing, bindings or server logic.
