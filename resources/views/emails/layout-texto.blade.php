{{--
    Versão em TEXTO PURO de todo e-mail do app, enviada junto com a HTML (multipart).

    Não é enfeite: filtro de spam desconfia de mensagem só-HTML, leitor de tela e cliente
    em modo texto mostram esta parte, e um HTML que não renderize deixa a mensagem
    ilegível sem ela. O conteúdo é o MESMO — quem lê só o texto não perde informação.

    Mesmas variáveis do layout HTML; `$paragrafosTexto`, `$rodapeAvisoTexto` e
    `$rodapeNotaTexto` já vêm sem marcação (App\Mail\Concerns\TextoSemMarcacao).

    Tudo aqui sai CRU (`{!! !!}`), e é seguro: texto puro não interpreta marcação — um
    `<b>` nesta parte é só um sinal de menor, um "b" e um de maior. Com `{{ }}` o Blade
    escapava para HTML o que já era texto, e quem lia esta parte recebia
    `Use &quot;Esqueci a senha&quot;` e `Olá, Ana &lt;Teste&gt; &amp; Cia` (achado E-1 da
    auditoria de 07/09/2026). Pior: o link saía com `&amp;signature=`, e a assinatura do
    link de confirmação não conferia mais — quem clicava na versão texto recebia "este link
    expirou". O escape de dado do usuário é assunto da parte HTML (layout.blade.php).
--}}
{!! $titulo !!}
{!! str_repeat('=', mb_strlen($titulo)) !!}

{!! $saudacao !!}
@foreach ($paragrafosTexto as $paragrafo)

{!! $paragrafo !!}
@endforeach
@foreach ($secoes ?? [] as $secao)

{!! mb_strtoupper($secao['titulo']) !!}
@foreach ($secao['itens'] as $item)
- {!! $item['nome'] !!} — {!! $item['quando'] !!} — {!! $item['valor'] !!}
@endforeach
@endforeach
@if (!empty($detalhes))

@foreach ($detalhes as $rotulo => $valor)
{!! $rotulo !!}: {!! $valor !!}
@endforeach
@endif
@if (!empty($acaoUrl))

{!! $acaoRotulo !!}:
{!! $acaoUrl !!}
@endif
@if (!empty($rodapeAvisoTexto))

! {!! $rodapeAvisoTexto !!}
@endif

--
{!! $rodapeNotaTexto ?? 'Stabil Money — aviso automático de segurança.' !!}
Nunca pedimos sua senha por e-mail.
Dúvidas: {!! config('legal.contact_email') !!}
