# Análise técnica — MAPA

Revisão de código e arquitetura (PHP 8 + Python 3 + SQLite, ~25,6 mil linhas).
Escopo: `src/`, `python/`, `scripts/`, `config/schema.sql`, `index.php`, docs de deploy.

Graus de criticidade:

| Grau | Significado |
|---|---|
| **CRÍTICO** | Exposição de dados pessoais ou tomada de conta; corrigir antes do próximo deploy |
| **ALTO** | Perda de dados, indisponibilidade ou risco real em produção |
| **MÉDIO** | Degradação, dívida técnica que já custa tempo, ou risco condicionado |
| **BAIXO** | Polimento, robustez, consistência |

---

## Pontos fortes (para não regredir)

- **SQL parametrizado de ponta a ponta.** `AnalyticsRepository`, `UserRepository` e afins usam `prepare`/`bindValue` inclusive nos filtros dinâmicos `IN (...)` (`appendCursoFilter`, `bindNamedParams`). Não há injeção de SQL.
- **Escape de saída consistente.** As views usam `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` de forma disciplinada — não encontrei XSS.
- **`escapeshellarg` correto** ao disparar o Python pelo portal (`PasseLivreController::gerar`).
- **Código de verificação de atestado forte** (`random_bytes(8)` → 64 bits, não enumerável).
- **Algoritmo de alarmes bem desenhado**: `gerar_alarmes.py` carrega faltas/aulas em memória em poucas consultas e processa em dicionários — sem N+1. `trazer_alarmes_tratados` preserva o estado de tratamento entre coletas.
- **Segredos migrados do `.env` para a tabela `configuracoes`**, com trava de ambiente (`EMAIL_SEND`) independente do interruptor do banco.

---

## CRÍTICO

### C1. DocumentRoot na raiz do repositório expõe `.env`, o banco e o `.git/`
`docs/servidor-apache.md:41-53` documenta `DocumentRoot /var/www/mapa` com `Require all granted` e nenhuma regra de bloqueio (e o projeto proíbe `.htaccess`).

Em produção, ficam publicamente baixáveis:
- `/.env` → senha SMTP, bind LDAP, `DB_PATH`
- `/data/mapa.db` → **banco inteiro**: dados pessoais de todos os alunos, hashes de senha, e a tabela `configuracoes` com `api_client_secret` e `ldap_bind_password`
- `/data/json/resposta_alunos_massa_cadastro.json` (e demais JSON da coleta), `/data/*.log`, `/config/schema.sql`
- `/.git/` → código-fonte e histórico completos

Impacto: vazamento total de base de dados pessoais (incidente de LGPD com dever de notificação) + credenciais da API institucional.

**Correção:** mover o front controller para `public/` e apontar o `DocumentRoot` para lá (`public/index.php`, com `assets/` dentro). Enquanto isso não acontecer, bloquear explicitamente no VirtualHost:

```apache
<DirectoryMatch "^/var/www/mapa/(\.git|data|config|python|src|scripts|docs)">
    Require all denied
</DirectoryMatch>
<FilesMatch "^(\.env|\.env\.example|.*\.(db|sqlite|sql|log|md|py|json))$">
    Require all denied
</FilesMatch>
```

### C2. Scripts de e-mail em massa acessíveis pela web, sem autenticação
`scripts/enviar_emails_alarmes_alunos.php`, `enviar_emails_alarmes_staff.php` e `enviar_emails_chamadas.php` não têm guarda de SAPI nem autenticação, e estão dentro do DocumentRoot.

`GET https://<host>/scripts/enviar_emails_alarmes_alunos.php` — de qualquer pessoa na internet — dispara o envio em massa de e-mails a alunos em nome da instituição (em produção `EMAIL_SEND=true`, conforme `INSTALL.md`). Repetível à vontade: abuso reputacional, blacklist do SMTP institucional e, mesmo com envio desligado, **poluição da tabela `alarme_emails`**, que suprime os avisos legítimos pela janela de deduplicação.

**Correção:** primeira linha de cada script:

```php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
```

Isso é defesa em profundidade junto com C1 — aplicar as duas.

### C3. Ausência total de proteção CSRF
Nenhum token em nenhuma das 18 rotas POST (busca por `csrf`/`token`/`SameSite` no código: zero resultados). `Session::start()` (`src/Core/Session.php:9-16`) não define `SameSite`, `HttpOnly` nem `Secure`.

