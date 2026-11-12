# PRD: Pesanan Belum Dibayar di Navbar

**Proyek:** Resto Laut Lestari · **Versi:** 1.1 (Draft) · **Tanggal:** 10 November 2026 · **Pemilik:** Masyhabi

**Prasyarat:** PRD *Integrasi Payment Gateway Midtrans Snap* Fase 1-3 **sudah selesai dan teruji** (10 November 2026): pesanan tersimpan di SQLite dan webhook Midtrans terverifikasi memakai `signature_secret_key` (pesanan uji berubah dari `pending` ke `paid` otomatis). Dokumen ini disesuaikan dengan kondisi tersebut.

## 1. Ringkasan

Saat ini, kalau customer menutup pop-up Snap sebelum membayar (atau memilih Virtual Account lalu pergi), pesanannya "hilang" dari sisi UI: keranjang sudah dikosongkan, dan customer tidak punya cara melihat atau melanjutkan pesanan itu. Fitur ini menambahkan **ikon pesanan belum dibayar di navbar** yang berisi:

1. Daftar pesanan yang masih menunggu pembayaran milik perangkat/browser tersebut.
2. Tombol **Lanjutkan bayar** (membuka kembali pop-up Snap).
3. Tombol **Batalkan** dengan **alasan pembatalan** yang dipilih customer.

PRD ini juga menjawab pertanyaan utama: **bagaimana mengenali bahwa pesanan berasal dari pengguna yang sama**. Rekomendasinya: **jangan memakai alamat IP**, pakai *device token* berbasis cookie dari server (bagian 3).

## 2. Masalah dan Tujuan

**Masalah**

- Customer yang menutup pop-up tidak bisa kembali ke pesanan yang sama kecuali memulai dari awal.
- Transaksi menggantung (status *pending*) menumpuk di dashboard Midtrans tanpa diketahui penyebabnya.
- Pemilik tidak tahu **mengapa** customer tidak jadi membeli.

**Tujuan**

1. Customer dapat melihat, melanjutkan, atau membatalkan pesanan yang belum dibayar dari navbar.
2. Pembatalan tercatat bersama alasannya, sehingga pemilik mendapat data umpan balik.
3. Pesanan hanya bisa dilihat dan dibatalkan oleh pemiliknya, bukan oleh orang lain.
4. Status di website selalu konsisten dengan status di Midtrans.

**Non-tujuan**

- Akun/login customer dan riwayat pesanan lengkap (hanya pesanan *belum dibayar*).
- Refund pesanan yang sudah dibayar.
- Mengubah isi pesanan (item/qty); customer cukup membatalkan lalu memesan ulang.
- Panel admin dan notifikasi email/WhatsApp.

## 3. Cara Mengenali Pengguna yang Sama

### 3.1 Mengapa alamat IP tidak disarankan

| Masalah | Dampak |
| --- | --- |
| **Satu IP dipakai banyak orang** (jaringan seluler dengan CGNAT, Wi-Fi kafe/kantor/kampus, hotspot) | Customer A bisa melihat dan membatalkan pesanan customer B. **Ini kebocoran data pribadi (nama, telepon, pesanan)** dan risiko keamanan. |
| **IP berubah-ubah** (ganti Wi-Fi ke data seluler, IP dinamis, IPv6 bergantian) | Pesanan milik customer yang sama "hilang" dari daftarnya. |
| **VPN/proxy** | Hasil deteksi tidak bisa diandalkan. |
| **Di balik reverse proxy/CDN** | `REMOTE_ADDR` berisi IP proxy; header `X-Forwarded-For` bisa dipalsukan. |

Kesimpulan: IP **tidak boleh** dipakai sebagai dasar otorisasi (siapa boleh melihat/membatalkan pesanan). IP hanya boleh dicatat (sebaiknya di-hash) untuk log penyalahgunaan dan pembatasan laju (*rate limit*).

### 3.2 Perbandingan metode

