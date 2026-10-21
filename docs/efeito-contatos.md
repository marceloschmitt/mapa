# Efeito dos contatos — como medimos a eficácia dos alertas

Tela: **Acompanhamento → Efeito dos contatos** (`/index.php/efeito-contatos`).
Geração: botão **Gerar análise** (administrador), que roda `python/gerar_efeito_contatos.py`.

Pergunta que a tela responde: **depois de receber contato, o aluno volta a frequentar mais do que voltaria sem o contato?**

---

## Por que a versão anterior foi refeita

A tela comparava a taxa de faltas antes e depois do contato e mostrava "quantos melhoraram" e "quanto melhoraram". Três problemas faziam ela mostrar melhora mesmo sem efeito nenhum:

1. **Média só de quem melhorou.** "Faltas antes → depois" e "Queda" somavam apenas os alunos que melhoraram. A queda era sempre positiva por construção.
2. **Regressão à média.** O alarme dispara no pico de faltas. Depois do pico, as faltas tendem a cair sozinhas. Qualquer antes/depois de alunos escolhidos por estarem mal mostra "melhora".
3. **Sem grupo de comparação.** Não havia como separar o efeito do contato da queda natural.

Teste com o banco local (2 coletas, set/2026, **nenhum aluno contatado**), fingindo que todo aluno com alarme foi contatado no dia do alarme:

| Grupo (ninguém contatado) | "Melhoraram" | O que a tela antiga mostraria | Média real de todos |
|---|---|---|---|
| Alarme crítico | 58% | 51,8% → 31,9% (−19,9 p.p.) | 47,6% → 41,3% (−6,3) |
| Alarme alto | 43% | 22,9% → 9,6% (−13,3 p.p.) | 15,6% → 19,9% (piorou) |
| Nunca teve alarme | 15% | 18,7% → 3,9% (−14,8 p.p.) | 3,2% → 5,2% (piorou) |

---

## Como funciona agora

### Série semanal

Para cada aluno/curso, o script grava aulas e faltas **por semana em relação a uma data de referência**:

- semana −1 = os 7 dias antes da referência; semana +1 = os 7 dias depois; o dia da referência não entra;
- de 6 semanas antes até a data dos dados (`SEMANAS_ANTES = 6`); semanas sem aula não são gravadas;
- aulas da turma do aluno (`turma_aulas`) até a última chamada lançada (`turma_ultima_aula`); sem turma, a grade e a última chamada da disciplina.

Tabelas: `efeito_contatos_execucoes`, `efeito_contatos_series` (uma série por aluno/curso e referência) e `efeito_contatos_semanas` (aulas e faltas por semana). Qualquer janela (2 semanas, até o corte etc.) vira uma soma na tela, sem rodar o Python de novo. A migração (PHP e Python) apaga a análise no formato antigo (`efeito_contatos_alunos`): depois do deploy, é preciso clicar em **Gerar análise**.

### Grupos

- **Contatados.** Um contato = um dia com algum contato (e-mail automático em `alarme_emails` ou contato registrado na tela de alarmes). Série alinhada no **1º contato** e, para quem teve dois ou mais, também no **2º contato**.
- **Comparação.** Alunos com **alarme crítico que ainda não tinham recebido contato**, alinhados no dia em que o alarme crítico apareceu (data da coleta). Quem recebe contato depois sai da comparação a partir da semana do contato. Quem foi contatado antes do alarme crítico não entra.

O 2º contato não tem comparação: ele costuma vir de um novo alarme crítico, no pico de faltas.

### Números da tela

Comparação padrão: as **2 semanas antes** contra as **2 semanas seguintes** ou contra **tudo até a data dos dados** (filtro). Só entram na conta ("analisados") os alunos com pelo menos **3 aulas** antes e depois.

- **Faltas antes → depois:** média das taxas de **todos** os analisados.
- **Melhoraram · iguais · pioraram:** distribuição dos analisados.
- **Frequência ≥ limite do alarme** (75% por padrão): % dos analisados com no máximo 25% de faltas, antes e depois.
- **Efeito líquido:** variação dos contatados menos a variação da comparação, em p.p. de faltas. Negativo (verde) = os contatados passaram a faltar menos que a comparação. Vem com **intervalo de 95%** (Welch, t de Student pelo menor grupo, arredondado para cima). Se o intervalo inclui o zero, a tela diz que a diferença ainda não se distingue do acaso. Só aparece com pelo menos 10 analisados em cada grupo (`EfeitoContatosRepository::MIN_ALUNOS`).

O gráfico mostra a taxa média de faltas por semana dos dois grupos, só com os alunos analisados (semanas com menos de 10 alunos ficam de fora), com as faixas "antes" e "depois", a linha do contato e a linha do limite de frequência. A tabela "Por canal do contato" repete os números por canal do dia do contato.

Parâmetros do script: `--semanas` (padrão 2) e `--min-aulas` (padrão 3).

---

## Validação

**Simulação com o banco local** (contatos sorteados entre os 491 alunos com alarme crítico na 1ª coleta):

