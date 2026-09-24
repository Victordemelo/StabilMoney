@extends('layouts.legal')

@section('title', 'Política de Privacidade — StabilMoney')

@section('content')
    <h1>Política de Privacidade</h1>
    {{-- Versão/data e dados do controlador vêm do config/legal.php (fonte única:
         o cadastro grava a mesma versão em users.terms_version como prova do aceite). --}}
    <p class="upd">Versão {{ config('legal.version') }} · Última atualização: {{ config('legal.updated_at') }}</p>

    <p>Esta política explica, de forma clara e sem juridiquês, <strong>quais dados o Stabil Money coleta,
       por que, com quem compartilha e como você controla tudo isso</strong>. Ela cumpre a Lei Geral de
       Proteção de Dados (Lei nº 13.709/2018 — LGPD) e vale para o aplicativo web e para a versão
       instalada no celular (PWA).</p>

    <div class="legal-note">
        <strong>O resumo honesto:</strong> o Stabil Money guarda o que você digita (suas transações, contas
        e metas), o mínimo para você entrar na conta, e nada além disso.
        <strong>Não há integração com bancos</strong>, <strong>não coletamos localização</strong>,
        <strong>não há rastreadores de publicidade</strong>, <strong>não vendemos nem compartilhamos seus
        dados</strong> com anunciantes. O aplicativo está em <strong>fase de testes</strong> e seus dados
        podem ser usados para encontrar e corrigir problemas.
    </div>

    <div class="legal-toc">
        <strong>Nesta página</strong>
        <ol>
            <li><a href="#s1">Quem trata seus dados (controlador)</a></li>
            <li><a href="#s2">Quais dados coletamos</a></li>
            <li><a href="#s3">O que nós não coletamos</a></li>
            <li><a href="#s4">Para que usamos e com qual base legal</a></li>
            <li><a href="#s5">Cookies e dados guardados no seu dispositivo</a></li>
            <li><a href="#s6">Com quem compartilhamos</a></li>
            <li><a href="#s7">Conta-família: dados de outras pessoas</a></li>
            <li><a href="#s8">Crianças e adolescentes</a></li>
            <li><a href="#s9">Modo offline: dados no seu aparelho</a></li>
            <li><a href="#s10">Segurança</a></li>
            <li><a href="#s11">Por quanto tempo guardamos</a></li>
            <li><a href="#s12">Seus direitos e como exercê-los</a></li>
            <li><a href="#s13">Transferência internacional de dados</a></li>
            <li><a href="#s14">Incidentes de segurança</a></li>
            <li><a href="#s15">Alterações nesta política</a></li>
            <li><a href="#s16">Contato e Encarregado (DPO)</a></li>
        </ol>
    </div>

    <h2 id="s1">1. Quem trata seus dados (controlador)</h2>
    <p>O <strong>controlador</strong> dos dados pessoais tratados no Stabil Money — quem decide o que é
       coletado e para quê — é <strong>{{ config('legal.controller') }}</strong>, pessoa física, desenvolvedor e
       mantenedor do aplicativo.</p>
    <p>Contato para qualquer assunto de privacidade: <strong>{{ config('legal.contact_email') }}</strong>.</p>

    <h2 id="s2">2. Quais dados coletamos</h2>
    <p>Praticamente tudo que o aplicativo sabe sobre você é <strong>o que você mesmo digita</strong>.
       Nada é buscado em bancos, bureaus de crédito ou redes sociais.</p>

    <h3>2.1. Dados de cadastro e perfil</h3>
    <ul>
        <li><strong>Nome</strong> e <strong>e-mail</strong> — obrigatórios, identificam sua conta e servem
            para o login.</li>
        <li><strong>Senha</strong> — armazenada apenas como <em>hash</em> criptográfico (argon2id).
            <strong>A senha em si nunca é guardada</strong> e não pode ser lida por ninguém, inclusive pelo
            desenvolvedor.</li>
        <li><strong>Telefone</strong> e <strong>foto de perfil</strong> — opcionais, só se você preencher.</li>
        <li><strong>Data da última troca de senha</strong> — usada para mostrar a idade da sua senha na
            tela de Segurança.</li>
        <li><strong>Data de nascimento</strong> e <strong>sexo</strong> — opcionais; você pode deixar em
            branco ou escolher "Prefiro não informar".</li>
        <li><strong>Verificação em duas etapas</strong>, se você ligar: o segredo do aplicativo
            autenticador e os códigos de recuperação, guardados <strong>cifrados</strong>, e a data em que a
            proteção foi ativada.</li>
        <li><strong>Novo e-mail aguardando confirmação</strong> — quando você pede para trocar de e-mail,
            o endereço novo fica guardado até ser confirmado pelo link enviado a ele.</li>
    </ul>

    <h3>2.2. Dados financeiros que você registra</h3>
    <ul>
        <li><strong>Métodos de pagamento:</strong> apelido da conta ou cartão, tipo (corrente, poupança,
            débito, crédito), banco escolhido de uma lista, saldo inicial informado, limite do cartão e
            dias de fechamento e vencimento.</li>
        <li><strong>Transações:</strong> valor, tipo (receita ou despesa), data, descrição opcional,
            categoria, método de pagamento, quem fez a compra, parcelas e se já foi paga.</li>
        <li><strong>Categorias:</strong> nome, ícone e cor.</li>
        <li><strong>Metas e investimentos:</strong> nome, valor-objetivo, aportes, resgates, indexador
            (CDI, Selic, IPCA+, prefixado) e percentual informado.</li>
        <li><strong>Dependentes:</strong> nome, e-mail, foto, parentesco e senha (também em <em>hash</em>).</li>
    </ul>
    <p>Esses dados podem revelar hábitos de consumo e situação financeira. Eles são tratados com o mesmo
       cuidado dos dados de cadastro e <strong>ficam visíveis apenas para a sua família</strong> dentro do
       aplicativo (veja a seção 7).</p>

    <h3>2.3. Dados técnicos de sessão e segurança</h3>
    <ul>
        <li><strong>Endereço IP</strong>, <strong>navegador e sistema operacional</strong> (do
            <em>user-agent</em>) e <strong>data do último acesso</strong> — guardados junto da sua sessão
            para que você veja, em <em>Configurações › Segurança</em>, quais dispositivos estão conectados
            e possa encerrá-los.</li>
        <li><strong>Registros técnicos do servidor</strong> (logs) — podem conter IP, data, página acessada
            e mensagens de erro. Servem para diagnosticar falhas e detectar abuso.</li>
        <li><strong>Registro do aceite</strong> — a <strong>data e hora</strong>, a
            <strong>versão dos documentos</strong> e o <strong>endereço IP</strong> de quando você marcou
            a caixa de aceite no cadastro. É a comprovação do seu consentimento exigida pelo art. 8º, §1º
            da LGPD; sem ela, não haveria registro de que você concordou nem de qual texto estava em vigor
            naquele momento.</li>
        <li><strong>Pedidos de redefinição de senha</strong> — um código de uso único, guardado apenas como
            <em>hash</em>, que deixa de valer em 60 minutos.</li>
        <li><strong>Contagem de tentativas</strong> — para barrar quem tenta adivinhar senhas ou códigos, o
            servidor conta por até uma hora as tentativas de login, de código e de cadastro, associadas ao
            endereço IP e, no login, ao e-mail digitado.</li>
        <li><strong>Alertas de segurança</strong> — os e-mails que avisam de mudanças na sua conta informam
            quando, de qual endereço IP e de qual aparelho a ação foi feita, para você reconhecer se foi
            você.</li>
    </ul>
    <p>Não usamos ferramentas de analytics, mapas de calor, gravação de sessão nem pixels de rastreamento
       de terceiros.</p>

    <h3>2.4. Moderação</h3>
    <p>Se uma conta for <strong>suspensa</strong> pela administração do aplicativo, guardamos a data, o
       motivo e qual administrador a suspendeu. Suspensões, reativações e exclusões feitas pela
       administração ficam num <strong>registro de auditoria</strong> com o nome e o e-mail da conta no
       momento da ação, o motivo informado e o endereço IP do administrador (veja a seção 11). O painel
       administrativo mostra os dados de cadastro e a <em>estrutura</em> da conta — quantas contas,
       lançamentos e dependentes existem e o último acesso —, mas <strong>nunca valores
       financeiros</strong>.</p>

    <h2 id="s3">3. O que nós não coletamos</h2>
    <p>Deixar isso explícito é tão importante quanto listar o que coletamos. O Stabil Money
       <strong>não</strong> coleta e <strong>não pede</strong>:</p>
    <ul>
        <li><strong>credenciais bancárias</strong> — não há Open Finance, não lemos extratos, não acessamos
            suas contas. Sua senha do banco, token, código por SMS ou senha de cartão
            <strong>nunca</strong> serão solicitados;</li>
        <li><strong>número de cartão de crédito, CVV ou dados de pagamento</strong> — o aplicativo é
            gratuito e não processa pagamentos;</li>
        <li><strong>CPF, RG ou documentos de identidade</strong>;</li>
        <li><strong>localização geográfica</strong> (GPS), contatos, agenda, microfone, câmera em segundo
            plano ou lista de aplicativos instalados;</li>
        <li><strong>dados sensíveis</strong> na acepção do art. 5º, II da LGPD — origem racial, convicção
            religiosa, opinião política, filiação sindical, dado de saúde ou vida sexual, biometria. Pedimos
            que você <strong>não escreva esse tipo de informação</strong> nas descrições das transações;</li>
        <li><strong>dados para publicidade</strong> — não há anúncios no aplicativo.</li>
    </ul>

    <h2 id="s4">4. Para que usamos e com qual base legal</h2>
    <p>A LGPD exige que todo tratamento tenha uma finalidade e uma <em>base legal</em>. Estas são as
       nossas:</p>

    <div class="legal-table">
        <table>
            <thead>
                <tr><th>Dados</th><th>Para que usamos</th><th>Base legal (LGPD)</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td>Nome, e-mail (e o novo e-mail, enquanto aguarda confirmação), senha</td>
                    <td>Criar sua conta, autenticar o login, identificar você no aplicativo</td>
                    <td>Execução de contrato — art. 7º, V</td>
                </tr>
                <tr>
                    <td>Transações, contas, cartões, categorias, metas, investimentos</td>
                    <td>Entregar a função central do aplicativo: registrar, somar e exibir seu controle
                        financeiro</td>
                    <td>Execução de contrato — art. 7º, V</td>
                </tr>
                <tr>
                    <td>Telefone, foto de perfil, data de nascimento, sexo</td>
                    <td>Personalizar e completar seu perfil (dados opcionais)</td>
                    <td>Consentimento — art. 7º, I</td>
                </tr>
                <tr>
                    <td>Dados dos dependentes</td>
                    <td>Criar o login deles e mostrar quem fez cada gasto na família</td>
                    <td>Execução de contrato — art. 7º, V, e consentimento do titular dos dados ou de seu
                        responsável legal</td>
                </tr>
                <tr>
                    <td>IP, navegador, sessões ativas, logs, contagem de tentativas, pedidos de redefinição
                        de senha</td>
                    <td>Segurança da conta, prevenção a acesso indevido, diagnóstico de falhas e
                        cumprimento do Marco Civil da Internet</td>
                    <td>Legítimo interesse — art. 7º, IX, e cumprimento de obrigação legal — art. 7º, II</td>
                </tr>
                <tr>
                    <td>Segredo da verificação em duas etapas e códigos de recuperação</td>
                    <td>Proteger o seu login com um segundo fator, quando você liga essa opção</td>
                    <td>Execução de contrato — art. 7º, V</td>
                </tr>
                <tr>
                    <td>Registro de moderação (suspensão, motivo e auditoria das ações administrativas)</td>
                    <td>Fazer cumprir os Termos de Uso, proteger o serviço e comprovar as ações tomadas</td>
                    <td>Legítimo interesse — art. 7º, IX, e exercício regular de direitos — art. 7º, VI</td>
                </tr>
                <tr>
                    <td>E-mail (avisos automáticos)</td>
                    <td>Enviar <strong>alertas de segurança</strong> (senha alterada ou redefinida, pedido e
                        confirmação de troca de e-mail, verificação em duas etapas ligada ou desligada,
                        sessões encerradas, conta excluída) e, ao titular,
                        <strong>lembretes de vencimento</strong> de faturas de cartão e contas fixas —
                        3 dias antes, na véspera, no dia e a cada 7 dias enquanto houver atraso. Os
                        lembretes podem ser desligados em <em>Configurações › Conta</em>; os alertas de
                        segurança não, porque existem justamente para avisar quando alguém mexe na sua
                        conta. Guardamos só a data do último lembrete enviado, para não repetir no mesmo dia.</td>
                    <td>Execução de contrato — art. 7º, V (lembretes) e legítimo interesse — art. 7º, IX
                        (alertas de segurança)</td>
                </tr>
                <tr>
                    <td>Uso agregado do aplicativo e relatos de erro</td>
                    <td><strong>Testar, corrigir e melhorar</strong> o aplicativo durante a fase de testes</td>
                    <td>Consentimento — art. 7º, I (o aceite no cadastro)</td>
                </tr>
                <tr>
                    <td>Data, hora, versão e IP do aceite dos documentos</td>
                    <td>Comprovar que você consentiu e a qual versão do texto</td>
                    <td>Cumprimento de obrigação legal e ônus da prova do consentimento —
                        art. 7º, II e art. 8º, §1º</td>
                </tr>
            </tbody>
        </table>
    </div>

    <p>Seus dados <strong>não</strong> são usados para decisões automatizadas que afetem seus interesses,
       nem para criar perfis de crédito, <em>score</em> ou classificação de risco.</p>
    <p>O consentimento que você deu para o uso dos dados em testes e melhorias
       <strong>pode ser revogado a qualquer momento</strong> pelo contato da seção 16, sem prejuízo ao
       funcionamento normal da sua conta.</p>

    <h2 id="s5">5. Cookies e dados guardados no seu dispositivo</h2>
    <p>O Stabil Money usa apenas <strong>cookies essenciais</strong> — sem eles não é possível manter você
       conectado. Não há cookies de publicidade ou de rastreamento entre sites.</p>

    <div class="legal-table">
        <table>
            <thead>
                <tr><th>Nome</th><th>Tipo</th><th>Para que serve</th></tr>
            </thead>
            <tbody>
                <tr>
                    {{-- O nome sai da config: é o que o navegador recebe de fato. --}}
                    <td><code>{{ config('session.cookie') }}</code></td>
                    <td>Cookie essencial</td>
                    <td>Mantém você conectado enquanto navega. Expira ao sair ou após inatividade.</td>
                </tr>
                <tr>
                    <td><code>XSRF-TOKEN</code></td>
                    <td>Cookie essencial</td>
                    <td>Protege contra ataques de falsificação de requisição (CSRF).</td>
                </tr>
                <tr>
                    <td><code>remember_web_…</code></td>
                    <td>Cookie essencial</td>
                    <td>Criado quando o login é feito com "Lembrar de mim" — a caixa já vem marcada;
                        desmarque-a em aparelho compartilhado. Mantém você conectado neste aparelho por até
                        400 dias. Some quando você sai da conta; trocar a senha ou encerrar as outras
                        sessões o invalida nos outros aparelhos.</td>
                </tr>
                <tr>
                    <td><code>sm-theme</code></td>
                    <td>Armazenamento local</td>
                    <td>Lembra se você escolheu tema claro ou escuro.</td>
                </tr>
                <tr>
                    <td><code>sm-collapsed</code></td>
                    <td>Armazenamento local</td>
                    <td>Lembra se o menu lateral fica recolhido.</td>
                </tr>
                <tr>
                    <td><code>sm-cookie-consent</code></td>
                    <td>Armazenamento local</td>
                    <td>Registra que você já viu o aviso de cookies, para não repeti-lo.</td>
                </tr>
                <tr>
                    <td><code>sm-form-user</code></td>
                    <td>Armazenamento local</td>
                    <td>Identifica qual usuário preencheu o formulário offline, para não misturar
                        lançamentos entre contas no mesmo aparelho.</td>
                </tr>
                <tr>
                    <td>Fila offline (IndexedDB) e cache de páginas</td>
                    <td>Armazenamento local</td>
                    <td>Guarda no seu aparelho os lançamentos feitos sem internet e as telas para uso
                        offline.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <p>Os itens de armazenamento local ficam <strong>somente no seu navegador</strong> e não são enviados
       para o servidor. Você pode apagá-los limpando os dados do site nas configurações do navegador —
       lançamentos ainda não sincronizados serão perdidos.</p>

    <h2 id="s6">6. Com quem compartilhamos</h2>
    <p><strong>Seus dados não são vendidos, alugados nem cedidos</strong> para anunciantes, corretores de
       dados, bureaus de crédito ou qualquer terceiro com finalidade comercial.</p>
    <p>O compartilhamento se limita a fornecedores que atuam como <em>operadores</em>, tratando dados por
       nossa ordem e apenas na medida necessária:</p>

    <div class="legal-table">
        <table>
            <thead>
                <tr><th>Quem</th><th>Papel</th><th>O que acessa</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td>Oracle Cloud Infrastructure (Oracle)</td>
                    <td>Operador</td>
                    <td>Servidor em nuvem onde rodam o aplicativo e o banco de dados. Não usa os dados para
                        finalidade própria.</td>
                </tr>
                <tr>
                    <td>Cloudflare</td>
                    <td>Operador</td>
                    <td>Todo acesso ao aplicativo passa pela rede da Cloudflare, que protege o site contra
                        ataques e encaminha a conexão ao servidor. Ela recebe seu <strong>endereço IP</strong>,
                        informações do navegador e o conteúdo que trafega entre você e o servidor.</td>
                </tr>
                <tr>
                    <td>Provedor de envio de e-mail (SMTP)</td>
                    <td>Operador</td>
                    <td>Entrega os e-mails do aplicativo — confirmação de cadastro e de troca de e-mail,
                        redefinição de senha, alertas de segurança e lembretes. Recebe o seu endereço de
                        e-mail e o conteúdo dessas mensagens.</td>
                </tr>
                <tr>
                    <td>Have I Been Pwned (Pwned Passwords)</td>
                    <td>Terceiro (consulta)</td>
                    <td>Quando você cria ou troca uma senha, o servidor confere se ela aparece em vazamentos
                        conhecidos. Só os <strong>5 primeiros caracteres do <em>hash</em> SHA-1</strong> da
                        senha são enviados — nunca a senha, o seu e-mail ou o seu IP — e a comparação final
                        é feita no nosso servidor.</td>
                </tr>
                <tr>
                    <td>Google Fonts</td>
                    <td>Terceiro (CDN)</td>
                    <td>As fontes do aplicativo são carregadas dos servidores do Google, que recebem seu
                        <strong>endereço IP</strong> e informações do navegador nessa requisição. Nenhum
                        dado financeiro ou de conta é enviado.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <p>Também podemos divulgar dados quando houver <strong>obrigação legal</strong> ou
       <strong>ordem judicial</strong>, sempre no limite do que for exigido, e — se a lei permitir —
       avisando você.</p>
    <p>Novas integrações previstas (por exemplo, um assistente por WhatsApp que usaria a API do WhatsApp
       e um serviço de inteligência artificial) <strong>ainda não existem</strong>. Se forem implementadas,
       esta política será atualizada <strong>antes</strong> de entrarem em operação.</p>

    <h2 id="s7">7. Conta-família: dados de outras pessoas</h2>
    <p>O Stabil Money organiza os dados por <strong>família</strong>, não por pessoa. Isso tem uma
       consequência de privacidade que você precisa entender antes de convidar alguém:</p>
    <ul>
        <li><strong>Todos os membros da família veem tudo.</strong> Um dependente com login próprio acessa
            todas as contas, cartões, transações, metas e investimentos da família —
            <strong>inclusive os do titular</strong>. Não existe visão parcial ou privada nesta versão.</li>
        <li><strong>Famílias diferentes são isoladas.</strong> Nenhum usuário de outra família tem qualquer
            acesso aos seus dados. Esse isolamento é aplicado no servidor e coberto por testes
            automatizados.</li>
        <li><strong>Ao cadastrar um dependente, você trata dados de terceiro.</strong> Informe a pessoa de
            que os dados dela estão no aplicativo, mostre-lhe esta política e obtenha sua concordância —
            ou seja seu responsável legal. Ela mantém todos os direitos da seção 12 sobre os próprios
            dados.</li>
    </ul>

    <h2 id="s8">8. Crianças e adolescentes</h2>
    <p>O Stabil Money não é destinado a menores de 18 anos como titulares de conta. Um menor pode ser
       cadastrado como <strong>dependente</strong> exclusivamente por seu <strong>responsável legal</strong>,
       que assim exerce o consentimento previsto no art. 14 da LGPD, no melhor interesse da criança ou
       adolescente.</p>
    <p>Nesse caso, cadastre apenas o necessário para o login dele — nome, e-mail e senha — e deixe em
       branco o que é opcional, como foto e parentesco. Se você tomar
       conhecimento de que dados de um menor foram cadastrados sem autorização do responsável, avise pelo
       contato da seção 16 e eles serão removidos.</p>

    <h2 id="s9">9. Modo offline: dados no seu aparelho</h2>
    <p>O aplicativo pode ser instalado no celular e funciona sem internet. Para isso, ele guarda no
       <strong>seu aparelho</strong> as telas visitadas e os lançamentos que ainda não foram enviados.
       Consequências práticas:</p>
    <ul>
        <li>informações financeiras podem ficar <strong>visíveis para quem tiver acesso ao aparelho
            desbloqueado</strong> — proteja o dispositivo com senha ou biometria;</li>
        <li>em aparelho compartilhado, <strong>saia da conta</strong> ao terminar;</li>
        <li>limpar os dados do site, desinstalar o aplicativo ou trocar de aparelho <strong>apaga a fila
            offline</strong>, e lançamentos não sincronizados são perdidos.</li>
    </ul>

    <h2 id="s10">10. Segurança</h2>
    <p>Medidas técnicas efetivamente aplicadas no aplicativo:</p>
    <ul>
        <li><strong>senhas com <em>hash</em> argon2id</strong> — algoritmo moderno, resistente a ataques
            por força bruta em GPU; a senha original não é recuperável nem pelo desenvolvedor;</li>
        <li><strong>isolamento por família no servidor</strong> — toda consulta ao banco é filtrada pelo
            titular da família, com regras de autorização por recurso e cobertura de testes automatizados;</li>
        <li><strong>proteção contra CSRF</strong> em todos os formulários;</li>
        <li><strong>gestão de sessões</strong> — você vê os dispositivos conectados e pode encerrar as
            outras sessões confirmando sua senha;</li>
        <li><strong>verificação em duas etapas</strong> opcional, por aplicativo autenticador, com códigos
            de recuperação;</li>
        <li><strong>limite de tentativas</strong> no login, nos códigos e nas ações que pedem senha;</li>
        <li><strong>alertas por e-mail</strong> quando a senha, o e-mail ou a verificação em duas etapas
            mudam, ou quando sessões são encerradas;</li>
        <li><strong>cópias de segurança do banco de dados</strong>, feitas diariamente;</li>
        <li><strong>HTTPS</strong> em todo o tráfego no ambiente publicado;</li>
        <li>acesso administrativo ao servidor <strong>restrito ao desenvolvedor</strong>; o painel
            administrativo exige verificação em duas etapas e não exibe valores financeiros.</li>
    </ul>
    <p><strong>Limites honestos:</strong> este é um projeto pessoal em fase de testes. Não há certificação
       de segurança nem auditoria externa, e a verificação em duas etapas é opcional — ligue-a em
       <em>Configurações › 2FA</em>. Cópias de segurança reduzem, mas não eliminam, o risco de perda de
       dados. <strong>Nenhum sistema é totalmente seguro.</strong> Cadastre apenas
       informações com as quais você se sinta confortável nesse cenário, e evite escrever em descrições
       dados que você não gostaria de ver expostos (senhas, números de documento, dados de saúde).</p>

    <h2 id="s11">11. Por quanto tempo guardamos</h2>
    <div class="legal-table">
        <table>
            <thead>
                <tr><th>Dados</th><th>Prazo de retenção</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td>Conta, perfil e dados financeiros</td>
                    <td>Enquanto sua conta existir. São <strong>apagados na exclusão da conta</strong>.</td>
                </tr>
                <tr>
                    <td>Dependentes vinculados a você</td>
                    <td>Removidos junto com a conta do titular.</td>
                </tr>
                <tr>
                    <td>Sessões ativas</td>
                    <td>Expiram por inatividade ou quando você encerra as sessões.</td>
                </tr>
                <tr>
                    <td>Registros de acesso (logs)</td>
                    <td>Até <strong>6 meses</strong>, prazo do art. 15 do Marco Civil da Internet.</td>
                </tr>
                <tr>
                    <td>Registro do aceite (data, versão e IP)</td>
                    <td>Enquanto sua conta existir; apagado com ela. Fica junto do seu cadastro, não em
                        base separada.</td>
                </tr>
                <tr>
                    <td>Pedidos de redefinição de senha</td>
                    <td>Deixam de valer em 60 minutos; são apagados quando usados, quando você pede outro
                        ou na exclusão da conta.</td>
                </tr>
                <tr>
                    <td>Contagem de tentativas</td>
                    <td>Até uma hora.</td>
                </tr>
                <tr>
                    <td>Cópias de segurança (backups) do banco de dados</td>
                    <td>Uma por dia, guardadas por até <strong>14 dias</strong> e depois apagadas.</td>
                </tr>
                <tr>
                    <td>Registro de auditoria das ações administrativas (suspensão, reativação,
                        exclusão)</td>
                    <td>Mantido <strong>mesmo após a exclusão da conta</strong>, com o nome e o e-mail da
                        época, para comprovar a ação tomada — exercício regular de direitos
                        (art. 7º, VI).</td>
                </tr>
                <tr>
                    <td>Comunicações por e-mail com o suporte</td>
                    <td>Mantidas enquanto necessárias ao atendimento e à defesa de direitos.</td>
                </tr>
            </tbody>
        </table>
    </div>
    <p>Ao excluir a conta em <em>Configurações › Conta</em>, o registro do usuário e os dados financeiros
       vinculados são removidos do banco de dados. Cópias feitas antes da exclusão continuam nos backups
       até serem apagadas pela rotação (até 14 dias), e os registros de acesso seguem o prazo dos logs
       (até 6 meses). A exceção é o registro de auditoria das ações administrativas, descrito acima.</p>

    <h2 id="s12">12. Seus direitos e como exercê-los</h2>
    <p>A LGPD (art. 18) garante a você, gratuitamente, o direito de:</p>
    <ul>
        <li><strong>confirmar</strong> se tratamos dados seus;</li>
        <li><strong>acessar</strong> os dados que temos;</li>
        <li><strong>corrigir</strong> dados incompletos, inexatos ou desatualizados;</li>
        <li><strong>solicitar anonimização, bloqueio ou eliminação</strong> de dados desnecessários,
            excessivos ou tratados em desconformidade com a lei;</li>
        <li><strong>solicitar a portabilidade</strong> dos dados a outro fornecedor;</li>
        <li><strong>eliminar</strong> dados tratados com base no seu consentimento;</li>
        <li><strong>saber com quem</strong> compartilhamos seus dados;</li>
        <li><strong>ser informado</strong> sobre a possibilidade de não consentir e as consequências
            disso;</li>
        <li><strong>revogar o consentimento</strong> a qualquer momento;</li>
        <li><strong>opor-se</strong> a tratamento feito com base em legítimo interesse.</li>
    </ul>

    <h3>Como exercer</h3>
    <p>Boa parte você faz sozinho, na hora, dentro do aplicativo:</p>
    <ul>
        <li><strong>acessar e corrigir</strong> — <em>Meu perfil</em> e as telas de transações, contas e
            categorias;</li>
        <li><strong>excluir tudo</strong> — <em>Configurações › Conta › Excluir conta</em>.</li>
    </ul>
    <p>Para os demais pedidos — inclusive <strong>exportar seus dados</strong>, <strong>excluir uma conta
       que você não consegue acessar</strong> (por exemplo, suspensa), revogar consentimento ou obter
       confirmação por escrito — escreva para
       <strong>{{ config('legal.contact_email') }}</strong> usando o e-mail cadastrado na sua conta. A resposta
       será dada em até <strong>15 dias</strong>, conforme o art. 19, II da LGPD. Pode ser necessário
       confirmar sua identidade antes de atender ao pedido, para proteger sua conta.</p>

    <h2 id="s13">13. Transferência internacional de dados</h2>
    <p>O servidor com o aplicativo e o banco de dados é contratado na nuvem da <strong>Oracle</strong>
       (Oracle Cloud Infrastructure). Além dele, alguns serviços usados pelo Stabil Money são de empresas
       estrangeiras e tratam dados fora do Brasil:</p>
    <ul>
        <li><strong>Cloudflare</strong> — todo acesso ao aplicativo passa pela rede global dela: endereço
            IP, dados do navegador e o conteúdo em trânsito;</li>
        <li><strong>Google Fonts</strong> — endereço IP e dados do navegador, ao carregar as fontes;</li>
        <li>o <strong>provedor de envio de e-mail</strong>, se for estrangeiro — seu endereço de e-mail e o
            conteúdo das mensagens do aplicativo;</li>
        <li><strong>Have I Been Pwned</strong> — só o trecho do <em>hash</em> da senha descrito na seção 6,
            que não identifica você.</li>
    </ul>
    <p>Essas transferências são necessárias para prestar o serviço que você contratou ao se cadastrar
       (art. 33, IX, combinado com o art. 7º, V, da LGPD). Os dados financeiros só passam pela Cloudflare
       no trajeto entre você e o servidor; nenhum desses serviços os recebe para finalidade própria.</p>

    <h2 id="s14">14. Incidentes de segurança</h2>
    <p>Se ocorrer um incidente de segurança com risco relevante aos seus dados, você será
       <strong>comunicado</strong> por e-mail e dentro do aplicativo, em prazo razoável, com a descrição do
       que aconteceu, quais dados foram afetados e o que fazer. A Autoridade Nacional de Proteção de Dados
       (ANPD) será notificada quando a lei exigir.</p>

    <h2 id="s15">15. Alterações nesta política</h2>
    <p>Esta política pode ser atualizada quando o aplicativo mudar ou a legislação exigir. O número da
       versão e a data no topo indicam a vigência. Mudanças relevantes — especialmente novas finalidades
       ou novos compartilhamentos — serão comunicadas dentro do aplicativo ou por e-mail
       <strong>antes</strong> de entrarem em vigor.</p>

    <h2 id="s16">16. Contato e Encarregado (DPO)</h2>
    <p>Como o Stabil Money é um projeto pessoal, o próprio controlador atua como
       <strong>Encarregado pelo Tratamento de Dados Pessoais</strong> (DPO), previsto no art. 41 da LGPD:</p>
    <ul>
        <li><strong>{{ config('legal.controller') }}</strong></li>
        <li><strong>{{ config('legal.contact_email') }}</strong></li>
    </ul>
    <p>Se você não ficar satisfeito com a resposta, pode registrar reclamação na
       <strong>Autoridade Nacional de Proteção de Dados (ANPD)</strong>, em
       <a href="https://www.gov.br/anpd" target="_blank" rel="noopener">gov.br/anpd</a>.</p>

    <div class="legal-note">
        Documento redigido para a <strong>fase de testes</strong> do Stabil Money e será revisado antes de
        um lançamento público amplo. Ao concordar no cadastro, você aceita esta política e os
        <a href="{{ route('termos') }}">Termos de Uso</a>, e consente que suas informações sejam usadas
        para operar, testar e melhorar o aplicativo.
    </div>

    <a class="legal-back" href="{{ url('/') }}" data-voltar>← Voltar</a>
@endsection
