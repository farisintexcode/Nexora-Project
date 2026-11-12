<?php

namespace App\Console\Commands;

use App\Actions\Tasks\TaskProof;
use App\Models\ImportTemplate;
use App\Models\ReferenceValue;
use App\Services\Attendance\LeaveDocument;
use App\Services\Customers\CustomerSearch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Explains why the assignment-to-report flow would fail on this computer. Shows settings by name, never their values. */
class Doctor extends Command
{
    private const EXTENSIONS = ['ctype', 'dom', 'fileinfo', 'gd', 'iconv', 'mbstring', 'openssl', 'pdo_pgsql', 'simplexml', 'xml', 'xmlreader', 'xmlwriter', 'zip', 'zlib'];

    private const REFERENCE_CATEGORIES = ['assignment_type', 'priority', 'state', 'image_category', 'visit_purpose', 'document_type', 'position'];

    protected $signature = 'dcms:doctor {--fix : Grant missing baseline permissions to the system roles}';

    protected $description = 'Check this computer can run the flow from Add Assignment to Report';

    /** @var array<int, array{0: string, 1: string, 2: string}> */
    private array $results = [];

    public function handle(): int
    {
        $this->checkPhp();
        $this->checkSettings();
        $databaseReady = $this->checkDatabase();
        if ($databaseReady) {
            $this->checkReferenceData();
            $this->checkPermissions();
        }
        $this->checkStorage();
        $this->checkScanner();
        $this->checkBackgroundServices();
        $this->checkAssets();

        $this->table(['Check', 'Result', 'What to do'], $this->results);
        $failures = count(array_filter($this->results, fn (array $result): bool => $result[1] === 'FAIL'));
        if ($failures > 0) {
            $this->error("{$failures} ".Str::plural('problem', $failures).' to fix. After changing .env, run: php artisan config:clear');

            return self::FAILURE;
        }
        $this->info('This computer is ready. Warnings do not stop the flow.');

        return self::SUCCESS;
    }

    private function record(string $check, ?string $problem, string $status = 'FAIL'): void
    {
        $this->results[] = [$check, $problem === null ? 'OK' : $status, $problem ?? ''];
    }

    private function checkPhp(): void
    {
        $this->record('PHP version', PHP_VERSION_ID >= 80300 ? null : 'Use PHP 8.3 or newer. This computer has '.PHP_VERSION.'.');
        $missing = array_values(array_filter(self::EXTENSIONS, fn (string $extension): bool => ! extension_loaded($extension)));
        $this->record('PHP extensions', $missing === [] ? null : 'Enable in php.ini: '.implode(', ', $missing).'. Excel files need zip and the xml extensions.');
        $needed = (int) config('imports.max_kilobytes') * 1024;
        $this->record('PHP upload limit', UploadedFile::getMaxFilesize() >= $needed ? null : 'Set upload_max_filesize and post_max_size in php.ini to at least '.ceil($needed / 1048576).'M, then restart the web server.', 'WARN');
    }

    private function checkSettings(): void
    {
        $this->record('APP_KEY', filled(config('app.key')) ? null : 'Run: php artisan key:generate');
        $this->record('DCMS_SEARCH_KEY', CustomerSearch::isConfigured() ? null : 'Excel uploads and new customers need this. Generate one: php -r "echo base64_encode(random_bytes(32)), PHP_EOL;" and put it in .env. It must be different from APP_KEY.');
        $this->record('Terms and privacy settings', filled(config('dcms.terms_version')) && filled(config('dcms.privacy_url')) ? null : 'Rider invitations need DCMS_TERMS_VERSION and DCMS_PRIVACY_URL. Copy them from .env.example.');
    }

    private function checkDatabase(): bool
    {
        try {
            DB::connection()->getPdo();
        } catch (\Throwable) {
            $this->record('Database connection', 'Cannot connect. Check PostgreSQL is running and DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD in .env. On Laragon, DB_HOST is 127.0.0.1.');

            return false;
        }
        $this->record('Database connection', null);
        $migrator = app('migrator');
        if (! $migrator->repositoryExists()) {
            $this->record('Database migrations', 'Run: php artisan migrate --seed');

            return false;
        }
        $pending = array_diff(array_keys($migrator->getMigrationFiles([database_path('migrations')])), $migrator->getRepository()->getRan());
        $this->record('Database migrations', $pending === [] ? null : count($pending).' not run yet. Run: php artisan migrate');

        return $pending === [];
    }

    private function checkReferenceData(): void
    {
        $this->record('Task upload template', ImportTemplate::query()->where('kind', 'task')->where('is_active', true)->exists() ? null : 'Run: php artisan db:seed');
        $missing = array_values(array_filter(self::REFERENCE_CATEGORIES, fn (string $category): bool => ! ReferenceValue::query()->where('category', $category)->where('is_active', true)->exists()));
        $this->record('Reference data', $missing === [] ? null : 'Missing '.implode(', ', $missing).'. Run: php artisan db:seed');
    }

