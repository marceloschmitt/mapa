#!/usr/bin/env python3
"""Efeito dos contatos: faltas antes e depois do contato com o aluno (manual).

Para cada aluno/curso do semestre atual, pega o primeiro contato de cada canal
(e-mail automatico em alarme_emails; contatos registrados na tela de alarmes)
e compara a taxa de faltas (faltas / aulas) nos N dias antes e depois.
Alunos com alarme que nunca foram contatados entram como comparacao, com a
data do primeiro alarme.

As aulas vem da grade (disciplina_aulas), limitadas a ultima chamada
registrada de cada disciplina: dias sem chamada lancada nao contam, senao
pareceriam presenca e a taxa "depois" ficaria artificialmente baixa.

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
CANAL_SEM_CONTATO = "sem_contato"

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


def carregar_contatos(cursor: Any, primeira_id: int) -> dict[Par, dict[str, date]]:
    """Primeiro contato de cada canal por aluno/curso."""
    contatos: dict[Par, dict[str, date]] = defaultdict(dict)

    def registrar(aluno_id: Any, curso_id: Any, canal: str, quando: Any) -> None:
        dia = data_local(quando)
        if dia is None:
            return
        par = (int(aluno_id), int(curso_id))
        atual = contatos[par].get(canal)
        if atual is None or dia < atual:
            contatos[par][canal] = dia

    for row in cursor.execute(
        """
        SELECT aluno_id, curso_id, MIN(enviado_em)
        FROM alarme_emails
        WHERE coleta_id >= ?
        GROUP BY aluno_id, curso_id
        """,
        (primeira_id,),
    ).fetchall():
        registrar(row[0], row[1], CANAL_AUTOMATICO, row[2])

    for row in cursor.execute(
        """
        SELECT aluno_id, curso_id,
               COALESCE(NULLIF(TRIM(contato_tipo), ''), ?) AS canal,
               MIN(visualizado_em)
        FROM alarmes
        WHERE coleta_id >= ?
          AND visualizado = 1
          AND visualizado_em IS NOT NULL
        GROUP BY aluno_id, curso_id, canal
        """,
        (CANAL_NAO_INFORMADO, primeira_id),
    ).fetchall():
        registrar(row[0], row[1], str(row[2]), row[3])

    return contatos


def carregar_primeiro_alarme(cursor: Any, primeira_id: int) -> dict[Par, date]:
    """Data do primeiro alarme de cada aluno/curso no semestre."""
    saida: dict[Par, date] = {}
    for row in cursor.execute(
        """
        SELECT aluno_id, curso_id, MIN(gerado_em)
        FROM alarmes
        WHERE coleta_id >= ?
        GROUP BY aluno_id, curso_id
        """,
        (primeira_id,),
    ).fetchall():
        dia = data_local(row[2])
        if dia is not None:
            saida[(int(row[0]), int(row[1]))] = dia
    return saida


def montar_eventos(
    contatos: dict[Par, dict[str, date]],
    primeiro_alarme: dict[Par, date],
) -> list[dict[str, Any]]:
    """Um evento por canal de contato; alunos nunca contatados como comparacao."""
    eventos: list[dict[str, Any]] = []
    for par, canais in contatos.items():
        if not canais:
            continue
        # Empate no mesmo dia: o automatico conta como o primeiro.
        primeiro = min(canais, key=lambda c: (canais[c], c != CANAL_AUTOMATICO, c))
        for canal, dia in canais.items():
            eventos.append({
                "par": par,
                "canal": canal,
                "primeiro": canal == primeiro,
                "data": dia,
            })

    for par, dia in primeiro_alarme.items():
        if par in contatos and contatos[par]:
            continue
        eventos.append({
            "par": par,
            "canal": CANAL_SEM_CONTATO,
            "primeiro": False,
            "data": dia,
        })
    return eventos


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
    ultimas: dict[tuple[str, int], tuple[list[int], list[str]]],
    chave: tuple[str, int],
    coleta_id: int,
) -> str | None:
    """Ultima chamada da disciplina conhecida ate a coleta informada."""
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
    eventos: list[dict[str, Any]],
    semestre: dict[str, Any],
    janela: int,
) -> int:
    """Preenche aulas/faltas antes e depois em cada evento.

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

    eventos_por_par: dict[Par, list[dict[str, Any]]] = defaultdict(list)
    for evento in eventos:
        evento.update(aulas_antes=0, faltas_antes=0, aulas_depois=0, faltas_depois=0)
        eventos_por_par[evento["par"]].append(evento)

    pares_por_coleta: dict[int, set[Par]] = defaultdict(set)
    for par in eventos_por_par:
        coleta_id = ultima_coleta.get(par)
        if coleta_id is not None:
            pares_por_coleta[coleta_id].add(par)

    aulas = carregar_aulas(cursor, semestre["inicio"])
    ultimas = carregar_ultimas_chamadas(cursor, primeira_id)
    um_dia = timedelta(days=1)
    sem_dados = 0

    for coleta_id in sorted(pares_por_coleta):
        pares = pares_por_coleta[coleta_id]
        disciplinas: dict[Par, list[str]] = defaultdict(list)
        for row in cursor.execute(
            """
            SELECT aluno_id, curso_id, codigo_disciplina
            FROM frequencia_disciplina
            WHERE coleta_id = ?
              AND (situacao IS NULL OR TRIM(situacao) = '')
            """,
            (coleta_id,),
        ).fetchall():
            par = (int(row[0]), int(row[1]))
            if par in pares:
                disciplinas[par].append(str(row[2]))

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
            for codigo in disciplinas.get(par, []):
                chave = (codigo, par[1])
                datas_aula = aulas.get(chave)
                if not datas_aula:
                    continue
                ultima = ultima_chamada_ate(ultimas, chave, coleta_id)
                if ultima is None:
                    continue
                limite = min(ultima, corte_iso)
                faltas_disc = faltas.get((par, codigo), set())
                for evento in eventos_por_par[par]:
                    dia: date = evento["data"]
                    antes = contar_janela(
                        datas_aula,
                        faltas_disc,
                        (dia - timedelta(days=janela)).isoformat(),
                        min((dia - um_dia).isoformat(), limite),
                    )
                    depois = contar_janela(
                        datas_aula,
                        faltas_disc,
                        (dia + um_dia).isoformat(),
                        min((dia + timedelta(days=janela)).isoformat(), limite),
                    )
                    evento["aulas_antes"] += antes[0]
                    evento["faltas_antes"] += antes[1]
                    evento["aulas_depois"] += depois[0]
                    evento["faltas_depois"] += depois[1]

    for par in eventos_por_par:
        if par not in ultima_coleta:
            sem_dados += 1
    return sem_dados


