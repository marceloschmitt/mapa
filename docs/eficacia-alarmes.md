# Eficácia dos alarmes — contexto da seção

Documento de contexto para quem for mexer na tela **Eficácia dos alarmes** (`/index.php/acompanhamento`).
Reúne a pergunta que a tela responde, as decisões tomadas (e por quê), de onde vêm os dados, como os
números são calculados, onde fica cada coisa no código e o que ainda falta. O histórico das versões
anteriores e a validação estatística ficam em [efeito-contatos.md](efeito-contatos.md).

---

## 1. A pergunta

> **O contato da equipe com alunos em alarme crítico faz eles voltarem a frequentar, além do que o
> e-mail automático já faz?**

E uma segunda: **vale insistir?** (o 2º contato da equipe ajuda quem não voltou com o 1º?)

A tela serve para **avaliar os contatos**, não os alarmes em si nem o MAPA como um todo.

## 2. Decisões de desenho

| Decisão | Escolha | Por quê |
|---|---|---|
| Quem entra | Só alunos com **alarme crítico real** (tabela `alarmes`, os mesmos da tela Alarmes) | É o público que recebe e-mail e que a equipe procura. Alarmes não críticos ficam de fora. |
| O que é "contato" | **Contato da equipe** = todo canal de `contatos_aluno` menos `email_automatico` (telefone, WhatsApp, presencial, e-mail manual, assistência, não informado) | O e-mail automático sai para quase todo aluno com alarme crítico no dia do alarme. "Recebeu contato × não recebeu" não separa ninguém, e quem fica sem nenhum contato é diferente (ex.: sem e-mail cadastrado). |
| Comparação | **Grupo "só e-mail automático"**: sem contato da equipe até o mesmo ponto depois do alarme | Sem grupo de comparação, qualquer antes/depois mostra melhora (regressão à média: o alarme dispara no pico de faltas). |
| Medida | **Duas análises separadas**: (1) presença antes × depois; (2) voltou a 75% | Uma mede o tamanho da mudança, a outra o resultado que importa (sair do risco). |
| Filtro de entrada | Nos dois grupos, só quem estava **abaixo de 75%** nas semanas antes | A equipe procura quem não voltou. Sem esse filtro, a comparação teria muitos alunos que já tinham voltado sozinhos. |
| 2º contato | Seção própria, alinhada no 2º contato da equipe, contra quem teve só um contato da equipe | Não existe recorte "1 contato × 2 ou mais": quem continua faltando é quem recebe mais contatos, e esse recorte pareceria sempre pior, mesmo que insistir funcione. |
| Recortes | Tipo de contato, conseguiu falar?, tipo de alarme, rapidez, ingresso, auxílio estudantil, curso | Para saber **qual** contato funciona e **para quem**. |
| "Antes do MAPA" | **Removido** | Comparar com semestres anteriores misturava muitas outras mudanças e não respondia a pergunta. `gerar_referencia_historica.py` e `referencia_historica_alunos` continuam no projeto, sem uso na tela. |
| Tela antiga "Efeito dos contatos" | **Descontinuada**: `/efeito-contatos` redireciona para `/acompanhamento` | A nova tela cobre tudo o que ela fazia. |
| "Precisam de nova abordagem" | **Fica na tela de eficácia** por enquanto | É a ação prática que sai da análise. Pode virar tela própria depois. |

Se o e-mail automático estiver desligado em produção, nada quebra: o grupo de comparação vira
simplesmente "sem contato da equipe".

## 3. De onde vêm os dados

- **Frequência e alarmes:** coletas do SIGAA (`coletas`, `turma_aulas`, faltas etc.) e `alarmes`,
  gerados por `gerar_alarmes.py`. A data do alarme é o dia da coleta em que o 1º alarme crítico do
  aluno/curso apareceu (só coletas completas). No mesmo dia, o tipo segue a prioridade
  `faltas_4dias` → `faltas_3semanas` → `percentual_baixo` (`PRIORIDADE_TIPO`).
- **Contatos:** `contatos_aluno` (registro permanente). Entram o e-mail automático (canal
  `email_automatico`) e os contatos que a equipe registra na tela Alarmes ou no modal "Registrar
  contato" desta tela, com canal, motivo, "conseguiu falar?" e observação.
  **A coleta do SIGAA não traz contatos:** eles só existem no servidor de produção, onde os e-mails
  saem e a equipe trabalha.
