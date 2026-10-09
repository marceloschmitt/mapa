# Arquivos JSON — MAPA

Quem gera e quem lê cada arquivo de `data/json/`, para decidir o que precisa
ser regenerado periodicamente (coleta) e o que só precisa ser gerado quando o
administrador quiser. Descrição dos scripts em
[ARQUIVOS_PYTHON.md](ARQUIVOS_PYTHON.md); ordem do pipeline em
[python/README.md](../python/README.md).

As telas PHP não leem JSON de `data/json/` para os dados de frequência: tudo o
que aparece no portal vem do SQLite. Os JSON são entrada/cache dos scripts
Python. A única exceção é o resumo de `integrados_anual_AAAA.json` na tela
Configuração da API.

`AAAA_S` = período letivo com `/` trocado por `_` (ex.: `2026/1` → `2026_1`).
"Período atual" = `api_periodo_letivo` (Configurações → API).

---

## 1. Visão geral

| Arquivo | Gerado por | Lido por | Frequência |
|---|---|---|---|
| `resposta_matriculas.json` | `consulta_inicial.py` | `analisar_frequencia.py`, `importar_professores.py`, `importar_grade.py`, `importar_chamadas.py`, `gerar_passe_livre.py`, `gerar_perda_vaga.py`* | **Periódico** |
| `resposta_matriculas_AAAA_S.json` | `consulta_inicial.py` (2 semestres anteriores); `gerar_perda_vaga.py` (se não existir) | `gerar_perda_vaga.py`* | **Periódico** (2 anteriores); demais sob demanda |
| `tabela_frequencia.json` | `analisar_frequencia.py` | `sincronizar_passe_livre_semestre_atual.py`, `importar_frequencia.py` | **Periódico** |
| `resposta_alunos_massa_cadastro.json` | `consulta_alunos_massa.py` | `importar_trancados.py`, `analisar_frequencia.py` | **Periódico** |
| `resposta_alunos_massa_intervalo.json` | `consulta_alunos_massa.py` | `analisar_frequencia.py`, `importar_chamadas.py` | **Periódico** |
| `integrados_anual_AAAA.json` | `consulta_integrados_anual.py` (tela Configuração da API → Buscar meses encerrados) | `analisar_frequencia.py`, tela Configuração da API (resumo) | **Sob demanda** (a cada troca de semestre) |

\* Usa o arquivo como **cache**: se existir, não consulta a API para aquele
período (a menos que rode com `--forcar`).

---

## 2. Arquivos periódicos (gerados pela coleta)

Gerados a cada execução de `executar_coleta.py` (cron). As tarefas abaixo
dependem deles estarem atualizados.

```text
consulta_alunos_massa.py
  ├─ resposta_alunos_massa_cadastro.json ──┬─> importar_trancados.py    → alunos_trancados
  │                                        └─> analisar_frequencia.py
  └─ resposta_alunos_massa_intervalo.json ─┬─> analisar_frequencia.py
                                           └─> importar_chamadas.py     → turma_ultima_aula, turma_chamadas,
                                                                          disciplina_ultima_aula, disciplina_chamadas

consulta_inicial.py
  ├─ resposta_matriculas.json ─────────────┬─> analisar_frequencia.py   (turma do aluno em cada disciplina)
  │                                        ├─> importar_professores.py  → cursos, professores, disciplina_professores,
  │                                        │                              turma_professores
  │                                        ├─> importar_grade.py        → turmas, turma_aulas,
  │                                        │                              disciplina_grade, disciplina_aulas
  │                                        └─> importar_chamadas.py     (turma de cada aluno)
  └─ resposta_matriculas_AAAA_S.json           (cache de gerar_perda_vaga.py)

integrados_anual_AAAA.json (sob demanda) ──> analisar_frequencia.py (integrados: soma o ano letivo)

analisar_frequencia.py
  └─ tabela_frequencia.json ───────────────┬─> sincronizar_passe_livre_semestre_atual.py → passe_livre_* (período atual)
                                           └─> importar_frequencia.py   → coletas, alunos, frequencia_*, faltas_dia

(BD) ─> gerar_alarmes.py → alarmes ─> e-mails PHP (chamadas, alarmes alunos/staff)
```

