# Pipeline Python — MAPA

Entrada única: `executar_coleta.py` (manual ou cron). Ele chama os scripts **nesta ordem** e, no fim, o PHP de e-mails.

```text
executar_coleta.py
  │
  ├─ 0. consulta_alunos_massa.py
  ├─ 1. consulta_inicial.py
  ├─ 2. analisar_frequencia.py
  ├─ 2b. sincronizar_passe_livre_semestre_atual.py
  ├─ 3. importar_frequencia.py
  ├─ 4. importar_trancados.py
  ├─ 5. importar_professores.py
  ├─ 6. importar_grade.py
  ├─ 7. importar_chamadas.py
  ├─ 8. gerar_alarmes.py
  ├─ 9. scripts/enviar_emails_chamadas.php       (PHP)
  ├─ 10. scripts/enviar_emails_alarmes_alunos.php (PHP)
  └─ 11. scripts/enviar_emails_alarmes_staff.php  (PHP)
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

**Status e cobertura (regras da coleta):** o status atual de cada vínculo vem
da consulta em massa (`consulta_alunos_massa.py`), que devolve todos os alunos
sem precisar de lista de logins. Frequência/alarmes usam só **ATIVO** e
**FORMANDO**; os trancados (`TRANCADO` / `TRANC. AUTOMÁTICO`) vêm do cadastro em
massa.

A 1ª consulta (`consulta_inicial.py`) busca os matriculados do período corrente
**e dos 2 semestres anteriores** (`SEMESTRES_RETROATIVOS`). Na coleta, só
`resposta_matriculas.json` (período corrente) é usado — por professores e grade.
Os semestres anteriores são regravados a cada coleta como cache atualizado de
`gerar_perda_vaga.py`.

Omitir `matriculado` na URL equivale a `sim`. A API exige `periodo_letivo`
quando `matriculado=nao` (sem ele devolve HTTP 500).

---

## Documentação da API (SIGAA / IFRS)

Ambos exigem login institucional do IFRS.

- Swagger (Alunos SIGAA): <https://dev8e.ifrs.edu.br/api/documentation#/Alunos%20SIGAA/644b7a2e4fc838f06acbbe10acc83b5b>
- Wiki da DTI (OpenProject): <https://openproject.ifrs.edu.br/projects/documentacoes-dti/wiki/api-de-servicos>

Comportamentos observados no endpoint `alunos` (set/2026):

- `tipo_frequencia=intervalo` usa `frequencia_data_inicial` / `frequencia_data_final`
  (formato `DD-MM-AAAA`) e traz `total.percentual_frequencia_total` e
  `total.frequencia_com_ausencias_justificadas` (percentual sem as justificadas).
- `tipo_frequencia=mensal` só devolve frequência com `frequencia_periodo=AAAA/S`;
  sem ele, `frequencias` vem `null`.
- Cursos integrados (`nivel`/`curso_nivel` = `N`, anuais, ingresso `AAAA/0`):
  no modo mensal `frequencias` vem sempre `null` (testado `2026/1`, `2026/2`,
  `2026/0`, `2026`, sem período). No modo intervalo funcionam normalmente.
  O EJA integrado vem com nível `T` e funciona no mensal.
- Ausências abonadas não contam como falta (fora dos totais); justificadas contam
  em `percentual_frequencia_total` e na FREQUÊNCIA GLOBAL do modo mensal.

---

## Programas do pipeline

| # | Programa | O que faz | Lê | Gera |
|---|----------|-----------|----|------|
| 0 | `consulta_alunos_massa.py` | Consulta em massa (sem login), respostas cruas: cadastro e frequência por intervalo. | BD (`configuracoes` API) | `resposta_alunos_massa_cadastro.json`, `resposta_alunos_massa_intervalo.json` |
| 1 | `consulta_inicial.py` | Matriculados do período corrente + 2 anteriores | BD (`configuracoes` API) | `resposta_matriculas.json`, `resposta_matriculas_AAAA_S.json` |
| 2 | `analisar_frequencia.py` | Frequência (ATIVO/FORMANDO) | `resposta_alunos_massa_intervalo.json`, `resposta_alunos_massa_cadastro.json` | `tabela_frequencia.json` |
| 2b | `sincronizar_passe_livre_semestre_atual.py` | Espelha o semestre atual (`api_periodo_letivo`) em `passe_livre_*` para frequência anual | `tabela_frequencia.json` | BD (`passe_livre_aluno_curso`, `passe_livre_disciplina`) |
| 3 | `importar_frequencia.py` | Nova coleta no SQLite (alunos, frequência, faltas) | `tabela_frequencia.json`, `config/consultas.json` | BD (`coletas`, `alunos`, `frequencia_disciplina`, `faltas_dia`, …) |
| 4 | `importar_trancados.py` | Alunos TRANCADO / TRANC. AUTOMÁTICO | `resposta_alunos_massa_cadastro.json` | BD (`alunos_trancados`) |
| 5 | `importar_professores.py` | Cursos, docentes e vínculos | `resposta_matriculas.json` | BD (`cursos`, `professores`, `disciplina_professores`) |
| 6 | `importar_grade.py` | Datas de aula a partir de `turno_turma` | `resposta_matriculas.json` | BD (`disciplina_grade`, `disciplina_aulas`) |
| 7 | `importar_chamadas.py` | Última aula / histórico de chamadas | `resposta_alunos_massa_intervalo.json`, BD (coleta) | BD (`disciplina_ultima_aula`, `disciplina_chamadas`) |
| 8 | `gerar_alarmes.py` | Regras de risco de evasão (limites, janelas e mensagens vindos do portal) | BD (coleta + faltas + `configuracoes`), `config/consultas.json` | BD (`alarmes`) |
| 9 | `enviar_emails_chamadas.php` | Avisa chamadas em atraso (2+ dias) | BD + `.env` (`EMAIL_SEND`) | BD (`chamada_emails`) + e-mail SMTP |
| 10 | `enviar_emails_alarmes_alunos.php` | E-mails de acolhimento aos alunos (alarmes críticos) | BD + `.env` | BD (`alarme_emails`, `alarmes`) + SMTP |
| 11 | `enviar_emails_alarmes_staff.php` | Resumos a professores/coordenadores | BD + `.env` | BD (`alarme_emails.staff_avisado_em`) + SMTP |

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
| `ausencias_especiais.py` | Disciplinas trancadas/canceladas (`ausencias_especiais`), usado por `analisar_frequencia.py` e `gerar_passe_livre.py` |
| `gerar_perda_vaga.py` | Manual (tela Perda de vaga → Gerar análise): candidatos a perda de vaga (2 semestres anteriores) → BD; usa `resposta_matriculas_AAAA_S.json` como cache |
| `gerar_passe_livre.py` | Manual: ATIVO/FORMANDO do semestre atual × frequência **mensal** (`frequencia_periodo`) dos **3 semestres anteriores** → BD (`passe_livre_*`), sem JSON. Trancadas (`ausencias_especiais`) → `situacao`; % total do curso = valor da API. Não apaga o semestre atual. Opção `--semestres N` (padrão 3). |
| `sincronizar_passe_livre_semestre_atual.py` | Coleta: grava o semestre de `api_periodo_letivo` (datas da frequência) em `passe_livre_*` a partir de `tabela_frequencia.json`. |
| `importar_emails_professores.py` | Manual: e-mails dos professores a partir de CSV do Moodle (`data/Users.csv`) |