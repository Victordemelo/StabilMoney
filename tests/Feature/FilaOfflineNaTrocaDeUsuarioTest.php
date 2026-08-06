<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fila offline num aparelho COMPARTILHADO (troca de usuário).
 *
 * Cenário real: o titular lança sem internet no celular, sai da conta, outra
 * pessoa entra. O lançamento dele mora no IndexedDB do NAVEGADOR (não da sessão)
 * e sobrevive ao logout — ele não pode ser sincronizado na conta de quem entrou,
 * nem sumir em silêncio (o dinheiro daquele lançamento existe no mundo real).
 *
 * A decisão fica no cliente (offline-queue.js / service worker), que o PHPUnit não
 * executa. O que dá para provar aqui, e está provado abaixo:
 *  - o logout continua mandando limpar o CACHE e NUNCA o "storage" (que apagaria
 *    a fila inteira, destruindo lançamentos ainda não enviados);
 *  - o servidor sempre define o dono pela SESSÃO, nunca pelo payload — logo, um
 *    replay na sessão errada cairia na família errada, e é por isso que a trava
 *    do cliente (carimbo `userId` no item) precisa existir e continuar existindo;
 *  - os contratos entre os arquivos JS (fila ↔ modal ↔ service worker) e o
 *    `<meta name="sm-user">` de que o carimbo depende.
 */
class FilaOfflineNaTrocaDeUsuarioTest extends TestCase
{
    use RefreshDatabase;

    // ---- Logout × fila -----------------------------------------------------

    /**
     * O logout limpa o cache de páginas, mas NUNCA o "storage": storage apagaria o
     * IndexedDB e junto com ele os lançamentos feitos offline que ainda não
     * sincronizaram. Perder cache custa um download; perder a fila custa dinheiro.
     */
    public function test_logout_limpa_cache_mas_nunca_o_storage_da_fila(): void
    {
        $user = User::factory()->create();

        $header = (string) $this->actingAs($user)->post('/logout')->headers->get('Clear-Site-Data');

        $this->assertStringContainsString('cache', $header);
        $this->assertStringNotContainsString('storage', $header);
        $this->assertStringNotContainsString('*', $header);
    }

    // ---- O dono vem da sessão, nunca do payload ----------------------------

