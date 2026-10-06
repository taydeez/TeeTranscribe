<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Payment confirmed</title></head>
<body style="font-family: Arial, sans-serif; color: #172033; line-height: 1.6;">
    <h1>Payment confirmed</h1>
    <p>Your payment for the {{ $payment['package_name'] }} credit package was successful. Your credits are ready to use.</p>
    <p>Your PDF invoice <strong>{{ $payment['invoice_number'] }}</strong> is attached to this email.</p>
    <p>Payment reference: {{ $payment['reference'] }}</p>
    <p><a href="{{ $billingUrl }}">View payment history and download your invoice</a></p>
    <p>Thank you,<br>TeeTranscribe</p>
</body>
</html>
