<?php

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\Transaction;
use App\Services\FinanceStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OfxImportFlowTest extends TestCase
{
    private function authenticated(): static
    {
        return $this->signInWithFinanceData();
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
                0 => ['category_id' => $this->categoryId('Trabalho')],
                1 => ['is_transfer' => 1, 'destination_account_id' => 2],
            ],
        ])->assertRedirect('/transactions/import/result');

        $income = Transaction::where('ofx_fitid', 'CREDIT-001')->firstOrFail();
        $transfer = Transaction::where('ofx_fitid', 'DEBIT-001')->firstOrFail();
        $tag = Tag::where('normalized_name', 'extrato agosto')->firstOrFail();

        $this->assertSame('income', $income['type']);
        $this->assertSame(125050, $income['amount']);
        $this->assertSame('CREDIT-001', $income['notes']);
        $this->assertSame('CREDIT-CHECK-001', $income['ofx_checknum']);
        $this->assertSame([$tag->id], $income->tags()->pluck('tags.id')->all());
        $this->assertSame('transfer', $transfer['type']);
        $this->assertSame(1, $transfer['source_account_id']);
        $this->assertSame(2, $transfer['destination_account_id']);
        $this->assertNull($transfer['category_id']);
        $this->assertSame([$tag->id], $transfer->tags()->pluck('tags.id')->all());

        $this->get('/transactions/import/result')
            ->assertOk()
            ->assertSee('2')
            ->assertSee('#Extrato agosto')
            ->assertSee('Ver transações importadas');
    }

    public function test_preview_preselects_the_first_automatic_category_and_allows_manual_override(): void
    {
        $this->authenticated();
        $workId = $this->categoryId('Trabalho');
        app(FinanceStore::class)->syncCategoryKeywords($workId, ['pagamento cliente']);

        $this->uploadDraft()->assertRedirect('/transactions/import/review');
        $draft = session('my_coins.ofx_import_draft');
        $this->assertSame($workId, $draft['rows'][0]['suggested_category_id']);
        $this->assertSame('pagamento cliente', $draft['rows'][0]['suggested_keyword']);
        $this->assertNull($draft['rows'][1]['suggested_category_id']);

        $this->get('/transactions/import/review')
            ->assertOk()
            ->assertSee('Sugerida automaticamente')
            ->assertSee('Correspondência: pagamento cliente');

        $this->post('/transactions/import', [
            'draft_token' => $draft['token'],
            'rows' => [
                0 => ['category_id' => $this->categoryId('Benefícios')],
                1 => ['ignore' => 1],
            ],
        ])->assertRedirect('/transactions/import/result');

        $this->assertSame($this->categoryId('Benefícios'), Transaction::where('ofx_fitid', 'CREDIT-001')->value('category_id'));
    }

    public function test_overlapping_exports_with_different_fitids_are_blocked_by_the_full_fingerprint(): void
    {
        $this->uploadDraft();
        $draft = session('my_coins.ofx_import_draft');
        $this->post('/transactions/import', [
            'draft_token' => $draft['token'],
            'rows' => [0 => ['category_id' => $this->categoryId('Trabalho')], 1 => ['ignore' => 1]],
        ]);

        $secondExport = str_replace(
            ['<FITID>CREDIT-001', '<CHECKNUM>CREDIT-CHECK-001'],
            ['<FITID>CREDIT-NEW-EXPORT', '<CHECKNUM>credit-check-001'],
            $this->ofx(),
        );
        $this->uploadDraft($secondExport);
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
        $this->assertSame(1, Transaction::where('ofx_fitid', 'CREDIT-001')->count());
    }

    public function test_same_checknum_remains_distinct_when_transaction_fields_differ(): void
    {
        $ofx = str_replace('<CHECKNUM>DEBIT-CHECK-001', '<CHECKNUM>CREDIT-CHECK-001', $this->ofx());
        $this->uploadDraft($ofx);
        $draft = session('my_coins.ofx_import_draft');

        $this->assertFalse($draft['rows'][0]['duplicate']);
        $this->assertFalse($draft['rows'][1]['duplicate']);
        $this->post('/transactions/import', [
            'draft_token' => $draft['token'],
            'rows' => [
                0 => ['category_id' => $this->categoryId('Trabalho')],
                1 => ['category_id' => $this->categoryId('Alimentação')],
            ],
        ])->assertRedirect('/transactions/import/result');

        $this->assertSame(2, session('my_coins.ofx_import_result.imported'));
    }

    public function test_repeated_fingerprint_inside_one_file_is_locked_in_preview(): void
    {
        $ofx = $this->ofx();
        preg_match('/<STMTTRN>.*?<\/STMTTRN>/s', $ofx, $matches);
        $duplicate = str_replace('<FITID>CREDIT-001', '<FITID>CREDIT-SECOND-EXPORT', $matches[0]);
        $ofx = str_replace('</BANKTRANLIST>', $duplicate."\n</BANKTRANLIST>", $ofx);

        $this->uploadDraft($ofx);
        $draft = session('my_coins.ofx_import_draft');

        $this->assertFalse($draft['rows'][0]['duplicate']);
        $this->assertFalse($draft['rows'][1]['duplicate']);
        $this->assertTrue($draft['rows'][2]['duplicate']);
        $this->get('/transactions/import/review')->assertSee('1 duplicadas');
    }

    public function test_same_fingerprint_is_allowed_for_another_selected_account(): void
    {
        $this->uploadDraft();
        $firstDraft = session('my_coins.ofx_import_draft');
        $this->post('/transactions/import', [
            'draft_token' => $firstDraft['token'],
            'rows' => [0 => ['category_id' => $this->categoryId('Trabalho')], 1 => ['ignore' => 1]],
        ]);

        $this->authenticated()->post('/transactions/import/preview', [
            'bank_format' => 'bradesco',
            'account_id' => 2,
            'label' => 'Outra conta',
            'ofx_file' => UploadedFile::fake()->createWithContent('outra-conta.ofx', $this->ofx()),
        ]);
        $secondDraft = session('my_coins.ofx_import_draft');

        $this->assertFalse($secondDraft['rows'][0]['duplicate']);
    }

    public function test_a_deleted_ofx_transaction_can_be_imported_again(): void
    {
        $this->uploadDraft();
        $draft = session('my_coins.ofx_import_draft');
        $this->post('/transactions/import', [
            'draft_token' => $draft['token'],
            'rows' => [0 => ['category_id' => $this->categoryId('Trabalho')], 1 => ['ignore' => 1]],
        ]);
        $transaction = Transaction::where('ofx_fitid', 'CREDIT-001')->firstOrFail();
        $this->delete("/transactions/{$transaction->id}")->assertRedirect('/transactions');

        $this->uploadDraft();
        $newDraft = session('my_coins.ofx_import_draft');
        $this->assertFalse($newDraft['rows'][0]['duplicate']);
        $this->post('/transactions/import', [
            'draft_token' => $newDraft['token'],
            'rows' => [0 => ['category_id' => $this->categoryId('Trabalho')], 1 => ['ignore' => 1]],
        ])->assertRedirect('/transactions/import/result');

        $this->assertSame(1, Transaction::where('ofx_fitid', 'CREDIT-001')->count());
        $this->assertSame(2, Transaction::withTrashed()->where('ofx_fitid', 'CREDIT-001')->count());
    }

    public function test_import_requires_a_matching_category_and_valid_transfer_destination(): void
    {
        $this->uploadDraft();
        $draft = session('my_coins.ofx_import_draft');

        $this->post('/transactions/import', [
            'draft_token' => $draft['token'],
            'rows' => [
                0 => ['category_id' => $this->categoryId('Moradia')],
                1 => ['is_transfer' => 1, 'destination_account_id' => 1],
            ],
        ])->assertSessionHasErrors(['rows.0.category_id', 'rows.1.destination_account_id']);

        $this->assertCount(10, Transaction::all());
        $this->assertCount(3, Tag::all());
    }

    public function test_upload_validates_extension_account_currency_and_authentication(): void
    {
        $this->get('/transactions/import')->assertRedirect('/login');

        $this->authenticated()->post('/transactions/import/preview', [
            'bank_format' => 'bradesco',
            'account_id' => 999,
            'label' => 'Importação',
            'ofx_file' => UploadedFile::fake()->createWithContent('extrato.txt', $this->ofx()),
        ])->assertSessionHasErrors(['account_id']);

        $usd = str_replace('<CURDEF>BRL', '<CURDEF>USD', $this->ofx());
        $this->post('/transactions/import/preview', [
            'bank_format' => 'bradesco',
            'account_id' => 1,
            'label' => 'Importação',
            'ofx_file' => UploadedFile::fake()->createWithContent('extrato.ofx', $usd),
        ])->assertSessionHasErrors(['ofx_file']);

        $missingCheckNumber = preg_replace('/<CHECKNUM>[^\r\n]+\R/', '', $this->ofx(), 1);
        $this->post('/transactions/import/preview', [
            'bank_format' => 'bradesco',
            'account_id' => 1,
            'label' => 'Importação',
            'ofx_file' => UploadedFile::fake()->createWithContent('sem-checknum.ofx', $missingCheckNumber),
        ])->assertSessionHasErrors(['ofx_file']);
    }

    public function test_review_and_result_require_their_session_state(): void
    {
        $this->authenticated()->get('/transactions/import/review')
            ->assertRedirect('/transactions/import');
        $this->get('/transactions/import/result')
            ->assertRedirect('/transactions/import');
    }

    public function test_inter_uses_fitid_to_distinguish_similar_payments_and_detect_reimport(): void
    {
        $ofx = $this->interOfx();
        $this->uploadDraft($ofx, 'inter');
        $draft = session('my_coins.ofx_import_draft');

        $this->assertSame('Banco Inter', $draft['bank_name']);
        $this->assertSame('expense', $draft['rows'][0]['type']);
        $this->assertFalse($draft['rows'][0]['duplicate']);
        $this->assertFalse($draft['rows'][1]['duplicate']);

        $this->post('/transactions/import', [
            'draft_token' => $draft['token'],
            'rows' => [
                0 => ['category_id' => $this->categoryId('Alimentação')],
                1 => ['category_id' => $this->categoryId('Alimentação')],
            ],
        ])->assertRedirect('/transactions/import/result');

        $this->assertSame(2, session('my_coins.ofx_import_result.imported'));

        $this->uploadDraft($ofx, 'inter');
        $reimport = session('my_coins.ofx_import_draft');
        $this->assertTrue($reimport['rows'][0]['duplicate']);
        $this->assertTrue($reimport['rows'][1]['duplicate']);
    }

    public function test_upload_requires_the_bank_and_rejects_a_mismatched_format(): void
    {
        $this->authenticated()->post('/transactions/import/preview', [
            'account_id' => 1,
            'label' => 'Importação',
            'ofx_file' => UploadedFile::fake()->createWithContent('extrato.ofx', $this->ofx()),
        ])->assertSessionHasErrors(['bank_format']);

        $this->post('/transactions/import/preview', [
            'bank_format' => 'inter',
            'account_id' => 1,
            'label' => 'Importação',
            'ofx_file' => UploadedFile::fake()->createWithContent('extrato.ofx', $this->ofx()),
        ])->assertSessionHasErrors(['ofx_file']);
    }

    private function uploadDraft(?string $contents = null, string $bankFormat = 'bradesco'): TestResponse
    {
        return $this->authenticated()->post('/transactions/import/preview', [
            'bank_format' => $bankFormat,
            'account_id' => 1,
            'label' => 'Extrato agosto',
            'ofx_file' => UploadedFile::fake()->createWithContent('agosto.ofx', $contents ?? $this->ofx()),
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
<BANKID>0237
<CURDEF>BRL
<BANKTRANLIST>
<STMTTRN>
<TRNTYPE>CREDIT
<DTPOSTED>20260803000000[-03:EST]
<TRNAMT>1250.50
<FITID>CREDIT-001
<CHECKNUM>CREDIT-CHECK-001
<MEMO>Pagamento cliente
</STMTTRN>
<STMTTRN>
<TRNTYPE>DEBIT
<DTPOSTED>20260804000000[-03:EST]
<TRNAMT>-300.00
<FITID>DEBIT-001
<CHECKNUM>DEBIT-CHECK-001
<MEMO>Reserva mensal
</STMTTRN>
</BANKTRANLIST>
</OFX>
OFX;
    }

    private function interOfx(): string
    {
        return <<<'OFX'
OFXHEADER:100
DATA:OFXSGML
VERSION:102
ENCODING:USASCII
CHARSET:1252

<OFX>
<BANKID>077</BANKID>
<CURDEF>BRL</CURDEF>
<BANKTRANLIST>
<STMTTRN><TRNTYPE>PAYMENT</TRNTYPE><DTPOSTED>20260805000000[-03:EST]</DTPOSTED><TRNAMT>-75.00</TRNAMT><FITID>INTER-001</FITID><CHECKNUM>077</CHECKNUM><NAME>Compra</NAME><REFNUM>REF-001</REFNUM><MEMO>Compra semelhante</MEMO></STMTTRN>
<STMTTRN><TRNTYPE>PAYMENT</TRNTYPE><DTPOSTED>20260805000000[-03:EST]</DTPOSTED><TRNAMT>-75.00</TRNAMT><FITID>INTER-002</FITID><CHECKNUM>077</CHECKNUM><NAME>Compra</NAME><REFNUM>REF-002</REFNUM><MEMO>Compra semelhante</MEMO></STMTTRN>
</BANKTRANLIST>
</OFX>
OFX;
    }
}
