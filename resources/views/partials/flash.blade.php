{{-- Flash de sessão: banner discreto no topo do conteúdo (auto-dismiss em shell.js) --}}
@php
    // Mapeia os slugs padrão do Breeze para mensagens em PT-BR;
    // qualquer outra string flasheada é exibida como veio.
    $mensagensConhecidas = [
        'profile-updated' => 'Perfil atualizado com sucesso.',
        'password-updated' => 'Senha atualizada com sucesso.',
        'verification-link-sent' => 'Link de verificação enviado para o seu e-mail.',
    ];
    $flash = session('status') ?? session('success');
    if ($flash !== null) {
        $flash = $mensagensConhecidas[$flash] ?? $flash;
    }
@endphp
@if ($flash)
    <div class="flash" data-flash role="status">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 5-5.5"/></svg>
        {{ $flash }}
    </div>
@endif
