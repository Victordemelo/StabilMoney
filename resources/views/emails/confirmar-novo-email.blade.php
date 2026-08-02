Olá, {{ $user->name }}.

Você pediu para trocar o e-mail da sua conta no Stabil Money para {{ $enderecoNovo }}.

Para confirmar, abra o link abaixo (vale por 2 horas):

{{ $url }}

Se não foi você, pode ignorar esta mensagem: nada muda sem essa confirmação, e o acesso
à conta continua pelo e-mail antigo.

--
Stabil Money
Dúvidas: {{ config('legal.contact_email') }}
