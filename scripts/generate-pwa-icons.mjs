/**
 * Gera os ícones do PWA a partir da logo da marca.
 *
 * Fonte: public/assets/stabilmoney-mark.png (256×256, símbolo verde sobre
 * transparente). O JPG de 1024px tem xadrez de transparência queimado no fundo,
 * por isso NÃO serve como fonte.
 *
 * Saída: public/assets/icons/ — símbolo BRANCO (via canal alpha da logo)
 * centralizado sobre fundo VERDE da marca (#0C3D2B, o mesmo theme-color).
 * Os PNGs gerados são commitados; produção não precisa do sharp.
 *
 * Rodar no host (tem Node): `node scripts/generate-pwa-icons.mjs`
 */
import sharp from 'sharp';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

const SRC = resolve('public/assets/stabilmoney-mark.png');
const OUT = resolve('public/assets/icons');
const GREEN = { r: 12, g: 61, b: 43, alpha: 1 }; // #0C3D2B

mkdirSync(OUT, { recursive: true });

/** Silhueta branca do símbolo: usa o canal alpha da logo como máscara. */
async function whiteSilhouette(size) {
  const mark = await sharp(SRC)
    .resize(size, size, { fit: 'contain', background: { r: 0, g: 0, b: 0, alpha: 0 } })
    .ensureAlpha()
    .png()
    .toBuffer();

  const alpha = await sharp(mark).extractChannel('alpha').toColourspace('b-w').toBuffer();

  return sharp({
    create: { width: size, height: size, channels: 3, background: { r: 255, g: 255, b: 255 } },
  })
    .joinChannel(alpha)
    .png()
    .toBuffer();
}

/** Compõe o símbolo branco (markRatio do canvas) centralizado sobre o fundo verde. */
async function icon(canvasSize, markRatio, outName) {
  const inner = Math.round(canvasSize * markRatio);
  const mark = await whiteSilhouette(inner);

  await sharp({
    create: { width: canvasSize, height: canvasSize, channels: 4, background: GREEN },
  })
    .composite([{ input: mark, gravity: 'center' }])
    .png()
    .toFile(resolve(OUT, outName));

  console.log('  ✓', outName, `(${canvasSize}px)`);
}

console.log('Gerando ícones do PWA em public/assets/icons/ ...');
await icon(192, 0.70, 'icon-192.png');
await icon(512, 0.70, 'icon-512.png');
await icon(512, 0.60, 'icon-maskable-512.png'); // safe-zone (80%) para maskable
await icon(180, 0.70, 'apple-touch-icon.png');  // iOS (opaco)
console.log('Pronto.');
