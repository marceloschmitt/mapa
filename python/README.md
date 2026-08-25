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

**Status (regras da coleta):** a 1ª consulta pode trazer vários status (não só
`ATIVO`). A 2ª consulta roda para **ATIVO**, **FORMANDO** e **trancados** da 1ª.
Frequência/alarmes usam só **ATIVO** e **FORMANDO** (`status_discente` da 2ª).
Trancamento é confirmado na 2ª (`TRANCADO` / `TRANC. AUTOMÁTICO`).

---

## Programas do pipeline

| # | Programa | O que faz | Lê | Gera |
|---|----------|-----------|----|------|
| 1 | `consulta_inicial.py` | Lista matriculados na API SIGAA | BD (`configuracoes` API) | `data/json/resposta_matriculas.json` |
| 2 | `consulta_alunos.py` | Frequência por aluno (ATIVO/FORMANDO/trancados da 1ª) | `resposta_matriculas.json`, BD (API), `config/consultas.json` | `data/json/resposta_alunos.json` (+ `erros_alunos.json` se houver falha) |
| 3 | `analisar_frequencia.py` | Frequência (ATIVO/FORMANDO); trancados pela 2ª | `resposta_alunos.json` | `tabela_frequencia.json`, `alunos_trancados.json` |
| 4 | `importar_frequencia.py` | Nova coleta no SQLite (alunos, frequência, faltas) | `tabela_frequencia.json`, `config/consultas.json` | BD (`coletas`, `alunos`, `frequencia_disciplina`, `faltas_dia`, …) |
| 5 | `importar_trancados.py` | Alunos TRANCADO / TRANC. AUTOMÁTICO | `alunos_trancados.json` | BD (`alunos_trancados`) |
| 6 | `importar_professores.py` | Cursos, docentes e vínculos | `resposta_matriculas.json` | BD (`cursos`, `professores`, `disciplina_professores`) |
| 7 | `importar_grade.py` | Datas de aula a partir de `turno_turma` | `resposta_matriculas.json` | BD (`disciplina_grade`, `disciplina_aulas`) |
| 8 | `importar_chamadas.py` | Última aula / histórico de chamadas | `resposta_alunos.json`, BD (coleta) | BD (`disciplina_ultima_aula`, `disciplina_chamadas`) |
| 9 | `gerar_alarmes.py` | Regras de risco de evasão | BD (coleta + faltas), `config/consultas.json` | BD (`alarmes`) |
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
| `turno_turma.py` | Expande intervalos de aula (usado por `importar_grade.py`) |
