# PRD: Integrasi Payment Gateway Midtrans Snap

**Proyek:** Resto Laut Lestari · **Versi:** 1.1 (Fase 1-3 selesai) · **Tanggal:** 10 November 2026 · **Pemilik:** Masyhabi

## 1. Ringkasan

Website Resto Laut Lestari sudah punya katalog menu, menu bundling, keranjang belanja (Alpine.js), dan form data customer dengan validasi. Saat ini tombol **Checkout** hanya mencetak data form ke console. PRD ini mendefinisikan lanjutan proyek: menghubungkan Checkout ke **Midtrans Snap (mode pop-up, sandbox)** sehingga customer bisa membayar langsung dari website, dan pemilik bisa melihat siapa yang sudah/belum bayar di dashboard Midtrans.

Referensi: tutorial YouTube (WPU) "payment gateway" dengan Midtrans. Alur tutorial diikuti, tetapi dengan beberapa perbaikan keamanan dan penyesuaian ke struktur proyek ini (lihat bagian 8 dan 12).

**Status (10 November 2026):** Fase 1-3 selesai dan teruji di sandbox: checkout, pembayaran QRIS/GoPay lewat simulator, dan webhook yang mengubah pesanan dari `pending` ke `paid`. Fase 4 (WhatsApp) belum dikerjakan. Dokumen ini disesuaikan dengan implementasi; lihat Riwayat Perubahan di bagian 15.

## 2. Kondisi Saat Ini (per 10 November 2026)

| Area | Status | Lokasi |
| --- | --- | --- |
| Keranjang belanja (add, remove, qty, total) | Selesai | `src/app.js` (`Alpine.store("cart")`) |
| Menu bundling (6 paket) | Selesai | `src/app.js`; harga server di `php/products.php` |
| Form customer dan validasi | Selesai | `index.html`, `src/app.js` |
| Checkout: fetch ke server, `snap.pay`, callback, toast status | Selesai | `src/app.js`, `index.html` |
| Backend token Snap (validasi, harga dari server, simpan pesanan) | Selesai | `php/place-order.php`, `php/products.php`, `php/midtrans.php` |
| Penyimpanan pesanan (SQLite) | Selesai | `php/orders.php` |
| Webhook notifikasi dan verifikasi signature | Selesai | `php/notification.php` |
| Pemeriksaan pesanan pending (CLI) | Selesai | `php/reconcile.php` |
| Tes otomatis | Selesai | `tests/` (`php tests/run.php`) |
| Pesan WhatsApp otomatis (FR-12) | Belum | - |

Struktur proyek saat ini: `css/`, `js/`, `src/`, `img/`, `index.html`, `router.php`, `php/`, `tests/` (rinci di bagian 8.2).

## 3. Tujuan dan Non-Tujuan

**Tujuan**

1. Customer dapat checkout dan membayar lewat pop-up Midtrans Snap (sandbox) tanpa meninggalkan website.
2. Data customer dan rincian item otomatis tercatat di dashboard Midtrans (menu *Transactions*).
3. Harga yang dibayar selalu dihitung ulang di server, bukan dipercaya dari browser.
4. Setelah pembayaran, UI memberi umpan balik jelas dan keranjang dibersihkan.

**Non-tujuan (di luar lingkup versi ini)**

- Mode production / uang sungguhan (hanya disiapkan, tidak diaktifkan).
- Login/akun customer, riwayat pesanan, panel admin.
- Ongkir, kode promo, stok, pajak/biaya layanan.
- Snap Embedded (tutorial memilih Snap Pop-up agar layout tidak berubah).
- Pembuatan Payment Link manual (bagian awal tutorial, tanpa kode).

## 4. Pengguna dan Alur Utama

**Persona:** (a) Customer: pengunjung yang memesan paket dan membayar online. (b) Pemilik/admin: memantau status pembayaran di dashboard Midtrans.

**Alur customer**

1. Customer menambah paket ke keranjang → badge qty bertambah.
2. Membuka keranjang, mengisi Name, Email, Phone → tombol Checkout aktif.
3. Klik Checkout → tombol berubah "Memproses..." dan nonaktif.
4. Server membuat transaksi → browser menerima token → pop-up Snap muncul.
5. Customer memilih metode bayar (GoPay QRIS, Virtual Account, kartu, dll.) dan membayar.
6. Pop-up menutup → muncul pesan status (sukses / menunggu pembayaran / gagal) → jika sukses, keranjang dan form dikosongkan.

## 5. Fase dan Prioritas

