# Deploy em VPS com Docker

Este guia sobe:

- `app`: Apache + PHP 8.3 servindo o sistema
- `worker`: processador da fila de campanhas em loop
- `db`: MySQL 8 com migrations iniciais automáticas

## 1) Preparar a VPS

Instale Docker Engine + Docker Compose plugin.

Exemplo Ubuntu:

```bash
sudo apt-get update
sudo apt-get install -y ca-certificates curl gnupg
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \
  $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
```

## 2) Subir projeto no servidor

```bash
git clone <URL_DO_REPOSITORIO> TechRecruit
cd TechRecruit
```

## 3) Configurar ambiente

```bash
cp .env.docker.example .env.docker
```

Ajuste no `.env.docker` (obrigatorio):

- `APP_URL` com sua URL publica
- `DB_PASS` e `MYSQL_ROOT_PASSWORD` fortes
- mantenha `DB_*` e `MYSQL_*` com os mesmos valores de banco/usuario/senha
- chaves `WHATSGW_*` se for usar integracao real

## 4) Build da imagem e deploy

```bash
docker compose build --pull
docker compose up -d
```

Ver status:

```bash
docker compose ps
docker compose logs -f app
```

## 5) Criar primeiro usuario interno

```bash
docker compose exec app php bin/create_management_user.php \
  --name="Admin TechRecruit" \
  --email="admin@empresa.com" \
  --username="admin.techrecruit" \
  --password="SENHA_FORTE" \
  --role=admin
```

## 6) Exposicao publica (dominio + SSL)

O container publica a porta `8080`.
Se quiser mudar, altere o mapeamento de portas no `docker-compose.yml`.

Opcoes comuns:

1. Reverse proxy Nginx/Caddy na VPS apontando para `127.0.0.1:8080`
2. Cloudflare Tunnel
3. Load balancer externo

Com reverse proxy, encaminhe cabecalho `X-Forwarded-Proto=https`.

Exemplo Nginx pronto no projeto:

- `docs/nginx-reverse-proxy.example.conf`

## 7) Atualizar versao no futuro

```bash
git pull
docker compose build --pull
docker compose up -d
```

## 8) Backup recomendado

Banco:

```bash
docker compose exec -T db sh -lc 'mysqldump -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' > backup.sql
```

Documentos/importacoes:

```bash
docker run --rm -v techrecruit_storage:/data -v "$PWD":/backup busybox \
  tar czf /backup/storage-backup.tar.gz -C /data .
```

## Publicar imagem em registry (opcional)

Se quiser versionar a imagem fora da VPS:

```bash
docker tag techrecruit:latest seuusuario/techrecruit:v1
docker push seuusuario/techrecruit:v1
```

Depois, no `docker-compose.yml`, troque a imagem do serviço `app` e `worker` para essa imagem publicada.
