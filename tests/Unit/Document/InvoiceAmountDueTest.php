<?php

namespace Tests\Unit\Document;

use App\Models\Banking\Transaction;
use App\Models\Document\Document;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InvoiceAmountDueTest extends TestCase
{
    #[DataProvider('amounts')]
    public function testInvoiceAmountDue(float $total, string $status, array $payments, float $expected): void
    {
        $invoice = new Document([
            'amount' => $total,
            'status' => $status,
            'currency_code' => 'USD',
            'currency_rate' => 1,
        ]);
        $invoice->setRelation('transactions', new Collection(array_map(
            fn (float $amount) => new Transaction([
                'amount' => $amount,
                'currency_code' => 'USD',
                'currency_rate' => 1,
            ]),
            $payments
        )));

        $this->assertSame($expected, (float) $invoice->amount_due);
    }

    public static function amounts(): array
    {
        return [
            'fully paid' => [100, 'paid', [], 0],
            'zero total' => [0, 'sent', [], 0],
            'unpaid invoice' => [100, 'sent', [], 100],
            'partial payment' => [100, 'partial', [25], 75],
        ];
    }
}