| Fase | Isi | Prioritas | Status |
| --- | --- | --- | --- |
| **Fase 1 (MVP)** | Backend token Snap + pop-up Snap berjalan di sandbox | Wajib | Selesai |
| **Fase 2** | Callback UI (success/pending/error/close), reset keranjang, penanganan error, anti-klik ganda | Wajib | Selesai |
| **Fase 3** | Webhook notifikasi Midtrans + penyimpanan pesanan + verifikasi signature | Disarankan | Selesai dan teruji (pesanan uji berubah `pending` ke `paid` lewat webhook asli) |
| **Fase 4 (opsional)** | Pesan WhatsApp otomatis ke penjual (`wa.me`), reuse token jika popup ditutup | Opsional | Belum dikerjakan |

## 6. Kebutuhan Fungsional

| ID | Kebutuhan | Prioritas |
| --- | --- | --- |
| FR-1 | Saat form di-submit, browser mengirim `POST` JSON ke `php/place-order.php` berisi data customer dan daftar item **hanya `id` dan `qty`**. | P0 |
| FR-2 | Server memvalidasi input: name, email (format valid), phone tidak kosong; `items` tidak kosong; setiap `id` ada di katalog server; `qty` bilangan bulat ≥ 1 (batas atas, mis. 50). | P0 |
| FR-3 | Server mengambil `price` dan `name` dari **katalog server**, menghitung `gross_amount` = Σ(price × qty), dan membuat `order_id` unik (mis. `RLL-20261110-xxxxxx`, maks 50 karakter). | P0 |
| FR-4 | Server memanggil Midtrans Snap API dengan `transaction_details`, `customer_details` (`first_name`, `email`, `phone`), dan `item_details` (`id`, `price`, `quantity`, `name`), lalu mengembalikan `{ token, order_id }`. | P0 |
| FR-5 | Browser memuat `snap.js` sandbox dengan `data-client-key` dan memanggil `window.snap.pay(token, callbacks)`. | P0 |
| FR-6 | Tombol Checkout nonaktif dan berlabel "Memproses..." selama request berjalan, aktif kembali jika gagal. | P1 |
| FR-7 | `onSuccess`: tutup panel keranjang, kosongkan store cart (`items`, `qty`, `totalHarga`), reset form, tampilkan pesan terima kasih + `order_id`. | P1 |
| FR-8 | `onPending`: tampilkan pesan "Menunggu pembayaran" + `order_id`; keranjang tidak dikosongkan atau dikosongkan sesuai keputusan di bagian 10. | P1 |
| FR-9 | `onError` / kegagalan request: tampilkan pesan ramah; data keranjang tetap utuh. `onClose`: kembali ke keranjang tanpa kehilangan data. | P1 |
| FR-10 | Endpoint `php/notification.php` menerima notifikasi Midtrans, memverifikasi `signature_key`, dan memperbarui status pesanan (`pending`, `paid`, `failed`, `expired`). | P2 |
| FR-11 | Pesanan disimpan (tabel `orders` dan `order_items`) saat token dibuat, status awal `pending`. | P2 |
| FR-12 | (Opsional) Setelah checkout, buka `wa.me/62...` berisi ringkasan pesanan (nama, email, HP, item, total) memakai `encodeURIComponent`. | P3 |

## 7. Kebutuhan Non-Fungsional dan Keamanan

- **Jangan percaya harga dari browser.** Hidden input `items` yang berisi `price` dan `totalHarga` tidak boleh dipakai server untuk menghitung tagihan. Ini perbedaan terpenting dari tutorial, yang menerima `total` dari `$_POST`.
- **Server Key hanya di server**, disimpan di file konfigurasi di luar webroot atau `.env`, dan masuk `.gitignore`. **Client Key** boleh ada di HTML.
- Respons error tidak membocorkan detail internal/Server Key; log error ditulis ke file.
- Hanya metode `POST` dan `Content-Type: application/json` yang diterima; selain itu `405/415`.
- Sandbox dan production dipisah lewat satu flag konfigurasi (`isProduction`), bukan ubah kode di banyak tempat.
- Kompatibel dengan layout responsif yang ada; pop-up Snap tidak boleh merusak panel keranjang.
- Semua harga integer Rupiah (tanpa desimal), sesuai data saat ini.

**Kunci dan izin yang dipakai:** `server_key` untuk Basic Auth ke API Midtrans (Snap dan status API); `signature_secret_key` hanya untuk verifikasi webhook; client key di HTML. Izin Server Key yang terbukti dibutuhkan: **Snap Checkout = Write** dan **Core API = Read**. Semua secret hanya di `php/config.php` (tidak di-commit); `RLL_CONFIG_PATH` hanya dihormati saat `RLL_ENV=test`.