### `resposta_matriculas.json`
- **Conteúdo:** matriculados do período atual (`/matriculados?matriculado=sim`),
  com disciplinas, turmas, `turno_turma` e docentes.
- **Telas que dependem dele (via BD):** Chamadas, Grade/aulas, Professores,
  filtros de curso, e-mails de chamadas em atraso e tudo o que é separado por
  turma (alarmes, disciplinas críticas, escopo do professor).
- **Turma do aluno:** cada disciplina do aluno traz `id_turma`, `turma`
  (nome), `turno_turma` e os docentes da turma. A análise de frequência e a
  importação de chamadas casam o aluno pela chave `id_discente` + código da
  disciplina.
- **Observação:** só pode conter matriculados — `importar_professores.py` e
  `importar_grade.py` contam com isso. Se faltar, a coleta segue com os dados
  por disciplina/curso (sem turma).

### `resposta_matriculas_AAAA_S.json` (2 semestres anteriores)
- **Conteúdo:** matriculados de cada semestre anterior.
- **Para que serve:** cache de `gerar_perda_vaga.py` (situação das disciplinas,
  para achar quem reprovou em tudo). Nenhum passo da coleta o lê, mas a coleta
  o regrava a cada execução para a perda de vaga usar dados atualizados.

### `tabela_frequencia.json`
- **Conteúdo:** versão já filtrada/normalizada dos dois arquivos da consulta em
  massa (só vínculos ATIVO/FORMANDO com curso).
- **Telas que dependem dele (via BD):** Frequência, Alarmes, Disciplinas
  trancadas, Frequência corrente, passe livre do semestre atual, Analytics.
- É intermediário: existe só para os imports seguintes.
- **Integrados:** quando há `integrados_anual_AAAA.json` válido, cada vínculo
  integrado ganha `frequencia_anual` (`desde`, `geral` e `disciplinas` por
  código) com o intervalo atual somado aos meses encerrados. Só
  `importar_frequencia.py` usa esse campo; os demais (`frequencia_geral`,
  `disciplinas`, `dias_falta`) continuam sendo do intervalo da coleta, e é
  deles que o passe livre do semestre atual lê.

### `resposta_alunos_massa_cadastro.json` (consulta em massa)
- **Conteúdo:** resposta crua da URL alunos em massa — cadastro
  (`api_url_alunos_massa_cadastro`, hoje `/sig/sigaa/alunos` sem parâmetros): mapa
  login → aluno (nome civil, nome social, e-mail, …) com a lista de cursos
  (matrícula, nome do curso, turma de entrada, ingresso, `status_discente`).
  Sem frequência.
- **Usado por:** `importar_trancados.py`, que seleciona os cursos `TRANCADO` /
  `TRANC. AUTOMÁTICO`, e `analisar_frequencia.py` (nome, nome social, e-mail,
  turma de entrada).
- **Telas que dependem dele (via BD):** Trancados e, via
  `tabela_frequencia.json`, as de frequência.

### `resposta_alunos_massa_intervalo.json` (consulta em massa)
- **Conteúdo:** resposta crua da URL alunos em massa — frequência por intervalo
  (`api_url_alunos_massa_intervalo`, com `{data_inicial}` e `{data_final}`): um
  registro por vínculo com status, totais, disciplinas e `ausencias_especiais`
  (inclui a data de trancamento de disciplina no período atual). Só vínculos
  `ATIVO` e `FORMANDO`: duas consultas (`&status=ATIVO`, `&status=FORMANDO`)
  juntadas no mesmo arquivo.
- **Usado por:** `analisar_frequencia.py` (fonte da frequência) e
  `importar_chamadas.py` (última aula ministrada por turma e por
  disciplina/curso; o campo `ultima_aula_ministrada` é por aluno, e a data da
  turma é a maior entre os seus alunos).
