# Stabil Money — guia de design

> **Fonte da verdade da identidade visual do Stabil Money (out/2026).** Toda tela nova, e toda
> mudança visual, segue este guia: as cores, as fontes, as imagens e as regras daqui. O que não
> estiver aqui segue o design system do app (`design/` + `resources/css/design-system.css`), sem
> inventar visual do zero.
>
> Abra `paleta.html` no navegador para ver as cores, as fontes e os componentes lado a lado.

## Onde já está aplicado

| Onde | Arquivos |
|---|---|
| Página inicial pública (`/` para quem não entrou) | `resources/views/inicio.blade.php` + `resources/css/inicio.css` (tokens no escopo `.inicio`) |

O app logado (painel, movimentações etc.) ainda usa o design v2 (`design/`, Sora + Plus Jakarta
Sans). Migrar o app para esta identidade é um passo separado, que precisa de uma decisão do Victor.

## Conceito

**Verde-petróleo profundo com um único destaque lima.** O ponto de partida é a referência da Wise
(`referencias/wise-style-reference.md`): uma cor escura que ocupa as superfícies de peso, um
destaque elétrico usado com parcimônia, títulos enormes e blocados, e botões em pílula. O verde
do Stabil Money é o fundo da ilustração do app (`imagens/web/inicio-app-*.jpg`). Por isso a
imagem se funde com a página, sem moldura.

A página alterna três tipos de faixa, e é esse o ritmo da página. Nada de enfeite para criar ritmo.

- **Escura** (`--mata`): o topo, a abertura, Segurança e a chamada final.
- **Clara** (`--papel` ou `--nevoa`): o conteúdo.
- **Creme** (`--creme`): as ilustrações claras.

## Cores

| Nome | Hex | Token | Uso |
|---|---|---|---|
| Mata | `#032628` | `--mata` | Superfícies escuras: topo, abertura, faixa de Segurança, bloco da chamada final. Também o fundo dos números dos passos. |
| Abeto | `#0A3A36` | `--abeto` | Ícones, texto forte sobre claro, contorno de foco sobre claro. |
| Lima | `#9FE870` | `--lima` | **O único destaque.** Botão da ação principal, marcas de confirmação ✓, número dos passos, linha acima das garantias de segurança, contorno de foco sobre escuro. Nunca em texto corrido sobre branco (contraste baixo). |
| Lima claro | `#E4F7D4` | `--lima-claro` | Fundo dos ícones. |
| Papel | `#FFFFFF` | `--papel` | Fundo padrão. |
| Névoa | `#EEF2EC` | `--nevoa` | Faixa neutra (perguntas frequentes). |
| Creme | `#FBF7F2` | `--creme` | Fundo das ilustrações claras (equilíbrio, contas). |
| Tinta | `#0E1A14` | `--tinta` | Títulos. Também o texto do botão lima. |
| Grafite | `#454745` | `--grafite` | Texto corrido. |
| Apagado | `#6B726D` | `--apagado` | Texto secundário (rodapé). |
| Linha | `#DDE3DC` | `--linha` | Divisórias. |
| Texto no escuro | `#B9CBC5` | `--no-escuro` | Texto corrido sobre o mata (o título fica branco). |

Os valores estão em `tokens.css`.

## Fontes

| Papel | Fonte | Pesos |
|---|---|---|
| Títulos (h1, h2, h3, marca, número dos passos) | **Bricolage Grotesque** | 800 nos títulos, 700 nos subtítulos |
| Texto, botões, menu | **Geist** | 400 no texto, 500 no menu, 600 nos botões |

Link do Google Fonts (já liberado na CSP e citado na Política de Privacidade):

```
https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600..800&family=Geist:wght@400..600&display=swap
```

**Escala:**

| Nível | Tamanho | Entrelinha | Espaçamento entre letras |
|---|---|---|---|
| h1 (abertura) | `clamp(46px, 5.6vw, 84px)` | .92 | −.038em |
| h2 (seção) | `clamp(34px, 4.4vw, 60px)` | .98 | −.035em |
| h3 | 21px | 1.15 | −.015em |
| Abertura de seção | 18px | 1.6 | — |
| Texto | 16px | 1.6 | — |

O título é o elemento de impacto da página. É blocado, apertado e grande. Linhas de texto até
~60 caracteres (`max-width: 60ch`).

## Componentes

- **Botões em pílula** (`border-radius: 999px`):
  - Principal: fundo lima com texto tinta. Só para a ação principal ("Criar conta grátis").
  - Secundário: contorno de 1,5px na cor do texto ao redor (`currentColor`). Funciona sobre claro e sobre escuro.
  - Link de texto: sublinhado grosso (`.in-link`), para a ação secundária ao lado do botão principal ("Já tenho conta").
