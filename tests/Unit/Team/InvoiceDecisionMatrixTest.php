<?php
namespace Tests\Unit\Team;

use App\Models\Document\Document;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Decision table: accrued = !excluded; outstanding = accrued && !paid. */
final class InvoiceDecisionMatrixTest extends TestCase
{
    #[DataProvider('cases')]
    public function testAccruedOutstandingDecision(string $status, bool $accrued, bool $outstanding): void
    {
        $invoice = new Document();
        $invoice->status = $status;
        self::assertSame($accrued, $invoice->isAccrued());
        self::assertSame($outstanding, $invoice->isOutstanding());
    }

    public static function cases(): array
    {
        return [
            'draft excluded' => ['draft', false, false],
            'cancelled excluded' => ['cancelled', false, false],
            'paid accrued but not outstanding' => ['paid', true, false],
            'sent outstanding' => ['sent', true, true],
            'viewed outstanding' => ['viewed', true, true],
            'partial outstanding' => ['partial', true, true],
            'received outstanding' => ['received', true, true],
        ];
    }
}
