# Especificação – Automação de Posts no Instagram

Oct 30, 2026 · @Lucas

## Visão geral

Um produto multi-tenant que gera e publica posts diários no Instagram de cada cliente, com conteúdo criado pelo Claude e arte renderizada a partir de templates. Os primeiros clientes são a Calutec e a JA Conveniência; depois, o produto passa a ser vendido para outros negócios.

É um sistema independente, vendido à parte: não depende do sistema da JA nem de nenhum outro. Cada cliente cadastra seus produtos, eventos e promoções no próprio painel (ou importa por CSV); integrações com sistemas externos, incluindo o da JA, ficam para uma fase futura via API.

Objetivos:

- Publicar automaticamente, no horário definido por cliente, sem intervenção manual.
- Manter a identidade visual de cada marca (logo, cores, fontes, tom de voz).
- Usar os dados do catálogo de cada cliente (produtos, preços, eventos) como matéria-prima dos posts.
- Gerar posts no automático ou a partir de ideias do cliente (texto e imagens), sempre com preview e aprovação antes de publicar.
- Manter o custo por post próximo de zero, para que o preço de venda vire margem.

## Fases do projeto

O projeto tem duas fases, separadas pelo nível de acesso à API da Meta.

| Fase | Contas atendidas | Acesso Meta | Pré-requisitos |
| --- | --- | --- | --- |
| 1 – Uso interno | Calutec e JA Conveniência | Standard Access | App Meta criado; contas profissionais vinculadas ao portfólio de negócios da Calutec |
| 2 – Produto comercial | Qualquer cliente | Advanced Access | Business Verification da Calutec (CNPJ), App Review aprovado, app em modo Live |

O código é o mesmo nas duas fases. A fase 1 valida o produto com uso real, e esse uso vira a demonstração para o App Review da fase 2.

## Formatos e modos de entrada

O sistema gera três formatos de imagem: feed, carrossel e Stories. Reels (vídeo) fica fora do escopo e entra numa fase futura.

| Formato | Tamanho | Uso típico | Observação |
| --- | --- | --- | --- |
| Feed | 1080×1350 (4:5) | Promoção, evento, novidade | Formato padrão |
| Carrossel | 2 a 10 slides de 1080×1350 | Cardápio, line-up, combos | Primeiro slide é a capa; o último traz a chamada |
| Stories | 1080×1920 (9:16) | Lembrete do dia, "é hoje", contagem regressiva | Deixar 250 px livres em cima e embaixo; a API não adiciona figurinhas de link nem enquete |

Uma mesma ideia pode gerar um kit: post de feed e Stories com a arte adaptada ao formato.

O cliente pode pedir um post de três formas, e elas se combinam:

| Modo | Como o cliente informa | Quando usar |
| --- | --- | --- |
| Automático | Nada além da agenda e do perfil da marca; o Claude escolhe o tema pelo catálogo, calendário e datas comemorativas | Constância diária sem esforço |
| Guiado | Escolhe em listas: objetivo, estilo visual, formato, itens do catálogo e oferta | Quem não sabe escrever o pedido |
| Livre | Escreve um comando e anexa arquivos (fotos, logos, flyer, PDF) | Pedidos específicos, como o flyer de Halloween |

O modo guiado sempre tem um campo de texto opcional, e o modo livre aceita um estilo escolhido na lista.

Listas do modo guiado:

- **Objetivo:** evento, promoção, novidade, combo, aviso (horário, feriado), engajamento (enquete, pergunta), data comemorativa.
- **Estilo visual:** padrão da marca, festa/neon, terror, premium, minimalista, verão, natal, junino e outros cadastrados pelo admin.
- **Segmento do negócio:** definido uma vez no perfil da marca (bar e lounge, restaurante, loja, serviços); ajusta o tom e as sugestões do modo automático.