| Metode | Akurasi | Privasi/Keamanan | Kompleksitas | Catatan |
| --- | --- | --- | --- | --- |
| Alamat IP | Rendah | Buruk (bisa membocorkan pesanan ke orang lain) | Rendah | Tidak dipakai untuk otorisasi |
| **Device token (cookie dari server)** | Baik untuk satu browser | Baik (acak, tidak bisa ditebak) | Rendah | **Dipilih sebagai metode utama** |
| `localStorage` berisi daftar order ID | Baik, tapi bisa dimanipulasi klien | Cukup (server tetap harus verifikasi) | Rendah | Hanya pelengkap tampilan, bukan bukti kepemilikan |
| Cari pesanan: order ID + email/telepon | Tinggi | Baik jika dua faktor | Sedang | **Dipilih sebagai cadangan** (ganti perangkat/cookie terhapus) |
| Login/akun | Tertinggi | Baik | Tinggi | Di luar lingkup versi ini |
| *Browser fingerprinting* | Rapuh | Buruk (isu privasi) | Sedang | Tidak disarankan |

### 3.3 Rekomendasi: pendekatan hibrida

**A. Device token (utama)**

- Saat customer pertama kali membuat pesanan, server membuat token acak (minimal 128 bit, mis. dari `random_bytes(32)`) dan mengirimnya sebagai cookie: `HttpOnly`, `SameSite=Lax`, `Secure` (di HTTPS/production), masa berlaku 90 hari.
- Di database, yang disimpan adalah **hash token** (mis. SHA-256), bukan token mentah. Jika database bocor, token tidak bisa dipakai ulang.
- Setiap pesanan menyimpan `device_hash`. Daftar "belum dibayar" = pesanan dengan `device_hash` cocok dan status `pending`.
- Cookie bersifat `HttpOnly`, sehingga JavaScript di halaman tidak bisa membacanya (lebih aman dari XSS).

**B. Pencarian pesanan (cadangan)**

- Form kecil di panel: **Order ID + email atau nomor telepon yang dipakai saat memesan**. Jika cocok, server mengaitkan pesanan itu ke device token browser saat ini.
- Dibatasi laju (mis. 5 percobaan per 10 menit per perangkat/IP) agar tidak bisa ditebak.

**C. IP (hanya untuk log)**

- Opsional: simpan hash IP + *user agent* ringkas di log kejadian untuk investigasi penyalahgunaan. Tidak dipakai untuk menentukan kepemilikan pesanan.

**Keterbatasan yang diterima:** jika customer menghapus cookie, memakai browser/perangkat lain, atau mode *incognito* yang ditutup, pesanan tidak muncul otomatis. Solusinya pencarian pada poin B. Pesanan yang tak tersentuh akan kedaluwarsa otomatis (bagian 8.4).

## 4. Alur Pengguna

1. Customer checkout → pop-up Snap muncul → customer menutupnya tanpa membayar.
2. Di navbar muncul ikon **pesanan menunggu pembayaran** dengan badge jumlah pesanan.
3. Customer membuka panel → melihat daftar pesanan: kode, waktu dibuat, batas waktu bayar, ringkasan item, total.
4. **Lanjutkan bayar:** pop-up Snap terbuka lagi untuk pesanan yang sama.
5. **Batalkan:** muncul pilihan alasan → customer konfirmasi → pesanan dibatalkan, hilang dari daftar, toast konfirmasi tampil.
6. Jika customer membayar (di pop-up atau lewat VA), status berubah *paid* lewat webhook dan pesanan otomatis hilang dari daftar.

## 5. Kebutuhan Fungsional

| ID | Kebutuhan | Prioritas |
| --- | --- | --- |
| PO-1 | Server membuat dan mengelola **device token** (cookie `HttpOnly`) saat pesanan pertama dibuat, dan menyimpan hash-nya di setiap pesanan. | P0 |
| PO-2 | Endpoint `GET php/orders-pending.php` mengembalikan pesanan berstatus `pending` milik device token tersebut (tanpa data pesanan orang lain). | P0 |
| PO-3 | Ikon navbar + badge jumlah pesanan belum dibayar; badge hilang atau ikon tersembunyi jika 0 (lihat keputusan terbuka). | P0 |
| PO-4 | Panel daftar pesanan: kode pesanan, waktu dibuat, batas waktu bayar, ringkasan item, total, status. | P0 |
| PO-5 | Tombol **Lanjutkan bayar** memanggil `snap.pay(snap_token)` dengan token yang tersimpan; jika token sudah tidak berlaku, tampilkan pesan dan tawarkan membatalkan. | P0 |
| PO-6 | Tombol **Batalkan** membuka formulir alasan (daftar pilihan + kolom teks untuk "Lainnya") dengan langkah konfirmasi sebelum benar-benar membatalkan. | P0 |
| PO-7 | Endpoint `POST php/cancel-order.php` memverifikasi kepemilikan, memanggil API pembatalan Midtrans, lalu memperbarui database (status, alasan, waktu, pelaku). | P0 |
| PO-8 | Pesanan yang dibayar atau kedaluwarsa otomatis hilang dari daftar (polling ringan saat panel dibuka dan/atau saat halaman dimuat). | P1 |
| PO-9 | Pencarian pesanan cadangan (order ID + email/telepon) untuk menautkan pesanan ke perangkat saat ini. | P2 |
| PO-10 | Parameter `expiry` pada Snap dikirim saat membuat transaksi (mis. 120 menit) agar pesanan menggantung tidak berlaku selama default. | P1 |
| PO-11 | Hitung mundur sisa waktu bayar pada setiap pesanan. | P3 |

