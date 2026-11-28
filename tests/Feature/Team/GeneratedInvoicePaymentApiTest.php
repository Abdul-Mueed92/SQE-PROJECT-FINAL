<?php

namespace Tests\Feature\Team;

use App\Models\Banking\Transaction;
use App\Models\Document\Document;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Arr;
use PHPUnit\Framework\AssertionFailedError;

final class GeneratedInvoicePaymentApiTest extends InvoicePaymentApiTestCase
{
    public function testGeneratedEquivalencePartitionsAndBoundaries(): void
    {
        $file = getenv('SQE_API_CASES') ?: base_path('api/generated_cases.json');
        $cases = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotEmpty($cases);
        // Independent partitions share one seeded company in this test process.
        $this->withoutMiddleware(ThrottleRequests::class);

        $results = [];
        $failures = [];
        foreach ($cases as $index => $case) {
            $status = null;
            $id = $case['id'];
            try {
                $this->runCase($case, $index + 1, $status);
                $results[] = ['id' => $id, 'expected' => $case['expected'], 'observed' => $status, 'result' => 'pass'];
            } catch (AssertionFailedError $error) {
                $failures[] = "$id: {$error->getMessage()}";
                $results[] = [
                    'id' => $id, 'expected' => $case['expected'], 'observed' => $status,
                    'result' => 'fail', 'failure' => $error->getMessage(),
                ];
            }
        }

        if ($output = getenv('SQE_API_RESULTS')) {
            $json = json_encode($results, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            self::assertNotFalse(file_put_contents($output, $json . PHP_EOL));
        }
        self::assertSame([], $failures, implode(PHP_EOL, $failures));
    }

    private function runCase(array $case, int $sequence, ?int &$status): void
    {
        $number = 'SQE-GENERATED-' . $sequence;
        $isInvoice = $case['schema'] === 'InvoiceCreate';
        self::assertContains($case['schema'], ['InvoiceCreate', 'PaymentCreate']);

        if ($isInvoice) {
            $payload = $this->invoicePayload($number);
            $documentsBefore = Document::query()->count();
        } else {
            $invoice = $this->postInvoice($this->invoicePayload($number))
                ->assertCreated()->json('data.id');
            $payload = $this->paymentPayload($number, 25);
        }

        if ($case['remove'] ?? false) {
            Arr::forget($payload, $case['field']);
        } else {
            Arr::set($payload, $case['field'], $case['value']);
        }

        $response = $isInvoice
            ? $this->postInvoice($payload)
            : $this->postPayment($invoice, $payload);
        $status = $response->status();
        $accepted = $status === 201;

        if ($isInvoice) {
            self::assertSame($documentsBefore + (int) $accepted, Document::query()->count());
            if ($accepted) {
                $created = $response->json('data.id');
                $this->getInvoice($created)
                    ->assertOk()->assertJsonPath('data.id', $created);
            }
        } else {
            $payments = $this->getJson(route('api.documents.transactions.index', $invoice))->assertOk();
            $payments->assertJsonCount((int) $accepted, 'data');
            $this->getInvoice($invoice)->assertOk()
                ->assertJsonCount((int) $accepted, 'data.transactions.data');
            if ($accepted) {
                $transaction = Transaction::query()->findOrFail($response->json('data.id'));
                self::assertSame($invoice, $transaction->document_id);
                $payments->assertJsonPath('data.0.id', $transaction->id);
            } else {
                $document = Document::query()->findOrFail($invoice);
                self::assertSame('sent', $document->status);
                self::assertSame(100.0, (float) $document->amount_due);
            }
        }

        $response->assertStatus($case['expected']);
        if ($status === 422) {
            $response->assertJsonValidationErrors($case['field']);
        }
    }
}
