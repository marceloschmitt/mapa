#!/usr/bin/env python3
"""Efeito dos contatos: faltas semana a semana em torno do contato com o aluno (manual).

So alunos/curso do semestre atual. Um contato = um dia com algum contato,
qualquer que seja o canal (e-mail automatico em alarme_emails; contatos
registrados na tela de alarmes). Para cada aluno grava uma serie semanal de
aulas e faltas em torno de uma data de referencia:

- contatados: o dia do primeiro contato (referencia "primeiro") e, com dois
  ou mais contatos, tambem o do segundo ("segundo");
- comparacao: alunos com alarme critico que ainda nao tinham recebido
  contato, alinhados no dia em que o alarme critico apareceu ("primeiro").
  Se o contato vier depois, a serie para na ultima semana inteira antes dele.

O alarme dispara no pico de faltas, e depois do pico as faltas tendem a cair
sozinhas: a comparacao mostra quanto da queda dos contatados aconteceria de
qualquer forma.

Semana -1 = os 7 dias antes da referencia; semana 1 = os 7 dias seguintes (o
dia da referencia nao entra). A tela soma as semanas que quiser (-N a -1
contra 1 a N, ou ate a data de corte).

As aulas vem da turma do aluno (turma_aulas), limitadas a ultima chamada
registrada da turma (turma_ultima_aula): dias sem chamada lancada nao contam,
senao pareceriam presenca e a taxa "depois" ficaria artificialmente baixa.
Sem turma identificada (ou sem dados dela), usa a grade e a ultima chamada
da disciplina no curso (disciplina_aulas, disciplina_ultima_aula).

Nao faz parte da coleta: le so o banco e pode levar alguns minutos.

Uso:
    python3 gerar_efeito_contatos.py
    python3 gerar_efeito_contatos.py --semanas 3 --min-aulas 4
"""

from __future__ import annotations

import argparse
import sys
import time
from bisect import bisect_left, bisect_right
from collections import defaultdict
from datetime import date, timedelta
from statistics import mean
from typing import Any

from db import conectar, fechar

CANAL_AUTOMATICO = "email_automatico"
CANAL_NAO_INFORMADO = "nao_informado"
REFERENCIAS = ("primeiro", "segundo")
GRUPOS = ("contatado", "comparacao")
# Semanas gravadas antes da referencia (a tela mostra a tendencia anterior).
SEMANAS_ANTES = 6

Par = tuple[int, int]


def data_local(texto: Any) -> date | None:
    """Data (AAAA-MM-DD) de um datetime gravado no banco."""
    valor = str(texto or "").strip()
    try:
        return date.fromisoformat(valor[:10])
    except ValueError:
        return None


def semestre_atual(cursor: Any) -> dict[str, Any] | None:
    """Coletas do semestre da ultima coleta e a data de corte de cada uma."""
    ultima = cursor.execute(
        """
        SELECT id, data_inicial, data_final
        FROM coletas
        ORDER BY id DESC
        LIMIT 1
        """
    ).fetchone()
    if ultima is None:
        return None

    primeira = cursor.execute(
        "SELECT MIN(id) FROM coletas WHERE data_final = ?",
        (ultima["data_final"],),
    ).fetchone()[0]
    primeira = int(primeira) if primeira is not None else int(ultima["id"])

    cortes: dict[int, date] = {}
    for row in cursor.execute(
        "SELECT id, executada_em, data_referencia FROM coletas WHERE id >= ?",
        (primeira,),
    ):
        referencia = data_local(row["data_referencia"])
        if referencia is None:
            executada = data_local(row["executada_em"])
            if executada is None:
                continue
            referencia = executada - timedelta(days=1)
        cortes[int(row["id"])] = referencia

    inicio = data_local(ultima["data_inicial"])
    if inicio is None:
        inicio = min(cortes.values()) if cortes else date.today()

    return {
        "ultima_id": int(ultima["id"]),
        "primeira_id": primeira,
        "inicio": inicio,
        "cortes": cortes,
    }


