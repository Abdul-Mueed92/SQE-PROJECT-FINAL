<?php

namespace Tests\Unit\Team;

use App\Http\Requests\Banking\Transaction;
use App\Http\Requests\Document\Document;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RecurringRequestDecisionTest extends TestCase
{
    #[DataProvider('combinations')]
    public function testRecurringRuleSelection(
        string $requestClass,
        ?string $frequency,
        ?string $limit
    ): void {
        $invoice = $requestClass === Document::class;
        $input = ['company_id' => 1, 'type' => $invoice ? 'invoice' : 'income'];

        if ($frequency !== null) {
            $input['recurring_frequency'] = $frequency;
        }
        if ($limit !== null) {
            $input['recurring_limit'] = $limit;
        }

        $request = $requestClass::create($invoice ? '/api/documents' : '/api/transactions', 'POST', $input);
        $request->setContainer($this->app);
        $rules = $request->rules();

        $fields = [
            'recurring_started_at' => $frequency === null ? null : 'required|date_format:Y-m-d H:i:s',
            'recurring_interval' => $frequency === 'custom' ? 'required|gte:1' : 'exclude_unless:recurring_frequency,custom|gt:0',
            'recurring_custom_frequency' => $frequency === 'custom' ? 'required|string|in:daily,weekly,monthly,yearly' : null,
            'recurring_limit_date' => $frequency !== null && $limit === 'date'
                ? 'required|date_format:Y-m-d H:i:s|after_or_equal:recurring_started_at' : null,
            'recurring_limit_count' => $frequency !== null && $limit === 'count' ? 'required|gte:0' : null,
        ];

        foreach ($fields as $field => $expected) {
            if ($expected === null) {
                self::assertArrayNotHasKey($field, $rules);
            } else {
                self::assertSame($expected, $rules[$field], $field);
            }
        }
    }

    public static function combinations(): iterable
    {
        foreach (['invoice' => Document::class, 'payment' => Transaction::class] as $label => $class) {
            yield "$label R0 non-recurring" => [$class, null, null];
            yield "$label R1 standard no limit" => [$class, 'monthly', null];
            yield "$label R2 standard date" => [$class, 'monthly', 'date'];
            yield "$label R3 standard count" => [$class, 'monthly', 'count'];
            yield "$label R4 custom no limit" => [$class, 'custom', null];
            yield "$label R5 custom date" => [$class, 'custom', 'date'];
            yield "$label R6 custom count" => [$class, 'custom', 'count'];
        }
    }
}