    private function checkPermissions(): void
    {
        $baseline = [
            'Admin' => array_values(array_diff(config('dcms.permissions'), config('dcms.rider_only_permissions'), config('dcms.reserved_permissions'))),
            'Administrative' => array_values([...array_diff(config('dcms.permissions'), config('dcms.rider_only_permissions'), config('dcms.reserved_permissions'), ['monitoring.view', 'audit.view']), ...config('dcms.office_permissions')]),
            'System Admin' => config('dcms.system_admin_permissions'),
            'Rider' => config('dcms.rider_permissions'),
        ];
        foreach ($baseline as $name => $expected) {
            $role = Role::query()->where('name', $name)->where('guard_name', 'web')->first();
            if (! $role) {
                $this->record("{$name} role", 'Run: php artisan db:seed');

                continue;
            }
            $missing = array_values(array_diff($expected, $role->permissions()->pluck('name')->all()));
            if ($missing !== [] && $this->option('fix')) {
                foreach ($missing as $permission) {
                    Permission::findOrCreate($permission, 'web');
                }
                $role->givePermissionTo($missing);
                app(PermissionRegistrar::class)->forgetCachedPermissions();
                $this->line("Granted {$name}: ".implode(', ', $missing));
                $missing = [];
            }
            $this->record("{$name} role permissions", $missing === [] ? null : 'Missing '.implode(', ', $missing).'. Run: php artisan dcms:doctor --fix');
        }
    }

    private function checkStorage(): void
    {
        foreach (['Excel file storage' => ['imports.disk', 'IMPORT_DISK'], 'Rider proof storage' => ['task_execution.disk', 'PROOF_DISK'], 'Leave document storage' => ['attendance.document_disk', 'LEAVE_DOCUMENT_DISK']] as $label => [$key, $setting]) {
            $disk = config($key);
            if ($disk === 's3' && blank(config('filesystems.disks.s3.bucket'))) {
                $this->record($label, "S3 is not configured. On a development computer, set {$setting}=local in .env.");

                continue;
            }
            $probe = 'doctor-probe-'.Str::random(12).'.tmp';
            try {
                $written = Storage::disk($disk)->put($probe, 'ok');
                Storage::disk($disk)->delete($probe);
            } catch (\Throwable) {
                $written = false;
            }
            $this->record($label, $written ? null : "Cannot write to the {$disk} disk. Check {$setting} and that the storage folder is writable.");
        }
    }

    private function checkScanner(): void
    {
        $required = array_filter([
            'IMPORT_SCAN_REQUIRED' => config('imports.scan_required') || app()->isProduction(),
            'PROOF_SCAN_REQUIRED' => TaskProof::scanRequired(),
            'LEAVE_DOCUMENT_SCAN_REQUIRED' => LeaveDocument::scanRequired(),
        ]);
        if ($required === []) {
            $this->record('Malware scanner', 'Scanning is off. This is fine on a development computer only.', 'WARN');

            return;
        }
        $socket = @stream_socket_client('tcp://'.config('imports.scanner_host').':'.config('imports.scanner_port'), $number, $message, 3);
        if ($socket) {
            fclose($socket);
        }
        $this->record('Malware scanner', $socket ? null : 'ClamAV is not reachable, so uploads will fail. Start ClamAV, or on a development computer set '.implode(' and ', array_map(fn (string $setting): string => "{$setting}=false", array_keys($required))).' in .env.');
    }

    private function checkBackgroundServices(): void
    {
        $usesRedis = array_filter(['SESSION_DRIVER' => config('session.driver'), 'CACHE_STORE' => config('cache.default'), 'QUEUE_CONNECTION' => config('queue.default')], fn (?string $driver): bool => $driver === 'redis');
        if ($usesRedis !== []) {
            try {
                Redis::connection()->ping();
                $this->record('Redis', null);
            } catch (\Throwable) {
                $this->record('Redis', 'Redis is not reachable. Start it, or on a development computer set SESSION_DRIVER=file, CACHE_STORE=file and QUEUE_CONNECTION=sync in .env.');
            }
        }
        $this->record('Background queue', config('queue.default') === 'sync' ? null : 'Keep php artisan schedule:work and a queue worker running, or commits and assignments stay waiting. On a development computer, QUEUE_CONNECTION=sync avoids this.', 'WARN');
    }

    private function checkAssets(): void
    {
        $this->record('Page styles', is_file(public_path('build/manifest.json')) || is_file(public_path('hot')) ? null : 'Run: npm ci and then npm run build');
    }
}