def carregar_contatos(cursor: Any, primeira_id: int) -> dict[Par, dict[date, set[str]]]:
    """Dias de contato de cada aluno/curso, com os canais usados em cada dia."""
    contatos: dict[Par, dict[date, set[str]]] = defaultdict(lambda: defaultdict(set))

    def registrar(aluno_id: Any, curso_id: Any, canal: str, quando: Any) -> None:
        dia = data_local(quando)
        if dia is not None:
            contatos[(int(aluno_id), int(curso_id))][dia].add(canal)

    for row in cursor.execute(
        """
        SELECT aluno_id, curso_id, enviado_em
        FROM alarme_emails
        WHERE coleta_id >= ?
        """,
        (primeira_id,),
    ).fetchall():
        registrar(row[0], row[1], CANAL_AUTOMATICO, row[2])

    for row in cursor.execute(
        """
        SELECT DISTINCT aluno_id, curso_id,
               COALESCE(NULLIF(TRIM(contato_tipo), ''), ?) AS canal,
               substr(visualizado_em, 1, 10)
        FROM alarmes
        WHERE coleta_id >= ?
          AND visualizado = 1
          AND visualizado_em IS NOT NULL
        """,
        (CANAL_NAO_INFORMADO, primeira_id),
    ).fetchall():
        registrar(row[0], row[1], str(row[2]), row[3])

    return contatos


def carregar_alarmes_criticos(cursor: Any, primeira_id: int) -> dict[Par, date]:
    """Dia da coleta em que cada aluno/curso teve o primeiro alarme critico no semestre."""
    saida: dict[Par, date] = {}
    for row in cursor.execute(
        """
        SELECT a.aluno_id, a.curso_id, MIN(c.executada_em)
        FROM alarmes a
        JOIN coletas c ON c.id = a.coleta_id
        WHERE a.coleta_id >= ?
          AND a.severidade = 'critico'
        GROUP BY a.aluno_id, a.curso_id
        """,
        (primeira_id,),
    ).fetchall():
        dia = data_local(row[2])
        if dia is not None:
            saida[(int(row[0]), int(row[1]))] = dia
    return saida


def semanas_da_serie(
    referencia: date,
    corte: date,
    ate: date | None = None,
) -> list[tuple[int, str, str]]:
    """(semana, inicio, fim) de SEMANAS_ANTES semanas antes da referencia ate o corte.

    Depois da referencia, com `ate`, so semanas que terminam ate esse dia.
    """
    semanas: list[tuple[int, str, str]] = []
    seis_dias = timedelta(days=6)
    for semana in range(-SEMANAS_ANTES, 0):
        inicio = referencia + timedelta(days=7 * semana)
        semanas.append((semana, inicio.isoformat(), (inicio + seis_dias).isoformat()))

    semana = 1
    inicio = referencia + timedelta(days=1)
    while inicio <= corte:
        fim = inicio + seis_dias
        if ate is not None and fim > ate:
            break
        semanas.append((semana, inicio.isoformat(), fim.isoformat()))
        semana += 1
        inicio += timedelta(days=7)
    return semanas


def montar_series(
    contatos: dict[Par, dict[date, set[str]]],
    alarmes_criticos: dict[Par, date],
    corte: date,
) -> list[dict[str, Any]]:
    """Series dos contatados (1o e 2o contato) e da comparacao.

    Comparacao = alarme critico sem contato ate o dia do alarme; quem recebe
    contato depois entra ate a semana anterior ao contato.
    """
    series: list[dict[str, Any]] = []

    def nova(par: Par, grupo: str, referencia: str, dia: date, total: int, canais: str,
             ate: date | None = None) -> None:
        series.append({
            "par": par,
            "grupo": grupo,
            "referencia": referencia,
            "data": dia,
            "total": total,
            "canais": canais,
            "semanas": semanas_da_serie(dia, corte, ate),
            "contagem": {},
        })

    for par, dias in contatos.items():
        datas = sorted(dias)
        for referencia, dia in zip(REFERENCIAS, datas):
            nova(par, "contatado", referencia, dia, len(datas), ",".join(sorted(dias[dia])))

    for par, alarme in alarmes_criticos.items():
        dias = contatos.get(par)
        primeiro = min(dias) if dias else None
        if primeiro is not None and primeiro <= alarme:
            continue
        ate = primeiro - timedelta(days=1) if primeiro is not None else None
        nova(par, "comparacao", "primeiro", alarme, 0, "", ate)

    return series


