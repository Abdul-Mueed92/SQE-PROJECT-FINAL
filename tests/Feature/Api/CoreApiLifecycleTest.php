<?php

namespace Tests\Feature\Api;

use App\Models\Banking\Transaction;
use App\Models\Document\Document;
use Tests\Feature\FeatureTestCase;

class CoreApiLifecycleTest extends FeatureTestCase
{
    private array $headers = [
        'Authorization' => 'Basic dGVzdEBjb21wYW55LmNvbToxMjM0NTY=',
    ];

    public function testApiRejectsMissingCredentials(): void
    {
        $this->withExceptionHandling()
            ->getJson(route('api.ping'))
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate');
    }

    public function testAuthenticatedApiPing(): void
    {
        $this->withHeaders($this->headers)
            ->getJson(route('api.ping'))
            ->assertOk();
    }

    public function testInvoiceApiCreateReadUpdateDeleteLifecycle(): void
    {
        $payload = Document::factory()->invoice()->items()->raw(['status' => 'draft']);

        $created = $this->withHeaders($this->headers)
            ->postJson(route('api.documents.store', ['search' => 'type:invoice']), $payload)
            ->assertCreated()
            ->json('data');

        $id = $created['id'];
        $this->assertDatabaseHas('documents', ['id' => $id, 'status' => 'draft']);

        $this->withHeaders($this->headers)
            ->getJson(route('api.documents.show', $id))
            ->assertOk()
            ->assertJsonPath('data.id', $id);

        $payload['status'] = 'sent';
        $this->withHeaders($this->headers)
            ->putJson(route('api.documents.update', $id), $payload)
            ->assertOk()
            ->assertJsonPath('data.status', 'sent');

        $this->withHeaders($this->headers)
            ->deleteJson(route('api.documents.destroy', $id))
            ->assertNoContent();

        $this->assertSoftDeleted('documents', ['id' => $id]);
    }

    public function testInvoiceApiRejectsInvalidRelations(): void
    {
        $payload = Document::factory()->invoice()->items()->raw([
            'contact_id' => 999999,
            'category_id' => 999999,
        ]);

        $this->withExceptionHandling()
            ->withHeaders($this->headers)
            ->postJson(route('api.documents.store', ['search' => 'type:invoice']), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['contact_id', 'category_id']);
    }

    public function testTransactionApiCreatesIsoDatedIncome(): void
    {
        $payload = Transaction::factory()->income()->raw([
            'paid_at' => '2026-11-18 10:00:00',
        ]);

        $this->withHeaders($this->headers)
            ->postJson(route('api.transactions.store'), $payload)
            ->assertCreated();

        $this->assertDatabaseHas('transactions', ['number' => $payload['number']]);
    }
}
