# Manual Nexora — System Admin

Panduan ini merangkumi HR dan Leave Management yang tersedia sekarang. Label skrin dikekalkan dalam English. Lihat juga [panduan pembangun dan senarai semak](manual-pembangun-ms.md).

## Log masuk dan akses

1. Buka `/login` pada alamat `APP_URL` yang dikonfigurasi. Untuk preview setempat semasa, gunakan `http://127.0.0.1:8000/login`.
2. Gunakan akaun aktif. Akaun berstatus `Invited` mesti menyelesaikan pautan pengaktifan dahulu.
3. Akses ditentukan oleh permissions, status akaun dan status staff. Nama role atau rekod position lama tidak memintas semakan ini.

## Tambah dan ubah staff

1. Buka **Human Resources → Add New Staff**.
2. Dalam **Staff Information**, isi **Staff ID / Staff Number**, **First Name**, **Last Name**, **Email Address**, **System Role** dan **Staff Status**. Maklumat hubungan lain adalah pilihan.
3. Pilih tepat satu role: **System Admin**, **Administrative** atau **Rider**. **Employment Position** tidak lagi digunakan dalam borang atau penapis HR.
4. Dalam **System Account Setup**, pilihan **Create system login account** diaktifkan secara lalai. Provisioning memerlukan `users.invite`; pemberian System Admin dan pertukaran role memerlukan `roles.manage`.
5. Klik **Save staff** sekali dan tunggu keputusan. Staff, akaun berhubung melalui `users.staff_id`, role dan audit disimpan dalam transaksi yang sama.
6. **Created At** dan **Updated At** dijana oleh sistem serta dipaparkan dalam `Asia/Kuala_Lumpur`. Nilai ini tidak boleh diubah secara manual.
7. Gunakan **Staff Management → View / Edit** untuk rekod sedia ada. Staff Status dan Account Status berbeza. Akaun Invited tidak boleh diaktifkan secara manual sebelum password setup.

Pertukaran role memerlukan autoriti berasingan dan direkodkan dalam audit. Rekod position lama dikekalkan untuk keserasian sejarah. Mengurangkan kelayakan Rider atau menyahaktifkan staff memerlukan alasan dan penyelesaian task yang masih diperuntukkan. Sistem menghalang pengurangan akses sendiri dan kehilangan pentadbir aktif terakhir.

## Jemputan staff

Jemputan menggunakan pautan sekali guna dengan tempoh luput password broker. Penerima menetapkan serta mengesahkan password sendiri dan menerima terms/privacy yang dikonfigurasi. Tiada password lalai dikongsi. Selepas pengaktifan, akaun dibawa ke dashboard yang dibenarkan. Untuk pautan luput atau email staff berubah, gunakan **Reissue invitation** pada Staff Details dan patuhi tempoh resend. Perubahan email staff tidak menukar login email akaun aktif secara automatik.

Mailpit setempat menerima mesej di `http://127.0.0.1:18025`. Pilih email penerima yang betul. Jangan salin token ke log atau dokumen awam. Mailer `array` hanya menangkap mesej dalam proses dan tidak menghantar email.

## Konfigurasi Leave Types

1. Buka **Human Resources > Leave Management > Leave Types & Entitlements**, atau klik **Leave Types & Entitlements** dalam **Leave Management**.
2. Akses memerlukan `admin.access`, `attendance.view_all` dan `attendance.configure`. Role System Admin lazimnya membekalkan konfigurasi ini; permissions sebenar masih disemak pada setiap tindakan.
3. Isi **Leave Type Name** dan **Maximum Calendar Span (days)**, antara 1 hingga 93 hari kalendar bagi satu permohonan.
4. Tentukan **Require supporting document**, **Allow applications for past dates**, **Allow Rider to cancel a pending application** dan **Available for new applications** menurut polisi organisasi yang diluluskan.
5. Klik **Save Leave Type**. Nama kosong, nama pendua tanpa mengira huruf besar/kecil dan had tidak sah ditolak.
6. Gunakan **Edit Type** untuk pindaan atau **Deactivate / Activate** untuk ketersediaan baharu. Tindakan menggunakan versi rekod; jika orang lain mengubah polisi, muat semula dan semak sebelum mencuba lagi.

