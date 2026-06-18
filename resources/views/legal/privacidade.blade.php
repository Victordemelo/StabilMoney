@extends('layouts.legal')

@section('title', 'Política de Privacidade — StabilMoney')

@section('content')
    <h1>Política de Privacidade</h1>
    <p class="upd">Última atualização: 18 de junho de 2026</p>

    <p>Esta política explica, de forma simples, quais dados o StabilMoney coleta e como os usamos.
       O app está <strong>em fase de testes</strong>.</p>

    <h2>1. Dados que coletamos</h2>
    <ul>
        <li><strong>Cadastro:</strong> nome, e-mail e senha (a senha é guardada com <em>hash</em>, nunca em texto puro).</li>
        <li><strong>Perfil:</strong> telefone e foto, se você informar.</li>
        <li><strong>Financeiros:</strong> as contas, cartões, categorias, transações, metas e investimentos que você cadastra.</li>
        <li><strong>Técnicos:</strong> cookies essenciais para manter você conectado e lembrar preferências (como o tema).</li>
    </ul>

    <h2>2. Como usamos</h2>
    <p>Usamos seus dados apenas para <strong>fornecer o aplicativo e, nesta fase, testá-lo e melhorá-lo</strong>.
       Não vendemos seus dados nem os compartilhamos com terceiros para marketing.</p>

    <h2>3. Conta-família</h2>
    <p>Se você adicionar dependentes, eles compartilham a mesma visão financeira da família. Considere
       isso antes de adicionar alguém.</p>

    <h2>4. Segurança</h2>
    <p>Senhas são protegidas com hashing forte (argon2id). Ainda assim, por estar em testes, evite
       cadastrar dados sensíveis com os quais você não se sinta confortável em um ambiente de testes.</p>

    <h2>5. Seus direitos (LGPD)</h2>
    <p>Você pode acessar, corrigir e <strong>excluir</strong> seus dados a qualquer momento — excluir a
       conta (<em>Configurações › Conta</em>) remove seus dados financeiros associados. Para outras
       solicitações, fale com o suporte.</p>

    <h2>6. Retenção</h2>
    <p>Mantemos seus dados enquanto sua conta existir. Ao excluir a conta, os dados associados são removidos.</p>

    <h2>7. Contato</h2>
    <p>Dúvidas sobre privacidade? Fale com a gente pelo canal de suporte do projeto.</p>

    <div class="legal-note">Documento simplificado para a fase de testes. Ao consentir no cadastro, você
        concorda que suas informações sejam usadas para operar e testar o StabilMoney.</div>

    <a class="legal-back" href="{{ url('/') }}" onclick="if (history.length > 1) { history.back(); return false; }">← Voltar</a>
@endsection
