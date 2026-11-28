<?php
namespace Tests\Unit\Team;

use App\Http\Requests\Document\Document as InvoiceRequest;
use App\Http\Requests\Banking\Transaction as PaymentRequest;
use Tests\TestCase;

/** Real FormRequest::rules() decision branches, using the installed Laravel app. */
final class InvoicePaymentRequestRulesTest extends TestCase
{
    private function rulesFor(string $class, array $input): array
    {
        // Supplying company_id avoids reliance on a current-company lookup for the value.
        $request = $class::create('/api/documents', 'POST', array_merge(['company_id' => 1], $input));
        $request->setContainer($this->app);
        return $request->rules();
    }

    public function testInvoiceRequiredAndDateOrderingRules(): void
    {
        $rules = $this->rulesFor(InvoiceRequest::class, []);
        self::assertStringContainsString('required', $rules['document_number']);
        self::assertStringContainsString('before_or_equal:due_at', $rules['issued_at']);
        self::assertStringContainsString('after_or_equal:issued_at', $rules['due_at']);
        self::assertSame('required|gt:0', $rules['currency_rate']);
        self::assertSame('required|string', $rules['status']); // no enum enforced: observed gap
    }

    public function testInvoiceRecurringCustomDateDecision(): void
    {
        $rules = $this->rulesFor(InvoiceRequest::class, [
            'recurring_frequency' => 'custom', 'recurring_limit' => 'date',
        ]);
        self::assertSame('required|gte:1', $rules['recurring_interval']);
        self::assertStringContainsString('in:daily,weekly,monthly,yearly', $rules['recurring_custom_frequency']);
        self::assertStringContainsString('after_or_equal:recurring_started_at', $rules['recurring_limit_date']);
        self::assertArrayNotHasKey('recurring_limit_count', $rules);
    }

    public function testInvoiceRecurringCountDecision(): void
    {
        $rules = $this->rulesFor(InvoiceRequest::class, [
            'recurring_frequency' => 'monthly', 'recurring_limit' => 'count',
        ]);
        self::assertSame('required|gte:0', $rules['recurring_limit_count']);
        self::assertArrayNotHasKey('recurring_custom_frequency', $rules);
        self::assertArrayNotHasKey('recurring_limit_date', $rules);
    }

    public function testInvoiceNonRecurringDecision(): void
    {
        $rules = $this->rulesFor(InvoiceRequest::class, []);
        self::assertArrayNotHasKey('recurring_started_at', $rules);
        self::assertArrayNotHasKey('recurring_limit_count', $rules);
        self::assertArrayNotHasKey('recurring_limit_date', $rules);
    }

    public function testPaymentRequiredFieldsAndAmountRule(): void
    {
        $rules = $this->rulesFor(PaymentRequest::class, ['type' => 'income']);
        self::assertSame('required|amount:0', $rules['amount']);
        self::assertSame('nullable|integer', $rules['document_id']);
        self::assertSame('required|gt:0', $rules['currency_rate']);
        self::assertStringContainsString('required|integer|exists:accounts', $rules['account_id']);
        self::assertSame('required|string|payment_method', $rules['payment_method']);
    }

    public function testPaymentRecurringCustomCountDecision(): void
    {
        $rules = $this->rulesFor(PaymentRequest::class, [
            'type' => 'income', 'recurring_frequency' => 'custom', 'recurring_limit' => 'count',
        ]);
        self::assertSame('required|gte:1', $rules['recurring_interval']);
        self::assertSame('required|gte:0', $rules['recurring_limit_count']);
        self::assertArrayHasKey('recurring_started_at', $rules);
        self::assertArrayNotHasKey('recurring_limit_date', $rules);
    }

    public function testPaymentRecurringDateDecision(): void
    {
        $rules = $this->rulesFor(PaymentRequest::class, [
            'type' => 'income', 'recurring_frequency' => 'monthly', 'recurring_limit' => 'date',
        ]);
        self::assertStringContainsString('after_or_equal:recurring_started_at', $rules['recurring_limit_date']);
        self::assertArrayNotHasKey('recurring_limit_count', $rules);
    }
}
