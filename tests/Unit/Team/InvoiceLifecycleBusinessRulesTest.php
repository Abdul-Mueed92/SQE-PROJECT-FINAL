<?php

namespace Tests\Unit\Team;

use App\Models\Document\Document;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InvoiceLifecycleBusinessRulesTest extends TestCase
{
    #[DataProvider('states')]
    public function testAccruedAndOutstanding(string $status, bool $accrued, bool $outstanding): void
    {
        $invoice = new Document(['status' => $status]);

        $this->assertSame($accrued, $invoice->isAccrued());
        $this->assertSame($outstanding, $invoice->isOutstanding());
    }

    public static function states(): array
    {
        return [
            'draft excluded' => ['draft', false, false],
            'cancelled excluded' => ['cancelled', false, false],
            'sent outstanding' => ['sent', true, true],
            'viewed outstanding' => ['viewed', true, true],
            'partial outstanding' => ['partial', true, true],
            'paid settled' => ['paid', true, false],
            'received outstanding' => ['received', true, true],
            'unknown status follows current implementation' => ['custom', true, true],
        ];
    }
}