Um administrador autenticado que abra uma página maliciosa pode ter, sem clique adicional:
- criação de um novo usuário administrador (`POST /usuarios`)
- alteração do host/segredo da API ou do SMTP (`POST /configuracoes/api`, `/email`)
- exclusão de usuários (`POST /usuarios/excluir`)
- assinatura de atestados de passe livre (`POST /passe-livre/assinar`) — documento com valor legal

**Correção:** token por sessão emitido no `Controller::render` e validado com `hash_equals` em todo POST (um helper `requirePost()` no `Controller` resolve para todas as rotas de uma vez), e no `Session::start`:

```php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
```

### C4. `/setup` permite tomada da conta de administrador sem autenticação
`Router::dispatch` (`src/Core/Router.php:31-39`) redireciona **qualquer visitante** para `/setup` sempre que existir um admin local ativo sem `senha_hash`, e `AuthController::setupForm`/`setupSave` (`src/Controllers/AuthController.php:13-72`) não exigem autenticação: quem chega primeiro define a senha do admin e já entra logado.

Não é só a janela de instalação. `UserRepository::update` (`src/Models/UserRepository.php:140-185`) reabre o buraco em operação normal: editar um admin para `auth_type = ldap` zera `senha_hash = NULL` (linha 151); editá-lo de volta para `local` sem informar senha cai no `else` (linhas 171-184), que **não** grava senha. Resultado: `findAdminPendenteSenha()` volta a casar, o site inteiro passa a redirecionar para `/setup` e qualquer anônimo assume a conta.

**Correção:** amarrar o `/setup` a um segredo fora da web — token de uso único gravado em `data/setup_token` pelo instalador e exigido no formulário —, ou restringir a rota a `127.0.0.1`, ou movê-la para um comando CLI. Em paralelo, impedir em `update()` que um admin `local` fique sem hash (validar no controller e no repositório).

### C5. Validação de certificado TLS desligada por padrão na API SIGAA
`api_verify_ssl` tem default `'false'` (`src/Core/Database.php:199` e `python/api_auth.py:52`), e com isso `obter_access_token`/`ssl_context` usam `ssl._create_unverified_context()` (`python/api_auth.py:89,108-112`).

Sem verificação de certificado, quem estiver no caminho de rede (ou capaz de envenenar DNS) captura `client_id` + `client_secret` do OAuth e todo o tráfego de dados de alunos, e pode devolver respostas forjadas que alimentam alarmes e atestados.

**Correção:** default `true`; manter o desligamento apenas como exceção explícita e documentada para homologação. Se o certificado institucional é interno, apontar a CA (`ssl.create_default_context(cafile=...)`) em vez de desabilitar a verificação.

---

## ALTO

### A1. Host header injection persistido → links envenenados em e-mails e PDFs assinados
`Router::dispatch` chama `ConfigRepository::lembrarAppUrlDoPedido()` (`src/Models/ConfigRepository.php:735-747`) em **toda requisição, inclusive anônima**, gravando o valor de `Url::detectPublicBase()` (`src/Core/Url.php:53-77`), que confia em `HTTP_X_FORWARDED_HOST` / `HTTP_HOST` sem lista branca.

Uma única requisição `curl -H 'X-Forwarded-Host: dominio-falso' https://<host>/index.php/login` reescreve `configuracoes.app_url`. A partir daí, esse domínio aparece:
- no link "entre em ..." dos e-mails automáticos a alunos e staff (`AlarmeEmailService`)
- no **link de conferência impresso no atestado assinado** (`PasseLivreController::urlConferenciaBase`)

Ou seja: phishing com a marca da instituição, e documentos oficiais com URL de verificação de terceiros.

**Correção:** `app_url` passa a ser configuração administrativa (ou `APP_URL` no `.env`) e nunca é aprendida do pedido; se a detecção automática for mantida, validar o host contra uma lista branca e só aceitar `X-Forwarded-*` de proxy confiável.

### A2. O schema inteiro é reaplicado a cada requisição HTTP
`Database::connection()` chama `migrate()` sempre (`src/Core/Database.php:41-107`). Por requisição isso executa: todo o `config/schema.sql` (~40 `CREATE TABLE/INDEX`), ~20 `PRAGMA table_info` + `ALTER TABLE` condicionais, 4 leituras de `sqlite_master`, e vários `SELECT`/`INSERT` de seed de configuração.