Cada estilo é um registro em `style_presets` com descrição para o prompt, paleta, fontes, elementos decorativos e de 1 a 3 exemplos HTML de referência. O Claude usa o preset como guia e cria um layout novo a cada post; não existe template fixo.

## Arquitetura e stack

O painel e o agendador colocam jobs numa fila, e três workers especializados processam as etapas; só o worker de publicação fala com a Meta.

&#91;embedded content: arquitetura · 7 componentes, 3 serviços externos\]

Todos os workers atualizam o status do post no banco a cada etapa, o que alimenta o painel e a auditoria.

| Camada | Tecnologia sugerida |
| --- | --- |
| Painel e API | PHP (Laravel), mesma stack dos sistemas da Calutec |
| Banco | PostgreSQL (JSONB para os campos JSON); MySQL também atende |
| Fila | Redis (Laravel Queues; BullMQ no lado Node) |
| Renderização | Node.js com Playwright (renderização), Python com rembg e Pillow (pré-processamento) |
| Storage | Cloudflare R2 (sem custo de saída) ou AWS S3 |
| Hospedagem | VPS atual com Docker, um container por tipo de worker |

## Modelo de dados multi-tenant

Toda tabela de negócio carrega `tenant_id`, e toda consulta filtra por ele, no mesmo padrão do sistema da JA.

| Tabela | Campos principais | Observação |
| --- | --- | --- |
| `tenants` | id, nome, plano, status, timezone | Um registro por cliente |
| `brand_profiles` | tenant\_id, segmento, logo\_url, cores (JSON), fontes, tom\_de\_voz, publico\_alvo, palavras\_proibidas, estilo\_padrao\_id | Identidade usada nos prompts |
| `instagram_accounts` | tenant\_id, ig\_user\_id, username, access\_token (criptografado), token\_expires\_at, status | Um tenant pode ter mais de uma conta |
| `catalog_items` | tenant\_id, tipo (produto, evento, promoção), nome, descricao, preco, imagem\_url, inicio, fim, ativo | Catálogo próprio de cada cliente |
| `content_sources` | tenant\_id, tipo, origem (CSV, API externa), config (JSON), ultima\_sincronizacao | Reservada para importações e integrações futuras |
| `style_presets` | tenant\_id (nulo = global), nome, descricao\_prompt, paleta (JSON), fontes, elementos, exemplos\_html (JSON), ativo | Estilos visuais usados como guia pelo Claude |
| `assets` | tenant\_id, origem (upload, catálogo), tipo (pessoa, produto, logo, flyer, documento), url\_original, url\_processada, descricao | Arquivos já processados (sem fundo, logos convertidos) |
| `schedules` | tenant\_id, account\_id, dias\_semana, horario, formato (feed, carrossel, stories), objetivo, estilo\_id, publicar\_sem\_revisao (padrão: não) | Agenda do modo automático |
| `ideas` | tenant\_id, account\_id, modo (livre, guiado), data\_publicacao, horario, formatos (JSON), objetivo, estilo\_id, descricao, campos\_extras (JSON), sequencia (JSON), status | Pedidos do cliente |
| `idea_assets` | idea\_id, asset\_id, ordem, uso (principal, fundo, logo, referência) | Arquivos anexados à ideia |
| `posts` | tenant\_id, account\_id, schedule\_id, idea\_id, formato, status, versao\_aprovada\_id, agendado\_para, ig\_container\_id, ig\_media\_id, publicado\_em, erro | Ciclo de vida de cada post |
| `post_versions` | post\_id, versao, saida\_claude (JSON), legenda, media\_urls, comentario, criado\_em | Cada versão gerada; o preview mostra a mais recente |
| `post_events` | post\_id, status\_anterior, status\_novo, detalhe, criado\_em | Auditoria e depuração |
| `usage_log` | tenant\_id, post\_id, tokens\_entrada, tokens\_saida, custo\_usd | Controle de custo por cliente |

Tokens de acesso ficam criptografados em repouso (por exemplo, AES-256 com a chave fora do banco).