def carregar_aulas(cursor: Any, inicio: date) -> dict[tuple[str, int], list[str]]:
    """Datas de aula da grade por disciplina/curso (ordenadas)."""
    aulas: dict[tuple[str, int], list[str]] = defaultdict(list)
    for row in cursor.execute(
        """
        SELECT codigo_disciplina, curso_id, data_aula
        FROM disciplina_aulas
        WHERE data_aula >= ?
        ORDER BY codigo_disciplina, curso_id, data_aula
        """,
        (inicio.isoformat(),),
    ).fetchall():
        aulas[(str(row[0]), int(row[1]))].append(str(row[2]))
    return aulas


def carregar_aulas_turma(cursor: Any, inicio: date) -> dict[int, list[str]]:
    """Datas de aula de cada turma (ordenadas)."""
    aulas: dict[int, list[str]] = defaultdict(list)
    for row in cursor.execute(
        """
        SELECT id_turma, data_aula
        FROM turma_aulas
        WHERE data_aula >= ?
        ORDER BY id_turma, data_aula
        """,
        (inicio.isoformat(),),
    ).fetchall():
        aulas[int(row[0])].append(str(row[1]))
    return aulas


def carregar_ultimas_chamadas_turma(
    cursor: Any,
    primeira_id: int,
) -> dict[int, tuple[list[int], list[str]]]:
    """Ultima chamada registrada por turma, em cada coleta (ordem de coleta).

    turma_ultima_aula tem uma linha por curso da turma, todas com a mesma data.
    """
    saida: dict[int, tuple[list[int], list[str]]] = {}
    for row in cursor.execute(
        """
        SELECT id_turma, coleta_id, MAX(data_ultima_aula)
        FROM turma_ultima_aula
        WHERE coleta_id >= ?
          AND data_ultima_aula IS NOT NULL
          AND TRIM(data_ultima_aula) != ''
        GROUP BY id_turma, coleta_id
        ORDER BY id_turma, coleta_id
        """,
        (primeira_id,),
    ).fetchall():
        coletas, datas = saida.setdefault(int(row[0]), ([], []))
        coletas.append(int(row[1]))
        datas.append(str(row[2])[:10])
    return saida


def carregar_ultimas_chamadas(
    cursor: Any,
    primeira_id: int,
) -> dict[tuple[str, int], tuple[list[int], list[str]]]:
    """Ultima chamada registrada por disciplina, em cada coleta (ordem de coleta)."""
    saida: dict[tuple[str, int], tuple[list[int], list[str]]] = {}
    for row in cursor.execute(
        """
        SELECT codigo_disciplina, curso_id, coleta_id, data_ultima_aula
        FROM disciplina_ultima_aula
        WHERE coleta_id >= ?
          AND data_ultima_aula IS NOT NULL
          AND TRIM(data_ultima_aula) != ''
        ORDER BY codigo_disciplina, curso_id, coleta_id
        """,
        (primeira_id,),
    ).fetchall():
        chave = (str(row[0]), int(row[1]))
        coletas, datas = saida.setdefault(chave, ([], []))
        coletas.append(int(row[2]))
        datas.append(str(row[3])[:10])
    return saida


