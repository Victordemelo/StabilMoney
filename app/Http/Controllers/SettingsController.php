<?php

namespace App\Http\Controllers;

use App\Models\Atividade;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\AparelhoConfiavel;
use App\Support\BrowserSessions;
use App\Support\Mailer;
use App\Support\PeriodoDoFiltro;
use App\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Configurações da conta com navegação por subabas (server-routed).
 * Segurança = senha + sessões ativas; 2FA = verificação em duas etapas;
 * Conta = excluir conta; Atividade = quem mexeu em quê, onde e quando (out/2026).
 *
 * O 2FA ganhou aba própria em 06/08/2026: junto com senha e sessões ele fazia a
 * Segurança passar de duas telas de rolagem, e é um fluxo de configuração com
 * passos (QR, confirmação, códigos de recuperação) que merece a tela inteira.
 * (Permissões de dependentes entram aqui num subprojeto futuro.)
 */
class SettingsController extends Controller
{
    private const TABS = [
        'seguranca' => 'Segurança',
        '2fa' => '2FA',
        'conta' => 'Conta',
        'atividade' => 'Atividade',
        // Instalar o app (PWA) — aba própria desde out/2026 (antes era um card da aba Conta).
        'celular' => 'Instalar no celular',
    ];

    /** Linhas por página na aba Atividade. */
    private const POR_PAGINA = 25;

    public function index(Request $request, TwoFactorService $twoFactor, string $tab = 'seguranca'): View
    {
        abort_unless(array_key_exists($tab, self::TABS), 404);

        $user = $request->user();

        $data = [
            'user' => $user,
            'tab' => $tab,
            'tabs' => self::TABS,
        ];

        // A aba Segurança lista as sessões/dispositivos conectados.
        if ($tab === 'seguranca') {
            $data['sessions'] = BrowserSessions::forUser($request);
        }

        if ($tab === '2fa') {
            // QR e chave manual só existem enquanto a configuração do 2FA está em
            // andamento — passar do controller evita a view chamar service por conta.
            $data['qrCode'] = $user->doisFatoresPendente() ? $twoFactor->qrCodeSvg($user) : null;
            $data['chaveManual'] = $user->doisFatoresPendente()
                ? Totp::formatarSegredo($user->two_factor_secret)
                : null;

            // Sem mailer configurado não há "esqueci a senha" que entregue nada, então os
            // códigos de recuperação passam a ser a ÚNICA porta de volta de quem perder o
            // celular. Isso muda o texto do aviso, e a pessoa precisa saber antes de ligar.
            $data['semRecuperacaoPorEmail'] = ! Mailer::entrega();

            // Até quando ESTE navegador dispensa o código desta conta ("Confiar neste
            // aparelho por 7 dias", marcado no desafio do login) — null se não dispensa.
            $data['aparelhoConfiavelAte'] = AparelhoConfiavel::validoAte($request, $user);

            // Aparelhos com autenticador ligado na FAMÍLIA inteira. Quem divide a
            // conta divide o dinheiro, então saber quem já protegeu o próprio login
            // é informação útil — e cobra quem ainda não protegeu. Só nome, papel e
            // desde quando: nenhum segredo sai daqui (o `two_factor_secret` é
            // `encrypted` e não é lido nesta tela).
            $data['totpDaFamilia'] = User::familyOf($user->ownerId())
                ->orderBy('name')
                ->get()
                ->map(fn (User $membro) => (object) [
                    'nome' => $membro->name,
                    'euMesmo' => $membro->id === $user->id,
                    'papel' => $membro->isTitular() ? 'Titular' : ($membro->relationshipLabel() ?? 'Dependente'),
                    'ativo' => $membro->temDoisFatores(),
                    'desde' => $membro->two_factor_confirmed_at,
                    'avatar' => $membro->avatarUrl(),
                ]);
        }

        if ($tab === 'atividade') {
            $data += $this->atividade($request, $user);
        }

        return view('settings.index', $data);
    }

