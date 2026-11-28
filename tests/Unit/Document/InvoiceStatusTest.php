<?php

namespace Tests\Unit\Document;

use App\Models\Document\Document;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InvoiceStatusTest extends TestCase
{
    #[DataProvider('statuses')]
    public function testInvoiceStatusLabel(string $status, string $expected): void
    {
        $invoice = new Document(['status' => $status]);

        $this->assertSame($expected, $invoice->status_label);
    }

    public static function statuses(): array
    {
        return [
            ['paid', 'status-success'],
            ['partial', 'status-partial'],
            ['sent', 'status-danger'],
            ['received', 'status-danger'],
            ['viewed', 'status-sent'],
            ['cancelled', 'status-canceled'],
            ['draft', 'status-draft'],
        ];
    }
}
