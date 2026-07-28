<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ImageMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regressões da "onda 2" do pentest: headers de segurança, senha atual para trocar
 * e-mail, política de senha e remoção de metadados das fotos.
 */
class SecurityHardeningWave2Test extends TestCase
{
    use RefreshDatabase;

    /**
     * JPEG mínimo válido com um segmento APP1 (onde o EXIF/GPS vive) e um APP0.
     * Estrutura: SOI + APP0 + APP1(payload marcado) + SOF0 + SOS + dados + EOI.
     */
    private function jpegComExif(string $marcaSecreta = 'GPS-LAT-SECRETA'): string
    {
        $app1 = "Exif\x00\x00".$marcaSecreta;
        $seg = fn (string $marcador, string $dados) => $marcador
            .pack('n', strlen($dados) + 2)
            .$dados;

        return "\xFF\xD8"                                   // SOI
            .$seg("\xFF\xE0", "JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00") // APP0
            .$seg("\xFF\xE1", $app1)                        // APP1 (EXIF)
            .$seg("\xFF\xDB", str_repeat("\x10", 65))       // DQT (tabela — deve ficar)
            .$seg("\xFF\xC0", "\x08\x00\x10\x00\x10\x01\x01\x11\x00") // SOF0
            .$seg("\xFF\xC4", "\x00".str_repeat("\x00", 16).str_repeat("\x00", 1)) // DHT
            ."\xFF\xDA\x00\x08\x01\x01\x00\x00\x3F\x00"     // SOS
            ."\x00\x01\x02\x03dados-da-imagem"              // payload comprimido (fake)
            ."\xFF\xD9";                                    // EOI
    }

    public function test_strip_removes_exif_segment_from_jpeg(): void
    {
        $original = $this->jpegComExif();

        $this->assertStringContainsString('GPS-LAT-SECRETA', $original, 'O fixture deve conter EXIF.');

        $limpo = ImageMetadata::strip($original);

        $this->assertStringNotContainsString('GPS-LAT-SECRETA', $limpo);
        $this->assertStringNotContainsString('Exif', $limpo);
        // Continua um JPEG e preserva o que NÃO é metadado.
        $this->assertStringStartsWith("\xFF\xD8", $limpo);
        $this->assertStringEndsWith("\xFF\xD9", $limpo);
        $this->assertStringContainsString('dados-da-imagem', $limpo, 'A imagem em si não pode ser perdida.');
        $this->assertStringContainsString("\xFF\xC0", $limpo, 'O SOF0 (dimensões) deve permanecer.');
    }

    public function test_strip_removes_text_chunks_from_png(): void
    {
        $chunk = function (string $tipo, string $dados) {
            return pack('N', strlen($dados)).$tipo.$dados.pack('N', crc32($tipo.$dados));
        };

        $png = "\x89PNG\r\n\x1A\n"
            .$chunk('IHDR', pack('NN', 1, 1)."\x08\x02\x00\x00\x00")
            .$chunk('tEXt', "Comment\x00LOCALIZACAO-SECRETA")
            .$chunk('IDAT', 'dados-comprimidos')
            .$chunk('IEND', '');

        $limpo = ImageMetadata::strip($png);

        $this->assertStringNotContainsString('LOCALIZACAO-SECRETA', $limpo);
        $this->assertStringContainsString('IHDR', $limpo);
        $this->assertStringContainsString('dados-comprimidos', $limpo);
        $this->assertStringContainsString('IEND', $limpo);
    }

    /** Formato desconhecido não deve ser corrompido — devolve como veio. */
    public function test_strip_leaves_unknown_formats_untouched(): void
    {
        $bytes = 'nao-e-imagem-nenhuma';

        $this->assertSame($bytes, ImageMetadata::strip($bytes));
    }

    /** O caminho real: upload de avatar grava a versão sem EXIF no disco. */
    public function test_uploaded_avatar_is_stored_without_exif(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => UploadedFile::fake()->createWithContent('foto.jpg', $this->jpegComExif()),
        ])->assertSessionHasNoErrors();

        $caminho = $user->fresh()->avatar_path;
        $this->assertNotNull($caminho, 'O avatar deveria ter sido gravado.');

        $gravado = Storage::disk('public')->get($caminho);
        $this->assertStringNotContainsString('GPS-LAT-SECRETA', $gravado);
        $this->assertStringContainsString('dados-da-imagem', $gravado);
    }

    /**
     * Trocar o e-mail exige a senha atual: o e-mail é o que recupera a conta, então
     * sem isto uma sessão sequestrada virava tomada de conta definitiva.
     */
    public function test_changing_email_requires_current_password(): void
    {
        $user = User::factory()->create(['email' => 'dono@example.com', 'password' => 'senha-real']);

        $this->actingAs($user)
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => 'atacante@example.com',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertSame('dono@example.com', $user->fresh()->email);
    }

    public function test_changing_email_with_wrong_password_fails(): void
    {
        $user = User::factory()->create(['email' => 'dono@example.com', 'password' => 'senha-real']);

        $this->actingAs($user)
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => 'atacante@example.com',
                'current_password' => 'chute-errado',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertSame('dono@example.com', $user->fresh()->email);
    }

    public function test_changing_email_works_with_correct_password(): void
    {
        $user = User::factory()->create(['email' => 'dono@example.com', 'password' => 'senha-real']);

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => 'novo@example.com',
                'current_password' => 'senha-real',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('novo@example.com', $user->fresh()->email);
    }

    /** Mudar só o nome NÃO deve pedir senha — senão a tela vira um pedágio. */
    public function test_updating_name_only_does_not_require_password(): void
    {
        $user = User::factory()->create(['email' => 'dono@example.com', 'name' => 'Antigo']);

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Nome Novo',
                'email' => 'dono@example.com',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Nome Novo', $user->fresh()->name);
    }

    /** Cabeçalhos de segurança presentes nas respostas do app. */
    public function test_security_headers_are_sent(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp, 'A CSP precisa estar presente.');
        // O essencial: nada de iframe de terceiro, nada de plugin, form e base travados.
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        // Exfiltração para domínio externo barrada.
        $this->assertStringContainsString("connect-src 'self'", $csp);
    }

    /** Páginas públicas (login, termos) também recebem os headers. */
    public function test_security_headers_on_public_pages(): void
    {
        $this->get(route('login'))->assertOk()->assertHeader('X-Frame-Options', 'DENY');
        $this->get('/termos')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /** A política de senha do app rejeita menos de 8 caracteres em todos os fluxos. */
    public function test_password_policy_rejects_short_passwords(): void
    {
        $this->post(route('register'), [
            'name' => 'Curta',
            'email' => 'curta@example.com',
            'password' => 'abc123',
            'terms' => '1',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'curta@example.com']);
    }
}