    /**
     * Aba "Atividade": quem mexeu em quê, onde e quando.
     *
     * O TITULAR vê a família inteira e pode filtrar por pessoa; o DEPENDENTE vê só o que ele
     * mesmo fez (`Atividade::visivelPara`) — o filtro de pessoa nem aparece para ele, e um
     * `?pessoa=` na URL é ignorado. Pessoa de OUTRA família na URL também é ignorada (nunca
     * aplicada): aceitá-la viraria sonda — mesma regra dos filtros das Movimentações.
     *
     * Sem N+1: o nome do autor está congelado na própria linha (`autor_nome`), então a lista
     * é UMA consulta paginada, mais a dos membros da família para o filtro.
     *
     * @return array<string, mixed>
     */
    private function atividade(Request $request, User $user): array
    {
        $membros = $user->isTitular()
            ? User::familyOf($user->ownerId())->get(['id', 'name', 'account_owner_id'])
            : collect();

        $pessoa = (int) $request->query('pessoa');
        $pessoa = $membros->contains('id', $pessoa) ? $pessoa : null;

        $grupo = $request->query('tipo');
        $grupo = is_string($grupo) && array_key_exists($grupo, Atividade::GRUPOS) ? $grupo : null;

        [$de, $ate] = PeriodoDoFiltro::ler($request);

        // `created_at` é DATETIME: o "até" vai até o fim do dia, comparando com o início do
        // dia seguinte (sem função na coluna — o índice continua valendo).
        $atividades = Atividade::visivelPara($user)
            ->when($pessoa, fn ($q) => $q->where('user_id', $pessoa))
            ->when($grupo, fn ($q) => $q->where('grupo', $grupo))
            ->when($de, fn ($q) => $q->where('created_at', '>=', $de->toDateTimeString()))
            ->when($ate, fn ($q) => $q->where('created_at', '<', $ate->addDay()->toDateTimeString()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();

        return [
            'atividades' => $atividades,
            'membros' => $membros,
            'grupos' => Atividade::GRUPOS,
            'filtroPessoa' => $pessoa,
            'filtroTipo' => $grupo,
            'filtroDe' => $de?->toDateString(),
            'filtroAte' => $ate?->toDateString(),
            'filtrando' => $pessoa || $grupo || $de || $ate,
        ];
    }

    /**
     * Fuso do relógio da topbar (out/2026). Preferência de exibição, como o tema: sem senha.
     * Não muda nenhuma data de dinheiro — essas seguem em Brasília.
     */
    public function atualizarRelogio(Request $request): RedirectResponse
    {
        $dados = $request->validate(
            ['timezone' => ['required', 'string', Rule::in(array_keys(User::FUSOS))]],
            ['timezone.in' => 'Escolha um fuso da lista.'],
        );

        $request->user()->forceFill(['timezone' => $dados['timezone']])->save();

        return redirect()->route('settings', 'conta')
            ->with('status', 'Relógio ajustado para '.User::FUSOS[$dados['timezone']].'.');
    }

    /**
     * Liga/desliga o lembrete de vencimento por e-mail (`lembretes:vencimentos`).
     *
     * Só o titular: é ele quem recebe (o dinheiro é da família e ele responde por ele),
     * então um dependente não tem o que ligar — 403 em vez de um toggle sem efeito.
     */
    public function atualizarLembretes(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isTitular(), 403);

        $dados = $request->validate(['reminder_emails' => ['required', 'boolean']]);
        $ligado = (bool) $dados['reminder_emails'];

        $user->forceFill(['reminder_emails' => $ligado]);

        // Só quando mudou de fato: reenviar o mesmo formulário não é uma ação nova. Medido
        // ANTES do save com `isDirty`: o `wasChanged` de um save sem mudança ainda responde
        // pelo save anterior do mesmo model.
        $mudou = $user->isDirty('reminder_emails');
        $user->save();

        if ($mudou) {
            Atividade::registrar(
                $ligado ? 'lembretes.ligados' : 'lembretes.desligados',
                $ligado ? 'ligou os lembretes de vencimento por e-mail' : 'desligou os lembretes de vencimento por e-mail',
                $user->ownerId(),
                $user,
            );
        }

        return redirect()->route('settings', 'conta')->with('status', $ligado
            ? 'Lembretes de vencimento por e-mail ligados.'
            : 'Lembretes de vencimento por e-mail desligados.');
    }
}
