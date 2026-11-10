#!/usr/bin/env python3
"""Acompanhamento dos alunos em risco e efeito dos contatos (manual).

So alunos/curso do semestre atual. Grava duas analises:

1. Indicadores por aluno (acompanhamento_alunos), para a tela de eficacia.
   "Em risco" e o primeiro alarme critico pela regra de referencia
   (risco_referencia.py), simulada sobre as faltas do semestre; nao usa os
   alarmes gravados, que seguem a configuracao do momento. Para cada aluno em
   risco ou contatado: tipo e semana do risco, presenca nas 2 semanas antes e
   depois (retorno: voltou, parcial, nao_voltou, sem_aulas), contatos
   (contatos_aluno: primeiro, ultimo, dias do risco ao contato), retorno nas
   2 semanas apos o primeiro contato e presenca desde o ultimo contato.
   Ingressante, auxilio estudantil e nivel do curso entram como recortes.

2. Serie semanal (efeito_contatos_series/semanas), da tela antiga: faltas
   semana a semana em torno do 1o e do 2o contato e, como comparacao, do
   primeiro alarme critico gravado dos alunos ainda sem contato.

As aulas vem da turma do aluno (turma_aulas), limitadas a ultima chamada
registrada da turma (turma_ultima_aula): dias sem chamada lancada nao contam,
senao pareceriam presenca e a taxa "depois" ficaria artificialmente baixa.
Sem turma identificada (ou sem dados dela), usa a grade e a ultima chamada
da disciplina no curso (disciplina_aulas, disciplina_ultima_aula). Quem saiu
do curso no meio do semestre continua na analise com os dados que havia.

Nao faz parte da coleta: le so o banco e pode levar alguns minutos.

Uso:
    python3 gerar_efeito_contatos.py
    python3 gerar_efeito_contatos.py --semanas 3 --min-aulas 4
"""

from __future__ import annotations

import argparse
import sys
import time
from bisect import bisect_right
from collections import Counter, defaultdict
from datetime import date, timedelta
from statistics import mean, median
from typing import Any

from api_auth import carregar_config_api
from db import conectar, fechar
from risco_referencia import (
    SEMANAS_RETORNO,
    TIPOS,
    Disciplina,
    presenca,
    primeiro_critico,
    retorno_apos,
    semana_do_semestre,
    simular_alarmes,
)

