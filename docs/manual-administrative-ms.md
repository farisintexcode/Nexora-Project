# Manual Nexora — Administrative

## Log masuk dan skop

Buka `/login`, lengkapkan pengaktifan jika akaun masih **Invited**, kemudian log masuk. Dashboard Admin tersedia jika permissions akaun membenarkannya. Akses operasi bergantung pada permissions yang diberikan; role Administrative tidak membawa `roles.manage`, restricted settings atau permission kelulusan secara automatik.

Fungsi operasi yang dibenarkan boleh merangkumi task monitoring, assignment, independent task verification, reports dan attendance management. Rekod position lama tidak menentukan akses. Jangan menggunakan akaun Rider untuk tugas pentadbiran atau berkongsi akaun System Admin.

## Leave Management

1. Buka **Human Resources → Leave Management** jika mempunyai `admin.access` dan `attendance.view_all`.
2. Gunakan **Rider Name or Employee ID**, **Status**, **Leave Type** dan **Date**, kemudian klik **Apply Filters**.
3. Klik **View Request** untuk melihat identiti staff, tarikh, jumlah working days, sebab dan keputusan. Akses dokumen memerlukan autoriti ke atas rekod yang sama; pautan dokumen bukan pautan awam.
4. Semak attendance conflicts. Jadual yang berubah, punch bertindih atau correction yang belum selesai boleh menghalang approval.
5. Jika diberikan `attendance.approve_correction` secara eksplisit, pilih **Approve / Reject**, isi remarks dan klik **Confirm Decision**. **Reject** memerlukan alasan.
6. Jika butang keputusan tiada, minta pentadbir akses menyemak autoriti. Jangan menggunakan role label atau kaedah URL terus untuk memintasnya.

Pemohon tidak boleh meluluskan permohonannya sendiri, walaupun turut mempunyai permission pentadbiran. Konfigurasi Leave Types memerlukan `attendance.configure`; Administrative biasa tidak boleh mengubah polisi hanya kerana boleh melihat permohonan.

## Maksud status

| Status | Maksud dan tindakan |
|---|---|
| Pending | Permohonan diterima dan menunggu independent review. Belum menjadi cuti yang diluluskan. |
| Approved | Keputusan akhir. Attendance register memaparkan Leave bagi hari bekerja yang diluluskan; tiada raw punch direka. |
| Rejected | Keputusan akhir dengan alasan. Pemohon boleh mempertimbangkan permohonan baharu mengikut polisi dan tarikh yang sah. |
| Cancelled | Pemohon membatalkan permohonan Pending apabila snapshot polisi membenarkannya. Bukan approval dan tidak mengubah attendance kepada Leave. |

Klik keputusan berulang atau menggunakan halaman lama tidak mewujudkan approval berganda. Muat semula halaman untuk melihat status terkini jika sistem memaklumkan bahawa rekod telah diputuskan.

## Hari cuti dan polisi

**Total Leave Days** ialah bilangan hari bekerja yang ditentukan oleh jadual kerja diluluskan dan override tarikh. Rest days dan public holidays dikecualikan. **Maximum Calendar Span** mengehadkan jarak tarikh satu permohonan; ia bukan annual entitlement. Baki tahunan menggunakan entitlement eksplisit, pelarasan, approved usage dan pending reservations. Accrual, carry-forward automatik, payroll dan permohonan half-day tidak disokong.

Nama atau peraturan Leave Type yang diubah tidak menulis semula snapshot permohonan lama. Deactivation menghalang permohonan baharu sahaja. Permohonan lama masih boleh disemak menggunakan peraturan yang telah dihantar.

## Penolakan, pembatalan dan notifikasi

Berikan alasan penolakan yang jelas dan minimum, tanpa data peribadi yang tidak diperlukan. Keputusan disimpan bersama reviewer dan masa; status serta remarks dapat dilihat pada request details. Notifikasi cuti tersedia dalam aplikasi. Notifikasi ini bukan email invitation dan tidak dihantar melalui Mailpit. Jemputan staff dan password recovery terus menggunakan mailer sedia ada.

Pembatalan Approved atau Rejected bukan fungsi semasa. Jangan memadam rekod atau menukar keputusan melalui database. Eskalasi ke pemilik polisi jika terdapat kes luar biasa.

Untuk ujian praktikal dan troubleshooting, lihat [manual pembangun](manual-pembangun-ms.md). Untuk pemohon, lihat [manual Rider](manual-rider-ms.md).

## Penapis seragam dan baki cuti - 9 November 2026

Buka Human Resources > Leave Management. Butang **Leave Types & Entitlements** memerlukan kebenaran konfigurasi. Item sidebar Leave Types dan Administration (Planned) telah dibuang; laluan konfigurasi dikekalkan. Tiga System Role kekal System Admin, Administrative dan Rider; kebenaran granular menentukan tindakan.