## 8. Rancangan Teknis

### 8.1 Arsitektur

```
Browser (Alpine + app.js)
  │ 1. POST /php/place-order.php  {customer, items:[{id,qty}]}
  ▼
Server PHP
  │ 2. validasi → hitung total dari katalog server
  │ 3. Midtrans Snap API (getSnapToken)
  ▼
Browser ← {token, order_id}
  │ 4. snap.pay(token, callbacks) → pop-up Midtrans
  ▼
Midtrans ──(Fase 3: HTTP notification)──► /php/notification.php
```

### 8.2 Struktur file (aktual)

```
resto-laut-lestari/
├─ css/style.css
├─ js/script.js
├─ src/app.js                (checkout: fetch + snap.pay + callback)
├─ index.html                (snap.js sandbox, toast status)
├─ router.php                (router php -S: memblokir berkas sensitif)
├─ php/
│  ├─ place-order.php        (validasi, harga dari katalog server, simpan pesanan, token Snap)
│  ├─ notification.php       (webhook Midtrans)
│  ├─ orders.php             (SQLite, migrasi, status, verifikasi signature)
│  ├─ midtrans.php           (klien cURL: Snap dan status API)
│  ├─ products.php           (katalog harga server)
│  ├─ reconcile.php          (CLI: periksa pesanan pending)
│  ├─ config-loader.php
│  ├─ config.example.php     (config.php lokal tidak di-commit)
│  ├─ storage/               (database SQLite, diblokir dari web)
│  └─ .htaccess
├─ tests/                    (run.php, unit-signature.php, http-webhook.php)
└─ .gitignore                (config.php, *.sqlite*, *.log)
```

**Klien Midtrans:** memakai cURL langsung dengan Basic Auth (`php/midtrans.php`: pembuatan transaksi Snap dan Get Status API, dengan timeout dan verifikasi TLS), bukan library resmi `midtrans-php`, sehingga tanpa Composer atau unduhan manual. Lokasi database default `php/storage/orders.sqlite`, dapat diganti lewat `RLL_DB_PATH`; untuk production pindahkan ke luar webroot.

### 8.3 Kontrak API

`POST php/place-order.php`

Request:

```json
{
  "customer": { "name": "Budi", "email": "budi@mail.com", "phone": "081234567890" },
  "items": [ { "id": 1, "qty": 2 }, { "id": 6, "qty": 1 } ]
}
```

Response sukses (`200`):

```json
{ "token": "<snap-transaction-token>", "order_id": "RLL-20261110-a1b2c3" }
```

Response gagal: `400` (input tidak valid), `500` (gagal menghubungi Midtrans), body `{ "error": "pesan singkat" }`.

### 8.4 Pemetaan data keranjang → Midtrans

| Cart item (`app.js`) | Midtrans `item_details` | Catatan |
| --- | --- | --- |
| `id` | `id` | cocokkan ke katalog server |
| `name` | `name` | diambil dari katalog server; maks 50 karakter |
| `price` | `price` | diambil dari katalog server |
| `qty` | `quantity` | **nama berbeda**, harus dipetakan |
| `totalHarga` (subtotal) | - | jangan dikirim; server menghitung sendiri |

`gross_amount` **harus sama persis** dengan jumlah semua `price × quantity`, kalau tidak Midtrans menolak transaksi.

### 8.5 Perubahan di sisi klien

1. **`index.html`:** `snap.js` sandbox dengan `data-client-key`, elemen toast status; field hidden `items` dan `totalHarga` dihapus.
2. **`src/app.js`:** fungsi checkout `async` mengirim hanya `id` dan `qty` beserta data customer ke `php/place-order.php`, lalu memanggil `snap.pay` dengan callback `onSuccess`, `onPending`, `onError`, `onClose`; anti-klik ganda; keranjang dan form direset saat sukses atau pending.
3. **Modal detail:** tombol add-to-cart di enam modal sudah terhubung ke keranjang.
4. **Perbaikan kecil:** `pad` menjadi `padding`, `font-weight: 100px` menjadi `300`, `for` label disamakan dengan `id` input.

### 8.6 Lingkungan pengembangan

Karena memakai PHP, proyek dijalankan lewat server PHP, **bukan Live Server VS Code**. Perintah yang dipakai: `& C:\xampp\php\php.exe -S 127.0.0.1:8000 router.php` (PHP XAMPP memiliki `pdo_sqlite` dan `curl`; PHP lain di PATH mungkin tidak). `router.php` memblokir berkas sensitif karena `php -S` mengabaikan `.htaccess`. Laragon atau XAMPP Apache tetap bisa dipakai.

