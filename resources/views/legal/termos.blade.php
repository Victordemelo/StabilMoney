@extends('layouts.legal')

@section('title', 'Termos de Uso — StabilMoney')

@section('content')
    <h1>Termos de Uso</h1>
    <p class="upd">Última atualização: 18 de junho de 2026</p>

    <p>O StabilMoney é um aplicativo de controle financeiro pessoal <strong>em fase de testes</strong>.
       Ao criar uma conta e usar o app, você concorda com estes termos.</p>

    <h2>1. Sobre o serviço</h2>
    <p>O StabilMoney ajuda você a registrar receitas, despesas, contas, cartões, categorias, metas e
       investimentos. Por estar em fase de testes, funcionalidades podem mudar, ficar temporariamente
       indisponíveis ou conter erros.</p>

    <h2>2. Sua conta</h2>
    <p>Você é responsável por manter sua senha em segurança e pelas informações que cadastra. Os dados
       financeiros que você insere servem ao seu controle pessoal e são de sua responsabilidade.</p>

    <h2>3. Uso das informações para testes</h2>
    <p>Durante esta fase, as informações que você cadastra podem ser usadas para
       <strong>operar, testar, validar e melhorar o aplicativo</strong>. Não vendemos seus dados nem os
       compartilhamos com terceiros para fins de marketing. Detalhes na
       <a href="{{ route('privacidade') }}">Política de Privacidade</a>.</p>

    <h2>4. Não é aconselhamento financeiro</h2>
    <p>O StabilMoney é uma ferramenta de organização. Ele não oferece aconselhamento financeiro, de
       investimento ou tributário. Decisões tomadas a partir das informações do app são de sua
       responsabilidade.</p>

    <h2>5. Limitação de responsabilidade</h2>
    <p>Por estar em testes, o serviço é fornecido "como está". Não nos responsabilizamos por perdas
       decorrentes de indisponibilidade, erros de cálculo ou perda de dados. Não dependa exclusivamente
       do app para decisões importantes.</p>

    <h2>6. Encerramento</h2>
    <p>Você pode excluir sua conta a qualquer momento em <em>Configurações › Conta</em>. Podemos
       suspender contas que violem estes termos.</p>

    <h2>7. Contato</h2>
    <p>Dúvidas sobre estes termos? Fale com a gente pelo canal de suporte do projeto.</p>

    <div class="legal-note">Documento simplificado para a fase de testes do StabilMoney. Será revisado
        antes de um lançamento público amplo.</div>

    <a class="legal-back" href="{{ url('/') }}" onclick="if (history.length > 1) { history.back(); return false; }">← Voltar</a>
@endsection