def gravar(
    cursor: Any,
    semestre: dict[str, Any],
    janela: int,
    min_aulas: int,
    eventos: list[dict[str, Any]],
) -> int:
    """Substitui a analise anterior pela nova."""
    cursor.execute("DELETE FROM efeito_contatos_eventos")
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
            len(eventos),
        ),
    )
    execucao_id = int(cursor.lastrowid)
    cursor.executemany(
        """
        INSERT INTO efeito_contatos_eventos (
            execucao_id, aluno_id, curso_id, canal, primeiro_contato, data_evento,
            aulas_antes, faltas_antes, aulas_depois, faltas_depois
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        """,
        [
            (
                execucao_id,
                e["par"][0],
                e["par"][1],
                e["canal"],
                1 if e["primeiro"] else 0,
                e["data"].isoformat(),
                e["aulas_antes"],
                e["faltas_antes"],
                e["aulas_depois"],
                e["faltas_depois"],
            )
            for e in eventos
        ],
    )
    return execucao_id


def imprimir_resumo(eventos: list[dict[str, Any]], min_aulas: int) -> None:
    """Taxa de faltas antes/depois por canal (so eventos com aulas suficientes)."""
    grupos: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for evento in eventos:
        if evento["aulas_antes"] < min_aulas or evento["aulas_depois"] < min_aulas:
            continue
        grupos[evento["canal"]].append(evento)
        if evento["primeiro"]:
            grupos["(primeiro contato)"].append(evento)

    print("\nCanal                  Alunos  Faltas antes  Faltas depois")
    for canal in sorted(grupos):
        lista = grupos[canal]
        aulas_a = sum(e["aulas_antes"] for e in lista)
        aulas_d = sum(e["aulas_depois"] for e in lista)
        taxa_a = 100 * sum(e["faltas_antes"] for e in lista) / aulas_a
        taxa_d = 100 * sum(e["faltas_depois"] for e in lista) / aulas_d
        print(f"{canal:<22} {len(lista):>6}  {taxa_a:>11.1f}%  {taxa_d:>12.1f}%")


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
        primeiro_alarme = carregar_primeiro_alarme(cursor, semestre["primeira_id"])
        eventos = montar_eventos(contatos, primeiro_alarme)
        contatados = sum(1 for canais in contatos.values() if canais)
        sem_contato = sum(1 for e in eventos if e["canal"] == CANAL_SEM_CONTATO)
        print(f"Alunos/curso contatados: {contatados}; com alarme sem contato: {sem_contato}")
        print(f"Eventos: {len(eventos)}")

        sem_dados = calcular_janelas(cursor, eventos, semestre, args.janela)
        if sem_dados:
            print(f"Sem frequencia no semestre (ignorados): {sem_dados}")

        execucao_id = gravar(cursor, semestre, args.janela, args.min_aulas, eventos)
        conn.commit()
    except Exception as error:  # noqa: BLE001
        conn.rollback()
        print(f"Erro: {error}", file=sys.stderr)
        return 1
    finally:
        fechar()

    imprimir_resumo(eventos, args.min_aulas)
    print(f"\nExecucao #{execucao_id} gravada em {time.monotonic() - inicio_execucao:.0f}s.")
    print("Tela: /index.php/efeito-contatos")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
