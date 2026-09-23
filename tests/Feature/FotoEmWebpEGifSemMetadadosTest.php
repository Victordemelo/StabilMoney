<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ImageMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Foto em WebP ou GIF chega ao disco sem os metadados (achado A-13 da auditoria de 05/09/2026).
 *
 * O defeito: `ImageMetadata::strip()` só conhecia JPEG e PNG, mas a regra `image` aceita também
 * WebP e GIF — e o arquivo desses formatos era gravado como veio. Um WebP tirado ou salvo com
 * localização carrega o GPS no chunk `EXIF` (e no `XMP `); um GIF, em comentários e extensões
 * de aplicação (o XMP vive em "XMP DataXMP").
 *
 * O comportamento certo, no nível dos bytes (o GD do container não tem WebP nem JPEG para
 * re-encodar): WebP perde `EXIF`, `XMP ` e qualquer chunk que não desenhe a imagem, os bits
 * deles no `VP8X` e o que vier depois do fim do RIFF, com o tamanho do RIFF recalculado; GIF
 * perde comentários, texto puro e extensões de aplicação — menos a de repetição da animação —,
 * e o que vier depois do fim. A imagem continua a mesma.
 *
 * Os arquivos são montados byte a byte, com GPS de verdade: o EXIF é um TIFF com o bloco de GPS
 * que a própria extensão `exif` do PHP lê (conferido abaixo), e a imagem do WebP é um bitstream
 * VP8L real de 5×3 (gerado por um codificador de verdade, libwebp).
 */
class FotoEmWebpEGifSemMetadadosTest extends TestCase
{
    use RefreshDatabase;

    /** Bitstream VP8L (sem perdas) de uma imagem 5×3 com transparência, saído do libwebp. */
    private const VP8L_5X3 = '2f0480001007508f2257ab808188e87f00';

    /** 23°33'7" S — os três racionais da latitude, como ficam gravados no EXIF. */
    private const LATITUDE = [23, 1, 33, 1, 7, 1];

    /** 46°38'34" O. */
    private const LONGITUDE = [46, 1, 38, 1, 34, 1];

    private const XMP = '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?><x:xmpmeta xmlns:x="adobe:ns:meta/">'
        .'<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description '
        .'xmlns:exif="http://ns.adobe.com/exif/1.0/" exif:GPSLatitude="23,33.1167S" exif:GPSLongitude="46,38.5667W"/>'
        .'</rdf:RDF></x:xmpmeta><?xpacket end="w"?>';

    // ══════════════════════════════════════════════════ montagem dos arquivos

    /** TIFF little-endian com IFD0 apontando para um bloco de GPS (latitude e longitude). */
    private function tiffComGps(): string
    {
        $inicioDoGps = 8 + 2 + 12 + 4;
        $inicioDosDados = $inicioDoGps + 2 + 4 * 12 + 4;

        return "II*\x00".pack('V', 8)
            // IFD0: uma entrada só, GPSInfo (0x8825) → onde começa o bloco de GPS.
            .pack('v', 1).pack('vvVV', 0x8825, 4, 1, $inicioDoGps).pack('V', 0)
            // Bloco de GPS: referência e valor da latitude e da longitude.
            .pack('v', 4)
            .pack('vvV', 0x0001, 2, 2)."S\x00\x00\x00"
            .pack('vvVV', 0x0002, 5, 3, $inicioDosDados)
            .pack('vvV', 0x0003, 2, 2)."W\x00\x00\x00"
            .pack('vvVV', 0x0004, 5, 3, $inicioDosDados + 24)
            .pack('V', 0)
            .pack('V6', ...self::LATITUDE)
            .pack('V6', ...self::LONGITUDE);
    }

    private function chunk(string $tipo, string $dados): string
    {
        return $tipo.pack('V', strlen($dados)).$dados.(strlen($dados) % 2 === 1 ? "\x00" : '');
    }

    private function riff(string ...$chunks): string
    {
        $corpo = implode('', $chunks);

        return 'RIFF'.pack('V', 4 + strlen($corpo)).'WEBP'.$corpo;
    }

    /** VP8X de uma imagem 5×3, com as flags dadas (0x10 transparência, 0x08 EXIF, 0x04 XMP). */
    private function vp8x(int $flags): string
    {
        return $this->chunk('VP8X', chr($flags)."\x00\x00\x00"."\x04\x00\x00"."\x02\x00\x00");
    }

    /** Como o celular grava: formato estendido, a imagem, e depois EXIF e XMP. */
    private function webpComGps(): string
    {
        return $this->riff(
            $this->vp8x(0x10 | 0x08 | 0x04),
            $this->chunk('VP8L', hex2bin(self::VP8L_5X3)),
            $this->chunk('EXIF', "Exif\x00\x00".$this->tiffComGps()),
            $this->chunk('XMP ', self::XMP),
        );
    }

