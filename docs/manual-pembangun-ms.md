# Manual Nexora — Pembangun dan Ujian Setempat

## Skop dan keselamatan

Branch kerja ialah `drago`. Jangan reset database preview pengguna. Perubahan ini menambah halaman Leave Types dan menjadikan `staff.position_code` nullable; ia tidak memadam staff, akaun, sejarah cuti atau position lama. Normal seeding tidak mencipta password atau mengandaikan kelayakan cuti berkanun.

Empat panduan pengguna: [System Admin](manual-system-admin-ms.md), [Administrative](manual-administrative-ms.md), [Rider](manual-rider-ms.md) dan manual ini. `Plan.md`, `Module.md`, `Prototype.md` serta `Database.md` kekal sebagai rujukan kontrak domain.

## Persekitaran preview semasa

Semak `.env` dan `php artisan config:show app.env --no-interaction` dahulu. Jangan cetak konfigurasi database penuh kerana ia mungkin mengandungi secret.

| Perkhidmatan | Preview setempat |
|---|---|
| Laravel | `http://127.0.0.1:8000` |
| Admin login | `/login` |
| Rider login | `/rider/login` |
| PostgreSQL | `127.0.0.1:15432` |
| Redis | `127.0.0.1:16379` |
| Mailpit SMTP | `127.0.0.1:11025` |
| Mailpit web | `http://127.0.0.1:18025` |
| Vite | `127.0.0.1:5173` apabila dev server digunakan |

Untuk mesin ini, kontena preview telah disediakan secara berasingan daripada database asal `dcms`. Semak sebelum memulakan semula:

```powershell
docker ps -a --filter name=nexora-preview
docker start nexora-preview-pg nexora-preview-redis nexora-preview-mailpit
```

Jalankan hanya untuk kontena preview setempat yang dikenal pasti. Untuk mesin baharu, gunakan workflow Sail atau Laragon dalam README dan ports yang disahkan. Jangan menjalankan dua Compose project pada ports yang sama. Jangan menggunakan `docker compose down -v`, `migrate:fresh` atau `db:wipe` terhadap preview yang mempunyai data pengguna.

Dalam terminal berasingan dari root project, jika proses berkenaan belum berjalan:

```powershell
php artisan serve --host=127.0.0.1 --port=8000 --no-interaction
npm.cmd run dev -- --host=127.0.0.1 --port=5173 --strictPort
php artisan queue:work redis --queue=default,imports,media --sleep=1 --timeout=120 --tries=3 --no-interaction
php artisan schedule:work --no-interaction
```

Jemputan staff menggunakan notification sedia ada selepas transaksi commit; worker email berasingan tidak diperlukan untuk penghantaran jemputan ini. Worker dan scheduler masih diperlukan untuk operasi lain yang menggunakan Redis/outbox.

## Migrations dan data konfigurasi tanpa reset

```powershell
php artisan migrate:status --no-interaction
php artisan migrate --no-interaction
php artisan db:seed --class=RolePermissionSeeder --no-interaction
php artisan config:clear --no-interaction
php artisan dcms:doctor --no-interaction
```

Migration `make_legacy_staff_position_optional` melonggarkan NOT NULL sahaja. Composite foreign key dan nilai position lama kekal. Borang baharu tidak menerima input position. Rollback sengaja ditolak jika staff tanpa position sudah wujud; gunakan forward fix dan jangan mereka nilai position untuk memaksa rollback.

Leave Types ialah konfigurasi organisasi, bukan reference codes wajib. `DatabaseSeeder` tidak menyemai kategori atau entitlements secara automatik. Dalam **Human Resources > Leave Management > Leave Types & Entitlements**, pentadbir dengan `attendance.configure` boleh menambah polisi sebenar.

Untuk contoh setempat sahaja, gantikan email berikut dengan pentadbir aktif yang mempunyai `admin.access`, `attendance.view_all` dan `attendance.configure`:

```powershell
php artisan dcms:seed-leave-samples system-admin@preview.example.test --no-interaction
```

Command ini menambah lima kategori berlabel **Sample** sebagai Inactive. Tambah `--activate` hanya jika mahu menggunakannya untuk demo setempat. Sample Annual/Sick/Emergency/Unpaid/Compassionate Leave bukan polisi undang-undang atau kelayakan tahunan. Nilai 93 hari ialah had teknikal calendar span sedia ada, bukan jumlah entitlement. Command tidak mengubah polisi yang sudah wujud, tidak mencipta akaun, dan ditolak dalam production/staging. Aktifkan atau ubah sample sedia ada melalui UI, bukan dengan mengharapkan rerun command menimpa pilihan pentadbir.

