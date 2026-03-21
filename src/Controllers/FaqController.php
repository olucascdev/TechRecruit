<?php

declare(strict_types=1);

namespace TechRecruit\Controllers;

final class FaqController extends Controller
{
    public function __construct()
    {
        $this->requireAuth();
    }

    public function index(): void
    {
        $quickLinks = [
            [
                'href' => '/import',
                'label' => 'Importações',
                'description' => 'Entrada de planilhas e consolidação inicial.',
            ],
            [
                'href' => '/candidates',
                'label' => 'Candidatos',
                'description' => 'Base principal, filtros, status e detalhe individual.',
            ],
            [
                'href' => '/campaigns',
                'label' => 'Campanhas',
                'description' => 'Disparo manual, automação W13 e fila de mensagens.',
            ],
            [
                'href' => '/operations',
                'label' => 'Operações',
                'description' => 'Validação documental, pendências e decisão final.',
            ],
        ];

        $processFlow = [
            [
                'step' => '01',
                'title' => 'Acesso e setup',
                'description' => 'O primeiro administrador nasce em /setup. Depois disso o login fica restrito a username ou e-mail e a criação de acessos segue por /management/users.',
                'route' => '/setup',
            ],
            [
                'step' => '02',
                'title' => 'Importação da base',
                'description' => 'Planilhas entram por /import, viram candidatos estruturados e alimentam os filtros, campanhas e operação.',
                'route' => '/import',
            ],
            [
                'step' => '03',
                'title' => 'Campanhas e triagem',
                'description' => 'A gestão cria campanhas, escolhe disparo manual ou bot W13, processa a fila e acompanha retornos inbound.',
                'route' => '/campaigns',
            ],
            [
                'step' => '04',
                'title' => 'Portal documental',
                'description' => 'Quando o candidato avança, o sistema gera um token único, envia o link e centraliza perfil, anexos e checklist.',
                'route' => '/candidates',
            ],
            [
                'step' => '05',
                'title' => 'Validação operacional',
                'description' => 'A equipe opera a fila em /operations, registra notas, cria pendências e decide aprovação, correção ou reprovação.',
                'route' => '/operations',
            ],
        ];

        $faqGroups = [
            [
                'id' => 'access',
                'eyebrow' => 'Acesso',
                'title' => 'Login, setup e roles',
                'summary' => 'Quem pode entrar, como nasce o primeiro admin e como a gestão de usuários funciona.',
                'items' => [
                    [
                        'question' => 'Como o primeiro acesso do sistema é criado?',
                        'answer' => 'Se não existir nenhum usuário interno, o bootstrap é feito em /setup. Essa tela cria obrigatoriamente o primeiro administrador. Depois que ao menos um usuário existe, o setup deixa de ser usado e redireciona para /login.',
                    ],
                    [
                        'question' => 'Como funciona o login no backoffice?',
                        'answer' => 'O login aceita username ou e-mail. O usuário precisa estar com status ativo. Se a autenticação passar, a sessão guarda o auth_user_id e o sistema libera o acesso ao backoffice.',
                    ],
                    [
                        'question' => 'Quais roles existem hoje?',
                        'answer' => 'Existem duas roles: admin e manager. Admin gerencia usuários internos, roles e status em /management/users. Manager usa normalmente o backoffice operacional, mas não administra acessos.',
                    ],
                    [
                        'question' => 'Como novos acessos são criados depois do setup?',
                        'answer' => 'Somente um admin cria novos usuários em /management/users. Cada cadastro nasce com full name, username, e-mail, senha inicial, role e status ativo.',
                    ],
                ],
            ],
            [
                'id' => 'import',
                'eyebrow' => 'Entrada',
                'title' => 'Importação e base de candidatos',
                'summary' => 'Como a base entra no sistema, quais dados são consolidados e para onde isso vai depois.',
                'items' => [
                    [
                        'question' => 'Onde a base de candidatos entra no sistema?',
                        'answer' => 'A entrada principal é /import. O operador envia uma planilha XLS ou XLSX com colunas como nome, telefone, WhatsApp, e-mail, skill, estado e cidade.',
                    ],
                    [
                        'question' => 'O que acontece depois da importação?',
                        'answer' => 'O sistema cria ou consolida registros em recruit_candidates e tabelas auxiliares como contatos, skills, endereços e histórico de status. A importação também registra lote, linhas processadas, duplicidades e erros.',
                    ],
                    [
                        'question' => 'Como a base importada passa a ser usada?',
                        'answer' => 'Depois da importação, os candidatos ficam visíveis em /candidates, podem receber campanhas, podem entrar em triagem W13 e depois seguir para portal e operação.',
                    ],
                    [
                        'question' => 'Onde eu confiro se a importação ficou correta?',
                        'answer' => 'Na própria tela de resultado da importação e depois em /candidates. O ideal é validar total importado, duplicados, erros por linha e se os filtros por skill e estado refletem o arquivo enviado.',
                    ],
                ],
            ],
            [
                'id' => 'campaigns',
                'eyebrow' => 'Relacionamento',
                'title' => 'Campanhas, fila e retornos inbound',
                'summary' => 'Como a gestão dispara mensagens, processa a fila e transforma respostas em ações dentro do sistema.',
                'items' => [
                    [
                        'question' => 'Qual é a diferença entre disparo manual e bot de triagem W13?',
                        'answer' => 'Disparo manual envia a mensagem da campanha e acompanha resposta básica. O modo W13 cria sessões de triagem por candidato, conduz o fluxo por etapas e registra classificação preliminar técnica e de campo.',
                    ],
                    [
                        'question' => 'O que acontece quando uma campanha é criada?',
                        'answer' => 'O sistema captura um snapshot do público no momento da criação, gera recipients da campanha e alimenta a fila recruit_message_queue com mensagens pendentes para processamento.',
                    ],
                    [
                        'question' => 'Como a fila de mensagens é processada?',
                        'answer' => 'Ela pode ser processada na própria interface de campanhas ou por CLI, com php bin/process_campaign_queue.php. O processamento ocorre em lotes, respeitando tamanho configurado, tentativas, scheduled_at e falhas transitórias.',
                    ],
                    [
                        'question' => 'O que é o inbound em /triage/inbound?',
                        'answer' => 'Esse endpoint recebe mensagens e eventos do WhatsGW. Dependendo do tipo de automação, ele interpreta a resposta, atualiza a campanha, registra webhook e pode disparar o bot W13.',
                    ],
                ],
            ],
            [
                'id' => 'triage',
                'eyebrow' => 'Qualificação',
                'title' => 'Bot de triagem W13',
                'summary' => 'Fluxo guiado para pré-filtro, qualificação técnica e encaminhamento operacional.',
                'items' => [
                    [
                        'question' => 'Como o bot W13 começa?',
                        'answer' => 'Quando a campanha está em modo triage_w13 e a fila é processada, o sistema cria uma sessão por destinatário em recruit_triage_sessions. A conversa começa pela oferta inicial e segue para pré-filtro, qualificação e readiness de campo.',
                    ],
                    [
                        'question' => 'Quais dados o W13 tenta coletar?',
                        'answer' => 'Cidade e UF, disponibilidade, estrutura mínima de campo, situação de MEI, equipamentos, experiência, ASO, NR10, NR35, ferramental e outros indicadores usados na classificação preliminar.',
                    ],
                    [
                        'question' => 'Que classificação o W13 gera?',
                        'answer' => 'Ele produz status preliminar como approved, pending, rejected ou bank, além de technical_level N1 a N3 e field_level complete, partial ou restricted, de acordo com as respostas recebidas.',
                    ],
                    [
                        'question' => 'Quando o bot entrega o caso para um operador?',
                        'answer' => 'Quando há respostas inválidas em sequência, quando a entrada sai do fluxo esperado ou quando a sessão passa a exigir validação manual. Nesse momento o needs_operator e o histórico da sessão deixam claro que a operação precisa intervir.',
                    ],
                ],
            ],
            [
                'id' => 'portal',
                'eyebrow' => 'Documentação',
                'title' => 'Portal do candidato e anexos',
                'summary' => 'Como o link é criado, o que o candidato preenche e como isso volta para o backoffice.',
                'items' => [
                    [
                        'question' => 'Como o portal é gerado para um candidato?',
                        'answer' => 'Na tela do candidato, o operador gera ou regenera o portal. O sistema cria um token único, salva o portal no banco e tenta enviar o link por WhatsApp para o contato principal.',
                    ],
                    [
                        'question' => 'O que o candidato faz no portal?',
                        'answer' => 'Ele preenche perfil, disponibilidade, experiência, CNPJ ou MEI, CPF, Pix e anexa documentos exigidos pelo fluxo W13, tudo por uma página pública identificada pelo token.',
                    ],
                    [
                        'question' => 'Quais documentos o sistema aceita nesse fluxo?',
                        'answer' => 'Documento de identidade, comprovante de residência, cartão CNPJ ou comprovante MEI, ASO, NR10, NR35, currículo, certificado técnico e anexos classificados como outro, conforme a etapa do processo.',
                    ],
                    [
                        'question' => 'Como a equipe interna acompanha o portal?',
                        'answer' => 'No detalhe do candidato. Lá ficam status do portal, perfil consolidado, checklist documental, links de visualização e ações internas de atualização.',
                    ],
                ],
            ],
            [
                'id' => 'operations',
                'eyebrow' => 'Decisão',
                'title' => 'Validação operacional',
                'summary' => 'Fila de análise, pendências, histórico e decisão final do candidato dentro do sistema.',
                'items' => [
                    [
                        'question' => 'Qual é o papel da tela /operations?',
                        'answer' => 'Ela concentra a fila operacional dos candidatos que já chegaram na etapa de análise. O operador vê documentos, perfil, pendências, notas e toma decisões controladas.',
                    ],
                    [
                        'question' => 'Como a equipe trata problemas documentais?',
                        'answer' => 'Cada documento pode ser aprovado, reprovado ou marcado como correction_requested. Quando necessário, o sistema cria pendências formais com título, descrição, responsável, data e histórico.',
                    ],
                    [
                        'question' => 'Quais decisões finais existem para o candidato?',
                        'answer' => 'Aprovar, pedir correção ou reprovar. Essas ações atualizam histórico, portal, pendências e status do candidato para manter o processo coerente e auditável.',
                    ],
                    [
                        'question' => 'O que muda em relação a um processo manual fora do sistema?',
                        'answer' => 'A operação passa a ficar toda dentro do backoffice: fila, documentos, observações, decisões, resolução de pendências e sincronização de status, sem depender de controles paralelos dispersos.',
                    ],
                ],
            ],
            [
                'id' => 'integration',
                'eyebrow' => 'Infra',
                'title' => 'WhatsGW, automação e operação contínua',
                'summary' => 'Como a integração externa e os modos de processamento mantêm o fluxo rodando.',
                'items' => [
                    [
                        'question' => 'Quais eventos do WhatsGW o sistema espera?',
                        'answer' => 'Principalmente message, status e phonestate. O evento message alimenta inbound e triagem, status atualiza entrega e leitura, e phonestate acompanha o estado do número ou instância.',
                    ],
                    [
                        'question' => 'Onde esses eventos ficam registrados?',
                        'answer' => 'Os webhooks ficam armazenados em recruit_whatsgw_webhook_events. Além disso, a fila e o inbound guardam provider_message_id, provider_waid, estado da mensagem e payloads relevantes.',
                    ],
                    [
                        'question' => 'Como manter o sistema rodando sem depender da tela aberta?',
                        'answer' => 'Use o agendador externo via CLI com process_campaign_queue.php em cron, Task Scheduler ou outro orquestrador. Isso evita depender do modo automático pelo navegador.',
                    ],
                    [
                        'question' => 'Quais são os problemas operacionais mais comuns?',
                        'answer' => 'Setup não executado, login com usuário inativo, migrations parciais, fila não processada, webhook mal configurado ou falha de envio externo no WhatsGW. A primeira checagem deve ser rota, credenciais, status do usuário e integridade das migrations.',
                    ],
                ],
            ],
        ];

        $this->render('faq/index', [
            'quickLinks' => $quickLinks,
            'processFlow' => $processFlow,
            'faqGroups' => $faqGroups,
        ], 'FAQ');
    }
}
