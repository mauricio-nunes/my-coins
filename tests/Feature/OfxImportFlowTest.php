<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OfxImportFlowTest extends TestCase
{
    private function authenticated(): static
    {
        return $this->withSession(['demo_authenticated' => true]);
    }

    public function test_user_can_preview_and_import_income_and_a_transfer_with_one_tag(): void
    {
        $this->uploadDraft()->assertRedirect('/transactions/import/review');
        $draft = session('my_coins.ofx_import_draft');

        $this->get('/transactions/import/review')
            ->assertOk()
            ->assertSee('Pagamento cliente')
            ->assertSee('Reserva mensal')
            ->assertSee('#Extrato agosto');

        $this->post('/transactions/import', [
            'draft_token' => $draft['token'],
            'rows' => [
                0 => ['category_id' => 1],
                1 => ['is_transfer' => 1, 'destination_account_id' => 2],
            ],
        ])->assertRedirect('/transactions/import/result');

        $data = session('my_coins.demo_data');
        $income = collect($data['transactions'])->firstWhere('ofx_fitid', 'CREDIT-001');
        $transfer = collect($data['transactions'])->firstWhere('ofx_fitid', 'DEBIT-001');
        $tag = collect($data['tags'])->firstWhere('normalized_name', 'extrato agosto');

        $this->assertSame('income', $income['type']);
        $this->assertSame(125050, $income['amount']);
        $this->assertSame('CREDIT-001', $income['notes']);
        $this->assertSame([$tag['id']], $income['tag_ids']);
        $this->assertSame('transfer', $transfer['type']);
        $this->assertSame(1, $transfer['source_account_id']);
        $this->assertSame(2, $transfer['destination_account_id']);
        $this->assertNull($transfer['category_id']);
        $this->assertSame([$tag['id']], $transfer['tag_ids']);

        $this->get('/transactions/import/result')
            ->assertOk()
            ->assertSee('2')
            ->assertSee('#Extrato agosto')
            ->assertSee('Ver transações importadas');
    }

    public function test_fitids_already_imported_for_the_account_are_blocked(): void
    {
        $this->uploadDraft();
        $draft = session('my_coins.ofx_import_draft');
        $this->post('/transactions/import', [
            'draft_token' => $draft['token'],
            'rows' => [0 => ['category_id' => 1], 1 => ['ignore' => 1]],
        ]);

        $this->uploadDraft();
        $duplicateDraft = session('my_coins.ofx_import_draft');
        $this->assertTrue($duplicateDraft['rows'][0]['duplicate']);
        $this->assertFalse($duplicateDraft['rows'][1]['duplicate']);

        $this->get('/transactions/import/review')->assertSee('Já importada');
        $this->post('/transactions/import', [
            'draft_token' => $duplicateDraft['token'],
            'rows' => [1 => ['ignore' => 1]],
        ])->assertRedirect('/transactions/import/result');

        $this->assertSame(1, session('my_coins.ofx_import_result.duplicates'));
        $this->assertSame(0, session('my_coins.ofx_import_result.imported'));
        $this->assertCount(1, collect(session('my_coins.demo_data.transactions'))->where('ofx_fitid', 'CREDIT-001'));
    }

    public function test_import_requires_a_matching_category_and_valid_transfer_destination(): void
    {
        $this->uploadDraft();
        $draft = session('my_coins.ofx_import_draft');

        $this->post('/transactions/import', [
            'draft_token' => $draft['token'],
            'rows' => [
                0 => ['category_id' => 3],
                1 => ['is_transfer' => 1, 'destination_account_id' => 1],
            ],
        ])->assertSessionHasErrors(['rows.0.category_id', 'rows.1.destination_account_id']);

        $this->assertCount(10, session('my_coins.demo_data.transactions'));
        $this->assertCount(3, session('my_coins.demo_data.tags'));
    }

    public function test_upload_validates_extension_account_currency_and_authentication(): void
    {
        $this->get('/transactions/import')->assertRedirect('/login');

        $this->authenticated()->post('/transactions/import/preview', [
            'account_id' => 999,
            'label' => 'Importação',
            'ofx_file' => UploadedFile::fake()->createWithContent('extrato.txt', $this->ofx()),
        ])->assertSessionHasErrors(['account_id']);

        $usd = str_replace('<CURDEF>BRL', '<CURDEF>USD', $this->ofx());
        $this->post('/transactions/import/preview', [
            'account_id' => 1,
            'label' => 'Importação',
            'ofx_file' => UploadedFile::fake()->createWithContent('extrato.ofx', $usd),
        ])->assertSessionHasErrors(['ofx_file']);
    }

    public function test_review_and_result_require_their_session_state(): void
    {
        $this->authenticated()->get('/transactions/import/review')
            ->assertRedirect('/transactions/import');
        $this->get('/transactions/import/result')
            ->assertRedirect('/transactions/import');
    }

    private function uploadDraft(): TestResponse
    {
        return $this->authenticated()->post('/transactions/import/preview', [
            'account_id' => 1,
            'label' => 'Extrato agosto',
            'ofx_file' => UploadedFile::fake()->createWithContent('agosto.ofx', $this->ofx()),
        ]);
    }

    private function ofx(): string
    {
        return <<<'OFX'
OFXHEADER:100
DATA:OFXSGML
VERSION:102
SECURITY:NONE
ENCODING:USASCII
CHARSET:1252

<OFX>
<CURDEF>BRL
<BANKTRANLIST>
<STMTTRN>
<TRNTYPE>CREDIT
<DTPOSTED>20260803000000[-03:EST]
<TRNAMT>1250.50
<FITID>CREDIT-001
<MEMO>Pagamento cliente
</STMTTRN>
<STMTTRN>
<TRNTYPE>DEBIT
<DTPOSTED>20260804000000[-03:EST]
<TRNAMT>-300.00
<FITID>DEBIT-001
<MEMO>Reserva mensal
</STMTTRN>
</BANKTRANLIST>
</OFX>
OFX;
    }
}
