<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AvatarController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DependentController;
use App\Http\Controllers\FaturaController;
use App\Http\Controllers\FixedBillController;
use App\Http\Controllers\GoalContributionController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\InvestmentContributionController;
use App\Http\Controllers\InvestmentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TwoFactorController;
use Illuminate\Support\Facades\Route;

// PWA — PÚBLICO de propósito (fora do 'auth'): o navegador lê o manifest
// para oferecer "Instalar" (inclusive na tela de login) e registra o
// service worker sem sessão. Nada aqui expõe dado do usuário.
Route::get('/site.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');
Route::get('/sw.js', [PwaController::class, 'serviceWorker'])->name('pwa.sw');
Route::view('/offline', 'pwa.offline')->name('pwa.offline');

// Páginas legais (Termos / Privacidade) — PÚBLICAS: o cadastro e o aviso de
// cookies linkam para elas, e o aceite obrigatório no registro deixa de ser um
// link morto (LGPD).
Route::view('/termos', 'legal.termos')->name('termos');
Route::view('/privacidade', 'legal.privacidade')->name('privacidade');

// Todas as telas do app exigem login (multiusuário desde a Fase 1) e e-mail confirmado.
//
// `verified` (item 13 do checklist de publicação) fecha um buraco concreto: sem ele, dá
// para cadastrar com o e-mail de OUTRA pessoa — e, como o "esqueci a senha" manda o link
// para aquele endereço, o dono do e-mail "recupera" a conta e vê os lançamentos de quem
// a criou.
//
// **Por que isto não tranca ninguém**, mesmo sem SMTP configurado: quem cria usuário só
// deixa `email_verified_at` nulo quando o app CONSEGUE enviar o link. Sem entrega
// (`App\Support\Mailer::entrega()` falso), o cadastro grava a data no ato, o dependente
// nasce verificado e a troca de e-mail no perfil também. Ou seja, o middleware fica
// inerte enquanto não houver mailer e passa a valer sozinho no dia em que houver — sem
// mudar código. Coberto por VerificacaoDeEmailTest.
//
// ⚠️ Ao criar uma rota que precise funcionar ANTES da confirmação (reenviar o link, sair
// da conta), coloque-a em routes/auth.php, fora deste grupo.
Route::middleware(['auth', 'verified'])->group(function () {
    // Dashboard (tela inicial)
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Token CSRF fresco da sessão atual. Usado pelo submit AJAX do lançamento
    // para se recuperar de um 419: quando o form é aberto OFFLINE (servido do
    // cache do service worker, com _token velho) e enviado depois que a conexão
    // volta, o token do cache já não bate; o cliente busca um token novo aqui e
    // refaz o POST. GET não precisa de proteção CSRF; fica atrás de 'auth' e
    // devolve só o token da própria sessão.
    Route::get('/csrf-token', fn () => response()->json(['token' => csrf_token()]))->name('csrf.token');

    // Recursos principais
    //
    // Transferência entre contas de caixa (corrente ↔ poupança). ANTES do resource:
    // `POST transactions/transferir` não colide com o resource (que só tem
    // `POST transactions`), mas fica registrado junto por clareza. `POST /transactions`
    // com `type=transfer` também transfere — é o caminho da fila offline.
    Route::post('transactions/transferir', [TransactionController::class, 'transfer'])
        ->name('transactions.transfer');
    Route::resource('transactions', TransactionController::class)->except('show');
    Route::resource('accounts', AccountController::class)->except('show');

    // ⚠️ ANTES do resource, sempre: `PATCH categories/{category}` casaria com
    // "ordenar" e o route-model binding devolveria 404. O Laravel resolve na
    // ordem de registro, então esta linha precisa vir primeiro.
    Route::patch('categories/ordenar', [CategoryController::class, 'ordenar'])
        ->name('categories.ordenar');
    Route::resource('categories', CategoryController::class)->except('show');

    // Foto de perfil — servida pelo app, não pelo symlink de storage/. Fica atrás de
    // `auth` e só entrega a foto de quem é da MESMA família (ver AvatarController).
    Route::get('/avatar/{user}', [AvatarController::class, 'show'])->name('avatar.show');

    // Meu perfil (dados pessoais: nome, e-mail, telefone, foto)
    Route::get('/meu-perfil', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/meu-perfil', [ProfileController::class, 'update'])->name('profile.update');
    // Valida a senha atual → limitada (ver 'senha' no AppServiceProvider). Com 2FA
    // ligado também confere o código do autenticador, então leva os DOIS limites:
    // `senha` conta a tentativa de senha e `dois-fatores`, a de código — um teto só
    // deixaria o atacante gastar toda a cota num dos dois campos.
    Route::delete('/meu-perfil', [ProfileController::class, 'destroy'])
        ->middleware(['throttle:senha', 'throttle:dois-fatores'])
        ->name('profile.destroy');

    // Confirmação do e-mail NOVO (link assinado enviado ao próprio endereço novo).
    Route::get('/meu-perfil/confirmar-email/{user}', [ProfileController::class, 'confirmEmail'])
        ->middleware('signed')
        ->name('profile.email.confirm');

    // Configurações (subabas: Segurança / Conta)
    Route::get('/configuracoes/{tab?}', [SettingsController::class, 'index'])->name('settings');

    // Conta: liga/desliga o lembrete de vencimento por e-mail (só o titular recebe,
    // então só ele muda). Preferência sem risco — não pede senha nem tem limite próprio.
    Route::patch('/configuracoes/lembretes', [SettingsController::class, 'atualizarLembretes'])
        ->name('settings.lembretes');

    // Segurança: encerrar as demais sessões/dispositivos conectados
    // Valida a senha atual → limitada (ver 'senha' no AppServiceProvider).
    Route::delete('/configuracoes/sessoes', [SecurityController::class, 'destroyOtherSessions'])
        ->middleware('throttle:senha')
        ->name('settings.sessions.destroy');

    // Verificação em duas etapas (2FA por app autenticador) — OPCIONAL: nasce desligada e
    // só o dono da conta liga, informando a senha atual. Ligar, desligar e trocar os
    // códigos de recuperação decidem quem entra na conta, então pedem senha e têm limite
    // (mesma razão de "encerrar outras sessões" e "excluir conta").
    Route::post('/configuracoes/2fa', [TwoFactorController::class, 'ativar'])
        ->middleware('throttle:senha')
        ->name('settings.2fa.ativar');

    // Confirmar não pede senha (a pessoa acabou de digitá-la para gerar o QR): o que ela
    // prova aqui é a posse do aparelho. Limite próprio, o mesmo do desafio do login.
    Route::post('/configuracoes/2fa/confirmar', [TwoFactorController::class, 'confirmar'])
        ->middleware('throttle:dois-fatores')
        ->name('settings.2fa.confirmar');

    Route::post('/configuracoes/2fa/codigos', [TwoFactorController::class, 'regerarCodigos'])
        ->middleware('throttle:senha')
        ->name('settings.2fa.codigos');

    Route::delete('/configuracoes/2fa', [TwoFactorController::class, 'desativar'])
        ->middleware('throttle:senha')
        ->name('settings.2fa.desativar');

    // Dependentes (conta-família) — só o titular gerencia
    Route::get('/dependentes', [DependentController::class, 'index'])->name('dependentes');
    Route::post('/dependentes', [DependentController::class, 'store'])->name('dependentes.store');
    Route::patch('/dependentes/{dependent}', [DependentController::class, 'update'])->name('dependentes.update');
    Route::delete('/dependentes/{dependent}', [DependentController::class, 'destroy'])->name('dependentes.destroy');

    // Metas (objetivos de poupança — modelo "cofrinho"). Compartilhadas na família.
    Route::get('/metas', [GoalController::class, 'index'])->name('metas.index');
    Route::post('/metas', [GoalController::class, 'store'])->name('metas.store');
    Route::patch('/metas/{meta}', [GoalController::class, 'update'])->name('metas.update');
    Route::delete('/metas/{meta}', [GoalController::class, 'destroy'])->name('metas.destroy');
    // Movimentações da meta: aporte (reserva) e resgate (devolve à conta).
    Route::post('/metas/{meta}/aportes', [GoalContributionController::class, 'store'])->name('metas.aportes.store');
    Route::post('/metas/{meta}/resgates', [GoalContributionController::class, 'withdraw'])->name('metas.resgates.store');

    // Investimentos (modelo "cofrinho" + metadados/projeções). Compartilhados na família.
    Route::get('/investimentos', [InvestmentController::class, 'index'])->name('investimentos.index');
    Route::post('/investimentos', [InvestmentController::class, 'store'])->name('investimentos.store');
    Route::patch('/investimentos/{investimento}', [InvestmentController::class, 'update'])->name('investimentos.update');
    Route::delete('/investimentos/{investimento}', [InvestmentController::class, 'destroy'])->name('investimentos.destroy');
    // Movimentações do investimento: aporte (reserva) e resgate (devolve à conta).
    Route::post('/investimentos/{investimento}/aportes', [InvestmentContributionController::class, 'store'])->name('investimentos.aportes.store');
    Route::post('/investimentos/{investimento}/resgates', [InvestmentContributionController::class, 'withdraw'])->name('investimentos.resgates.store');

    // Faturas / Despesas — faturas por cartão (parcelas/recorrência) + despesas
    // avulsas em conta. Compartilhado na família.
    Route::get('/faturas', [FaturaController::class, 'index'])->name('faturas.index');
    Route::post('/faturas/lancar', [FaturaController::class, 'store'])->name('faturas.lancar');
    Route::delete('/faturas/compra/{transaction}', [FaturaController::class, 'destroy'])
        ->name('faturas.compra.destroy');
    // Recorrência "infinita": pagar a ocorrência em aberto gera a próxima (+1 mês).
    Route::post('/faturas/recorrente/{transaction}/pagar', [FaturaController::class, 'pay'])
        ->name('faturas.recorrente.pagar');
    // Marcar a fatura de um cartão de crédito como paga (desconta do caixa escolhido).
    Route::post('/faturas/cartao/{account}/pagar', [FaturaController::class, 'payInvoice'])
        ->name('faturas.fatura.pagar');
    // Desfaz o pagamento: as compras voltam para a fatura e o dinheiro, para a conta.
    Route::delete('/faturas/quitacao/{transaction}', [FaturaController::class, 'estornarFatura'])
        ->name('faturas.fatura.estornar');

    // Contas fixas mensais (condomínio, aluguel, carro…). A listagem não tem
    // rota própria: as ocorrências aparecem como um bloco de /faturas.
    Route::post('/contas-fixas', [FixedBillController::class, 'store'])->name('contas-fixas.store');
    Route::patch('/contas-fixas/{conta}', [FixedBillController::class, 'update'])->name('contas-fixas.update');
    Route::delete('/contas-fixas/{conta}', [FixedBillController::class, 'destroy'])->name('contas-fixas.destroy');
    // Paga UMA competência (mês), no formato AAAA-MM.
    Route::post('/contas-fixas/{conta}/pagar/{competencia}', [FixedBillController::class, 'pay'])
        // Mês 01..12 de verdade: `\d{4}-\d{2}` aceitava 2026-13 e 2026-00, que o Carbon
        // convertia por overflow em jan/2027 e dez/2025 — pagamento debitado num mês que
        // não existe e invisível na tela.
        ->where('competencia', '\d{4}-(0[1-9]|1[0-2])')
        ->name('contas-fixas.pagar');
});

require __DIR__.'/auth.php';
