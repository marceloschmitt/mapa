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
   em `AAAA-MM-DD`. Um registro por vínculo, com `status_discente`, totais,
   disciplinas e `ausencias_especiais`. São duas consultas do período inteiro,
   uma com `&status=ATIVO` e outra com `&status=FORMANDO` (os únicos status que
   entram na frequência), juntadas em `resposta_alunos_massa_intervalo.json`,
   lido por `analisar_frequencia.py` e `importar_chamadas.py`. Sem o filtro
   (todos os status) a resposta passava dos 60 s do Cloudflare do IFRS (HTTP
   504). Em 09/10/2026, de 25/07 a 31/12: ATIVO em 40 s, FORMANDO em 4 s. Se uma
   consulta passar de 45 s, o log avisa que está perto do limite; se o ATIVO
   voltar a dar 504 no fim do semestre, a saída é quebrá-lo por período e somar
   as contagens.

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

Também lê `resposta_matriculas.json` (gerado no passo anterior) para
identificar a turma (`id_turma`) do aluno em cada disciplina, casando
`id_discente` + código da disciplina (`indexar_turmas`). Sem o arquivo, as
disciplinas saem sem turma e as etapas seguintes usam os dados por
disciplina/curso.

Integrados (`curso_nivel = N`): se `integrados_anual_AAAA.json` cobrir os
meses encerrados do ano letivo (ver `integrados_anual.py`), cada registro
integrado ganha `frequencia_anual` com o intervalo atual somado a esses meses.
Os demais campos continuam sendo só do intervalo da coleta. Sem o arquivo, ou
com o período incompleto, imprime um aviso e segue com o semestre.

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
`alunos`, `frequencia_curso`, `frequencia_disciplina` (com `id_turma`),
`faltas_dia`, entre outras. O JSON permanece como cache daquela coleta.

Registros com `frequencia_anual` (integrados) gravam os números do ano letivo
em `frequencia_curso` e `frequencia_disciplina`, com
`frequencia_curso.frequencia_desde` = início do ano. `faltas_dia` continua só
com as faltas do intervalo da coleta.

### `importar_trancados.py`
Lê `resposta_alunos_massa_cadastro.json` (consulta em massa), seleciona os
cursos com `status_discente` `TRANCADO` / `TRANC. AUTOMÁTICO` e grava na tabela
`alunos_trancados` (última coleta).
Se o arquivo não existir, não grava nada. Alunos
trancados não entram nas contas de frequência nem alarmes; ficam disponíveis só
para consulta no portal (tela Trancados).

### `importar_professores.py`
A partir de `resposta_matriculas.json`, popula `cursos` (todos os cursos da
matrícula, não só os com frequência), `professores`, o vínculo por código
`disciplina_professores` e o vínculo por turma `turma_professores`
(substituído a cada execução; cria a linha mínima em `turmas` se a turma
ainda não existir).

### `importar_grade.py`
Lê `resposta_matriculas.json`, expande os códigos de `turno_turma` (via
`turno_turma.py`) em datas efetivas de aula (segunda a sábado, sem feriados)
e popula:
- `turmas` + `turma_aulas`: grade de cada turma (`id_turma` do SIGAA);
- `disciplina_grade` + `disciplina_aulas`: união das turmas por
  disciplina/curso, usada para nome/semestre e quando o aluno não tem turma
  identificada.

### `importar_chamadas.py`
A partir de `resposta_alunos_massa_intervalo.json` (consulta em massa), grava
a data da última aula ministrada:
- por turma: a maior `ultima_aula_ministrada` entre os alunos da turma
  (snapshot por curso em `turma_ultima_aula`, histórico em `turma_chamadas`).
  A turma de cada aluno vem de `resposta_matriculas.json`. Turma nova que é a
  única da disciplina no curso herda o histórico de `disciplina_chamadas`;
- por disciplina/curso, como antes (`disciplina_ultima_aula`,
  `disciplina_chamadas`), usado quando a turma não é identificada.

Vínculos sem curso (alunos especiais) ficam de fora.

### `gerar_alarmes.py`
Gera os alarmes de risco de evasão a partir dos dados já no SQLite. Aplica
três regras — percentual de frequência abaixo do limite (após período de
carência), sequência de dias úteis com falta e semanas consecutivas faltando
a todas as aulas da disciplina — cujos parâmetros (limites, janelas, textos)
vêm de `config_alarmes.py`/tabela `configuracoes` (tela Configurações →
Alarmes), com os valores antigos como padrão. Grava em `alarmes`, com a
turma do aluno (`id_turma`).

