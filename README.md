# PostFlow

SaaS multi-tenant que gera e publica posts no Instagram (feed, carrossel e Stories) com conteúdo do Claude e arte renderizada a partir de HTML. A especificação completa está em `docs/especificacao.md`.

## Estado atual: Etapa 1 – Fundação ✅

| Entrega | Onde |
| --- | --- |
| Laravel 12 + PostgreSQL | `config/database.php`, `.env` |
| Migrations de todas as tabelas do modelo de dados | `database/migrations/2026_10_31_*` |
| Models + escopo multi-tenant (`tenant_id` em toda consulta) | `app/Models`, `app/Models/Concerns/BelongsToTenant.php`, `app/Support/Tenancy/TenantContext.php` |
| Criptografia do `access_token` (AES-256, chave própria fora do banco) | `app/Casts/EncryptedToken.php`, `app/Support/TokenEncrypter.php` |
| Filas `content`, `render`, `publish` + job de teste | `app/Jobs/Pipeline/PipelineProbeJob.php`, `php artisan postflow:pipeline-test` |
| Auditoria de status dos posts | `Post::transitionTo()` grava em `post_events` |
| Worker Node (renderizador) | `workers/renderer` (esqueleto + `/health`; Playwright na etapa 4) |
| Worker Python (pré-processador) | `workers/preprocessor` (esqueleto + `/health`; rembg/Pillow na etapa 4) |
| docker-compose | `docker-compose.yml`, `docker/app/Dockerfile` |

> **Laravel 12 em vez de 11:** o Composer bloqueia o Laravel 11 por avisos de segurança (fim do suporte). O 12 roda no PHP 8.2 do XAMPP e não muda nada na especificação.

### Decisões de arquitetura

- **As três filas são do Laravel (Redis).** O worker da fila `render` chama o renderizador (Node) e o pré-processador (Python) por HTTP interno, em vez de usar BullMQ. Assim não há dois formatos de job no Redis e o status do post continua sendo atualizado num lugar só. Só o worker `publish` fala com a Meta.
- **Tenancy:** `TenantContext` guarda o tenant atual; os models com `BelongsToTenant` filtram e preenchem `tenant_id` sozinhos. Sem tenant definido (agendador, comandos de sistema) não há filtro, e o código de sistema entra no tenant com `TenantContext::run($tenant, fn () => ...)`. O contexto é zerado a cada requisição e a cada job.
- **`style_presets` globais:** `tenant_id` nulo = preset do admin, visível para todos; dentro de um tenant, aparecem os globais e os dele.
- **Tabelas filhas** (`idea_assets`, `post_versions`, `post_events`) não têm `tenant_id`, como na especificação; herdam o tenant do registro pai.
- **Chave dos tokens separada da `APP_KEY`** (`POSTFLOW_TOKEN_KEY`): trocar a chave da aplicação não derruba as contas conectadas.

## Etapa 2 – Conexão Instagram ✅ (código) · ⏳ (aceite com a conta real)

| Entrega | Onde |
| --- | --- |
| OAuth com Business Login for Instagram | `GET /api/v1/instagram/connect` e `/callback` → `app/Http/Controllers/InstagramOAuthController.php` |
| Código → token curto → token longo (60 dias) → perfil (`user_id`, `username`) | `app/Services/Instagram/InstagramClient.php`, `InstagramAccountService::connect()` |
| `state` de uso único (15 min) amarrando o callback ao tenant, sem depender de sessão | `app/Services/Instagram/OAuthState.php` |
| Token gravado criptografado; reconectar atualiza a conta e volta para `ativo` | `instagram_accounts` (cast `EncryptedToken`) |
| Renovação diária às 03:00 dos tokens que vencem em < 10 dias, na fila `publish` | `routes/console.php`, `app/Jobs/Instagram/RefreshInstagramTokenJob.php` |
| Falha na renovação → conta em `reconectar` + e-mail aos usuários do tenant | `InstagramAccountService::markReconnect()`, `app/Notifications/InstagramReconnectNeeded.php` |
| Link de conexão para a fase 1 (sem painel) | `php artisan postflow:instagram-connect-url Calutec` |
| Renovação forçada | `php artisan postflow:instagram-refresh --account=ID --force --sync` |

Regras da renovação: token revogado/inválido (código 190/102) ou já vencido vai direto para `reconectar`, sem novas tentativas; erros temporários (rede, throttling) tentam 3 vezes com espera crescente (1, 5 e 15 min) e só então marcam `reconectar`.

### Configurar o app Meta (uma vez)

1. Em [developers.facebook.com](https://developers.facebook.com/apps), crie um app do tipo **Empresa** ligado ao portfólio de negócios da Calutec e adicione o produto **Instagram** → *Configuração da API com login do Instagram*.
2. Copie o **ID do app do Instagram** e a **chave secreta do app do Instagram** (não são os do app Facebook) para `INSTAGRAM_APP_ID` e `INSTAGRAM_APP_SECRET`.
3. Em *Configurar login comercial do Instagram*, cadastre a URL de redirecionamento exatamente igual a `INSTAGRAM_REDIRECT_URI`. A Meta exige **HTTPS**: em produção use o domínio do VPS; para testar no Windows, exponha a porta local com um túnel (ex.: `cloudflared tunnel --url http://localhost:8000`) e use a URL do túnel em `APP_URL` e no redirect.
4. Em modo de desenvolvimento, adicione a conta do Instagram da Calutec (profissional) em *Funções do app → Testadores do Instagram* e aceite o convite no app do Instagram (Configurações → Apps e sites).
5. Rode `php artisan db:seed` (cria os tenants Calutec e JA Conveniência), gere o link com `php artisan postflow:instagram-connect-url Calutec`, abra no navegador e autorize.
6. Aceite da etapa: a conta aparece em `instagram_accounts` com `status = ativo`. A renovação forçada só funciona com o token tendo **pelo menos 24 h** (regra da Meta); antes disso a Meta recusa.

## Como rodar

### Com Docker (alvo de produção no VPS)

```bash
cp .env.example .env
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan postflow:token-key
docker compose up -d --build
docker compose exec app php artisan migrate
docker compose exec app php artisan postflow:pipeline-test
```

Serviços: `app` (porta 8000), `queue-content`, `queue-render`, `queue-publish`, `scheduler`, `renderer` (3001), `preprocessor` (3002), `postgres`, `redis`.

### Local no Windows (XAMPP)

O PHP do XAMPP vem com `pdo_pgsql` desativado. Passe a extensão na linha de comando (ou ative `extension=pdo_pgsql` no `php.ini`):

```bash
php -d extension=pdo_pgsql artisan migrate:fresh
```

Sem Redis local, use `QUEUE_CONNECTION=database` e `CACHE_STORE=database` no `.env`. Para o job de teste, abra um worker por fila e os dois serviços:

```bash
php -d extension=pdo_pgsql artisan queue:work --queue=content
php -d extension=pdo_pgsql artisan queue:work --queue=render
php -d extension=pdo_pgsql artisan queue:work --queue=publish
node workers/renderer/server.js
python workers/preprocessor/app.py
php -d extension=pdo_pgsql artisan postflow:pipeline-test
```

## Testes

```bash
php artisan test                                   # Laravel (SQLite em memória)
cd workers/renderer && node --test                 # renderizador
cd workers/preprocessor && python -m unittest      # pré-processador
```

## Próximas etapas

3. Publicação manual · 4. Renderização e assets · 5. Geração com o Claude · 6. Preview e aprovação · 7. Agendas, ideias e modos · 8. Catálogo próprio · 9. Produto
