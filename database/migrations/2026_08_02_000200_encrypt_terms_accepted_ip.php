<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cifra em repouso o IP que prova o aceite dos documentos legais.
 *
 * `users.terms_accepted_ip` é a prova do consentimento (LGPD art. 8º, §1º) e vive
 * em texto puro. Um dump de backup vazado entrega o IP de todo mundo.
 *
 * **Por que `encrypted` e não hash:** hash é mão única. O IP precisa continuar
 * legível — é prova, e prova ilegível não prova nada. (O mesmo raciocínio já está
 * no CLAUDE.md para a tela de dispositivos.)
 *
 * **Por que a coluna precisa crescer:** medido nesta máquina, o `Crypt::encryptString`
 * (AES-256-CBC) devolve **200 caracteres** para um IPv4 e **256** para um IPv6 completo.
 * A coluna era `varchar(45)`. Em MySQL com `STRICT_TRANS_TABLES` isso é erro 1406
 * ("Data too long") no `/register` — e como a suíte roda em **sqlite, que não aplica
 * o limite**, os testes ficariam verdes e só a produção quebraria. `varchar(255)` não
 * serve; por isso `text`, que ainda dá folga para uma troca futura de cifra.
 *
 * ⚠️ Com o cast `encrypted`, rotacionar a `APP_KEY` sem preencher `APP_PREVIOUS_KEYS`
 * torna a prova ilegível para sempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('terms_accepted_ip')->nullable()->change();
        });

        // Cifra o que já estava gravado. Apagar não é opção: é a prova do aceite.
        DB::table('users')
            ->whereNotNull('terms_accepted_ip')
            ->orderBy('id')
            ->chunkById(200, function ($linhas) {
                foreach ($linhas as $linha) {
                    DB::table('users')
                        ->where('id', $linha->id)
                        ->update(['terms_accepted_ip' => Crypt::encryptString($linha->terms_accepted_ip)]);
                }
            });
    }

    public function down(): void
    {
        // Decifra ANTES de encolher a coluna — na ordem inversa o texto cifrado
        // seria truncado e o dado morreria.
        DB::table('users')
            ->whereNotNull('terms_accepted_ip')
            ->orderBy('id')
            ->chunkById(200, function ($linhas) {
                foreach ($linhas as $linha) {
                    // Linha que já estava em texto puro (migration rodada pela metade)
                    // não deve derrubar o rollback.
                    try {
                        $puro = Crypt::decryptString($linha->terms_accepted_ip);
                    } catch (DecryptException) {
                        continue;
                    }

                    DB::table('users')->where('id', $linha->id)->update(['terms_accepted_ip' => $puro]);
                }
            });

        Schema::table('users', function (Blueprint $table) {
            $table->string('terms_accepted_ip', 45)->nullable()->change();
        });
    }
};
