<?php

namespace Tests\Unit\Team;

use App\Models\Banking\Transaction;
use App\Models\Document\Document;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

final class InvoicePaymentCoverageTest extends TestCase
{
    public function testLoadsTransactionsWhenRelationIsMissing(): void
    {
        $invoice = $this->getMockBuilder(Document::class)
            ->onlyMethods(['load'])
            ->getMock();
        $invoice->fill($this->invoiceAttributes());
        $this->assertFalse($invoice->relationLoaded('transactions'));

        $invoice->expects($this->once())
            ->method('load')
            ->with('transactions')
            ->willReturnCallback(function () use ($invoice): Document {
                return $invoice->setRelation('transactions', new Collection([
                    new Transaction(['amount' => 25, 'currency_code' => 'USD', 'currency_rate' => 1]),
                ]));
            });

        $this->assertSame(25.0, $invoice->paid);
        $this->assertSame(75.0, $invoice->amount_due);
    }

    public function testConvertsForeignPaymentBeforeCalculatingBalance(): void
    {
        $invoice = new Document($this->invoiceAttributes());
        $invoice->setRelation('transactions', new Collection([
            new Transaction(['amount' => 50, 'currency_code' => 'EUR', 'currency_rate' => 2]),
        ]));

        $this->assertSame(25.0, $invoice->paid);
        $this->assertSame(75.0, $invoice->amount_due);
    }

    private function invoiceAttributes(): array
    {
        return ['amount' => 100, 'status' => 'partial', 'currency_code' => 'USD', 'currency_rate' => 1];
    }
}
