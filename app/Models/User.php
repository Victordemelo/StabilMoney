<?php

namespace App\Models;

use App\Mail\ConfirmarNovoEmail;
use App\Support\BrowserSessions;
use App\Support\ImageMetadata;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Verificação de e-mail — por que exigir confirmação NÃO tranca ninguém.
 *
 * Implementar `MustVerifyEmail` só habilita o fluxo (link assinado + rotas
 * `verification.*`). Quem de fato exige a confirmação é o middleware `verified`, que
 * **está aplicado às rotas do app** desde 06/08/2026 — ver routes/web.php.
 *
 * A regra que evita o desastre está em quem CRIA usuário, não aqui. O invariante:
 * **enquanto o app não consegue enviar e-mail, ninguém fica pendente de confirmação.**
 *
 *  - `RegisteredUserController::store` grava `email_verified_at` no ato quando
 *    `App\Support\Mailer::entrega()` é falso — sem mailer não há endereço a confirmar,
 *    então ninguém nasce trancado. Com SMTP configurado, o campo nasce nulo e o listener
 *    `SendEmailVerificationNotification` (evento `Registered`) manda o link sozinho: só
 *    o usuário NOVO precisa confirmar; quem já estava dentro segue verificado.
 *  - `DependentController::store` grava SEMPRE: dependente não passa pelo `/register`,
 *    ninguém lhe envia link nenhum, e deixá-lo nulo trancaria toda a família fora do app.
 *  - `ProfileController::update` grava no ato quando não há mailer. 🚨 Ele já fez o
 *    contrário — zerava o campo — e criava conta que NENHUM link destrava. Foi inofensivo
 *    só enquanto `verified` não existia.
 *
 * Coberto por tests/Feature/VerificacaoDeEmailTest.php.
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'birth_date',
        'gender',
        'avatar_path',
        'password',
        'password_changed_at',
        // ATENÇÃO: `is_admin` e `account_owner_id` NÃO entram aqui de propósito.
        // São os dois campos que definem PRIVILÉGIO (titular × dependente) e a que
        // família a pessoa pertence. Hoje nenhum caminho HTTP os preenche, mas
        // bastaria um `$user->update($request->all())` futuro para virar escalada de
        // privilégio ou sequestro de família. Quem os grava faz isso explicitamente:
        // RegisteredUserController::store e DependentController::store.
        'relationship',
        // `pending_email` fica FORA: quem o define é o ProfileController, depois de
        // exigir a senha atual. Nunca vem direto de um formulário.
        //
        // As quatro colunas `two_factor_*` também ficam FORA, pela mesma razão dos campos
        // de privilégio: são o que decide se o login vai cobrar a segunda etapa. Quem as
        // grava é o TwoFactorService, sempre depois de conferir a senha atual ou um código
        // válido — nunca a partir de um campo de formulário.
        'terms_accepted_at',
        'terms_version',
        'terms_accepted_ip',
    ];

    /**
     * Disco onde a foto de perfil é guardada.
     *
     * `local` (privado), NÃO `public`: o avatar sai só pela rota `avatar.show`, que
     * exige sessão e valida a família. No disco público ele era servido pelo symlink
     * sem passar pelo Laravel — quem tivesse a URL via a foto para sempre.
     */
    public const AVATAR_DISK = 'local';

    /** Graus de parentesco de um dependente (valor no banco => rótulo PT-BR). */
    /**
     * Sexo/gênero — opções do perfil.
     *
     * "Prefiro não informar" existe e é a escolha padrão de quem não responde:
     * obrigar alguém a se declarar para usar o app não serve a nada aqui, e o
     * campo inteiro é opcional. A ordem alfabética evita sugerir uma resposta.
     */
    public const GENEROS = [
        'feminino' => 'Feminino',
        'masculino' => 'Masculino',
        'outro' => 'Outro',
        'nao_informar' => 'Prefiro não informar',
    ];

    public const RELATIONSHIPS = [
        'conjuge' => 'Cônjuge',
        'filho' => 'Filho(a)',
        'pai_mae' => 'Pai/Mãe',
        'irmao' => 'Irmão(ã)',
        'outro' => 'Outro',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        // Quem tiver o segredo TOTP calcula os códigos sozinho, e os de recuperação
        // pulam a segunda etapa inteira: nenhum dos dois pode escapar num `toJson()`.
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'password_changed_at' => 'datetime',
            'pending_email_sent_at' => 'datetime',
            'is_admin' => 'boolean',
            // `date:Y-m-d` (e não só `date`): o cast padrão GRAVA "Y-m-d H:i:s", e o
            // input[type=date] do formulário só aceita "Y-m-d" — sem isto o campo
            // voltava vazio ao reabrir a tela. Mesma pegadinha de `transactions.date`.
            'birth_date' => 'date:Y-m-d',
            'terms_accepted_at' => 'datetime',
            // Prova do aceite (LGPD art. 8º, §1º) cifrada em repouso: um dump de
            // backup vazado não entrega o IP de ninguém. `encrypted`, NUNCA hash —
            // hash é mão única e prova ilegível não prova nada. A coluna virou
            // `text` na migration 2026_08_02_000200 porque o cifrado tem 200-256
            // caracteres e ela era varchar(45).
            'terms_accepted_ip' => 'encrypted',
            // 2FA — o segredo TOTP precisa ser REVERSÍVEL (é com ele que o servidor
            // recalcula o código de 6 dígitos), então cifra, jamais hash. Cifrado em
            // repouso para um dump de backup vazado não entregar a segunda etapa de todo
            // mundo de uma vez.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_step' => 'integer',
        ];
    }

    /**
     * Ao excluir a conta, remover o que o `cascadeOnDelete` do banco NÃO alcança:
     * o arquivo da foto no disco e as linhas da tabela `sessions` (que guardam IP e
     * user-agent). A Política de Privacidade promete que os dados associados são
     * removidos — sem isto, o retrato da pessoa continuaria servido publicamente
     * pelo symlink de `storage/` depois da conta deixar de existir.
     *
     * Os dependentes são apagados aqui, um a um, DE PROPÓSITO: o cascade da FK
     * `account_owner_id` roda no banco e não dispara eventos do Eloquent, então as
     * fotos e sessões deles passariam batido.
     */
    protected static function booted(): void
    {
        static::deleting(function (User $user) {
            foreach ($user->dependents as $dependent) {
                $dependent->delete();
            }

            $user->purgeStoredAvatar();

            BrowserSessions::purgeForUser($user->getKey());
        });
    }

    /** Apaga o arquivo da foto de perfil do disco (não mexe na coluna). */
    public function purgeStoredAvatar(): void
    {
        if (! $this->avatar_path) {
            return;
        }

        Storage::disk(self::AVATAR_DISK)->delete($this->avatar_path);
        // Também no disco antigo: contas criadas antes da mudança para o disco privado
        // têm o arquivo lá, e excluir a conta precisa levar os dois.
        Storage::disk('public')->delete($this->avatar_path);
    }

    /**
     * Guarda a foto de perfil: apaga a anterior, REMOVE OS METADADOS e grava.
     *
     * A limpeza de metadados existe porque estes arquivos vão para o disco `public`,
     * servidos sem autenticação — foto de celular costuma trazer GPS no EXIF. O nome é
     * aleatório e a extensão vem do MIME real (nunca do nome enviado pelo cliente),
     * então não há path traversal nem `.php` disfarçado.
     *
     * Não persiste: quem chama decide quando dar `save()`.
     */
    public function storeAvatar(UploadedFile $arquivo): void
    {
        $this->purgeStoredAvatar();

        $limpo = ImageMetadata::strip((string) file_get_contents($arquivo->getRealPath()));
        $caminho = 'avatars/'.Str::random(40).'.'.$arquivo->extension();

        Storage::disk(self::AVATAR_DISK)->put($caminho, $limpo);

        $this->avatar_path = $caminho;
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /** Transações LANÇADAS por este usuário (made_by_user_id) — base do gasto do dependente. */
    public function madeTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'made_by_user_id');
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** Id do dono da família: o próprio id se titular, ou o do titular se dependente. */
    public function ownerId(): int
    {
        return $this->account_owner_id ?? $this->id;
    }

    public function isTitular(): bool
    {
        return $this->account_owner_id === null;
    }

    public function dependents(): HasMany
    {
        return $this->hasMany(User::class, 'account_owner_id');
    }

    /**
     * Membros da família (titular + dependentes), ordenados por nome.
     * $ownerId = id do titular. O wrapper em closure preserva o agrupamento
     * do OR caso outras cláusulas where sejam encadeadas depois.
     */
    public function scopeFamilyOf($query, int $ownerId)
    {
        return $query->where(function ($q) use ($ownerId) {
            $q->where('id', $ownerId)->orWhere('account_owner_id', $ownerId);
        })->orderBy('name');
    }

    public function titular(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_owner_id');
    }

    /** Rótulo PT-BR do parentesco do dependente (ou null se não informado). */
    public function relationshipLabel(): ?string
    {
        return self::RELATIONSHIPS[$this->relationship] ?? null;
    }

    /**
     * A verificação em duas etapas está LIGADA nesta conta?
     *
     * O que vale é `two_factor_confirmed_at`, não o segredo. Entre gerar o QR e confirmar
     * o primeiro código existe uma janela em que o segredo já existe mas o autenticador
     * talvez nem tenha sido escaneado — cobrar o código ali trancaria a pessoa fora da
     * própria conta. É por isso que o login pergunta por este método, e nunca por
     * `two_factor_secret !== null`.
     */
    public function temDoisFatores(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    /** Segredo gerado, esperando o usuário confirmar o primeiro código (setup em andamento). */
    public function doisFatoresPendente(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at === null;
    }

    /** Quantos códigos de recuperação ainda restam (0 quando o 2FA está desligado). */
    public function codigosDeRecuperacaoRestantes(): int
    {
        return count($this->two_factor_recovery_codes ?? []);
    }

    /** Há um e-mail novo esperando confirmação no próprio endereço novo? */
    public function temEmailPendente(): bool
    {
        return $this->pending_email !== null;
    }

    /**
     * Envia ao ENDEREÇO NOVO o link que confirma a troca.
     *
     * A URL é assinada e expira: quem não tiver acesso à caixa nova não confirma, que é
     * exatamente o ponto. O link vai para `pending_email`, não para o e-mail atual.
     */
    public function sendPendingEmailVerification(): void
    {
        if (! $this->temEmailPendente()) {
            return;
        }

        $url = URL::temporarySignedRoute(
            'profile.email.confirm',
            now()->addHours(2),
            ['user' => $this->getKey(), 'hash' => sha1($this->pending_email)],
        );

        Mail::to($this->pending_email)
            ->send(new ConfirmarNovoEmail($this, $this->pending_email, $url));
    }

    /**
     * URL da foto de perfil (ou null se não houver — a view cai nas iniciais).
     *
     * Aponta para uma ROTA AUTENTICADA, não para o arquivo: quem não estiver logado na
     * mesma família recebe 403. Continua sendo um `<img src>` normal do ponto de vista
     * da view — o navegador manda o cookie de sessão junto.
     */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? route('avatar.show', $this) : null;
    }
}
