<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
     * URL com a versão da foto ATUAL (`?v=`, ver User::avatarUrl): pode ficar em cache.
     * Privado: o navegador guarda, proxies e CDNs não. Quando a foto muda, a versão muda
     * junto e a página passa a pedir outra URL — o cache nunca serve a foto velha.
     */
    private const CACHE = 'private, max-age=3600';

    /**
     * URL sem versão, ou com a versão de uma foto que já foi trocada: responde a foto atual
     * e não deixa o navegador guardá-la sob essa chave. Guardar a foto nova numa URL que
     * não muda (ou na de uma foto antiga) era exatamente o defeito — a troca de foto só
     * aparecia depois de 1 hora.
     */
    private const SEM_CACHE = 'private, no-cache';

    public function show(Request $request, User $user): StreamedResponse
    {
        // Só a própria família vê a foto. Titular e dependentes compartilham o ownerId,
        // então a comparação cobre os dois sentidos (titular vendo dependente e vice-versa).
        // Vem ANTES de olhar a versão: a resposta a quem não é da família não pode variar
        // com o estado da foto de ninguém.
        abort_unless(
            $user->ownerId() === $request->user()->ownerId(),
            403,
            'Esta foto não é da sua família.',
        );

        abort_if($user->avatar_path === null, 404);

        $disco = Storage::disk(User::AVATAR_DISK);

        abort_unless($disco->exists($user->avatar_path), 404);

        // `is_string` antes de comparar: `?v[]=x` chega como array, e convertê-lo em texto
        // viraria erro 500 numa URL que qualquer um consegue digitar.
        $versao = $request->query('v');
        $versaoAtual = is_string($versao) && $versao === $user->versaoDaFoto();

        return $disco->response(
            $user->avatar_path,
            null,
            ['Cache-Control' => $versaoAtual ? self::CACHE : self::SEM_CACHE],
        );
    }
}
