<?php
declare(strict_types=1);

require_once __DIR__ . '/../php/receipt-lib.php';
require_once __DIR__ . '/support.php';

$failures = 0;

try {
    assert_true(
        'masking email menyisakan domain',
        receipt_mask_email('budi@example.test') === 'b***@example.test',
        $failures
    );
    assert_true(
        'masking email tidak valid menjadi bintang',
        receipt_mask_email('bukan-email') === '***',
        $failures
    );
    assert_true(
        'masking telepon menyisakan awal dan akhir',
        receipt_mask_phone('081234567890') === '0812******90',
        $failures
    );
    assert_true(
        'masking telepon pendek menjadi bintang',
        receipt_mask_phone('0812') === '***',
        $failures
    );

    assert_true(
        'label metode pembayaran ramah',
        receipt_payment_label('bank_transfer') === 'Transfer Bank'
            && receipt_payment_label('gopay') === 'GoPay'
            && receipt_payment_label(null) === 'Belum tercatat'
            && receipt_payment_label('metode_baru') === 'Metode pembayaran lain',
        $failures
    );

    assert_true(
        'waktu UTC ditampilkan sebagai WIB',
        receipt_format_time('2026-11-11 10:39:00') === '11/11/2026 17:39 WIB',
        $failures
    );
    assert_true(
        'paid_at kosong ditampilkan sebagai tanda hubung',
        receipt_format_time(null) === '-' && receipt_format_time('') === '-',
        $failures
    );
    assert_true(
        'format rupiah tanpa desimal',
        receipt_format_money(57000) === 'Rp 57.000',
        $failures
    );

    $order = [
        'order_id' => 'RLL-20261111-abc123',
        'customer_name' => '<script>alert("x")</script>',
        'customer_email' => 'budi@example.test',
        'customer_phone' => '081234567890',
        'gross_amount' => 57000,
        'paid_at' => '2026-11-11 10:39:00',
        'payment_type' => 'bank_transfer',
        'snap_token' => 'rahasia-snap-token',
        'transaction_id' => 'rahasia-transaksi',
    ];
    $items = [[
        'name' => '<b>Paket Hemat Duo</b>',
        'price' => 57000,
        'qty' => 1,
    ]];
    $config = [
        'business_name' => 'Resto <Laut> Lestari',
        'business_address' => 'Jl. Contoh 1',
        'business_phone' => '021-000000',
        'business_email' => 'halo@example.test',
    ];

    $html = receipt_render_page($order, $items, $config);

    assert_true(
        'nama pelanggan di-escape',
        str_contains($html, '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;')
            && !str_contains($html, '<script>alert'),
        $failures
    );
    assert_true(
        'nama item di-escape',
        str_contains($html, '&lt;b&gt;Paket Hemat Duo&lt;/b&gt;'),
        $failures
    );
    assert_true(
        'identitas usaha di-escape',
        str_contains($html, 'Resto &lt;Laut&gt; Lestari'),
        $failures
    );
    assert_true(
        'kontak pelanggan disamarkan',
        str_contains($html, 'b***@example.test')
            && str_contains($html, '0812******90')
            && !str_contains($html, 'budi@example.test'),
        $failures
    );
    assert_true(
        'token dan id transaksi tidak pernah tampil',
        !str_contains($html, 'rahasia-snap-token')
            && !str_contains($html, 'rahasia-transaksi'),
        $failures
    );
    assert_true(
        'struk memuat total dan catatan bukan faktur pajak',
        str_contains($html, 'Rp 57.000') && str_contains($html, 'Bukan faktur pajak.'),
        $failures
    );
    assert_true(
        'paid_at kosong pada order legacy tampil sebagai tanda hubung',
        str_contains(
            receipt_render_page(
                ['order_id' => 'RLL-20261111-legacy', 'paid_at' => null],
                [],
                []
            ),
            '<strong>-</strong>'
        ),
        $failures
    );

    $notFoundA = receipt_render_not_found();
    $notFoundB = receipt_render_not_found();
    assert_true(
        'halaman 404 struk identik untuk semua kegagalan',
        $notFoundA === $notFoundB
            && str_contains($notFoundA, 'Struk tidak ditemukan')
            && !str_contains($notFoundA, 'RLL-'),
        $failures
    );
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . PHP_EOL);
    $failures++;
}

echo $failures === 0
    ? 'UNIT RECEIPT: OK' . PHP_EOL
    : 'UNIT RECEIPT: ' . $failures . ' gagal' . PHP_EOL;

exit($failures === 0 ? 0 : 1);