## Mailpit dan notifikasi

Untuk SMTP Laragon, gunakan `MAIL_MAILER=smtp`, `MAIL_HOST=127.0.0.1`, `MAIL_PORT=11025` dan `MAIL_URL=null` jika tiada SMTP DSN. String kosong untuk MAIL_URL boleh mengatasi konfigurasi SMTP. Pastikan `APP_URL` betul sebelum menghantar jemputan; pautan terikat pada alamat ini. `DCMS_TERMS_VERSION` dan `DCMS_PRIVACY_URL` perlu mengandungi nilai yang diluluskan. Jangan menjana semula APP_KEY untuk environment yang mempunyai data encrypted.

Buka Mailpit dan cari penerima serta subject yang betul. Uji pengaktifan sekali guna, confirmation password, normal login dan resend selepas tempoh throttle. Jangan tulis password, email body atau token pengaktifan dalam output terminal, screenshot/trace awam, Git atau manual. Mailer `array` tidak menghantar mesej ke Mailpit; mailer `log` disekat untuk jemputan bagi mengelakkan token direkodkan.

**LeaveUpdated** ialah notifikasi dalam aplikasi melalui channel database. Ia tidak menghantar email cuti. Semak halaman notifikasi atau Recent leave notifications, bukan Mailpit, untuk status leave. Jemputan dan password recovery tidak diubah oleh modul ini.

## Ujian automatik

PHP tests memerlukan PostgreSQL sebenar. Gunakan database disposable yang berasingan dan disahkan, contohnya `nexora_leave_testing_20261108`; jangan gunakan nama database preview. Sediakan secret ujian sendiri tanpa mencetaknya, dan kekalkan `DB_CONNECTION=pgsql`. Laravel test traits boleh menjalankan migrations yang memadam data dalam database ujian.

Contoh PowerShell selepas database ujian dan aksesnya disediakan:

```powershell
$env:APP_ENV='testing'
$env:DB_DATABASE='nexora_leave_testing_20261108'
$env:DB_URL=''
php artisan config:show database.connections.pgsql.database --no-interaction
php artisan test --compact tests/Feature/LeaveTypeConfigurationTest.php tests/Feature/LeaveRequestTest.php
php artisan test --compact tests/Feature/AdminHrAccountTest.php tests/Feature/StaffManagementTest.php tests/Feature/StaffInvitationTest.php tests/Feature/Foundation tests/Feature/AttendanceTest.php tests/Feature/TaskManagementTest.php tests/Feature/RiderExecutionTest.php
php vendor/bin/pint --dirty --format agent
php artisan view:cache --no-interaction
npm.cmd run build
Remove-Item Env:APP_ENV,Env:DB_DATABASE,Env:DB_URL
```

Jika `.env.testing` digunakan, pastikan host, port, nama database dan keys merujuk environment ujian. Jangan menukar kepada SQLite: FK, JSONB, locks dan immutable history adalah sebahagian kontrak. Dalam Docker/Sail, gunakan PHP 8.4 dengan extension projek dan PostgreSQL 17 serta jalankan arahan sama melalui runtime tersebut. Kod juga menyokong native PHP 8.3 yang dipasang pada mesin ini.

Browser acceptance menggunakan database disposable berakhiran `_e2e`, `BrowserTestSeeder` dan password runtime. Seeder ini menghasilkan identiti synthetic; jangan jalankannya pada preview pengguna. Set `PLAYWRIGHT_BASE_URL`, `DCMS_E2E_ADMIN_EMAIL`, `DCMS_E2E_RIDER_EMAIL` dan `DCMS_E2E_PASSWORD` dalam environment proses sahaja. Untuk fixture biasa, email ialah `admin@example.test`, `hr-admin@example.test`, `administrative@example.test` dan `rider@example.test`; tiada password lalai disimpan dalam source.

```powershell
npx.cmd playwright test tests/Browser/leave-configuration.spec.ts tests/Browser/leave-requests.spec.ts tests/Browser/admin-hr.spec.ts tests/Browser/staff.spec.ts --trace=off
```

Playwright config sedia ada menjalankan desktop dan rider-mobile dengan satu worker. Untuk runtime Chromium dalam folder ignored setempat, tetapkan `PLAYWRIGHT_BROWSERS_PATH` kepada `.local-tools/browsers`; jika runtime belum dipasang, pasang Chromium melalui workflow projek. Fail `tests/Browser/local-preview.spec.ts` milik sesi preview terdahulu tidak diperlukan untuk perubahan ini dan tidak termasuk dalam commit.

