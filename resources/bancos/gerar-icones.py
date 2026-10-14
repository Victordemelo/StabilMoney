"""
Ícones dos bancos (out/2026): os logos que o Victor mandou (design_stabilmoney/imagens/originais/bancos)
viram quadrados arredondados do MESMO tamanho, com o mesmo canto e fundo transparente fora dele —
public/assets/banks/icones/{banco}.png. Quem já vinha num quadrado (Itaú, Inter, Caixa) é recortado;
quem não vinha, ou tinha brilho 3D, é refeito: o logo sobre a cor lisa da marca.

    python3 -m venv /tmp/venv && /tmp/venv/bin/pip install pillow numpy
    /tmp/venv/bin/python -I resources/bancos/gerar-icones.py design_stabilmoney/imagens/originais/bancos public/assets/banks/icones

Banco novo: o original na pasta, uma linha abaixo e o nome em `Account::BANKS`.
"""
import sys
import numpy as np
from PIL import Image, ImageDraw

I, OUT = sys.argv[1], sys.argv[2]
N = 512                 # lado final (nítido em tela retina)
RAIO = int(N * 0.24)    # o mesmo canto em todos

def mascara_arredondada(n=N, r=RAIO):
    k = 4  # supersampling: borda lisa
    m = Image.new('L', (n * k, n * k), 0)
    ImageDraw.Draw(m).rounded_rectangle((0, 0, n * k - 1, n * k - 1), r * k, fill=255)
    return m.resize((n, n), Image.LANCZOS)

def bbox(mask):
    ys, xs = np.where(mask)
    return xs.min(), ys.min(), xs.max() + 1, ys.max() + 1

def quadrado(box, recuo):
    x0, y0, x1, y1 = box
    lado = min(x1 - x0, y1 - y0)
    cx, cy = (x0 + x1) / 2, (y0 + y1) / 2
    lado *= (1 - 2 * recuo)
    return (int(cx - lado / 2), int(cy - lado / 2), int(cx + lado / 2), int(cy + lado / 2))

def finalizar(tile, nome):
    tile = tile.convert('RGBA').resize((N, N), Image.LANCZOS)
    a = np.array(tile)
    a[..., 3] = np.minimum(a[..., 3], np.array(mascara_arredondada()))
    Image.fromarray(a).save(f'{OUT}/{nome}.png', optimize=True)

def recortar_tile(arquivo, nome, seletor, recuo):
    im = Image.open(f'{I}/{arquivo}').convert('RGBA')
    a = np.array(im).astype(int)
    caixa = quadrado(bbox(seletor(a)), recuo)
    finalizar(im.crop(caixa), nome)

def cor_de_fundo(cor):
    return Image.new('RGBA', (N, N), cor + (255,))

def logo_sobre(arquivo, nome, seletor, fundo, cor_logo, escala, regiao=None):
    im = Image.open(f'{I}/{arquivo}').convert('RGBA')
    if regiao:
        im = im.crop(regiao)
    a = np.array(im).astype(int)
    m = seletor(a)
    x0, y0, x1, y1 = bbox(m)
    alfa = Image.fromarray((m * 255).astype('uint8')).crop((x0, y0, x1, y1))
    w, h = alfa.size
    f = escala * N / max(w, h)
    alfa = alfa.resize((max(1, int(w * f)), max(1, int(h * f))), Image.LANCZOS)
    tile = cor_de_fundo(fundo)
    camada = Image.new('RGBA', alfa.size, cor_logo + (255,))
    camada.putalpha(alfa)
    tile.alpha_composite(camada, ((N - alfa.width) // 2, (N - alfa.height) // 2))
    finalizar(tile, nome)

sat = lambda a: (a[..., :3].max(-1) - a[..., :3].min(-1))

# Itaú, Inter: o quadrado colorido sobre fundo claro
recortar_tile('itau.png', 'itau', lambda a: sat(a) > 60, 0.015)
recortar_tile('inter.png', 'inter', lambda a: sat(a) > 60, 0.015)
# Caixa: o azul do quadrado (a sombra cinza fica de fora)
recortar_tile('caixa.png', 'caixa', lambda a: (a[..., 2] > 110) & (a[..., 0] < 60) & (a[..., 3] > 200), 0.01)
# Bradesco: o logo vermelho vira branco sobre o vermelho da marca
logo_sobre('bradesco.png', 'bradesco', lambda a: (a[..., 0] > 170) & (a[..., 1] < 90) & (a[..., 2] < 100), (204, 9, 47), (255, 255, 255), 0.66)
# Banco do Brasil: o logo azul sobre o amarelo, com respiro (no original ele encosta na borda)
logo_sobre('banco_do_brasil.webp', 'banco_do_brasil', lambda a: (a[..., 2] > 120) & (a[..., 0] < 60), (252, 240, 59), (0, 61, 164), 0.70, regiao=(60, 60, 1872, 1872))
# Nubank: o logo branco sobre o roxo, ocupando a largura com respiro

# --- Refeitos no padrão plano (o original tinha brilho 3D / logo encostado na borda) ---
from PIL import ImageDraw as _D

def logo_colorido_sobre(arquivo, nome, fundo_original_xy, fundo, escala, regiao, limiar=28):
    """Logo com as CORES dele, sem o fundo: tudo que difere da cor de fundo do original vira
    logo, e os buracos (as mãos brancas dentro do oval) são preenchidos — a parte de fora é a
    que um balde de tinta alcança a partir do canto."""
    im = Image.open(f'{I}/{arquivo}').convert('RGBA').crop(regiao)
    a = np.array(im).astype(int)
    # Logo = o que tem cor (o azul) ou é escuro (o contorno e o texto); os buracos se fecham abaixo.
    dif = ((a[..., :3].max(-1) - a[..., :3].min(-1)) > 90) | (a[..., :3].max(-1) < 130)
    m = Image.fromarray((dif * 255).astype('uint8')).copy()
    _D.floodfill(m, (0, 0), 128)
    logo = np.array(m) != 128
    x0, y0, x1, y1 = bbox(logo)
    alfa = Image.fromarray((logo * 255).astype('uint8')).crop((x0, y0, x1, y1))
    peca = im.crop((x0, y0, x1, y1))
    peca.putalpha(alfa)
    w, h = peca.size
    f = escala * N / max(w, h)
    peca = peca.resize((int(w * f), int(h * f)), Image.LANCZOS)
    tile = cor_de_fundo(fundo)
    tile.alpha_composite(peca, ((N - peca.width) // 2, (N - peca.height) // 2))
    finalizar(tile, nome)

logo_sobre('santander.png', 'santander', lambda a: (a[..., :3].min(-1) > 225) & (a[..., 3] > 200), (236, 0, 0), (255, 255, 255), 0.66, regiao=(120, 120, 580, 580))
logo_colorido_sobre('mercado_pago.png', 'mercado_pago', (10, 10), (238, 241, 250), 0.76, regiao=(185, 165, 795, 855), limiar=70)
logo_sobre('nubank.png', 'nubank', lambda a: (a[..., :3].min(-1) > 200), (130, 10, 209), (255, 255, 255), 0.74, regiao=(300, 108, 980, 612))

# Versão do app: 256 px (aparece a no máximo 72 px na tela, 2x em tela retina).
import os
for nome in os.listdir(OUT):
    if nome.endswith('.png'):
        caminho = os.path.join(OUT, nome)
        Image.open(caminho).resize((256, 256), Image.LANCZOS).save(caminho, optimize=True)
print('ícones em', OUT)