def ultima_chamada_ate(
    ultimas: dict[Any, tuple[list[int], list[str]]],
    chave: Any,
    coleta_id: int,
) -> str | None:
    """Ultima chamada da disciplina (ou turma) conhecida ate a coleta informada."""
    registro = ultimas.get(chave)
    if registro is None:
        return None
    coletas, datas = registro
    posicao = bisect_right(coletas, coleta_id) - 1
    if posicao < 0:
        return None
    return datas[posicao]


def contar_janela(
    datas_aula: list[str],
    faltas: set[str],
    inicio: str,
    fim: str,
) -> tuple[int, int]:
    """(aulas, faltas) no intervalo; falta em dia fora da grade conta como aula."""
    if fim < inicio:
        return 0, 0
    planejadas = bisect_right(datas_aula, fim) - bisect_left(datas_aula, inicio)
    faltas_janela = [d for d in faltas if inicio <= d <= fim]
    extras = 0
    for dia in faltas_janela:
        posicao = bisect_left(datas_aula, dia)
        if posicao >= len(datas_aula) or datas_aula[posicao] != dia:
            extras += 1
    return planejadas + extras, len(faltas_janela)


def calcular_semanas(
    cursor: Any,
    series: list[dict[str, Any]],
    semestre: dict[str, Any],
) -> int:
    """Preenche (aulas, faltas) de cada semana de cada serie (so semanas com aula).

    Usa a ultima coleta em que o aluno aparece (quem saiu do curso no meio do
    semestre continua na analise com os dados que havia).
    """
    primeira_id = int(semestre["primeira_id"])
    cortes: dict[int, date] = semestre["cortes"]

    ultima_coleta: dict[Par, int] = {}
    for row in cursor.execute(
        """
        SELECT aluno_id, curso_id, MAX(coleta_id)
        FROM frequencia_curso
        WHERE coleta_id >= ?
        GROUP BY aluno_id, curso_id
        """,
        (primeira_id,),
    ).fetchall():
        ultima_coleta[(int(row[0]), int(row[1]))] = int(row[2])

    series_por_par: dict[Par, list[dict[str, Any]]] = defaultdict(list)
    for serie in series:
        series_por_par[serie["par"]].append(serie)

    pares_por_coleta: dict[int, set[Par]] = defaultdict(set)
    for par in series_por_par:
        coleta_id = ultima_coleta.get(par)
        if coleta_id is not None:
            pares_por_coleta[coleta_id].add(par)

    aulas = carregar_aulas(cursor, semestre["inicio"])
    ultimas = carregar_ultimas_chamadas(cursor, primeira_id)
    aulas_turma = carregar_aulas_turma(cursor, semestre["inicio"])
    ultimas_turma = carregar_ultimas_chamadas_turma(cursor, primeira_id)

    for coleta_id in sorted(pares_por_coleta):
        pares = pares_por_coleta[coleta_id]
        disciplinas: dict[Par, list[tuple[str, int | None]]] = defaultdict(list)
        for row in cursor.execute(
            """
            SELECT aluno_id, curso_id, codigo_disciplina, id_turma
            FROM frequencia_disciplina
            WHERE coleta_id = ?
              AND (situacao IS NULL OR TRIM(situacao) = '')
            """,
            (coleta_id,),
        ).fetchall():
            par = (int(row[0]), int(row[1]))
            if par in pares:
                id_turma = int(row[3]) if row[3] is not None else None
                disciplinas[par].append((str(row[2]), id_turma))

        faltas: dict[tuple[Par, str], set[str]] = defaultdict(set)
        for row in cursor.execute(
            """
            SELECT aluno_id, curso_id, codigo_disciplina, data_falta
            FROM faltas_dia
            WHERE coleta_id = ?
            """,
            (coleta_id,),
        ).fetchall():
            par = (int(row[0]), int(row[1]))
            if par in pares:
                faltas[(par, str(row[2]))].add(str(row[3])[:10])

        corte_coleta = cortes.get(coleta_id)
        if corte_coleta is None:
            continue
        corte_iso = corte_coleta.isoformat()

        for par in pares:
            for codigo, id_turma in disciplinas.get(par, []):
                datas_aula = None
                ultima = None
                if id_turma is not None:
                    datas_aula = aulas_turma.get(id_turma)
                    ultima = ultima_chamada_ate(ultimas_turma, id_turma, coleta_id)
                if not datas_aula or ultima is None:
                    chave = (codigo, par[1])
                    datas_aula = aulas.get(chave)
                    ultima = ultima_chamada_ate(ultimas, chave, coleta_id)
                if not datas_aula or ultima is None:
                    continue
                limite = min(ultima, corte_iso)
                faltas_disc = faltas.get((par, codigo), set())
                for serie in series_por_par[par]:
                    for semana, inicio, fim in serie["semanas"]:
                        aulas_semana, faltas_semana = contar_janela(
                            datas_aula, faltas_disc, inicio, min(fim, limite)
                        )
                        if aulas_semana:
                            contagem = serie["contagem"].setdefault(semana, [0, 0])
                            contagem[0] += aulas_semana
                            contagem[1] += faltas_semana

    return sum(1 for par in series_por_par if par not in ultima_coleta)


