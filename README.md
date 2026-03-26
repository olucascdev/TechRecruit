# TechRecruit Flow

Aplicação web PHP para importar planilhas de candidatos, consolidar base de recrutamento e operar status de candidatos.

`v0.2.0` inicia a base de campanhas WhatsApp: criação de campanha, segmentação simples, snapshot dos destinatários e fila inicial de mensagens pendentes.

`v0.3.0` adiciona o bot de triagem W13: sessão por candidato, fluxo por etapa, captura de respostas, classificação automática de interesse, coleta de qualificação e encaminhamento para operador.

`v0.4.0` adiciona o portal de cadastro e documentos: link único por token, formulário web, upload de anexos, checklist documental, aceite de termos e visualização interna no backoffice.

`v0.5.0` adiciona a validação operacional: fila de análise, aprovação/reprovação, pedido de correção, pendências, observações internas, histórico de decisão e mudança de status controlada dentro do sistema.

`v0.6.0` expande o modelo W13: triagem em 3 etapas (captação, pré-filtro e segurança), classificação automática (Aprovado/Pendente/Reprovado/Banco, N1-N3 e nível de campo) e portal documental com CNPJ/PIX/ASO/NRs.

`v0.6.1` automatiza o envio do portal: ao gerar ou regenerar o link no backoffice, o sistema tenta enviar o URL por WhatsApp para o contato principal do candidato.

## Requisitos

- PHP `>= 8.2`
- Composer
- Node.js `>= 18`
- npm
- MySQL `8+` (ou MariaDB compatível)
- Extensões PHP:
  - `curl`
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
npm install
npm run build:css
```

Durante o desenvolvimento, para recompilar o Tailwind automaticamente:

```bash
npm run watch:css
```

## 3. Configurar ambiente

Crie o `.env` a partir do exemplo:

```bash
cp .env.example .env
```

Edite o `.env` com os dados do seu banco:

```env
APP_URL=https://recrutamento.suaempresa.com
DB_HOST=127.0.0.1
DB_NAME=techrecruit
DB_USER=root
DB_PASS=password
WHATSGW_BASE_URL=https://app.whatsgw.com.br/api/WhatsGw
WHATSGW_API_KEY=seu-token
WHATSGW_WEBHOOK_API_KEY=seu-token-ou-outro-segredo
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

Notas:

- `APP_URL` deve apontar para a URL publica real do sistema. Os links do portal usam esse valor em ambientes com proxy reverso ou SSL externo.
- `WHATSGW_WEBHOOK_API_KEY` protege o `POST /triage/inbound`. Se ficar vazio, o endpoint rejeita eventos do provedor. Se preferir, ele pode reutilizar o valor de `WHATSGW_API_KEY`.

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
mysql -u root -p techrecruit < database/migrations/008_expand_w13_flow.sql
mysql -u root -p techrecruit < database/migrations/009_create_management_users.sql
mysql -u root -p techrecruit < database/migrations/010_add_management_usernames.sql
mysql -u root -p techrecruit < database/migrations/011_add_portal_banking_fields.sql
```

## 4.1 Criar o primeiro usuário interno

O login do backoffice agora segue a mesma lógica do `recargaaki`:

- se não existir nenhum usuário interno, o primeiro administrador é criado em `http://127.0.0.1:8090/setup`
- depois do bootstrap inicial, o setup é bloqueado
- o login aceita `username` ou e-mail
- novos acessos continuam restritos a um admin em `/management/users`

Opção 1, setup web:

- acesse `http://127.0.0.1:8090/setup`
- cadastre `nome`, `email`, `username` e `senha`
- depois entre em `http://127.0.0.1:8090/login`

Opção 2, CLI:

```bash
php bin/create_management_user.php --name="Admin TechRecruit" --email="admin@empresa.com" --username="admin.techrecruit" --password="SENHA_SEGURA" --role=admin
```

Depois disso:

- acesse `http://127.0.0.1:8090/setup` e confirme que o setup redireciona para login
- acesse `http://127.0.0.1:8090/login`
- entre com o `username` ou e-mail do usuário criado
- use `/management/users` para cadastrar outros usuários internos com role `admin` ou `manager`

## 5. Rodar localmente

```bash
php -S 127.0.0.1:8090 -t public
```

Abra no navegador:

- `http://127.0.0.1:8090/setup`
- `http://127.0.0.1:8090/login`
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
3. Escolha o modo da automação:
   - `Bot de triagem W13` para o fluxo `1 / 2 / 3`
   - `Disparo manual` para o fluxo simples de campanha