Pada Attendance, pilih **Service date**, **Rider** dan **Attendance status**. Buka **Filters** untuk **Rider name or staff number**, kemudian **Apply filters**. Nilai kekal pada borang; **Clear filters** mengembalikan tarikh bulan semasa dan membuang carian/status/Rider. Kad ringkasan, rekod, **Export CSV** dan **Print / Save PDF** menggunakan penapis efektif yang sama. Leave Management menyediakan **Rider / Staff**, **Status** dan **Date**; Filters mengandungi **Rider Name or Employee ID** dan **Leave Type**. Pending, Approved, Rejected dan Cancelled boleh ditapis. Staff Management mengekalkan Search staff, System Role dan Staff Status; Personnel, Account Status, Created date, Sort dan Staff per page berada di Filters. Carian staff dikemas kini secara langsung; Apply filters juga tersedia. Task/Assignment mengekalkan pemilihan, penapis dan pagination. Telefon menyusun medan menegak; Filters menyokong papan kekunci dan tema gelap.

Pentadbir dengan `attendance.configure` membuka **Leave Types & Entitlements** untuk menambah/edit nama, **Description**, had setiap permohonan dan dokumen wajib. Tandakan **Deduct from tracked annual balance**, tetapkan **Leave Year** dan **Annual Entitlement (days)**, kemudian **Save Leave Type**. Nilai ialah polisi organisasi, bukan kelayakan berkanun lalai. Semak tahun sebelum menyimpan; tahun lampau tidak boleh ditulis semula. Deactivate menghentikan permohonan baharu dan mengekalkan sejarah. **Staff Leave Balances / Adjustments** menunjukkan baki Rider/type bagi Leave Year dipilih. Masukkan **Adjustment days** positif/negatif dan **Adjustment reason**, kemudian **Record Adjustment**. Pelarasan diaudit dan tidak boleh dipadam; pembetulan menggunakan pelarasan pampasan.

Baki tersedia = kelayakan tahunan + pelarasan dibenarkan - Approved/Used Days - Pending Reserved Days. Pending menempah hari; Approved menukar tempahan kepada penggunaan tanpa potongan kedua. Rejected dan Cancelled melepaskan tempahan. Permohonan merentas tahun mengira hari bekerja dan baki setiap tahun; konfigurasikan kedua-dua tahun. Permohonan lama tanpa snapshot penjejakan tidak dipotong secara retroaktif. Rekonsiliasi baki pembukaan melalui pelarasan dengan sebab; jangan mereka data sejarah. Jenis tanpa penjejakan menunjukkan **Non-deductible / no tracked limit**. Tiada accrual/carry-forward automatik, separuh hari atau pembatalan selepas Approved.

Buka **Attendance > Schedules & policies**. Terbitkan polisi dengan Working days dan waktu sebenar; jangan menganggap semua Rider bekerja Isnin-Jumaat. Pilih **Rider to schedule**, **Approved policy version** dan **Assign dates** sebagai julat kuat kuasa. Tambah **Public holiday date/name** kepada polisi sebelum tarikh dijana, kemudian **Assign recurring work schedule**. Hari normal dijana apabila cuti dikira; Rest Day dan Public Holiday tidak dicaj. Tarikh override sedia ada diutamakan; perubahan tarikh sedia ada memerlukan pembetulan bebas. Julat berulang tidak boleh bertindih. Mesej Working calendar unavailable memerlukan pentadbir melengkapkan jadual bagi semua tarikh berkenaan.

Rider membuka **Attendance > Apply for Leave**, memilih jenis aktif, menyemak Annual Entitlement, Approved/Used Days, Pending Reserved Days dan Available Days. Pilih **Start Date/End Date** untuk Requested Days dan Projected Balance setiap tahun. Isi **Reason for Leave** dan **Supporting Document** jika wajib; pilih **Review Application**, semak, kemudian **Confirm and Submit**. Permohonan menjadi Pending. Pentadbir dengan `attendance.approve_correction` membuka **View Request** dan membuat keputusan bebas; pemohon tidak boleh meluluskan sendiri. Rejected memerlukan sebab. Rider menerima notifikasi dalam aplikasi dan melihat status/baki melalui butiran permohonan. **Cancel Application** hanya untuk Pending yang dibenarkan snapshot polisi. Dokumen peribadi masih memerlukan S3/scanner.

Ujian manual menggunakan data synthetic berasingan: cipta jenis tracked/kelayakan; konfigurasikan jadual termasuk Sabtu bekerja, Ahad rehat dan cuti umum; mohon satu hari dan semak tempahan; lulus melalui akaun bebas dan pastikan tiada potongan berganda. Ulang dengan penolakan/pembatalan. Cuba pertindihan, baki tidak cukup dan merentas tahun tanpa kelayakan tahun kedua. Semak notifikasi/audit dan paparan Leave dalam Attendance selepas Approved. Uji penapis, Clear filters, jumlah kad dan eksport pada desktop/telefon dalam tema cerah/gelap; cuba akaun tanpa kebenaran dan Rider lain. Workflow invitation/first-time login dikekalkan.
