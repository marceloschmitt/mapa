#!/usr/bin/env python3
"""Efeito dos contatos: faltas antes e depois do contato com o aluno (manual).

So alunos/curso contatados no semestre atual (e-mail automatico em
alarme_emails; contatos registrados na tela de alarmes). Um contato = um dia
com algum contato, qualquer que seja o canal. Para cada aluno grava o numero
de contatos e a taxa de faltas (faltas / aulas) nas janelas de N dias antes do
primeiro contato, antes do ultimo e depois do ultimo (com um so contato, as
duas primeiras coincidem), e do dia seguinte ao ultimo contato ate a data de
corte dos dados.

As aulas vem da turma do aluno (turma_aulas), limitadas a ultima chamada
registrada da turma (turma_ultima_aula): dias sem chamada lancada nao contam,
senao pareceriam presenca e a taxa "depois" ficaria artificialmente baixa.
Sem turma identificada (ou sem dados dela), usa a grade e a ultima chamada
da disciplina no curso (disciplina_aulas, disciplina_ultima_aula).

Nao faz parte da coleta: le so o banco e pode levar alguns minutos.

Uso:
    python3 gerar_efeito_contatos.py
    python3 gerar_efeito_contatos.py --janela 21 --min-aulas 4
"""

from __future__ import annotations

import argparse
import sys
import time
from bisect import bisect_left, bisect_right
from collections import defaultdict
from datetime import date, timedelta
from typing import Any

from db import conectar, fechar

CANAL_AUTOMATICO = "email_automatico"
CANAL_NAO_INFORMADO = "nao_informado"
JANELAS = ("antes_primeiro", "antes_ultimo", "depois_ultimo", "ate_corte")
SEM_FIM = "9999-12-31"

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


def montar_registros(
    contatos: dict[Par, dict[date, set[str]]],
    janela: int,
) -> list[dict[str, Any]]:
    """Um registro por aluno/curso contatado, com as tres janelas de comparacao."""
    registros: list[dict[str, Any]] = []
    um_dia = timedelta(days=1)
    tamanho = timedelta(days=janela)
    for par, dias in contatos.items():
        if not dias:
            continue
        primeiro = min(dias)
        ultimo = max(dias)
        registros.append({
            "par": par,
            "total": len(dias),
            "primeiro": primeiro,
            "ultimo": ultimo,
            "canais_ultimo": ",".join(sorted(dias[ultimo])),
            "janelas": {
                "antes_primeiro": ((primeiro - tamanho).isoformat(), (primeiro - um_dia).isoformat()),
                "antes_ultimo": ((ultimo - tamanho).isoformat(), (ultimo - um_dia).isoformat()),
                "depois_ultimo": ((ultimo + um_dia).isoformat(), (ultimo + tamanho).isoformat()),
                "ate_corte": ((ultimo + um_dia).isoformat(), SEM_FIM),
            },
            "contagem": {nome: [0, 0] for nome in JANELAS},
        })
    return registros


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


