# DebtOps entity implementation register

> **November 2026:** clients, Customer Management (M13), customer categories and column aliases were removed, the IWK ID became the task ID, and financial snapshots were added. See "Current implementation" in [Module.md](../Module.md) and [Database.md](../Database.md), which override older text here.

Derived tracking index only; Database.md remains authoritative for every field, relationship and constraint. Foundation does not imply module CRUD is complete. Framework reset/job tables and package-owned Pulse tables are additional operational schema.

| Entity / source | Model | Module | Disposition |
|---|---|---|---|
| [users](../Database.md#table-users) | User | M01/M12 | Foundation + controlled staff invitations |
| [staff](../Database.md#table-staff) | Staff | M10 | Personnel and attendance implemented; performance reporting follows M09 |
| [roles](../Database.md#table-roles) | Role (Spatie) | M12 | Foundation |
| [permissions](../Database.md#table-permissions) | Permission (Spatie) | M12 | Foundation |
| [role_has_permissions](../Database.md#table-role_has_permissions) | package pivot | M12 | Foundation |
| [model_has_roles](../Database.md#table-model_has_roles) | package polymorphic pivot | M12 | Foundation |
| [model_has_permissions](../Database.md#table-model_has_permissions) | package polymorphic pivot | M12 | Foundation |
| [reference_values](../Database.md#table-reference_values) | ReferenceValue | M03/M06/M10/M12 | Foundation |
| [clients](../Database.md#table-clients) | Client | M03/M06 | Foundation |
| [assignment_batches](../Database.md#table-assignment_batches) | AssignmentBatch | M03 | Metadata/register/archive implemented; task intake pending M04/M05 |
| [properties](../Database.md#table-properties) | Property | M06 | M05 implemented; execution/review extensions follow in M06/M07 |
| [debtors](../Database.md#table-debtors) | Debtor | M05/M06 | Internal record, created from tasks |
| [contacts](../Database.md#table-contacts) | Contact | M05/M06/M11 | Implemented; created from tasks, edited in Call Centre |
| [addresses](../Database.md#table-addresses) | Address | M06 | Table kept; no longer written by a page |
| [debt_accounts](../Database.md#table-debt_accounts) | DebtAccount | M06/M08 | M05 implemented; execution/review extensions follow in M06/M07 |
| [account_parties](../Database.md#table-account_parties) | AccountParty | M06 | M05 implemented; execution/review extensions follow in M06/M07 |
| [import_templates](../Database.md#table-import_templates) | ImportTemplate | M04/M11/M12 | M04 implemented; dependent target kinds remain gated |
| [import_batches](../Database.md#table-import_batches) | ImportBatch | M04/M08/M11 | M04 implemented; dependent target kinds remain gated |
| [import_rows](../Database.md#table-import_rows) | ImportRow | M04/M08/M11 | M04 implemented; dependent target kinds remain gated |
| [tasks](../Database.md#table-tasks) | Task | M03/M05/M06/M07 | M05 implemented; execution/review extensions follow in M06/M07 |
| [task_allocations](../Database.md#table-task_allocations) | TaskAllocation | M05 | M05 implemented; execution/review extensions follow in M06/M07 |
| [collection_activities](../Database.md#table-collection_activities) | CollectionActivity | M06/M11 | Implemented visits and immutable manual call snapshots |
| [property_inspections](../Database.md#table-property_inspections) | PropertyInspection | M06 | Implemented M06/M07 |
| [task_observations](../Database.md#table-task_observations) | TaskObservation | M06 | Planned |
| [follow_ups](../Database.md#table-follow_ups) | FollowUp | M06/M11 | Implemented visit/call reminders and immutable assigned-agent call resolutions |
| [task_reviews](../Database.md#table-task_reviews) | TaskReview | M07 | Implemented M07: immutable independent decisions |
| [financial_updates](../Database.md#table-financial_updates) | FinancialUpdate | M08 | Not built |
| [financial_snapshots](../Database.md#table-financial_snapshots) | FinancialSnapshot | M08 | Implemented |
| [documents](../Database.md#table-documents) | Document | M04/M06/M09/M01/M14 | Private source/proof/letter artifacts implemented; reporting targets remain gated |
| [export_jobs](../Database.md#table-export_jobs) | ExportJob | — | Removed; reports download directly |
| [bulk_operations](../Database.md#table-bulk_operations) | BulkOperation | M05 | M05 implemented; execution/review extensions follow in M06/M07 |
| [bulk_operation_items](../Database.md#table-bulk_operation_items) | BulkOperationItem | M05 | M05 implemented; execution/review extensions follow in M06/M07 |
| [databank_sources](../Database.md#table-databank_sources) | DatabankSource | M11 | Planned; G05 approval required |
| [databank_records](../Database.md#table-databank_records) | DatabankRecord | M11 | Planned; G05 approval required |
| [databank_search_tokens](../Database.md#table-databank_search_tokens) | DatabankSearchToken | M11 | Planned; G05 approval required |
| [notifications](../Database.md#table-notifications) | DatabaseNotification (Laravel) | M12 | Foundation |
| [audit_logs](../Database.md#table-audit_logs) | AuditLog | M01–M12 | Foundation |
| [system_settings](../Database.md#table-system_settings) | SystemSetting | M12 | Planned |
| [outbox_events](../Database.md#table-outbox_events) | OutboxEvent | M04/M05/M09/M12 | Foundation |
| [processed_events](../Database.md#table-processed_events) | ProcessedEvent | M12 | Foundation |
| [user_client_scopes](../Database.md#table-user_client_scopes) | UserClientScope | M01/M12 | Foundation |
| [customer_categories](../Database.md#table-customer_categories) | CustomerCategory | — | Removed |
| [customer_category_assignments](../Database.md#table-customer_category_assignments) | CustomerCategoryAssignment | — | Removed |
| [import_fields](../Database.md#table-import_fields) | ImportField | M04 | M04 implemented; dependent target kinds remain gated |
| [column_aliases](../Database.md#table-column_aliases) | ColumnAlias | — | Removed; aliases are in config/imports.php |
| [import_mapping_profiles](../Database.md#table-import_mapping_profiles) | ImportMappingProfile | M04 | M04 implemented; dependent target kinds remain gated |
| [import_column_mappings](../Database.md#table-import_column_mappings) | ImportColumnMapping | M04 | M04 implemented; dependent target kinds remain gated |
| [task_status_histories](../Database.md#table-task_status_histories) | TaskStatusHistory | M05/M06/M07 | M05 implemented; execution/review extensions follow in M06/M07 |
| [task_submissions](../Database.md#table-task_submissions) | TaskSubmission | M06/M07 | Implemented M06/M07 |
| [task_submission_documents](../Database.md#table-task_submission_documents) | TaskSubmissionDocument | M06/M07 | Implemented M06/M07 |
| [attendance_policies](../Database.md#table-attendance_policies) | AttendancePolicy | M10 | Implemented; immutable versions, initial inactive template |
| [attendance_schedules](../Database.md#table-attendance_schedules) | AttendanceSchedule | M10 | Implemented explicit assignments/calendar; versioned corrections |
| [attendances](../Database.md#table-attendances) | Attendance | M10 | Implemented immutable raw punches and approved effective projection |
| [attendance_adjustments](../Database.md#table-attendance_adjustments) | AttendanceAdjustment | M10 | Implemented independent decisions, immutable payload/history |
| [integration_accounts](../Database.md#table-integration_accounts) | IntegrationAccount | M14/M12 | Provider parent implemented; no account activated |
| [communication_templates](../Database.md#table-communication_templates) | CommunicationTemplate | M14 | Implemented UI65: owner-approved defaults, versioned review and scoped previews |
| [communication_batches](../Database.md#table-communication_batches) | CommunicationBatch | M14 | Frozen previews, exact confirmation and queued processing controls; live activation gated |
| [conversations](../Database.md#table-conversations) | Conversation | M14 | Canonical parent schema only; channel workflows pending |
| [communications](../Database.md#table-communications) | Communication | M14/M11/M13 | Frozen email/letter envelopes, private PDF workflow and authenticated email receipts; external activation gated |
| [communication_attempts](../Database.md#table-communication_attempts) | CommunicationAttempt | M14 | Retained provider attempts, capped definitive retries and ambiguity |
| [integration_events](../Database.md#table-integration_events) | IntegrationEvent | M14 | Verified encrypted SES/SNS inbox, deduplication and safe unresolved-event review |
| [communication_events](../Database.md#table-communication_events) | CommunicationEvent | M14 | Append-only local/verified provider events and semantic receipt deduplication |
| [communication_preferences](../Database.md#table-communication_preferences) | CommunicationPreference | M14/M13 | Versioned service-email evidence, restrictions and reserved-permission review |
| [communication_quota_reservations](../Database.md#table-communication_quota_reservations) | CommunicationQuotaReservation | M14 | Atomic rolling quotas; unknowns reconcile to consumed only through verified receipt provenance |
| [call_permissions](../Database.md#table-call_permissions) | CallPermission | M14 | Planned |
| [voice_sessions](../Database.md#table-voice_sessions) | VoiceSession | M14 | Planned |
