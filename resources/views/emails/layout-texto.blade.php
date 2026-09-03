{{--
    Versão em TEXTO PURO de todo e-mail do app, enviada junto com a HTML (multipart).

    Não é enfeite: filtro de spam desconfia de mensagem só-HTML, leitor de tela e cliente
    em modo texto mostram esta parte, e um HTML que não renderize deixa a mensagem
    ilegível sem ela. O conteúdo é o MESMO — quem lê só o texto não perde informação.

    Mesmas variáveis do layout HTML; `$paragrafosTexto` já vem sem marcação.
--}}
{{ $titulo }}
{{ str_repeat('=', mb_strlen($titulo)) }}

{{ $saudacao }}
@foreach ($paragrafosTexto as $paragrafo)

{{ $paragrafo }}
@endforeach
@foreach ($secoes ?? [] as $secao)

{{ mb_strtoupper($secao['titulo']) }}
@foreach ($secao['itens'] as $item)
- {{ $item['nome'] }} — {{ $item['quando'] }} — {{ $item['valor'] }}
@endforeach
@endforeach
@if (!empty($detalhes))

@foreach ($detalhes as $rotulo => $valor)
{{ $rotulo }}: {{ $valor }}
@endforeach
@endif
@if (!empty($acaoUrl))

{{ $acaoRotulo }}:
{{ $acaoUrl }}
@endif
@if (!empty($rodapeAvisoTexto))

! {{ $rodapeAvisoTexto }}
@endif

--
{{ $rodapeNotaTexto ?? 'Stabil Money — aviso automático de segurança.' }}
Nunca pedimos sua senha por e-mail.
Dúvidas: {{ config('legal.contact_email') }}