4. Informe um script usando `{first_name}` ou `{full_name}` se quiser personalização básica
5. Abra a campanha criada e clique em `Processar fila`
   - o processamento agora roda por lote, com tamanho configurável na tela
   - por padrão, o lote sugerido é `25`
6. Simule um retorno inbound com frases como `sim tenho interesse`, `não tenho interesse` ou `sair da lista`
7. Confirme que a campanha foi criada com:
   - público capturado
   - destinatários associados
   - fila inicial em `pending`
   - mensagens processadas como `sent`
   - falhas transitórias voltando para retry com `scheduled_at`
   - inbound gravado com intenção interpretada
   - status do candidato atualizado conforme o retorno

### Execução automática da fila

Você pode operar de dois jeitos:

1. Agendador interno via navegador
   - abra `/campaigns` ou a tela de detalhe da campanha
   - marque `Auto a cada 15s`
   - deixe a tela aberta em uma aba visível
   - a interface dispara lotes periódicos e atualiza a página

2. Agendador externo via CLI
   - execute manualmente:

```bash
php bin/process_campaign_queue.php --limit=25
```

   - para uma campanha específica:

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
2. Processe a fila para abrir as sessões do bot
3. Na tela da campanha, use `Simular retorno inbound` com:
   - `1` para ir para a etapa de pre-filtro
   - `2` para encerrar como não interessado
   - `3` para receber mais detalhes e depois responder `SIM`
4. Depois envie o pre-filtro em texto livre, por exemplo:

```text
Cidade/UF: Campinas/SP
MEI ativo: sim
Notebook: sim
Cabo console: sim
Serviços: 2, 3, 5
Disponibilidade imediata: sim
```

5. Em seguida envie a qualificação técnica e de segurança, por exemplo:

```text
ASO: sim
NR10: sim
NR35: não
Ferramental completo: sim
Ferramentas: multímetro, kit de ferramentas, alicate de crimpagem
```

6. Confirme na tela da campanha:
   - sessão de triagem criada por destinatário
   - status de triagem atualizado para `interested`, `needs_details`, `not_interested` ou `awaiting_validation`
   - dados de pré-filtro, segurança e classificação salvos na sessão
   - classificação preliminar W13 com:
     - `status`: `approved`, `pending`, `rejected` ou `bank`
     - `technical_level`: `N1`, `N2` ou `N3`
     - `field_level`: `complete`, `partial` ou `restricted`
   - encaminhamento para operador quando houver duas respostas inválidas seguidas
7. Opcionalmente teste o endpoint inbound:

```bash
curl -X POST http://127.0.0.1:8090/triage/inbound \
  -H "Content-Type: application/json" \
  -d '{
    "event": "message",
    "apikey": "seu-token-ou-outro-segredo",
    "contact_phone_number": "5511999990000",
    "message_body": "1"
  }'
```

Sem `event`, o inbound manual agora exige sessão autenticada do backoffice e CSRF válido. Para simular manualmente pela interface, use a tela da campanha em `/campaigns/{id}`.

### Teste de autenticação interna

1. Se o banco estiver vazio, acesse `/setup` e crie o primeiro admin
2. Confirme que depois disso `/setup` deixa de aceitar novo cadastro e volta para `/login`
3. Entre em `/login` usando `username` ou e-mail
4. Confirme que sem login o backoffice redireciona para `/login` e, sem usuários, para `/setup`
5. Acesse `/management/users` com um admin
6. Crie um usuário `manager`
7. Atualize role/status de um usuário e confirme persistência
8. Faça logout pelo cabeçalho e valide que a sessão é encerrada

### Teste do WhatsGW real

1. Configure `WHATSGW_API_KEY` e `WHATSGW_PHONE_NUMBER` no `.env`
2. Configure `WHATSGW_WEBHOOK_API_KEY` no `.env` e use esse mesmo valor no webhook do provedor
3. Aponte o webhook do WhatsGW para `POST /triage/inbound`
4. Garanta que o WhatsGW envie os eventos:
   - `message`
   - `status`
   - `phonestate`
5. Processe a fila de uma campanha
6. Confirme:
   - `recruit_message_queue` com `provider_message_custom_id` preenchido
   - webhooks salvos em `recruit_whatsgw_webhook_events`
   - `event=message` entrando no bot de triagem
   - `event=status` atualizando entrega/leitura
   - `event=phonestate` atualizando o estado do número/instância

### Teste do portal de cadastro e documentos

1. Vá em `/candidates/{id}`
2. Clique em `Gerar e enviar portal`
3. Confirme no flash se o sistema enviou o link por WhatsApp ou se houve falha operacional de envio
4. Abra o link/token gerado
5. Preencha o formulário com `CNPJ / MEI`, `CPF`, `Pix`, disponibilidade e experiência
6. Envie os documentos obrigatórios W13:
   - documento de identidade
   - comprovante de residência
   - cartão CNPJ / comprovante MEI
   - ASO
   - NR10
   - NR35