## Senarai semak manual dengan hasil dijangka

| Langkah | Hasil dijangka |
|---|---|
| System Admin membuka Add New Staff | Tiga System Role sahaja; tiada Employment Position. |
| Simpan setiap role dengan email/ID unik | Staff dan user berhubung; akaun Invited dan audit tersedia; position baharu NULL. |
| Edit rekod staff lama | Rekod kekal boleh dibaca; role boleh diubah dengan autoriti; position sejarah tidak ditulis semula. |
| Buka Leave Types tanpa permission | HTTP 403 dan tiada write melalui URL/Livewire terus. |
| Tambah, edit, deactivate, activate jenis cuti | Versi disemak; audit disimpan; sejarah permohonan kekal. |
| Rider membuka Apply tanpa jenis aktif | Panduan jelas dipaparkan; borang submit yang tidak boleh digunakan disembunyikan. |
| Pilih jenis aktif serta kalendar lengkap | Working days tepat; rest/holiday dikecualikan. |
| Hantar field kosong, tarikh terbalik, overlap atau dokumen tidak sah | Validation jelas; tiada permohonan separa atau duplicate. |
| Ubah polisi/kalendar selepas review | Confirmation lama ditolak dan perlu review semula. |
| Reviewer tanpa grant atau pemohon sendiri membuat keputusan | Akses/keputusan ditolak; status tidak berubah. |
| Independent reviewer approve | Status Approved, audit dan notifikasi in-app; register attendance menunjukkan Leave tanpa raw punch palsu. |
| Clock in pada approved leave | Ditolak. Pembetulan kalendar cuti diluluskan juga ditolak. |
| Reject tanpa alasan | Ditolak; alasan sah menyimpan Rejected dan boleh dilihat pemohon. |
| Cancel permohonan Pending | Hanya dibenarkan oleh snapshot polisi; tiada perubahan attendance kepada Leave. |
| Klik confirmation/decision berulang | Tiada duplicate atau keputusan berganda; refresh menunjukkan status sebenar. |
| Cuba request staff lain | HTTP 404/403; alasan dan dokumen tidak dibocorkan. |
| Uji desktop, 320px, 430px dan dark mode | Labels, controls, empty states dan scrolling kekal boleh digunakan. |
| Aktifkan invitation, guna semula, cuba expired token | Pengaktifan pertama berjaya; reuse/expiry ditolak; normal login kekal berfungsi. |

## Had dan troubleshooting

- Annual entitlement dan baki tersedia melalui konfigurasi eksplisit. Tiada accrual, carry-forward automatik, payroll, half-day atau withdrawal selepas approval; jangan mereka baki sejarah.
- Semua tarikh memerlukan recurring work schedule yang diluluskan atau override eksplisit. Hari normal dijana daripada polisi; tiada statutory holidays atau leave entitlements direka.
- Private supporting documents masih memerlukan S3 dan scanner. Infrastruktur yang tidak tersedia menghasilkan validation yang jelas; jangan mematikan semakan keselamatan untuk meluluskan fail.
- Jika jenis cuti hilang, semak status Active dan permission konfigurasi; tiada tenant/client selector dalam model leave_types semasa.
- Semak logs terkini sahaja. Bezakan HTTP 403/404 yang memang dijangka dalam ujian keselamatan daripada exception yang tidak dijangka. Jangan cetak secret atau token ketika debugging.

## Penapis seragam dan baki cuti - 9 November 2026

Buka Human Resources > Leave Management. Butang **Leave Types & Entitlements** memerlukan kebenaran konfigurasi. Item sidebar Leave Types dan Administration (Planned) telah dibuang; laluan konfigurasi dikekalkan. Tiga System Role kekal System Admin, Administrative dan Rider; kebenaran granular menentukan tindakan.

Pada Attendance, pilih **Service date**, **Rider** dan **Attendance status**. Buka **Filters** untuk **Rider name or staff number**, kemudian **Apply filters**. Nilai kekal pada borang; **Clear filters** mengembalikan tarikh bulan semasa dan membuang carian/status/Rider. Kad ringkasan, rekod, **Export CSV** dan **Print / Save PDF** menggunakan penapis efektif yang sama. Leave Management menyediakan **Rider / Staff**, **Status** dan **Date**; Filters mengandungi **Rider Name or Employee ID** dan **Leave Type**. Pending, Approved, Rejected dan Cancelled boleh ditapis. Staff Management mengekalkan Search staff, System Role dan Staff Status; Personnel, Account Status, Created date, Sort dan Staff per page berada di Filters. Carian staff dikemas kini secara langsung; Apply filters juga tersedia. Task/Assignment mengekalkan pemilihan, penapis dan pagination. Telefon menyusun medan menegak; Filters menyokong papan kekunci dan tema gelap.

