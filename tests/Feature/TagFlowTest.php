<?php

namespace Tests\Feature;

use Tests\TestCase;

class TagFlowTest extends TestCase
{
    private function authenticated(): static
    {
        return $this->withSession(['demo_authenticated' => true]);
    }

    private function transactionPayload(array $overrides = []): array
    {
        return array_merge([
            'description' => 'Hotel da viagem',
            'type' => 'expense',
            'amount' => '350,00',
            'date' => now()->format('Y-m-d'),
            'account_id' => 1,
            'category_id' => 6,
            'notes' => '',
        ], $overrides);
    }

    public function test_transaction_can_reuse_and_create_tags_inline(): void
    {
        $this->authenticated()->post('/transactions', $this->transactionPayload([
            'tags' => ['Essencial', ' Viagem ', 'viagem'],
        ]))->assertRedirect('/transactions/10');

        $data = session('my_coins.demo_data');
        $transaction = collect($data['transactions'])->firstWhere('id', 10);

        $this->assertCount(4, $data['tags']);
        $this->assertSame([1, 4], $transaction['tag_ids']);
        $this->get('/transactions/10')->assertOk()->assertSee('#Essencial')->assertSee('#Viagem');
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

        $this->assertSame('Casa nova', session('my_coins.demo_data.tags.3.name'));

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
            'category_id' => 1,
        ]))->assertRedirect('/transactions/1');

        $transaction = collect(session('my_coins.demo_data.transactions'))->firstWhere('id', 1);
        $this->assertSame([], $transaction['tag_ids']);
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

        $data = session('my_coins.demo_data');
        $this->assertCount(9, $data['transactions']);
        $this->assertFalse(collect($data['tags'])->contains('id', 1));
        $this->assertFalse(collect($data['transactions'])->contains(
            fn (array $transaction): bool => in_array(1, $transaction['tag_ids'], true),
        ));
    }

    public function test_transaction_rejects_more_than_ten_tags(): void
    {
        $tags = array_map(fn (int $number): string => "Tag {$number}", range(1, 11));

        $this->authenticated()->post('/transactions', $this->transactionPayload(['tags' => $tags]))
            ->assertSessionHasErrors('tags');
    }

    public function test_existing_session_data_without_tag_fields_is_upgraded(): void
    {
        $this->authenticated()->get('/dashboard');
        $data = session('my_coins.demo_data');
        unset($data['tags']);
        foreach ($data['transactions'] as &$transaction) {
            unset($transaction['tag_ids']);
        }
        unset($transaction);
        session()->put('my_coins.demo_data', $data);

        $this->get('/transactions')->assertOk();
        $this->assertSame([], session('my_coins.demo_data.tags'));
        $this->assertTrue(collect(session('my_coins.demo_data.transactions'))->every(
            fn (array $transaction): bool => $transaction['tag_ids'] === [],
        ));
    }
}
