# TechRecruit Flow

Aplicação web PHP para importar planilhas de candidatos, consolidar base de recrutamento e operar status de candidatos.

`v0.2.0` inicia a base de campanhas WhatsApp: criação de campanha, segmentação simples, snapshot dos destinatários e fila inicial de mensagens pendentes.

`v0.3.0` adiciona o bot de triagem W13: sessao por candidato, fluxo por etapa, captura de respostas, classificacao automatica de interesse, coleta de qualificacao e fallback para operador.

`v0.4.0` adiciona o portal de cadastro e documentos: link unico por token, formulario web, upload de anexos, checklist documental, aceite de termos e visualizacao interna no backoffice.

`v0.5.0` adiciona a validacao operacional: fila de analise, aprovacao/reprovacao, pedido de correcao, pendencias, observacoes internas, historico de decisao e mudanca de status controlada dentro do sistema.

## Requisitos

- PHP `>= 8.2`
- Composer
- MySQL `8+` (ou MariaDB compatível)
- Extensões PHP:
  - `pdo_mysql`
  - `mbstring`
  - `xml`
  - `zip`
  - `gd`

## 1. Baixar o projeto

Via `git`:

```bash
git clone <URL_DO_REPOSITORIO> TechRecruit
cd TechRecruit
```

Se você já baixou a pasta, apenas entre nela:

```bash
cd TechRecruit
```

## 2. Instalar dependências

```bash
composer install
```

## 3. Configurar ambiente

Crie o `.env` a partir do exemplo:

```bash
cp .env.example .env
```

Edite o `.env` com os dados do seu banco:

```env
DB_HOST=127.0.0.1
DB_NAME=techrecruit
DB_USER=root
DB_PASS=password
WHATSGW_BASE_URL=https://app.whatsgw.com.br/api/WhatsGw
WHATSGW_API_KEY=seu-token
WHATSGW_DEFAULT_COUNTRY_CODE=55
WHATSGW_PHONE_NUMBER=5511999999999
WHATSGW_INSTANCE_ID=
WHATSGW_CHECK_STATUS=1
WHATSGW_SIMULATE_TYPING=0
WHATSGW_TIMEOUT_SECONDS=30
CAMPAIGN_QUEUE_BATCH_SIZE=25
CAMPAIGN_QUEUE_MAX_ATTEMPTS=5
CAMPAIGN_QUEUE_STALE_MINUTES=10
CAMPAIGN_QUEUE_AUTO_INTERVAL_SECONDS=15
```

## 4. Criar banco e tabelas

Crie o banco no MySQL:

```sql
CREATE DATABASE techrecruit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Aplique a migration SQL:

```bash
mysql -u root -p techrecruit < database/migrations/001_create_recruit_tables.sql
mysql -u root -p techrecruit < database/migrations/002_create_campaign_tables.sql
mysql -u root -p techrecruit < database/migrations/003_create_whatsapp_operation_tables.sql
mysql -u root -p techrecruit < database/migrations/004_create_candidate_portal_tables.sql
mysql -u root -p techrecruit < database/migrations/005_create_operational_review_tables.sql
mysql -u root -p techrecruit < database/migrations/006_create_triage_bot_tables.sql
mysql -u root -p techrecruit < database/migrations/007_create_whatsgw_integration_tables.sql
```

## 5. Rodar localmente

```bash
php -S 127.0.0.1:8090 -t public
```

Abra no navegador:

- `http://127.0.0.1:8090/`
- `http://127.0.0.1:8090/import`
- `http://127.0.0.1:8090/candidates`
- `http://127.0.0.1:8090/campaigns`
- `http://127.0.0.1:8090/operations`
- `http://127.0.0.1:8090/portal/{token}`
- `POST http://127.0.0.1:8090/triage/inbound`

## 6. Teste manual rápido

### Teste de importação

