@extends('layouts.app')

@section('title', 'Tutorial')

{{-- Tutorial (out/2026): o botão começa o tour guiado (sm/tutorial.js), que passa tela por tela
     destacando cada botão. Embaixo, o mesmo conteúdo por escrito — vale sem JavaScript e serve
     de consulta rápida. --}}
@section('content')
<section class="view settings-view">
    <div class="section-head">
        <h2>Tutorial</h2>
        <span class="sub">Conheça cada tela do app</span>
    </div>

    <div class="settings-body">
        <div class="card sec-card span12 tut-inicio">
            <div>
                <h3>Tour guiado</h3>
                <p class="sec-card-desc">Passamos por todas as telas e mostramos, botão por botão, para que serve cada coisa. Leva uns dois minutos; dá para sair a qualquer momento com Esc.</p>
            </div>
            <button class="btn-primary" type="button" data-tutorial-iniciar>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M8 5.5v13l10-6.5-10-6.5Z"/></svg>
                Começar o tour
            </button>
        </div>

        @php
            $telas = [
                ['Visão geral', 'dashboard', 'Seu saldo disponível, receitas, despesas e economia do período, gráficos e as movimentações recentes. O "+" no topo lança uma receita, despesa ou transferência em qualquer tela.'],
                ['Movimentações', 'transactions.index', 'Tudo o que entrou e saiu, com filtros por tipo, conta, categoria e período. O que já foi pago ou recebido não muda de valor: para corrigir, exclua e lance de novo.'],
                ['Contas a pagar', 'faturas.index', 'Contas fixas do mês, faturas dos cartões e despesas em conta. Pague, ajuste o valor previsto ou exclua (pedindo a sua senha).'],
                ['Metas', 'metas.index', 'Objetivos com aportes: o dinheiro guardado sai do disponível para não ser gasto sem querer.'],
                ['Investimentos', 'investimentos.index', 'Aplicações com projeção de rendimento e estimativa de IR e IOF.'],
                ['Contas e cartões', 'accounts.index', 'Contas de banco, cartões de crédito e débito, Pix e TED. Receita entra em conta; despesa sai por um cartão, Pix ou TED.'],
                ['Categorias', 'categories.index', 'Receitas e despesas em duas colunas: arraste para reordenar ou trocar o tipo.'],
                ['Configurações', 'settings', 'Senha, verificação em duas etapas, conta, registro de atividade e instalação no celular.'],
            ];
        @endphp
        @foreach ($telas as [$nome, $rota, $texto])
            <a class="card sec-card span6 tut-tela" href="{{ route($rota) }}">
                <h3>{{ $nome }}</h3>
                <p class="sec-card-desc">{{ $texto }}</p>
            </a>
        @endforeach
    </div>
</section>
@endsection