| Cenário | Contatados | Comparação | Efeito líquido |
|---|---|---|---|
| Contato sem efeito (placebo) | −7,2 p.p. | −11,3 p.p. | +4,1 (0,0 a +8,2): "não se distingue do acaso" |
| Contato que corta 40% das faltas | −23,0 p.p. | −11,3 p.p. | −11,7 (−15,8 a −7,5): detectado |

Em **40 sorteios** sem efeito, a média do efeito líquido foi **+0,09 p.p.** e só **1 de 40** intervalos excluiu o zero (esperado ≈ 2). O intervalo não inventa efeito.

**Dados reais** (coleta local de 20/10/2026, sem contatos): 567 alunos com alarme crítico **e sem contato nenhum** subiram até ~52% de faltas antes do alarme, caíram para ~43% duas semanas depois e voltaram a 45–49% em seguida. A queda logo após o alarme (−6,4 p.p. nas 2 semanas) acontece sozinha e é passageira. É ela que o efeito líquido desconta.

**Testes:** `python/tests/test_turmas.py` (semanas em torno da referência, quem entra como contatado ou comparação, aulas até a última chamada da turma) e `tests/php/efeito_contatos_test.php` (médias de todos, distribuição, limite de frequência, canais, janela × até o corte, efeito líquido com intervalo, curva só com analisados).

---

## Cuidados na leitura

- **A comparação não é sorteada.** Quem não foi contatado pode ser diferente de quem foi (ex.: aluno sem e-mail cadastrado). Antes de confiar no efeito líquido, conferir no gráfico se as duas curvas estavam próximas **antes** do contato.
- **Os contatados incluem contatos manuais em alarmes "alto"**, e a comparação só tem alarme crítico. Se as curvas antes do contato ficarem muito afastadas, filtrar por severidade (próximo passo).
- **Tamanho da comparação.** Se em produção o e-mail sai no mesmo dia em que o alarme fica crítico, sobram poucos alunos sem contato. Com menos de 10 analisados, o efeito líquido não aparece.
- **Data do contato manual** = dia em que foi registrado na tela de alarmes, não necessariamente o dia da conversa.
- **O banco local não tem contatos.** E-mails e contatos registrados só existem em produção. Para avaliar a eficácia, gerar a análise em produção ou numa cópia do banco de produção (dados pessoais: fora do Git, apagar depois).

---

## Achados paralelos (fora da tela, mas afetam a leitura)

- **Alarme tratado não reabre no semestre.** `gerar_alarmes.py` preserva o alarme com contato (mesmo aluno/curso/disciplina/tipo) em todas as coletas seguintes. `faltas_4dias` é por curso, então, depois de tratado, não dispara de novo para o aluno no semestre. Recaída depois do contato fica invisível na operação, e o 2º contato só acontece com um alarme novo.
- **Histórico de contatos e retenção.** `alarme_emails` é apagado em cascata com a coleta (`ON DELETE CASCADE`). Se a poda de coletas antigas (A5 em [analise-tecnica.md](analise-tecnica.md)) for implementada, o histórico de e-mails some. É preciso um registro permanente de contatos antes.
- **Volume de alarmes.** No banco local de set/2026, ~64% dos alunos tinham algum alarme. A capacidade de contato é o gargalo, e a eficácia por tipo de alarme ajuda a priorizar.
- **API de frequência em massa no limite.** A consulta por intervalo passa de 60 s (504 do Cloudflare) quando pede todos os status. A divisão por status (`ATIVO`/`FORMANDO`) já está na `main`, mas o custo fixo (~35–45 s por consulta) continua perto do limite. Se voltar a falhar, dá para dividir também por datas. Foi testado: dois pedaços de 1 semana juntados deram **zero diferenças** em relação à consulta direta de 2 semanas (6.720 vínculos, 8.786 disciplinas). Regras da junção:
  - somar horários, ausências e presenças (total, justificadas e por disciplina); juntar as datas de falta sem repetir;
  - percentual do total: inteiro arredondado "meio para cima"; por disciplina: inteiro quando exato, senão 2 casas; nulo sem horários;
  - `carga_horaria` da disciplina: a maior entre os pedaços (vem 0 no pedaço sem aula); `carga_horaria_total` = soma das disciplinas;
  - `ultima_aula_ministrada`: a mais recente;
  - `ausencias_especiais`: `trancamento_cancelamento` com um grupo por disciplina; os demais tipos num grupo só; juntar sem repetir.

---

## Próximos passos

1. **Gerar a análise com dados de produção** e conferir o tamanho da comparação e as curvas antes do contato.
2. **Eficácia por tipo de alarme e filtros** (tipo, severidade, curso → disciplina/turma). Os dados existem: `alarme_emails.alarme_ids` e `alarmes.tipo`.
3. **Visão por aluno:** contatados que não voltaram, recaídas e trancamentos, com mini linha do tempo.
4. **Resultado no fim do semestre e precisão dos alarmes:** % que termina com frequência no limite, tranca ou evade, por tipo de alarme, comparado com quem não teve alarme.
5. Se a comparação não for suficiente: aproveitar o corte de 50% (crítico recebe e-mail, alto não) ou atrasar o e-mail em uma semana para uma parte sorteada (precisa de aval institucional).
