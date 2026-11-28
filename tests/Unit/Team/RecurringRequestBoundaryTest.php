<?php

namespace Tests\Unit\Team;

use App\Http\Requests\Banking\Transaction;
use App\Http\Requests\Document\Document;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RecurringRequestBoundaryTest extends TestCase
{
    #[DataProvider('boundaries')]
    public function testRecurringBoundary(
        string $requestClass,
        string $field,
        string $value,
        bool $expectedValid
    ): void {
        $invoice = $requestClass === Document::class;
        $input = [
            'company_id' => 1,
            'type' => $invoice ? 'invoice' : 'income',
            'recurring_frequency' => 'custom',
            'recurring_custom_frequency' => 'monthly',
            'recurring_started_at' => '2026-11-27 12:00:00',
            'recurring_interval' => '1',
        ];

        if ($field === 'recurring_limit_count') {
            $input['recurring_limit'] = 'count';
        } elseif ($field === 'recurring_limit_date') {
            $input['recurring_limit'] = 'date';
        }
        $input[$field] = $value;

        $request = $requestClass::create($invoice ? '/api/documents' : '/api/transactions', 'POST', $input);
        $request->setContainer($this->app);
        $rules = array_filter(
            $request->rules(),
            static fn (string $key): bool => str_starts_with($key, 'recurring_'),
            ARRAY_FILTER_USE_KEY
        );

        $validator = Validator::make($input, $rules);
        self::assertSame($expectedValid, $validator->passes());
        self::assertSame($expectedValid ? [] : [$field], $validator->errors()->keys());
    }

    public static function boundaries(): iterable
    {
        $values = [
            'recurring_interval' => ['0', '1', '2'],
            'recurring_limit_count' => ['-1', '0', '1'],
            'recurring_limit_date' => [
                '2026-11-27 11:59:59',
                '2026-11-27 12:00:00',
                '2026-11-27 12:00:01',
            ],
        ];

        foreach (['invoice' => Document::class, 'payment' => Transaction::class] as $name => $class) {
            foreach ($values as $field => $boundary) {
                foreach (['below', 'at', 'above'] as $index => $position) {
                    yield "$name $field $position" => [$class, $field, $boundary[$index], $index !== 0];
                }
            }
        }
    }
}