Custo: dezenas de statements e **transações de escrita** antes de qualquer página renderizar — inclusive em telas somente-leitura. É o maior gargalo do portal e o principal gerador de contenção de lock (ver A3).

**Correção:** tabela `schema_migrations` com versão; `migrate()` só roda o que falta e sai por um único `SELECT` quando já está atualizado. Idealmente, migração como comando explícito de deploy, não efeito colateral de request.

### A3. SQLite sem WAL e sem `busy_timeout` → "database is locked"
Nem o PHP (`src/Core/Database.php:28-39`) nem o Python (`python/db.py:74-78`) configuram `journal_mode=WAL`, `busy_timeout` ou `synchronous`. No modo rollback-journal padrão, **escritor bloqueia todos os leitores**, e o PDO sem `busy_timeout` falha na hora com `SQLITE_BUSY`.

Cenário real: o cron horário abre transações longas de importação enquanto usuários navegam — as páginas quebram com erro 500 (e, por A2, até o simples carregar de página é um escritor concorrendo com o pipeline).

**Correção:** nas duas pontas, na abertura da conexão:

```
PRAGMA journal_mode = WAL;
PRAGMA busy_timeout = 5000;
PRAGMA synchronous = NORMAL;
```

(o `docs/servidor-apache.md` já prevê permissão de escrita para `-wal`/`-shm`, então a infraestrutura está pronta).

### A4. Schema e migrações duplicados entre PHP e Python, e divergentes
`src/Core/Database.php:46-107` e `python/db.py:134-210` implementam **independentemente** a aplicação do schema e as migrações de coluna. O Python cobre um subconjunto: não tem a migração de `usuarios`, nem `alarmes.contato_tipo`, nem `passe_livre_atestados` nullable, nem os seeds de configuração.

O estado final do banco depende de **qual processo tocou nele primeiro**. Toda alteração de schema precisa ser escrita duas vezes, em duas linguagens, sem nada que garanta a paridade.

**Correção:** uma única fonte de verdade — arquivos de migração numerados em `config/migrations/` aplicados por um runner só (comando CLI PHP ou Python); a outra ponta apenas abre a conexão e, no máximo, valida a versão esperada.

### A5. Nenhuma política de retenção: crescimento ilimitado das tabelas de coleta
Cada execução cria uma coleta nova com um snapshot completo (`importar_frequencia.py`), e o cron sugerido é **horário** (`python/README.md:27`). Nada nunca apaga coleta antiga, e não há `VACUUM`.

Ordem de grandeza: 2.000 alunos × ~6 disciplinas = ~12 mil linhas/hora em `frequencia_disciplina`, ~290 mil/dia, ~8,6 milhões/mês — mais `faltas_dia`, `disciplina_ultima_aula`, `alarmes`. O arquivo SQLite vai a vários GB por ano; os relatórios e o `DELETE ... CASCADE` degradam junto.

**Correção:** definir retenção (ex.: manter todas as coletas dos últimos 30 dias + uma diária dos últimos 12 meses) e uma rotina de poda + `VACUUM` no fim do pipeline. Decidir também a retenção de `acessos_log` (ver M1), que tem implicação legal.

### A6. Pipeline sem trava: execuções concorrentes do cron
`executar_coleta.py` (`python/executar_coleta.py:52-97`) não usa lock. Uma coleta com 2.000+ requisições HTTP sequenciadas em 10 subprocessos pode passar de uma hora — e então o cron dispara a segunda instância sobre a primeira: duas coletas simultâneas, escrita concorrente no mesmo SQLite e estado parcial. O botão "Gerar passe livre" (`PasseLivreController::gerar`, `src/Controllers/PasseLivreController.php:96-160`) tem o mesmo problema: dois cliques = dois harvests completos concorrentes.

**Correção:** `flock` em `data/coleta.lock` (ou lockfile com PID + verificação de processo vivo) no início do pipeline e na rota de geração, saindo com mensagem clara se já houver execução ativa.