- **Telas que dependem dele (via BD):** Chamadas e, via
  `tabela_frequencia.json`, as de frequência.

### `integrados_anual_AAAA.json` (sob demanda)
- **Conteúdo:** meses encerrados do ano letivo dos integrados
  (`curso_nivel = N`), de `integrados_data_inicio` até a véspera de
  `frequencia_data_inicial`. `AAAA` é o ano do início. Consultado na URL de
  frequência por intervalo, com `&status=ATIVO`, em blocos de até 2 meses (o
  Cloudflare corta respostas acima de 60 s); se um bloco falhar, tenta mês a
  mês. Guarda só os vínculos integrados:
  `{data_inicial, data_final, executado_em, erros, blocos: [{data_inicial, data_final, consultado_em, vinculos}]}`.
- **Quando gerar:** a cada troca de semestre dos integrados (quando
  `frequencia_data_inicial` muda), ou para corrigir frequência lançada depois
  no SIGAA. Bloco que falhar mantém a versão anterior do mesmo período, se
  houver, e fica listado em `erros`.
- **Usado por:** `analisar_frequencia.py`, só se os blocos cobrirem o período
  inteiro sem buracos; senão a coleta avisa e os integrados ficam só com o
  semestre.
- **Telas que dependem dele (via BD):** Ingressantes, Alarmes e Analytics
  (percentual anual dos integrados).

---

## 3. Tarefas sob demanda (administrador)

Não rodam na coleta. Os resultados ficam no BD.

| Tarefa | Como roda | Lê | Grava (JSON) | Grava (BD) | Quando rodar |
|---|---|---|---|---|---|
| Passe livre (semestres anteriores) | Tela Passe livre → gerar, ou `gerar_passe_livre.py` | `resposta_matriculas.json` (logins ATIVO/FORMANDO; senão última coleta no BD) + API mensal aluno por aluno | — | `passe_livre_aluno_curso`, `passe_livre_disciplina` | Início de semestre, ou quando houver correção de frequência no SIGAA |
| Meses encerrados dos integrados | Tela Configuração da API → Buscar meses encerrados, ou `consulta_integrados_anual.py` (log em `data/integrados_anual.log`) | Configuração (`integrados_data_inicio`, `frequencia_data_inicial`, URL de intervalo) + API | `integrados_anual_AAAA.json` | — | A cada troca de semestre dos integrados |
| Perda de vaga | `gerar_perda_vaga.py` (tela Perda de vaga → Gerar análise, ou CLI) | `resposta_matriculas.json` + `resposta_matriculas_AAAA_S.json` dos 2 anteriores (cache) | `resposta_matriculas_AAAA_S.json` se faltar | `perda_vaga_*` | Após fechamento de semestre |

O semestre **atual** do passe livre não depende de
`gerar_passe_livre.py`: é atualizado pela coleta
(`sincronizar_passe_livre_semestre_atual.py`). A tela Frequência corrente não
usa essas tabelas: lê a última coleta (`frequencia_curso` e `frequencia_disciplina`).

---

## 4. Pontos de atenção

1. **Cache sem validade:** `gerar_perda_vaga.py` reaproveita qualquer
   `resposta_matriculas_AAAA_S.json` existente. A coleta regrava os 2
   semestres anteriores a cada execução; se ela estiver parada, o arquivo pode
   estar desatualizado. Use `--forcar` para ignorar o cache.
2. **Integrados no modo mensal:** o endpoint antigo
   (`tipo_frequencia=mensal`) devolve `frequencias: null` para cursos
   integrados (`curso_nivel = N`). Ver "Documentação da API" em
   [python/README.md](../python/README.md).
3. **Frequência anual dos integrados desatualizada:** `integrados_anual_AAAA.json`
   não é refeito pela coleta. Ao trocar `frequencia_data_inicial` (novo
   semestre), rode de novo "Buscar meses encerrados"; até lá a coleta avisa que
   o arquivo não cobre o período e os integrados ficam só com o semestre.
