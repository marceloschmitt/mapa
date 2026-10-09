# Pipeline Python — MAPA

Entrada única: `executar_coleta.py` (manual ou cron). Ele chama os scripts **nesta ordem** e, no fim, o PHP de e-mails.

```text
executar_coleta.py
  │
  ├─ 0. consulta_alunos_massa.py
  ├─ 1. consulta_inicial.py
  ├─ 2. analisar_frequencia.py
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
`resposta_matriculas.json` (período corrente) é usado — por professores, grade
e para identificar a turma do aluno em cada disciplina.

**Turmas:** uma disciplina pode ter várias turmas, com horário, professores e
chamada próprios. A turma do aluno (`id_turma`) vem de
`resposta_matriculas.json` e segue para `frequencia_disciplina` e `alarmes`.
Chamadas, alarmes e o escopo do professor usam os dados da turma; sem turma
identificada, os da disciplina no curso. Detalhes em
[`docs/banco-de-dados.md`](../docs/banco-de-dados.md#turmas).
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
- O endpoint de intervalo não filtra por nível; aceita `&status=ATIVO`. Para os
  integrados, a frequência por disciplina é presenças / horários × 100 com 2
  casas e a do curso, inteira (ambas arredondando meio para cima).

**Frequência anual dos integrados:** a coleta consulta só o semestre. Os
meses encerrados do ano letivo (de `integrados_data_inicio` até a véspera de
`frequencia_data_inicial`) são buscados sob demanda pela tela Configuração da
API → "Buscar meses encerrados" (`consulta_integrados_anual.py`) e gravados em
`integrados_anual_AAAA.json`. A cada coleta, `analisar_frequencia.py` os soma
ao semestre e `importar_frequencia.py` grava o resultado no banco
(`frequencia_curso.frequencia_desde` = início do ano). Rode a busca de novo a
cada troca de semestre dos integrados. Faltas consecutivas e passe livre
continuam com o semestre. Detalhes em
[`docs/banco-de-dados.md`](../docs/banco-de-dados.md#frequência-anual-dos-integrados).
- Ausências abonadas não contam como falta (fora dos totais); justificadas contam
  em `percentual_frequencia_total` e na FREQUÊNCIA GLOBAL do modo mensal.

---

## Programas do pipeline

| # | Programa | O que faz | Lê | Gera |
|---|----------|-----------|----|------|
| 0 | `consulta_alunos_massa.py` | Consulta em massa (sem login), respostas cruas: cadastro e frequência por intervalo (só ATIVO e FORMANDO, uma consulta por status). | BD (`configuracoes` API) | `resposta_alunos_massa_cadastro.json`, `resposta_alunos_massa_intervalo.json` |
| 1 | `consulta_inicial.py` | Matriculados do período corrente + 2 anteriores | BD (`configuracoes` API) | `resposta_matriculas.json`, `resposta_matriculas_AAAA_S.json` |
| 2 | `analisar_frequencia.py` | Frequência (ATIVO/FORMANDO), com a turma do aluno em cada disciplina; integrados com o ano letivo somado | `resposta_alunos_massa_intervalo.json`, `resposta_alunos_massa_cadastro.json`, `resposta_matriculas.json`, `integrados_anual_AAAA.json` (opcional) | `tabela_frequencia.json` |
| 3 | `importar_frequencia.py` | Nova coleta no SQLite (alunos, frequência, faltas) | `tabela_frequencia.json`, `config/consultas.json` | BD (`coletas`, `alunos`, `frequencia_curso`, `frequencia_disciplina`, `faltas_dia`, …) |
| 4 | `importar_trancados.py` | Alunos TRANCADO / TRANC. AUTOMÁTICO | `resposta_alunos_massa_cadastro.json` | BD (`alunos_trancados`) |
| 5 | `importar_professores.py` | Cursos, docentes e vínculos por disciplina e por turma | `resposta_matriculas.json` | BD (`cursos`, `professores`, `disciplina_professores`, `turma_professores`) |
| 6 | `importar_grade.py` | Turmas e datas de aula a partir de `turno_turma` | `resposta_matriculas.json` | BD (`turmas`, `turma_aulas`, `disciplina_grade`, `disciplina_aulas`) |
| 7 | `importar_chamadas.py` | Última aula / histórico de chamadas por turma e por disciplina | `resposta_alunos_massa_intervalo.json`, `resposta_matriculas.json`, BD (coleta) | BD (`turma_ultima_aula`, `turma_chamadas`, `disciplina_ultima_aula`, `disciplina_chamadas`) |
| 8 | `gerar_alarmes.py` | Regras de risco de evasão (limites, janelas e mensagens vindos do portal), com a turma do aluno | BD (coleta + faltas + `configuracoes`), `config/consultas.json` | BD (`alarmes`) |
| 9 | `enviar_emails_chamadas.php` | Avisa chamadas em atraso (2+ dias), um e-mail por turma aos professores dela | BD + `.env` (`EMAIL_SEND`) | BD (`turma_chamada_emails`; `chamada_emails` sem turma) + e-mail SMTP |
| 10 | `enviar_emails_alarmes_alunos.php` | E-mails de acolhimento aos alunos (alarmes críticos) | BD + `.env` | BD (`alarme_emails`, `alarmes`) + SMTP |
| 11 | `enviar_emails_alarmes_staff.php` | Resumos a professores (os da turma do aluno) e coordenadores | BD + `.env` | BD (`alarme_emails.staff_avisado_em`) + SMTP |

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
| `integrados_anual.py` | Valida `integrados_anual_AAAA.json` e soma os meses encerrados à frequência dos integrados (usado por `analisar_frequencia.py`) |
| `consulta_integrados_anual.py` | Manual (Configuração da API → Buscar meses encerrados): meses encerrados do ano letivo dos integrados → `integrados_anual_AAAA.json`; log em `data/integrados_anual.log` |
| `gerar_perda_vaga.py` | Manual (tela Perda de vaga → Gerar análise): candidatos a perda de vaga (2 semestres anteriores) → BD; usa `resposta_matriculas_AAAA_S.json` como cache |
| `gerar_passe_livre.py` | Manual: ATIVO/FORMANDO do semestre atual × frequência **mensal** (`frequencia_periodo`) dos **3 semestres anteriores** → BD (`passe_livre_*`), sem JSON. Trancadas (`ausencias_especiais`) → `situacao`; % total do curso = valor da API. Só apaga os semestres que regenera. Opção `--semestres N` (padrão 3). |
| `importar_emails_professores.py` | Manual: e-mails dos professores a partir de CSV do Moodle (`data/Users.csv`) |
| `gerar_efeito_contatos.py` | Manual (tela Efeito dos contatos → Gerar análise): faltas antes e depois do contato, com as aulas e a última chamada da turma do aluno → BD (`efeito_contatos_*`) |

---

## Testes

Usam bancos SQLite temporários criados a partir do `schema.sql`; não tocam em `data/mapa.db`, não leem o `.env` e não chamam a API. Rodar na raiz do projeto:

```bash
python3 -m unittest discover -v python/tests   # regras por turma e frequência anual no pipeline
php tests/php/turmas_test.php                  # escopo do professor, críticas por turma, e-mail de chamada
php tests/php/frequencia_anual_test.php        # integrados nos ingressantes e no painel
```

| Arquivo | O que cobre |
|---------|-------------|
| `python/tests/test_turmas.py` | `id_turma` vindo das matrículas; turmas e professores da grade; chamada da turma (maior data entre os alunos) e herança do histórico quando a disciplina tem uma só turma no curso; alarme de 3 semanas com as aulas da turma; efeito dos contatos parando na última chamada da turma |
| `python/tests/test_consulta_alunos_massa.py` | Intervalo em massa dividido por status: URLs com `&status=ATIVO` e `&status=FORMANDO` do período inteiro, respostas juntadas no mesmo arquivo, arquivo anterior mantido se uma falhar |
| `python/tests/test_integrados_anual.py` | Busca dos meses encerrados (`consulta_integrados_anual.py`, API simulada): blocos de até 2 meses, só integrados, `&status=ATIVO`, nova tentativa mês a mês, versão anterior mantida quando um bloco falha |
| `python/tests/test_frequencia_anual.py` | Soma do ano letivo (`integrados_anual.py`): arredondamento como a API, disciplinas casadas pela matrícula no componente, arquivo ausente ou com buraco; só integrados ganham `frequencia_anual`; importação grava o ano e `frequencia_desde` (faltas por dia seguem do semestre); alarme com carência do início do ano e mensagem "no ano letivo" |
| `tests/php/turmas_test.php` | Turmas do usuário professor; alarmes, contagens e marcação restritos às turmas dele (sem turma identificada continua visível); disciplinas críticas uma linha por turma com os professores da turma; chave de envio do e-mail de chamada por turma, reconhecendo o envio antigo por disciplina |
| `tests/php/frequencia_anual_test.php` | Ingressantes do semestre mais integrados do ano (AAAA/0) com `frequencia_desde`; asterisco nos cursos integrados no gráfico por curso e nas disciplinas críticas; início do ano letivo da coleta |