- **Consequência para o ambiente local:** o banco real local (`data/mapa.db`) não tem contatos, e com
  uma coleta só não há semanas "depois". Para ver a tela com dados, use o banco de demonstração
  (`DB_PATH=data/demo.db` no `.env`, login `admin` / `demo123`), que tem contatos e séries
  fictícios. **Gerar análise no demo apaga as séries fictícias** (o script recalcula a partir das
  coletas).

## 4. Como a análise é gerada

Botão **Gerar análise** (só administrador) → `POST /acompanhamento/gerar` → roda
`python/gerar_efeito_contatos.py` (parâmetros `--semanas`, padrão 2, e `--min-aulas`, padrão 3).

O script grava uma execução e, para cada aluno/curso e referência, uma **série semanal**:

- semana −1 = os 7 dias antes da referência; +1 = os 7 dias depois; o dia da referência não entra;
- de 6 semanas antes (`SEMANAS_ANTES`) até a data dos dados; semanas sem aula não são gravadas;
- aulas da turma do aluno até a última chamada lançada; sem turma, a grade da disciplina.

Grupos gravados em `efeito_contatos_series` (`grupo` × `referencia`):

| Série | Grupo | Data de referência |
|---|---|---|
| `contatado` / `primeiro` | teve contato da equipe | dia do 1º contato da equipe |
| `comparacao` / `primeiro` | sem contato da equipe até `intervalo_equipe` dias depois do alarme | alarme + `intervalo_equipe` |
| `contatado` / `segundo` | teve 2º contato da equipe | dia do 2º contato da equipe |
| `comparacao` / `segundo` | só um contato da equipe até `intervalo_segundo` dias depois dele | 1º contato + `intervalo_segundo` |

- `intervalo_equipe` = mediana de dias do alarme ao 1º contato da equipe (padrão 3 sem dados).
- `intervalo_segundo` = mediana de dias entre o 1º e o 2º contato da equipe (padrão 7).
- Quem recebe contato da equipe depois sai da comparação a partir da semana do contato.
- Cada série guarda também `alarme_tipo`, `dias_ate_contato`, `falou`, `canais`, `ingressante` e
  `auxilio`, usados nos recortes.

Como a série é semanal, qualquer janela (2 semanas, tudo até o corte) vira uma soma na tela, sem
rodar o Python de novo.

## 5. O que a tela mostra

1. **Topo** (`AcompanhamentoRepository::cobertura()`, lido ao vivo de `contatos_aluno`, então
   reflete contatos novos sem gerar a análise): alunos com alarme crítico (e quantos sem nenhum
   contato), quantos receberam o e-mail automático, quantos receberam contato da equipe (e dois ou
   mais) e a mediana de dias do alarme ao 1º contato da equipe.
2. **Contato da equipe: presença antes e depois.** Presença média nas 2 semanas antes e nas 2 depois,
   quantos melhoraram, e o **efeito do contato da equipe** = variação do grupo com contato menos a
   do grupo "só e-mail", em p.p., com margem de 95% (t de Student). Só aparece com pelo menos
   10 analisados por grupo (`EfeitoContatosRepository::MIN_ALUNOS`). "Analisado" = pelo menos
   3 aulas antes e depois.
3. **Contato da equipe: voltaram a frequentar.** % que chegou a 75% depois, nos dois grupos;
   diferença com margem de 95% (z de duas proporções, `AcompanhamentoRepository::comparar()`,
   mínimo de 20 por grupo). Selo: melhor / pior / incerto / poucos alunos.
4. **Vale insistir? O 2º contato da equipe.** As mesmas duas medidas, nas séries `segundo`.
5. **Presença semana a semana.** Curva dos dois grupos (só quem estava abaixo de 75% antes), de
   6 semanas antes a até 4 depois, com a linha do contato e a de 75%.
6. **Por recorte (abas).** Tipo de contato, conseguiu falar? e rapidez comparam com **todo** o grupo
   "só e-mail" (esses atributos não existem para quem não teve contato). Tipo de alarme, ingresso,
   auxílio e curso comparam com a parte do grupo "só e-mail" **do mesmo recorte**.
   Rapidez: antes do alarme, até 3 dias, até 7 dias, mais de 7 dias.
7. **Precisam de nova abordagem.** Alunos contatados há 14 dias ou mais
   (`DIAS_NOVA_ABORDAGEM`) que não voltaram a 75% desde o último contato; prioridade alta abaixo de
   50%. Paginada (25/50/100), com filtros, exportação CSV (admin e perfil geral) e modal para
   registrar contato.
