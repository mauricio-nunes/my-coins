<?php

namespace App\Support;

use Illuminate\Http\Request;

final class TransactionListReturn
{
    public static function from(Request $request): ?string
    {
        $target = $request->input('return_to');
        if (! is_string($target) || $target === '') {
            return null;
        }

        $parts = parse_url($target);
        $expectedPath = parse_url(route('transactions.index'), PHP_URL_PATH);
        if ($parts === false
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || ($parts['path'] ?? '') !== $expectedPath) {
            return null;
        }

        return $parts['path'].(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