    /** @return array<string, int> FourCC => tamanho, na ordem em que aparecem */
    private function chunksDe(string $webp): array
    {
        $chunks = [];
        $i = 12;

        while ($i + 8 <= strlen($webp)) {
            $tamanho = unpack('V', substr($webp, $i + 4, 4))[1];
            $chunks[substr($webp, $i, 4)] = $tamanho;
            $i += 8 + $tamanho + ($tamanho % 2);
        }

        return $chunks;
    }

    private function assertSemGps(string $bytes): void
    {
        $this->assertStringNotContainsString(pack('V6', ...self::LATITUDE), $bytes, 'A latitude continua no arquivo.');
        $this->assertStringNotContainsString(pack('V6', ...self::LONGITUDE), $bytes, 'A longitude continua no arquivo.');
        $this->assertStringNotContainsString('GPSLatitude', $bytes, 'O XMP com a localização continua no arquivo.');
        $this->assertStringNotContainsString("II*\x00", $bytes, 'O EXIF continua no arquivo.');
    }

    /**
     * GIF animado de dois quadros, montado a partir do que o GD grava, com tudo o que carrega
     * metadado no meio: comentário, XMP (pacote cru + o "trailer mágico" de 258 bytes, como a
     * especificação manda), texto puro, uma extensão de aplicação qualquer e lixo depois do fim.
     *
     * @return array{0: string, 1: string} [com metadados, o que deve sobrar]
     */
    private function gifs(): array
    {
        $quadro = function (int $vermelho, int $azul): array {
            $imagem = imagecreate(4, 3);
            imagecolorallocate($imagem, $vermelho, 0, $azul);
            ob_start();
            imagegif($imagem);
            $gif = (string) ob_get_clean();

            // Cabeçalho + tela + tabela global (GD grava 2 cores: 6 bytes) | o bloco da imagem.
            return [substr($gif, 0, 19), substr($gif, 19, -1)];
        };

        [$cabecalho, $imagem1] = $quadro(255, 0);
        [, $imagem2] = $quadro(0, 255);

        $cabecalho = 'GIF89a'.substr($cabecalho, 6); // extensões são do 89a
        $repeticao = "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00";
        $controle = fn (int $atraso) => "\x21\xF9\x04\x00".pack('v', $atraso)."\x00\x00";

        $comentario = 'GPSLatitude -23.5519 casa da familia';
        $trailerMagico = "\x01".implode('', array_map('chr', range(0xFF, 0x00)))."\x00";

        $comMetadados = $cabecalho.$repeticao
            ."\x21\xFE".chr(strlen($comentario)).$comentario."\x00"
            ."\x21\xFF\x0BXMP DataXMP".self::XMP.$trailerMagico
            ."\x21\xFF\x0BICCRGBG1012\x04dado\x00"
            .$controle(10).$imagem1
            ."\x21\x01\x0C".str_repeat("\x00", 12)."\x04OLA!\x00"
            .$controle(30).$imagem2
            ."\x3B".'GPSLatitude depois do fim';

        $limpo = $cabecalho.$repeticao.$controle(10).$imagem1.$controle(30).$imagem2."\x3B";

        return [$comMetadados, $limpo];
    }

    // ══════════════════════════════════════════════════ WebP

    /** O fixture carrega GPS de verdade: a extensão `exif` do PHP o lê. */
    public function test_o_exif_montado_tem_gps_de_verdade(): void
    {
        $exif = exif_read_data('data://image/tiff;base64,'.base64_encode($this->tiffComGps()));

        $this->assertSame('S', $exif['GPSLatitudeRef'] ?? null);
        $this->assertSame(['23/1', '33/1', '7/1'], $exif['GPSLatitude'] ?? null);
    }

    public function test_webp_perde_o_exif_e_o_xmp_e_continua_a_mesma_imagem(): void
    {
        $original = $this->webpComGps();
        $limpo = ImageMetadata::strip($original);

        $this->assertSemGps($limpo);

        // Só os chunks da imagem, sem os bits de EXIF e XMP no VP8X (a transparência fica).
        $this->assertSame(['VP8X', 'VP8L'], array_keys($this->chunksDe($limpo)));
        $this->assertSame(0x10, ord($limpo[20]), 'O VP8X continua anunciando EXIF/XMP que não existem mais.');

        // O RIFF declara o tamanho certo, e a imagem é a mesma, byte a byte.
        $this->assertSame(strlen($limpo) - 8, unpack('V', substr($limpo, 4, 4))[1]);
        $this->assertStringContainsString($this->chunk('VP8L', hex2bin(self::VP8L_5X3)), $limpo);

        // E continua sendo um WebP 5×3 para quem o lê.
        $tamanho = getimagesizefromstring($limpo);
        $this->assertSame([5, 3, IMAGETYPE_WEBP], [$tamanho[0], $tamanho[1], $tamanho[2]]);
    }

