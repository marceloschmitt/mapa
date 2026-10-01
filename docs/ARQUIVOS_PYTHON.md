# Arquivos Python — MAPA

Descrição individual de cada script/módulo em `python/`. Para a ordem de
execução do pipeline e o fluxo de dados entre eles, veja o [README.md](README.md).
Quem gera e quem lê cada JSON de `data/json/`: [ARQUIVOS_JSON.md](ARQUIVOS_JSON.md).

---

## 1. Orquestração

### `executar_coleta.py`
Ponto de entrada único do pipeline de coleta. Roda os scripts na sequência
fixa (consulta em massa → consulta inicial → análise de frequência → imports →
alarmes → e-mails PHP), interrompendo a cadeia se um
passo falhar. Pensado para ser chamado por caminho absoluto (cron ou manual),
sem `cd` prévio.

---

## 2. Pipeline de coleta (ordem de execução)

### `consulta_alunos_massa.py`
Primeiro passo da coleta. Só consulta a API e grava as respostas cruas; não
filtra nada. Faz duas chamadas sem login, com os endereços completos
configurados na tela Configuração da API (nada de URL no código):

1. **URL alunos em massa — cadastro** (`api_url_alunos_massa_cadastro`; hoje
   `/sig/sigaa/alunos`, cerca de 2 segundos): cadastro de todos os alunos
   (e-mail, nome social, cursos com matrícula, turma de entrada, ingresso e
   `status_discente`). Grava `resposta_alunos_massa_cadastro.json`, lido por
   `importar_trancados.py` e `analisar_frequencia.py`.
2. **URL alunos em massa — frequência por intervalo**
   (`api_url_alunos_massa_intervalo`; hoje
   `/sig/sigaa/alunos/desempenho/frequencia/intervalo?data_inicial={data_inicial}&data_final={data_final}`).
   `{data_inicial}` e `{data_final}` são trocados pelas datas da configuração
   em `AAAA-MM-DD`. Cerca de 1 minuto; o Cloudflare do IFRS corta respostas
   acima de 60 s, o que limita o intervalo a uns 2 meses de aulas. Um registro por vínculo de
   todos os alunos da unidade, com `status_discente`, totais, disciplinas e
   `ausencias_especiais`. Grava `resposta_alunos_massa_intervalo.json`, lido por
   `analisar_frequencia.py` e `importar_chamadas.py`.

Cada arquivo só é substituído quando a sua chamada dá certo; em falha, o
anterior é mantido e a coleta é interrompida (a frequência não pode ser
importada com dados antigos).

### `consulta_inicial.py`
1ª consulta ao webservice SIGAA. Lê URL e credenciais OAuth da tabela
`configuracoes` (tela Configurações → API) e lista todos os alunos
matriculados (pode trazer vários status, não só `ATIVO`). Grava
`data/json/resposta_matriculas.json`.

Consulta também os matriculados dos `SEMESTRES_RETROATIVOS` semestres anteriores
(padrão: 2):

| Arquivo | Conteúdo | Usado por |
|---|---|---|
| `resposta_matriculas.json` | corrente | `importar_professores.py`, `importar_grade.py` |
| `resposta_matriculas_AAAA_S.json` | anteriores | cache de `gerar_perda_vaga.py` |

Os semestres anteriores são regravados a cada coleta para que a perda de vaga
use a situação das disciplinas mais recente sem precisar de `--forcar`. Falhas
neles não interrompem a coleta — o período corrente é o único crítico.

### `analisar_frequencia.py`
Lê os dois arquivos da consulta em massa e monta a tabela de frequência por
aluno/disciplina: frequência, disciplinas, trancadas e matrícula atrasada vêm de
`resposta_alunos_massa_intervalo.json`; nome civil, nome social, e-mail e turma
de entrada vêm de `resposta_alunos_massa_cadastro.json`. Considera apenas
vínculos `ATIVO` ou `FORMANDO` com curso (alunos especiais, sem curso, ficam de
fora). O percentual total do curso é arredondado para inteiro, como a consulta
individual devolvia. Gera `tabela_frequencia.json`.

Diferenças em relação à tabela que era montada a partir da consulta individual
por login (comparação de 01/10/2026): entram vínculos que a consulta individual
perdia (frequência ou cursos vazios, ex.: Meio Ambiente Subsequente e mestrados)
e as disciplinas aparecem como a API devolve, inclusive as que ainda não têm
chamada (frequência nula).

