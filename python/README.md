# Pipeline Python — MAPA

Entrada única: `executar_coleta.py` (manual ou cron). Ele chama os scripts **nesta ordem** e, no fim, o PHP de e-mails.

```text
executar_coleta.py
  │
  ├─ 1. consulta_inicial.py
  ├─ 2. consulta_alunos.py
  ├─ 3. analisar_frequencia.py
  ├─ 4. importar_frequencia.py
  ├─ 5. importar_trancados.py
  ├─ 6. importar_professores.py
  ├─ 7. importar_grade.py
  ├─ 8. importar_chamadas.py
  ├─ 9. gerar_alarmes.py
  ├─ 10. scripts/enviar_emails_chamadas.php       (PHP)
  ├─ 11. scripts/enviar_emails_alarmes_alunos.php (PHP)
  └─ 12. scripts/enviar_emails_alarmes_staff.php  (PHP)
```

```bash
# Manual
python3 python/executar_coleta.py

# Cron (exemplo, a cada hora)
0 * * * * /usr/bin/python3 /var/www/mapa/python/executar_coleta.py > /var/www/mapa/data/coleta.log 2>&1
```

API OAuth/SIGAA: tabela `configuracoes` (tela Configurações → API).  
Datas de frequência/referência: `config/consultas.json`.  
Critérios dos alarmes (limite de frequência, janelas de faltas e texto dos
alertas): tabela `configuracoes` (tela Configurações → Alarmes).

**Status e cobertura (regras da coleta):** a 1ª consulta roda para o período
corrente **e para os 2 semestres anteriores** (`SEMESTRES_RETROATIVOS` em
`consulta_inicial.py`), cada um em **duas variantes**: `matriculado=sim` (padrão
da URL gravada) e `matriculado=nao`. A 2ª consulta roda para **todos** os logins
da união, qualquer que seja o status.

Os três alargamentos existem pelo mesmo motivo: o status da 1ª vale só para o
vínculo naquele período, e é a 2ª consulta que devolve todos os cursos da pessoa
com o **status atual**. Filtrar por status escondia quem trancou o único vínculo
que tinha; olhar só o período corrente escondia quem trancou antes (o
`TRANC. AUTOMÁTICO` só é aplicado no fechamento do semestre); e `matriculado=sim`
escondia justamente os trancados, que por definição não têm matrícula em
disciplina.

A API **exige** `periodo_letivo` quando `matriculado=nao` — sem ele devolve
HTTP 500 —, por isso a varredura é período a período. Omitir `matriculado` na
URL equivale a `sim`.

Frequência/alarmes continuam usando só **ATIVO** e **FORMANDO**
(`status_discente` da 2ª). Trancamento é confirmado na 2ª
(`TRANCADO` / `TRANC. AUTOMÁTICO`).

---

## Programas do pipeline

| # | Programa | O que faz | Lê | Gera |
|---|----------|-----------|----|------|
| 1 | `consulta_inicial.py` | Matriculados e não-matriculados do período corrente + 2 anteriores | BD (`configuracoes` API) | `resposta_matriculas.json`, `resposta_matriculas_AAAA_S.json`, `resposta_matriculas_AAAA_S_naomatriculados.json` |
| 2 | `consulta_alunos.py` | Detalhes por aluno (união dos logins de todos os períodos) | `resposta_matriculas*.json`, BD (API), `config/consultas.json` | `data/json/resposta_alunos.json` (+ `erros_alunos.json` se houver falha) |
| 3 | `analisar_frequencia.py` | Frequência (ATIVO/FORMANDO); trancados pela 2ª | `resposta_alunos.json` | `tabela_frequencia.json`, `alunos_trancados.json` |
| 3b | `sincronizar_passe_livre_semestre_atual.py` | Espelha o semestre atual (`api_periodo_letivo`) em `passe_livre_*` para frequência anual | `tabela_frequencia.json` | BD (`passe_livre_aluno_curso`, `passe_livre_disciplina`) |
| 4 | `importar_frequencia.py` | Nova coleta no SQLite (alunos, frequência, faltas) | `tabela_frequencia.json`, `config/consultas.json` | BD (`coletas`, `alunos`, `frequencia_disciplina`, `faltas_dia`, …) |
| 5 | `importar_trancados.py` | Alunos TRANCADO / TRANC. AUTOMÁTICO | `alunos_trancados.json` | BD (`alunos_trancados`) |
| 6 | `importar_professores.py` | Cursos, docentes e vínculos | `resposta_matriculas.json` | BD (`cursos`, `professores`, `disciplina_professores`) |
| 7 | `importar_grade.py` | Datas de aula a partir de `turno_turma` | `resposta_matriculas.json` | BD (`disciplina_grade`, `disciplina_aulas`) |
| 8 | `importar_chamadas.py` | Última aula / histórico de chamadas | `resposta_alunos.json`, BD (coleta) | BD (`disciplina_ultima_aula`, `disciplina_chamadas`) |
| 9 | `gerar_alarmes.py` | Regras de risco de evasão (limites, janelas e mensagens vindos do portal) | BD (coleta + faltas + `configuracoes`), `config/consultas.json` | BD (`alarmes`) |
| 10 | `enviar_emails_chamadas.php` | Avisa chamadas em atraso (2+ dias) | BD + `.env` (`EMAIL_SEND`) | BD (`chamada_emails`) + e-mail SMTP |
| 11 | `enviar_emails_alarmes_alunos.php` | E-mails de acolhimento aos alunos (alarmes críticos) | BD + `.env` | BD (`alarme_emails`, `alarmes`) + SMTP |
| 12 | `enviar_emails_alarmes_staff.php` | Resumos a professores/coordenadores | BD + `.env` | BD (`alarme_emails.staff_avisado_em`) + SMTP |

---

## Módulos de apoio (não são etapas)

Usados pelos programas acima; não entram na lista do `executar_coleta.py`.

| Módulo | Função |
|--------|--------|
| `paths.py` | Caminhos (`data/json/`, `config/`, …) |
| `db.py` | Conexão SQLite (`DB_PATH`) e `schema.sql` |
| `api_auth.py` | Token OAuth e URLs da API |
| `config_consultas.py` | Lê `config/consultas.json` |
| `config_alarmes.py` | Lê as regras de alarme (`alarme_*` em `configuracoes`, tela Configurações → Alarmes) com os padrões antigos como fallback |
| `status_aluno.py` | Regras ATIVO/FORMANDO/trancado |
| `periodo_letivo.py` | Períodos AAAA/S: validação, semestre anterior, sufixo de cache |
| `turno_turma.py` | Expande intervalos de aula (usado por `importar_grade.py`) |
| `gerar_perda_vaga.py` | Manual: candidatos a perda de vaga (2 semestres anteriores) → BD |
| `gerar_passe_livre.py` | Manual: ATIVO/FORMANDO do semestre atual × frequência **mensal** (`frequencia_periodo`) dos **3 semestres anteriores** → BD (`passe_livre_*`) + cache `resposta_alunos_AAAA_S_mensal.json`. Trancadas (`ausencias_especiais`) → `situacao`; % total do curso = valor da API. Não apaga o semestre atual. Opção `--semestres N` (padrão 3). |
| `sincronizar_passe_livre_semestre_atual.py` | Coleta: grava o semestre de `api_periodo_letivo` (datas da frequência) em `passe_livre_*` a partir de `tabela_frequencia.json`. |
| `gerar_carga_horaria.py` | Manual (também via Configurações → Carga horária): API alunos nos **4 últimos semestres** → `disciplina_carga_horaria` (`carga_horaria`, null permitido). |