Webhook Midtrans butuh alamat publik: jalankan `cloudflared tunnel --url http://127.0.0.1:8000`, isi **Payment notification URL** di dashboard Sandbox dengan `https://<alamat-tunnel>/php/notification.php`, lalu matikan tunnel dan kosongkan URL setelah selesai (alamat tunnel berubah setiap dijalankan). Request `fetch("php/place-order.php")` bersifat *same-origin* sehingga tidak butuh CORS.

### 8.7 Skema data dan webhook (Fase 3, terimplementasi)

- `orders`: `order_id` (PK), `customer_name`, `customer_email`, `customer_phone`, `gross_amount`, `status`, `payment_type`, `snap_token`, `created_at`, `updated_at`. Nilai `status`: `pending`, `paid`, `failed`, `expired`.
- `order_items`: `id`, `order_id` (FK), `product_id`, `name`, `price`, `qty`.
- `rate_limits`: dipakai oleh fallback verifikasi webhook.
- Pengaturan SQLite: `foreign_keys=ON`, `journal_mode=WAL`, `busy_timeout=5000`.

**Webhook (`php/notification.php`):** urutan format `order_id`, verifikasi signature, lalu cek pesanan. `signature_key` = `sha512(order_id + status_code + gross_amount + signature_secret_key)` memakai string persis dari payload (mis. `"76000.00"`). Kuncinya adalah **Signature Secret Key** (Settings > Access Keys), **bukan Server Key**; ini dibuktikan lewat tes (signature yang dihitung dengan Server Key ditolak). Pemetaan status: `settlement` atau `capture` sukses menjadi `paid`, `pending` tetap `pending`, `deny`/`cancel`/`failure` menjadi `failed`, `expire` menjadi `expired`. Hanya status `pending` yang bisa berubah; `paid` tidak pernah turun.

Fallback verifikasi lewat Get Status API (`webhook_status_fallback`) **nonaktif secara default**; bila aktif, respons seragam dan ada batas laju. Pesanan yang notifikasinya terlewat dapat diperiksa dengan `php php/reconcile.php --dry-run`. Untuk pengujian lokal, URL notifikasi di dashboard Midtrans (sandbox) harus publik (tunnel).

## 9. Kriteria Penerimaan

**Fase 1**

- **Lulus:** `POST php/place-order.php` dengan payload valid mengembalikan `token` dan `order_id` (termasuk tes pembuatan token Snap asli).
- **Lulus:** klik Checkout menampilkan pop-up Snap dan pembayaran lewat simulator berhasil.
- **Lulus:** transaksi muncul di dashboard Midtrans Sandbox lengkap dengan data customer dan item.
- **Lulus (tes otomatis):** tagihan memakai harga katalog server walau `price` dimanipulasi dari klien.

**Fase 2**

- **Lulus (tes klien otomatis):** bayar sukses menampilkan pesan sukses, mengosongkan keranjang, dan mereset form.
- **Lulus (tes klien otomatis):** menutup pop-up tanpa bayar membiarkan keranjang utuh.
- **Terimplementasi, belum dikonfirmasi manual:** anti-klik ganda pada tombol Checkout.
- **Lulus:** kegagalan server menampilkan pesan ramah, bukan error mentah.

**Fase 3**

- **Lulus:** notifikasi `settlement` mengubah status pesanan menjadi `paid` (pesanan uji berubah `pending` ke `paid` lewat webhook asli).
- **Lulus (tes otomatis):** notifikasi dengan signature salah, termasuk yang dihitung dengan Server Key, ditolak `403`.

## 10. Keputusan Terbuka

1. **Bahasa backend:** terselesaikan, PHP.
2. **Status `pending`:** terselesaikan, keranjang dikosongkan setelah `onPending` karena pesanan sudah tercatat di Midtrans dan database.
3. **Penyimpanan pesanan:** terselesaikan, SQLite.
4. **Katalog server:** terselesaikan, duplikasi di `php/products.php`; harga di `src/app.js` harus tetap sinkron.
5. **Metode pembayaran:** terselesaikan, semua metode Snap tampil di sandbox.
6. **Hosting production:** masih terbuka; dibutuhkan PHP, HTTPS, dan folder tulis untuk SQLite di luar webroot.

## 11. Rencana Pengujian (Sandbox)