Deactivation tidak memadam permohonan lama. Setiap permohonan menyimpan snapshot nama dan peraturan semasa dihantar. Had calendar span bukan kelayakan cuti tahunan. Nexora menyediakan kelayakan dan baki tahunan secara konfigurasi eksplisit; accrual dan carry-forward automatik tidak disokong. Permohonan separuh hari tidak disokong; hanya hari bekerja penuh daripada jadual diluluskan dan override tarikh digunakan.

Supporting documents memerlukan private S3 storage dan security scanner yang dikonfigurasi. Format yang disokong ialah PDF/JPG/PNG sehingga 5 MB, dengan had dimensi imej yang dipaparkan dalam borang. Jangan mewajibkan dokumen sebelum infrastruktur ini tersedia.

## Sediakan kalendar untuk permohonan

Leave Type sahaja tidak menentukan hari bekerja. Buka **Human Resources → Attendance → Schedules & policies**. Semak atau publish versi polisi yang diluluskan, kemudian gunakan bahagian **Assign calendar days**: pilih **Rider to schedule**, **Approved policy version**, **Assign dates** dan **Calendar day type** yang sesuai. Gunakan Assign recurring work schedule untuk menjana hari normal daripada polisi diluluskan; tarikh override sedia ada diutamakan. Ikut polisi organisasi; jangan menandakan semua hujung minggu sebagai working day semata-mata untuk membolehkan permohonan. Jadual sedia ada memerlukan proses correction berasingan, bukan overwrite. Calendar span satu permohonan dihadkan maksimum 93 hari oleh model semasa.

## Semakan dan keputusan cuti

1. Buka **Human Resources → Leave Management**. Tapis mengikut nama/ID staff, Status, Leave Type dan julat tarikh.
2. Klik **View Request** dan semak tarikh, working days, sebab, dokumen serta attendance conflicts.
3. Untuk membuat keputusan, akaun mesti mempunyai `attendance.approve_correction`. Permission ini reserved dan tidak diberikan secara automatik kepada semua System Admin atau Administrative. Ia perlu diberikan melalui proses pentadbiran akses yang diluluskan.
4. Pilih **Approve** atau **Reject**. Isi **Admin Remarks / Rejection Reason**; alasan wajib untuk penolakan.
5. Klik **Confirm Decision**, semak dialog dan sahkan. Pemohon tidak boleh menyemak permohonannya sendiri. Keputusan akhir tidak boleh dihantar semula atau ditukar sesuka hati.

Approved leave dipaparkan sebagai **Leave** pada attendance register untuk hari bekerja yang berkenaan. Raw punches dan jadual asal tidak diubah atau direka. Clock-in yang bertindih dengan approved leave disekat. Pending, Rejected dan Cancelled tidak menjadi approved leave. Pembetulan attendance pada kalendar cuti yang telah diluluskan disekat.

## Penyelesaian masalah

- **No active leave types**: buka Leave Types dan aktifkan polisi sebenar. Jangan menambah pilihan terus dalam Blade.
- **Working calendar unavailable**: pegawai dengan `attendance.configure` perlu menetapkan recurring work schedule bagi julat permohonan serta public holidays yang diluluskan.
- **Tiada butang keputusan**: semak permission approval dan pastikan permohonan bukan milik sendiri.
- **Calendar changed / stale version**: semak rekod terkini; jangan memaksa overwrite.
- **Email tidak diterima**: semak Mailpit, SMTP host/port, `MAIL_URL=null` jika tiada DSN, dan mesej keputusan penghantaran. Rujuk manual pembangun.

## Penapis seragam dan baki cuti - 9 November 2026

Buka Human Resources > Leave Management. Butang **Leave Types & Entitlements** memerlukan kebenaran konfigurasi. Item sidebar Leave Types dan Administration (Planned) telah dibuang; laluan konfigurasi dikekalkan. Tiga System Role kekal System Admin, Administrative dan Rider; kebenaran granular menentukan tindakan.

Pada Attendance, pilih **Service date**, **Rider** dan **Attendance status**. Buka **Filters** untuk **Rider name or staff number**, kemudian **Apply filters**. Nilai kekal pada borang; **Clear filters** mengembalikan tarikh bulan semasa dan membuang carian/status/Rider. Kad ringkasan, rekod, **Export CSV** dan **Print / Save PDF** menggunakan penapis efektif yang sama. Leave Management menyediakan **Rider / Staff**, **Status** dan **Date**; Filters mengandungi **Rider Name or Employee ID** dan **Leave Type**. Pending, Approved, Rejected dan Cancelled boleh ditapis. Staff Management mengekalkan Search staff, System Role dan Staff Status; Personnel, Account Status, Created date, Sort dan Staff per page berada di Filters. Carian staff dikemas kini secara langsung; Apply filters juga tersedia. Task/Assignment mengekalkan pemilihan, penapis dan pagination. Telefon menyusun medan menegak; Filters menyokong papan kekunci dan tema gelap.

