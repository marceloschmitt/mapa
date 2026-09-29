# Arquivos Python — MAPA

Descrição individual de cada script/módulo em `python/`. Para a ordem de
execução do pipeline e o fluxo de dados entre eles, veja o [README.md](README.md).

---

## 1. Orquestração

### `executar_coleta.py`
Ponto de entrada único do pipeline de coleta. Roda os scripts na sequência
fixa (consulta inicial → consulta alunos → análise de frequência → imports →
alarmes → e-mails PHP), interrompendo a cadeia se um passo falhar. Pensado
para ser chamado por caminho absoluto (cron ou manual), sem `cd` prévio.

---

## 2. Pipeline de coleta (ordem de execução)

### `consulta_inicial.py`
1ª consulta ao webservice SIGAA. Lê URL e credenciais OAuth da tabela
`configuracoes` (tela Configurações → API) e lista todos os alunos
matriculados (pode trazer vários status, não só `ATIVO`). Grava
`data/json/resposta_matriculas.json`.

Consulta também os `SEMESTRES_RETROATIVOS` semestres anteriores (padrão: 2) e,
para **cada** período (inclusive o corrente), a variante `matriculado=nao`:

| Arquivo | Conteúdo |
|---|---|
| `resposta_matriculas.json` | corrente, matriculados |
| `resposta_matriculas_AAAA_S.json` | anteriores, matriculados |
| `resposta_matriculas_AAAA_S_naomatriculados.json` | todos os períodos, não-matriculados |

Isso amplia o alcance da 2ª consulta para quem não está matriculado agora —
sobretudo os trancados, que por definição não têm matrícula em disciplina e por
isso somem da consulta padrão. A API exige `periodo_letivo` junto de
`matriculado=nao` (sem ele, HTTP 500), daí a varredura período a período.

O `resposta_matriculas.json` não é reescrito pela cobertura extra:
`importar_professores.py` e `importar_grade.py` dependem dele conter apenas
matriculados. Falhas nos períodos extras não interrompem a coleta — o período
corrente é o único crítico.

### `consulta_alunos.py`
2ª consulta ao SIGAA. Une **todos** os logins dos arquivos gravados por
`consulta_inicial.py` (período corrente + anteriores), um por pessoa e qualquer
que seja o status, e consulta em paralelo o endpoint de detalhes/frequência de
cada um. É esta consulta que devolve todos os cursos da pessoa, com o status
atual, e portanto a única que revela os vínculos trancados. Em empate de
relevância, os metadados (nome/matrícula/e-mail) do período mais recente
prevalecem. Grava `data/json/resposta_alunos.json` (e `erros_alunos.json` em
caso de falhas).

### `analisar_frequencia.py`
Lê `resposta_alunos.json` e monta a tabela de frequência por aluno/disciplina.
Considera apenas cursos `ATIVO` ou `FORMANDO`; alunos com `status_discente`
`TRANCADO` / `TRANC. AUTOMÁTICO` (da 2ª consulta) são separados como
trancados. Gera `tabela_frequencia.json` e `alunos_trancados.json`.

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
Importa `alunos_trancados.json` para a tabela `alunos_trancados` (última
coleta). Alunos trancados não entram nas contas de frequência nem alarmes;
ficam disponíveis só para consulta no portal.

### `importar_professores.py`
A partir de `resposta_matriculas.json`, popula `cursos` (todos os cursos da
matrícula, não só os com frequência), `professores` e o vínculo
`disciplina_professores`.

### `importar_grade.py`
Lê `resposta_matriculas.json`, expande os códigos de `turno_turma` (via
`turno_turma.py`) em datas efetivas de aula (segunda a sábado) e popula
`disciplina_grade` + `disciplina_aulas`.

### `importar_chamadas.py`
A partir de `resposta_alunos.json`, grava por disciplina/curso a data da
última aula ministrada (snapshot em `disciplina_ultima_aula`) e acumula o
histórico de datas distintas em `disciplina_chamadas`.

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
`TRANCADO`, `TRANC. AUTOMÁTICO` etc.), usado pela 2ª consulta e pelo
controle de frequência/trancamento.

### `periodo_letivo.py`
Funções puras para período letivo `AAAA/S`: validação/normalização, cálculo do
semestre anterior, lista dos N semestres anteriores e conversão para sufixo de
arquivo de cache (`2026/1` → `2026_1`). Usado por `consulta_inicial.py` e
`consulta_alunos.py`. Os scripts manuais ainda mantêm cópias próprias dessas
regras — candidatos a migrar para cá.

### `turno_turma.py`
Faz o parse do campo `turno_turma` retornado pelo SIGAA (códigos de
dia-da-semana + intervalo de datas) e expande cada intervalo nas datas
efetivas de aula. Usado por `importar_grade.py`.

