# TechRecruit Flow

Aplicação web PHP para importar planilhas de candidatos, consolidar base de recrutamento e operar status de candidatos.

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
```

## 5. Rodar localmente

```bash
php -S 127.0.0.1:8090 -t public
```

Abra no navegador:

- `http://127.0.0.1:8090/`
- `http://127.0.0.1:8090/import`
- `http://127.0.0.1:8090/candidates`

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

## Estrutura principal

- `public/index.php`: bootstrap e rotas
- `src/Controllers`: controllers HTTP
- `src/Models`: acesso a dados
- `src/Services/ImportService.php`: regra de importação Excel
- `database/migrations/001_create_recruit_tables.sql`: schema inicial
- `storage/imports`: arquivos importados (ignorado no git)

## Observações

- Arquivos de `storage/` são ignorados no git.
- O projeto não usa framework fullstack; o roteamento e renderização são próprios.
