# Resto Laut Lestari

Website restoran seafood statis dengan pemesanan online dan pembayaran **Midtrans Snap** (sandbox). Backend ditulis ringan dengan **PHP** tanpa framework dan menggunakan **SQLite** untuk menyimpan pesanan, item, rate limit, dan status pembayaran.

## Fitur

- Landing page responsif: home, tentang kami, menu, menu bundling, kontak.
- Keranjang belanja dan checkout berbasis **Alpine.js**.
- Harga selalu diambil dari server (`php/products.php`), bukan dari browser.
- Pembuatan pesanan `pending` sebelum memanggil Midtrans Snap.
- Webhook notifikasi pembayaran dengan verifikasi signature.
- Panel pesanan pending dan pembatalan pesanan.
- Pencarian pesanan dan struk pembayaran (data pribadi disamarkan).
- CLI rekonsiliasi status pembayaran (`php/reconcile.php`).
- Proteksi berkas sensitif melalui `router.php` dan `php/.htaccess`.
- Tes otomatis untuk signature, Midtrans, orders, struk, HTTP, dan CLI.

## Teknologi

- HTML, CSS, JavaScript vanilla
- Alpine.js 3.15.12 (CDN, versi dipin)
- PHP 8.1+ dengan ekstensi `pdo_sqlite` dan `curl`
- SQLite (file lokal di `php/storage/orders.sqlite`, dapat diubah lewat `RLL_DB_PATH`)
- Midtrans Snap sandbox

## Struktur Proyek

```text
.
|-- index.html              # Halaman utama
|-- css/style.css           # Gaya tampilan
|-- js/script.js            # Interaksi UI (navbar, cart, panel)
|-- src/app.js              # Katalog, keranjang, checkout, integrasi Snap
|-- img/                    # Aset gambar
|-- php/
|   |-- place-order.php     # Membuat pesanan + token Snap
|   |-- notification.php    # Webhook Midtrans
|   |-- orders-pending.php  # Daftar pesanan pending per perangkat
|   |-- order-lookup.php    # Pencarian pesanan
|   |-- cancel-order.php    # Pembatalan pesanan
|   |-- receipt.php         # Struk pembayaran
|   |-- receipt-lib.php     # Helper struk
|   |-- products.php        # Sumber harga di server
|   |-- orders.php          # Akses database + aturan status
|   |-- midtrans.php        # Klien Midtrans
|   |-- device.php          # Token perangkat (cookie HttpOnly)
|   |-- config-loader.php   # Pemuat konfigurasi
|   |-- config.example.php  # Contoh konfigurasi
|   |-- reconcile.php       # CLI rekonsiliasi
|   `-- storage/            # Database SQLite (tidak di-commit)
|-- tests/                  # Tes otomatis
|-- router.php              # Router untuk PHP built-in server
`-- AGENTS.md               # Konteks internal proyek
```

## Kebutuhan

- PHP 8.1 atau lebih baru dengan `pdo_sqlite` dan `curl`.
  - Di Windows, gunakan PHP dari XAMPP, misalnya `C:\xampp\php\php.exe`.
- PowerShell (terminal VS Code).
- `cloudflared` hanya diperlukan jika ingin menguji webhook dari Midtrans.
- Akun Midtrans sandbox beserta **Server Key** dan **Signature Secret Key**.

Periksa ekstensi PHP:

```powershell
& C:\xampp\php\php.exe -m | Select-String "pdo_sqlite|curl"
```

## Konfigurasi

1. Salin contoh konfigurasi:

   ```powershell
   Copy-Item php\config.example.php php\config.php
   ```

2. Isi `php/config.php`:

   - `server_key`: Server Key sandbox (berawalan `SB-Mid-server-`).
   - `signature_secret_key`: Signature Secret Key dari dashboard Midtrans.
   - `is_production`: biarkan `false` untuk sandbox.

3. Jangan pernah meng-commit `php/config.php`, `.env`, file `*.sqlite*`, atau `*.log`.
   Semua sudah tercantum di `.gitignore`.

Opsional, simpan database di luar folder proyek:

```powershell
$env:RLL_DB_PATH = "C:\data\resto-laut-lestari\orders.sqlite"
```

## Menjalankan Server Dev (PowerShell VS Code)

