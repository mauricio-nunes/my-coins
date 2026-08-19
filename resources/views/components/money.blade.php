@props(['value', 'signed' => false])
@php($amount = (int) $value)
<span {{ $attributes }}>{{ $signed && $amount > 0 ? '+' : '' }}{{ \App\Support\Money::format($amount) }}</span>
