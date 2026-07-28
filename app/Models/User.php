<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\BrowserSessions;
use App\Support\ImageMetadata;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
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
        'avatar_path',
        'password',
        'password_changed_at',
        'is_admin',
        'account_owner_id',
        'relationship',
        'terms_accepted_at',
        'terms_version',
        'terms_accepted_ip',
    ];

    /** Graus de parentesco de um dependente (valor no banco => rótulo PT-BR). */
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
            'is_admin' => 'boolean',
            'terms_accepted_at' => 'datetime',
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
        if ($this->avatar_path) {
            Storage::disk('public')->delete($this->avatar_path);
        }
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

        Storage::disk('public')->put($caminho, $limpo);

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

    /** URL pública da foto de perfil (ou null se não houver — a view cai nas iniciais). */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null;
    }
}