### A7. Falha no meio do pipeline deixa a coleta publicada e incompleta
Os passos são processos independentes; se `gerar_alarmes.py` falhar, a coleta já foi criada e `ultimaColeta()` passa a apontar para ela. O portal então exibe a coleta mais recente **sem alarmes**, indistinguível de "nenhum aluno em risco" — um falso negativo silencioso em um sistema de prevenção de evasão.

**Correção:** marcar a coleta como `completa` só no fim do pipeline (coluna de status) e fazer `ultimaColeta()` considerar apenas coletas completas.

### A8. Nenhum log de erro da aplicação; 18 `catch` silenciosos
Zero ocorrências de `error_log`, `set_exception_handler` ou `set_error_handler` no projeto. Em contrapartida, 18 blocos `catch` — vários totalmente vazios: `Router::dispatch` (linhas 26-30), `AccessLogRepository::registrar` (`src/Models/AccessLogRepository.php:91-93`), `PasseLivreController::assinar` (que converte qualquer falha em "Não foi possível assinar o documento" sem registrar a causa).

Sem trilha de erro, falha de envio de e-mail, erro de PDF ou `SQLITE_BUSY` são invisíveis: não há como diagnosticar um incidente depois do fato. Além disso, sem `set_exception_handler`, uma exceção não tratada depende do `display_errors` do servidor para não vazar caminhos e SQL ao usuário.

**Correção:** handler global de exceção/erro gravando em `data/app.log` (com página de erro genérica ao usuário), e trocar os `catch` vazios por `catch + log`.

### A9. Autorização congelada na sessão
`Auth::login` (`src/Core/Auth.php:41-58`) copia `perfil`, `curso_ids`, `disciplina_codigos` e `pode_assinar_passe_livre` para a sessão, e nada revalida contra o banco depois. Desativar um usuário, rebaixar seu perfil ou revogar a permissão de assinar **não tem efeito** enquanto a sessão dele estiver viva.

Também faltam: `session_regenerate_id(true)` no login (fixação de sessão), limite de tentativas no `AuthController::login` (força bruta livre), e `password_needs_rehash`. A senha mínima é de 6 caracteres (`AuthController:50,180`).

**Correção:** revalidar usuário/perfil no `requireAuth()` (uma consulta por request, ou cache curto), regenerar o ID de sessão no login, aplicar rate limit por usuário/IP e elevar o mínimo de senha.

### A10. Nenhum teste automatizado e nenhuma automação de qualidade
Não há `tests/`, `composer.json`, `requirements.txt`, nem configuração de CI. As regras que mais importam — cálculo de frequência, carência de alarme, sequência de faltas em dias úteis, numeração de atestado, expansão de `turno_turma` — são puramente algorítmicas e hoje só são validadas manualmente, em cima do banco de produção.

**Correção:** começar pelo núcleo determinístico (`turno_turma.py`, `sequencias_faltas_uteis`, `semanas_perdidas`, `ausencias_especiais.py`) com `pytest`, e PHPUnit para `AnalyticsRepository` sobre um SQLite em memória. Um workflow de CI rodando testes + `phpstan`/`ruff` fecha o ciclo.

---

## MÉDIO

### M1. Gravação em `acessos_log` a cada requisição autenticada
`Controller::requireAuth()` (`src/Core/Controller.php:44-56`) chama `registrarAcessoAtual()` em toda página: um `INSERT` (portanto uma transação com fsync, em journal rollback) por page view, guardando IP e user-agent **sem retenção definida**. Custo de I/O somado a A2/A3, e retenção indefinida de dado pessoal sem base documentada.

**Correção:** definir prazo de retenção com poda automática, e considerar não registrar `GET` de baixo valor (ou agregar).

### M2. Classes-Deus
`AnalyticsRepository` tem 2.073 linhas e 48 métodos cobrindo sete domínios distintos (dashboard, chamadas, alarmes, ingressantes, trancados, perda de vaga, passe livre). `AlarmeEmailService` tem 1.714 linhas. `ConfigRepository`, 818. Qualquer mudança em um relatório obriga a navegar um arquivo de 2 mil linhas, e o acoplamento impede teste isolado.

**Correção:** fatiar por domínio (`ChamadasRepository`, `AlarmeRepository`, `PasseLivreRepository`...) reaproveitando os helpers de filtro que já estão prontos (`appendCursoFilter`, `bindNamedParams`). Refatoração incremental, um domínio por PR.

