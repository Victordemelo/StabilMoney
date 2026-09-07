# Auditoria: e-mails, páginas legais e acessibilidade — 07/09/2026

Três frentes. **Nada corrigido** — só registro. Os subagentes travaram repetidas vezes nesta
rodada (lado da API, não do ambiente), então a parte de e-mail foi executada diretamente pelo
agente principal via API do Mailpit; a jurídica é leitura de código; a de acessibilidade roda em
Playwright + axe (seção 3, complementada ao terminar).

---

## 1. E-mails (16 mensagens, todos os Mailables, no Mailpit com `html-check` e `link-check`)

### 🟠 Defeitos

- **E-1 · A parte TEXTO de todos os e-mails do layout próprio sai com entidades HTML.**
  `resources/views/emails/layout-texto.blade.php` imprime com `{{ }}` (escapa), mas os Mailables
  já entregam texto escapado ou com `strip_tags` sobre HTML escapado. Resultado no texto puro:
  `Use &quot;Esqueci a senha&quot;` (alertas de segurança), `Olá, Dependente &lt;Teste&gt; &amp;
  Cia` (bem-vindo dependente), `Condomínio &lt;Bloco B&gt;` (lembrete), e no painel **duplo**:
  `&amp;lt;email@x&amp;gt;` (`AlertaDoPainel.php:59` faz `strip_tags` sobre `e()`). Só
  `LembreteDeVencimento::semMarcacao` decodifica (`html_entity_decode`, linha 148) — e mesmo
  assim o layout escapa de novo. Quem lê em cliente só-texto (ou o preview do Gmail/Outlook, que
  às vezes usa a parte texto) vê lixo. Correção: o layout-texto imprimir com `{!! !!}` sobre
  texto já decodificado, ou os Mailables passarem texto cru.
- **E-2 · Verificação de e-mail e redefinição de senha usam o template padrão do framework**,
  não o layout do app: score de compatibilidade **48%** (box-shadow, box-sizing, word-break
  sem suporte em Outlook/Gmail), 11 kB, sem marca, rodapé "Todos os direitos reservados." e
  botão de 38 px. Todos os outros e-mails têm 89-92%. São justamente os dois e-mails que
  **toda** pessoa recebe no primeiro dia. Não há `resources/views/vendor/mail`; bastaria
  publicar e adaptar, ou trocar as Notifications do Breeze por Mailables no layout próprio.
- **E-3 · `ConfirmarNovoEmail` é só texto** (`Content(text:)`, sem HTML) — o único assim. Não é
  erro, mas destoa e o link fica cru no meio do texto.
- **E-4 · Sem `Reply-To` e sem `List-Unsubscribe`.** O `From` é `StabilMoney <victor_teste@…>`
  (caixa de teste). Resposta do usuário cai na caixa pessoal; Gmail/Yahoo exigem
  `List-Unsubscribe` para remetentes em volume (lembretes são recorrentes).
- **E-5 · Preheader com cadeia de `&#8199;&#65279;&#847;`** (truque de espaçamento) forma uma
  string de 90 caracteres sem quebra: no render cru a 375 px o documento estoura para 463 px.
  Em clientes reais o preheader fica oculto, mas Outlook antigo pode mostrá-lo.
- **E-6 · `html-check`:** avisos recorrentes em todos os e-mails próprios — `opacity` (37% sem
  suporte), `<body>` styles (33%), `white-space`, `max-height`. Nenhum quebra o layout; são
  decorativos.

### ✅ O que está bem

Todas as 16 mensagens em **duas partes** (HTML + texto), assunto e corpo em PT-BR com UTF-8
correto, `From` com nome, `Message-ID` e `Date` no fuso `-0300`; `<meta name="color-scheme"
content="light">` + `supported-color-schemes` em todos os próprios (evita a inversão automática
do Apple Mail/Outlook; Gmail ignora e pode inverter — risco aceito, cores têm contraste nos dois
sentidos); nenhuma imagem (nada de `data:` bloqueado); HTML de 6-8 kB (Gmail corta em 102 kB);
tabela de 600 px com `width:100%` externa — **não estoura a 375 px** (medido) exceto E-5; botão
do lembrete 42×201 px (≥ 44 recomendado, quase); links do lembrete levam a `/faturas` e a
Configurações › Conta; alertas trazem data por extenso, IP e aparelho e a instrução "não foi
você"; e-mail do dependente **não contém a senha**; dado do usuário com `<` e `&` sai escapado
no HTML (sem injeção). Links saem com o `APP_URL` do `.env` — em produção dependem do
`APP_URL` https. O `link-check` do Mailpit devolveu 451 para `localhost` (bloqueio de rede
privada do próprio Mailpit), não é quebra.

**Entregabilidade (só produção prova):** SPF, DKIM e DMARC no domínio do `MAIL_FROM_ADDRESS`,
`Return-Path` alinhado, caixa `nao-responda@` no lugar da `victor_teste@`, e `Reply-To` para o
suporte.

---

## 2. Páginas legais × código (leitura de código)

**Contexto:** `config/legal.php` está em `version 2.0` / `27 de julho de 2026` desde o único
commit que a definiu. Depois entraram 2FA, verificação obrigatória, `pending_email`, alertas,
nascimento/sexo, avatar privado, transferência, lembretes, painel com banimento e auditoria,
backups. O commit de 02/09 **editou o texto da Política sem subir a versão**: dois textos
carregam a mesma "Versão 2.0" e `users.terms_version` não distingue quem aceitou qual.