def gravar(
    cursor: Any,
    semestre: dict[str, Any],
    semanas: int,
    min_aulas: int,
    series: list[dict[str, Any]],
) -> int:
    """Substitui a analise anterior pela nova."""
    cursor.execute("DELETE FROM efeito_contatos_semanas")
    cursor.execute("DELETE FROM efeito_contatos_series")
    cursor.execute("DELETE FROM efeito_contatos_execucoes")
    corte = semestre["cortes"].get(semestre["ultima_id"])
    contatados = sum(1 for s in series if s["grupo"] == "contatado" and s["referencia"] == "primeiro")
    cursor.execute(
        """
        INSERT INTO efeito_contatos_execucoes (
            coleta_id, data_inicio, data_corte, janela_dias, min_aulas, total_eventos, executado_em
        ) VALUES (?, ?, ?, ?, ?, ?, datetime('now', 'localtime'))
        """,
        (
            semestre["ultima_id"],
            semestre["inicio"].isoformat(),
            corte.isoformat() if corte else "",
            7 * semanas,
            min_aulas,
            contatados,
        ),
    )
    execucao_id = int(cursor.lastrowid)

    linhas: list[tuple[int, int, int, int]] = []
    for serie in series:
        cursor.execute(
            """
            INSERT INTO efeito_contatos_series (
                execucao_id, aluno_id, curso_id, grupo, referencia,
                data_referencia, total_contatos, canais
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            """,
            (
                execucao_id,
                serie["par"][0],
                serie["par"][1],
                serie["grupo"],
                serie["referencia"],
                serie["data"].isoformat(),
                serie["total"],
                serie["canais"],
            ),
        )
        serie_id = int(cursor.lastrowid)
        linhas.extend(
            (serie_id, semana, aulas, faltas)
            for semana, (aulas, faltas) in sorted(serie["contagem"].items())
        )
    cursor.executemany(
        "INSERT INTO efeito_contatos_semanas (serie_id, semana, aulas, faltas) VALUES (?, ?, ?, ?)",
        linhas,
    )
    return execucao_id


def taxas_antes_depois(
    serie: dict[str, Any],
    semanas: int,
    min_aulas: int,
) -> tuple[float, float] | None:
    """Taxas de faltas (%) nas N semanas antes e nas N depois; None sem aulas suficientes."""
    aulas_antes = faltas_antes = aulas_depois = faltas_depois = 0
    for semana, (aulas, faltas) in serie["contagem"].items():
        if -semanas <= semana <= -1:
            aulas_antes += aulas
            faltas_antes += faltas
        elif 1 <= semana <= semanas:
            aulas_depois += aulas
            faltas_depois += faltas
    if aulas_antes < min_aulas or aulas_depois < min_aulas:
        return None
    return 100 * faltas_antes / aulas_antes, 100 * faltas_depois / aulas_depois


