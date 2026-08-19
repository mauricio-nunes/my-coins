<ol class="import-steps list-unstyled mb-4" aria-label="Etapas da importação">
    @foreach([1 => ['Arquivo', 'Escolha o OFX'], 2 => ['Classificação', 'Revise os registros'], 3 => ['Conclusão', 'Confira o resultado']] as $number => [$title, $subtitle])
        <li class="import-step {{ $number < $step ? 'is-complete' : '' }} {{ $number === $step ? 'is-active' : '' }}" @if($number === $step) aria-current="step" @endif>
            <span class="import-step-number">@if($number < $step)<i class="bi bi-check-lg" aria-hidden="true"></i>@else{{ $number }}@endif</span>
            <span><strong>{{ $title }}</strong><small>{{ $subtitle }}</small></span>
        </li>
    @endforeach
</ol>