### 🔴 Promete e não faz

- **L-1 · "Logs: até 6 meses"** (PP:357) — `LOG_STACK=single`: arquivo único sem rotação nem
  expurgo. Ou cresce para sempre, ou (com `daily`) apaga em 14 dias. Nenhuma configuração
  corresponde à promessa. Decisão jurídica: art. 15 do Marco Civil obriga 6 meses se aplicável.
- **L-2 · "Apagados na exclusão da conta"** (PP:345, 361) — sobrevivem `admin_audit_logs.
  alvo_descricao` (nome + e-mail, para sempre, por desenho), backups por 14 dias e
  `password_reset_tokens` (ninguém apaga no delete).
- **L-3 · "Você será avisado antes da suspensão"** (TU:221) — `ModeracaoController::banir` não
  notifica o usuário; só o admin recebe alerta. O banido descobre no login, sem motivo e sem
  canal de recurso.
- **L-4 · "Excluir tudo em Configurações"** como direito de eliminação (PP:398) — **banido não
  chega lá**: `BloqueiaUsuarioBanido` derruba antes. Só por e-mail ao DPO.
- **L-5 · "Exportar seus dados"** (PP:400; TU:181) — não existe rota nem comando. Depende de
  trabalho manual.
- **L-6 · "Mudanças comunicadas antes"** (PP:420; TU:226) — nada lê `terms_version`; não há
  re-aceite nem aviso. Alertas e lembretes (novas finalidades) entraram em silêncio.
- **L-7 · "Cadastre só o mínimo — nome e, se preciso, e-mail" para dependente menor** (PP:302)
  — e-mail e senha são **obrigatórios** no `StoreDependentRequest`.

### 🟠 Faz e não diz (falta na Política)

Nascimento e sexo; segredo TOTP e códigos de recuperação cifrados; `pending_email`;
`reminder_emails` nascendo **ligado**; banimento (`banned_reason` é texto livre do admin sobre o
titular); `admin_audit_logs` com nome/e-mail/motivo/IP e sem expurgo; e-mail digitado em login
falho do painel; o painel lê nome, e-mail, telefone, foto, verificação, banimento e **último
acesso via `sessions`** (não há finalidade "moderação" na tabela); alertas carregam IP e
aparelho; `AlertaDoPainel` manda nome/e-mail do alvo para `ADMIN_ALERT_EMAIL`;
`password_reset_tokens`; chaves de throttle com IP+e-mail na tabela `cache`; **Have I Been
Pwned** (5 chars do SHA-1 da senha para serviço fora do Brasil — PP diz que Google Fonts é "a
única" transferência internacional: falso); **SMTP externo** como operador (Gmail sugerido no
`.env.example` = mais uma transferência internacional); backups (PP diz "sem garantia de rotina
de backup" e "cópias residuais"); Pix e transferência como funcionalidades; cookie
`remember_web_*` (~5 anos, marcado por padrão) ausente da lista de cookies; durações ausentes.

### 🟠 Desatualizado

PP §10: "2FA prevista, ainda não disponível" e "sem rotina de backup". TU §4: "recuperação e
verificação de e-mail podem estar desativados" (verificação é obrigatória). TU §14: fala em
"suspensão" sem painel, critérios, recurso, nem que banir o titular derruba os dependentes e que
um admin pode excluir a família inteira. Nada sobre perda dos códigos de recuperação = perda da
conta. Banner de cookies pede "Aceitar" para o que é essencial (consentimento que não é
necessário nem colhido de verdade).

### ✅ Correto

Controlador/DPO em fonte única e consistente em Termos, Política, e-mails e mensagem de banido;
senha argon2id; prova do aceite (data, versão, IP cifrado); sessões visíveis e encerráveis;
conta-família bem avisada; isolamento; sem analytics/CDN (CSP); lembretes descritos com opt-out;
avatar privado; exclusão purga foto, sessões e dependentes; Google Fonts confirmado em 5
layouts; foro do consumidor, 18 anos, menores só como dependentes; sem CPF/GPS/cartão
(`Permissions-Policy` bloqueia geolocalização, câmera, microfone, pagamento).

### Decisões para o Victor / advogado

1. Subir `legal.version` para 3.0 e refazer §2, §4, §5, §6, §10, §11, §13; decidir re-aceite no
   próximo login (hoje não existe) ou aviso por e-mail.
2. HIBP e SMTP externo como transferência internacional (art. 33) — nomear ou trocar.
3. Retenção de logs: `daily` com N dias declarados, ou logrotate de 6 meses.
4. `admin_audit_logs` com nome/e-mail para sempre: prazo ou pseudonimização, e declarar como
   exceção à exclusão (legítimo interesse).
5. Cláusula de moderação: critérios, alcance aos dependentes, exclusão administrativa, recurso,
   retenção do motivo, eliminação para banido, e se o app avisa o banido por e-mail.
6. Portabilidade: criar exportação (CSV/JSON) ou reduzir a promessa.
7. Dependente menor sem e-mail, ou tirar a promessa de "só nome".
8. Declarar `remember_web_*` e decidir se continua marcado por padrão.
9. Backups: prazo real e localização na Política; tirar "sem garantia de backup".
10. "Fase de testes" como base legal: o app já opera como serviço.
11. Menores de 16-18 como titulares "com assistência": o cadastro não pergunta idade.

---

## 3. Acessibilidade

_(em andamento — Playwright + axe, teclado, árvore de acessibilidade, contraste, zoom; será
anexado ao terminar)_