8. **Como ler.** Explicação curta dos números para a equipe.

Filtros do topo: curso, tipo de alarme, ingresso e auxílio estudantil. Eles valem para todas as
seções.

**Escopo por perfil:** admin e perfil geral veem tudo (com seletor de curso); coordenador, os cursos
dele; professor, os alunos das disciplinas e turmas dele
(`AcompanhamentoRepository::sqlEscopoProfessor()`). O registro de contato confere se o aluno está no
escopo (`alunoNoEscopo()`).

## 6. Mapa do código

| Parte | Arquivo |
|---|---|
| Rotas | `src/routes.php`: `GET /acompanhamento`, `POST /acompanhamento/contato`, `GET /acompanhamento/exportar`, `POST /acompanhamento/gerar` |
| Controller | `src/Controllers/AcompanhamentoController.php` (escopo, filtros, registro de contato, CSV, gerar) |
| Redirecionamento da tela antiga | `src/Controllers/EfeitoContatosController.php` |
| Análises (efeito, voltou, recortes, curva) | `src/Models/EfeitoContatosRepository.php`: `avaliar()`, `serieSemanal()`, `faixaRapidez()` |
| Topo, lista, comparação de proporções, escopo | `src/Models/AcompanhamentoRepository.php`: `cobertura()`, `novaAbordagem()`, `comparar()`, `alunoNoEscopo()`, `sqlEscopoProfessor()` |
| Validação de contato | `src/Models/ContatoRepository.php` |
| View | `src/Views/acompanhamento/index.php` (Chart.js 4.4.1 + plugin de anotação) |
| Estilos | `assets/css/mapa.css` (`.selo-comparacao`, `.selo-melhor`, `.selo-pior`, `.selo-incerto`) |
| Menu | `src/Views/layouts/main.php` (item "Eficácia dos alarmes") |
| Geração | `python/gerar_efeito_contatos.py` |
| Esquema | `config/schema.sql`; migrações em `python/db.py` (`_ensure_column`) e `src/Core/Database.php` (`ensureColumn`) |

Tabelas: `efeito_contatos_execucoes` (uma por geração, com `data_corte`, `intervalo_equipe`,
`intervalo_segundo`), `efeito_contatos_series`, `efeito_contatos_semanas` (aulas e faltas por
semana), `acompanhamento_alunos` (base da lista e do topo), `contatos_aluno`.

## 7. Testes

```bash
php tests/php/efeito_contatos_test.php        # análises com contatos fictícios, 2º contato, conseguiu falar, curva
php tests/php/acompanhamento_tela_test.php    # topo (cobertura), lista, escopo, CSV, página por perfil
php tests/php/acompanhamento_test.php         # comparação de proporções
cd python && python -m unittest tests/test_turmas.py  # alarmes críticos reais, séries da equipe e da comparação
```

No Windows com XAMPP, o PHP fica em `C:\xampp\php\php.exe` (não está no PATH).

## 8. Cuidados na leitura

- **A comparação não é sorteada.** Quem recebe contato da equipe pode ser diferente de quem não
  recebe. Antes de confiar no efeito, conferir na curva se os dois grupos estavam próximos
  **antes** do contato.
- **Poucos contatos da equipe = poucos alunos.** Se a equipe registrar só dezenas de contatos por
  semestre, a tela mostra "poucos alunos" em vez de conclusões. É o comportamento esperado.
- **Data do contato manual** = dia em que foi registrado, não necessariamente o dia da conversa.
- **Dados de demonstração:** o `demo.db` tem no máximo um contato da equipe por aluno em
  `contatos_aluno`, então o topo mostra "0 com dois ou mais" enquanto a seção do 2º contato tem
  alunos (as séries foram geradas à parte). Com dados reais os dois batem.

## 9. Pendências

- Gerar a análise em produção (ou numa cópia do banco de produção, fora do Git) e conferir o tamanho
  dos grupos e as curvas antes do contato. Ainda não se sabe quantos alunos caem em cada grupo.
- T8 do plano: fechar e reabrir casos automaticamente (hoje o alarme tratado não reabre no semestre,
  então recaídas depois do contato ficam invisíveis; ver [efeito-contatos.md](efeito-contatos.md)).
- Fase 4 do plano.
- Talvez levar "Precisam de nova abordagem" para uma tela própria.
