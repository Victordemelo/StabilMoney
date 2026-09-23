<?php

namespace Tests\Feature;

use App\Http\Middleware\PainelAdminLigado;
use App\Http\Middleware\SecurityHeaders;
use App\Models\Account;
use App\Models\Category;
use App\Models\FixedBill;
use App\Models\Goal;
use App\Models\Investment;
use App\Models\Transaction;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as Rota;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Reflector;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * Sentinela da decisão de 23/09/2026: em TODA rota do app com um recurso da família na URL,
 * o id de OUTRA família recebe exatamente a mesma resposta que um id que não existe —
 * status, corpo (HTML e JSON) e cabeçalhos.
 *
 * O defeito: o route model binding achava qualquer linha, e quem barrava era a policy (ou o
 * `authorize()` do Form Request, ou um `abort_unless`). Id alheio → 403; id inexistente →
 * 404 do binding. A diferença bastava para varrer ids e descobrir quais existem: quantas
 * contas, lançamentos e metas o app guarda — e, em `/avatar/{id}`, quais ids são usuários.
 * Trocar o 403 por um 404 na policy não fecharia a sonda: o 404 do binding nasce ANTES do
 * `SecurityHeaders` (CSP estática, sem `X-Csp-Nonce`) e diz "No query results for model
 * [...]" no JSON; um 404 lançado mais adiante sai com a CSP com nonce e outra mensagem. O
 * remédio (`Concerns\EscopoDaFamiliaNaRota` e `User::daFamiliaNaRota`) põe o escopo no
 * próprio binding: o recurso alheio cai no MESMO caminho do inexistente.
 *
 * Por isso a comparação é da resposta INTEIRA, como no `PainelAdminDesligadoNaoSeRevelaTest`,
 * tirando só o que muda a cada requisição por natureza: `Date`, o valor dos cookies, o nonce
 * e o próprio id pedido (quem pergunta sabe qual id mandou).
 *
 * A varredura é pelo REGISTRO DE ROTAS, não por uma lista escrita à mão: rota nova com
 * `{account}`, `{meta}`, `{transaction}`... entra sozinha, e um parâmetro de model que o teste
 * não sabe montar o faz FALHAR pedindo a receita — nunca passar sem olhar.
 */
