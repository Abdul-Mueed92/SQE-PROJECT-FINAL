<?php

namespace Tests\Feature\Team;

use App\Events\Banking\TransactionCreating;
use App\Models\Document\Document;
use Illuminate\Support\Facades\Event;

final class InvoicePaymentApiTest extends InvoicePaymentApiTestCase
{
    public function testDraftUpdateRejectsAnExistingInvoiceNumber(): void
    {
        $original = ['status' => 'draft'] + $this->invoicePayload('SQE-EDIT-ORIGINAL');
        $id = $this->postInvoice($original)->assertCreated()->json('data.id');
        $existingNumber = 'SQE-EDIT-EXISTING';
        $this->postInvoice(['status' => 'draft'] + $this->invoicePayload($existingNumber))->assertCreated();

        $this->loginAs()->post(route('invoices.update', $id), [
            '_method' => 'PATCH',
            'document_number' => $existingNumber,
        ] + $original, ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document_number');

        self::assertSame($original['document_number'], Document::findOrFail($id)->document_number);
    }

    public function testMalformedInvoiceAndPaymentDatesReturnFieldErrors(): void
    {
        foreach (['issued_at', 'due_at'] as $field) {
            $number = 'SQE-INVALID-' . $field;
            $payload = $this->invoicePayload($number);
            $payload[$field] = 'invalid-date';
            $this->postInvoice($payload)->assertUnprocessable()->assertJsonValidationErrors($field);
            self::assertFalse(Document::query()->where('document_number', $number)->exists());
        }

        $invoice = $this->postInvoice($this->invoicePayload('SQE-INVALID-PAYMENT-DATE'))
            ->assertCreated()->json('data.id');
        $payload = $this->paymentPayload('SQE-INVALID-PAID-AT', 25);
        $payload['paid_at'] = 'invalid-date';
        $this->postPayment($invoice, $payload)->assertUnprocessable()->assertJsonValidationErrors('paid_at');
        $this->assertInvoiceState($invoice, 'sent', 100, []);
    }

    public function testInvoiceAndPaymentReadbackAcrossAcceptanceAndRejection(): void
    {
        $invoicePayload = $this->invoicePayload('SQE-INVOICE-001');
        $invoicePayload['amount'] = 10;
        $invoice = $this->postInvoice($invoicePayload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'sent')
            ->json('data.id');
        self::assertSame(100.0, (float) $this->getInvoice($invoice)->assertOk()->json('data.amount'));
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
