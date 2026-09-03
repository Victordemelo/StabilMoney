<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Envia aviso ao usuário **sem nunca derrubar a ação que o gerou**.
 *
 * 🚨 É a razão de esta classe existir, e a regra vale para todo alerta novo: trocar a
 * senha, desligar o 2FA e excluir a conta são ações que a pessoa toma **justamente quando
 * desconfia de invasão**. Se o servidor de e-mail estiver fora do ar e a exceção subir, o
 * usuário recebe um erro 500 e conclui que a troca de senha falhou — quando ela já foi
 * gravada. Ou pior: repete a operação. O aviso é acessório; a ação é o que importa.
 *
 * Por isso: exceção vira **log**, não erro de tela. E um alerta que não saiu é uma falha
 * de entrega a investigar, não um pedido para desfazer nada.
 *
 * ⚠️ O envio é SÍNCRONO. Some ~1 segundo à requisição, o que é aceitável em ações raras
 * (ninguém troca de senha dez vezes por minuto) e evita depender de um worker de fila que
 * hoje não roda em lugar nenhum. Se um dia houver `queue:work` no ar, basta os Mailables
 * implementarem `ShouldQueue` — esta classe não muda.
 */
final class Notificador
{
    /**
     * @return bool enviou de fato? (false = app sem mailer, ou falha registrada no log)
     */
    public static function avisar(User $usuario, Mailable $email): bool
    {
        // Sem transporte que entregue, mandar só encheria storage/logs com HTML: o alerta
        // não chegaria a ninguém e o log de erro do app fica ilegível. Ver App\Support\Mailer.
        if (! Mailer::entrega()) {
            return false;
        }

        try {
            Mail::to($usuario->email)->send($email);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Aviso por e-mail não foi entregue.', [
                'mailable' => $email::class,
                'user_id' => $usuario->getKey(),
                'erro' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