## Fluxo diário

O conteúdo é gerado com antecedência (sugestão: 12 h antes do horário de publicação), para dar tempo de aprovar e de refazer em caso de erro.

1. O agendador (cron a cada 5 min) verifica primeiro se há ideia cadastrada para o horário; se não houver, busca os `schedules` cujo próximo post ainda não foi gerado e cria o registro em `posts` com status `agendado`.
2. O worker de conteúdo monta o contexto (marca, dados do dia, últimos 30 posts para evitar repetição) e chama o Claude, que devolve o roteiro em JSON.
3. O worker de renderização preenche o template, gera o JPEG ou MP4 e envia ao storage, que devolve uma URL pública.
4. O preview é gerado e o post aguarda aprovação no painel (ver Preview e aprovação); só segue direto se a agenda estiver liberada para publicar sem revisão.
5. No horário marcado, o worker de publicação cria o container no Instagram, aguarda o processamento (vídeos) e chama o `media_publish`.
6. O resultado (`ig_media_id` ou erro) é gravado e registrado em `post_events`.

| Status | Significado |
| --- | --- |
| `agendado` | Registro criado (automático ou ideia), aguardando geração |
| `gerando` | Claude e renderização em andamento |
| `aguardando_aprovacao` | Preview pronto, esperando o cliente aprovar |
| `aprovado` | Liberado para publicar no horário |
| `publicando` | Container criado, aguardando processamento |
| `publicado` | Post no ar, com `ig_media_id` gravado |
| `rejeitado` | Cliente recusou; o horário fica vago ou volta ao automático |
| `expirado` | Não aprovado até 1 h antes do horário; não publicado |
| `erro` | Falha após as tentativas; notificar o responsável |

Cada etapa tenta de novo até 3 vezes com espera crescente antes de ir para `erro`.

## Posts a partir de uma ideia

Além do modo automático, o cliente pode cadastrar uma ideia para uma data, com texto e imagens. O Claude cria o post a partir dela, e nesse horário a geração automática não roda.

1. No painel, a tela "Nova ideia" recebe data e horário, formato (feed, carrossel ou Reels), descrição livre (por exemplo: "Show do cantor X no sábado, 22h, ingresso R$ 40, clima de festa"), imagens (foto do artista, flyer, foto do local) e campos opcionais (texto obrigatório, CTA, link).
2. O sistema cria o post com status `agendado`, vinculado à ideia por `idea_id`. Se já existir um post automático no mesmo horário, a ideia o substitui.
3. O worker de conteúdo envia a ideia e as imagens ao Claude. Ele lê as imagens, extrai o que estiver escrito nelas (nome do artista, data, local) e escolhe o template e os campos; as imagens do cliente entram na arte como foto principal ou fundo.
4. Posts de ideia passam pelo preview com aprovação obrigatória (ver Preview e aprovação), com o botão "Gerar outra versão" e um campo de comentário (por exemplo: "mais destaque no preço").
5. No horário marcado, o post é publicado como os demais.

Regras:

- No mesmo horário, a ideia tem prioridade sobre o automático; uma ideia fora dos horários da agenda vira post avulso.
- O calendário do painel mostra lado a lado os horários automáticos e as ideias cadastradas.
- Uma ideia pode gerar uma sequência de posts em datas diferentes (por exemplo, anúncio do show, "faltam 2 dias" e "é hoje").
- Imagens aceitas: JPEG ou PNG de até 10 MB. O cliente confirma que tem direito de uso das fotos (artista, flyer).
- Cada imagem enviada ao Claude consome cerca de 1.600 tokens de entrada; o custo por post continua na casa de centavos.

## Preview e aprovação

Todo post gera um preview fiel ao Instagram antes de ser agendado; nada é publicado sem aprovação, a não ser que o cliente libere o modo automático sem revisão.