Buka terminal PowerShell di VS Code, lalu jalankan dari root proyek:

```powershell
& C:\xampp\php\php.exe -S 127.0.0.1:8000 router.php
```

Buka <http://127.0.0.1:8000> di browser.

Catatan:

- Selalu sertakan `router.php`. PHP built-in server tidak membaca `.htaccess`, sehingga router inilah yang menolak akses ke `php/config.php`, `php/storage/`, `tests/`, file `*.sqlite`, dan `*.log`.
- Jika `php` sudah ada di `PATH`, perintahnya bisa disingkat menjadi `php -S 127.0.0.1:8000 router.php`.
- Biarkan terminal ini tetap berjalan selama pengembangan.

## Menjalankan Cloudflare Tunnel

Tunnel hanya diperlukan untuk menerima webhook Midtrans di komputer lokal. Jalankan server dev terlebih dahulu, lalu buka **terminal PowerShell kedua**:

```powershell
cloudflared tunnel --url http://127.0.0.1:8000
```

Salin URL publik yang muncul, misalnya:

```text
https://contoh-acak.trycloudflare.com
```

Lalu atur **Payment Notification URL** di dashboard Midtrans Sandbox menjadi:

```text
https://contoh-acak.trycloudflare.com/php/notification.php
```

Setelah selesai menguji webhook:

1. Hentikan `cloudflared` dengan `Ctrl+C`.
2. Kosongkan kembali Payment Notification URL di dashboard Midtrans.

Jika `cloudflared` belum terpasang:

```powershell
winget install --id Cloudflare.cloudflared
```

## Menjalankan Tes

```powershell
& C:\xampp\php\php.exe tests\run.php
```

Tes memakai database sementara, key palsu, dan `RLL_ENV=test`, sehingga tidak menyentuh database atau kredensial asli.

## Alur Pemesanan

1. Klien mengirim `POST php/place-order.php` berisi `customer` dan `items: [{id, qty}]`.
2. Server memvalidasi input dan mengambil harga dari `php/products.php`.
3. Pesanan `pending` disimpan ke SQLite sebelum Midtrans dipanggil.
4. Server meminta Snap token ke Midtrans, lalu mengembalikan `{token, order_id}`.
5. Klien membuka `snap.pay`; hasil `success`, `pending`, `error`, atau `close` ditampilkan sebagai notifikasi.
6. Midtrans mengirim webhook ke `php/notification.php` untuk memperbarui status pesanan.
7. Pembeli dapat membuka struk melalui tautan yang diberikan setelah pembayaran.

## Endpoint Utama

| Endpoint | Metode | Keterangan |
| --- | --- | --- |
| `php/place-order.php` | `POST` | Membuat pesanan dan token Snap |
| `php/notification.php` | `POST` | Webhook Midtrans (publik, diverifikasi signature) |
| `php/orders-pending.php` | `GET` | Daftar pesanan pending milik perangkat |
| `php/order-lookup.php` | `POST` | Mencari pesanan |
| `php/cancel-order.php` | `POST` | Membatalkan pesanan pending |
| `php/receipt.php` | `GET`/`POST` | Menampilkan struk pembayaran |
| `php/reconcile.php` | CLI | Rekonsiliasi status pesanan pending |

## Catatan Keamanan

- Harga dan total selalu dihitung di server; input harga dari klien diabaikan.
- Kepemilikan pesanan ditentukan oleh token perangkat (cookie HttpOnly berisi hash), bukan alamat IP.
- Webhook memvalidasi format `order_id`, memverifikasi signature, lalu memeriksa pesanan.
- Status `paid` tidak pernah turun dan status terminal tidak kembali menjadi `pending`.
- Struk hanya menampilkan data pribadi yang disamarkan.
- Respons error ke klien bersifat generik; detail hanya ditulis ke log tanpa secret atau data pribadi.

## Sinkronisasi Katalog

Katalog dan harga ada di dua tempat:

- `src/app.js` (`menuItems`) untuk tampilan.
- `php/products.php` untuk sumber kebenaran saat checkout.

Jika nama atau harga paket berubah, ubah **keduanya**.

## Lisensi

Proyek portofolio. Silakan gunakan sebagai referensi belajar.