- **Lista com confirmação**: círculo lima com ✓ em mata (Família e os pontos da abertura).
- **Ícones**: traço de 1,8px, em círculo de 48px com fundo lima claro e traço abeto.
- **Recursos**: itens com ícone + título + texto, separados por uma linha fina no topo. **Nunca
  uma grade de cards iguais com sombra.**
- **Passos numerados**: só quando o conteúdo É uma sequência (o "Como funciona"). O número fica
  num círculo mata, em lima.
- **Perguntas**: `details` brancos com raio de 22px sobre a névoa; um "+" que gira para "×" ao abrir.
- **Foto do autor**: retangular 4:5 e raio de 28px. Nada de foto redonda.

## Imagens

| Arquivo | Onde | Observação |
|---|---|---|
| `imagens/originais/app-no-celular-escuro.png` → `public/assets/inicio-app-1672.jpg` / `-960.jpg` | Abertura (ao fundo, à direita) | O fundo da imagem é o próprio `--mata`. No celular, a imagem desce para depois do texto. |
| `imagens/originais/contas-claro.png` → `public/assets/inicio-contas-1672.jpg` / `-960.jpg` | "Como funciona" (à direita dos passos) | Fundo `#FDFAF6`. |
| `imagens/originais/equilibrio-carteira.png` → `public/assets/equilibrio-1600.jpg` / `-900.jpg` | Faixa "Equilíbrio" | Bordas esfumadas por máscara radial, para sumirem no creme. |
| `imagens/originais/foto-victor.png` → `public/assets/victor-de-melo.jpg` | "Quem fez" | 583×600. |
| `marca/stabilmoney-mark.png`, `marca/favicon.png`, `marca/icon-512.png` | Marca | O "S" da marca. Ao lado dele, "Stabil" no texto e "Money" no destaque. |
| `imagens/web/og-stabilmoney.jpg` | Prévia de link (redes) | 1200×630. Trocou o conteúdo? Troque o nome do arquivo. |

**Estilo das ilustrações:** objetos 3D foscos (celular, calculadora, carteira, moedas, planta)
em verdes, bege e dourado. Iluminação suave e o lado esquerdo vazio, para o texto.

Toda ilustração nova segue este estilo e entra em duas versões JPEG: uma grande (~1600px) e uma
para celular (~960px), cada uma com menos de 260 KB. O teste `PaginaInicialPublicaTest` cobra o
tamanho.

**Para gerar a versão web de uma imagem** (macOS):

```bash
sips -s format jpeg -s formatOptions 78 -Z 1672 original.png --out public/assets/nome-1672.jpg
```

Repita com `-Z 960` para a versão de celular.

## Layout

- Conteúdo com até 1200px de largura e 24px de margem lateral (mais a área segura do aparelho).
- O conteúdo fica alinhado à esquerda. Só a faixa Equilíbrio e a chamada final são centralizadas.
- Seções com 120px de respiro vertical (80px no celular).
- Abertura e "Como funciona": a imagem fica ao fundo, à direita, e o texto à esquerda. Abaixo de
  980px, a imagem desce para depois do texto.
- Responsivo de 2000px até 300px, sem rolagem lateral.

## Movimento

Um único movimento automático: o texto da abertura sobe uma vez ao carregar. Fora ele, só
movimento que responde a uma ação (o "+" das perguntas gira). O `prefers-reduced-motion` desliga
os dois.

## Não fazer

- Rótulo em MAIÚSCULAS acima dos títulos.
- Destacar uma palavra só do título (itálico ou outra cor).
- Degradê como enfeite. A máscara que funde a imagem com o fundo é funcional e pode ficar.
- Grade de cards iguais com a mesma sombra.
- Textos com pontos no meio, como "A · B · C".
- "→" no fim de botão ou link.
- Lima em texto sobre fundo claro.
- Uma segunda cor de destaque.

## Conteúdo desta pasta

```
design_stabilmoney/
├── README.md                       este guia
├── tokens.css                      os tokens (cores, fontes, escala, raios, espaço)
├── paleta.html                     a paleta, as fontes e os componentes, para abrir no navegador
├── marca/                          logo (S), favicon, ícone 512
├── imagens/originais/              as imagens como vieram (ChatGPT e a foto do autor)
├── imagens/web/                    as versões otimizadas que o site serve (cópia de public/assets)
├── referencias/                    a referência de estilo da Wise usada como base
└── capturas/                       a página inicial nova no computador e no celular
```