1. Assim que a arte é renderizada, o painel monta o preview no formato do feed: foto de perfil e @ da conta, imagem (ou carrossel navegável, ou Reels tocando), legenda com o "mais" e as hashtags.
2. O cliente recebe um aviso (e-mail e, depois, WhatsApp) com link direto para o preview, que funciona no celular.
3. Na tela de preview, as ações são:
   - **Aprovar e agendar:** confirma o horário (ou ajusta) e o post vai para `aprovado`.
   - **Editar legenda:** muda o texto direto, sem gerar a arte de novo.
   - **Gerar outra versão:** com comentário opcional ("troca a cor do fundo"); a versão anterior fica no histórico para comparação.
   - **Trocar imagem:** sobe outra foto e a arte é refeita.
   - **Rejeitar:** o horário fica vago ou volta para o automático.
4. Se o post não for aprovado até 1 hora antes do horário, ele não é publicado e o cliente é avisado. Nunca se publica algo que não foi visto.

Regras:

- Padrão para clientes novos e para posts de ideia: aprovação obrigatória. O cliente pode liberar "publicar automático sem revisão" por agenda, depois de confiar nos resultados.
- O preview é o arquivo final (o mesmo JPEG ou MP4 que vai ao Instagram), não uma simulação.
- Cada versão gerada fica salva em `post_versions` (post\_id, versao, roteiro, media\_urls, comentario, criado\_em), e o post aponta para a versão aprovada.
- O link do preview é assinado e expira em 7 dias, para poder ser compartilhado com quem aprova sem precisar de login.

## Geração de conteúdo com o Claude

O Claude recebe o pedido, os arquivos e o perfil da marca e devolve o texto do post e o layout da arte em HTML; o servidor só transforma esse HTML em imagem.

Entradas do prompt:

- **System prompt fixo (cacheado):** papel de designer e social media, regras de layout (tamanhos, área segura, fontes disponíveis, contraste mínimo), formato de saída e proibições.
- **Bloco da marca (cacheado por tenant):** segmento, tom de voz, cores, fontes, logo e palavras proibidas.
- **Bloco do estilo:** preset escolhido, ou o padrão da marca, com os exemplos HTML de referência.
- **Bloco do pedido:** modo, objetivo, texto do cliente, itens do catálogo, data e oferta.
- **Arquivos:** imagens já processadas (o Claude vê as fotos e lê o texto de flyers) e PDFs (cardápio, programação).

Pré-processamento dos arquivos, antes de chamar o Claude:

- Fotos de pessoas e produtos: remoção de fundo com rembg (modelo `u2net_human_seg` para pessoas), recorte e redução para no máximo 1080 px.
- Logos: detecção de fundo claro ou escuro e geração das versões branca e preta com fundo transparente.
- Cada arquivo processado recebe um id e uma descrição curta; o HTML referencia os arquivos por id (`{{asset:gcruz_recorte}}`).

Saída esperada:

```json
{
  "formato": "feed",
  "objetivo": "evento",
  "estilo": "terror",
  "slides": [
    { "html": "<div class='post'>...</div>", "css": "..." }
  ],
  "legenda": "...",
  "hashtags": ["#halloween", "#tatuquara"],
  "alt_text": "...",
  "assets_usados": ["gcruz_recorte", "dj_recorte", "logo_ja_branco"]
}
```

Carrossel = vários itens em `slides`; Stories = um slide de 1080×1920.

Regras de validação no backend:

- O HTML é sanitizado: sem scripts, sem URLs externas; só fontes da biblioteca local e assets por id.
- Render de teste: o worker confere se algum texto saiu do quadro e se o contraste é suficiente; se falhar, devolve o problema ao Claude para corrigir (no máximo 2 tentativas).
- Preços, datas e horários são conferidos contra o pedido e o catálogo; o Claude não inventa informação que não recebeu.
- Legenda até 2.200 caracteres e no máximo 30 hashtags.

