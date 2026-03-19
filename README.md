# TechRecruit Flow

Aplicação web PHP para importar planilhas de candidatos, consolidar base de recrutamento e operar status de candidatos.

`v0.2.0` inicia a base de campanhas WhatsApp: criação de campanha, segmentação simples, snapshot dos destinatários e fila inicial de mensagens pendentes.

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
3. Informe um script usando `{first_name}` ou `{full_name}` se quiser personalização básica
4. Abra a campanha criada e clique em `Processar fila`
5. Simule um retorno inbound com frases como `sim tenho interesse`, `nao tenho interesse` ou `sair da lista`
6. Confirme que a campanha foi criada com:
   - público capturado
   - destinatários associados
   - fila inicial em `pending`
   - mensagens processadas como `sent`
   - inbound gravado com intenção interpretada
   - status do candidato atualizado conforme o retorno

## Estrutura principal

- `public/index.php`: bootstrap e rotas
- `src/Controllers`: controllers HTTP
- `src/Controllers/CampaignController.php`: CRUD inicial de campanhas WhatsApp
- `src/Models`: acesso a dados
- `src/Services/CampaignService.php`: montagem da fila inicial de campanha
- `src/Services/ImportService.php`: regra de importação Excel
- `database/migrations/001_create_recruit_tables.sql`: schema inicial
- `database/migrations/002_create_campaign_tables.sql`: schema de campanhas e fila de mensagens
- `database/migrations/003_create_whatsapp_operation_tables.sql`: inbound, opt-out e trilha operacional
- `storage/imports`: arquivos importados (ignorado no git)

## Observações

- Arquivos de `storage/` são ignorados no git.
- O projeto não usa framework fullstack; o roteamento e renderização são próprios.
