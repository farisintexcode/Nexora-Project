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
5. Semak bilangan working days. Tempoh dikira daripada jadual attendance eksplisit; rest days dan public holidays tidak dikenakan. Jika kalendar tidak lengkap, pentadbir perlu melengkapkannya dahulu.
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

**Total Leave Days bukan baki cuti tahunan.** Nexora belum menyediakan annual entitlement, accrual, carry-forward atau permohonan half-day. Rujuk polisi organisasi untuk kelayakan sebenar. Jika Leave Type dinyahaktifkan selepas permohonan anda dihantar, sejarah dan snapshot permohonan tetap disimpan.
