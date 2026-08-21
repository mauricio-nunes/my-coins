<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

class OfxParser
{
    public function parse(string $contents): array
    {
        if (trim($contents) === '') {
            throw new InvalidArgumentException('O arquivo OFX está vazio.');
        }

        $contents = $this->toUtf8($contents);
        if (! preg_match('/<OFX>/i', $contents)) {
            throw new InvalidArgumentException('O arquivo não contém uma estrutura OFX válida.');
        }

        $currency = strtoupper($this->field($contents, 'CURDEF') ?? '');
        if ($currency !== 'BRL') {
            throw new InvalidArgumentException('A importação aceita somente extratos em BRL.');
        }

        preg_match_all('/<STMTTRN>(.*?)<\/STMTTRN>/is', $contents, $matches);
        if (empty($matches[1])) {
            throw new InvalidArgumentException('Nenhuma movimentação foi encontrada no arquivo OFX.');
        }
        if (count($matches[1]) > 500) {
            throw new InvalidArgumentException('O arquivo pode conter no máximo 500 movimentações.');
        }

        return array_map(fn (string $block, int $index): array => $this->transaction($block, $index), $matches[1], array_keys($matches[1]));
    }

    private function transaction(string $block, int $index): array
    {
        $number = $index + 1;
        $ofxType = strtoupper($this->requiredField($block, 'TRNTYPE', $number));
        if (! in_array($ofxType, ['CREDIT', 'DEBIT'], true)) {
            throw new InvalidArgumentException("A movimentação {$number} possui um tipo não suportado ({$ofxType}).");
        }

        $rawDate = $this->requiredField($block, 'DTPOSTED', $number);
        if (! preg_match('/^(\d{8})/', $rawDate, $dateMatch)) {
            throw new InvalidArgumentException("A movimentação {$number} possui uma data inválida.");
        }
        $date = CarbonImmutable::createFromFormat('!Ymd', $dateMatch[1]);
        $dateErrors = CarbonImmutable::getLastErrors();
        if (! $date || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
            throw new InvalidArgumentException("A movimentação {$number} possui uma data inválida.");
        }

        $rawAmount = trim($this->requiredField($block, 'TRNAMT', $number));
        if (! preg_match('/^([+-]?)(\d+)(?:\.(\d{1,2}))?$/', $rawAmount, $amountMatch)) {
            throw new InvalidArgumentException("A movimentação {$number} possui um valor inválido.");
        }
        $amount = ((int) $amountMatch[2] * 100) + (int) str_pad($amountMatch[3] ?? '', 2, '0');
        $isNegative = ($amountMatch[1] ?? '') === '-';
        if ($amount <= 0 || ($ofxType === 'DEBIT') !== $isNegative) {
            throw new InvalidArgumentException("A movimentação {$number} possui tipo e valor incompatíveis.");
        }

        $fitId = trim($this->requiredField($block, 'FITID', $number));
        $checkNumber = trim($this->requiredField($block, 'CHECKNUM', $number));
        $memo = preg_replace('/\s+/u', ' ', trim($this->field($block, 'MEMO') ?? '')) ?? '';

        return [
            'ofx_type' => $ofxType,
            'type' => $ofxType === 'CREDIT' ? 'income' : 'expense',
            'date' => $date->format('Y-m-d'),
            'amount' => $amount,
            'description' => mb_substr($memo !== '' ? $memo : 'Transação OFX', 0, 120),
            'fitid' => mb_substr($fitId, 0, 500),
            'checknum' => mb_substr($checkNumber, 0, 120),
        ];
    }

    private function requiredField(string $contents, string $tag, int $number): string
    {
        $value = $this->field($contents, $tag);
        if ($value === null || trim($value) === '') {
            throw new InvalidArgumentException("A movimentação {$number} não possui o campo {$tag}.");
        }

        return $value;
    }

    private function field(string $contents, string $tag): ?string
    {
        return preg_match('/<'.preg_quote($tag, '/').'>\s*([^<\r\n]*)/i', $contents, $match)
            ? trim($match[1])
            : null;
    }

    private function toUtf8(string $contents): string
    {
        preg_match('/^CHARSET:\s*([^\r\n]+)/mi', $contents, $match);
        $charset = strtoupper(trim($match[1] ?? ''));
        $source = match ($charset) {
            '1252', 'WINDOWS-1252' => 'Windows-1252',
            'ISO-8859-1', 'LATIN1' => 'ISO-8859-1',
            default => 'UTF-8',
        };

        if ($source !== 'UTF-8' || ! mb_check_encoding($contents, 'UTF-8')) {
            return mb_convert_encoding($contents, 'UTF-8', $source === 'UTF-8' ? 'Windows-1252' : $source);
        }

        return $contents;
    }
}
