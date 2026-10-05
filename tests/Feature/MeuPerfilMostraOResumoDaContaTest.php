<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Meu perfil" ganhou um cabeçalho e atalhos de segurança (out/2026): antes era só o
 * formulário, e o Victor achou a tela "muito simples". Tudo aqui é dado REAL da pessoa —
 * cada número é conferido contra o que o banco diz.
 */
class MeuPerfilMostraOResumoDaContaTest extends TestCase
{
    use RefreshDatabase;

    private function pagina(User $user): string
    {
        return $this->actingAs($user)->get(route('profile.edit'))->assertOk()->getContent();
    }

    public function test_perfil_vazio_mostra_o_que_falta_preencher(): void
    {
        $user = User::factory()->create(['phone' => null, 'birth_date' => null, 'gender' => null, 'avatar_path' => null]);

        $html = $this->pagina($user);

        // Nome e e-mail (obrigatórios) contam; os quatro opcionais faltam: 2 de 6.
        $this->assertStringContainsString('<strong>33%</strong>', $html);
        $this->assertStringContainsString('Falta: foto, telefone, data de nascimento, sexo', $html);
        $this->assertStringContainsString('Adicionar foto', $html);
    }

    public function test_perfil_completo_diz_tudo_preenchido(): void
    {
        Storage::fake(User::AVATAR_DISK);
        Storage::disk(User::AVATAR_DISK)->put('avatars/eu.png', 'png');
        $user = User::factory()->create([
            'phone' => '(11) 98888-7777', 'birth_date' => '1990-05-04', 'gender' => 'masculino',
            'avatar_path' => 'avatars/eu.png',
        ]);

        $html = $this->pagina($user);

        $this->assertStringContainsString('<strong>100%</strong>', $html);
        $this->assertStringContainsString('Tudo preenchido', $html);
        $this->assertStringContainsString('Trocar foto', $html);
    }

    public function test_lancamentos_do_mes_contam_so_os_da_pessoa_no_mes_sem_quitacao_nem_transferencia(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-10-15 12:00:00'));
        $titular = User::factory()->create();
        $dependente = User::factory()->create(['account_owner_id' => $titular->id]);
        $conta = Account::factory()->for($titular)->create(['type' => 'checking', 'initial_balance' => 5000]);
        $outra = Account::factory()->for($titular)->create(['type' => 'savings', 'initial_balance' => 0]);
        $cartao = Account::factory()->for($titular)->creditCard()->create();

        $linha = fn (array $d) => Transaction::query()->forceCreate(array_merge([
            'user_id' => $titular->id, 'made_by_user_id' => $titular->id, 'account_id' => $conta->id,
            'type' => 'expense', 'amount' => 10, 'date' => '2026-10-10', 'paid_at' => '2026-10-10',
        ], $d));

        $linha([]);                                            // conta
        $linha(['type' => 'income', 'date' => '2026-10-01']);  // conta (receita também é lançamento)
        $linha(['date' => '2026-10-31']);                      // conta (último dia do mês)
        $linha(['date' => '2026-09-30']);                      // mês passado
        $linha(['date' => '2026-11-01']);                      // mês que vem (parcela futura)
        $linha(['made_by_user_id' => $dependente->id]);        // é da Maria, não dele
        $linha(['settles_account_id' => $cartao->id]);         // quitação de fatura
        $grupo = (string) Str::uuid();
        $linha(['transfer_group_id' => $grupo]);               // pontas de transferência
        $linha(['transfer_group_id' => $grupo, 'type' => 'income', 'account_id' => $outra->id]);

        $html = $this->pagina($titular);

        $this->assertMatchesRegularExpression('#Seus lançamentos no mês</span>\s*<strong>3</strong>#', $html);
        $this->assertMatchesRegularExpression('#Pessoas na família</span>\s*<strong>2</strong>#', $html);
    }

    public function test_estado_do_acesso_e_os_atalhos_para_as_configuracoes(): void
    {
        $user = User::factory()->create(['password_changed_at' => now()->subDays(40)]);

        $html = $this->pagina($user);
        $this->assertStringContainsString('2FA desligado', $html);
        $this->assertStringContainsString('Desligada — ligue para proteger o login', $html);
        $this->assertStringContainsString(route('settings', '2fa'), $html);
        $this->assertStringContainsString(route('settings', 'seguranca'), $html);
        $this->assertStringContainsString('Trocada há 1 mês', $html);

        $user->forceFill(['two_factor_secret' => 'segredo', 'two_factor_confirmed_at' => now()])->save();
        $html = $this->pagina($user->fresh());
        $this->assertStringContainsString('2FA ligado', $html);
        $this->assertStringContainsString('Ligada neste login', $html);
    }

    public function test_selos_de_papel_e_de_troca_de_email_pendente(): void
    {
        $titular = User::factory()->create();
        $dependente = User::factory()->create(['account_owner_id' => $titular->id, 'relationship' => 'conjuge']);

        $this->assertStringContainsString('<span class="dp-badge titular">Titular</span>', $this->pagina($titular));
        $this->assertStringContainsString('E-mail verificado', $this->pagina($titular));
        $this->assertStringContainsString('<span class="dp-badge">'.$dependente->relationshipLabel().'</span>', $this->pagina($dependente));

        $titular->forceFill(['pending_email' => 'novo@exemplo.test'])->save();
        $html = $this->pagina($titular->fresh());
        $this->assertStringContainsString('Troca de e-mail pendente', $html);
        $this->assertStringNotContainsString('E-mail verificado', $html);
    }
}