class IdAlheioNaRotaIgualAIdInexistenteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Rotas fora da varredura, cada uma com o motivo. Só entra aqui rota com regra PRÓPRIA e
     * deliberada — nunca uma rota só porque ficou vermelha.
     *
     * Vazia desde 23/09/2026: a única que havia, `profile.email.confirm`, perdeu o model
     * binding — a pessoa só é procurada depois de a assinatura conferir, e sem assinatura
     * todo id recebe o mesmo 403 (`ConfirmarEmailNaoRevelaQuemExisteTest`).
     *
     * @var array<string, string>
     */
    private const FORA = [];

    /**
     * As rotas que precisam aparecer na varredura. Não é a lista do que é varrido (isso vem
     * do registro de rotas); é a trava contra o teste passar VAZIO — se a detecção dos
     * parâmetros de model quebrar, estas somem e o teste acusa.
     */
    private const ROTAS_CONHECIDAS = [
        'transactions.edit', 'transactions.update', 'transactions.destroy',
        'accounts.edit', 'accounts.update', 'accounts.destroy',
        'categories.edit', 'categories.update', 'categories.destroy',
        'avatar.show',
        'dependentes.update', 'dependentes.destroy',
        'metas.update', 'metas.destroy', 'metas.aportes.store', 'metas.resgates.store',
        'investimentos.update', 'investimentos.destroy', 'investimentos.aportes.store', 'investimentos.resgates.store',
        'faturas.compra.destroy', 'faturas.recorrente.pagar', 'faturas.fatura.pagar', 'faturas.fatura.estornar',
        'contas-fixas.update', 'contas-fixas.destroy', 'contas-fixas.pagar',
    ];

    /**
     * Distância entre os ids do teste e os que o banco sorteia. Os ids pedidos (alheio e
     * inexistente) saem do corpo antes da comparação, e só dá para trocá-los com segurança se
     * forem números que não aparecem por acaso na página (um "4" apareceria no "404").
     */
    private const DESLOCAMENTO_DO_ID = 7_000_000;

    private User $eu;

    private User $euDependente;

    private User $vizinho;

    protected function setUp(): void
    {
        parent::setUp();

        // O corpo comparado é o que o scanner recebe em produção, sem rastro de depuração.
        config(['app.debug' => false]);

        // A varredura faz oito requisições por caso com os mesmos usuários, e uma rota com
        // `throttle` responderia 429 aos dois lados — nada a ver com família. O limite roda
        // antes do binding e trata os dois ids igual, então tirá-lo não esconde diferença.
        $this->withoutMiddleware(ThrottleRequests::class);

        Storage::fake(User::AVATAR_DISK);

        $this->eu = User::factory()->create(['is_admin' => true, 'account_owner_id' => null]);
        $this->euDependente = User::factory()->create(['is_admin' => false, 'account_owner_id' => $this->eu->id]);
        $this->vizinho = User::factory()->create(['is_admin' => true, 'account_owner_id' => null]);
    }

    public function test_id_de_outra_familia_na_url_recebe_a_mesma_resposta_que_um_id_que_nao_existe(): void
    {
        $denuncias = [];
        $varridas = [];

        foreach ($this->casos() as [$rota, $metodo, $parametro, $modelos]) {
            $varridas[] = $rota->getName() ?? $rota->uri();

            foreach (['titular' => $this->eu, 'dependente' => $this->euDependente] as $papel => $quemPede) {
                // Os OUTROS parâmetros de model da rota (se houver) recebem um recurso da
                // família de quem pede: só o parâmetro em teste muda entre as duas requisições.
                $valores = $this->valoresFixos($rota);
                foreach ($modelos as $outro => $classe) {
                    if ($outro !== $parametro) {
                        $valores[$outro] = $this->recursoDe($this->eu, $outro, $classe)->getKey();
                    }
                }

                $alheio = $this->recursoDe($this->vizinho, $parametro, $modelos[$parametro]);
                $idAlheio = (string) $alheio->getKey();
                $idInexistente = (string) ($alheio->getKey() + 1_000_000);

                foreach (['html' => false, 'json' => true] as $formato => $json) {
                    $noAlheio = $this->pedir($quemPede, $metodo, $this->url($rota, [...$valores, $parametro => $idAlheio]), $json);
                    $noInexistente = $this->pedir($quemPede, $metodo, $this->url($rota, [...$valores, $parametro => $idInexistente]), $json);

                    $caso = "{$metodo} /{$rota->uri()} [{$parametro} · {$formato} · {$papel}]";

                    // Controle: sem o 404 do lado inexistente, a comparação não prova nada
                    // (ex.: os dois redirecionando para o login).
                    if ($noInexistente->getStatusCode() !== 404) {
                        $denuncias[] = "{$caso} → o id INEXISTENTE deu {$noInexistente->getStatusCode()}, não 404: o caso não prova nada";

                        continue;
                    }

                    if ($diferenca = $this->diferenca($noAlheio, $idAlheio, $noInexistente, $idInexistente)) {
                        $denuncias[] = "{$caso} → {$diferenca}";
                    }
                }
            }
        }

        $faltando = array_diff(self::ROTAS_CONHECIDAS, $varridas);
        $this->assertSame(
            [],
            array_values($faltando),
            'Rotas com recurso da família na URL que a varredura deixou de enxergar. Se a detecção dos '
                .'parâmetros quebrou, o teste estava passando vazio; se a rota foi renomeada ou removida '
                .'de propósito, atualize ROTAS_CONHECIDAS.',
        );

        $this->assertSame(
            [],
            $denuncias,
            "Recurso de outra família responde diferente de um que não existe — dá para varrer ids e saber quais existem:\n"
                .implode("\n", $denuncias),
        );
    }

    /**
     * A regra das PESSOAS na URL, por papel e por parâmetro. `{dependent}` só nomeia
     * dependente da família (o titular não é dependente de ninguém); `{membro}` nomeia
     * qualquer pessoa da família. Outra família, nunca — nem vista por um dependente.
     */
    public function test_pessoa_na_url_so_e_encontrada_dentro_da_familia_de_quem_pede(): void
    {
        $achar = function (User $quemPede, User $alvo, bool $soDependentes): bool {
            $this->actingAs($quemPede);

            try {
                return User::daFamiliaNaRota((string) $alvo->id, $soDependentes)->is($alvo);
            } catch (ModelNotFoundException) {
                return false;
            }
        };

        $dependenteDoVizinho = User::factory()->create(['is_admin' => false, 'account_owner_id' => $this->vizinho->id]);

        foreach ([$this->eu, $this->euDependente] as $quemPede) {
            $this->assertTrue($achar($quemPede, $this->euDependente, true), 'Dependente da família é {dependent}.');
            $this->assertFalse($achar($quemPede, $this->eu, true), 'O titular não é dependente de ninguém.');
            $this->assertTrue($achar($quemPede, $this->eu, false), 'O titular é {membro} da própria família.');
            $this->assertTrue($achar($quemPede, $this->euDependente, false), 'O dependente é {membro} da família.');

            foreach ([$this->vizinho, $dependenteDoVizinho] as $deOutraFamilia) {
                $this->assertFalse($achar($quemPede, $deOutraFamilia, true));
                $this->assertFalse($achar($quemPede, $deOutraFamilia, false));
            }
        }
    }

    /**
     * Pessoa não ganha binding pelo nome `{user}`: ele é global, e pegaria o `{user}` do
     * painel administrativo — que modera QUALQUER família — e o da confirmação de troca de
     * e-mail (A-12). Por isso as rotas do app usam `{dependent}` e `{membro}`.
     */
    public function test_user_nao_ganha_binding_de_familia(): void
    {
        $this->assertNull(Route::getBindingCallback('user'));
        $this->assertNotNull(Route::getBindingCallback('dependent'));
        $this->assertNotNull(Route::getBindingCallback('membro'));
    }

    /**
     * Os bindings de pessoa vêm do PROVIDER, nunca dos arquivos de rota: com `route:cache`
     * (deploy) o routes/web.php nem é lido, e um `Route::bind` escrito ali sumiria em produção
     * sem erro nenhum — a foto e os dependentes voltariam ao binding sem família. Medido em
     * 23/09/2026: com os dois `Route::bind` no web.php e as rotas em cache, a varredura acima
     * acusou 403 × 404 em `/avatar` e `/dependentes`.
     *
     * Aqui um roteador VAZIO (sem arquivo de rota nenhum, como fica com o cache) recebe só o
     * que o provider registra.
     */
    public function test_os_bindings_de_pessoa_sobrevivem_ao_cache_de_rotas(): void
    {
        $roteadorSemArquivosDeRota = new Router($this->app['events'], $this->app);
        $this->app->instance('router', $roteadorSemArquivosDeRota);
        Route::clearResolvedInstance('router');

        $this->app->getProvider(AppServiceProvider::class)->boot();

        $this->assertNotNull($roteadorSemArquivosDeRota->getBindingCallback('dependent'));
        $this->assertNotNull($roteadorSemArquivosDeRota->getBindingCallback('membro'));
    }

    /**
     * Sem usuário do APP nada é encontrado — nem o que existe. É o que impede uma rota que um
     * dia nasça fora do `auth` de entregar o recurso de alguém.
     */
    public function test_sem_usuario_do_app_o_binding_nao_encontra_nada(): void
    {
        $minha = Account::factory()->for($this->eu)->create();

        $this->assertNull((new Account)->resolveRouteBinding($minha->id));

        try {
            User::daFamiliaNaRota((string) $this->eu->id);
            $this->fail('Sem ninguém logado, uma pessoa foi encontrada pela rota.');
        } catch (ModelNotFoundException $e) {
            // A mesma mensagem que o binding implícito dá para um id que não existe.
            $this->assertSame('No query results for model [App\Models\User] '.$this->eu->id, $e->getMessage());
        }

        $this->actingAs($this->eu);
        $this->assertTrue($minha->is((new Account)->resolveRouteBinding($minha->id)), 'Logado, a própria conta é encontrada.');
    }

    /**
     * O escopo vale também no binding ANINHADO (`scopeBindings`), em que o model é a FILHA —
     * é por isso que ele fica no `resolveRouteBindingQuery`, por onde passam os três caminhos
     * do framework, e não só no `resolveRouteBinding`.
     */
    public function test_o_escopo_vale_no_binding_aninhado(): void
    {
        $this->actingAs($this->eu);

        $contaAlheia = Account::factory()->for($this->vizinho)->create();
        $lancamentoAlheio = Transaction::factory()->expense()->for($this->vizinho)->create(['account_id' => $contaAlheia->id]);

        $minhaConta = Account::factory()->for($this->eu)->create();
        $meuLancamento = Transaction::factory()->expense()->for($this->eu)->create(['account_id' => $minhaConta->id]);

        $this->assertNull($contaAlheia->resolveChildRouteBinding('transaction', $lancamentoAlheio->id, null));
        $this->assertTrue($meuLancamento->is($minhaConta->resolveChildRouteBinding('transaction', $meuLancamento->id, null)));
    }

    // ── Varredura ────────────────────────────────────────────────────────────

    /**
     * Um caso por rota × verbo × parâmetro de model, fora o painel e as rotas de `FORA`.
     *
     * @return list<array{0: Rota, 1: string, 2: string, 3: array<string, class-string|null>}>
     */
    private function casos(): array
    {
        $casos = [];

        foreach (Route::getRoutes() as $rota) {
            // O painel administrativo não tem família: o admin modera todo mundo, e o
            // `{user}` dele precisa achar qualquer pessoa. (Todas as rotas dele carregam o
            // interruptor — ver routes/admin.php.)
            if (in_array(PainelAdminLigado::class, $rota->middleware(), true)) {
                continue;
            }

            if (array_key_exists($rota->getName() ?? '', self::FORA)) {
                continue;
            }

            $modelos = $this->parametrosDeModel($rota);

            foreach (array_keys($modelos) as $parametro) {
                foreach (array_diff($rota->methods(), ['HEAD']) as $metodo) {
                    $casos[] = [$rota, $metodo, $parametro, $modelos];
                }
            }
        }

        return $casos;
    }

    /**
     * Os parâmetros da rota que viram model: tipados no controller (binding implícito) ou com
     * binding explícito (`Route::bind`, caso do `{dependent}` e do `{membro}`).
     *
     * @return array<string, class-string|null> parâmetro => classe (null: binding sem tipo)
     */
    private function parametrosDeModel(Rota $rota): array
    {
        $tipados = [];
        foreach ($rota->signatureParameters(['subClass' => UrlRoutable::class]) as $parametro) {
            $tipados[$parametro->getName()] = Reflector::getParameterClassName($parametro);
        }

        $modelos = [];
        foreach ($rota->parameterNames() as $nome) {
            // Mesma correspondência do binding implícito: `{fixed_bill}` casa com `$fixedBill`.
            $classe = $tipados[$nome] ?? $tipados[Str::camel($nome)] ?? null;

            if ($classe !== null || Route::getBindingCallback($nome) !== null) {
                $modelos[$nome] = $classe;
            }
        }

        return $modelos;
    }

    /**
     * Valores dos parâmetros que NÃO são model.
     *
     * @return array<string, string>
     */
    private function valoresFixos(Rota $rota): array
    {
        $conhecidos = ['competencia' => now()->format('Y-m')];

        return array_intersect_key($conhecidos, array_flip($rota->parameterNames()));
    }

    /**
     * Um recurso da família de `$titular` para o parâmetro, com id DISTINTO (ver
     * `DESLOCAMENTO_DO_ID`).
     *
     * @param  class-string|null  $classe
     */
    private function recursoDe(User $titular, string $parametro, ?string $classe): Model
    {
        $id = $classe === null ? null : $this->idDistinto($classe);

        return match (true) {
            // A foto: uma pessoa da família COM foto — sem ela a rota daria 404 de qualquer
            // jeito, e o caso não provaria nada se o escopo sumisse.
            $parametro === 'membro' && $classe === User::class => $this->comFoto(
                User::factory()->create(['id' => $id, 'is_admin' => false, 'account_owner_id' => $titular->id]),
            ),
            // `{dependent}` e qualquer outra pessoa na URL: um dependente da família.
            $classe === User::class => User::factory()->create(['id' => $id, 'is_admin' => false, 'account_owner_id' => $titular->id]),
            $classe === Account::class => Account::factory()->for($titular)->create(['id' => $id]),
            $classe === Category::class => Category::factory()->expense()->for($titular)->create(['id' => $id]),
            $classe === Transaction::class => Transaction::factory()->expense()->for($titular)->create([
                'id' => $id,
                'account_id' => Account::factory()->for($titular)->create()->id,
                'amount' => 50,
                'date' => now()->toDateString(),
            ]),
            $classe === Goal::class => Goal::factory()->for($titular)->create(['id' => $id]),
            $classe === Investment::class => Investment::factory()->for($titular)->create(['id' => $id]),
            $classe === FixedBill::class => FixedBill::factory()->create(['id' => $id, 'user_id' => $titular->id]),
            default => $this->fail(
                "O parâmetro {{$parametro}} (".($classe ?? 'binding sem tipo').') aparece numa rota do app, e o '
                .'sentinela não sabe criar um recurso dele para outra família. Ensine a receita em recursoDe() — '
                .'e confira que o binding desse parâmetro tem escopo de família (Concerns\EscopoDaFamiliaNaRota '
                .'ou um binding explícito como o de User::daFamiliaNaRota).',
            ),
        };
    }

    /** @param  class-string<Model>  $classe */
    private function idDistinto(string $classe): int
    {
        $modelo = new $classe;

        return (int) $modelo->newQuery()->max($modelo->getKeyName()) + self::DESLOCAMENTO_DO_ID;
    }

    private function comFoto(User $pessoa): User
    {
        $pessoa->storeAvatar(UploadedFile::fake()->create('foto.jpg', 12));
        $pessoa->save();

        return $pessoa;
    }

    /** @param  array<string, string|int>  $valores */
    private function url(Rota $rota, array $valores): string
    {
        $caminho = preg_replace_callback('/\{(\w+)(?::\w+)?(\?)?\}/', function (array $m) use ($rota, $valores): string {
            if (array_key_exists($m[1], $valores)) {
                return (string) $valores[$m[1]];
            }

            if (($m[2] ?? '') === '?') {
                return '';
            }

            $this->fail("A rota /{$rota->uri()} tem o parâmetro {{$m[1]}}, que o sentinela não sabe preencher: ensine um valor em valoresFixos().");
        }, $rota->uri());

        return '/'.trim((string) preg_replace('#/+#', '/', $caminho), '/');
    }

    private function pedir(User $quemPede, string $metodo, string $url, bool $json): TestResponse
    {
        $this->actingAs($quemPede);

        return $json ? $this->json($metodo, $url) : $this->call($metodo, $url);
    }

    // ── Comparação ───────────────────────────────────────────────────────────

    /** O que difere entre as duas respostas, ou null se nada. */
    private function diferenca(TestResponse $alheio, string $idAlheio, TestResponse $inexistente, string $idInexistente): ?string
    {
        $partes = [];

        if ($alheio->getStatusCode() !== $inexistente->getStatusCode()) {
            $partes[] = "status {$alheio->getStatusCode()} (id inexistente: {$inexistente->getStatusCode()})";
        }

        $cabecalhosAlheio = $this->cabecalhos($alheio);
        $cabecalhosInexistente = $this->cabecalhos($inexistente);

        $diferentes = array_filter(
            array_keys($cabecalhosAlheio + $cabecalhosInexistente),
            fn (string $nome) => ($cabecalhosAlheio[$nome] ?? null) !== ($cabecalhosInexistente[$nome] ?? null),
        );

        if ($diferentes !== []) {
            $partes[] = 'cabeçalhos '.implode(', ', $diferentes);
        }

        if ($this->corpo($alheio, $idAlheio) !== $this->corpo($inexistente, $idInexistente)) {
            $partes[] = 'corpo';
        }

        return $partes === [] ? null : implode(' · ', $partes);
    }

    /**
     * Os cabeçalhos, sem o que varia a cada requisição por natureza: `Date`, o VALOR dos
     * cookies (nome e atributos ficam — um cookie a mais ou a menos é diferença), o nonce e
     * os contadores do limite de taxa. A PRESENÇA de cada cabeçalho continua contando: é
     * justamente a CSP com nonce × sem nonce que denunciava o 404 lançado fora do binding.
     *
     * @return array<string, list<string>>
     */
    private function cabecalhos(TestResponse $resposta): array
    {
        $cabecalhos = $resposta->headers->all();
        unset($cabecalhos['date'], $cabecalhos['set-cookie']);

        $nonce = $resposta->headers->get(SecurityHeaders::HEADER_NONCE);

        foreach ($cabecalhos as $nome => $valores) {
            $cabecalhos[$nome] = array_map(function ($valor) use ($nome, $nonce): string {
                if (in_array($nome, ['x-ratelimit-remaining', 'x-ratelimit-reset', 'retry-after'], true)) {
                    return '{valor}';
                }

                return $nonce ? str_replace($nonce, '{nonce}', (string) $valor) : (string) $valor;
            }, $valores);
        }

        $cookies = array_map(fn (Cookie $cookie) => implode(';', [
            $cookie->getName(),
            $cookie->getPath(),
            (string) $cookie->getDomain(),
            $cookie->isSecure() ? 'secure' : '',
            $cookie->isHttpOnly() ? 'httponly' : '',
            (string) $cookie->getSameSite(),
            $cookie->getExpiresTime() > 0 ? 'expira' : 'de-sessao',
        ]), $resposta->headers->getCookies());
        sort($cookies);
        $cabecalhos['set-cookie'] = $cookies;

        ksort($cabecalhos);

        return $cabecalhos;
    }

    /** O corpo, com o id pedido e o nonce trocados por marcadores. */
    private function corpo(TestResponse $resposta, string $id): string
    {
        $corpo = (string) $resposta->getContent();

        if ($nonce = $resposta->headers->get(SecurityHeaders::HEADER_NONCE)) {
            $corpo = str_replace($nonce, '{nonce}', $corpo);
        }

        return str_replace($id, '{id}', $corpo);
    }
}