Modelo: Claude Sonnet 5, que lê imagens e escreve HTML bem.

## Renderização de mídia

O worker recebe o HTML do Claude, injeta as fontes e os assets locais e gera o JPEG num navegador headless (Playwright). O custo por imagem é zero.

**Imagens (feed e carrossel)**

- Tamanhos: feed 1080×1350 e Stories 1080×1920; carrossel = um render por slide (2 a 10).
- Saída em JPEG com qualidade 90 e até 8 MB (a API só aceita JPEG).
- Biblioteca de fontes local, instalada via `@fontsource` (Anton, Oswald, Montserrat, Bebas Neue, Creepster e outras); o Claude só usa fontes da lista.
- Efeitos nas fotos (tonalidade, brilho em volta, desfoque na base) são aplicados no pré-processamento com Pillow, e não com filtros CSS, que criam bordas retangulares em volta dos recortes.

**Armazenamento**

- Upload para um bucket compatível com S3 (Cloudflare R2 ou AWS S3) com URL pública, porque o Instagram baixa a mídia dessa URL.
- Arquivos expiram depois de 30 dias, depois que o post já foi publicado.

**IA de imagem (plano premium, fase posterior)**

- Campo opcional `prompt_imagem` no roteiro, enviado a um provedor de imagem (OpenAI, Google ou Flux) e usado como fundo do template.
- Custo por imagem registrado no `usage_log`.

## Integração com a API do Instagram

A integração usa a Instagram API com Business Login for Instagram, que dispensa página do Facebook e usa as permissões `instagram_business_basic` e `instagram_business_content_publish`.

**Conexão da conta (OAuth)**

1. O cliente clica em "Conectar Instagram" no painel e é levado à tela de consentimento da Meta.
2. O callback troca o código por um token de curta duração e, em seguida, por um token de longa duração.
3. O sistema grava o token criptografado, o `ig_user_id` e a data de expiração.
4. Um job diário renova os tokens que vencem em menos de 10 dias; uma falha na renovação marca a conta como `reconectar` e avisa o cliente.

**Publicação**

1. Criar o container: `POST /<IG_ID>/media` com `image_url` (ou `video_url` + `media_type=REELS`) e `caption`. Carrossel: um container por item com `is_carousel_item=true`, depois um container `CAROUSEL` com os `children`.
2. Vídeo: consultar `status_code` do container até `FINISHED` (a cada 10 s, com timeout de 5 min).
3. Publicar: `POST /<IG_ID>/media_publish` com `creation_id`.
4. Antes de publicar, consultar `GET /<IG_ID>/content_publishing_limit` (limite atual de 100 posts por 24 h por conta).

Stories seguem o mesmo fluxo, com `media_type=STORIES` e imagem 1080×1920. Reels e vídeo ficam para a fase futura.

**Erros a tratar**

| Situação | Ação |
| --- | --- |
| Token expirado ou revogado | Status `erro`, conta em `reconectar`, aviso ao cliente |
| Limite de publicação atingido | Reagendar para a próxima janela |
| Mídia rejeitada (formato ou tamanho) | Registrar o detalhe e re-renderizar |
| Throttling da Graph API | Tentar de novo com espera crescente |
| Timeout no processamento de vídeo | Até 3 tentativas, depois `erro` |

## Custos estimados

Com templates em código, o custo variável fica em cerca de US$ 0,03 a 0,06 por post. A API do Instagram não cobra pela publicação.

| Item | Base de cálculo | Custo estimado |
| --- | --- | --- |
| API da Meta (Instagram) | Gratuita | US$ 0 |
| Claude Sonnet 5 (roteiro) | \~8 mil tokens de entrada (com imagens) + \~4 mil de saída (HTML), a US$ 2 / US$ 10 por milhão | \~US$ 0,03 a 0,06 por post |
| Renderização e pré-processamento (Playwright, rembg, Pillow) | No VPS existente | US$ 0 adicional |
| Storage (R2/S3) | Arquivos pequenos, expiram em 30 dias | Centavos por mês |
| IA de imagem (opcional) | Depende do provedor | Cobrado por imagem; repassar no plano premium |