### `sincronizar_passe_livre_semestre_atual.py`
Espelha o semestre corrente (`api_periodo_letivo`) nas tabelas
`passe_livre_aluno_curso` / `passe_livre_disciplina`, a partir de
`tabela_frequencia.json`. Permite que o relatório de frequência anual enxergue
o semestre atual sem depender da geração manual de passe livre (que só cobre
semestres anteriores). Também é chamado automaticamente após
`analisar_frequencia.py` dentro do pipeline.

### `importar_frequencia.py`
Importa `tabela_frequencia.json` para o SQLite: cria uma nova coleta e popula
`alunos`, `frequencia_disciplina`, `faltas_dia`, entre outras. O JSON
permanece como cache daquela coleta.

### `importar_trancados.py`
Lê `resposta_alunos_massa_cadastro.json` (consulta em massa), seleciona os
cursos com `status_discente` `TRANCADO` / `TRANC. AUTOMÁTICO` e grava na tabela
`alunos_trancados` (última coleta).
Se o arquivo não existir, não grava nada. Alunos
trancados não entram nas contas de frequência nem alarmes; ficam disponíveis só
para consulta no portal (tela Trancados).

### `importar_professores.py`
A partir de `resposta_matriculas.json`, popula `cursos` (todos os cursos da
matrícula, não só os com frequência), `professores` e o vínculo
`disciplina_professores`.

### `importar_grade.py`
Lê `resposta_matriculas.json`, expande os códigos de `turno_turma` (via
`turno_turma.py`) em datas efetivas de aula (segunda a sábado) e popula
`disciplina_grade` + `disciplina_aulas`.

### `importar_chamadas.py`
A partir de `resposta_alunos_massa_intervalo.json` (consulta em massa), grava
por disciplina/curso a data da última aula ministrada (snapshot em
`disciplina_ultima_aula`) e acumula o histórico de datas distintas em
`disciplina_chamadas`. Vínculos sem curso (alunos especiais) ficam de fora.

### `gerar_alarmes.py`
Gera os alarmes de risco de evasão a partir dos dados já no SQLite. Aplica
duas regras — percentual de frequência abaixo do limite (após período de
carência) e sequência de faltas consecutivas dentro de uma janela de dias
úteis — cujos parâmetros (limites, janelas, textos) vêm de
`config_alarmes.py`/tabela `configuracoes` (tela Configurações → Alarmes),
com os valores antigos como padrão. Grava em `alarmes`.

Depois deste passo, `executar_coleta.py` ainda dispara três scripts PHP
(fora do escopo deste diretório): envio de e-mails de chamadas em atraso e
de alarmes para alunos/staff.

---

## 3. Módulos de apoio (usados pelos scripts acima, não são etapas do pipeline)

### `paths.py`
Centraliza os caminhos padronizados do projeto (`ROOT`, `data/json/`,
`config/`, `.env`, `schema.sql` etc.) e a função `garantir_diretorios()`.

### `db.py`
Conexão SQLite compartilhada por todos os scripts. Lê `DB_PATH` do `.env`
(padrão `data/mapa.db`), aplica `config/schema.sql` e expõe helpers
(`conectar`, `fechar`, `row_to_dict`).

### `api_auth.py`
Lê a configuração da API SIGAA (URL, credenciais, verificação SSL) gravada na
tabela `configuracoes` e obtém o access token OAuth usado pelas consultas.

### `config_consultas.py`
Lê as datas/período de referência da coleta, preferencialmente do banco e,
como fallback, de `config/consultas.json`.

### `config_alarmes.py`
Lê os parâmetros das regras de alarme configurados pelo administrador
(tabela `configuracoes`, prefixo `alarme_`, tela Configurações → Alarmes).
Sem nada gravado, usa os padrões que reproduzem o comportamento antigo
(frequência < 75%, 3 dias úteis seguidos, 3 semanas consecutivas).

### `status_aluno.py`
Normaliza e classifica o status do discente (`ATIVO`, `FORMANDO`,
`TRANCADO`, `TRANC. AUTOMÁTICO` etc.), usado pelo controle de
frequência/trancamento.

### `periodo_letivo.py`
Funções puras para período letivo `AAAA/S`: validação/normalização, cálculo do
semestre anterior, lista dos N semestres anteriores e conversão para sufixo de
arquivo de cache (`2026/1` → `2026_1`). Usado por `consulta_inicial.py`. Os
scripts manuais ainda mantêm cópias próprias dessas
regras — candidatos a migrar para cá.

