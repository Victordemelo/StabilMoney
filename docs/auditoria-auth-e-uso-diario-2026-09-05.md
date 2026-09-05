# Auditoria de autenticação, família e uso diário — 05/09/2026

Três frentes executadas (testes temporários apagados + Playwright em Chromium real com os dados
de demonstração). **Nada corrigido** — só registro. Suíte oficial: 1.152 testes verdes.

## 🔴 Corrigir antes de abrir cadastro

- **A-1 · `/register` responde 500 quando o SMTP recusa o destinatário**, e o usuário é gravado
  mesmo assim (fica com e-mail ocupado e `email_verified_at` nulo para sempre). O envio da
  verificação (evento `Registered`) não passa por `Notificador::avisar` nem é protegido.
  Specs e2e `smoke-screens`/`offline-lancamento` falham por isso com SMTP real.
- **A-2 · Redefinir senha pelo link não derruba as outras sessões** nem grava
  `password_changed_at` (`NewPasswordController.php:223-226`). É o caminho de quem já perdeu
  a senha para um invasor: redefine, e o invasor logado continua dentro. A troca pelas
  Configurações faz certo (`PasswordController.php:279-292`).
- **A-3 · Editar conta alheia vaza nome, tipo e existência** via a closure de `type` em
  `UpdateAccountRequest.php:37-43` (`travaDeClasse` sem guarda de posse; validação roda antes
  da policy). As outras duas closures do arquivo já têm a guarda.

## 🟠 Lógica quebrada no dia a dia

- **A-4 · Corrente espelhada por débito/Pix pode virar cartão de crédito** (sem histórico):
  o débito passa a lançar num cartão — vira fatura, consome limite, não sai de caixa.
  Variante: corrente → poupança deixa o débito apontando `checking_account_id` para poupança.
  `Account::travaDeClasse` (`Account.php:140-148`) só olha o histórico da própria conta.
- **A-5 · Arrastar categoria com transações para a outra coluna** deixa lançamentos presos a
  categoria do tipo errado: editar a despesa sem mudar nada dá 422; o donut mostra categoria de
  receita entre despesas. `CategoryController::update:158-172` só barra `is_locked`.
- **A-6 · Titular troca a senha do dependente**: sessão do dependente sobrevive, nenhum alerta
  por e-mail, `password_changed_at` não gravado (`DependentController::update`).
- **A-7 · Troca de e-mail nunca avisa o endereço ANTIGO** (`ProfileController.php:229-237` e
  `298-306`). Quem tem sessão + senha troca o e-mail de recuperação em silêncio.
- **A-8 · Ativar/cancelar 2FA volta para a aba Segurança**, sem o QR
  (`TwoFactorController::voltar` → `settings, 'seguranca'`, linha 185). O QR só aparece ao
  clicar de novo em "2FA".
- **A-9 · Barra de cookies cobre o "Salvar" do modal Lançar** na primeira visita (desktop):
  `#cookieBar` e `.modal-scrim` têm `z-index: 90` (`design-system.css` 653 e 945).
- **A-10 · Foto de perfil trocada continua a antiga na tela** por até 1 h: `/avatar/{id}` com
  `Cache-Control: private, max-age=3600` e URL sem versão.
- **A-11 · Editar transação sem enviar `made_by_user_id` reatribui o autor a quem editou**
  (`TransactionController.php:420`). Na edição o padrão deveria preservar o gravado.
- **A-12 · Link de confirmação de e-mail de A, clicado por B logado, efetiva a troca em A** e
  mostra o flash em B (`ProfileController::confirmEmail`, sem `user()->is($user)`).
- **A-13 · `ImageMetadata::strip` não cobre WebP** (EXIF/GPS sobrevive; só JPEG/PNG).
- **A-14 · `url.intended` do painel contamina o login do app**: visitante que bateu em
  `/painel_admin/inicio` e depois loga no app é jogado no login do painel
  (`AutenticaNoPainel.php`, `redirect()->guest`).

## 🔵 Observações

- Criar conta pelo modal não mostra flash de sucesso (só o caminho sem JS). Modal Lançar também
  fecha sem toast.
- Select de banco pré-selecionado em "Nubank" (`accounts/_form.blade.php:23`).
- Card "Dependentes" da sidebar não recebe estado ativo em `/dependentes`.
- Não há caminho para REMOVER a foto de perfil sem trocá-la.
- Banido com 2FA gasta um passo TOTP antes de ser barrado.
- Admin com celular perdido e códigos esgotados só se recupera por `tinker` (`admin:criar` não
  zera o 2FA).
- Login com e-mail em maiúsculas falha em sqlite; em MySQL `_ci` entra.
- Senha nova igual à antiga é aceita.
- `.env` de dev aponta para SMTP real, não para o Mailpit — os alertas de dev vão para o Gmail
  real e o cadastro de teste bate no 550 (A-1). Voltar `MAIL_HOST=mailpit`/`MAIL_PORT=1025`.

## ✅ Verificado e correto

Cadastro (caixa, espaços, duplicado, HTML no nome), verificação com e sem mailer, reenvio,
link expirado/alheio; throttles de login por e-mail+IP e por IP, remember-me, logout recicla
token, banido sem motivo na tela; 2FA completo (ligar, replay, recuperação queimada, expiração
de 5 min, throttle minuto+hora, remember-me, mantido após trocar/redefinir senha); reset
(token 2×, expirado, alerta); sessões (encerrar outras, senha errada); troca de e-mail
(pendente, único); excluir conta (dependentes, sessões, avatares, recadastro); painel
(404 desligado, guard separado, throttle, banir alcança dependente, excluir dispara
`deleting`); CSP/headers em app, login, painel (0 script inline no admin). Isolamento total
entre famílias por id, payload, filtro, composer e pjax; dependente sem escalada por mass
assignment; 5 tipos de conta com campos condicionais; categorias; perfil (SVG recusado,
2 MB, foto antiga apagada); validações de transação. No navegador: 33 rotas × 2 viewports
sem 5xx, sem erro de JS, sem violação de CSP, sem inglês, sem scroll lateral; modal Lançar,
transferência, pjax nos 8 links, tema, colapso, sino, popover, filtros, paginação, drag,
modais de faturas, offline enfileira e sincroniza uma vez; mobile ok.