Cenários com um post por dia: 1 cliente ≈ US$ 1 a 2/mês; 50 clientes ≈ US$ 50 a 90/mês. Prompt caching do bloco da marca reduz o custo de entrada. Valores de referência de outubro de 2026; confirmar na página de preços da Anthropic antes de precificar o produto.

## Checklist do App Review (fase 2)

O Advanced Access exige Business Verification e App Review aprovados; a análise leva de 2 a 4 semanas.

- [ ] Portfólio de negócios da Calutec criado no Meta Business, com CNPJ, domínio e telefone comercial
- [ ] Business Verification concluída
- [ ] App conectado ao portfólio verificado e em modo Live
- [ ] Política de privacidade publicada, tratando especificamente dos dados do Instagram (o que é coletado, para quê, por quanto tempo)
- [ ] Termos de uso publicados
- [ ] Fluxo de exclusão de dados documentado (URL de callback de exclusão + página de instruções)
- [ ] Usuário de teste com conta Instagram profissional para os revisores
- [ ] Screencast mostrando: login, tela de consentimento com as permissões, geração do post e o post publicado aberto no Instagram
- [ ] Descrição de uso de cada permissão solicitada (`instagram_business_basic`, `instagram_business_content_publish`)

Motivos comuns de reprovação: pedir acesso antes da verificação, screencast sem a tela de consentimento e fluxo que o revisor não consegue reproduzir.

## Telas e endpoints

O painel tem dez telas, servidas por uma API REST em `/api/v1` com autenticação por tenant.

| Tela | Função |
| --- | --- |
| Marca | Logo, cores, fontes, tom de voz, palavras proibidas |
| Contas Instagram | Conectar, ver status do token, reconectar |
| Catálogo | Produtos, eventos e promoções com preço, foto e validade; importação por CSV |
| Agendas | Dias, horários, formato e opção de publicar sem revisão |
| Calendário | Visão semanal e mensal dos horários automáticos e das ideias |
| Nova ideia | Modo livre (comando e anexos) ou guiado (objetivo, estilo, formato); data, horário e sequência opcional |
| Preview | Mockup fiel do post, versões anteriores e ações de aprovação |
| Histórico | Posts publicados com link para o Instagram e erros |
| Templates | Lista de templates disponíveis por formato |
| Uso e custos | Tokens e custo por cliente (apenas admin) |

| Método | Rota | Descrição |
| --- | --- | --- |
| GET / PUT | `/brand-profile` | Lê e atualiza a identidade da marca |
| GET | `/instagram/connect` | Redireciona para o OAuth da Meta |
| GET | `/instagram/callback` | Recebe o código e grava o token longo |
| GET / POST / PUT / DELETE | `/catalog` | CRUD de produtos, eventos e promoções |
| POST | `/catalog/import` | Importação de catálogo por CSV |
| GET / POST / PUT / DELETE | `/schedules` | CRUD das agendas |
| GET / POST | `/ideas` | Lista e cria ideias |
| POST | `/ideas/{id}/assets` | Upload de imagens (multipart) |
| GET | `/calendar?from=&to=` | Horários automáticos e ideias no período |
| GET | `/posts?status=` | Lista posts por status |
| GET | `/posts/{id}` | Detalhe com versões |
| GET | `/preview/{token}` | Preview público com link assinado (7 dias) |
| POST | `/posts/{id}/approve` | Aprova; aceita novo horário opcional |
| POST | `/posts/{id}/regenerate` | Gera nova versão com comentário |
| PATCH | `/posts/{id}/caption` | Edita a legenda sem refazer a arte |
| POST | `/posts/{id}/replace-image` | Troca a imagem e refaz a arte |
| POST | `/posts/{id}/reject` | Rejeita o post |
| POST | `/webhooks/meta/data-deletion` | Callback de exclusão de dados exigido pela Meta |

