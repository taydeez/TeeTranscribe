<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PaymentInvoiceMail extends Mailable
{
    public function __construct(public readonly array $payment, private readonly string $pdf) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Payment confirmed — '.$this->payment['invoice_number']);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.payment-invoice', with: ['billingUrl' => rtrim(config('app.frontend_url'), '/').'/dashboard/billing']);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn (): string => $this->pdf, $this->payment['invoice_number'].'.pdf')->withMime('application/pdf')];
    }
}
