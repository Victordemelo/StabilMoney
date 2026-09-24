@extends('layouts.legal')

@section('title', 'Termos de Uso — StabilMoney')

@section('content')
    <h1>Termos de Uso</h1>
    {{-- Versão/data e dados do controlador vêm do config/legal.php (fonte única:
         o cadastro grava a mesma versão em users.terms_version como prova do aceite). --}}
    <p class="upd">Versão {{ config('legal.version') }} · Última atualização: {{ config('legal.updated_at') }}</p>

    <p>Estes Termos de Uso são o contrato entre você e o responsável pelo <strong>Stabil Money</strong>.
       Ao criar uma conta e usar o aplicativo, você declara que leu e concorda com o que está escrito aqui.
       Se não concordar com algum ponto, não crie a conta.</p>

    <div class="legal-note">
        <strong>Em poucas palavras:</strong> o Stabil Money é um caderno digital para você organizar seu
        dinheiro. Ele <strong>não é um banco</strong>, <strong>não movimenta dinheiro real</strong>,
        <strong>não se conecta às suas contas bancárias</strong> e <strong>não dá conselhos de
        investimento</strong>. Está em <strong>fase de testes</strong>, é gratuito, e os números que ele
        mostra dependem do que você digita.
    </div>

    <div class="legal-toc">
        <strong>Nesta página</strong>
        <ol>
            <li><a href="#s1">Quem é o responsável pelo aplicativo</a></li>
            <li><a href="#s2">O que é o Stabil Money</a></li>
            <li><a href="#s3">Quem pode usar</a></li>
            <li><a href="#s4">Fase de testes: o que isso significa na prática</a></li>
            <li><a href="#s5">Sua conta e sua senha</a></li>
            <li><a href="#s6">Conta-família e dependentes</a></li>
            <li><a href="#s7">O que o Stabil Money não é</a></li>
            <li><a href="#s8">Nunca pedimos a senha do seu banco</a></li>
            <li><a href="#s9">Uso aceitável</a></li>
            <li><a href="#s10">Gratuidade e planos futuros</a></li>
            <li><a href="#s11">Disponibilidade e mudanças no serviço</a></li>
            <li><a href="#s12">Conteúdo, marca e seus dados</a></li>
            <li><a href="#s13">Limitação de responsabilidade</a></li>
            <li><a href="#s14">Encerramento da conta</a></li>
            <li><a href="#s15">Alterações nestes termos</a></li>
            <li><a href="#s16">Lei aplicável e foro</a></li>
            <li><a href="#s17">Contato</a></li>
        </ol>
    </div>

    <h2 id="s1">1. Quem é o responsável pelo aplicativo</h2>
    <p>O Stabil Money é um projeto pessoal desenvolvido e mantido por <strong>{{ config('legal.controller') }}</strong>,
       pessoa física, atuando como responsável pelo serviço e como <em>controlador</em> dos dados pessoais
       tratados no aplicativo, nos termos da Lei Geral de Proteção de Dados (Lei nº 13.709/2018 — LGPD).</p>
    <p>Contato oficial para qualquer assunto relacionado ao aplicativo, incluindo estes termos e pedidos
       sobre seus dados: <strong>{{ config('legal.contact_email') }}</strong>.</p>

    <h2 id="s2">2. O que é o Stabil Money</h2>
    <p>O Stabil Money é um aplicativo de <strong>controle financeiro pessoal e familiar</strong>. Ele permite
       que você registre manualmente:</p>
    <ul>
        <li>receitas e despesas, com categorias, datas e descrições;</li>
        <li>métodos de pagamento — contas corrente e poupança, cartões de débito e de crédito;</li>
        <li>compras parceladas e despesas recorrentes, organizadas em faturas por cartão;</li>
        <li>metas de poupança, com aportes e resgates;</li>
        <li>investimentos, com indexadores e projeções estimadas;</li>
        <li>dependentes da família, para acompanhar quem fez cada gasto.</li>
    </ul>
    <p>Tudo é <strong>inserido por você</strong>. O aplicativo organiza, soma e apresenta essas informações
       em gráficos e resumos. Ele não busca dados em nenhum banco, corretora ou órgão público.</p>

    <h2 id="s3">3. Quem pode usar</h2>
    <p>Para criar uma conta de titular, você deve ter <strong>18 anos ou mais</strong> e capacidade civil
       para aceitar estes termos. Se você tem entre 16 e 18 anos, precisa da assistência de um responsável
       legal.</p>
    <p>Menores de idade podem ser cadastrados como <strong>dependentes</strong> por um titular adulto que
       seja seu responsável legal — veja a seção 6.</p>

    <h2 id="s4">4. Fase de testes: o que isso significa na prática</h2>
    <p>O Stabil Money está em <strong>fase de testes</strong> e é oferecido <strong>"no estado em que
       se encontra"</strong> (<em>as is</em>), sem garantias de funcionamento contínuo, exatidão ou
       adequação a qualquer finalidade específica. Concretamente, isso quer dizer que:</p>
    <ul>
        <li>o aplicativo pode ficar <strong>indisponível</strong> a qualquer momento, sem aviso prévio;</li>
        <li>funcionalidades podem <strong>mudar, ser reescritas ou removidas</strong>;</li>
        <li>podem ocorrer <strong>erros de cálculo, de exibição ou de sincronização</strong>;</li>
        <li>pode haver <strong>perda de dados</strong>, inclusive em manutenções de banco de dados;</li>
        <li>recursos de recuperação de senha por e-mail e verificação de e-mail podem estar
            <strong>desativados</strong> nesta fase — se perder o acesso, use o contato da seção 17;</li>
        <li>não há garantia de prazo de resposta a dúvidas ou problemas.</li>
    </ul>
    <p><strong>Recomendação séria:</strong> não use o Stabil Money como sua única fonte de registro
       financeiro. Mantenha um backup próprio — planilha, extrato do banco ou anotações — das informações
       que você não pode perder.</p>

    <h2 id="s5">5. Sua conta e sua senha</h2>
    <p>Você é responsável por:</p>
    <ul>
        <li>fornecer informações de cadastro verdadeiras e mantê-las atualizadas;</li>
        <li>escolher uma senha forte e <strong>não reutilizá-la</strong> de outros serviços;</li>
        <li>manter sua senha em sigilo — ela é pessoal e não deve ser compartilhada;</li>
        <li>todas as atividades realizadas na sua conta.</li>
    </ul>
    <p>Sua senha é armazenada com <em>hash</em> criptográfico (argon2id) e <strong>não pode ser lida
       por ninguém</strong>, inclusive por quem mantém o aplicativo. Em <em>Configurações › Segurança</em>
       você vê os dispositivos com sessão ativa e pode <strong>encerrar as outras sessões</strong>. Se
       suspeitar de acesso indevido, troque a senha e encerre as demais sessões imediatamente.</p>

    <h2 id="s6">6. Conta-família e dependentes</h2>
    <p>O titular pode cadastrar <strong>dependentes</strong> (cônjuge, filhos, pais, irmãos ou outros),
       criando para cada um um login próprio. É essencial entender como isso funciona:</p>
    <ul>
        <li><strong>Dependentes veem tudo.</strong> Nesta versão, um dependente tem acesso à
            <strong>mesma visão financeira completa da família</strong> — todas as contas, cartões,
            transações, metas e investimentos, incluindo os do titular. Não existe visão restrita ou
            permissão por módulo.</li>
        <li><strong>Você responde pelos dados que cadastra de outras pessoas.</strong> Ao informar nome,
            e-mail, foto e gastos de um dependente, você está tratando dados pessoais de terceiros. Você
            declara que <strong>tem autorização dessa pessoa</strong> (ou é seu responsável legal) e se
            compromete a informá-la de que os dados estão no aplicativo e de que estes termos e a
            <a href="{{ route('privacidade') }}">Política de Privacidade</a> se aplicam a ela.</li>
        <li><strong>Menores de idade.</strong> Só cadastre uma criança ou adolescente como dependente se
            você for seu responsável legal, e apenas com os dados estritamente necessários.</li>
        <li><strong>Ao excluir um dependente</strong>, o login dele deixa de funcionar. As transações
            lançadas por ele permanecem no histórico da família, sem vínculo com o usuário removido.</li>
    </ul>

    <h2 id="s7">7. O que o Stabil Money não é</h2>
    <p>Este é o ponto mais importante destes termos. O Stabil Money:</p>
    <ul>
        <li><strong>não é instituição financeira</strong>, banco, corretora, fintech de pagamentos nem
            entidade autorizada ou supervisionada pelo Banco Central do Brasil ou pela CVM;</li>
        <li><strong>não movimenta, guarda, transfere nem custodia dinheiro real.</strong> Registrar o
            pagamento de uma fatura no aplicativo <em>não paga</em> a fatura no seu banco — é apenas uma
            anotação;</li>
        <li><strong>não é aconselhamento financeiro, de investimento, contábil ou tributário.</strong>
            As projeções de investimento (CDI, Selic, IPCA+, prefixado) e as prévias de IR e IOF são
            <strong>estimativas matemáticas simplificadas</strong>, calculadas a partir de percentuais que
            você mesmo informa. Não consideram sua situação tributária real, taxas, mudanças de indexador
            nem regras específicas de cada produto. <strong>Não use esses números para declarar imposto
            ou tomar decisão de investimento</strong> — consulte um profissional habilitado;</li>
        <li><strong>não substitui seus extratos e faturas oficiais.</strong> Em caso de divergência, o
            documento do seu banco ou emissor de cartão sempre prevalece.</li>
    </ul>

    <h2 id="s8">8. Nunca pedimos a senha do seu banco</h2>
    <p>O Stabil Money <strong>não tem qualquer integração bancária</strong> — nem Open Finance, nem
       leitura de extratos, nem acesso a contas. Por isso:</p>
    <ul>
        <li>nunca vamos pedir sua <strong>senha de banco, senha de cartão, código de 6 dígitos, token
            ou código enviado por SMS</strong>;</li>
        <li>nunca vamos pedir número completo de cartão de crédito, CVV ou dados de pagamento;</li>
        <li>nunca vamos pedir sua senha do Stabil Money por e-mail, WhatsApp, telefone ou mensagem.</li>
    </ul>
    <p>Qualquer mensagem que peça isso em nome do Stabil Money é <strong>tentativa de fraude</strong>.
       Não responda e avise pelo contato da seção 17.</p>

    <h2 id="s9">9. Uso aceitável</h2>
    <p>Ao usar o aplicativo, você concorda em <strong>não</strong>:</p>
    <ul>
        <li>tentar acessar contas, dados ou famílias de outros usuários;</li>
        <li>explorar falhas de segurança para obter acesso indevido, nem realizar testes de intrusão,
            varreduras automatizadas ou ataques de sobrecarga sem autorização por escrito;</li>
        <li>usar robôs, <em>scrapers</em> ou automações para extrair dados em massa do aplicativo;</li>
        <li>tentar descompilar, copiar ou revender o aplicativo ou partes dele;</li>
        <li>usar o serviço para atividade ilícita, incluindo lavagem de dinheiro, fraude ou registro de
            operações criminosas;</li>
        <li>inserir conteúdo ofensivo, ilegal ou que viole direitos de terceiros em campos de texto
            (descrições, nomes de categorias, nomes de dependentes).</li>
    </ul>
    <p>Encontrou uma falha de segurança? <strong>Nos avise em vez de explorá-la</strong> —
       relatos responsáveis são bem-vindos pelo contato da seção 17.</p>

    <h2 id="s10">10. Gratuidade e planos futuros</h2>
    <p>Nesta fase, o Stabil Money é <strong>totalmente gratuito</strong>. Não há cobrança, assinatura,
       teste pago nem cartão de crédito exigido em nenhum momento.</p>
    <p>No futuro, poderão ser introduzidos planos pagos ou recursos adicionais cobrados. Se isso
       acontecer, você será <strong>avisado com antecedência</strong> e nenhuma cobrança será feita sem
       sua contratação expressa. Recursos que você já usa gratuitamente não passam a ser cobrados
       retroativamente.</p>

    <h2 id="s11">11. Disponibilidade e mudanças no serviço</h2>
    <p>Não há compromisso de disponibilidade (SLA). Manutenções, atualizações e correções podem tornar o
       aplicativo indisponível temporariamente. O serviço pode ser <strong>descontinuado</strong> a
       qualquer momento; nesse caso, será feito um esforço razoável para avisar com antecedência e
       permitir que você registre ou exporte suas informações antes do encerramento.</p>
    <p>O aplicativo funciona parcialmente <strong>offline</strong> (PWA): lançamentos feitos sem internet
       ficam numa fila no seu dispositivo e são enviados quando a conexão volta. Não há garantia de que
       um lançamento em fila seja enviado — se você limpar os dados do navegador, desinstalar o aplicativo
       ou trocar de dispositivo antes da sincronização, <strong>a fila é perdida</strong>.</p>

    <h2 id="s12">12. Conteúdo, marca e seus dados</h2>
    <p>O código, o design, a marca "Stabil Money", o logotipo e os textos do aplicativo pertencem ao
       responsável indicado na seção 1 e são protegidos por direitos autorais. Você recebe apenas uma
       licença pessoal, limitada, gratuita, não exclusiva e revogável para usar o aplicativo conforme
       estes termos.</p>
    <p><strong>Os dados que você cadastra continuam sendo seus.</strong> Nenhuma propriedade sobre suas
       informações financeiras é reivindicada. O tratamento desses dados está descrito na
       <a href="{{ route('privacidade') }}">Política de Privacidade</a>.</p>

    <h2 id="s13">13. Limitação de responsabilidade</h2>
    <p>Na máxima extensão permitida pela lei brasileira, o responsável pelo Stabil Money
       <strong>não se responsabiliza</strong> por:</p>
    <ul>
        <li>decisões financeiras, de investimento ou tributárias tomadas com base em informações do
            aplicativo;</li>
        <li>prejuízos decorrentes de indisponibilidade, lentidão, erro de cálculo, erro de exibição ou
            falha de sincronização;</li>
        <li>perda, corrupção ou apagamento de dados, inclusive de lançamentos em fila offline;</li>
        <li>informações incorretas <strong>inseridas por você</strong> ou por um dependente da sua
            família;</li>
        <li>juros, multas, atrasos, negativação ou qualquer encargo por conta não paga — o aplicativo é
            um lembrete, não um sistema de pagamento;</li>
        <li>acesso indevido decorrente de senha compartilhada, senha fraca ou dispositivo comprometido;</li>
        <li>atos de terceiros, incluindo falhas do provedor de hospedagem, do seu provedor de internet
            ou ataques externos.</li>
    </ul>
    <p>Nada nesta seção afasta responsabilidades que a lei não permite excluir, especialmente as do
       Código de Defesa do Consumidor em caso de dolo ou culpa grave.</p>

    <h2 id="s14">14. Encerramento da conta</h2>
    <p><strong>Por você:</strong> pode excluir sua conta a qualquer momento, sem justificativa, em
       <em>Configurações › Conta</em>. A exclusão remove seus dados conforme descrito na Política de
       Privacidade e <strong>não pode ser desfeita</strong>. Se você é titular de uma família, a exclusão
       da sua conta remove também os dependentes vinculados a ela.</p>
    <p><strong>Pelo responsável:</strong> a conta pode ser suspensa ou encerrada em caso de violação
       destes termos, uso fraudulento, risco à segurança do serviço ou determinação legal. Sempre que
       possível, você será avisado antes. A suspensão da conta de um titular vale também para os
       dependentes da família, e o encerramento pela administração remove a família inteira, como na
       exclusão feita por você — nesse caso, cada pessoa recebe um aviso por e-mail. Quem tiver a conta
       suspensa vê, ao tentar entrar, o contato para contestar a decisão ou pedir a exclusão dos dados:
       <strong>{{ config('legal.contact_email') }}</strong>.</p>

    <h2 id="s15">15. Alterações nestes termos</h2>
    <p>Estes termos podem ser atualizados para refletir mudanças no aplicativo ou na legislação. A data
       e o número da versão no topo da página indicam a vigência. Mudanças relevantes serão comunicadas
       dentro do aplicativo ou por e-mail. Continuar usando o Stabil Money após a atualização significa
       concordar com a nova versão; se não concordar, você pode encerrar sua conta.</p>

    <h2 id="s16">16. Lei aplicável e foro</h2>
    <p>Estes termos são regidos pelas leis da <strong>República Federativa do Brasil</strong> — em
       especial o Código Civil, o Código de Defesa do Consumidor (Lei nº 8.078/1990), o Marco Civil da
       Internet (Lei nº 12.965/2014) e a LGPD (Lei nº 13.709/2018).</p>
    <p>Eventuais controvérsias serão resolvidas preferencialmente por contato direto e de boa-fé. Não
       havendo acordo, fica eleito o <strong>foro do domicílio do consumidor</strong>, conforme garante
       o Código de Defesa do Consumidor.</p>

    <h2 id="s17">17. Contato</h2>
    <p>Dúvidas, problemas, relatos de falhas de segurança ou pedidos relacionados a estes termos:
       <strong>{{ config('legal.contact_email') }}</strong>. Nesta fase de testes o atendimento é feito
       pessoalmente pelo desenvolvedor, sem prazo garantido de resposta.</p>

    <div class="legal-note">
        Documento redigido para a <strong>fase de testes</strong> do Stabil Money e será revisado antes de
        um lançamento público amplo. Veja também a
        <a href="{{ route('privacidade') }}">Política de Privacidade</a>, que é parte integrante destes
        termos.
    </div>

    <a class="legal-back" href="{{ url('/') }}" data-voltar>← Voltar</a>
@endsection