## 6. UI/UX

**Ikon dan panel**

- Tambahkan ikon baru di `.navbar-extra`, di samping ikon keranjang (gaya sama dengan ikon Feather yang dipakai, mis. `clock` atau `file-text`), dengan badge jumlah.
- Panel `.pending-orders` mengikuti pola `.shopping-cart`: muncul dari kanan dengan kelas `active`, tertutup saat klik di luar (perluas logika *click outside* di `js/script.js`), dan tidak saling tumpang tindih dengan panel keranjang (membuka satu menutup yang lain).
- Responsif: lebar panel mengikuti breakpoint yang ada (tablet dan ponsel).

**Isi tiap kartu pesanan**

- Kode pesanan (mis. `RLL-20261110-a1b2c3`), waktu dibuat, **berlaku sampai** (jam/tanggal).
- Ringkasan: "3 item", dapat diperluas untuk melihat nama dan jumlah.
- Total (format Rupiah yang sama dengan keranjang).
- Aksi: **Lanjutkan bayar** (primer) dan **Batalkan** (sekunder).

**Formulir pembatalan (inline atau modal)**

Pilihan alasan (satu pilihan wajib):

1. Salah memilih menu atau jumlah
2. Ingin mengubah pesanan
3. Ingin mengganti metode pembayaran
4. Tidak jadi memesan
5. Kendala saat pembayaran
6. Lainnya (kolom teks wajib, 5-200 karakter)

Tombol: **Ya, batalkan pesanan** dan **Kembali**. Kolom teks ditampilkan sebagai teks biasa (`x-text`/`textContent`), tidak pernah sebagai HTML.

**Keadaan khusus:** daftar kosong ("Tidak ada pesanan yang menunggu pembayaran"), memuat, error jaringan, dan pesan jika pesanan ternyata sudah dibayar saat akan dibatalkan.

## 7. Model Status

