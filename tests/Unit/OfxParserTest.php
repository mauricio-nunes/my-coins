<?php

namespace Tests\Unit;

use App\Services\OfxParser;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OfxParserTest extends TestCase
{
    public function test_it_parses_the_project_sample_file(): void
    {
        $rows = (new OfxParser)->parse(file_get_contents(dirname(__DIR__, 2).'/sample.ofx'));

        $this->assertCount(53, $rows);
        $this->assertSame('income', $rows[0]['type']);
        $this->assertSame('2026-08-03', $rows[0]['date']);
        $this->assertSame(1550000, $rows[0]['amount']);
        $this->assertSame('Ted-t Elet Disp Remet.nc7 Integradora Ltda', $rows[0]['description']);
        $this->assertNotSame('', $rows[0]['fitid']);
        $this->assertCount(45, array_filter($rows, fn (array $row): bool => $row['type'] === 'expense'));
    }

    #[DataProvider('invalidOfxProvider')]
    public function test_it_rejects_invalid_or_unsupported_files(string $contents, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (new OfxParser)->parse($contents);
    }

    public static function invalidOfxProvider(): array
    {
        return [
            'empty' => ['', 'vazio'],
            'not ofx' => ['arquivo comum', 'estrutura OFX válida'],
            'wrong currency' => ['<OFX><CURDEF>USD<STMTTRN></STMTTRN>', 'somente extratos em BRL'],
            'no transactions' => ['<OFX><CURDEF>BRL', 'Nenhuma movimentação'],
            'inconsistent value' => [self::ofx('<TRNTYPE>DEBIT<DTPOSTED>20260803000000[-03:EST]<TRNAMT>10.00<FITID>A-1<MEMO>Teste'), 'tipo e valor incompatíveis'],
        ];
    }

    private static function ofx(string $transaction): string
    {
        return "OFXHEADER:100\nCHARSET:1252\n\n<OFX><CURDEF>BRL<BANKTRANLIST><STMTTRN>{$transaction}</STMTTRN></BANKTRANLIST></OFX>";
    }
}