def calcular_janelas(
    cursor: Any,
    registros: list[dict[str, Any]],
    semestre: dict[str, Any],
) -> int:
    """Preenche (aulas, faltas) de cada janela em cada registro.

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

    registro_por_par: dict[Par, dict[str, Any]] = {r["par"]: r for r in registros}

    pares_por_coleta: dict[int, set[Par]] = defaultdict(set)
    for par in registro_por_par:
        coleta_id = ultima_coleta.get(par)
        if coleta_id is not None:
            pares_por_coleta[coleta_id].add(par)

    aulas = carregar_aulas(cursor, semestre["inicio"])
    ultimas = carregar_ultimas_chamadas(cursor, primeira_id)
    aulas_turma = carregar_aulas_turma(cursor, semestre["inicio"])
    ultimas_turma = carregar_ultimas_chamadas_turma(cursor, primeira_id)
    sem_dados = 0

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
                registro = registro_por_par[par]
                for nome, (inicio, fim) in registro["janelas"].items():
                    aulas_janela, faltas_janela = contar_janela(
                        datas_aula, faltas_disc, inicio, min(fim, limite)
                    )
                    registro["contagem"][nome][0] += aulas_janela
                    registro["contagem"][nome][1] += faltas_janela

    for par in registro_por_par:
        if par not in ultima_coleta:
            sem_dados += 1
    return sem_dados


def gravar(
    cursor: Any,
    semestre: dict[str, Any],
    janela: int,
    min_aulas: int,
    registros: list[dict[str, Any]],
) -> int:
    """Substitui a analise anterior pela nova."""
    cursor.execute("DELETE FROM efeito_contatos_alunos")
    cursor.execute("DELETE FROM efeito_contatos_execucoes")
    corte = semestre["cortes"].get(semestre["ultima_id"])
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
            janela,
            min_aulas,
            len(registros),
        ),
    )
    execucao_id = int(cursor.lastrowid)
    cursor.executemany(
        """
        INSERT INTO efeito_contatos_alunos (
            execucao_id, aluno_id, curso_id, total_contatos,
            primeiro_contato, ultimo_contato, canais_ultimo,
            aulas_antes_primeiro, faltas_antes_primeiro,
            aulas_antes_ultimo, faltas_antes_ultimo,
            aulas_depois_ultimo, faltas_depois_ultimo,
            aulas_ate_corte, faltas_ate_corte
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        """,
        [
            (
                execucao_id,
                r["par"][0],
                r["par"][1],
                r["total"],
                r["primeiro"].isoformat(),
                r["ultimo"].isoformat(),
                r["canais_ultimo"],
                *r["contagem"]["antes_primeiro"],
                *r["contagem"]["antes_ultimo"],
                *r["contagem"]["depois_ultimo"],
                *r["contagem"]["ate_corte"],
            )
            for r in registros
        ],
    )
    return execucao_id


def imprimir_resumo(registros: list[dict[str, Any]], min_aulas: int) -> None:
    """Quantos melhoraram em cada grupo (um contato / dois ou mais)."""
    comparacoes = [
        ("Um contato", lambda r: r["total"] == 1, "antes_primeiro"),
        ("2+ contatos, desde o primeiro", lambda r: r["total"] > 1, "antes_primeiro"),
        ("2+ contatos, desde o ultimo", lambda r: r["total"] > 1, "antes_ultimo"),
    ]
    print("\n                                              Janela seguinte     Ate o corte")
    print("Grupo                           Alunos  Contatos  Anal.  Melh.  Anal.  Melh.")
    for rotulo, filtro, antes in comparacoes:
        lista = [r for r in registros if filtro(r)]
        colunas: list[int] = []
        for depois in ("depois_ultimo", "ate_corte"):
            analisados = melhoraram = 0
            for r in lista:
                aulas_a, faltas_a = r["contagem"][antes]
                aulas_d, faltas_d = r["contagem"][depois]
                if aulas_a < min_aulas or aulas_d < min_aulas:
                    continue
                analisados += 1
                if faltas_d * aulas_a < faltas_a * aulas_d:
                    melhoraram += 1
            colunas += [analisados, melhoraram]
        contatos = sum(r["total"] for r in lista)
        print(
            f"{rotulo:<31} {len(lista):>6}  {contatos:>8}  "
            + "  ".join(f"{valor:>5}" for valor in colunas)
        )


def main(argv: list[str] | None = None) -> int:
    """Ponto de entrada."""
    parser = argparse.ArgumentParser(description="Efeito dos contatos nas faltas.")
    parser.add_argument("--janela", type=int, default=14, help="Dias antes e depois do contato (padrao 14).")
    parser.add_argument(
        "--min-aulas",
        type=int,
        default=3,
        help="Minimo de aulas em cada janela para o aluno entrar na conta (padrao 3).",
    )
    args = parser.parse_args(argv)
    if args.janela < 1 or args.min_aulas < 1:
        print("Erro: --janela e --min-aulas devem ser positivos.", file=sys.stderr)
        return 1

    inicio_execucao = time.monotonic()
    conn = conectar()
    cursor = conn.cursor()
    try:
        semestre = semestre_atual(cursor)
        if semestre is None:
            print("Erro: nenhuma coleta no banco.", file=sys.stderr)
            return 1

        print("Efeito dos contatos")
        print(f"Semestre desde {semestre['inicio']:%d/%m/%Y}; coletas {semestre['primeira_id']} a {semestre['ultima_id']}")
        print(f"Janela: {args.janela} dias antes e depois; minimo {args.min_aulas} aulas em cada")

        contatos = carregar_contatos(cursor, semestre["primeira_id"])
        registros = montar_registros(contatos, args.janela)
        print(
            f"Alunos/curso contatados: {len(registros)}; "
            f"contatos (dias): {sum(r['total'] for r in registros)}"
        )

        sem_dados = calcular_janelas(cursor, registros, semestre)
        if sem_dados:
            print(f"Sem frequencia no semestre (ignorados): {sem_dados}")

        execucao_id = gravar(cursor, semestre, args.janela, args.min_aulas, registros)
        conn.commit()
    except Exception as error:  # noqa: BLE001
        conn.rollback()
        print(f"Erro: {error}", file=sys.stderr)
        return 1
    finally:
        fechar()

    imprimir_resumo(registros, args.min_aulas)
    print(f"\nExecucao #{execucao_id} gravada em {time.monotonic() - inicio_execucao:.0f}s.")
    print("Tela: /index.php/efeito-contatos")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