| Status | Arti | Muncul di daftar navbar |
| --- | --- | --- |
| `pending` | Transaksi dibuat, belum dibayar | Ya |
| `paid` | Midtrans melaporkan *settlement*/*capture* sukses | Tidak |
| `cancelled` | Dibatalkan customer (atau sistem) | Tidak |
| `expired` | Lewat batas waktu bayar | Tidak |
| `failed` | Ditolak/*deny* | Tidak |

Transisi yang diizinkan: `pending → paid | cancelled | expired | failed`. **Status `paid` tidak boleh berubah menjadi `cancelled`** (pesanan yang sudah dibayar membutuhkan proses refund, di luar lingkup).

Pemetaan notifikasi Midtrans yang dipakai: `settlement` atau `capture` sukses menjadi `paid`; `pending` tetap `pending`; `cancel` menjadi `cancelled`; `deny` atau `failure` menjadi `failed`; `expire` menjadi `expired`. Webhook sudah berjalan (Fase 3), sehingga pesanan yang dibayar atau kedaluwarsa otomatis hilang dari daftar navbar.

## 8. Rancangan Teknis

### 8.1 Perubahan database (menambah tabel `orders` dari Fase 3)

Database memakai **SQLite** (`get_db()` di `php/orders.php`, migrasi idempoten), bukan MySQL. Kolom `CHECK` pada `status` tidak bisa diubah dengan `ALTER`, sehingga tabel `orders` perlu dibangun ulang dalam satu transaksi agar menerima nilai `cancelled`.

| Kolom | Keterangan | Status |
| --- | --- | --- |
| `status` | Tambahkan nilai `cancelled` pada `CHECK` | Ubah |
| `snap_token` | Token Snap untuk Lanjutkan bayar | Sudah ada dari Fase 3 (verifikasi di kode) |
| `device_hash` | Hash SHA-256 dari device token, diindeks bersama `status` | Baru |
| `expires_at` | Batas waktu bayar | Baru (periksa dulu apakah Fase 3 sudah menyimpannya) |
| `cancel_reason_code` | Salah satu kode alasan baku | Baru |
| `cancel_reason_text` | Teks alasan untuk "Lainnya" (maks 200 karakter) | Baru |
| `cancelled_at`, `cancelled_by` | Waktu dan pelaku (`customer` atau `system`) | Baru |
| `ip_hash`, `user_agent` | Hanya untuk log penyalahgunaan | Opsional |

### 8.2 Endpoint

| Endpoint | Fungsi |
| --- | --- |
| `GET php/orders-pending.php` | Daftar pesanan `pending` milik device token (item, total, `expires_at`, `snap_token`) |
| `POST php/cancel-order.php` | Body: `{ "order_id", "reason_code", "reason_text" }`; verifikasi kepemilikan → batalkan → simpan alasan |
| `POST php/order-lookup.php` (P2) | Body: `{ "order_id", "contact" }`; jika cocok, menautkan pesanan ke device token saat ini |
| `php/place-order.php` (ubah) | Membuat/membaca device token, menyimpan `snap_token`, `expires_at`, `device_hash`, menambah `expiry` ke parameter Snap |

Respons gagal memakai pola yang sama dengan `place-order.php`: `{ "error": "pesan singkat" }` dengan status 400/403/404/409/500.

### 8.3 Interaksi dengan Midtrans

- **Membatalkan:** gunakan Cancel API (`POST {BASE_URL}/v2/{order_id}/cancel`, sandbox di `api.sandbox.midtrans.com`). Hanya bisa sebelum *settlement*, untuk transaksi *pending* seperti VA, GoPay/QRIS, dan toko. Buat helper cURL di `php/midtrans.php` dengan pola yang sama seperti `fetch_midtrans_status()`.
- **Perhatikan khusus Snap:** transaksi Snap yang metode bayarnya belum dipilih mungkin belum punya status di Core API, sehingga Cancel API bisa mengembalikan 404. Jika Midtrans menjawab transaksi tidak ditemukan, tetap tandai `cancelled` di database lokal dan jangan lagi menawarkan Lanjutkan bayar. Uji di sandbox; dokumentasi Midtrans juga memuat opsi *Cancel/Expire a Snap Session*.
- **Balapan:** customer menekan Batalkan saat pembayaran masuk. Cek status ke Midtrans dulu; jika sudah *settlement*, tampilkan "Pesanan sudah dibayar" dan perbarui menjadi `paid`. Webhook tidak boleh menurunkan `paid` menjadi `cancelled` (aturan ini sudah ditegakkan `update_order_status()`).
- **Izin Server Key:** key `resto-laut-lestari` sudah terbukti bisa **Snap Checkout = Write** dan **Core API = Read** (status API membalas 200 pada dry-run reconcile). Fitur ini membutuhkan tambahan **Cancel transaction = Write**: ubah izin key yang ada jika menu edit tersedia, atau buat key baru lalu perbarui `server_key` di `php/config.php`. `signature_secret_key` tidak berubah.
- **Verifikasi webhook:** memakai `signature_secret_key` (Settings > Access Keys > Signature Secret Key), bukan Server Key. Jangan mengganti Signature Secret Key (Regenerate) tanpa memperbarui `config.php`.
- **Melanjutkan bayar:** `snap.pay(snap_token)` dapat dipakai ulang selama transaksi belum kedaluwarsa atau dibatalkan. Uji di sandbox, termasuk kasus setelah VA dipilih.

### 8.4 Kedaluwarsa

- Kirim `expiry` pada parameter Snap (mis. `{ "unit": "minutes", "duration": 120 }`), dan simpan `expires_at`.
- Pesanan yang melewati `expires_at` ditandai `expired` secara *lazy* saat daftar dibaca, dan diperkuat oleh webhook `expire` dari Midtrans.

### 8.5 Keamanan

- **Otorisasi berdasarkan device token, bukan IP.** Setiap endpoint memfilter berdasarkan `device_hash`; permintaan untuk pesanan milik perangkat lain dijawab `404` (bukan `403`) agar keberadaan pesanan tidak bocor.
- **CSRF:** cookie `SameSite=Lax` + wajib `Content-Type: application/json` + header kustom (mis. `X-Requested-With`) pada `POST`.
- **Rate limit** pada `cancel-order` dan `order-lookup`.
- **XSS:** teks alasan di-escape saat disimpan/ditampilkan; tampilkan memakai `x-text`.
- **Data pribadi:** hanya kirim data minimum ke klien (tanpa email/telepon penuh; mask bila perlu). Pertimbangkan pemberitahuan privasi singkat tentang cookie fungsional ini.
- Server Key tetap hanya di server; `snap_token` dikirim ke klien hanya untuk pesanan milik perangkat tersebut.

## 9. Kriteria Penerimaan

- [ ] Setelah checkout lalu menutup pop-up, ikon navbar menampilkan badge 1 dan pesanan muncul di panel.
- [ ] Browser/perangkat lain **tidak** melihat pesanan itu (uji dengan dua browser berbeda pada jaringan yang sama).
- [ ] **Lanjutkan bayar** membuka pop-up Snap dengan item dan total yang sama.
- [ ] Batalkan dengan alasan "Salah memilih menu" → pesanan hilang dari daftar; di database `status=cancelled`, `cancel_reason_code` terisi; di dashboard Midtrans transaksi berstatus *cancel* (atau tercatat kasus 404 Snap yang ditangani).
- [ ] Alasan "Lainnya" tanpa teks atau teks < 5 karakter ditolak.
- [ ] Pesanan yang sudah dibayar (simulator) hilang dari daftar dan tidak bisa dibatalkan (pesan jelas).
- [ ] Memanggil `cancel-order.php` untuk `order_id` milik perangkat lain menghasilkan 404.
- [ ] Pesanan yang lewat `expires_at` otomatis hilang dari daftar dan tidak bisa dilanjutkan.
- [ ] Menghapus cookie menyembunyikan daftar; pencarian order ID + email/telepon (P2) mengembalikannya.

## 10. Rencana Pengujian

| Skenario | Hasil yang diharapkan |
| --- | --- |
| Checkout lalu tutup pop-up | Pesanan muncul di panel |
| Pilih VA lalu tutup pop-up | Pesanan muncul; Lanjutkan bayar menampilkan nomor VA yang sama |
| Batalkan pesanan VA | Status *cancel* di Midtrans; hilang dari daftar |
| Bayar VA lewat simulator saat panel terbuka | Status berubah ke `paid`; pesanan hilang dari daftar |
| Batalkan pesanan yang sudah dibayar | Ditolak, pesan "sudah dibayar" |
| Dua browser, satu jaringan | Masing-masing hanya melihat pesanannya sendiri |
| Ubah `order_id` di DevTools | 404 |
| Kirim alasan berisi tag HTML | Tampil sebagai teks biasa |
| Pesanan kedaluwarsa | Hilang dari daftar |
| Cookie dihapus | Daftar kosong; pencarian cadangan berfungsi |

## 11. Keputusan Terbuka

Sudah diputuskan: penyimpanan pesanan memakai SQLite (Fase 3 selesai), sehingga opsi MySQL dihapus dari daftar.

1. **Metode identifikasi:** device token + pencarian cadangan (usulan) atau device token saja untuk MVP.
2. **Ikon navbar:** selalu tampil atau hanya saat ada pesanan belum dibayar? *Usulan: hanya tampil jika ≥ 1.*
3. **Masa berlaku pembayaran:** 2 jam, 24 jam (default Snap), atau nilai lain. *Usulan: 2 jam untuk restoran.*
4. **Daftar alasan:** apakah enam pilihan cukup, atau disesuaikan dengan kebiasaan pelanggan?
5. **Kebijakan pesanan baru:** boleh lebih dari satu pesanan pending, atau pesanan lama otomatis dibatalkan saat checkout baru? *Usulan: boleh banyak, batas 3 per perangkat.*
6. **Izin pembatalan pada key:** ubah izin key `resto-laut-lestari` (jika bisa) atau buat key baru dengan Cancel transaction = Write.

## 12. Risiko

| Risiko | Mitigasi |
| --- | --- |
| Pesanan pending milik orang lain terlihat jika identifikasi lemah | Otorisasi hanya dengan device token acak; jangan pakai IP |
| Cookie terhapus sehingga pesanan "hilang" | Pencarian cadangan; kedaluwarsa otomatis |
| Status lokal berbeda dari status Midtrans | Webhook sebagai sumber kebenaran; cek ulang sebelum membatalkan |
| Cancel API menolak untuk transaksi Snap tanpa metode bayar | Penanganan khusus (bagian 8.3), uji di sandbox |
| Server Key tanpa izin Cancel | Buat key baru dengan Cancel transaction = Write |
| Penyalahgunaan endpoint (menebak order ID) | Otorisasi device token, rate limit, order ID acak |

## 13. Rencana Implementasi

| Tahap | Isi | Status |
| --- | --- | --- |
| **Fase 3 (payment gateway)** | SQLite, webhook terverifikasi, reconcile, tes otomatis | Selesai dan teruji |
| **A. Fondasi** | Migrasi `cancelled` + kolom baru, pemetaan `cancel` ke `cancelled`, device token dan `expiry` Snap di `place-order.php` | Siap dikerjakan |
| **B. Daftar** | `orders-pending.php`, ikon navbar, badge, panel, Lanjutkan bayar | Setelah A |
| **C. Pembatalan** | Izin Server Key, `cancel-order.php`, formulir alasan, penanganan balapan dan 404 Snap | Setelah B |
| **D. Penyempurnaan** | Kedaluwarsa otomatis, hitung mundur, pencarian cadangan, batas pesanan per perangkat | Setelah C |

## 14. Instruksi untuk Agent Coding

Tempel **Aturan umum** bersama instruksi satu tahap per sesi. Selesaikan dan uji satu tahap sebelum lanjut ke tahap berikutnya.

**Aturan umum (semua tahap)**

```
Konteks: proyek resto-laut-lestari (HTML/CSS/Alpine.js + PHP). Fase 1-3 payment gateway sudah selesai
(php/place-order.php, php/notification.php, php/orders.php SQLite, php/midtrans.php, router.php, tests/run.php).
Aturan:
- Baca PRD 'Pesanan Belum Dibayar di Navbar' dan kode yang ada sebelum mengubah; tampilkan rencana singkat dan tunggu persetujuan.
- Jangan membaca, mencetak, atau menulis ke log nilai server_key, signature_secret_key, snap_token, atau cookie. Tes memakai key palsu dan DB sementara (RLL_ENV=test, RLL_CONFIG_PATH, RLL_DB_PATH).
- Pakai C:\xampp\php\php.exe (ada pdo_sqlite dan curl). Jalankan php -l pada file yang berubah dan php tests/run.php; tambah tes untuk setiap perilaku baru.
- Semua query memakai prepared statement; respons error generik; endpoint baru diatur di router.php dan php/.htaccess.
- Fetch klien selalu same-origin dan relatif (php/...). Jangan commit; laporkan file yang berubah dan hasil tes.
```

**Tahap A: Fondasi** (PRD bagian 7, 8.1, 8.4)

```
1. Migrasi idempoten di php/orders.php (get_db): bangun ulang tabel orders dalam satu transaksi (orders_new, salin, drop, rename;
   foreign_keys dimatikan lalu dinyalakan lagi) agar CHECK status menerima 'cancelled'. Tambah kolom nullable: device_hash, expires_at,
   cancel_reason_code, cancel_reason_text, cancelled_at, cancelled_by (snap_token sudah ada, jangan diduplikasi). Index (device_hash, status). Data lama utuh.
2. map_transaction_status: 'cancel' menjadi 'cancelled'; 'deny' dan 'failure' menjadi 'failed'. update_order_status tetap hanya mengubah status 'pending';
   paid dan status terminal lain tidak berubah.
3. place-order.php: buat atau baca cookie rll_device (random_bytes(32) hex, HttpOnly, SameSite=Lax, 90 hari, Secure hanya jika HTTPS).
   Simpan SHA-256-nya di device_hash. Kirim 'expiry' ke Snap (config order_expiry_minutes, default 120) dan simpan expires_at.
4. Tes: migrasi pada DB lama, pemetaan status, atribut cookie, device_hash tersimpan sebagai hash (bukan token mentah).
```

**Tahap B: Daftar pesanan belum dibayar** (PRD bagian 5 PO-1 sampai PO-5, bagian 6)

```
1. php/orders-pending.php (GET): kembalikan pesanan berstatus 'pending' milik device_hash dari cookie (tanpa cookie: daftar kosong dan jangan membuat cookie).
   Tandai 'expired' secara lazy jika expires_at lewat. Kembalikan order_id, created_at, expires_at, total, ringkasan item (nama dan qty), snap_token.
   Jangan kirim email atau telepon penuh.
2. UI: ikon baru di .navbar-extra (gaya sama dengan ikon keranjang) + badge jumlah; panel .pending-orders mengikuti pola .shopping-cart
   (kelas 'active', klik di luar menutup, membuka satu panel menutup yang lain). Kartu: kode pesanan, dibuat, berlaku sampai, ringkasan item, total,
   tombol 'Lanjutkan bayar' (snap.pay dengan snap_token). Keadaan kosong, memuat, error. Teks ditampilkan dengan x-text.
3. Muat ulang daftar saat halaman dibuka, setelah pop-up Snap ditutup, dan saat panel dibuka.
4. Tes: pesanan perangkat lain tidak muncul (dua cookie berbeda), paid dan expired tidak muncul, tanpa cookie daftar kosong.
```

**Tahap C: Pembatalan** (PRD bagian 6, 8.2, 8.3, 8.5)

```
Prasyarat manual (pemilik): Server Key punya izin Cancel transaction = Write.
1. php/midtrans.php: cancel_midtrans_transaction(order_id, server_key, is_production) dengan pola fetch_midtrans_status
   (cURL, Basic Auth, timeout, TLS verify, hasil terstruktur ok/http_status/error).
2. php/cancel-order.php (POST JSON {order_id, reason_code, reason_text}): wajib header X-Requested-With dan Content-Type JSON;
   verifikasi kepemilikan via device_hash (bukan milik perangkat ini: 404); hanya status 'pending'; reason_code dari daftar baku;
   'lainnya' wajib teks 5-200 karakter; rate limit.
3. Alur: cek status Midtrans lebih dulu; jika sudah settlement atau capture sukses, update ke 'paid' dan balas 409 'sudah dibayar'.
   Jika pending, panggil Cancel API. Jika Midtrans menjawab 404 (Snap tanpa metode bayar), tetap tandai 'cancelled' lokal.
   Simpan cancel_reason_code/text, cancelled_at, cancelled_by='customer' lewat update_order_status (paid tidak pernah menjadi cancelled).
   Error 401/403 dari Midtrans: 500 generik + log 'izin key kurang (Cancel transaction: Write?)'.
4. UI: tombol 'Batalkan' membuka formulir alasan (enam pilihan, 'Lainnya' menampilkan kolom teks), konfirmasi 'Ya, batalkan pesanan' dan 'Kembali',
   toast hasil, kartu hilang dari daftar.
5. Tes: pemilik vs bukan pemilik, alasan tidak valid, pesanan sudah paid (409), Midtrans 404 ditangani, XSS pada reason_text tidak dieksekusi.
```

**Tahap D: Penyempurnaan** (PRD bagian 5 PO-8 sampai PO-11, 8.4)

```
1. Kedaluwarsa otomatis: webhook 'expire' menjadi 'expired' (sudah ada) ditambah lazy expire saat daftar dibaca.
2. Hitung mundur sisa waktu bayar di kartu (JS ringan, tanpa polling cepat); muat ulang daftar saat hitung mundur habis.
3. php/order-lookup.php (POST {order_id, contact}): cocokkan order_id dengan email ATAU telepon yang tersimpan; jika cocok, set device_hash
   pesanan itu ke perangkat ini. Rate limit ketat (mis. 5 percobaan per 10 menit per perangkat); respons seragam untuk 'tidak ada' dan 'tidak cocok'.
   Tambah form kecil di panel.
4. Batas pesanan pending per perangkat (config max_pending_per_device, default 3) di place-order.php.
5. Tes: percobaan lookup salah berulang kena rate limit, respons seragam, kedaluwarsa menghilangkan kartu.
```
