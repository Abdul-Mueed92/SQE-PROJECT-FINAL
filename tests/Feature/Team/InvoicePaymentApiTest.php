<?php

namespace Tests\Feature\Team;

use App\Events\Banking\TransactionCreating;
use App\Models\Document\Document;
use Illuminate\Support\Facades\Event;

final class InvoicePaymentApiTest extends InvoicePaymentApiTestCase
{
    public function testInvoiceAndPaymentReadbackAcrossAcceptanceAndRejection(): void
    {
        $invoicePayload = $this->invoicePayload('SQE-INVOICE-001');
        $invoice = $this->postInvoice($invoicePayload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'sent')
            ->json('data.id');
        $this->assertInvoiceState($invoice, 'sent', 100, []);

        $this->postInvoice($invoicePayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document_number');
        self::assertSame(1, Document::query()->where('document_number', 'SQE-INVOICE-001')->count());
        $this->assertInvoiceState($invoice, 'sent', 100, []);

        $payment = $this->paymentPayload('SQE-PAYMENT-001', 25);
        $this->postPayment($invoice, $payment)->assertCreated()->assertJsonPath('data.document_id', $invoice);
        $this->assertInvoiceState($invoice, 'partial', 75, ['SQE-PAYMENT-001']);

        $this->postPayment($invoice, $payment)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('number');
        $this->assertInvoiceState($invoice, 'partial', 75, ['SQE-PAYMENT-001']);

        $this->postPayment($invoice, $this->paymentPayload('SQE-PAYMENT-002', 75.01))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
        $this->assertInvoiceState($invoice, 'partial', 75, ['SQE-PAYMENT-001']);

        $this->postPayment($invoice, $this->paymentPayload('SQE-PAYMENT-002', 75))->assertCreated();
        $this->assertInvoiceState($invoice, 'paid', 0, ['SQE-PAYMENT-001', 'SQE-PAYMENT-002']);

        $this->postPayment($invoice, $this->paymentPayload('SQE-PAYMENT-003', 0.01))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
        $this->assertInvoiceState($invoice, 'paid', 0, ['SQE-PAYMENT-001', 'SQE-PAYMENT-002']);
    }

    public function testPaymentRetryAfterAnUnavailableDependencyWritesExactlyOnce(): void
    {
        $invoice = $this->postInvoice($this->invoicePayload('SQE-RECOVERY-001'))
            ->assertCreated()
            ->json('data.id');
        $payment = $this->paymentPayload('SQE-RECOVERY-PAYMENT', 100);

        $dependencyUnavailable = true;
        $failuresInjected = 0;
        Event::listen(TransactionCreating::class, static function () use (&$dependencyUnavailable, &$failuresInjected): void {
            if ($dependencyUnavailable) {
                $failuresInjected++;
                throw new \RuntimeException('SQE synthetic payment dependency unavailable');
            }
        });

        $this->postPayment($invoice, $payment)->assertInternalServerError();
        self::assertSame(1, $failuresInjected);
        $this->assertInvoiceState($invoice, 'sent', 100, []);

        $dependencyUnavailable = false;
        $this->postPayment($invoice, $payment)->assertCreated();
        $this->assertInvoiceState($invoice, 'paid', 0, ['SQE-RECOVERY-PAYMENT']);

        $this->postPayment($invoice, $payment)->assertUnprocessable()->assertJsonValidationErrors('number');
        $this->assertInvoiceState($invoice, 'paid', 0, ['SQE-RECOVERY-PAYMENT']);
    }

    private function assertInvoiceState(int $invoice, string $status, float $balance, array $paymentNumbers): void
    {
        $this->getInvoice($invoice)
            ->assertOk()
            ->assertJsonPath('data.status', $status)
            ->assertJsonCount(count($paymentNumbers), 'data.transactions.data');
        $payments = $this->getJson(route('api.documents.transactions.index', $invoice))
            ->assertOk()
            ->json('data');

        self::assertEqualsCanonicalizing($paymentNumbers, array_column($payments, 'number'));
        self::assertSame($balance, (float) Document::query()->findOrFail($invoice)->amount_due);
    }
}