### M3. Uma conexão SMTP por destinatário, sem throttle nem retry
`AlarmeEmailService:110,276,684` e `ChamadaEmailService:122` chamam `SmtpMailer::send()` dentro do laço, e cada `send()` abre socket + STARTTLS + AUTH + QUIT (`src/Lib/SmtpMailer.php:62-124`). Centenas de destinatários = centenas de handshakes sequenciais: rodada lenta e alvo típico de greylisting/rate limit institucional. Falha de envio não é retentada nem registrada em disco (ver A8).

**Correção:** reaproveitar uma conexão para o lote (`send` recebendo a lista de mensagens), com pausa configurável entre envios e retry com backoff em erro transitório (4xx).

### M4. Bootstrap via CDN externo, sem SRI, e sem cabeçalhos de segurança
`src/Views/layouts/main.php:7,192` carrega CSS e JS de `cdn.jsdelivr.net` sem atributo `integrity`. Um comprometimento do CDN (ou um MITM) injeta JavaScript arbitrário em sessão de administrador. Em rede institucional restrita, o portal ainda quebra visualmente se o CDN estiver bloqueado. Também não há CSP, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, HSTS, nem `Cache-Control: no-store` nas páginas com dados de aluno.

**Correção:** servir o Bootstrap localmente a partir de `assets/` (a pasta já existe) e adicionar os cabeçalhos no bootstrap da aplicação ou no VirtualHost.

### M5. O log da coleta é apagado no início de cada execução
`limpar_log_coleta()` (`python/executar_coleta.py:43-49`) trunca `data/coleta.log` a cada run. Uma falha às 3h desaparece às 4h — exatamente o log que seria necessário para diagnosticar (e, por A8, não há outro).

**Correção:** append com rotação por tamanho/data, mantendo alguns dias.

### M6. `php` invocado sem caminho absoluto no pipeline
`python/executar_coleta.py:87-91` usa `["php", str(script)]` (os passos Python usam `sys.executable`). Sob cron o `PATH` é mínimo: se o binário não estiver lá, `subprocess.run` lança `FileNotFoundError` **não tratado** — traceback e saída anormal depois de todos os dados já importados, e nenhum e-mail enviado. Também não há `timeout` em nenhum `subprocess.run`: um passo travado trava o pipeline indefinidamente.

**Correção:** resolver o binário com `shutil.which('php')` (ou configurá-lo), tratar a ausência com mensagem clara, e passar `timeout` a cada passo.

### M7. Código morto: ~800 linhas do fluxo de API antigo em PHP
`ApiModel`, `AlunosModel`, `MatriculadosModel`, `ConfigModel`, `Models/Aluno`, `Core/Debug` não são instanciados por nenhum controller — são resquícios de quando o PHP fazia as consultas à API (`ConfigModel` ainda lê um `API_TOKEN` que não existe mais). (`scripts/enviar_emails_alarmes.php`, legado de `enviar_emails_alarmes_alunos.php` + `_staff.php`, foi removido em 01/10/2026.)

**Correção:** remover. Código morto que fala com API e e-mail é superfície de ataque (ver C2) e confunde quem lê.

### M8. Índices que não servem, e os que faltam
`config/schema.sql:231-232`: `idx_freq_percentual(percentual_frequencia)` e `idx_alarmes_visualizado(visualizado)` têm seletividade baixíssima (o segundo tem dois valores possíveis) e nunca são usados sem `coleta_id` — ocupam espaço e custam em cada `INSERT` do pipeline. Faltam índices compostos para o padrão real de consulta: `frequencia_disciplina(coleta_id, curso_id)`, `alarmes(coleta_id, curso_id)`, `alarmes(aluno_id)`, `passe_livre_aluno_curso(periodo, curso_id)`.

**Correção:** trocar os dois índices por compostos com `coleta_id` à frente, conferindo com `EXPLAIN QUERY PLAN` nas consultas do dashboard.

### M9. Corrida na numeração do atestado
`PasseLivreAtestadoRepository::proximoNumero()` (`src/Models/PasseLivreAtestadoRepository.php:238-246`) faz `MAX(numero)+1` e depois insere, com `numero` UNIQUE. Duas assinaturas simultâneas → violação de unicidade → o segundo signatário recebe "Não foi possível assinar o documento" (sem log, por A8) em um documento com valor legal.