| Skenario | Cara | Hasil yang diharapkan |
| --- | --- | --- |
| Alur normal | Pilih 3 paket, isi form, bayar GoPay lewat simulator Midtrans | Status *settlement*; UI sukses |
| Form kosong | Kosongkan satu field | Tombol Checkout abu-abu dan tidak bisa diklik |
| Keranjang kosong | Buka keranjang tanpa item | Form tidak tampil |
| Manipulasi harga | Ubah `price` di DevTools lalu checkout | Tagihan tetap memakai harga katalog server |
| `id` tidak dikenal | Kirim `id: 999` via DevTools/curl | `400` dengan pesan error |
| Tutup pop-up | Klik silang pada Snap | Keranjang tetap, bisa checkout lagi |
| Server key salah | Ubah sementara di `config.php` | Pesan error ramah, tidak membocorkan key |
| Webhook (Fase 3) | Bayar via simulator dengan tunnel aktif | Status pesanan menjadi `paid` |

## 12. Risiko dan Catatan Temuan pada Kode Saat Ini

**Risiko**

- Secret bocor jika ikut ter-commit (mitigasi: `php/config.php` di `.gitignore`; ganti key jika sempat bocor).
- `gross_amount` tidak cocok dengan `item_details` membuat transaksi ditolak (mitigasi: total dihitung di server dari item yang sama).
- Status pembayaran tidak boleh bergantung pada callback browser: **webhook (Fase 3) adalah sumber kebenaran**, dan `reconcile.php` memeriksa pesanan yang notifikasinya terlewat.
- Key salah jenis (Production vs Sandbox) atau Signature Secret Key di-*Regenerate* tanpa memperbarui `config.php` membuat semua webhook ditolak `403`.
- Akun Midtrans masih tahap review: sandbox tetap bisa dipakai, production menunggu persetujuan.

**Temuan kecil pada kode (perbaiki sebelum/selama Fase 1)**

1. **Sudah diperbaiki:** `css/style.css` `pad: 0` menjadi `padding: 0`.
2. **Sudah diperbaiki:** `for` pada label form disamakan dengan `id` input.
3. **Sudah diperbaiki:** tombol add-to-cart di enam modal detail terhubung ke keranjang.
4. **Sudah diperbaiki:** `font-weight: 100px` menjadi `300`.
5. **Belum diubah (aman):** `src/app.js` dimuat di `<head>` tanpa `defer`; logika form memakai `DOMContentLoaded`, pertahankan pola itu saat menambah kode.

## 13. Lampiran: Pemetaan ke Tutorial

| Menit (kira-kira) | Topik tutorial | Padanan di PRD ini |
| --- | --- | --- |
| 13:40–19:30 | Validasi form, disable tombol Checkout | Sudah selesai (versi `every()` + `classList.toggle`) |
| 19:30–28:20 | Kirim data customer + items, format pesan WhatsApp | Disederhanakan; WhatsApp jadi FR-12 (opsional) |
| 28:50–31:50 | Konsep Snap, Server Key, Client Key | Bagian 7 dan 8.5 |
| 32:30–38:50 | Setup Laragon, `place-order.php`, library Midtrans | Bagian 8.2, 8.3, 8.6 (dengan harga dari katalog server) |
| 39:00–44:00 | `snap.js` di head, `fetch` token, `snap.pay` | Bagian 8.5, FR-5 |
| 45:40–46:10 | Callback `onSuccess`/`onPending`/`onError` | FR-7 sampai FR-9 |

## 14. Menuju Production

- Ganti `server_key`, `signature_secret_key`, dan client key ke versi **Production** (tanpa awalan `SB-`), lalu set `is_production` menjadi `true`. Semuanya hanya di `php/config.php`.
- Ganti `snap.js` ke `https://app.midtrans.com/snap/snap.js`; base URL API mengikuti `is_production`.
- Isi ulang **Payment notification URL** di dashboard **Production** dengan alamat HTTPS tetap (bukan tunnel).
- Pindahkan `config.php`, file SQLite, dan log ke luar webroot; pastikan HTTPS aktif.
- Biarkan `webhook_status_fallback` nonaktif kecuali ada alasan; jalankan `reconcile.php` secara berkala bila perlu.
- Setelah akun disetujui, uji satu transaksi nyata bernominal kecil.

## 15. Riwayat Perubahan

| Tanggal | Versi | Perubahan |
| --- | --- | --- |
| 10 Nov 2026 | 1.1 | Fase 1-3 selesai. Klien Midtrans memakai cURL (bukan library). Penyimpanan SQLite (bukan MySQL). Webhook memakai Signature Secret Key (bukan Server Key). Fallback status API default nonaktif; ditambah `reconcile.php`, `router.php`, dan tes otomatis. Kriteria, keputusan terbuka, risiko, dan temuan kode diperbarui. |
| 10 Nov 2026 | 1.0 | Draf awal. |