## Roadmap de implementação

Nove etapas, cada uma entregável e testável isoladamente. O primeiro post real sai na etapa 5.

| Etapa | Entrega | Critério de aceite |
| --- | --- | --- |
| 1. Fundação | Laravel + PostgreSQL, migrations de todas as tabelas, criptografia dos tokens, Redis e workers em Docker | Migrations rodam do zero; um job de teste é processado |
| 2. Conexão Instagram | App Meta em modo de desenvolvimento, OAuth, token longo e job de renovação | Conta da Calutec conectada; renovação forçada funciona |
| 3. Publicação manual | Storage, container, `media_publish` para feed, carrossel e Stories, checagem de limite | Um JPEG fixo publicado nos três formatos via comando |
| 4. Renderização e assets | Worker Node com Playwright, biblioteca de fontes, pré-processamento (rembg, logos, efeitos com Pillow), sanitização do HTML | Um HTML de exemplo vira JPEG em 1080×1350 e 1080×1920; foto de pessoa sai sem fundo |
| 5. Geração com Claude | Prompts com caching, presets de estilo, envio de imagens e PDFs, saída JSON com HTML, render de teste com correção, `usage_log` | Reproduzir o flyer de Halloween a partir das 4 imagens e do comando; 10 gerações seguidas válidas |
| 6. Preview e aprovação | Tela de preview, `post_versions`, ações, link assinado, aviso por e-mail, regra de 1 h | Post gerado, nova versão pedida, aprovado e publicado; post sem aprovação vira `expirado` |
| 7. Agendas, ideias e modos | Agendas, cron, máquina de estados, tela Nova ideia nos modos livre e guiado, calendário, sequência | Ideia livre e ideia guiada geram posts; o automático segue nos outros dias |
| 8. Catálogo próprio | Tela de catálogo e importação por CSV | Uma semana rodando nas contas da Calutec e da JA usando só o catálogo |
| 9. Produto | Planos, onboarding, gestão de presets pelo admin, preparação do App Review | Cliente novo cadastrado sem ajuda; checklist do App Review completo |

Cada etapa vira uma tarefa separada para o Codex (seção Tarefas para o Codex), com contexto só das seções que ela usa. Isso economiza tokens, reduz erro e permite revisar uma etapa antes de começar a próxima. Reels (vídeo) fica fora destas nove etapas.

## Tarefas para o Codex

Mande ao Codex o bloco de contexto geral uma vez e depois uma etapa por vez, colando junto só as seções citadas na etapa. Só avance quando o critério de aceite da etapa passar.

### Contexto geral (enviar uma vez)

```text
Vamos construir um SaaS multi-tenant que gera e publica posts no Instagram (feed, carrossel e Stories).
Stack: Laravel 11 + PostgreSQL + Redis (filas); worker Node.js com Playwright para renderizar HTML em JPEG;
worker Python com rembg e Pillow para pré-processar imagens; storage S3/R2; IA de texto e layout: API do Claude.
Regras: toda tabela de negócio tem tenant_id e toda consulta filtra por ele; tokens criptografados;
nada de vídeo nesta versão. Trabalharemos em etapas; faça só a etapa pedida, com testes, e me mostre
o que mudou e como rodar. A especificação completa está nas seções que vou colar em cada etapa.
```

### Etapa 1 – Fundação

```text
Seções: Arquitetura e stack, Modelo de dados multi-tenant.
Crie o projeto Laravel com PostgreSQL, as migrations e models de todas as tabelas do modelo de dados,
criptografia do access_token, Redis com filas (content, render, publish), docker-compose com app, worker
Node e worker Python. Aceite: migrations rodam do zero e um job de teste passa pelas três filas.
```

### Etapa 2 – Conexão Instagram

