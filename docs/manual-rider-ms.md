# Manual Nexora — Rider

## Pengaktifan dan password

1. Pentadbir mendaftarkan staff dan menghantar jemputan ke email akaun anda.
2. Buka email **Activate your Nexora staff account**. Dalam preview setempat, email boleh disemak melalui Mailpit di `http://127.0.0.1:18025`.
3. Buka pautan pengaktifan, isi **Your name**, **New password** dan **Confirm password**, serta terima terms/privacy yang dipaparkan.
4. Gunakan sekurang-kurangnya 12 aksara dengan huruf besar, huruf kecil, nombor dan simbol. Kedua-dua password mesti sama.
5. Klik **Activate account**. Anda dibawa ke Rider Dashboard selepas berjaya.

Pautan hanya boleh digunakan sekali dan mempunyai tempoh luput. Jika tidak sah atau luput, minta pentadbir menggunakan **Reissue invitation**. Akaun Invited atau staff Inactive tidak boleh memintas password setup. Jangan berkongsi pautan atau password. Untuk akaun aktif yang terlupa password, gunakan **Forgot password** dan ikuti email recovery.

## Log masuk

Buka `/rider/login` pada alamat aplikasi, contohnya `http://127.0.0.1:8000/rider/login`. Masukkan email berdaftar dan password sendiri. Akses Admin Dashboard, staff management, task assignment dan independent approval tidak diberikan kepada Rider.

## Apply for Leave

1. Dari **Attendance**, buka **Leave Request**, kemudian **Apply for Leave**.
2. Semak nama dan **Employee** number anda. Borang hanya memohon untuk identiti staff sendiri.
3. Pilih **Leave Type** yang tersedia. Jika tiada jenis aktif, sistem menunjukkan panduan dan menyembunyikan borang yang tidak boleh digunakan. Hubungi pentadbir untuk konfigurasi **Leave Types**.
4. Pilih **Start Date** dan **End Date**. End Date tidak boleh mendahului Start Date. Polisi menentukan sama ada tarikh lalu dibenarkan dan had calendar span.
5. Semak bilangan working days. Tempoh dikira daripada jadual kerja berulang yang diluluskan dan override tarikh; rest days dan public holidays tidak dikenakan. Jika kalendar tidak lengkap, pentadbir perlu melengkapkannya dahulu.
6. Isi **Reason for Leave** yang jelas. Elakkan maklumat perubatan/peribadi yang berlebihan.
7. Jika perlu, pilih **Supporting Document**. PDF, JPG atau PNG sehingga 5 MB disokong. Infrastruktur private storage dan scanner mesti tersedia; dokumen yang tidak dapat diperiksa tidak diterima.
8. Klik **Review Application**. Semak Leave Type, tarikh, Total Leave Days, sebab dan lampiran. Gunakan **Back to Edit** jika perlu.
9. Klik **Confirm and Submit** sekali dan tunggu pengesahan. Permohonan menjadi **Pending**.

Permohonan yang bertindih dengan Pending atau Approved ditolak, termasuk pertindihan antara Leave Types berbeza. Permohonan yang sama tidak diwujudkan dua kali jika confirmation dihantar semula. Jika borang, polisi atau kalendar berubah selepas review, semak semula sebelum menghantar.

## Sejarah, status dan pembatalan

Buka **My Leave Requests** untuk menapis mengikut Status, Leave Type dan julat tarikh. Klik **View Request** untuk melihat remarks dan masa keputusan dalam timezone perniagaan `Asia/Kuala_Lumpur`.

- **Pending**: sedang menunggu independent approval.
- **Approved**: cuti diluluskan; working days yang berkaitan dipaparkan sebagai Leave dalam attendance. Jangan clock in pada shift yang dilindungi approved leave.
- **Rejected**: semak alasan reviewer sebelum memohon semula.
- **Cancelled**: permohonan Pending telah dibatalkan mengikut polisi.

Butang **Cancel Application** muncul hanya jika permohonan masih Pending dan peraturan semasa dihantar membenarkan pembatalan. Klik dan sahkan dialog. Approved dan Rejected adalah keputusan akhir; withdrawal selepas approval tidak disokong dalam modul semasa.

Status dikemas kini melalui notifikasi dalam aplikasi; buka pautan **View Leave Request**. Tiada automatic leave email dijanjikan. Jemputan akaun dan email password recovery kekal sebagai aliran berasingan.

**Total Leave Days bukan baki cuti tahunan.** Nexora menyediakan annual entitlement dan baki bagi jenis tracked yang dikonfigurasi. Accrual, carry-forward automatik dan permohonan half-day tidak disokong. Rujuk polisi organisasi untuk kelayakan sebenar. Jika Leave Type dinyahaktifkan selepas permohonan anda dihantar, sejarah dan snapshot permohonan tetap disimpan.

## Penapis seragam dan baki cuti - 9 November 2026

Buka Human Resources > Leave Management. Butang **Leave Types & Entitlements** memerlukan kebenaran konfigurasi. Item sidebar Leave Types dan Administration (Planned) telah dibuang; laluan konfigurasi dikekalkan. Tiga System Role kekal System Admin, Administrative dan Rider; kebenaran granular menentukan tindakan.