7. Volte ao candidato no backoffice e confirme:
   - status do portal
   - checklist documental
   - dados enviados pelo candidato
   - anexos internos com visualização

### Teste da validação operacional

1. Garanta que o candidato já enviou o portal e os documentos obrigatórios
2. Vá em `/operations`
3. Abra um candidato da fila operacional
4. Registre uma observação interna
5. Na análise documental:
   - aprove um documento válido
   - ou use `Pedir correção` / `Reprovar` com motivo
6. Confirme no detalhe do candidato:
   - criação de pendência
   - histórico de decisão
   - status do portal e do candidato sincronizados
7. Resolva a pendência
8. Registre a decisão final do candidato:
   - `Aprovar`
   - `Pedir correção`
   - `Reprovar`
9. Confirme que a operação passa a trabalhar inteiramente dentro do sistema, com fila, trilha e mudanças controladas

## Estrutura principal

- `public/index.php`: bootstrap e rotas
- `src/Controllers`: controllers HTTP
- `src/Controllers/CampaignController.php`: CRUD inicial de campanhas WhatsApp
- `src/Controllers/OperationsController.php`: fila operacional e decisões de validação
- `src/Controllers/PortalController.php`: portal público por token e ações internas do portal
- `src/Controllers/TriageController.php`: endpoint inbound para o bot/WhatsGW
- `src/Models`: acesso a dados
- `src/Models/OperationsModel.php`: fila de análise, pendências e histórico operacional
- `src/Models/PortalModel.php`: leitura do portal, checklist e anexos
- `src/Services/CampaignService.php`: montagem da fila inicial de campanha
- `src/Services/TriageBotService.php`: motor do bot de triagem, etapas, captura e encaminhamento
- `src/Services/WhatsGwClient.php`: cliente HTTP para envio real pelo WhatsGW
- `src/Services/WhatsGwWebhookService.php`: adapter de eventos `message/status/phonestate`
- `src/Services/OperationsService.php`: aprovação, reprovação, correção, pendências e sync de status
- `src/Services/PortalService.php`: geração do link, submissão e sync do portal
- `src/Services/ImportService.php`: regra de importação Excel
- `database/migrations/001_create_recruit_tables.sql`: schema inicial
- `database/migrations/002_create_campaign_tables.sql`: schema de campanhas e fila de mensagens
- `database/migrations/003_create_whatsapp_operation_tables.sql`: inbound, opt-out e trilha operacional
- `database/migrations/004_create_candidate_portal_tables.sql`: token, perfil do portal e documentos
- `database/migrations/005_create_operational_review_tables.sql`: pendências e histórico da validação operacional
- `database/migrations/006_create_triage_bot_tables.sql`: sessões do bot, respostas estruturadas e automação W13
- `database/migrations/007_create_whatsgw_integration_tables.sql`: rastreio do provedor, webhook e estado do telefone
- `storage/imports`: arquivos importados (ignorado no git)
- `storage/portal-documents`: anexos enviados pelos candidatos

## Observações

- Arquivos de `storage/` são ignorados no git.
- O projeto não usa framework fullstack; o roteamento e renderização são próprios.

## Deploy em VPS com Docker

- Arquivos de deploy adicionados: `Dockerfile`, `docker-compose.yml`, `.env.docker.example`
- Guia completo: `docs/deploy-vps-docker.md`

## CI/CD (GitHub Actions)

Este repositório já inclui automação de integração e deploy:

- `CI`: `.github/workflows/ci.yml`
  - lint PHP
  - build CSS (`npm run build:css`)
  - build Docker de validação
- `CD`: `.github/workflows/cd.yml`
  - deploy na VPS via SSH em `push` para `main`
  - opção manual (`workflow_dispatch`) com `deploy_branch` e `deploy_mode`

### Como habilitar o deploy automático

1. Configure a variável do repositório:

```text
DEPLOY_ENABLED=true
```

2. Configure os secrets:

```text
DEPLOY_HOST
DEPLOY_USER
DEPLOY_SSH_KEY
DEPLOY_PORT (opcional)
```

3. Configure a variável (opcional):

```text
DEPLOY_PATH=/opt/TechRecruit
```

4. Na VPS, mantenha o projeto clonado em `DEPLOY_PATH` e com `.env.docker` preenchido.

O deploy remoto executa:

- `git pull` da branch alvo
- `bash scripts/deploy/docker-compose-deploy.sh build` (ou `pull`)
