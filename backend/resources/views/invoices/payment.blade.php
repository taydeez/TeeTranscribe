<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $payment['invoice_number'] }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #172033; font-size: 12px; line-height: 1.6; margin: 28px; }
        h1 { margin: 0; font-size: 28px; color: #4338ca; }
        h2 { font-size: 22px; margin: 32px 0 4px; }
        .muted { color: #64748b; }
        .paid { color: #047857; font-weight: bold; }
        table { border-collapse: collapse; width: 100%; margin-top: 28px; }
        th { background: #eef2ff; text-align: left; padding: 12px; }
        td { padding: 12px; border-bottom: 1px solid #e2e8f0; }
        .right { text-align: right; }
        .total { font-size: 18px; font-weight: bold; }
        .footer { margin-top: 40px; font-size: 11px; }
    </style>
</head>
<body>
    @php
        $decimal = fn (int $minor): string => number_format(intdiv($minor, 100)).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
        $amount = $payment['currency'].' '.$decimal((int) $payment['amount_minor']);
    @endphp
    <h1>TeeTranscribe</h1>
    <p class="muted">Audio transcription services</p>
    <h2>Invoice</h2>
    <p>{{ $payment['invoice_number'] }}<br>
        <span class="muted">Paid on {{ \Carbon\CarbonImmutable::parse($payment['paid_at'])->format('F j, Y, H:i T') }}</span><br>
        <span class="paid">PAID</span>
    </p>
    <p><strong>Billed to</strong><br>{{ $payment['customer_email'] }}</p>
    <table>
        <thead><tr><th>Description</th><th>Credits</th><th class="right">Amount</th></tr></thead>
        <tbody>
            <tr><td>{{ $payment['package_name'] }} credit package</td><td>{{ $decimal((int) $payment['credit_units']) }}</td><td class="right">{{ $amount }}</td></tr>
            <tr><td colspan="2" class="total">Total paid</td><td class="right total">{{ $amount }}</td></tr>
        </tbody>
    </table>
    <p><strong>Payment method:</strong> {{ ucfirst($payment['gateway']) }}<br>
        <strong>Payment reference:</strong> {{ $payment['reference'] }}
    </p>
    <p class="muted footer">Thank you for your payment. Your credits are available in your TeeTranscribe account.</p>
</body>
</html>
