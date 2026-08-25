<?php

namespace Tests\Unit;

use App\Services\OfxParser;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OfxParserTest extends TestCase
{
    public function test_it_parses_bradesco_sgml_transactions(): void
    {
        $rows = (new OfxParser)->parse(self::bradescoOfx(), 'bradesco');

        $this->assertCount(2, $rows);
        $this->assertSame('bradesco', $rows[0]['bank_format']);
        $this->assertSame('income', $rows[0]['type']);
        $this->assertSame('2026-08-03', $rows[0]['date']);
        $this->assertSame(155000, $rows[0]['amount']);
        $this->assertSame('Pagamento cliente', $rows[0]['description']);
        $this->assertSame('expense', $rows[1]['type']);
    }

    public function test_it_parses_inter_xml_style_payment_as_an_expense(): void
    {
        $rows = (new OfxParser)->parse(self::interOfx(), 'inter');

        $this->assertCount(2, $rows);
        $this->assertSame('inter', $rows[1]['bank_format']);
        $this->assertSame('PAYMENT', $rows[1]['ofx_type']);
        $this->assertSame('expense', $rows[1]['type']);
        $this->assertSame(4590, $rows[1]['amount']);
        $this->assertSame('077', $rows[1]['checknum']);
    }

    public function test_it_rejects_a_file_from_a_bank_other_than_the_selected_bank(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('não corresponde ao formato Bradesco');

        (new OfxParser)->parse(self::interOfx(), 'bradesco');
    }

    #[DataProvider('invalidOfxProvider')]
    public function test_it_rejects_invalid_or_unsupported_files(string $contents, string $bank, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (new OfxParser)->parse($contents, $bank);
    }

    public static function invalidOfxProvider(): array
    {
        return [
            'empty' => ['', 'bradesco', 'vazio'],
            'not ofx' => ['arquivo comum', 'bradesco', 'estrutura OFX válida'],
            'missing bank id' => ['<OFX><CURDEF>BRL<STMTTRN></STMTTRN>', 'bradesco', 'não informa o código do banco'],
            'wrong currency' => ['<OFX><BANKID>0237<CURDEF>USD<STMTTRN></STMTTRN>', 'bradesco', 'somente extratos em BRL'],
            'no transactions' => ['<OFX><BANKID>0237<CURDEF>BRL', 'bradesco', 'Nenhuma movimentação'],
            'inconsistent value' => [self::singleTransaction('<TRNTYPE>DEBIT<DTPOSTED>20260803000000[-03:EST]<TRNAMT>10.00<FITID>A-1<CHECKNUM>C-1<MEMO>Teste'), 'bradesco', 'tipo e valor incompatíveis'],
            'missing check number' => [self::singleTransaction('<TRNTYPE>CREDIT<DTPOSTED>20260803000000[-03:EST]<TRNAMT>10.00<FITID>A-1<MEMO>Teste'), 'bradesco', 'não possui o campo CHECKNUM'],
            'unsupported inter type' => [str_replace('<TRNTYPE>PAYMENT</TRNTYPE>', '<TRNTYPE>OTHER</TRNTYPE>', self::interOfx()), 'inter', 'tipo não suportado (OTHER)'],
        ];
    }

    private static function singleTransaction(string $transaction): string
    {
        return "OFXHEADER:100\nCHARSET:1252\n\n<OFX><BANKID>0237<CURDEF>BRL<BANKTRANLIST><STMTTRN>{$transaction}</STMTTRN></BANKTRANLIST></OFX>";
    }

    private static function bradescoOfx(): string
    {
        $ofx = self::singleTransaction('<TRNTYPE>CREDIT<DTPOSTED>20260803000000[-03:EST]<TRNAMT>1550.00<FITID>B-1<CHECKNUM>100<MEMO>Pagamento cliente');

        return str_replace('</BANKTRANLIST>', '<STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20260804000000[-03:EST]<TRNAMT>-25.90<FITID>B-2<CHECKNUM>101<MEMO>Café</STMTTRN></BANKTRANLIST>', $ofx);
    }

    private static function interOfx(): string
    {
        return <<<'OFX'
OFXHEADER:100
CHARSET:1252

<OFX>
<BANKID>077</BANKID>
<CURDEF>BRL</CURDEF>
<BANKTRANLIST>
<STMTTRN><TRNTYPE>CREDIT</TRNTYPE><DTPOSTED>20260803000000[-03:EST]</DTPOSTED><TRNAMT>100.00</TRNAMT><FITID>I-1</FITID><CHECKNUM>077</CHECKNUM><NAME>Crédito</NAME><REFNUM>R-1</REFNUM><MEMO>Recebimento identificado</MEMO></STMTTRN>
<STMTTRN><TRNTYPE>PAYMENT</TRNTYPE><DTPOSTED>20260804000000[-03:EST]</DTPOSTED><TRNAMT>-45.90</TRNAMT><FITID>I-2</FITID><CHECKNUM>077</CHECKNUM><NAME>Pagamento</NAME><REFNUM>R-2</REFNUM><MEMO>Compra no débito</MEMO></STMTTRN>
</BANKTRANLIST>
</OFX>
OFX;
    }
}
