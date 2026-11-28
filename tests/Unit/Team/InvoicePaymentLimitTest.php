<?php

namespace Tests\Unit\Team;

use App\Events\Document\PaidAmountCalculated;
use App\Jobs\Banking\CreateBankingDocumentTransaction;
use App\Models\Banking\Transaction;
use App\Models\Document\Document;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Event;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\CreatesApplication;

final class InvoicePaymentLimitTest extends TestCase
{
    use CreatesApplication;

    #[DataProvider('paymentAmounts')]
    public function testPaymentAgainstRemainingBalance(
        string $currency,
        float $rate,
        float $amount,
        float $limit,
        ?string $expectedStatus,
        float $existingPayment = 25,
        float $credit = 0
    ): void {
        $settings = Mockery::mock();
        $settings->shouldReceive('get')->with('default.currency', null)->andReturn('USD');
        $this->app->instance('setting', $settings);

        $invoice = new Document([
            'amount' => 100,
            'status' => 'partial',
            'currency_code' => 'USD',
            'currency_rate' => 1,
        ]);
        $invoice->setRelation('transactions', new Collection([
            new Transaction(['amount' => $existingPayment, 'currency_code' => 'USD', 'currency_rate' => 1]),
        ]));
        $invoice->syncOriginal();

        if ($credit > 0) {
            Event::listen(PaidAmountCalculated::class, static function (PaidAmountCalculated $event) use ($credit): void {
                $event->model->paid_amount += $credit;
            });
        }

        $job = new class($invoice, [
            'amount' => $amount,
            'currency_code' => $currency,
            'currency_rate' => $rate,
        ]) extends CreateBankingDocumentTransaction {
            public function checkAmount(): bool
            {
                return parent::checkAmount();
            }
        };

        if ($expectedStatus === null) {
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage(trans('messages.error.over_payment', [
                'amount' => money($limit, $currency),
            ]));

            try {
                $job->checkAmount();
            } finally {
                self::assertSame('partial', $invoice->status);
                self::assertFalse($invoice->isDirty('status'));
            }

            return;
        }

        self::assertTrue($job->checkAmount());
        self::assertSame($expectedStatus, $invoice->status);
    }

    public static function paymentAmounts(): array
    {
        return [
            'USD below balance' => ['USD', 1, 74.99, 75, 'partial'],
            'USD at balance' => ['USD', 1, 75, 75, 'paid'],
            'USD above balance' => ['USD', 1, 75.01, 75, null],
            'EUR below converted balance' => ['EUR', 2, 149.98, 150, 'partial'],
            'EUR at converted balance' => ['EUR', 2, 150, 150, 'paid'],
            'EUR above converted balance' => ['EUR', 2, 150.02, 150, null],
            'USD exact fractional balance' => ['USD', 1, 75.35, 75.35, 'paid', 24.65],
            'EUR above fractional converted balance' => ['EUR', 2, 150.72, 150.70, null, 24.65],
            'an applied credit reduces the remaining balance' => ['USD', 1, 70, 70, 'paid', 25, 5],
        ];
    }
}