1. Vá em `/import`
2. Envie um arquivo `.xlsx` ou `.xls` com cabeçalhos:
   - `nome`, `telefone`, `whatsapp`, `email`, `skill`, `estado`, `cidade`
3. Verifique no resultado:
   - total importado
   - duplicados
   - erros por linha

### Teste de filtros

1. Vá em `/candidates`
2. Filtre por `skill`
3. Confirme se a listagem mostra apenas os candidatos esperados

### Teste de status

1. Abra `/candidates/{id}`
2. Altere o status para `interested`
3. Confirme atualização no candidato e no histórico

### Teste de campanha WhatsApp base

1. Vá em `/campaigns`
2. Crie uma campanha com `skill`, `estado` ou `status` opcional
3. Escolha o modo da automacao:
   - `Bot de triagem W13` para o fluxo `1 / 2 / 3`
   - `Broadcast manual` para o fluxo simples de campanha
4. Informe um script usando `{first_name}` ou `{full_name}` se quiser personalização básica
5. Abra a campanha criada e clique em `Processar fila`
   - o processamento agora roda por lote, com tamanho configuravel na tela
   - por padrão, o lote sugerido é `25`
6. Simule um retorno inbound com frases como `sim tenho interesse`, `nao tenho interesse` ou `sair da lista`
7. Confirme que a campanha foi criada com:
   - público capturado
   - destinatários associados
   - fila inicial em `pending`
   - mensagens processadas como `sent`
   - falhas transitórias voltando para retry com `scheduled_at`
   - inbound gravado com intenção interpretada
   - status do candidato atualizado conforme o retorno

### Execucao automatica da fila

Voce pode operar de dois jeitos:

1. Agendador interno via navegador
   - abra `/campaigns` ou a tela de detalhe da campanha
   - marque `Auto a cada 15s`
   - deixe a tela aberta em uma aba visivel
   - a interface dispara lotes periodicos e atualiza a pagina

2. Agendador externo via CLI
   - execute manualmente:

```bash
php bin/process_campaign_queue.php --limit=25
```

   - para uma campanha especifica:

```bash
php bin/process_campaign_queue.php --campaign-id=12 --limit=25
```

   - exemplo de cron Linux:

```bash
* * * * * cd /caminho/para/TechRecruit && php bin/process_campaign_queue.php --limit=25 >> storage_campaign_queue.log 2>&1
```

   - exemplo de Windows Task Scheduler:

```powershell
php.exe C:\caminho\TechRecruit\bin\process_campaign_queue.php --limit=25
```

### Teste do bot de triagem W13

1. Crie uma campanha em `/campaigns` usando `Bot de triagem W13`
2. Processe a fila para abrir as sessoes do bot
3. Na tela da campanha, use `Simular retorno inbound` com:
   - `1` para ir para a etapa de qualificacao
   - `2` para encerrar como nao interessado
   - `3` para receber mais detalhes e depois responder `SIM`
4. Depois envie a qualificacao em texto livre, por exemplo:

```text
Nome completo: Joao da Silva
Cidade atual: Campinas
UF: SP
Telefone: 11999990000
Possui notebook: sim
Possui cabo console: sim
Tem disponibilidade imediata: sim
Pode retirar equipamento em base: sim
```

5. Confirme na tela da campanha:
   - sessao de triagem criada por destinatario
   - status de triagem atualizado para `interested`, `needs_details`, `not_interested` ou `awaiting_validation`
   - dados de qualificacao salvos na sessao
   - fallback para operador quando houver duas respostas invalidas seguidas
6. Opcionalmente teste o endpoint inbound:

```bash
curl -X POST http://127.0.0.1:8090/triage/inbound \
  -H "Content-Type: application/json" \
  -d '{
    "contact": "5511999990000",
    "message_body": "1"
  }'
```

### Teste do WhatsGW real