Pentadbir dengan `attendance.configure` membuka **Leave Types & Entitlements** untuk menambah/edit nama, **Description**, had setiap permohonan dan dokumen wajib. Tandakan **Deduct from tracked annual balance**, tetapkan **Leave Year** dan **Annual Entitlement (days)**, kemudian **Save Leave Type**. Nilai ialah polisi organisasi, bukan kelayakan berkanun lalai. Semak tahun sebelum menyimpan; tahun lampau tidak boleh ditulis semula. Deactivate menghentikan permohonan baharu dan mengekalkan sejarah. **Staff Leave Balances / Adjustments** menunjukkan baki Rider/type bagi Leave Year dipilih. Masukkan **Adjustment days** positif/negatif dan **Adjustment reason**, kemudian **Record Adjustment**. Pelarasan diaudit dan tidak boleh dipadam; pembetulan menggunakan pelarasan pampasan.

Baki tersedia = kelayakan tahunan + pelarasan dibenarkan - Approved/Used Days - Pending Reserved Days. Pending menempah hari; Approved menukar tempahan kepada penggunaan tanpa potongan kedua. Rejected dan Cancelled melepaskan tempahan. Permohonan merentas tahun mengira hari bekerja dan baki setiap tahun; konfigurasikan kedua-dua tahun. Permohonan lama tanpa snapshot penjejakan tidak dipotong secara retroaktif. Rekonsiliasi baki pembukaan melalui pelarasan dengan sebab; jangan mereka data sejarah. Jenis tanpa penjejakan menunjukkan **Non-deductible / no tracked limit**. Tiada accrual/carry-forward automatik, separuh hari atau pembatalan selepas Approved.

Buka **Attendance > Schedules & policies**. Terbitkan polisi dengan Working days dan waktu sebenar; jangan menganggap semua Rider bekerja Isnin-Jumaat. Pilih **Rider to schedule**, **Approved policy version** dan **Assign dates** sebagai julat kuat kuasa. Tambah **Public holiday date/name** kepada polisi sebelum tarikh dijana, kemudian **Assign recurring work schedule**. Hari normal dijana apabila cuti dikira; Rest Day dan Public Holiday tidak dicaj. Tarikh override sedia ada diutamakan; perubahan tarikh sedia ada memerlukan pembetulan bebas. Julat berulang tidak boleh bertindih. Mesej Working calendar unavailable memerlukan pentadbir melengkapkan jadual bagi semua tarikh berkenaan.

Rider membuka **Attendance > Apply for Leave**, memilih jenis aktif, menyemak Annual Entitlement, Approved/Used Days, Pending Reserved Days dan Available Days. Pilih **Start Date/End Date** untuk Requested Days dan Projected Balance setiap tahun. Isi **Reason for Leave** dan **Supporting Document** jika wajib; pilih **Review Application**, semak, kemudian **Confirm and Submit**. Permohonan menjadi Pending. Pentadbir dengan `attendance.approve_correction` membuka **View Request** dan membuat keputusan bebas; pemohon tidak boleh meluluskan sendiri. Rejected memerlukan sebab. Rider menerima notifikasi dalam aplikasi dan melihat status/baki melalui butiran permohonan. **Cancel Application** hanya untuk Pending yang dibenarkan snapshot polisi. Dokumen peribadi masih memerlukan S3/scanner.

Ujian manual menggunakan data synthetic berasingan: cipta jenis tracked/kelayakan; konfigurasikan jadual termasuk Sabtu bekerja, Ahad rehat dan cuti umum; mohon satu hari dan semak tempahan; lulus melalui akaun bebas dan pastikan tiada potongan berganda. Ulang dengan penolakan/pembatalan. Cuba pertindihan, baki tidak cukup dan merentas tahun tanpa kelayakan tahun kedua. Semak notifikasi/audit dan paparan Leave dalam Attendance selepas Approved. Uji penapis, Clear filters, jumlah kad dan eksport pada desktop/telefon dalam tema cerah/gelap; cuba akaun tanpa kebenaran dan Rider lain. Workflow invitation/first-time login dikekalkan.