A carência e as semanas consecutivas usam as aulas da turma do aluno
(`turma_aulas`); sem turma identificada, as da disciplina no curso
(`disciplina_aulas`).

Integrados com frequência anual (`frequencia_curso.frequencia_desde`): o
percentual é o do ano letivo, a carência conta do início do ano e a mensagem
termina com ", no ano letivo desde DD/MM/AAAA" (vale também para os e-mails).
As regras de faltas consecutivas seguem com as faltas do semestre.

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

### `integrados_anual.py`
Frequência anual dos integrados. Lê `integrados_anual_AAAA.json` e só o aceita
se os blocos cobrirem, sem buracos, de `integrados_data_inicio` até a véspera
de `frequencia_data_inicial` (`carregar`). Soma os meses encerrados ao intervalo
atual (`somar`): disciplinas casadas por `id_matricula_componente` (ou código),
só as que o aluno tem no intervalo atual; o total do curso soma os totais de
todos os períodos. Percentuais como a API: presenças / horários × 100,
arredondando meio para cima (2 casas na disciplina, inteiro no curso). Usado por
`analisar_frequencia.py`.

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

### `consulta_integrados_anual.py`
Busca na API os meses encerrados do ano letivo dos integrados
(`curso_nivel = N`): de `integrados_data_inicio` até a véspera de
`frequencia_data_inicial`, na URL de frequência por intervalo com
`&status=ATIVO`, em blocos de até 2 meses (limite de 60 s do Cloudflare). Se
um bloco falhar, tenta mês a mês; se ainda faltar algum mês, mantém a versão
anterior do bloco (se houver) e registra o erro. Guarda só os vínculos
integrados em `integrados_anual_AAAA.json`. Acionado pela tela Configuração da
API → "Buscar meses encerrados" (somente administradores; log em
`data/integrados_anual.log`). Rodar a cada troca de semestre dos integrados.

### `gerar_efeito_contatos.py`
Compara a taxa de faltas dos alunos contatados no semestre (e-mail automático
em `alarme_emails` e contatos registrados na tela de alarmes) antes e depois
do contato. Conta as aulas da turma do aluno (`turma_aulas`) até a última
chamada da turma (`turma_ultima_aula`); sem turma identificada, usa a grade e
a última chamada da disciplina no curso. Só lê o banco e grava em
`efeito_contatos_*`. Acionado pela tela Efeito dos contatos → Gerar análise
(opções `--janela` e `--min-aulas`).

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
| `importar_professores.py` | Pipeline (5) | Grava cursos, professores e vínculos por disciplina e por turma |
| `importar_grade.py` | Pipeline (6) | Grava turmas, grade e datas de aula |
| `importar_chamadas.py` | Pipeline (7) | Grava última aula e histórico de chamadas por turma e por disciplina |
| `gerar_alarmes.py` | Pipeline (8) | Calcula e grava alarmes de risco de evasão (com a turma) |
| `paths.py` | Módulo de apoio | Caminhos padronizados do projeto |
| `db.py` | Módulo de apoio | Conexão SQLite e schema |
| `api_auth.py` | Módulo de apoio | Token OAuth e URLs da API |
| `config_consultas.py` | Módulo de apoio | Datas de consulta (BD ou `consultas.json`) |
| `config_alarmes.py` | Módulo de apoio | Parâmetros das regras de alarme |
| `status_aluno.py` | Módulo de apoio | Regras de status ATIVO/FORMANDO/trancado |
| `periodo_letivo.py` | Módulo de apoio | Períodos AAAA/S: validação e semestres anteriores |
| `turno_turma.py` | Módulo de apoio | Expande intervalos de aula do SIGAA |
| `ausencias_especiais.py` | Módulo de apoio | Trancamento/cancelamento de disciplina (API) |
| `integrados_anual.py` | Módulo de apoio | Soma os meses encerrados à frequência dos integrados |
| `consulta_integrados_anual.py` | Manual (tela Configuração da API) | Busca os meses encerrados do ano letivo dos integrados |
| `gerar_perda_vaga.py` | Manual (tela Perda de vaga) | Candidatos a perda de vaga |
| `gerar_passe_livre.py` | Manual | Passe livre a partir da frequência mensal |
| `gerar_efeito_contatos.py` | Manual (tela Efeito dos contatos) | Faltas antes e depois do contato com o aluno |
| `importar_emails_professores.py` | Manual | Atualiza e-mails de professores via CSV |
