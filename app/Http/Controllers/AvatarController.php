<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serve a foto de perfil a partir do disco PRIVADO.
 *
 * Antes os avatares ficavam em `storage/app/public/avatars`, servidos pelo symlink sem
 * passar pelo Laravel — ou seja, sem sessão: quem tivesse a URL via a foto para sempre,
 * mesmo depois de sair do app ou de ser removido da família. O nome é aleatório (40
 * caracteres), então não dava para enumerar, mas URL vaza com facilidade (print, cache,
 * histórico, backup) e foto de rosto é dado pessoal.
 *
 * Agora o arquivo vive no disco `local` e só sai por aqui, com sessão e escopo de família.
 */
class AvatarController extends Controller
{
    /**
     * A foto vai para o cache do navegador, mas é CONFERIDA a cada uso (`no-cache` + ETag):
     * o navegador pergunta, o servidor confere a sessão e a família e responde 304 sem
     * reenviar a imagem. Antes era `max-age=3600`, que o navegador usava sem perguntar — com
     * o fim do `Clear-Site-Data` no "Sair" (out/2026), a foto de alguém da família seguia
     * abrindo por até 1 hora depois de sair, num computador compartilhado. Privado: proxies
     * e CDNs não guardam.
     *
     * URL sem versão, ou com a de uma foto que já foi trocada: responde a foto atual, sem
     * ETag — guardar a foto nova numa URL que não muda (ou na de uma foto antiga) era o
     * defeito de a troca só aparecer depois de 1 hora.
     */
    private const CACHE = 'private, no-cache';

    public function show(Request $request, User $membro): Response
    {
        // Só a própria família vê a foto. Quem garante isso primeiro é o binding do
        // `{membro}` (User::daFamiliaNaRota): pessoa de outra família nem chega aqui — recebe
        // o mesmo 404 de um id que não existe, sem revelar que a pessoa existe. Esta checagem
        // fica como SEGUNDA linha, para o dia em que o binding mudar. Titular e dependentes
        // compartilham o ownerId, então ela cobre os dois sentidos (titular vendo dependente e
        // vice-versa). Vem ANTES de olhar a versão: a resposta a quem não é da família não
        // pode variar com o estado da foto de ninguém.
        abort_unless(
            $membro->ownerId() === $request->user()->ownerId(),
            403,
            'Esta foto não é da sua família.',
        );

        abort_if($membro->avatar_path === null, 404);

        $disco = Storage::disk(User::AVATAR_DISK);

        abort_unless($disco->exists($membro->avatar_path), 404);

        // `is_string` antes de comparar: `?v[]=x` chega como array, e convertê-lo em texto
        // viraria erro 500 numa URL que qualquer um consegue digitar.
        $versao = $request->query('v');
        $versaoAtual = is_string($versao) && $versao === $membro->versaoDaFoto();

        if (! $versaoAtual) {
            return $disco->response($membro->avatar_path, null, ['Cache-Control' => self::CACHE]);
        }

        // A ETag é a própria versão da foto (muda a cada upload). Bateu: 304, sem a imagem.
        $etag = '"'.$membro->versaoDaFoto().'"';
        if (in_array($etag, $request->getETags(), true)) {
            return response('', 304, ['Cache-Control' => self::CACHE, 'ETag' => $etag]);
        }

        return $disco->response($membro->avatar_path, null, ['Cache-Control' => self::CACHE, 'ETag' => $etag]);
    }
}