### Gate integrasi Docker dan migration bagi perubahan ini

Migration additive: `2026_11_08_171943_add_leave_entitlements_and_recurring_work_calendars`. Semak `php artisan migrate:status --no-interaction`, jalankan `php artisan migrate --pretend --no-interaction`, kemudian `php artisan migrate --no-interaction`. Migration ini menambah dua medan leave_types dan empat jadual polisi/baki/jadual berulang; ia tidak backfill kelayakan atau menukar permohonan lama. Pada preview yang diperiksa, kiraan sebelum/selepas kekal 7 users, 8 staff, 0 tasks, 5 leave_types, 0 leave_requests dan 0 attendances. Tiada reset atau seeder dijalankan pada preview.

Ujian PostgreSQL menggunakan database disposable `nexora_leave_testing`; browser menggunakan database berakhiran `_e2e` yang berasingan serta password runtime. Image setempat `nexora-preview-tests:local` menyediakan PHP 8.4 dan GD. Gunakan `php -d memory_limit=512M vendor/bin/pest ...` untuk set regression yang melibatkan workbook besar. Test concurrency menggunakan dua proses sebenar dan data committed pada database ujian, dengan DatabaseTruncation; ia tidak menjalankan rollback migration legacy yang sengaja menolak kehilangan rekod staff tanpa position.

Server browser yang mendapat DB/Redis melalui environment kontena mesti menggunakan `php artisan serve --host=0.0.0.0 --port=8000 --no-reload --no-interaction`. Reload mode Laravel menapis environment child process dan boleh membaca semula nilai `.env`; `--no-reload` mengekalkan override database/Redis ujian. Semak nama database efektif sebelum seeding. Jalankan Playwright hanya terhadap server ujian ini. Redis test menggunakan logical database berasingan. Jika Chromium revision tiada, `npx.cmd playwright install chromium` memasang runtime browser tanpa mengubah dependencies projek.

### Keputusan automatik sebenar - 9 November 2026

| Invocation | Lulus | Gagal | Skip | Assertions / masa |
|---|---:|---:|---:|---|
| Regression luas PostgreSQL/Docker sebelum pembetulan terakhir | 324 | 2 | 1 | 1,585 assertions; 532.33s |
| Rerun fokus balance, leave, shared calendars dan concurrency selepas pembetulan | 89 | 0 | 0 | 384 assertions; 163.40s |
| Rerun akhir 15 kes balance/adjustment/filter | 15 | 0 | 0 | 94 assertions; 35.43s |
| Concurrency dahulu, kemudian 33 kes leave requests untuk membuktikan isolation dan dokumen | 34 | 0 | 0 | 126 assertions; 67.78s |
| Playwright akhir: 10 desktop + 10 rider-mobile | 20 | 0 | 0 | 8.6 minit |

Dua kegagalan regression awal telah diselesaikan: calendar field baharu kini menggunakan shared date component, dan concurrency test menggunakan closure bebas daripada Pest class serta cleanup yang serasi dengan transactional tests. Semakan calendar lama turut dikemas kini kerana Staff editor memaparkan timestamps read-only, bukannya editable appointment date. Cleanup concurrency menandakan migration baseline perlu dibina semula supaya reference data milik migration kekal tersedia pada test seterusnya.

Jumlah kes backend berbeza yang akhirnya lulus ialah 328, dengan satu skip EXIF sedia ada kerana decoder pilihan tidak tersedia; ini gabungan regression dan rerun, bukan dakwaan satu invocation 328 kes. Dua kes tambahan menguji immutable/authorized adjustment history dan keempat-empat status filter. Percubaan awal juga mengenal pasti GD yang tiada pada image lama, memory limit 128 MB yang tidak cukup untuk workbook, Chromium revision yang belum dipasang dan browser helpers lama; semua gate berkaitan telah dijalankan semula dalam runtime sesuai. Tiada gate gagal yang belum diselesaikan dalam skop ini.

Pint `--dirty --format agent`, pemformatan fail PHP baharu, `php artisan view:cache`, `npm.cmd run build`, migration additive preview dan `git diff --check` lulus. Browser memeriksa filter expand/clear, widths 320/768/1280, dark mode, recurring schedule, baki sebelum/selepas independent approval, rejection/cancellation, attendance correction, staff, assignment dan Task ID/allocation. Screenshot desktop dan mobile telah diperiksa. Tiada production deployment, push Git atau external provider activation dilakukan.
