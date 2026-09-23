<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AutenticaNoPainel;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Support\AdminAudit;
use App\Support\Totp;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Segundo fator do painel: setup obrigatório e desafio por sessão.
 *
 * Não existe rota para DESLIGAR o 2FA — de propósito. No app do cliente desligar é
 * uma escolha dele; aqui o painel enxerga a base inteira, e uma senha vazada não pode
 * ser suficiente.
 */
class TwoFactorController extends Controller
{
    // ── Setup (primeiro acesso) ──────────────────────────────────────────────

    public function setup(Request $request)
    {
        $admin = $request->user('admin');

        if ($admin->temDoisFatores()) {
            return redirect()->route('painel.home');
        }

        // Sem segredo ainda (ou setup abandonado): gera um novo. Regerar a cada visita
        // à tela é seguro porque nada foi confirmado — e evita o QR "morto" de uma
        // tentativa anterior continuar valendo.
        if (! $admin->two_factor_secret) {
            $admin->iniciarDoisFatores();
        }

        return view('admin.auth.dois-fatores-setup', [
            'qr' => $this->qrSvg($admin->uriDoAutenticador()),
            'segredo' => Totp::formatarSegredo($admin->two_factor_secret),
        ]);
    }

    public function confirmar(Request $request)
    {
        $admin = $request->user('admin');

        // Quem já confirmou não "confirma" de novo — a tela (GET) já desviava, o POST não.
        // Por aqui, a senha e UM código do autenticador, que no desafio só abrem a sessão,
        // devolviam a lista inteira de códigos de recuperação (o `confirmarDoisFatores` a
        // devolve): oito entradas futuras que não dependem do celular, e que o painel não
        // tem como trocar.
        if ($admin->temDoisFatores()) {
            return redirect()->route('painel.home');
        }

        $dados = $request->validate(
            ['codigo' => ['required', 'string']],
            [],
            ['codigo' => 'código'],
        );

        $codigos = $admin->confirmarDoisFatores($dados['codigo']);

        if ($codigos === null) {
            // Código errado na configuração também é tentativa com a senha certa: vai para
            // o histórico e para o e-mail, como no desafio (achado A-2 da auditoria de
            // 06/09/2026).
            AdminAudit::registrar(
                AdminAuditLog::TOTP_FALHOU,
                $admin,
                $request,
                motivo: 'Na configuração do app autenticador (primeiro acesso).',
            );

            throw ValidationException::withMessages([
                'codigo' => 'Código inválido. Confira o app autenticador e tente de novo.',
            ]);
        }

        $this->entrar($request, $admin, motivo: 'Primeiro acesso, com a configuração do app autenticador.');

        // Os códigos de recuperação aparecem UMA vez. Vão pela sessão (flash), não pela
        // URL nem pelo banco em texto.
        return redirect()->route('painel.2fa.recuperacao')
            ->with('codigosDeRecuperacao', $codigos);
    }

    public function recuperacao(Request $request)
    {
        $codigos = $request->session()->get('codigosDeRecuperacao');

        if (! $codigos) {
            return redirect()->route('painel.home');
        }

        return view('admin.auth.codigos-de-recuperacao', ['codigos' => $codigos]);
    }

    // ── Desafio (a cada sessão) ──────────────────────────────────────────────

    public function desafio(Request $request)
    {
        $admin = $request->user('admin');

        if (! $admin->temDoisFatores()) {
            return redirect()->route('painel.2fa.setup');
        }

        if ($request->session()->get('admin_2fa_ok')) {
            return redirect()->route('painel.home');
        }

        return view('admin.auth.dois-fatores-desafio');
    }

    public function verificar(Request $request)
    {
        $admin = $request->user('admin');

        $dados = $request->validate(
            ['codigo' => ['required', 'string']],
            [],
            ['codigo' => 'código'],
        );

        $codigo = trim($dados['codigo']);

        // Aceita o código do autenticador OU um de recuperação (para o caso de o
        // celular ter sido perdido — sem isso, perder o aparelho tranca o painel
        // para sempre).
        $ok = $admin->verificarTotp($codigo) || $admin->consumirCodigoDeRecuperacao($codigo);

        if (! $ok) {
            AdminAudit::registrar(AdminAuditLog::TOTP_FALHOU, $admin, $request);

            throw ValidationException::withMessages([
                'codigo' => 'Código inválido.',
            ]);
        }

        $this->entrar($request, $admin);

        // O destino guardado pelo PAINEL, nunca o `url.intended` — aquele é do app, e ler
        // dele mandava o admin para uma tela do app (achado A-14 da auditoria de 05/09/2026).
        return redirect()->to(
            $request->session()->pull(AutenticaNoPainel::CHAVE_DESTINO, route('painel.home')),
        );
    }

    /**
     * O que faz de um segundo fator aceito um LOGIN no painel, num lugar só.
     *
     * São duas portas: o desafio de toda sessão (`verificar`) e a configuração do primeiro
     * acesso (`confirmar`). Enquanto isto morava só na primeira, o primeiro acesso de cada
     * admin — justamente o que amarra um autenticador à conta — não entrava no histórico,
     * não avisava ninguém e não gravava o último acesso (achado A-2 da auditoria de
     * 06/09/2026). Quem descobrisse a senha de um admin que ainda não configurou o 2FA
     * cadastraria o PRÓPRIO celular em silêncio.
     */
    private function entrar(Request $request, Admin $admin, ?string $motivo = null): void
    {
        // Sessão nova a cada elevação de privilégio: o id de sessão que existia antes
        // do segundo fator não continua valendo depois dele.
        $request->session()->regenerate();
        $request->session()->put('admin_2fa_ok', true);

        $admin->registrarLogin($request->ip());
        AdminAudit::registrar(AdminAuditLog::LOGIN, $admin, $request, motivo: $motivo);
    }

    /**
     * QR como SVG inline.
     *
     * Reaproveita o writer do 2FA dos usuários se ele existir; senão devolve string
     * vazia e a tela mostra o segredo em texto (que é o caminho manual do autenticador).
     */
    private function qrSvg(string $uri): string
    {
        if (! class_exists(Writer::class)) {
            return '';
        }

        $writer = new Writer(new ImageRenderer(
            new RendererStyle(208, 2),
            new SvgImageBackEnd,
        ));

        $svg = $writer->writeString($uri);

        return trim(substr($svg, (int) strpos($svg, '<svg')));
    }
}