    /**
     * O `user_id` da transação é sempre o da família de quem está LOGADO. Um item
     * da fila não carrega (nem consegue impor) o dono — por isso reenviar o
     * lançamento de A na sessão de B o colocaria na família de B.
     */
    public function test_dono_do_lancamento_vem_da_sessao_e_nunca_do_payload(): void
    {
        $vitima = User::factory()->create();
        $quemEstaLogado = User::factory()->create();
        $conta = Account::factory()->for($quemEstaLogado)->create();

        $uuid = (string) Str::uuid();

        $this->actingAs($quemEstaLogado)->postJson('/transactions', [
            'client_uuid' => $uuid,
            'user_id' => $vitima->id,          // tentativa de forjar o dono
            'made_by_user_id' => $vitima->id,  // e o autor
            'type' => 'expense',
            'amount' => '25,90',
            'account_id' => $conta->id,
            'date' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('made_by_user_id');

        // Nem sequer criou: o autor de fora da família derruba a requisição.
        $this->assertDatabaseCount('transactions', 0);

        // Sem o autor forjado, a transação nasce na família de quem está logado —
        // o `user_id` do payload é simplesmente ignorado (não está nas rules).
        $this->actingAs($quemEstaLogado)->postJson('/transactions', [
            'client_uuid' => $uuid,
            'user_id' => $vitima->id,
            'type' => 'expense',
            'amount' => '25,90',
            'account_id' => $conta->id,
            'date' => now()->toDateString(),
        ])->assertCreated();

        $this->assertDatabaseHas('transactions', [
            'client_uuid' => $uuid,
            'user_id' => $quemEstaLogado->id,
        ]);
        $this->assertDatabaseMissing('transactions', ['user_id' => $vitima->id]);
    }

    /**
     * Replay do lançamento de OUTRA família na sessão errada: a conta do payload
     * não pertence a quem está logado, então o servidor recusa (422) e nada é
     * gravado. Esta é a rede de proteção do servidor — mas ela só existe porque a
     * conta é de outra família; ver o teste seguinte, que mostra o buraco que só o
     * cliente fecha.
     */
    public function test_replay_com_conta_de_outra_familia_e_recusado(): void
    {
        $dono = User::factory()->create();
        $contaDoDono = Account::factory()->for($dono)->create();

        $outro = User::factory()->create();
        Account::factory()->for($outro)->create();

        $this->actingAs($outro)->postJson('/transactions', [
            'client_uuid' => (string) Str::uuid(),
            'type' => 'expense',
            'amount' => '80,00',
            'account_id' => $contaDoDono->id, // conta do dono anterior do aparelho
            'date' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('account_id');

        $this->assertDatabaseCount('transactions', 0);
    }

    /**
     * O BURACO que o servidor não fecha: dentro da MESMA família (titular e
     * dependente compartilham contas), o replay passa — a transação é gravada e o
     * autor vira quem está logado, não quem fez a compra.
     *
     * É exatamente o cenário "o Victor lança, a esposa dependente entra depois":
     * o gasto sairia no nome dela. Por isso o carimbo `userId` no item da fila,
     * do lado do cliente, é a única trava possível. Se este teste um dia falhar
     * porque o servidor passou a recusar, ótimo — mas NÃO remova a trava do
     * cliente por causa disso.
     */
    public function test_dentro_da_familia_o_servidor_nao_distingue_quem_reenvia(): void
    {
        $titular = User::factory()->create();
        $conta = Account::factory()->for($titular)->create();
        $dependente = User::factory()->create(['account_owner_id' => $titular->id]);

        // Payload que o titular enfileirou offline (sem autor explícito).
        $this->actingAs($dependente)->postJson('/transactions', [
            'client_uuid' => (string) Str::uuid(),
            'type' => 'expense',
            'amount' => '120,00',
            'account_id' => $conta->id,
            'date' => now()->toDateString(),
        ])->assertCreated();

        $lancamento = Transaction::firstOrFail();

        $this->assertSame($titular->id, $lancamento->user_id);              // família certa
        $this->assertSame($dependente->id, $lancamento->made_by_user_id);  // AUTOR ERRADO
    }

    // ---- Contratos de que o carimbo por usuário depende --------------------

    /**
     * O carimbo do dono no item da fila vem do `<meta name="sm-user">` do layout.
     * Sem essa meta, `offline-queue.js` carimbaria `null` em tudo e a troca de
     * usuário voltaria a vazar.
     */
    public function test_layout_expoe_o_id_do_usuario_para_o_javascript(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')
            ->assertSee('name="sm-user" content="'.$user->id.'"', false);
    }

    /**
     * O service worker só reenvia item ARMADO (com `csrf`). Quem desarma o item de
     * outro dono é a página. Sem esse filtro, o Background Sync mandaria o
     * lançamento do dono anterior — com o app fechado, sem ninguém ver.
     */
    public function test_service_worker_so_reenvia_lancamento_armado(): void
    {
        $sw = $this->get('/sw.js')->assertOk()->getContent();

        $this->assertStringContainsString('i.payload && i.csrf', $sw);
    }

    /**
     * Guardas estáticos do JavaScript. O projeto não tem runner de teste para JS
     * (o Playwright em tests/e2e/ exige o app no ar), então estas asserções sobre
     * o CÓDIGO-FONTE são o que impede uma regressão silenciosa das duas regras
     * mais caras desta rodada.
     */
    public function test_a_fila_segura_o_lancamento_de_outro_dono_em_vez_de_apagar(): void
    {
        $fila = file_get_contents(resource_path('js/sm/offline-queue.js'));

        // Some com o apagão silencioso da versão anterior.
        $this->assertStringNotContainsString('purgeQueueFromOtherUsers', $fila);
        // O item do outro dono é DESARMADO (perde o csrf), não apagado…
        $this->assertStringContainsString('csrf: null', $fila);
        // …e a pessoa que está no aparelho é avisada.
        $this->assertStringContainsString('renderAvisoOutroDono', $fila);
        // Só o próprio dono reenvia: o carimbo é comparado antes de qualquer POST.
        $this->assertStringContainsString('String(i.userId) === String(userId)', $fila);
    }

    /** O modal "Lançar" (topbar + FAB) usa a MESMA fila do formulário cheio. */
    public function test_modal_lancar_usa_a_fila_offline(): void
    {
        $modal = file_get_contents(resource_path('js/sm/launch.js'));
        $fila = file_get_contents(resource_path('js/sm/offline-queue.js'));

        // A asserção olha o SÍMBOLO importado, não a linha inteira: a lista de
        // imports cresce (o modal também passou a usar `refreshCsrfToken` para tratar
        // 419), e casar a linha literal quebrava o teste a cada import novo — sem
        // nada de errado no código.
        $this->assertMatchesRegularExpression(
            "/import \{[^}]*\benfileirarLancamento\b[^}]*\} from '\.\/offline-queue'/",
            $modal,
        );
        $this->assertStringContainsString('export function enfileirarLancamento', $fila);
        // E não promete "salvo" para algo que ainda vai ser enviado.
        $this->assertStringNotContainsString('lançamento salvo', $fila);
    }
}
