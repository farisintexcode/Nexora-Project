<?php

namespace Database\Seeders;

use App\Actions\Attendance\ConfigureAttendance;
use App\Actions\Attendance\ManageLeave;
use App\Actions\Imports\ImportWorkflow;
use App\Actions\Tasks\RiderWork;
use App\Models\AccountParty;
use App\Models\AssignmentBatch;
use App\Models\Attendance;
use App\Models\AttendanceSchedule;
use App\Models\Contact;
use App\Models\DebtAccount;
use App\Models\Debtor;
use App\Models\Document;
use App\Models\FinancialSnapshot;
use App\Models\ImportBatch;
use App\Models\ImportColumnMapping;
use App\Models\ImportField;
use App\Models\ImportRow;
use App\Models\IntegrationAccount;
use App\Models\IntegrationEvent;
use App\Models\OutboxEvent;
use App\Models\Staff;
use App\Models\Task;
use App\Models\TaskAllocation;
use App\Models\User;
use App\Services\Customers\CustomerSearch;
use App\Services\Tasks\CompletionChecklist;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** Synthetic accounts only, with a runtime-supplied secret; never included in DatabaseSeeder. */
class BrowserTestSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || ! str_ends_with(config('database.connections.pgsql.database'), '_e2e') || ! config('dcms.e2e_password')) {
            throw new \LogicException('Browser fixtures require a local/test *_e2e database and a runtime secret.');
        }
        $this->call(DatabaseSeeder::class);
        $hrAdmin = User::factory()->create(['name' => 'Synthetic HR Admin', 'email' => 'hr-admin@example.test', 'password' => config('dcms.e2e_password')]);
        $hrAdmin->assignRole('System Admin');
        $administrative = User::factory()->for(Staff::factory())->create(['name' => 'Synthetic Administrative', 'email' => 'administrative@example.test', 'password' => config('dcms.e2e_password')]);
        $administrative->assignRole('Administrative');
        $admin = User::factory()->create(['name' => 'Synthetic Admin', 'email' => 'admin@example.test', 'password' => config('dcms.e2e_password')]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo('users.invite');
        $admin->givePermissionTo('templates.approve');
        $admin->givePermissionTo('communications.generate_letter');
        $admin->givePermissionTo('integrations.manage');
        $admin->givePermissionTo(['communication-permission.create', 'communication-permission.update', 'communication-permission.block', 'communication-permission.approve', 'communication-permission.override']);
        foreach (['desktop', 'rider-mobile'] as $callSurface) {
            $callCustomer = Debtor::factory()->create(['customer_reference' => 'CALL-'.strtoupper($callSurface), 'display_name_ciphertext' => 'Synthetic Call Customer '.$callSurface]);
            Contact::factory()->create(['debtor_id' => $callCustomer->id, 'kind_code' => 'mobile', 'value_ciphertext' => '+60123456789', 'value_fingerprint' => app(CustomerSearch::class)->token('contact:mobile', '+60123456789')]);
        }
        // Historical synthetic UI fixture only; this does not bypass production scan or storage adapters.
        $import = ImportBatch::factory()->create(['uploaded_by' => $admin->id, 'status' => 'mapping']);
        $fields = ImportField::query()->whereIn('id', $import->template->schema_definition['field_ids'])->get();
        foreach ($fields as $column => $field) {
            $mapping = new ImportColumnMapping;
            $mapping->forceFill(['import_batch_id' => $import->id, 'source_sheet' => 'Intake', 'source_column_index' => $column + 1, 'original_header' => $field->canonical_field, 'normalized_header' => ImportWorkflow::normalize($field->canonical_field), 'field_id' => $field->id, 'match_method' => 'canonical', 'transform_code' => 'exact', 'confirmed_by' => $admin->id, 'confirmed_at' => now()])->save();
        }
        $row = new ImportRow;
        $row->forceFill(['import_batch_id' => $import->id, 'sheet_name' => 'Intake', 'row_number' => 2, 'raw_payload_ciphertext' => ['IMPORT-UI-FIXTURE', 'Synthetic Import Customer', 'domestic_dr', '2026-10-01', '2026-10-31'], 'normalized_payload_ciphertext' => ['operation' => 'create', 'changes' => ['name' => ['before' => '—', 'after' => 'Sy•••er']]], 'state' => 'processed', 'target_assignment_batch_id' => AssignmentBatch::factory()->create(['name' => 'Synthetic Import Fixture'])->id, 'processed_at' => now()])->save();
        $import->forceFill(['input_count' => 1, 'accepted_count' => 1, 'processed_count' => 1, 'mapping_digest' => hash('sha256', 'synthetic historical UI fixture'), 'selected_sheet' => 'Intake', 'header_row_number' => 1, 'mapping_confirmed_by' => $admin->id, 'mapping_confirmed_at' => now(), 'confirmed_by' => $admin->id, 'confirmed_at' => now(), 'status' => 'completed', 'completed_at' => now()])->save();
        $rider = User::factory()->for(Staff::factory()->create())->create(['name' => 'Synthetic Rider', 'email' => 'rider@example.test', 'password' => config('dcms.e2e_password')]);
        $rider->assignRole('Rider');
        $rider->staff->first_name = 'Field';
        $rider->staff->last_name = 'Rider';
        $rider->staff->save();
        // M10 synthetic calendar/punch fixtures are confined to the guarded disposable E2E database.
        $admin->givePermissionTo('attendance.approve_correction');
        $attendancePolicy = app(ConfigureAttendance::class)->policy($admin, ['code' => 'browser-attendance', 'rules' => config('attendance.initial_rules')]);
        // Synthetic leave types/calendars exist only in this guarded disposable browser database.
        app(ManageLeave::class)->configure($admin, ['name' => 'Synthetic Personal Leave', 'max_calendar_days' => 14, 'requires_document' => false, 'allow_pending_cancel' => true, 'is_active' => true]);
        foreach ([3, 4, 5, 6, 7, 8] as $offset) {
            $date = now()->timezone(config('dcms.business_timezone'))->addDays($offset)->toDateString();
            app(ConfigureAttendance::class)->assign($admin, $rider->staff->public_id, $attendancePolicy->public_id, $date, $date, 'scheduled');
        }
        foreach (['desktop', 'rider-mobile'] as $attendanceSurface) {
            $attendanceStaff = Staff::factory()->create(['staff_no' => 'ATT-'.strtoupper($attendanceSurface), 'first_name' => 'Attendance', 'last_name' => $attendanceSurface]);
            $attendanceUser = User::factory()->for($attendanceStaff)->create(['name' => 'Attendance '.$attendanceSurface, 'email' => 'attendance-'.$attendanceSurface.'@example.test', 'password' => config('dcms.e2e_password')]);
            $attendanceUser->assignRole('Rider');
            $attendanceUser->givePermissionTo('attendance.correct');
            $historyDate = now()->timezone(config('dcms.business_timezone'))->subDays(2)->startOfDay();
            $historySchedule = AttendanceSchedule::factory()->create(['rider_id' => $attendanceStaff->id, 'policy_id' => $attendancePolicy->id, 'service_date' => $historyDate->toDateString(), 'scheduled_start_at' => $historyDate->copy()->setTime(9, 0)->utc(), 'scheduled_end_at' => $historyDate->copy()->setTime(18, 0)->utc(), 'authorized_by' => $admin->id]);
            Attendance::factory()->create(['rider_id' => $attendanceStaff->id, 'schedule_id' => $historySchedule->id, 'service_date' => $historyDate->toDateString(), 'raw_clock_in_at' => $historyDate->copy()->setTime(9, 0)->utc(), 'effective_clock_in_at' => $historyDate->copy()->setTime(9, 0)->utc(), 'raw_clock_out_at' => $historyDate->copy()->setTime(17, 0)->utc(), 'effective_clock_out_at' => $historyDate->copy()->setTime(17, 0)->utc(), 'clock_out_request_id' => (string) Str::uuid7(), 'status' => 'present', 'worked_minutes' => 420, 'lock_version' => 1]);
            app(ConfigureAttendance::class)->assign($admin, $attendanceStaff->public_id, $attendancePolicy->public_id, now()->timezone(config('dcms.business_timezone'))->toDateString(), now()->timezone(config('dcms.business_timezone'))->toDateString(), 'scheduled');
        }
        Debtor::factory()->create(['customer_reference' => 'TASK-UI', 'display_name_ciphertext' => 'Synthetic Task Customer']);
        AssignmentBatch::factory()->create(['created_by' => $admin->id, 'name' => 'Synthetic Task Batch', 'external_reference' => 'TASK-UI-BATCH']);
        foreach (['desktop', 'rider-mobile'] as $surface) {
            $customer = Debtor::factory()->create(['customer_reference' => 'RIDER-'.strtoupper($surface), 'display_name_ciphertext' => 'Synthetic Field Customer '.$surface]);
            $account = DebtAccount::factory()->create(['account_number' => 'M06-'.strtoupper($surface)]);
            AccountParty::factory()->create(['debt_account_id' => $account->id, 'debtor_id' => $customer->id]);
            $batch = AssignmentBatch::factory()->create(['created_by' => $admin->id, 'name' => 'Synthetic Rider Batch '.$surface]);
            $task = Task::factory()->create(['debt_account_id' => $account->id, 'customer_id' => $customer->id, 'assignment_batch_id' => $batch->id, 'created_by' => $admin->id, 'scheduled_visit_at' => now(), 'due_at' => now()->addDay(), 'special_instruction_ciphertext' => 'Synthetic field work only. No receipt is recorded.']);
            TaskAllocation::factory()->create(['task_id' => $task->id, 'collector_id' => $rider->staff_id, 'assigned_by' => $admin->id]);
            FinancialSnapshot::factory()->create(['task_id' => $task->id, 'debt_account_id' => $account->id, 'recorded_by' => $admin->id, 'outstanding_balance_ciphertext' => '250.00']);
            foreach (['approve', 'return'] as $reviewPath) {
                $reviewCustomer = Debtor::factory()->create(['customer_reference' => 'VERIFY-'.strtoupper($reviewPath.'-'.$surface), 'display_name_ciphertext' => 'Synthetic Verification '.$reviewPath.' '.$surface]);
                $reviewAccount = DebtAccount::factory()->create(['account_number' => 'M07-'.strtoupper($reviewPath.'-'.$surface)]);
                AccountParty::factory()->create(['debt_account_id' => $reviewAccount->id, 'debtor_id' => $reviewCustomer->id]);
                $reviewTask = Task::factory()->create(['debt_account_id' => $reviewAccount->id, 'customer_id' => $reviewCustomer->id, 'assignment_batch_id' => $batch->id, 'created_by' => $admin->id]);
                TaskAllocation::factory()->create(['task_id' => $reviewTask->id, 'collector_id' => $rider->staff_id, 'assigned_by' => $admin->id]);
                app(RiderWork::class)->start($rider, $reviewTask->refresh(), 0);
                $visit = app(RiderWork::class)->visit($rider, $reviewTask, $reviewTask->refresh()->lock_version, ['purpose' => 'site_visit', 'business_applicable' => false, 'availability' => 'available', 'visit_status' => 'operating', 'contact_result' => 'customer_contacted', 'outcome' => 'successful_visit', 'site_address' => 'Synthetic verification location', 'visit_remarks' => 'Synthetic frozen verification visit'], (string) Str::uuid7());
                // Isolated historical clean metadata only; real provider/scan acceptance is never bypassed in preview.
                Document::factory()->create(['owner_type' => 'task', 'owner_id' => $reviewTask->id, 'activity_id' => $visit->id, 'uploaded_by' => $rider->id, 'document_type_code' => 'proof_image', 'image_category_code' => 'premises', 'scan_status' => 'clean', 'mime_type' => 'image/jpeg']);
                $check = app(CompletionChecklist::class)->review($reviewTask->refresh(), $rider);
                app(RiderWork::class)->submit($rider, $reviewTask, $reviewTask->lock_version, (string) Str::uuid7(), $check['digest']);
            }
        }
        foreach (['desktop', 'rider-mobile'] as $surface) {
            $recipient = Debtor::query()->where('customer_reference', 'RIDER-'.strtoupper($surface))->firstOrFail();
            $email = 'service-'.$surface.'@example.test';
            Contact::factory()->create(['debtor_id' => $recipient->id, 'kind_code' => 'email', 'value_ciphertext' => $email, 'value_fingerprint' => app(CustomerSearch::class)->token('contact:email', $email)]);
        }
        // Guarded historical inbox fixture only: never provisions an account, topic or live listener.
        foreach (['desktop', 'rider-mobile'] as $surface) {
            $receiptAccount = IntegrationAccount::factory()->create(['name' => 'Synthetic SES receipts '.$surface, 'provider_code' => 'amazon_ses']);
            $receipt = IntegrationEvent::factory()->create(['integration_account_id' => $receiptAccount->id, 'state' => 'needs_resolution', 'resolution_code' => 'unmatched_provider_resource']);
            $outbox = new OutboxEvent;
            $outbox->forceFill(['event_key' => (string) Str::uuid7(), 'event_type' => 'ses.receipt', 'aggregate_type' => 'integration_event', 'aggregate_id' => $receipt->id, 'payload' => [], 'available_at' => now()->addDay()])->save();
        }
    }
}