**Correção:** sequência dedicada (tabela contador com `UPDATE ... RETURNING`) ou retry no conflito de unicidade; a transação já existe, falta só a atomicidade da numeração.

### M10. `migrate()` divide o SQL por `;` ingenuamente, e caminhos relativos dependem do CWD
`src/Core/Database.php:58-77` faz `explode(';', ...)` após remover comentários — qualquer `;` dentro de string literal ou corpo de trigger futuro quebra a migração silenciosamente. E `$schemaPath = 'config/schema.sql'` (linha 48), `DB_PATH` default `'data/mapa.db'` (linha 20) e `Env::load()` com `.env` relativo assumem que o CWD é a raiz do projeto — o que só é verdade por acidente do Apache e do `chdir()` nos scripts.

**Correção:** caminhos ancorados em `dirname(__DIR__, 2)` (como já se faz em `PasseLivreController::gerar`); com migrações versionadas (A4), cada arquivo é executado inteiro, sem split manual.

### M11. Banco versionado em `python/data/mapa.db` e lacuna no `.gitignore`
Existe um `python/data/mapa.db` rastreado no Git (hoje com 0 byte). O `.gitignore` só cobre `/data/*` na raiz — `python/data/` não está ignorado. Basta uma configuração de `DB_PATH` relativa a esse diretório para um banco com dados de alunos entrar em um commit.

**Correção:** `git rm --cached python/data/mapa.db` e acrescentar `python/data/` ao `.gitignore`.

---

## BAIXO

- **B1.** ~~`montar_url_alunos` (`python/consulta_alunos.py:105-118`) interpola o login na query string sem `urllib.parse.quote`.~~ Script removido em 01/10/2026.
- **B2.** `ssl._create_unverified_context()` usa API privada do módulo `ssl`; o público é `ssl.create_default_context()` com flags explícitas.
- **B3.** `CONCORRENCIA = 50` fixo no código (`python/gerar_passe_livre.py`; antes também em `consulta_alunos.py`, removido): 50 requisições simultâneas contra o SIGAA sem backoff. Deveria ser configurável e mais conservador por padrão.
- **B4.** `SmtpMailer` manda `EHLO mapa.local` fixo (`src/Lib/SmtpMailer.php:77`), não define `Message-ID`, não abre o socket com contexto SSL explícito (sem `peer_name`), e não limita a linha a 998 caracteres com `Content-Transfer-Encoding: 8bit` — tudo isso pesa na entregabilidade.
- **B5.** `/logout` é `GET` (`src/routes.php:30`): logout forçado por CSRF. Incômodo, não grave.
- **B6.** Duplicação entre `PasseLivreController` e `FrequenciaAnualController`: `filtroNome()`, `cursoSelecionado()`, `resolverEscopo()` e `semestreSelecionado()` praticamente idênticos. Candidatos a um trait ou classe base.
- **B7.** Views grandes com lógica embutida (`passe_livre/index.php` com 665 linhas, `alarmes/index.php` com 624, `alarmes/index.php:425-448` montando rótulos). A formatação deveria descer para o controller ou um helper — hoje regra de apresentação está espalhada entre os dois.
- **B8.** `interpolação de constante em SQL` (`AlarmeEmailService:1415,1619`: `'-{$dias} days'`). Não é injeção (é `(int)` de uma constante), mas abre precedente para o padrão errado; usar parâmetro.
- **B9.** `docs/TODO.md` já registra a unificação professor↔usuário (e-mail duplicado entre `professores` e `usuarios`, sincronizado por CPF) — dívida de modelagem reconhecida e ainda aberta.

---

## Ordem sugerida

**Agora (uma janela de trabalho, risco de exposição ativo):**
C1 e C2 são mudanças de configuração e três linhas de código, e são o que mais reduz risco. C5 é trocar um default. Em seguida C3 e C4.

**Curto prazo (estabilidade):**
A3 (WAL/busy_timeout, ~4 linhas, resolve os erros de lock), A2 (versionar migração), A6 (lockfile), A8 (log de erro), A1.

**Médio prazo (sustentabilidade):**
A4 (migração única), A5 (retenção), A7 (coleta completa), A9, A10 (testes do núcleo), M1–M3, M8.

**Oportunístico:** M7 e M11 são deleção pura; M5, M6, M10 são pontuais. O grupo B entra junto de mudanças na mesma área.