### `ausencias_especiais.py`
Utilitários para o campo `ausencias_especiais.trancamento_cancelamento` da
API: extrai o mapa código→data de trancamento/cancelamento de disciplina e
marca essas disciplinas com `situacao = "Trancada"` (no lugar do percentual)
nas listas usadas pelo passe livre.

---

## 4. Scripts manuais (não entram em `executar_coleta.py`)

### `gerar_perda_vaga.py`
Consulta os dois semestres anteriores ao período atual da API e grava no
SQLite os alunos que reprovaram em todas as disciplinas em ambos os
semestres (candidatos a perda de vaga).

### `gerar_passe_livre.py`
Cruza os alunos `ATIVO`/`FORMANDO` do semestre atual com a frequência
**mensal** (`frequencia_periodo`) dos semestres anteriores (padrão: 3,
ajustável via `--semestres`). Grava em `passe_livre_*`, salva cache
`resposta_alunos_AAAA_S_mensal.json` por semestre, marca disciplinas trancadas
via `ausencias_especiais.py` e não apaga os dados do semestre atual (que
ficam a cargo de `sincronizar_passe_livre_semestre_atual.py`). Também expõe
as funções reaproveitadas por `gerar_resposta_alunos_mensal.py`
(`consultar_um_aluno`, `salvar_cache_mensal`, `validar_periodo` etc.).

### `gerar_resposta_alunos_mensal.py`
Utilitário de linha de comando para gerar apenas o cache JSON de alunos em
modo mensal (`frequencia_periodo`) de um semestre específico, reaproveitando
a lógica de consulta de `gerar_passe_livre.py`. Útil para depurar ou
pré-aquecer o cache sem rodar a geração completa do passe livre.

### `gerar_carga_horaria.py`
Monta a tabela `disciplina_carga_horaria` consultando a API de alunos nos 4
últimos semestres (incluindo o atual). Reaproveita caches JSON existentes
quando disponíveis (`resposta_matriculas*.json`, `resposta_alunos*.json`) e
grava a carga horária por disciplina (permite `null`). Também acionável pela
tela Configurações → Carga horária.

### `importar_emails_professores.py`
Atualiza os e-mails dos professores a partir de um CSV exportado do Moodle
(`data/Users.csv`). Casa os registros pelo nome (normalizado, sem acentos) e
remove prefixos de iniciais que o Moodle cola no início do nome.

---

## Resumo rápido

| Arquivo | Categoria | Responsabilidade em uma linha |
|---|---|---|
| `executar_coleta.py` | Orquestração | Roda o pipeline completo na ordem correta |
| `consulta_inicial.py` | Pipeline (1) | Lista matriculados do período corrente + anteriores |
| `consulta_alunos.py` | Pipeline (2) | Consulta detalhes/vínculos da união dos logins |
| `analisar_frequencia.py` | Pipeline (3) | Monta tabela de frequência e lista de trancados |
| `sincronizar_passe_livre_semestre_atual.py` | Pipeline (3b) | Espelha o semestre atual em `passe_livre_*` |
| `importar_frequencia.py` | Pipeline (4) | Grava frequência/faltas no SQLite |
| `importar_trancados.py` | Pipeline (5) | Grava alunos trancados no SQLite |
| `importar_professores.py` | Pipeline (6) | Grava cursos, professores e vínculos |
| `importar_grade.py` | Pipeline (7) | Grava grade e datas de aula |
| `importar_chamadas.py` | Pipeline (8) | Grava última aula e histórico de chamadas |
| `gerar_alarmes.py` | Pipeline (9) | Calcula e grava alarmes de risco de evasão |
| `paths.py` | Módulo de apoio | Caminhos padronizados do projeto |
| `db.py` | Módulo de apoio | Conexão SQLite e schema |
| `api_auth.py` | Módulo de apoio | Token OAuth e URLs da API |
| `config_consultas.py` | Módulo de apoio | Datas de consulta (BD ou `consultas.json`) |
| `config_alarmes.py` | Módulo de apoio | Parâmetros das regras de alarme |
| `status_aluno.py` | Módulo de apoio | Regras de status ATIVO/FORMANDO/trancado |
| `periodo_letivo.py` | Módulo de apoio | Períodos AAAA/S: validação e semestres anteriores |
| `turno_turma.py` | Módulo de apoio | Expande intervalos de aula do SIGAA |
| `ausencias_especiais.py` | Módulo de apoio | Trancamento/cancelamento de disciplina (API) |
| `gerar_perda_vaga.py` | Manual | Candidatos a perda de vaga |
| `gerar_passe_livre.py` | Manual | Passe livre a partir da frequência mensal |
| `gerar_resposta_alunos_mensal.py` | Manual | Cache JSON de alunos em modo mensal |
| `gerar_carga_horaria.py` | Manual | Carga horária por disciplina |
| `importar_emails_professores.py` | Manual | Atualiza e-mails de professores via CSV |