Pada Attendance, pilih **Service date**, **Rider** dan **Attendance status**. Buka **Filters** untuk **Rider name or staff number**, kemudian **Apply filters**. Nilai kekal pada borang; **Clear filters** mengembalikan tarikh bulan semasa dan membuang carian/status/Rider. Kad ringkasan, rekod, **Export CSV** dan **Print / Save PDF** menggunakan penapis efektif yang sama. Leave Management menyediakan **Rider / Staff**, **Status** dan **Date**; Filters mengandungi **Rider Name or Employee ID** dan **Leave Type**. Pending, Approved, Rejected dan Cancelled boleh ditapis. Staff Management mengekalkan Search staff, System Role dan Staff Status; Personnel, Account Status, Created date, Sort dan Staff per page berada di Filters. Carian staff dikemas kini secara langsung; Apply filters juga tersedia. Task/Assignment mengekalkan pemilihan, penapis dan pagination. Telefon menyusun medan menegak; Filters menyokong papan kekunci dan tema gelap.

Pentadbir dengan `attendance.configure` membuka **Leave Types & Entitlements** untuk menambah/edit nama, **Description**, had setiap permohonan dan dokumen wajib. Tandakan **Deduct from tracked annual balance**, tetapkan **Leave Year** dan **Annual Entitlement (days)**, kemudian **Save Leave Type**. Nilai ialah polisi organisasi, bukan kelayakan berkanun lalai. Semak tahun sebelum menyimpan; tahun lampau tidak boleh ditulis semula. Deactivate menghentikan permohonan baharu dan mengekalkan sejarah. **Staff Leave Balances / Adjustments** menunjukkan baki Rider/type bagi Leave Year dipilih. Masukkan **Adjustment days** positif/negatif dan **Adjustment reason**, kemudian **Record Adjustment**. Pelarasan diaudit dan tidak boleh dipadam; pembetulan menggunakan pelarasan pampasan.

Baki tersedia = kelayakan tahunan + pelarasan dibenarkan - Approved/Used Days - Pending Reserved Days. Pending menempah hari; Approved menukar tempahan kepada penggunaan tanpa potongan kedua. Rejected dan Cancelled melepaskan tempahan. Permohonan merentas tahun mengira hari bekerja dan baki setiap tahun; konfigurasikan kedua-dua tahun. Permohonan lama tanpa snapshot penjejakan tidak dipotong secara retroaktif. Rekonsiliasi baki pembukaan melalui pelarasan dengan sebab; jangan mereka data sejarah. Jenis tanpa penjejakan menunjukkan **Non-deductible / no tracked limit**. Tiada accrual/carry-forward automatik, separuh hari atau pembatalan selepas Approved.

Buka **Attendance > Schedules & policies**. Terbitkan polisi dengan Working days dan waktu sebenar; jangan menganggap semua Rider bekerja Isnin-Jumaat. Pilih **Rider to schedule**, **Approved policy version** dan **Assign dates** sebagai julat kuat kuasa. Tambah **Public holiday date/name** kepada polisi sebelum tarikh dijana, kemudian **Assign recurring work schedule**. Hari normal dijana apabila cuti dikira; Rest Day dan Public Holiday tidak dicaj. Tarikh override sedia ada diutamakan; perubahan tarikh sedia ada memerlukan pembetulan bebas. Julat berulang tidak boleh bertindih. Mesej Working calendar unavailable memerlukan pentadbir melengkapkan jadual bagi semua tarikh berkenaan.

Rider membuka **Attendance > Apply for Leave**, memilih jenis aktif, menyemak Annual Entitlement, Approved/Used Days, Pending Reserved Days dan Available Days. Pilih **Start Date/End Date** untuk Requested Days dan Projected Balance setiap tahun. Isi **Reason for Leave** dan **Supporting Document** jika wajib; pilih **Review Application**, semak, kemudian **Confirm and Submit**. Permohonan menjadi Pending. Pentadbir dengan `attendance.approve_correction` membuka **View Request** dan membuat keputusan bebas; pemohon tidak boleh meluluskan sendiri. Rejected memerlukan sebab. Rider menerima notifikasi dalam aplikasi dan melihat status/baki melalui butiran permohonan. **Cancel Application** hanya untuk Pending yang dibenarkan snapshot polisi. Dokumen peribadi masih memerlukan S3/scanner.

Ujian manual menggunakan data synthetic berasingan: cipta jenis tracked/kelayakan; konfigurasikan jadual termasuk Sabtu bekerja, Ahad rehat dan cuti umum; mohon satu hari dan semak tempahan; lulus melalui akaun bebas dan pastikan tiada potongan berganda. Ulang dengan penolakan/pembatalan. Cuba pertindihan, baki tidak cukup dan merentas tahun tanpa kelayakan tahun kedua. Semak notifikasi/audit dan paparan Leave dalam Attendance selepas Approved. Uji penapis, Clear filters, jumlah kad dan eksport pada desktop/telefon dalam tema cerah/gelap; cuba akaun tanpa kebenaran dan Rider lain. Workflow invitation/first-time login dikekalkan.
