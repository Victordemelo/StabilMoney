<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * Move as fotos de perfil do disco público para o privado.
     *
     * No disco `public` o arquivo era servido pelo symlink de `storage/`, sem passar
     * pelo Laravel — logo, sem sessão: qualquer um com a URL via a foto, inclusive
     * depois de sair do app. Agora o avatar só sai pela rota `avatar.show`, autenticada
     * e restrita à família.
     *
     * Move o ARQUIVO, não o caminho: `avatar_path` continua valendo (é relativo ao
     * disco), então nada no banco muda — o que muda é de onde o app lê.
     *
     * Idempotente: pula quem já está no destino ou perdeu o arquivo de origem.
     */
    public function up(): void
    {
        $this->mover(from: 'public', to: User::AVATAR_DISK);
    }

    /**
     * Volta os arquivos para o disco público.
     *
     * O `down()` reverte o LOCAL, mas quem depender dele precisa saber: com os arquivos
     * de volta em `public`, as fotos voltam a ser acessíveis sem sessão. É a razão de a
     * migration existir — reverter só faz sentido junto com reverter o código.
     */
    public function down(): void
    {
        $this->mover(from: User::AVATAR_DISK, to: 'public');
    }

    private function mover(string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }

        $origem = Storage::disk($from);
        $destino = Storage::disk($to);

        User::query()
            ->whereNotNull('avatar_path')
            ->select(['id', 'avatar_path'])
            ->chunkById(100, function ($usuarios) use ($origem, $destino) {
                foreach ($usuarios as $usuario) {
                    $caminho = $usuario->avatar_path;

                    // Já está no destino (migration reexecutada) ou o arquivo sumiu:
                    // nos dois casos não há o que fazer, e a coluna segue intacta —
                    // a rota devolve 404 e a view cai nas iniciais.
                    if ($destino->exists($caminho) || ! $origem->exists($caminho)) {
                        continue;
                    }

                    $destino->put($caminho, $origem->get($caminho));
                    $origem->delete($caminho);
                }
            });
    }
};
