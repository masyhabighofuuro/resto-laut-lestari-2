<?php
declare(strict_types=1);

/*
 * Salin file ini menjadi php/config.php, lalu isi kredensial Midtrans Anda.
 * File php/config.php sudah masuk .gitignore dan tidak boleh di-commit.
 *
 * Server Key dipakai untuk Basic Auth ke API Midtrans (Snap dan status API).
 *   Sandbox    -> Settings > Access Keys (key berawalan SB-Mid-server-)
 *   Production -> setelah akun disetujui
 *
 * Signature Secret Key dipakai untuk memverifikasi signature webhook.
 *   Dashboard Midtrans -> Settings > Access Keys > Signature Secret Key.
 *   Key ini terpisah dari Server Key; jangan gunakan Server Key untuk signature.
 *
 * is_production = false -> Midtrans sandbox
 * is_production = true  -> Midtrans production (butuh kredensial production)
 *
 * webhook_status_fallback = false -> signature tidak valid langsung ditolak 403.
 * Set true untuk memverifikasi ulang lewat GET /v2/{order_id}/status.
 *
 * Opsional: set environment variable RLL_DB_PATH untuk menyimpan SQLite
 * di luar webroot, misalnya C:\data\resto-laut-lestari\orders.sqlite.
 */
return [
    'server_key' => 'SB-Mid-server-XXXXXXXXXXXXXXXX',
    'signature_secret_key' => '',

    'webhook_status_fallback' => false,

    // Opsional; nilai default dipakai bila tidak diisi.
    'status_api_timeout' => 10,
    'rate_limit_order' => 5,
    'rate_limit_ip' => 20,
    'rate_limit_window' => 600,

    // Pesanan belum dibayar.
    'order_expiry_minutes' => 60,
    'max_pending_per_device' => 3,
    // Refresh status saat panel dibuka; interval per order tidak boleh < 30 detik.
    'pending_refresh_limit' => 3,
    'pending_refresh_min_interval' => 30,
    'cancel_api_timeout' => 10,
    'cancel_rate_limit_device' => 10,
    'cancel_rate_limit_ip' => 30,
    'lookup_rate_limit_device' => 5,
    'lookup_rate_limit_ip' => 10,

    // Struk pembayaran. Batas laju aktor = hash cookie perangkat + IP;
    // batas per order hanya tambahan dan tidak bisa dikunci perangkat lain.
    'receipt_rate_limit_actor' => 30,
    'receipt_rate_limit_order' => 60,

    // Identitas pada struk; kosongkan baris yang tidak ingin dicetak.
    'business_name' => 'Resto Laut Lestari',
    'business_address' => 'Alamat restoran',
    'business_phone' => 'Nomor telepon',
    'business_email' => 'Email restoran',

    'is_production' => false,
];