1. Configure `WHATSGW_API_KEY` e `WHATSGW_PHONE_NUMBER` no `.env`
2. Aponte o webhook do WhatsGW para `POST /triage/inbound`
3. Garanta que o WhatsGW envie os eventos:
   - `message`
   - `status`
   - `phonestate`
4. Processe a fila de uma campanha
5. Confirme:
   - `recruit_message_queue` com `provider_message_custom_id` preenchido
   - webhooks salvos em `recruit_whatsgw_webhook_events`
   - `event=message` entrando no bot de triagem
   - `event=status` atualizando entrega/leitura
   - `event=phonestate` atualizando o estado do numero/instancia

### Teste do portal de cadastro e documentos

1. Vá em `/candidates/{id}`
2. Clique em `Gerar link do portal`
3. Abra o link/token gerado
4. Preencha o formulário, aceite os termos e envie os documentos obrigatórios
5. Volte ao candidato no backoffice e confirme:
   - status do portal
   - checklist documental
   - dados enviados pelo candidato
   - anexos internos com visualização

### Teste da validacao operacional

1. Garanta que o candidato ja enviou o portal e os documentos obrigatorios
2. Va em `/operations`
3. Abra um candidato da fila operacional
4. Registre uma observacao interna
5. Na analise documental:
   - aprove um documento valido
   - ou use `Pedir correcao` / `Reprovar` com motivo
6. Confirme no detalhe do candidato:
   - criacao de pendencia
   - historico de decisao
   - status do portal e do candidato sincronizados
7. Resolva a pendencia
8. Registre a decisao final do candidato:
   - `Aprovar`
   - `Pedir correcao`
   - `Reprovar`
9. Confirme que a operacao passa a trabalhar inteiramente dentro do sistema, com fila, trilha e mudancas controladas

## Estrutura principal

- `public/index.php`: bootstrap e rotas
- `src/Controllers`: controllers HTTP
- `src/Controllers/CampaignController.php`: CRUD inicial de campanhas WhatsApp
- `src/Controllers/OperationsController.php`: fila operacional e decisoes de validacao
- `src/Controllers/PortalController.php`: portal público por token e ações internas do portal
- `src/Controllers/TriageController.php`: endpoint inbound para o bot/WhatsGW
- `src/Models`: acesso a dados
- `src/Models/OperationsModel.php`: fila de analise, pendencias e historico operacional
- `src/Models/PortalModel.php`: leitura do portal, checklist e anexos
- `src/Services/CampaignService.php`: montagem da fila inicial de campanha
- `src/Services/TriageBotService.php`: motor do bot de triagem, steps, captura e fallback
- `src/Services/WhatsGwClient.php`: cliente HTTP para envio real pelo WhatsGW
- `src/Services/WhatsGwWebhookService.php`: adapter de eventos `message/status/phonestate`
- `src/Services/OperationsService.php`: aprovacao, reprovacao, correcao, pendencias e sync de status
- `src/Services/PortalService.php`: geração do link, submissão e sync do portal
- `src/Services/ImportService.php`: regra de importação Excel
- `database/migrations/001_create_recruit_tables.sql`: schema inicial
- `database/migrations/002_create_campaign_tables.sql`: schema de campanhas e fila de mensagens
- `database/migrations/003_create_whatsapp_operation_tables.sql`: inbound, opt-out e trilha operacional
- `database/migrations/004_create_candidate_portal_tables.sql`: token, perfil do portal e documentos
- `database/migrations/005_create_operational_review_tables.sql`: pendencias e historico da validacao operacional
- `database/migrations/006_create_triage_bot_tables.sql`: sessoes do bot, respostas estruturadas e automacao W13
- `database/migrations/007_create_whatsgw_integration_tables.sql`: rastreio do provedor, webhook e estado do telefone
- `storage/imports`: arquivos importados (ignorado no git)
- `storage/portal-documents`: anexos enviados pelos candidatos

## Observações

- Arquivos de `storage/` são ignorados no git.
- O projeto não usa framework fullstack; o roteamento e renderização são próprios.