```text
Seções: Integração com a API do Instagram (Conexão da conta).
Implemente o OAuth com Business Login for Instagram (/instagram/connect e /instagram/callback), troca para
token longo, gravação criptografada e job diário de renovação (vence em < 10 dias). Conta que falha vira
status 'reconectar'. Aceite: conta da Calutec conectada e renovação forçada por comando funcionando.
```

### Etapa 3 – Publicação manual

```text
Seções: Integração com a API do Instagram (Publicação, Erros a tratar).
Crie o serviço de publicação: upload ao storage com URL pública, container, media_publish para feed,
carrossel (children) e Stories (media_type=STORIES), checagem de content_publishing_limit e tratamento
dos erros da tabela. Aceite: comando artisan publica um JPEG fixo nos três formatos.
```

### Etapa 4 – Renderização e assets

```text
Seções: Renderização de mídia, Geração de conteúdo com o Claude (Pré-processamento).
Worker Python: remoção de fundo com rembg (u2net_human_seg), conversão de logos para branco/preto com fundo
transparente, efeitos com Pillow, grava em assets. Worker Node: recebe HTML + CSS, sanitiza (sem script,
sem URL externa), resolve {{asset:id}} e fontes locais (@fontsource), renderiza com Playwright em
1080x1350 ou 1080x1920 e salva JPEG q90. Aceite: HTML de exemplo vira JPEG nos dois tamanhos e uma foto
de pessoa sai sem fundo.
```

### Etapa 5 – Geração com o Claude

```text
Seções: Formatos e modos de entrada, Geração de conteúdo com o Claude.
Monte o prompt em blocos com prompt caching (fixo, marca, estilo, pedido, arquivos), envie imagens e PDFs,
valide a saída JSON por schema, renderize, verifique overflow e contraste e devolva o erro ao Claude para
corrigir (máx. 2 vezes). Registre tokens e custo em usage_log. Crie 5 style_presets iniciais (marca, festa,
terror, premium, minimalista). Aceite: com 4 imagens (2 artistas e 2 logos) e o comando do flyer de
Halloween, gera um post de feed coerente; 10 gerações seguidas válidas.
```

### Etapa 6 – Preview e aprovação

```text
Seções: Preview e aprovação, Telas e endpoints (Preview e rotas /posts).
Tela de preview fiel ao Instagram (feed, carrossel navegável, Stories), post_versions, ações aprovar,
editar legenda, gerar outra versão com comentário, trocar imagem e rejeitar, link assinado de 7 dias,
aviso por e-mail e regra de expirar 1 h antes. Aceite: fluxo completo até publicar; post sem aprovação
vira 'expirado'.
```

### Etapa 7 – Agendas, ideias e modos

```text
Seções: Fluxo diário, Posts a partir de uma ideia, Formatos e modos de entrada, Telas e endpoints.
Agendas e cron (5 min), máquina de estados dos posts, tela Nova ideia nos modos livre (comando + anexos)
e guiado (objetivo, estilo, formato, itens do catálogo), calendário e sequência de posts por ideia.
Aceite: uma ideia livre e uma guiada geram posts, e o automático segue nos outros dias.
```

### Etapa 8 – Catálogo próprio

```text
Seções: Modelo de dados (catalog_items), Telas e endpoints (Catálogo).
CRUD de produtos, eventos e promoções com foto, preço e validade, importação por CSV e uso do catálogo
no modo automático. Aceite: uma semana rodando nas contas da Calutec e da JA usando só o catálogo.
```

### Etapa 9 – Produto

```text
Seções: Fases do projeto, Checklist do App Review, Custos estimados.
Planos e limites por plano, onboarding do cliente (marca, conta, primeira agenda), tela de admin para
criar e editar style_presets, política de privacidade, termos e callback de exclusão de dados.
Aceite: cliente novo se cadastra sem ajuda e o checklist do App Review está completo.
```
