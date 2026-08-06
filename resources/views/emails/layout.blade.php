{{--
    Shell HTML de TODOS os e-mails do app — o design system traduzido para o que
    cliente de e-mail entende.

    Por que não dá para reusar o CSS do app:
      • `var(--brand-600)` não existe em Outlook, Gmail app e boa parte do resto:
        custom property em e-mail é aposta perdida. Os tokens viram HEX LITERAL aqui,
        copiados do :root do design-system.css.
      • folha externa (@vite) nunca chega: o cliente só recebe o HTML da mensagem.
      • flex/grid são irregulares — por isso o layout é feito com <table>, que é o
        único que todo mundo renderiza igual desde sempre.
      • Sora/Plus Jakarta não carregam (web font é bloqueada na maioria): a pilha cai
        em fontes de sistema, que é o comportamento correto e legível.

    Tema: SEMPRE claro, como as telas de auth. Cliente de e-mail não avisa a
    preferência do usuário de forma confiável e um "dark mode" mal aplicado costuma
    inverter só metade da mensagem.

    Espera: $titulo, $preheader, $saudacao, $paragrafos (array), $detalhes (array
    rótulo => valor), $acaoUrl/$acaoRotulo (opcionais) e $rodapeAviso.
--}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $titulo }}</title>
</head>
<body style="margin:0; padding:0; background-color:#EFF4F1; -webkit-font-smoothing:antialiased;">

    {{-- Preheader: o trecho que a caixa de entrada mostra ao lado do assunto. Sem ele,
         o cliente puxa o primeiro texto visível (que aqui seria "Stabil Money"). --}}
    <div style="display:none; font-size:1px; color:#EFF4F1; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden;">
        {{ $preheader }}&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#EFF4F1;">
        <tr>
            <td align="center" style="padding:32px 16px;">

                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px;">

                    {{-- Cabeçalho: bloco verde da marca (mesmo verde da sidebar do app) --}}
                    <tr>
                        <td style="background-color:#0C3D2B; border-radius:16px 16px 0 0; padding:26px 32px;">
                            <span style="font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:19px; font-weight:700; letter-spacing:.14em; color:#EAF5EF;">Stabil<span style="color:#6FCDA8;">Money</span></span>
                        </td>
                    </tr>

                    {{-- Corpo --}}
                    <tr>
                        <td style="background-color:#FFFFFF; padding:32px; font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">

                            <h1 style="margin:0 0 18px; font-size:21px; line-height:1.3; font-weight:600; color:#112019;">{{ $titulo }}</h1>

                            <p style="margin:0 0 16px; font-size:15px; line-height:1.6; color:#46584F;">{{ $saudacao }}</p>

                            @foreach ($paragrafos as $paragrafo)
                                <p style="margin:0 0 16px; font-size:15px; line-height:1.6; color:#46584F;">{!! $paragrafo !!}</p>
                            @endforeach

                            {{-- Detalhes do evento (quando, de onde). É o que permite a alguém
                                 dizer "isto não fui eu" com alguma base. --}}
                            @if (!empty($detalhes))
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 22px; background-color:#F6FAF8; border:1px solid #E5ECE8; border-radius:12px;">
                                    @foreach ($detalhes as $rotulo => $valor)
                                        <tr>
                                            <td style="padding:11px 16px; font-size:13px; color:#7C8C84; font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif; white-space:nowrap;">{{ $rotulo }}</td>
                                            <td style="padding:11px 16px; font-size:13px; color:#112019; font-weight:600; font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif; text-align:right;">{{ $valor }}</td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endif

                            {{-- Botão: <table> e não <a> com padding, porque o Outlook ignora
                                 padding em elemento inline e o botão viraria um link solto. --}}
                            @if (!empty($acaoUrl))
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:4px 0 24px;">
                                    <tr>
                                        <td style="background-color:#15795A; border-radius:13px;">
                                            <a href="{{ $acaoUrl }}" style="display:inline-block; padding:13px 26px; font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:14.5px; font-weight:600; color:#FFFFFF; text-decoration:none;">{{ $acaoRotulo }}</a>
                                        </td>
                                    </tr>
                                </table>
                                {{-- O endereço em texto: cliente que bloqueia link ainda deixa copiar. --}}
                                <p style="margin:0 0 20px; font-size:12.5px; line-height:1.6; color:#7C8C84; word-break:break-all;">
                                    Se o botão não funcionar, copie este endereço no navegador:<br>{{ $acaoUrl }}
                                </p>
                            @endif

                            @if (!empty($rodapeAviso))
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:6px; background-color:#FCEDEA; border:1px solid #F4C7BF; border-radius:12px;">
                                    <tr>
                                        <td style="padding:14px 16px; font-size:13.5px; line-height:1.55; color:#B4422F; font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
                                            {!! $rodapeAviso !!}
                                        </td>
                                    </tr>
                                </table>
                            @endif
                        </td>
                    </tr>

                    {{-- Rodapé --}}
                    <tr>
                        <td style="background-color:#FFFFFF; border-radius:0 0 16px 16px; border-top:1px solid #EFF3F1; padding:22px 32px 26px; font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
                            <p style="margin:0 0 6px; font-size:12.5px; line-height:1.6; color:#7C8C84;">
                                Este é um aviso automático de segurança da sua conta no Stabil&nbsp;Money.
                                <strong style="color:#46584F;">Nunca pedimos sua senha por e-mail.</strong>
                            </p>
                            <p style="margin:0; font-size:12.5px; line-height:1.6; color:#7C8C84;">
                                Dúvidas: <a href="mailto:{{ config('legal.contact_email') }}" style="color:#15795A; text-decoration:none;">{{ config('legal.contact_email') }}</a>
                            </p>
                        </td>
                    </tr>
                </table>

            </td>
        </tr>
    </table>
</body>
</html>