### `turno_turma.py`
Faz o parse do campo `turno_turma` retornado pelo SIGAA (códigos de
dia-da-semana + intervalo de datas) e expande cada intervalo nas datas
efetivas de aula. Usado por `importar_grade.py`.

### `ausencias_especiais.py`
Utilitários para o campo `ausencias_especiais.trancamento_cancelamento` da
API: extrai o mapa código→data de trancamento/cancelamento de disciplina e
marca essas disciplinas com `situacao = "Trancada"` (no lugar do percentual).
Usado por `analisar_frequencia.py` (coleta) e `gerar_passe_livre.py`.

---

## 4. Scripts manuais (não entram em `executar_coleta.py`)

### `gerar_perda_vaga.py`
Lê os matriculados dos dois semestres anteriores ao período atual (com a
situação de cada disciplina) e grava no SQLite os alunos que reprovaram em
todas as disciplinas em ambos os semestres (candidatos a perda de vaga). Usa
`resposta_matriculas_AAAA_S.json` como cache — a coleta os mantém atualizados —
e só consulta a API se o arquivo faltar ou com `--forcar`. Acionado pela tela
Perda de vaga → Gerar análise (somente administradores; log em
`data/perda_vaga.log`).

### `gerar_passe_livre.py`
Cruza os alunos `ATIVO`/`FORMANDO` do semestre atual com a frequência
**mensal** (`frequencia_periodo`) dos semestres anteriores (padrão: 3,
ajustável via `--semestres`), consultando a API aluno por aluno. Grava direto
em `passe_livre_*` (não gera JSON), marca disciplinas trancadas via
`ausencias_especiais.py` e não apaga os dados do semestre atual (que ficam a
cargo de `sincronizar_passe_livre_semestre_atual.py`, que reaproveita
`gravar_banco` e `validar_periodo` deste script). Acionado pela tela Passe
livre → gerar.

### `importar_emails_professores.py`
Atualiza os e-mails dos professores a partir de um CSV exportado do Moodle
(`data/Users.csv`). Casa os registros pelo nome (normalizado, sem acentos) e
remove prefixos de iniciais que o Moodle cola no início do nome.

---

## Resumo rápido

| Arquivo | Categoria | Responsabilidade em uma linha |
|---|---|---|
| `executar_coleta.py` | Orquestração | Roda o pipeline completo na ordem correta |
| `consulta_alunos_massa.py` | Pipeline (0) | Consulta em massa (cadastro + intervalo), respostas cruas |
| `consulta_inicial.py` | Pipeline (1) | Lista matriculados do período corrente + anteriores |
| `analisar_frequencia.py` | Pipeline (2) | Monta a tabela de frequência a partir da consulta em massa |
| `sincronizar_passe_livre_semestre_atual.py` | Pipeline (2b) | Espelha o semestre atual em `passe_livre_*` |
| `importar_frequencia.py` | Pipeline (3) | Grava frequência/faltas no SQLite |
| `importar_trancados.py` | Pipeline (4) | Grava alunos trancados no SQLite |
| `importar_professores.py` | Pipeline (5) | Grava cursos, professores e vínculos |
| `importar_grade.py` | Pipeline (6) | Grava grade e datas de aula |
| `importar_chamadas.py` | Pipeline (7) | Grava última aula e histórico de chamadas |
| `gerar_alarmes.py` | Pipeline (8) | Calcula e grava alarmes de risco de evasão |
| `paths.py` | Módulo de apoio | Caminhos padronizados do projeto |
| `db.py` | Módulo de apoio | Conexão SQLite e schema |
| `api_auth.py` | Módulo de apoio | Token OAuth e URLs da API |
| `config_consultas.py` | Módulo de apoio | Datas de consulta (BD ou `consultas.json`) |
| `config_alarmes.py` | Módulo de apoio | Parâmetros das regras de alarme |
| `status_aluno.py` | Módulo de apoio | Regras de status ATIVO/FORMANDO/trancado |
| `periodo_letivo.py` | Módulo de apoio | Períodos AAAA/S: validação e semestres anteriores |
| `turno_turma.py` | Módulo de apoio | Expande intervalos de aula do SIGAA |
| `ausencias_especiais.py` | Módulo de apoio | Trancamento/cancelamento de disciplina (API) |
| `gerar_perda_vaga.py` | Manual (tela Perda de vaga) | Candidatos a perda de vaga |
| `gerar_passe_livre.py` | Manual | Passe livre a partir da frequência mensal |
| `importar_emails_professores.py` | Manual | Atualiza e-mails de professores via CSV |
