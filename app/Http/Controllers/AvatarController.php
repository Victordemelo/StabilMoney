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
    /** Cache privado: o navegador guarda, proxies e CDNs não. */
    private const CACHE = 'private, max-age=3600';

    public function show(Request $request, User $user): StreamedResponse
    {
        // Só a própria família vê a foto. Titular e dependentes compartilham o ownerId,
        // então a comparação cobre os dois sentidos (titular vendo dependente e vice-versa).
        abort_unless(
            $user->ownerId() === $request->user()->ownerId(),
            403,
            'Esta foto não é da sua família.',
        );

        abort_if($user->avatar_path === null, 404);

        $disco = Storage::disk(User::AVATAR_DISK);

        abort_unless($disco->exists($user->avatar_path), 404);

        return $disco->response(
            $user->avatar_path,
            null,
            ['Cache-Control' => self::CACHE],
        );
    }
}
