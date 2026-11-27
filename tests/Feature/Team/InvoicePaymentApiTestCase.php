<?php

namespace Tests\Feature\Team;

use App\Models\Banking\Transaction;
use App\Models\Document\Document;
use Illuminate\Testing\TestResponse;
use Tests\Feature\FeatureTestCase;

abstract class InvoicePaymentApiTestCase extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withExceptionHandling()->withHeaders([
            'Authorization' => 'Basic ' . base64_encode('test@company.com:123456'),
        ]);
    }

    protected function invoicePayload(string $number): array
    {
        $payload = Document::factory()->invoice()->items()->raw([
            'document_number' => $number,
            'status' => 'sent',
            'issued_at' => '2026-11-01 12:00:00',
            'due_at' => '2026-11-30 12:00:00',
            'amount' => 100,
        ]);
        $payload['items'][0]['price'] = 100;
        $payload['items'][0]['tax_ids'] = [];

        return $payload;
    }

    protected function paymentPayload(string $number, float $amount): array
    {
        return Transaction::factory()->income()->raw([
            'number' => $number,
            'amount' => $amount,
            'paid_at' => '2026-11-15 12:00:00',
        ]);
    }

    protected function postPayment(int $invoice, array $payload): TestResponse
    {
        return $this->postJson(route('api.documents.transactions.store', $invoice), $payload);
    }

    protected function postInvoice(array $payload): TestResponse
    {
        return $this->postJson(route('api.documents.store', ['search' => 'type:invoice']), $payload);
    }

    protected function getInvoice(int $invoice): TestResponse
    {
        return $this->getJson(route('api.documents.show', ['document' => $invoice, 'search' => 'type:invoice']));
    }
}
