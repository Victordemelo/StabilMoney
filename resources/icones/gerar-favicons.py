"""
Favicons para o navegador E para os buscadores (15/10/2026 — o site aparecia sem ícone na Busca do
Google: o /favicon.ico era o arquivo VAZIO do esqueleto do Laravel, e o único PNG tinha 64 px, que
não é múltiplo de 48 — o tamanho que o Google pede). Saem todos do ícone do app (o "S" branco no
verde-mata), que é legível em fundo claro e escuro:

  public/favicon.ico                      16, 32 e 48 px (o que robôs e navegadores pedem na raiz)
  public/apple-touch-icon.png             180 px (alguns robôs pedem direto na raiz)
  public/assets/icons/favicon-48.png      48 px
  public/assets/icons/favicon-96.png      96 px

    /tmp/venv/bin/python -I resources/icones/gerar-favicons.py public/assets/icons/icon-512.png public

Trocou o ícone do app? Rode de novo e suba o `?v=` em `partials/favicons` (os buscadores e o
service worker guardam o ícone pelo endereço).
"""
import sys
from PIL import Image

ORIGEM, PUBLIC = sys.argv[1], sys.argv[2]
icone = Image.open(ORIGEM).convert('RGBA')


def em(lado):
    return icone.resize((lado, lado), Image.LANCZOS)


icone.save(f'{PUBLIC}/favicon.ico', format='ICO', sizes=[(16, 16), (32, 32), (48, 48)])
em(180).convert('RGB').save(f'{PUBLIC}/apple-touch-icon.png', optimize=True)
for lado in (48, 96):
    em(lado).convert('RGB').save(f'{PUBLIC}/assets/icons/favicon-{lado}.png', optimize=True)
print('favicons em', PUBLIC)
