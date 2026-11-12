<?php
declare(strict_types=1);

/*
 * Sumber kebenaran harga di sisi server.
 *
 * PERINGATAN: data ini harus selalu sinkron dengan katalog di src/app.js
 * (menuBundling.items). Jika nama atau harga paket berubah di app.js,
 * ubah juga di file ini. Server TIDAK memakai harga yang dikirim browser.
 */
return [
    1 => ['name' => 'Paket Hemat Duo', 'price' => 57000],
    2 => ['name' => 'Paket Bakar Madu', 'price' => 76000],
    3 => ['name' => 'Paket Pepes Nusantara', 'price' => 69000],
    4 => ['name' => 'Paket Sop Ikan Hangat', 'price' => 66000],
    5 => ['name' => 'Paket Goreng Sharing', 'price' => 128000],
    6 => ['name' => 'Paket Premium Kepiting', 'price' => 166000],
];
