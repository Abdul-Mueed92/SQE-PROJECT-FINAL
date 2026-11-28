<?php

namespace Tests\Unit\Team;

use App\Events\Banking\DocumentTransactionCreated;
use App\Events\Banking\DocumentTransactionCreating;
use App\Events\Document\PaidAmountCalculated;
use App\Jobs\Banking\CreateBankingDocumentTransaction;
use App\Jobs\Banking\CreateTransaction;
use App\Jobs\Document\CreateDocumentHistory;
use App\Models\Banking\Transaction;
use App\Models\Document\Document;
use App\Utilities\Date;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use ReflectionProperty;
use Tests\CreatesApplication;

final class InvoicePaymentPreparationTest extends TestCase
{
    use CreatesApplication;

    public function testOmittedPaymentValuesUseTheInvoiceAndCompanyDefaults(): void
    {
        $settings = Mockery::mock();
        $settings->shouldReceive('get')->once()->with('default.account', null)->andReturn(9);
        $settings->shouldReceive('get')->once()->with('default.payment_method', null)->andReturn('cash');
        $this->app->instance('setting', $settings);

        $invoice = $this->invoice();
        Event::listen(PaidAmountCalculated::class, static function (PaidAmountCalculated $event): void {
            $event->model->paid_amount += 5;
        });

        Date::setTestNow('2026-11-27 12:00:00');
        try {
            self::assertEquals([
                'amount' => 70, 'company_id' => 1, 'currency_code' => 'USD',
                'paid_at' => '2026-11-27 12:00:00', 'currency_rate' => 1.0,
                'account_id' => 9, 'document_id' => 42, 'contact_id' => 3,
                'category_id' => 4, 'payment_method' => 'cash', 'notify' => 0,
            ], (new PaymentPreparationProbe($invoice, []))->preparedRequest());
        } finally {
            Date::setTestNow();
        }
    }

    public function testSuppliedPaymentValuesArePreserved(): void
    {
        Event::fake([PaidAmountCalculated::class]);
        $input = [
            'amount' => 40, 'currency_code' => 'EUR', 'paid_at' => '2026-11-26 09:30:00',
            'currency_rate' => 2.0, 'account_id' => 11, 'document_id' => 42,
            'contact_id' => 5, 'category_id' => 6, 'payment_method' => 'bank_transfer', 'notify' => 1,
        ];

        self::assertSame($input + ['company_id' => 1], (new PaymentPreparationProbe($this->invoice(), $input))->preparedRequest());
        Event::assertNotDispatched(PaidAmountCalculated::class);
    }

    public function testPaymentDispatchesHistoryAndPublishesTheCreatedTransaction(): void
    {
        Event::fake([DocumentTransactionCreating::class, DocumentTransactionCreated::class]);
        DB::shouldReceive('transaction')->once()->andReturnUsing(static fn ($operation) => $operation());

        $invoice = Mockery::mock(Document::class)->makePartial();
        $this->fillInvoice($invoice);
        $invoice->shouldReceive('save')->once()->andReturnUsing(static function () use ($invoice): bool {
            self::assertSame('paid', $invoice->status);

            return true;
        });

        $transaction = new Transaction(['amount' => 75, 'currency_code' => 'USD']);
        $job = $this->getMockBuilder(CreateBankingDocumentTransaction::class)
            ->setConstructorArgs([$invoice, [
                'amount' => 75, 'currency_code' => 'USD', 'currency_rate' => 1,
                'account_id' => 9, 'payment_method' => 'cash',
            ]])
            ->onlyMethods(['dispatch'])
            ->getMock();

        $dispatched = [];
        $job->expects($this->exactly(2))->method('dispatch')->willReturnCallback(
            static function ($command) use (&$dispatched, $transaction) {
                $dispatched[] = $command::class;

                if ($command instanceof CreateTransaction) {
                    $request = (new ReflectionProperty($command, 'request'))->getValue($command);
                    self::assertSame(42, $request['document_id']);
                    self::assertSame(1, $request['company_id']);

                    return $transaction;
                }

                self::assertInstanceOf(CreateDocumentHistory::class, $command);
                self::assertSame(0, (new ReflectionProperty($command, 'notify'))->getValue($command));
                self::assertSame(
                    money(75.0, 'USD')->format() . ' ' . trans_choice('general.payments', 1),
                    (new ReflectionProperty($command, 'description'))->getValue($command)
                );

                return null;
            }
        );

        self::assertSame($transaction, $job->handle());
        self::assertSame([CreateTransaction::class, CreateDocumentHistory::class], $dispatched);
        Event::assertDispatchedTimes(DocumentTransactionCreating::class, 1);
        Event::assertDispatchedTimes(DocumentTransactionCreated::class, 1);
    }

    private function invoice(): Document
    {
        $invoice = new Document();
        $this->fillInvoice($invoice);

        return $invoice;
    }

    private function fillInvoice(Document $invoice): void
    {
        $invoice->forceFill([
            'id' => 42, 'company_id' => 1, 'contact_id' => 3, 'category_id' => 4,
            'amount' => 100, 'status' => 'partial', 'currency_code' => 'USD', 'currency_rate' => 1,
        ]);
        $invoice->setRelation('transactions', new Collection([
            new Transaction(['amount' => 25, 'currency_code' => 'USD', 'currency_rate' => 1]),
        ]));
    }
}

final class PaymentPreparationProbe extends CreateBankingDocumentTransaction
{
    public function preparedRequest(): array
    {
        $this->prepareRequest();

        return $this->request->all();
    }
}
