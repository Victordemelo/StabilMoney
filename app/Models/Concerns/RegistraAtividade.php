<?php

namespace App\Models\Concerns;

use App\Models\Atividade;
use App\Support\Atividades\Descritor;
use Illuminate\Database\Eloquent\Model;

/**
 * Registra no histórico de atividade da família cada criação, edição e exclusão do model
 * (a frase vem do `Descritor`).
 *
 * O registro é gravado DENTRO do evento do Eloquent — portanto na mesma conexão e na mesma
 * transação de banco de quem salvou. Se a ação for desfeita (rollback, ou o FundingService
 * repetindo a gravação num deadlock), a linha de atividade some junto. Não troque por fila
 * nem por `afterCommit`: o registro de uma ação desfeita contaria uma história que não aconteceu.
 *
 * ⚠️ Delete e update EM MASSA (`Model::where(...)->delete()` / `->update()`) não passam por
 * model nenhum e NÃO disparam estes eventos. Quem escreve assim registra explicitamente com
 * `Atividade::registrar()` no próprio ponto da escrita (ver FaturaController::destroy,
 * TransactionController::destroy, FundingService::estornarFonte…).
 */
trait RegistraAtividade
{
    public static function bootRegistraAtividade(): void
    {
        static::created(fn (Model $m) => self::registrarAtividade($m, 'created'));
        static::updated(fn (Model $m) => self::registrarAtividade($m, 'updated'));
        static::deleted(fn (Model $m) => self::registrarAtividade($m, 'deleted'));
    }

    private static function registrarAtividade(Model $m, string $evento): void
    {
        // Antes de descrever: a frase faz consultas (nome da conta, da meta), e com o
        // registro pausado (seeder de demonstração) elas seriam desperdício.
        if (! Atividade::registrando()) {
            return;
        }

        $linha = Descritor::descrever($m, $evento);

        if ($linha === null) {
            return;
        }

        Atividade::registrar(
            acao: $linha['acao'],
            oQue: $linha['oQue'],
            dono: $linha['dono'],
            alvo: $m,
            mudancas: $linha['mudancas'] ?? [],
            semIdentificacao: $linha['semIdentificacao'] ?? false,
        );
    }
}
