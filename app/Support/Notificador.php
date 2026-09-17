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

        return self::tentarEnviar($usuario, $email::class, fn () => Mail::to($usuario->email)->send($email));
    }

    /**
     * Executa um envio de e-mail **sem deixar a exceção derrubar a ação que o gerou**.
     *
     * É o miolo do `avisar()`, extraído para servir também ao que NÃO é `Mailable`.
     * O caso concreto que obrigou a extração: o link de verificação do cadastro sai por
     * `Notification` e vinha do listener do framework (`SendEmailVerificationNotification`),
     * que não tem proteção nenhuma — um "550" do SMTP virava **HTTP 500 com o usuário já
     * gravado no banco**, e o e-mail dele ficava ocupado sem nunca destravar a conta.
     *
     * ⚠️ Quem chama decide o que fazer com o `false`. Em alerta de segurança não há nada a
     * fazer (o aviso é acessório). No cadastro há: se o link não saiu, exigir a confirmação
     * trancaria a pessoa fora do app — ver `RegisteredUserController::store`.
     *
     * @param  string  $oque  o que se tentou enviar (só para o log dizer algo útil)
     * @param  \Closure():void  $envio
     * @return bool o e-mail saiu de fato?
     */
    public static function tentarEnviar(User $usuario, string $oque, \Closure $envio): bool
    {
        try {
            $envio();

            return true;
        } catch (\Throwable $e) {
            Log::warning('E-mail não foi entregue.', [
                'oque' => $oque,
                'user_id' => $usuario->getKey(),
                'erro' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
