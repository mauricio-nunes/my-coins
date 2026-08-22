<?php

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class TagFlowTest extends TestCase
{
    private function authenticated(): static
    {
        return $this->signInWithFinanceData();
    }

    private function transactionPayload(array $overrides = []): array
    {
        return array_merge([
            'description' => 'Hotel da viagem',
            'type' => 'expense',
            'amount' => '350,00',
            'date' => now()->format('Y-m-d'),
            'account_id' => 1,
            'category_id' => $this->categoryId('Lazer e compras'),
            'notes' => '',
        ], $overrides);
    }

    public function test_transaction_can_reuse_and_create_tags_inline(): void
    {
        $this->authenticated()->post('/transactions', $this->transactionPayload([
            'tags' => ['Essencial', ' Viagem ', 'viagem'],
        ]))->assertRedirect('/transactions/11');

        $transaction = Transaction::with('tags')->findOrFail(11);

        $this->assertCount(4, Tag::all());
        $this->assertSame([1, 4], $transaction->tags->pluck('id')->all());
        $this->get('/transactions/11')->assertOk()->assertSee('#Essencial')->assertSee('#Viagem');
    }

    public function test_transactions_can_be_filtered_by_all_selected_tags(): void
    {
        $this->authenticated()->post('/transactions', $this->transactionPayload([
            'description' => 'Evento profissional',
            'tags' => ['Trabalho', 'Fim de semana'],
        ]));

        $this->get('/transactions?tags%5B0%5D=2&tags%5B1%5D=3')
            ->assertOk()
            ->assertSee('Evento profissional')
            ->assertDontSee('Projeto freelance')
            ->assertDontSee('Cinema');
    }

    public function test_report_can_be_filtered_by_one_tag(): void
    {
        $response = $this->authenticated()->get('/reports?tags%5B0%5D=1');

        $response->assertOk()
            ->assertViewHas('report', function (array $report): bool {
                return $report['income'] === 1560000
                    && $report['expenses'] === 532450
                    && $report['result'] === 1027550
                    && $report['byCategory']->pluck('name')->all() === [
                        'Moradia',
                        'Alimentação',
                        'Saúde e cuidados pessoais',
                    ];
            })
            ->assertSee('value="1" selected', false);
    }

    public function test_report_requires_all_selected_tags(): void
    {
        $this->authenticated()->post('/transactions', $this->transactionPayload([
            'description' => 'Evento essencial de trabalho',
            'tags' => ['Essencial', 'Trabalho'],
        ]));

        $this->get('/reports?tags%5B0%5D=1&tags%5B1%5D=2')
            ->assertOk()
            ->assertViewHas('report', fn (array $report): bool => $report['income'] === 0
                && $report['expenses'] === 35000
                && $report['result'] === -35000
                && $report['transactions']->pluck('description')->all() === ['Evento essencial de trabalho']);
    }

    public function test_report_tag_filter_combines_with_period_account_and_category(): void
    {
        $this->authenticated();
        $today = CarbonImmutable::today();
        $query = http_build_query([
            'from' => $today->subDays(11)->format('Y-m-d'),
            'to' => $today->format('Y-m-d'),
            'account_id' => 1,
            'category_id' => $this->categoryId('Moradia'),
            'tags' => [1],
        ]);

        $this->get("/reports?{$query}")
            ->assertOk()
            ->assertViewHas('report', fn (array $report): bool => $report['income'] === 0
                && $report['expenses'] === 235000
                && $report['byCategory']->pluck('name')->all() === ['Moradia']);
    }

    public function test_report_rejects_invalid_tags_and_does_not_expose_another_users_data(): void
    {
        $this->authenticated()->get('/reports?tags%5B0%5D=invalid')
            ->assertSessionHasErrors('tags.0');

        $otherUser = User::factory()->create();
        $otherTag = Tag::create([
            'user_id' => $otherUser->id,
            'name' => 'Privada',
            'normalized_name' => 'privada',
        ]);

        $this->get('/reports?'.http_build_query(['tags' => [$otherTag->id]]))
            ->assertOk()
            ->assertDontSee('Privada')
            ->assertViewHas('report', fn (array $report): bool => $report['transactions']->isEmpty()
                && $report['income'] === 0
                && $report['expenses'] === 0);
    }

    public function test_free_text_search_includes_tag_names(): void
    {
        $this->authenticated()->get('/transactions?search=Trabalho')
            ->assertOk()
            ->assertSee('Projeto freelance')
            ->assertDontSee('Aluguel');
    }

    public function test_tag_can_be_created_and_renamed_from_management(): void
    {
        $this->authenticated()->post('/tags', ['name' => '  Casa   nova  '])
            ->assertRedirect('/tags');

        $this->assertSame('Casa nova', Tag::findOrFail(4)->name);

        $this->put('/tags/1', ['name' => 'Prioridade'])->assertRedirect('/tags');
        $this->get('/transactions/1')->assertSee('#Prioridade');
    }

    public function test_tag_names_are_unique_ignoring_case(): void
    {
        $this->authenticated()->post('/tags', ['name' => ' ESSENCIAL '])
            ->assertSessionHasErrors('name');

        $this->put('/tags/1', ['name' => 'trabalho'])
            ->assertSessionHasErrors('name');
    }

    public function test_editing_a_transaction_can_remove_all_tags(): void
    {
        $this->authenticated()->put('/transactions/1', $this->transactionPayload([
            'description' => 'Salário mensal',
            'type' => 'income',
            'amount' => '7800,00',
            'account_id' => 1,
            'category_id' => $this->categoryId('Trabalho'),
        ]))->assertRedirect('/transactions/1');

        $transaction = Transaction::findOrFail(1);
        $this->assertSame([], $transaction->tags()->pluck('tags.id')->all());
        $this->get('/transactions/1')->assertSee('Nenhuma tag');
    }

    public function test_tag_management_can_filter_unused_tags(): void
    {
        $this->authenticated()->post('/tags', ['name' => 'Sem uso']);

        $this->get('/tags?usage=unused')
            ->assertOk()
            ->assertSee('#Sem uso')
            ->assertDontSee('#Essencial');
    }

    public function test_deleting_a_tag_only_removes_its_transaction_associations(): void
    {
        $this->authenticated()->delete('/tags/1')->assertRedirect('/tags');

        $this->assertCount(10, Transaction::all());
        $this->assertSoftDeleted('tags', ['id' => 1]);
        $this->assertDatabaseMissing('tag_transaction', ['tag_id' => 1]);
    }

    public function test_transaction_rejects_more_than_ten_tags(): void
    {
        $tags = array_map(fn (int $number): string => "Tag {$number}", range(1, 11));

        $this->authenticated()->post('/transactions', $this->transactionPayload(['tags' => $tags]))
            ->assertSessionHasErrors('tags');
    }
}