CANAL_NAO_INFORMADO = "nao_informado"
REFERENCIAS = ("primeiro", "segundo")
GRUPOS = ("contatado", "comparacao")
# Semanas gravadas antes da referencia (a tela mostra a tendencia anterior).
SEMANAS_ANTES = 6
# Coletas que o portal mostra: completas, ou antigas/manuais sem status.
COLETA_COMPLETA = "(status IS NULL OR status = 'completa')"

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
        f"""
        SELECT id, data_inicial, data_final
        FROM coletas
        WHERE {COLETA_COMPLETA}
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
        f"SELECT id, executada_em, data_referencia FROM coletas WHERE id >= ? AND {COLETA_COMPLETA}",
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
        "periodo": "",
    }


def carregar_contatos_registros(cursor: Any, inicio: date) -> dict[Par, list[dict[str, Any]]]:
    """Contatos de cada aluno/curso no semestre, em ordem de data."""
    contatos: dict[Par, list[dict[str, Any]]] = defaultdict(list)
    for row in cursor.execute(
        """
        SELECT aluno_id, curso_id, data_contato, canal, conseguiu_falar, motivo
        FROM contatos_aluno
        WHERE data_contato >= ?
        ORDER BY data_contato, registrado_em, id
        """,
        (inicio.isoformat(),),
    ).fetchall():
        dia = data_local(row[2])
        if dia is None:
            continue
        contatos[(int(row[0]), int(row[1]))].append({
            "data": dia,
            "canal": str(row[3] or CANAL_NAO_INFORMADO),
            "conseguiu_falar": int(row[4]) if row[4] is not None else None,
            "motivo": str(row[5]) if row[5] else None,
        })
    return contatos


def carregar_contatos(cursor: Any, inicio: date) -> dict[Par, dict[date, set[str]]]:
    """Dias de contato de cada aluno/curso no semestre, com os canais usados em cada dia."""
    contatos: dict[Par, dict[date, set[str]]] = defaultdict(lambda: defaultdict(set))
    for par, registros in carregar_contatos_registros(cursor, inicio).items():
        for registro in registros:
            contatos[par][registro["data"]].add(registro["canal"])
    return contatos


def carregar_alarmes_criticos(cursor: Any, primeira_id: int) -> dict[Par, date]:
    """Dia da coleta em que cada aluno/curso teve o primeiro alarme critico gravado no semestre."""
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


def carregar_datas_aula(cursor: Any, inicio: date) -> tuple[dict[tuple[str, int], list[date]], dict[int, list[date]]]:
    """Datas de aula (ordenadas) por disciplina/curso (grade) e por turma."""
    por_disciplina: dict[tuple[str, int], list[date]] = defaultdict(list)
    for row in cursor.execute(
        """
        SELECT codigo_disciplina, curso_id, data_aula
        FROM disciplina_aulas
        WHERE data_aula >= ?
        ORDER BY codigo_disciplina, curso_id, data_aula
        """,
        (inicio.isoformat(),),
    ).fetchall():
        dia = data_local(row[2])
        if dia is not None:
            por_disciplina[(str(row[0]), int(row[1]))].append(dia)

    por_turma: dict[int, list[date]] = defaultdict(list)
    for row in cursor.execute(
        """
        SELECT id_turma, data_aula
        FROM turma_aulas
        WHERE data_aula >= ?
        ORDER BY id_turma, data_aula
        """,
        (inicio.isoformat(),),
    ).fetchall():
        dia = data_local(row[1])
        if dia is not None:
            por_turma[int(row[0])].append(dia)
    return por_disciplina, por_turma


def carregar_ultimas_chamadas(
    cursor: Any,
    primeira_id: int,
) -> tuple[dict[tuple[str, int], tuple[list[int], list[date]]], dict[int, tuple[list[int], list[date]]]]:
    """Ultima chamada por disciplina/curso e por turma, em cada coleta (ordem de coleta).

    turma_ultima_aula tem uma linha por curso da turma, todas com a mesma data.
    """
    por_disciplina: dict[tuple[str, int], tuple[list[int], list[date]]] = {}
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
        dia = data_local(row[3])
        if dia is not None:
            coletas, datas = por_disciplina.setdefault((str(row[0]), int(row[1])), ([], []))
            coletas.append(int(row[2]))
            datas.append(dia)

    por_turma: dict[int, tuple[list[int], list[date]]] = {}
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
        dia = data_local(row[2])
        if dia is not None:
            coletas, datas = por_turma.setdefault(int(row[0]), ([], []))
            coletas.append(int(row[1]))
            datas.append(dia)
    return por_disciplina, por_turma


def ultima_chamada_ate(
    ultimas: dict[Any, tuple[list[int], list[date]]],
    chave: Any,
    coleta_id: int,
) -> date | None:
    """Ultima chamada da disciplina (ou turma) conhecida ate a coleta informada."""
    registro = ultimas.get(chave)
    if registro is None:
        return None
    coletas, datas = registro
    posicao = bisect_right(coletas, coleta_id) - 1
    if posicao < 0:
        return None
    return datas[posicao]


def carregar_alunos(cursor: Any, semestre: dict[str, Any]) -> dict[Par, dict[str, Any]]:
    """Alunos/curso do semestre com as Disciplinas e os recortes da tela.

    Usa a ultima coleta completa em que o aluno aparece. Por aluno: disciplinas
    (datas de aula ate a ultima chamada e o corte da coleta, faltas por dia),
    nivel do curso, ingressante (ingresso no periodo, ou no ano para os cursos
    anuais), auxilio estudantil e saiu (trancou ou sumiu da ultima coleta).
    """
    primeira_id = int(semestre["primeira_id"])
    ultima_id = int(semestre["ultima_id"])
    cortes: dict[int, date] = semestre["cortes"]
    periodo = str(semestre.get("periodo") or "")
    ingressos = {periodo, periodo[:4] + "/0"} if periodo else set()

    ultima_coleta: dict[Par, int] = {}
    for row in cursor.execute(
        f"""
        SELECT aluno_id, curso_id, MAX(coleta_id)
        FROM frequencia_curso
        WHERE coleta_id >= ?
          AND coleta_id IN (SELECT id FROM coletas WHERE {COLETA_COMPLETA})
        GROUP BY aluno_id, curso_id
        """,
        (primeira_id,),
    ).fetchall():
        ultima_coleta[(int(row[0]), int(row[1]))] = int(row[2])

    niveis = {int(r[0]): (str(r[1]) if r[1] else None) for r in cursor.execute("SELECT id, curso_nivel FROM cursos")}
    ingressantes = {
        (int(r[0]), int(r[1]))
        for r in cursor.execute("SELECT aluno_id, curso_id, ano_semestre_ingresso FROM aluno_cursos")
        if str(r[2] or "").strip() in ingressos
    }
    auxilio = {
        int(r[0]) for r in cursor.execute(
            "SELECT a.id FROM alunos a JOIN alunos_auxilio_estudantil x ON x.login = a.login"
        )
    }
    trancados = {
        (int(r[0]), int(r[1]))
        for r in cursor.execute("SELECT aluno_id, curso_id FROM alunos_trancados WHERE coleta_id = ?", (ultima_id,))
    }

    aulas_disciplina, aulas_turma = carregar_datas_aula(cursor, semestre["inicio"])
    ultimas_disciplina, ultimas_turma = carregar_ultimas_chamadas(cursor, primeira_id)

    pares_por_coleta: dict[int, set[Par]] = defaultdict(set)
    for par, coleta_id in ultima_coleta.items():
        pares_por_coleta[coleta_id].add(par)

    alunos: dict[Par, dict[str, Any]] = {}
    for coleta_id in sorted(pares_por_coleta):
        corte = cortes.get(coleta_id)
        if corte is None:
            continue
        pares = pares_por_coleta[coleta_id]

        faltas: dict[tuple[Par, str], set[date]] = defaultdict(set)
        for row in cursor.execute(
            "SELECT aluno_id, curso_id, codigo_disciplina, data_falta FROM faltas_dia WHERE coleta_id = ?",
            (coleta_id,),
        ).fetchall():
            par = (int(row[0]), int(row[1]))
            dia = data_local(row[3])
            if par in pares and dia is not None:
                faltas[(par, str(row[2]))].add(dia)

        disciplinas: dict[Par, list[Disciplina]] = defaultdict(list)
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
            if par not in pares:
                continue
            codigo = str(row[2])
            id_turma = int(row[3]) if row[3] is not None else None
            datas = ultima = None
            if id_turma is not None:
                datas = aulas_turma.get(id_turma)
                ultima = ultima_chamada_ate(ultimas_turma, id_turma, coleta_id)
            if not datas or ultima is None:
                datas = aulas_disciplina.get((codigo, par[1]))
                ultima = ultima_chamada_ate(ultimas_disciplina, (codigo, par[1]), coleta_id)
            if not datas or ultima is None:
                continue
            disciplinas[par].append(
                Disciplina(codigo, datas, faltas.get((par, codigo), set()), min(ultima, corte))
            )

        for par in pares:
            alunos[par] = {
                "disciplinas": disciplinas.get(par, []),
                "nivel": niveis.get(par[1]),
                "ingressante": par in ingressantes,
                "auxilio": par[0] in auxilio,
                "saiu": coleta_id != ultima_id or par in trancados,
            }
    return alunos


def calcular_semanas(
    cursor: Any,
    series: list[dict[str, Any]],
    semestre: dict[str, Any],
    alunos: dict[Par, dict[str, Any]] | None = None,
) -> int:
    """Preenche (aulas, faltas) de cada semana de cada serie (so semanas com aula).

    Devolve quantos alunos das series nao tem frequencia no semestre.
    """
    alunos = alunos if alunos is not None else carregar_alunos(cursor, semestre)
    sem_dados: set[Par] = set()
    for serie in series:
        aluno = alunos.get(serie["par"])
        if aluno is None:
            sem_dados.add(serie["par"])
            continue
        for semana, inicio, fim in serie["semanas"]:
            aulas, faltas = presenca(aluno["disciplinas"], date.fromisoformat(inicio), date.fromisoformat(fim))
            if aulas:
                serie["contagem"][semana] = [aulas, faltas]
    return len(sem_dados)


def montar_indicadores(
    alunos: dict[Par, dict[str, Any]],
    contatos: dict[Par, list[dict[str, Any]]],
    inicio: date,
    corte: date,
) -> list[dict[str, Any]]:
    """Uma linha por aluno/curso em risco (regra de referencia) ou com contato.

    O retorno so e classificado quando as 2 semanas seguintes cabem nos dados
    (ate o corte); antes disso fica None. dias_ate_contato e do risco ao
    primeiro contato do semestre: negativo = contatado antes de entrar em risco.
    """
    janela = timedelta(days=7 * SEMANAS_RETORNO)
    linhas: list[dict[str, Any]] = []
    for par, aluno in alunos.items():
        disciplinas = aluno["disciplinas"]
        registros = contatos.get(par, [])
        critico = primeiro_critico(simular_alarmes(disciplinas, inicio, corte))
        if critico is None and not registros:
            continue

        linha: dict[str, Any] = {
            "par": par,
            "nivel": aluno["nivel"],
            "ingressante": aluno["ingressante"],
            "auxilio": aluno["auxilio"],
            "saiu": aluno["saiu"],
            "risco_tipo": None,
            "risco_data": None,
            "risco_semana": None,
            "antes": (0, 0),
            "retorno": (0, 0, None),
            "total_contatos": len(registros),
            "primeiro": None,
            "dias_ate_contato": None,
            "ultimo": None,
            "contato_retorno": (0, 0, None),
            "desde_contato": (0, 0),
        }
        if critico is not None:
            tipo, dia = critico
            aulas, faltas, classe = retorno_apos(disciplinas, dia)
            linha.update({
                "risco_tipo": tipo,
                "risco_data": dia,
                "risco_semana": semana_do_semestre(inicio, dia),
                "antes": presenca(disciplinas, dia - janela, dia - timedelta(days=1)),
                "retorno": (aulas, faltas, classe if dia + janela <= corte else None),
            })
        if registros:
            primeiro, ultimo = registros[0], registros[-1]
            aulas, faltas, classe = retorno_apos(disciplinas, primeiro["data"])
            linha.update({
                "primeiro": primeiro,
                "ultimo": ultimo,
                "contato_retorno": (aulas, faltas, classe if primeiro["data"] + janela <= corte else None),
                "desde_contato": presenca(disciplinas, ultimo["data"] + timedelta(days=1), corte),
            })
            if critico is not None:
                linha["dias_ate_contato"] = (primeiro["data"] - critico[1]).days
        linhas.append(linha)
    return linhas


def gravar_indicadores(
    cursor: Any,
    semestre: dict[str, Any],
    corte: date,
    total_alunos: int,
    linhas: list[dict[str, Any]],
) -> int:
    """Substitui o acompanhamento anterior pelo novo."""
    cursor.execute("DELETE FROM acompanhamento_alunos")
    cursor.execute("DELETE FROM acompanhamento_execucoes")
    cursor.execute(
        """
        INSERT INTO acompanhamento_execucoes (coleta_id, periodo, data_inicio, data_corte, total_alunos)
        VALUES (?, ?, ?, ?, ?)
        """,
        (semestre["ultima_id"], semestre.get("periodo") or "", semestre["inicio"].isoformat(),
         corte.isoformat(), total_alunos),
    )
    execucao_id = int(cursor.lastrowid)

    def iso(dia: date | None) -> str | None:
        return dia.isoformat() if dia else None

    cursor.executemany(
        """
        INSERT INTO acompanhamento_alunos (
            execucao_id, aluno_id, curso_id, nivel, ingressante, auxilio, saiu,
            risco_tipo, risco_data, risco_semana, antes_aulas, antes_faltas,
            retorno_aulas, retorno_faltas, retorno,
            total_contatos, primeiro_contato, primeiro_canal, dias_ate_contato,
            ultimo_contato, ultimo_canal, ultimo_falou, ultimo_motivo,
            contato_retorno_aulas, contato_retorno_faltas, contato_retorno,
            desde_contato_aulas, desde_contato_faltas
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        """,
        [
            (
                execucao_id, l["par"][0], l["par"][1], l["nivel"],
                int(l["ingressante"]), int(l["auxilio"]), int(l["saiu"]),
                l["risco_tipo"], iso(l["risco_data"]), l["risco_semana"],
                l["antes"][0], l["antes"][1],
                l["retorno"][0], l["retorno"][1], l["retorno"][2],
                l["total_contatos"],
                iso(l["primeiro"]["data"]) if l["primeiro"] else None,
                l["primeiro"]["canal"] if l["primeiro"] else None,
                l["dias_ate_contato"],
                iso(l["ultimo"]["data"]) if l["ultimo"] else None,
                l["ultimo"]["canal"] if l["ultimo"] else None,
                l["ultimo"]["conseguiu_falar"] if l["ultimo"] else None,
                l["ultimo"]["motivo"] if l["ultimo"] else None,
                l["contato_retorno"][0], l["contato_retorno"][1], l["contato_retorno"][2],
                l["desde_contato"][0], l["desde_contato"][1],
            )
            for l in linhas
        ],
    )
    return execucao_id


def gravar(
    cursor: Any,
    semestre: dict[str, Any],
    semanas: int,
    min_aulas: int,
    series: list[dict[str, Any]],
) -> int:
    """Substitui a serie semanal anterior pela nova."""
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
    print(f"\nSerie semanal: faltas nas {semanas} semanas antes x {semanas} depois (media dos analisados)")
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


def imprimir_indicadores(linhas: list[dict[str, Any]], total_alunos: int) -> None:
    """Funil (em risco, contatados, retorno) por situacao."""
    em_risco = [l for l in linhas if l["risco_tipo"]]
    contatados = [l for l in em_risco if l["primeiro"]]
    dias = [l["dias_ate_contato"] for l in contatados if l["dias_ate_contato"] is not None and l["dias_ate_contato"] >= 0]
    print(f"\nAlunos: {total_alunos}; em risco (referencia): {len(em_risco)}; contatados: {len(contatados)}"
          + (f"; dias do risco ao contato (mediana): {median(dias):.0f}" if dias else ""))
    print("Situacao              Em risco  Contat.  Medidos  Voltou  Parcial  Nao voltou")
    for tipo in TIPOS:
        grupo = [l for l in em_risco if l["risco_tipo"] == tipo]
        medidos = Counter(l["retorno"][2] for l in grupo if l["retorno"][2])
        total = sum(medidos.values())
        print(
            f"{tipo:<21} {len(grupo):>8}  {sum(1 for l in grupo if l['primeiro']):>7}  {total:>7}  "
            + "  ".join(f"{medidos[c]:>6}" for c in ("voltou", "parcial", "nao_voltou"))
        )


def main(argv: list[str] | None = None) -> int:
    """Ponto de entrada."""
    parser = argparse.ArgumentParser(description="Acompanhamento dos alunos em risco e efeito dos contatos.")
    parser.add_argument(
        "--semanas",
        type=int,
        default=2,
        help="Semanas antes e depois comparadas na serie semanal (padrao 2).",
    )
    parser.add_argument(
        "--min-aulas",
        type=int,
        default=3,
        help="Minimo de aulas antes e depois para o aluno entrar na serie semanal (padrao 3).",
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
        semestre["periodo"] = (carregar_config_api().get("api_periodo_letivo") or "").strip()
        corte = semestre["cortes"].get(semestre["ultima_id"]) or date.today()

        print("Acompanhamento dos alunos em risco e efeito dos contatos")
        print(f"Semestre {semestre['periodo'] or '?'} desde {semestre['inicio']:%d/%m/%Y}; "
              f"coletas {semestre['primeira_id']} a {semestre['ultima_id']}; dados ate {corte:%d/%m/%Y}")

        alunos = carregar_alunos(cursor, semestre)
        registros = carregar_contatos_registros(cursor, semestre["inicio"])
        indicadores = montar_indicadores(alunos, registros, semestre["inicio"], corte)
        gravar_indicadores(cursor, semestre, corte, len(alunos), indicadores)

        contatos = carregar_contatos(cursor, semestre["inicio"])
        series = montar_series(contatos, carregar_alarmes_criticos(cursor, semestre["primeira_id"]), corte)
        sem_dados = calcular_semanas(cursor, series, semestre, alunos)
        if sem_dados:
            print(f"Contatados sem frequencia no semestre (fora da serie): {sem_dados}")
        execucao_id = gravar(cursor, semestre, args.semanas, args.min_aulas, series)
        conn.commit()
    except Exception as error:  # noqa: BLE001
        conn.rollback()
        print(f"Erro: {error}", file=sys.stderr)
        return 1
    finally:
        fechar()

    imprimir_indicadores(indicadores, len(alunos))
    imprimir_resumo(series, args.semanas, args.min_aulas)
    print(f"\nExecucao #{execucao_id} gravada em {time.monotonic() - inicio_execucao:.0f}s.")
    print("Tela: /index.php/efeito-contatos")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
