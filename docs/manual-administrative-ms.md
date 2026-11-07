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

**Total Leave Days** ialah bilangan hari bekerja yang ditentukan oleh jadual eksplisit. Rest days dan public holidays dikecualikan. **Maximum Calendar Span** mengehadkan jarak tarikh satu permohonan; ia bukan annual entitlement. Modul tidak mengira baki tahunan, accrual, carry-forward, payroll atau permohonan half-day.

Nama atau peraturan Leave Type yang diubah tidak menulis semula snapshot permohonan lama. Deactivation menghalang permohonan baharu sahaja. Permohonan lama masih boleh disemak menggunakan peraturan yang telah dihantar.

## Penolakan, pembatalan dan notifikasi

Berikan alasan penolakan yang jelas dan minimum, tanpa data peribadi yang tidak diperlukan. Keputusan disimpan bersama reviewer dan masa; status serta remarks dapat dilihat pada request details. Notifikasi cuti tersedia dalam aplikasi. Notifikasi ini bukan email invitation dan tidak dihantar melalui Mailpit. Jemputan staff dan password recovery terus menggunakan mailer sedia ada.

Pembatalan Approved atau Rejected bukan fungsi semasa. Jangan memadam rekod atau menukar keputusan melalui database. Eskalasi ke pemilik polisi jika terdapat kes luar biasa.

Untuk ujian praktikal dan troubleshooting, lihat [manual pembangun](manual-pembangun-ms.md). Untuk pemohon, lihat [manual Rider](manual-rider-ms.md).
