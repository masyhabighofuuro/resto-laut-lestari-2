<?php
declare(strict_types=1);

function receipt_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function receipt_mask_email(string $email): string
{
    $email = trim($email);
    $at = strpos($email, '@');
    if ($at === false || $at === 0) {
        return '***';
    }

    $local = substr($email, 0, $at);
    $domain = substr($email, $at + 1);
    if ($domain === '') {
        return '***';
    }

    return substr($local, 0, 1) . '***@' . $domain;
}

function receipt_mask_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone);
    if (!is_string($digits) || strlen($digits) <= 6) {
        return '***';
    }

    $stars = str_repeat('*', max(2, strlen($digits) - 6));
    return substr($digits, 0, 4) . $stars . substr($digits, -2);
}

function receipt_payment_label(?string $paymentType): string
{
    $type = strtolower(trim((string) $paymentType));

    return match ($type) {
        'credit_card' => 'Kartu Kredit',
        'bank_transfer' => 'Transfer Bank',
        'echannel' => 'Mandiri Bill',
        'permata' => 'Permata Virtual Account',
        'bca_va' => 'BCA Virtual Account',
        'bni_va' => 'BNI Virtual Account',
        'bri_va' => 'BRI Virtual Account',
        'cimb_va' => 'CIMB Virtual Account',
        'other_va' => 'Virtual Account',
        'gopay' => 'GoPay',
        'shopeepay' => 'ShopeePay',
        'qris' => 'QRIS',
        'dana' => 'DANA',
        'linkaja' => 'LinkAja',
        'indomaret' => 'Indomaret',
        'alfamart' => 'Alfamart',
        'akulaku' => 'Akulaku',
        'kredivo' => 'Kredivo',
        'cstore' => 'Gerai Retail',
        '' => 'Belum tercatat',
        default => 'Metode pembayaran lain',
    };
}

function receipt_format_time(?string $utc): string
{
    $value = trim((string) $utc);
    if ($value === '') {
        return '-';
    }

    try {
        $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
    } catch (Exception $error) {
        return '-';
    }

    return $date
        ->setTimezone(new DateTimeZone('Asia/Jakarta'))
        ->format('d/m/Y H:i') . ' WIB';
}

function receipt_format_money(int|string $amount): string
{
    return 'Rp ' . number_format((int) $amount, 0, ',', '.');
}

/**
 * @param array<string, mixed> $config
 * @return array{name: string, address: string, phone: string, email: string}
 */
function receipt_business(array $config): array
{
    $name = trim((string) ($config['business_name'] ?? ''));
    $address = trim((string) ($config['business_address'] ?? ''));
    $phone = trim((string) ($config['business_phone'] ?? ''));
    $email = trim((string) ($config['business_email'] ?? ''));

    return [
        'name' => $name !== '' ? $name : 'Resto Laut Lestari',
        'address' => $address,
        'phone' => $phone,
        'email' => $email,
    ];
}

function receipt_headers(): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, private, max-age=0');
    header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
}

