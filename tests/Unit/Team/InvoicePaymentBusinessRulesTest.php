<?php

namespace Tests\Unit\Team;

use App\Models\Banking\Transaction;
use App\Models\Document\Document;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InvoicePaymentBusinessRulesTest extends TestCase
{
    #[DataProvider('payments')]
    public function testPaidAndAmountDue(
        float $total,
        string $status,
        array $payments,
        float $expectedPaid,
        float $expectedDue
    ): void {
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

        $this->assertSame($expectedPaid, (float) $invoice->paid);
        $this->assertSame($expectedDue, (float) $invoice->amount_due);
    }

    public static function payments(): array
    {
        return [
            'zero total no transactions' => [0, 'sent', [], 0, 0],
            'zero total with transaction ignored by accessor' => [0, 'partial', [10], 0, 0],
            'paid status uses invoice total' => [100, 'paid', [], 100, 0],
            'paid status overrides transactions' => [100, 'paid', [25], 100, 0],
            'unpaid draft' => [100, 'draft', [], 0, 100],
            'unpaid sent' => [100, 'sent', [], 0, 100],
            'partial single payment' => [100, 'partial', [25], 25, 75],
            'partial multiple payments' => [100, 'partial', [25, 25], 50, 50],
            'fully settled by transactions' => [100, 'partial', [60, 40], 100, 0],
            'overpayment current behavior' => [100, 'partial', [110], 110, -10],
            'zero payment current behavior' => [100, 'partial', [0], 0, 100],
            'rounding two decimal places' => [100, 'partial', [33.335], 33.34, 66.66],
        ];
    }
}
