"""
Cartões dos bancos (out/2026): no lugar das imagens de 164×100 px (cada uma num layout diferente,
pixeladas no card), o MESMO desenho para todos, nítido em tela retina — como os ícones:
a cor lisa da marca, o ícone do banco (o mesmo de public/assets/banks/icones) no canto, o chip,
o símbolo de aproximação e o nome do banco embaixo. Sem bandeira (Visa/Mastercard): o app não
sabe qual é a do cartão da pessoa.

    /tmp/venv/bin/python -I resources/bancos/gerar-cartoes.py public/assets/banks/icones public/assets/banks

Gere os ícones antes (gerar-icones.py): o cartão usa o ícone pronto.
Banco novo: uma linha em BANCOS e o ícone dele.
"""
import sys
from PIL import Image, ImageDraw, ImageFont

ICONES, OUT = sys.argv[1], sys.argv[2]
K = 3                     # supersampling: bordas lisas
W, H = 640, 404           # 1,586 — a proporção do cartão de verdade (ISO/IEC 7810 ID-1)
RAIO = 30                 # o canto do cartão
FONTE = '/System/Library/Fonts/Supplemental/Arial Bold.ttf'

# banco: (cor do cartão, cor do texto e dos símbolos, nome no cartão)
BANCOS = {
    'banco_do_brasil': ((252, 232, 59), (0, 61, 164), 'Banco do Brasil'),
    'bradesco': ((204, 9, 47), (255, 255, 255), 'Bradesco'),
    'caixa': ((0, 92, 169), (255, 255, 255), 'Caixa'),
    'inter': ((255, 122, 0), (255, 255, 255), 'Inter'),
    'itau': ((236, 112, 0), (255, 255, 255), 'Itaú'),
    'mercado_pago': ((0, 158, 227), (255, 255, 255), 'Mercado Pago'),
    'nubank': ((130, 10, 209), (255, 255, 255), 'Nubank'),
    'santander': ((236, 0, 0), (255, 255, 255), 'Santander'),
}


def s(v):
    return int(v * K)


def chip(d, x, y):
    """O chip: dourado, de canto arredondado, com as trilhas de contato."""
    w, h = s(70), s(54)
    d.rounded_rectangle((x, y, x + w, y + h), s(10), fill=(226, 196, 120))
    linha = (176, 146, 78)
    t = s(2)
    d.line((x + w * 0.33, y, x + w * 0.33, y + h), fill=linha, width=t)
    d.line((x + w * 0.67, y, x + w * 0.67, y + h), fill=linha, width=t)
    d.line((x, y + h * 0.5, x + w * 0.33, y + h * 0.5), fill=linha, width=t)
    d.line((x + w * 0.67, y + h * 0.5, x + w, y + h * 0.5), fill=linha, width=t)
    d.rounded_rectangle((x + w * 0.33, y + h * 0.28, x + w * 0.67, y + h * 0.72), s(5), outline=linha, width=t)


def aproximacao(d, x, y, cor):
    """O símbolo de pagamento por aproximação: três arcos."""
    for i, r in enumerate((s(12), s(22), s(32))):
        d.arc((x - r, y - r, x + r, y + r), -50, 50, fill=cor, width=s(4.5))


def cartao(banco, fundo, frente, nome):
    img = Image.new('RGBA', (s(W), s(H)), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    d.rounded_rectangle((0, 0, s(W) - 1, s(H) - 1), s(RAIO), fill=fundo + (255,))

    # Uma forma só, lisa (sem degradê): o círculo grande que sai pela direita, um tom acima da cor.
    claro = tuple(min(255, c + 18) for c in fundo) + (255,)
    sombra = Image.new('RGBA', img.size, (0, 0, 0, 0))
    ImageDraw.Draw(sombra).ellipse((s(W * 0.50), s(-H * 0.45), s(W * 1.45), s(H * 1.45)), fill=claro)
    mascara = Image.new('L', img.size, 0)
    ImageDraw.Draw(mascara).rounded_rectangle((0, 0, s(W) - 1, s(H) - 1), s(RAIO), fill=255)
    img.paste(sombra, (0, 0), Image.composite(sombra.getchannel('A'), Image.new('L', img.size, 0), mascara))
    d = ImageDraw.Draw(img)

    # O ícone do banco no canto, do mesmo tamanho em todos, com o mesmo contorno branco — o
    # quadrado aparece igual em todo cartão, também quando a cor dele é a do próprio cartão.
    lado = s(88)
    icone = Image.open(f'{ICONES}/{banco}.png').convert('RGBA').resize((lado, lado), Image.LANCZOS)
    margem = s(36)
    anel = s(4)
    d.rounded_rectangle((margem - anel, margem - anel, margem + lado + anel - 1, margem + lado + anel - 1),
                        int(lado * 0.24) + anel, fill=(255, 255, 255, 235))
    img.alpha_composite(icone, (margem, margem))

    chip(d, margem, s(176))
    aproximacao(d, margem + s(116), s(203), frente + (230,))

    f = ImageFont.truetype(FONTE, s(30))
    d.text((margem, s(H) - margem - s(30)), nome, font=f, fill=frente + (255,))

    final = img.resize((W, H), Image.LANCZOS)
    final.save(f'{OUT}/{banco}.png', optimize=True)


for banco, (fundo, frente, nome) in BANCOS.items():
    cartao(banco, fundo, frente, nome)
print('cartões em', OUT)