function receipt_styles(): string
{
    return <<<'CSS'
* { box-sizing: border-box; }
body {
    margin: 0;
    padding: 2rem 1rem;
    background: #f2efe9;
    color: #1f2933;
    font-family: "Segoe UI", Arial, sans-serif;
    line-height: 1.5;
}
main.receipt {
    width: 100%;
    max-width: 46rem;
    margin: 0 auto;
    padding: 2rem;
    background: #ffffff;
    border-radius: 1rem;
    box-shadow: 0 1rem 2.5rem rgba(31, 41, 51, 0.12);
}
header.receipt-head {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 1rem;
    padding-bottom: 1.25rem;
    border-bottom: 2px solid #d9d2c5;
}
.business h1 {
    margin: 0 0 0.35rem;
    font-size: 1.45rem;
    letter-spacing: 0.02em;
}
.business p {
    margin: 0.15rem 0;
    color: #52606d;
    font-size: 0.9rem;
}
.doc {
    text-align: right;
}
.doc h2 {
    margin: 0 0 0.4rem;
    font-size: 1.1rem;
}
.badge {
    display: inline-block;
    padding: 0.25rem 0.7rem;
    border-radius: 999px;
    background: #d9f2e3;
    color: #146c43;
    font-size: 0.8rem;
    font-weight: 700;
    letter-spacing: 0.12em;
}
.meta {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1rem;
    padding: 1.25rem 0;
    border-bottom: 1px dashed #cbd2d9;
}
.meta span,
.customer h3 {
    display: block;
    margin: 0 0 0.25rem;
    color: #7b8794;
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}
.meta strong {
    font-size: 0.95rem;
    word-break: break-word;
}
.customer {
    padding: 1.25rem 0;
    border-bottom: 1px dashed #cbd2d9;
}
.customer p {
    margin: 0.15rem 0;
}
table {
    width: 100%;
    margin: 1.25rem 0;
    border-collapse: collapse;
}
th,
td {
    padding: 0.55rem 0.35rem;
    text-align: left;
    border-bottom: 1px solid #e4e7eb;
    vertical-align: top;
}
th {
    color: #52606d;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}
td.amount,
th.amount {
    text-align: right;
    white-space: nowrap;
}
tfoot td {
    border-bottom: 0;
    font-size: 1.05rem;
    font-weight: 700;
}
.note {
    margin: 1.5rem 0 0.35rem;
    color: #7b8794;
    font-size: 0.85rem;
}
.thanks {
    margin: 0;
    color: #52606d;
    font-size: 0.9rem;
}
.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-top: 1.75rem;
}
.actions button,
.actions a {
    display: inline-block;
    padding: 0.65rem 1.1rem;
    border: 1px solid #1f2933;
    border-radius: 0.5rem;
    background: #1f2933;
    color: #ffffff;
    font: inherit;
    text-decoration: none;
    cursor: pointer;
}
.actions a {
    background: transparent;
    color: #1f2933;
}
.not-found {
    max-width: 32rem;
    text-align: center;
}
.not-found p {
    color: #52606d;
}
@media (max-width: 36rem) {
    body { padding: 1rem 0.5rem; }
    main.receipt { padding: 1.25rem; }
    .meta { grid-template-columns: 1fr; }
    .doc { text-align: left; }
}
@media print {
    @page { margin: 12mm; }
    body { padding: 0; background: #ffffff; }
    main.receipt {
        max-width: none;
        padding: 0;
        border-radius: 0;
        box-shadow: none;
    }
    .no-print { display: none !important; }
}
CSS;
}

function receipt_document(string $title, string $body): string
{
    $safeTitle = receipt_e($title);

    return '<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<meta name="referrer" content="no-referrer">
<title>' . $safeTitle . '</title>
<style>' . receipt_styles() . '</style>
</head>
<body>
' . $body . '
</body>
</html>';
}

function receipt_render_not_found(): string
{
    $body = '<main class="receipt not-found">
<h1>Struk tidak ditemukan</h1>
<p>Periksa kembali kode pesanan dan email atau nomor telepon yang dipakai saat memesan.</p>
<p>Struk hanya tersedia untuk pesanan yang sudah dibayar.</p>
<div class="actions no-print">
<a href="../index.html">Kembali ke Resto Laut Lestari</a>
</div>
</main>';

    return receipt_document('Struk tidak ditemukan', $body);
}

function receipt_render_error(): string
{
    $body = '<main class="receipt not-found">
<h1>Struk belum dapat dimuat</h1>
<p>Terjadi kesalahan pada server. Silakan coba lagi beberapa saat lagi.</p>
<div class="actions no-print">
<a href="../index.html">Kembali ke Resto Laut Lestari</a>
</div>
</main>';

    return receipt_document('Struk belum dapat dimuat', $body);
}

/**
 * @param array<string, mixed> $order
 * @param array<int, array<string, mixed>> $items
 * @param array<string, mixed> $config
 */
function receipt_render_page(array $order, array $items, array $config): string
{
    $business = receipt_business($config);
    $orderId = (string) ($order['order_id'] ?? '');
    $customerName = (string) ($order['customer_name'] ?? '');
    $email = receipt_mask_email((string) ($order['customer_email'] ?? ''));
    $phone = receipt_mask_phone((string) ($order['customer_phone'] ?? ''));
    $contactParts = array_values(array_filter([$email, $phone], static fn (string $part): bool => $part !== '***'));
    $contactLine = $contactParts === [] ? '***' : implode(' &middot; ', array_map('receipt_e', $contactParts));

    $businessLines = '';
    if ($business['address'] !== '') {
        $businessLines .= '<p>' . receipt_e($business['address']) . '</p>';
    }
    if ($business['phone'] !== '') {
        $businessLines .= '<p>Telp. ' . receipt_e($business['phone']) . '</p>';
    }
    if ($business['email'] !== '') {
        $businessLines .= '<p>' . receipt_e($business['email']) . '</p>';
    }

    $rows = '';
    foreach ($items as $item) {
        $name = (string) ($item['name'] ?? '');
        $qty = max(0, (int) ($item['qty'] ?? 0));
        $price = (int) ($item['price'] ?? 0);
        $subtotal = $qty * $price;
        $rows .= '<tr>
<td>' . receipt_e($name) . '</td>
<td class="amount">' . $qty . '</td>
<td class="amount">' . receipt_format_money($price) . '</td>
<td class="amount">' . receipt_format_money($subtotal) . '</td>
</tr>';
    }

    if ($rows === '') {
        $rows = '<tr><td colspan="4">Tidak ada item.</td></tr>';
    }

    $paidAt = receipt_format_time(isset($order['paid_at']) ? (string) $order['paid_at'] : null);
    $method = receipt_payment_label(isset($order['payment_type']) ? (string) $order['payment_type'] : null);
    $total = receipt_format_money((int) ($order['gross_amount'] ?? 0));

    $body = '<main class="receipt">
<header class="receipt-head">
<div class="business">
<h1>' . receipt_e($business['name']) . '</h1>
' . $businessLines . '
</div>
<div class="doc">
<h2>Struk Pembayaran</h2>
<span class="badge">LUNAS</span>
</div>
</header>
<section class="meta">
<div><span>No. Pesanan</span><strong>' . receipt_e($orderId) . '</strong></div>
<div><span>Tanggal Bayar</span><strong>' . receipt_e($paidAt) . '</strong></div>
<div><span>Metode</span><strong>' . receipt_e($method) . '</strong></div>
</section>
<section class="customer">
<h3>Pelanggan</h3>
<p>' . receipt_e($customerName) . '</p>
<p>' . $contactLine . '</p>
</section>
<table>
<thead>
<tr>
<th>Item</th>
<th class="amount">Qty</th>
<th class="amount">Harga</th>
<th class="amount">Subtotal</th>
</tr>
</thead>
<tbody>
' . $rows . '
</tbody>
<tfoot>
<tr>
<td colspan="3">Total</td>
<td class="amount">' . $total . '</td>
</tr>
</tfoot>
</table>
<p class="note">Bukan faktur pajak.</p>
<p class="thanks">Terima kasih telah memesan di ' . receipt_e($business['name']) . '.</p>
<div class="actions no-print">
<button type="button" onclick="window.print()">Cetak / Simpan PDF</button>
<a href="../index.html">Kembali</a>
</div>
</main>';

    return receipt_document('Struk ' . $orderId . ' - ' . $business['name'], $body);
}