Pentadbir dengan `attendance.configure` membuka **Leave Types & Entitlements** untuk menambah/edit nama, **Description**, had setiap permohonan dan dokumen wajib. Tandakan **Deduct from tracked annual balance**, tetapkan **Leave Year** dan **Annual Entitlement (days)**, kemudian **Save Leave Type**. Nilai ialah polisi organisasi, bukan kelayakan berkanun lalai. Semak tahun sebelum menyimpan; tahun lampau tidak boleh ditulis semula. Deactivate menghentikan permohonan baharu dan mengekalkan sejarah. **Staff Leave Balances / Adjustments** menunjukkan baki Rider/type bagi Leave Year dipilih. Masukkan **Adjustment days** positif/negatif dan **Adjustment reason**, kemudian **Record Adjustment**. Pelarasan diaudit dan tidak boleh dipadam; pembetulan menggunakan pelarasan pampasan.

Baki tersedia = kelayakan tahunan + pelarasan dibenarkan - Approved/Used Days - Pending Reserved Days. Pending menempah hari; Approved menukar tempahan kepada penggunaan tanpa potongan kedua. Rejected dan Cancelled melepaskan tempahan. Permohonan merentas tahun mengira hari bekerja dan baki setiap tahun; konfigurasikan kedua-dua tahun. Permohonan lama tanpa snapshot penjejakan tidak dipotong secara retroaktif. Rekonsiliasi baki pembukaan melalui pelarasan dengan sebab; jangan mereka data sejarah. Jenis tanpa penjejakan menunjukkan **Non-deductible / no tracked limit**. Tiada accrual/carry-forward automatik, separuh hari atau pembatalan selepas Approved.

Buka **Attendance > Schedules & policies**. Terbitkan polisi dengan Working days dan waktu sebenar; jangan menganggap semua Rider bekerja Isnin-Jumaat. Pilih **Rider to schedule**, **Approved policy version** dan **Assign dates** sebagai julat kuat kuasa. Tambah **Public holiday date/name** kepada polisi sebelum tarikh dijana, kemudian **Assign recurring work schedule**. Hari normal dijana apabila cuti dikira; Rest Day dan Public Holiday tidak dicaj. Tarikh override sedia ada diutamakan; perubahan tarikh sedia ada memerlukan pembetulan bebas. Julat berulang tidak boleh bertindih. Mesej Working calendar unavailable memerlukan pentadbir melengkapkan jadual bagi semua tarikh berkenaan.

Rider membuka **Attendance > Apply for Leave**, memilih jenis aktif, menyemak Annual Entitlement, Approved/Used Days, Pending Reserved Days dan Available Days. Pilih **Start Date/End Date** untuk Requested Days dan Projected Balance setiap tahun. Isi **Reason for Leave** dan **Supporting Document** jika wajib; pilih **Review Application**, semak, kemudian **Confirm and Submit**. Permohonan menjadi Pending. Pentadbir dengan `attendance.approve_correction` membuka **View Request** dan membuat keputusan bebas; pemohon tidak boleh meluluskan sendiri. Rejected memerlukan sebab. Rider menerima notifikasi dalam aplikasi dan melihat status/baki melalui butiran permohonan. **Cancel Application** hanya untuk Pending yang dibenarkan snapshot polisi. Dokumen peribadi masih memerlukan S3/scanner.

Ujian manual menggunakan data synthetic berasingan: cipta jenis tracked/kelayakan; konfigurasikan jadual termasuk Sabtu bekerja, Ahad rehat dan cuti umum; mohon satu hari dan semak tempahan; lulus melalui akaun bebas dan pastikan tiada potongan berganda. Ulang dengan penolakan/pembatalan. Cuba pertindihan, baki tidak cukup dan merentas tahun tanpa kelayakan tahun kedua. Semak notifikasi/audit dan paparan Leave dalam Attendance selepas Approved. Uji penapis, Clear filters, jumlah kad dan eksport pada desktop/telefon dalam tema cerah/gelap; cuba akaun tanpa kebenaran dan Rider lain. Workflow invitation/first-time login dikekalkan.