def imprimir_resumo(series: list[dict[str, Any]], semanas: int, min_aulas: int) -> None:
    """Media das taxas de faltas antes e depois, por referencia e grupo."""
    print(f"\nFaltas nas {semanas} semanas antes x {semanas} depois (media dos alunos analisados)")
    print("Referencia  Grupo        Alunos  Anal.   Antes  Depois  Variacao")
    for referencia in REFERENCIAS:
        for grupo in GRUPOS:
            lista = [s for s in series if s["referencia"] == referencia and s["grupo"] == grupo]
            if not lista:
                continue
            taxas = [t for t in (taxas_antes_depois(s, semanas, min_aulas) for s in lista) if t]
            if taxas:
                antes = mean(t[0] for t in taxas)
                depois = mean(t[1] for t in taxas)
                numeros = f"{antes:6.1f}%  {depois:5.1f}%  {depois - antes:+6.1f} p.p."
            else:
                numeros = "     -       -         -"
            print(f"{referencia:<11} {grupo:<12} {len(lista):>6}  {len(taxas):>5}  {numeros}")


def main(argv: list[str] | None = None) -> int:
    """Ponto de entrada."""
    parser = argparse.ArgumentParser(description="Efeito dos contatos nas faltas.")
    parser.add_argument(
        "--semanas",
        type=int,
        default=2,
        help="Semanas antes e depois comparadas na tela (padrao 2).",
    )
    parser.add_argument(
        "--min-aulas",
        type=int,
        default=3,
        help="Minimo de aulas antes e depois para o aluno entrar na conta (padrao 3).",
    )
    args = parser.parse_args(argv)
    if not 1 <= args.semanas <= SEMANAS_ANTES or args.min_aulas < 1:
        print(
            f"Erro: --semanas deve ficar entre 1 e {SEMANAS_ANTES} e --min-aulas ser positivo.",
            file=sys.stderr,
        )
        return 1

    inicio_execucao = time.monotonic()
    conn = conectar()
    cursor = conn.cursor()
    try:
        semestre = semestre_atual(cursor)
        if semestre is None:
            print("Erro: nenhuma coleta no banco.", file=sys.stderr)
            return 1
        corte = semestre["cortes"].get(semestre["ultima_id"]) or date.today()

        print("Efeito dos contatos")
        print(f"Semestre desde {semestre['inicio']:%d/%m/%Y}; coletas {semestre['primeira_id']} a {semestre['ultima_id']}")
        print(f"Comparacao: {args.semanas} semanas antes x depois; minimo {args.min_aulas} aulas em cada lado")

        contatos = carregar_contatos(cursor, semestre["primeira_id"])
        alarmes_criticos = carregar_alarmes_criticos(cursor, semestre["primeira_id"])
        series = montar_series(contatos, alarmes_criticos, corte)
        comparacao = sum(1 for s in series if s["grupo"] == "comparacao")
        print(
            f"Alunos/curso contatados: {len(contatos)}; "
            f"contatos (dias): {sum(len(d) for d in contatos.values())}; "
            f"comparacao (alarme critico sem contato): {comparacao}"
        )

        sem_dados = calcular_semanas(cursor, series, semestre)
        if sem_dados:
            print(f"Sem frequencia no semestre (ignorados): {sem_dados}")

        execucao_id = gravar(cursor, semestre, args.semanas, args.min_aulas, series)
        conn.commit()
    except Exception as error:  # noqa: BLE001
        conn.rollback()
        print(f"Erro: {error}", file=sys.stderr)
        return 1
    finally:
        fechar()

    imprimir_resumo(series, args.semanas, args.min_aulas)
    print(f"\nExecucao #{execucao_id} gravada em {time.monotonic() - inicio_execucao:.0f}s.")
    print("Tela: /index.php/efeito-contatos")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
