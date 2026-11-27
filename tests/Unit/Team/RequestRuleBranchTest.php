<?php

namespace Tests\Unit\Team;

use App\Http\Requests\Banking\Transaction;
use App\Http\Requests\Document\Document;
use App\Models\Banking\Transaction as PaymentModel;
use App\Models\Document\Document as InvoiceModel;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RequestRuleBranchTest extends TestCase
{
    #[DataProvider('updates')]
    public function testUpdateExcludesTheCurrentRecordFromUniqueness(
        string $requestClass,
        bool $api
    ): void {
        $invoice = $requestClass === Document::class;
        $type = $invoice ? 'invoice' : 'income';
        $parameter = $api ? ($invoice ? 'document' : 'transaction')
            : config('type.' . ($invoice ? 'document' : 'transaction') . ".$type.route.parameter");
        $uri = ($api ? '/api' : '/1') . ($invoice ? '/documents/42' : '/transactions/42');
        $request = $requestClass::create($uri, $api ? 'PATCH' : 'PUT', ['company_id' => 1, 'type' => $type]);
        $route = new Route([$request->getMethod()], ltrim(substr($uri, 0, -2), '/') . '{' . $parameter . '}', []);
        $route->bind($request);
        $model = $invoice ? new InvoiceModel() : new PaymentModel();
        $model->setAttribute('id', 42);
        $route->setParameter($parameter, $api ? '42' : $model);
        $request->setRouteResolver(static fn () => $route);

        $rules = $request->rules();
        $field = $invoice ? 'document_number' : 'number';
        $table = $invoice ? 'documents' : 'transactions';
        self::assertStringContainsString("unique:$table,NULL,42,id,", $rules[$field]);
    }

    public static function updates(): iterable
    {
        yield 'invoice API id' => [Document::class, true];
        yield 'invoice web model' => [Document::class, false];
        yield 'payment API id' => [Transaction::class, true];
        yield 'payment web model' => [Transaction::class, false];
    }

    public function testUploadRulesAreAddedWhenFilesArePresent(): void
    {
        $file = UploadedFile::fake()->create('sample.png', 1, 'image/png');
        $invoice = Document::create('/api/documents', 'POST', ['company_id' => 1], [], [
            'company_logo' => $file,
            'attachment' => [$file],
        ]);
        $payment = Transaction::create('/api/transactions', 'POST', ['company_id' => 1], [], [
            'attachment' => [$file],
        ]);

        self::assertStringContainsString('dimensions:max_width=', $invoice->rules()['company_logo']);
        self::assertStringStartsWith('mimes:', $invoice->rules()['attachment.*']);
        self::assertStringStartsWith('mimes:', $payment->rules()['attachment.*']);
    }

    public function testQuantityRulesNormalizeValidValuesAndReportInvalidValues(): void
    {
        $invoice = Document::create('/api/documents', 'POST', [
            'company_id' => 1,
            'items' => [
                ['quantity' => '2+3'],
                ['quantity' => '1,5'],
                ['quantity' => ''],
            ],
        ]);
        $rules = $invoice->rules();
        $items = $invoice->input('items');
        self::assertSame(5.0, (float) $items[0]['quantity']);
        self::assertSame(1.5, (float) $items[1]['quantity']);
        self::assertContains('max:10', $rules['items.0.quantity']);
        self::assertContains('max:12', $rules['items.1.quantity']);
        self::assertArrayHasKey('items.1.quantity.max', $invoice->messages());

        $validator = Validator::make(
            ['items' => [2 => ['quantity' => 'abc']]],
            ['items.2.quantity' => $rules['items.2.quantity']]
        );
        self::assertFalse($validator->passes());
        self::assertSame(['items.2.quantity'], $validator->errors()->keys());
    }
}
