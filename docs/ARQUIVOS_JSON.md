# Arquivos JSON — MAPA

Quem gera e quem lê cada arquivo de `data/json/`, para decidir o que precisa
ser regenerado periodicamente (coleta) e o que só precisa ser gerado quando o
administrador quiser. Descrição dos scripts em
[ARQUIVOS_PYTHON.md](ARQUIVOS_PYTHON.md); ordem do pipeline em
[python/README.md](../python/README.md).

As telas PHP não leem nenhum JSON de `data/json/`: tudo o que aparece no
portal vem do SQLite. Os JSON são entrada/cache dos scripts Python.

`AAAA_S` = período letivo com `/` trocado por `_` (ex.: `2026/1` → `2026_1`).
"Período atual" = `api_periodo_letivo` (Configurações → API).

---

## 1. Visão geral

| Arquivo | Gerado por | Lido por | Frequência |
|---|---|---|---|
| `resposta_matriculas.json` | `consulta_inicial.py` | `importar_professores.py`, `importar_grade.py`, `gerar_passe_livre.py`, `gerar_perda_vaga.py`* | **Periódico** |
| `resposta_matriculas_AAAA_S.json` | `consulta_inicial.py` (2 semestres anteriores); `gerar_perda_vaga.py` (se não existir) | `gerar_perda_vaga.py`* | **Periódico** (2 anteriores); demais sob demanda |
| `tabela_frequencia.json` | `analisar_frequencia.py` | `sincronizar_passe_livre_semestre_atual.py`, `importar_frequencia.py` | **Periódico** |
| `resposta_alunos_massa_cadastro.json` | `consulta_alunos_massa.py` | `importar_trancados.py`, `analisar_frequencia.py` | **Periódico** |
| `resposta_alunos_massa_intervalo.json` | `consulta_alunos_massa.py` | `analisar_frequencia.py`, `importar_chamadas.py` | **Periódico** |
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
                                           └─> importar_chamadas.py     → disciplina_ultima_aula, disciplina_chamadas

consulta_inicial.py
  ├─ resposta_matriculas.json ─────────────┬─> importar_professores.py  → cursos, professores, disciplina_professores
  │                                        └─> importar_grade.py        → disciplina_grade, disciplina_aulas
  └─ resposta_matriculas_AAAA_S.json           (cache de gerar_perda_vaga.py)

analisar_frequencia.py
  └─ tabela_frequencia.json ───────────────┬─> sincronizar_passe_livre_semestre_atual.py → passe_livre_* (período atual)
                                           └─> importar_frequencia.py   → coletas, alunos, frequencia_*, faltas_dia

(BD) ─> gerar_alarmes.py → alarmes ─> e-mails PHP (chamadas, alarmes alunos/staff)
```

### `resposta_matriculas.json`
- **Conteúdo:** matriculados do período atual (`/matriculados?matriculado=sim`),
  com disciplinas, turmas, `turno_turma` e docentes.
- **Telas que dependem dele (via BD):** Chamadas, Grade/aulas, Professores,
  filtros de curso, e-mails de chamadas em atraso.
- **Observação:** só pode conter matriculados — `importar_professores.py` e
  `importar_grade.py` contam com isso.

### `resposta_matriculas_AAAA_S.json` (2 semestres anteriores)
- **Conteúdo:** matriculados de cada semestre anterior.
- **Para que serve:** cache de `gerar_perda_vaga.py` (situação das disciplinas,
  para achar quem reprovou em tudo). Nenhum passo da coleta o lê, mas a coleta
  o regrava a cada execução para a perda de vaga usar dados atualizados.

### `tabela_frequencia.json`
- **Conteúdo:** versão já filtrada/normalizada dos dois arquivos da consulta em
  massa (só vínculos ATIVO/FORMANDO com curso).
- **Telas que dependem dele (via BD):** Frequência, Alarmes, Disciplinas
  trancadas, Frequência corrente / passe livre do semestre atual, Analytics.
- É intermediário: existe só para os imports seguintes.

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
  (inclui a data de trancamento de quem trancou no período atual).
- **Usado por:** `analisar_frequencia.py` (fonte da frequência) e
  `importar_chamadas.py` (última aula ministrada por disciplina/curso).
- **Telas que dependem dele (via BD):** Chamadas e, via
  `tabela_frequencia.json`, as de frequência.

---

## 3. Tarefas sob demanda (administrador)

Não rodam na coleta. Os resultados ficam no BD.

| Tarefa | Como roda | Lê | Grava (JSON) | Grava (BD) | Quando rodar |
|---|---|---|---|---|---|
| Passe livre (semestres anteriores) | Tela Passe livre → gerar, ou `gerar_passe_livre.py` | `resposta_matriculas.json` (logins ATIVO/FORMANDO; senão última coleta no BD) + API mensal aluno por aluno | — | `passe_livre_aluno_curso`, `passe_livre_disciplina` | Início de semestre, ou quando houver correção de frequência no SIGAA |
| Perda de vaga | `gerar_perda_vaga.py` (tela Perda de vaga → Gerar análise, ou CLI) | `resposta_matriculas.json` + `resposta_matriculas_AAAA_S.json` dos 2 anteriores (cache) | `resposta_matriculas_AAAA_S.json` se faltar | `perda_vaga_*` | Após fechamento de semestre |

O semestre **atual** do passe livre / frequência corrente não depende de
`gerar_passe_livre.py`: é atualizado pela coleta
(`sincronizar_passe_livre_semestre_atual.py`).

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
