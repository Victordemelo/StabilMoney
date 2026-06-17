# Assets do sistema (imagens da marca)

**Esta é a pasta padrão das imagens do sistema.** É de onde o app serve logo, favicon e
vídeo — referencie sempre com `asset('assets/<arquivo>')` nas views.

| Arquivo | O que é | Formato | Usado em |
|---|---|---|---|
| `stabilmoney-mark.png` | Logo (símbolo "S" + seta) | PNG transparente | sidebar, topbar, auth, favicon Apple, **card "Meu cartão"** (renderizada em branco via CSS `.cc-mark`) |
| `favicon.png` | Favicon do navegador | PNG | `<link rel="icon">` em todos os layouts |
| `auth-bg.mp4` | Vídeo animado da logo (fundo das telas de login/cadastro) | MP4 | `layouts/auth.blade.php` |

## Convenções
- **Tudo em `public/` é servido pela web** — por isso os assets da marca ficam aqui (e não
  em `resources/`, que passa pelo build do Vite).
- **Favicon em PNG, não JPG:** PNG tem transparência e fica menor; o favicon atual tem ~6 KB.
  Existe um `faviicon stail money.jpg` (~185 KB) nos masters, mas JPG não é recomendado p/ ícone.
- **Logo em uma cor só:** o PNG é verde sobre transparente. Onde precisar dela em branco
  (ex.: o card escuro "Meu cartão"), usar `filter: brightness(0) invert(1)` no CSS — assim não
  precisa de um segundo arquivo.

## Masters / originais
Os arquivos-fonte (incluindo o favicon em `.jpg` e o `.mp4` animado com nome original) ficam
no handoff do Claude Design, em `design/project/assets/` e `design/project/uploads/`.
A pasta `design/` é **fonte da verdade visual — não editar**; ao trocar um asset da marca,
copie o arquivo de lá para cá com o nome canônico da tabela acima.