    /** EXIF fora do lugar (WebP simples não tem onde anunciá-lo) sai do mesmo jeito. */
    public function test_webp_simples_com_exif_tambem_perde(): void
    {
        $limpo = ImageMetadata::strip($this->riff(
            $this->chunk('VP8L', hex2bin(self::VP8L_5X3)),
            $this->chunk('EXIF', $this->tiffComGps()),
        ));

        $this->assertSemGps($limpo);
        $this->assertSame(['VP8L'], array_keys($this->chunksDe($limpo)));
        $this->assertSame(strlen($limpo) - 8, unpack('V', substr($limpo, 4, 4))[1]);
    }

    /**
     * Chunk que ninguém conhece (é onde um programa esconderia o que quisesse) e bytes depois do
     * fim que o RIFF declara também saem. O desconhecido tem tamanho ímpar: o byte de
     * preenchimento dele não pode desalinhar a leitura do que vem depois.
     */
    public function test_webp_perde_chunk_desconhecido_e_o_que_vem_depois_do_fim(): void
    {
        $original = $this->riff(
            $this->vp8x(0x10 | 0x08),
            $this->chunk('ABCD', 'GPSLatitude impar'),
            $this->chunk('VP8L', hex2bin(self::VP8L_5X3)),
            $this->chunk('EXIF', $this->tiffComGps()),
        ).'GPSLatitude depois do fim';

        $limpo = ImageMetadata::strip($original);

        $this->assertSemGps($limpo);
        $this->assertSame(['VP8X', 'VP8L'], array_keys($this->chunksDe($limpo)));
        $this->assertSame(strlen($limpo) - 8, unpack('V', substr($limpo, 4, 4))[1]);
        $this->assertSame([5, 3], array_slice(getimagesizefromstring($limpo), 0, 2));
    }

    /** Arquivo inconsistente volta como veio, como já acontecia com JPEG e PNG. */
    public function test_webp_corrompido_volta_como_veio(): void
    {
        $corrompido = substr($this->webpComGps(), 0, 40);

        $this->assertSame($corrompido, ImageMetadata::strip($corrompido));
    }

    // ══════════════════════════════════════════════════ GIF

    public function test_gif_perde_comentario_xmp_e_texto_e_continua_animado(): void
    {
        [$comMetadados, $esperado] = $this->gifs();

        $limpo = ImageMetadata::strip($comMetadados);

        // Exatamente: cabeçalho, repetição da animação, e os dois quadros com o tempo de cada um.
        $this->assertSame(bin2hex($esperado), bin2hex($limpo));
        $this->assertStringContainsString('NETSCAPE2.0', $limpo, 'O GIF animado parou de repetir.');

        // E continua abrindo, com as mesmas dimensões.
        $this->assertNotFalse(imagecreatefromstring($limpo));
        $this->assertSame([4, 3, IMAGETYPE_GIF], array_slice(getimagesizefromstring($limpo), 0, 3));
    }

    public function test_gif_cortado_antes_do_fim_perde_os_metadados_e_ganha_o_fecho(): void
    {
        [$comMetadados] = $this->gifs();

        // Sem o 0x3B e o que vem depois: o navegador mostra um GIF assim.
        $cortado = substr($comMetadados, 0, strrpos($comMetadados, "\x3B"));

        $limpo = ImageMetadata::strip($cortado);

        $this->assertStringNotContainsString('GPSLatitude', $limpo);
        $this->assertStringEndsWith("\x3B", $limpo);
        $this->assertNotFalse(imagecreatefromstring($limpo));
    }

    // ══════════════════════════════════════════════════ o caminho real

    public function test_foto_em_webp_pelo_perfil_chega_ao_disco_sem_gps(): void
    {
        Storage::fake(User::AVATAR_DISK);
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => UploadedFile::fake()->createWithContent('foto.webp', $this->webpComGps()),
        ])->assertSessionHasNoErrors();

        $caminho = $user->fresh()->avatar_path;

        $this->assertStringEndsWith('.webp', $caminho);
        $this->assertSemGps(Storage::disk(User::AVATAR_DISK)->get($caminho));
    }

    public function test_foto_em_gif_do_dependente_chega_ao_disco_sem_metadados(): void
    {
        Storage::fake(User::AVATAR_DISK);
        Mail::fake();
        $titular = User::factory()->create(['is_admin' => true]);

        [$comMetadados, $esperado] = $this->gifs();

        $this->actingAs($titular)->post(route('dependentes.store'), [
            'name' => 'Bia',
            'email' => 'bia@familia.test',
            'password' => 'senha-do-dependente-123',
            'avatar' => UploadedFile::fake()->createWithContent('foto.gif', $comMetadados),
        ])->assertSessionHasNoErrors();

        $caminho = User::where('email', 'bia@familia.test')->value('avatar_path');

        $this->assertStringEndsWith('.gif', $caminho);
        $this->assertSame(bin2hex($esperado), bin2hex(Storage::disk(User::AVATAR_DISK)->get($caminho)));
    }
}
