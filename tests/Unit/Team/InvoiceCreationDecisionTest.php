<?php

namespace Tests\Unit\Team;

use App\Exceptions\Job\NotAllowed;
use App\Http\Requests\Document\Document;
use App\Jobs\Document\CreateDocument;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\CreatesApplication;

final class InvoiceCreationDecisionTest extends TestCase
{
    use CreatesApplication;

    #[DataProvider('invoiceDates')]
    public function testInvoiceDateOrderAtTheIssueTime(string $dueAt, bool $valid): void
    {
        $input = ['issued_at' => '2026-11-27 12:00:00', 'due_at' => $dueAt];
        $request = Document::create('/api/documents', 'POST', ['type' => 'invoice', 'company_id' => 1] + $input);
        $request->setContainer($this->app);
        $validator = Validator::make($input, Arr::only($request->rules(), ['issued_at', 'due_at']));

        self::assertSame($valid, $validator->passes());
        self::assertSame($valid ? [] : ['issued_at', 'due_at'], $validator->errors()->keys());
    }

    public static function invoiceDates(): array
    {
        return [
            'one second before issue' => ['2026-11-27 11:59:59', false],
            'same instant as issue' => ['2026-11-27 12:00:00', true],
            'one second after issue' => ['2026-11-27 12:00:01', true],
        ];
    }

    #[DataProvider('planDecisions')]
    public function testCreateDocumentAuthorization(bool $planAllows, ?string $type, bool $denied): void
    {
        $job = new class($planAllows, $type) extends CreateDocument {
            public function __construct(private bool $planAllows, ?string $type)
            {
                $this->request = Request::create('/api/documents', 'POST', $type === null ? [] : ['type' => $type]);
            }

            public function getAnyActionLimitOfPlan(): object
            {
                return (object) ['action_status' => $this->planAllows, 'message' => 'Synthetic plan limit'];
            }
        };

        if ($denied) {
            $this->expectException(NotAllowed::class);
            $this->expectExceptionMessage('Synthetic plan limit');
        }

        $job->authorize();
        self::assertFalse($denied, 'A restricted invoice unexpectedly passed authorization.');
    }

    public static function planDecisions(): array
    {
        return [
            'allowed and empty type' => [true, null, false],
            'allowed and bill' => [true, 'bill', false],
            'allowed and invoice' => [true, 'invoice', false],
            'limited and empty type' => [false, null, false],
            'limited and bill' => [false, 'bill', false],
            'limited and invoice' => [false, 'invoice', true],
        ];
    }
}
