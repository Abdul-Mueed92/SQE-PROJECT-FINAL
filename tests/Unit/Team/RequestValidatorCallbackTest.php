<?php

namespace Tests\Unit\Team;

use App\Http\Requests\Banking\Transaction;
use App\Http\Requests\Document\Document;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RequestValidatorCallbackTest extends TestCase
{
    #[DataProvider('combinations')]
    public function testDateNormalization(
        string $requestClass,
        bool $hasErrors,
        bool $hasStart,
        bool $hasLimit
    ): void {
        $invoice = $requestClass === Document::class;
        $dates = $invoice
            ? ['issued_at' => '2026-11-27 09:00:00', 'due_at' => '2026-12-01 10:00:00']
            : ['paid_at' => '2026-11-27 11:00:00'];

        if ($hasStart) {
            $dates['recurring_started_at'] = '2026-11-28 12:00:00';
        }
        if ($hasLimit) {
            $dates['recurring_limit_date'] = '2026-12-28 13:00:00';
        }

        $request = $requestClass::create($invoice ? '/api/documents' : '/api/transactions', 'POST', $dates);
        $request->setContainer($this->app);

        // Seed a genuine Laravel validation error without involving unrelated fields.
        $validator = Validator::make([], $hasErrors ? ['marker' => 'required'] : []);
        self::assertSame(!$hasErrors, $validator->passes());

        $request->withValidator($validator);

        $expected = $hasErrors
            ? array_map(static fn (string $date): string => substr($date, 0, 10), $dates)
            : $dates;

        self::assertSame($expected, $request->request->all());
    }

    public static function combinations(): iterable
    {
        foreach (['invoice' => Document::class, 'payment' => Transaction::class] as $label => $class) {
            foreach ([false, true] as $errors) {
                foreach ([false, true] as $start) {
                    foreach ([false, true] as $limit) {
                        $id = sprintf('%s E%d S%d L%d', $label, $errors, $start, $limit);
                        yield $id => [$class, $errors, $start, $limit];
                    }
                }
            }
        }
    }
}
