#!/usr/bin/env python3
"""Importa datas de ultima aula ministrada (chamadas) a partir de
resposta_alunos_massa_intervalo.json.

Alunos especiais (sem curso no intervalo) sao ignorados.

A API traz ultima_aula_ministrada por aluno; a chamada de um grupo e a data
mais recente entre seus alunos (quem entrou depois ou ficou fora da ultima
chamada nao faz o grupo parecer atrasado).

Para cada disciplina/curso:
  - grava snapshot em `disciplina_ultima_aula` (coleta atual)
  - acumula datas distintas em `disciplina_chamadas` (historico)

Para cada turma (id_turma via resposta_matriculas.json):
  - grava snapshot em `turma_ultima_aula`, uma linha por curso dos alunos
  - acumula datas distintas em `turma_chamadas`; na primeira vez, turma unica
    da disciplina no curso herda o historico de `disciplina_chamadas`

Uso:
    python3 importar_chamadas.py
    python3 importar_chamadas.py --coleta-id 27
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path
from typing import Any

from analisar_frequencia import indexar_turmas
from db import conectar, fechar, row_to_dict
from paths import JSON_RESPOSTA_ALUNOS_MASSA, JSON_RESPOSTA_MATRICULAS


def normalizar_data_aula(valor: Any) -> str | None:
    """Extrai data AAAA-MM-DD de ultima_aula_ministrada (str ou objeto aninhado)."""
    if valor is None:
        return None

    if isinstance(valor, str):
        texto = valor.strip()
        if texto == "":
            return None
        # Ja vem como 2026-08-03 na API
        if len(texto) >= 10 and texto[4] == "-" and texto[7] == "-":
            return texto[:10]
        return None

    if isinstance(valor, dict):
        for chave in ("ultima_aula_ministrada", "data", "data_aula", "data_registro"):
            if chave in valor:
                encontrado = normalizar_data_aula(valor.get(chave))
                if encontrado is not None:
                    return encontrado
        return None

    return None


def upsert_curso(cursor: Any, nome_curso: str) -> int:
    """Insere ou reutiliza curso e retorna o id."""
    nome = nome_curso.strip() or "Curso nao informado"
    cursor.execute(
        """
        INSERT INTO cursos (nome_curso)
        VALUES (?)
        ON CONFLICT(nome_curso) DO UPDATE SET nome_curso = excluded.nome_curso
        """,
        (nome,),
    )
    cursor.execute("SELECT id FROM cursos WHERE nome_curso = ?", (nome,))
    row = row_to_dict(cursor.fetchone())
    return int(row["id"])


def extrair_ultimas_aulas(vinculos: list[dict[str, Any]]) -> dict[tuple[str, str], dict[str, Any]]:
    """Agrega a data mais recente de ultima_aula por (codigo, nome_curso).

    Disciplinas vistas apenas com null entram com data None.
    """
    agregado: dict[tuple[str, str], dict[str, Any]] = {}

    for vinculo in vinculos:
        if not isinstance(vinculo, dict):
            continue

        nome_curso = str(vinculo.get("curso") or "").strip()
        if nome_curso == "":
            continue

        disciplinas = vinculo.get("disciplinas")
        if not isinstance(disciplinas, list):
            continue

        for disciplina in disciplinas:
            if not isinstance(disciplina, dict):
                continue

            codigo = str(disciplina.get("cod_disciplina") or "").strip()
            if codigo == "":
                continue

            nome = str(disciplina.get("nome") or "").strip() or codigo
            data = normalizar_data_aula(disciplina.get("ultima_aula_ministrada"))
            chave = (codigo, nome_curso)
            atual = agregado.get(chave)

            if atual is None:
                agregado[chave] = {
                    "codigo_disciplina": codigo,
                    "disciplina": nome,
                    "nome_curso": nome_curso,
                    "data_ultima_aula": data,
                }
                continue

            if nome and (not atual["disciplina"] or atual["disciplina"] == codigo):
                atual["disciplina"] = nome

            data_atual = atual.get("data_ultima_aula")
            if data is None:
                continue
            if data_atual is None or data > data_atual:
                atual["data_ultima_aula"] = data

    return agregado


def extrair_ultimas_aulas_turma(
    vinculos: list[dict[str, Any]],
    turmas: dict[tuple[str, str], int],
) -> dict[int, dict[str, Any]]:
    """Agrega a data mais recente de ultima_aula por turma, com os cursos dos alunos."""
    agregado: dict[int, dict[str, Any]] = {}

    for vinculo in vinculos:
        if not isinstance(vinculo, dict):
            continue

        nome_curso = str(vinculo.get("curso") or "").strip()
        id_discente = str(vinculo.get("id_discente") or "").strip()
        disciplinas = vinculo.get("disciplinas")
        if nome_curso == "" or id_discente == "" or not isinstance(disciplinas, list):
            continue

        for disciplina in disciplinas:
            if not isinstance(disciplina, dict):
                continue

            codigo = str(disciplina.get("cod_disciplina") or "").strip()
            id_turma = turmas.get((id_discente, codigo))
            if codigo == "" or id_turma is None:
                continue

            data = normalizar_data_aula(disciplina.get("ultima_aula_ministrada"))
            atual = agregado.setdefault(
                id_turma,
                {
                    "codigo_disciplina": codigo,
                    "disciplina": str(disciplina.get("nome") or "").strip() or codigo,
                    "cursos": set(),
                    "data_ultima_aula": None,
                },
            )
            atual["cursos"].add(nome_curso)
            if data is not None and (atual["data_ultima_aula"] is None or data > atual["data_ultima_aula"]):
                atual["data_ultima_aula"] = data

    return agregado


def herdar_historico_disciplina(
    cursor: Any,
    id_turma: int,
    codigo: str,
    curso_ids: list[int],
    turmas_por_disciplina: dict[tuple[str, int], set[int]],
) -> int:
    """Copia disciplina_chamadas para a turma ainda sem historico; retorna datas copiadas.

    So vale quando a turma e a unica da disciplina no curso: com mais de uma,
    o historico por disciplina mistura as chamadas das turmas.
    """
    cursor.execute("SELECT 1 FROM turma_chamadas WHERE id_turma = ? LIMIT 1", (id_turma,))
    if cursor.fetchone() is not None:
        return 0

    copiadas = 0
    for curso_id in curso_ids:
        if turmas_por_disciplina.get((codigo, curso_id)) != {id_turma}:
            continue
        cursor.execute(
            """
            INSERT OR IGNORE INTO turma_chamadas (id_turma, data_chamada, coleta_id)
            SELECT ?, data_chamada, coleta_id
            FROM disciplina_chamadas
            WHERE codigo_disciplina = ? AND curso_id = ?
            """,
            (id_turma, codigo, curso_id),
        )
        copiadas += max(cursor.rowcount, 0)
    return copiadas


def importar_turmas(
    cursor: Any,
    agregado: dict[int, dict[str, Any]],
    coleta_id: int,
) -> dict[str, int]:
    """Persiste snapshot e historico de chamadas por turma."""
    cursor.execute("DELETE FROM turma_ultima_aula WHERE coleta_id = ?", (coleta_id,))

    cursos_turma: dict[int, list[int]] = {}
    turmas_por_disciplina: dict[tuple[str, int], set[int]] = {}
    for id_turma, item in agregado.items():
        curso_ids = sorted(upsert_curso(cursor, nome) for nome in item["cursos"])
        cursos_turma[id_turma] = curso_ids
        for curso_id in curso_ids:
            turmas_por_disciplina.setdefault((item["codigo_disciplina"], curso_id), set()).add(id_turma)

    com_data = 0
    datas_novas = 0
    herdadas = 0
    for id_turma, item in agregado.items():
        codigo = str(item["codigo_disciplina"])
        data = item["data_ultima_aula"]
        # importar_grade cria a turma antes; isto so cobre turma ausente da grade.
        cursor.execute(
            "INSERT OR IGNORE INTO turmas (id_turma, codigo_disciplina, disciplina) VALUES (?, ?, ?)",
            (id_turma, codigo, str(item["disciplina"])),
        )
        cursor.executemany(
            """
            INSERT INTO turma_ultima_aula (coleta_id, id_turma, curso_id, data_ultima_aula)
            VALUES (?, ?, ?, ?)
            """,
            [(coleta_id, id_turma, curso_id, data) for curso_id in cursos_turma[id_turma]],
        )

        herdadas += herdar_historico_disciplina(
            cursor, id_turma, codigo, cursos_turma[id_turma], turmas_por_disciplina
        )

        if data is None:
            continue
        com_data += 1
        cursor.execute(
            "INSERT OR IGNORE INTO turma_chamadas (id_turma, data_chamada, coleta_id) VALUES (?, ?, ?)",
            (id_turma, data, coleta_id),
        )
        if cursor.rowcount > 0:
            datas_novas += 1
        else:
            cursor.execute(
                "UPDATE turma_chamadas SET coleta_id = ? WHERE id_turma = ? AND data_chamada = ?",
                (coleta_id, id_turma, data),
            )

    return {
        "turmas": len(agregado),
        "turmas_com_data": com_data,
        "turmas_datas_novas": datas_novas,
        "turmas_datas_herdadas": herdadas,
    }


def carregar_turmas() -> dict[tuple[str, str], int]:
    """id_turma por (id_discente, codigo); vazio se resposta_matriculas.json faltar."""
    caminho = Path(JSON_RESPOSTA_MATRICULAS)
    if not caminho.is_file():
        print(f"Aviso: {caminho.name} nao encontrado; chamadas so por disciplina.")
        return {}
    try:
        return indexar_turmas(json.loads(caminho.read_text(encoding="utf-8")))
    except (OSError, json.JSONDecodeError) as error:
        print(f"Aviso: turmas nao identificadas ({caminho.name}: {error})")
        return {}


def ultima_coleta_id(cursor: Any) -> int | None:
    """Retorna o id da coleta mais recente."""
    cursor.execute("SELECT id FROM coletas ORDER BY id DESC LIMIT 1")
    row = cursor.fetchone()
    if row is None:
        return None
    return int(row_to_dict(row)["id"])


def importar(
    vinculos: list[dict[str, Any]],
    coleta_id: int,
    turmas: dict[tuple[str, str], int] | None = None,
) -> dict[str, int]:
    """Persiste snapshot e historico de chamadas para a coleta."""
    agregado = extrair_ultimas_aulas(vinculos)
    conn = conectar()
    cursor = conn.cursor()

    cursor.execute(
        "DELETE FROM disciplina_ultima_aula WHERE coleta_id = ?",
        (coleta_id,),
    )

    com_data = 0
    sem_data = 0
    datas_novas = 0

    for item in agregado.values():
        curso_id = upsert_curso(cursor, str(item["nome_curso"]))
        data = item.get("data_ultima_aula")
        codigo = str(item["codigo_disciplina"])
        nome = str(item["disciplina"])

        cursor.execute(
            """
            INSERT INTO disciplina_ultima_aula (
                coleta_id, codigo_disciplina, disciplina, curso_id, data_ultima_aula
            ) VALUES (?, ?, ?, ?, ?)
            ON CONFLICT(coleta_id, codigo_disciplina, curso_id) DO UPDATE SET
                disciplina = excluded.disciplina,
                data_ultima_aula = excluded.data_ultima_aula
            """,
            (coleta_id, codigo, nome, curso_id, data),
        )

        if data is None:
            sem_data += 1
            continue

        com_data += 1
        cursor.execute(
            """
            INSERT OR IGNORE INTO disciplina_chamadas (
                codigo_disciplina, disciplina, curso_id, data_chamada, coleta_id
            ) VALUES (?, ?, ?, ?, ?)
            """,
            (codigo, nome, curso_id, data, coleta_id),
        )
        if cursor.rowcount > 0:
            datas_novas += 1
        else:
            cursor.execute(
                """
                UPDATE disciplina_chamadas
                SET disciplina = ?, coleta_id = ?
                WHERE codigo_disciplina = ? AND curso_id = ? AND data_chamada = ?
                """,
                (nome, coleta_id, codigo, curso_id, data),
            )

    resumo_turmas = importar_turmas(
        cursor, extrair_ultimas_aulas_turma(vinculos, turmas or {}), coleta_id
    )

    conn.commit()
    return {
        "coleta_id": coleta_id,
        "disciplinas": len(agregado),
        "com_data": com_data,
        "sem_data": sem_data,
        "datas_novas": datas_novas,
        **resumo_turmas,
    }


def main() -> int:
    """Ponto de entrada."""
    parser = argparse.ArgumentParser(description="Importa datas de chamadas das disciplinas.")
    parser.add_argument("--coleta-id", type=int, default=None, help="ID da coleta (padrao: ultima)")
    args = parser.parse_args()

    caminho = Path(JSON_RESPOSTA_ALUNOS_MASSA)
    try:
        vinculos = json.loads(caminho.read_text(encoding="utf-8"))
    except FileNotFoundError:
        print(f"Erro: arquivo nao encontrado: {caminho}", file=sys.stderr)
        return 1
    except json.JSONDecodeError as error:
        print(f"Erro ao ler JSON: {error}", file=sys.stderr)
        return 1

    if not isinstance(vinculos, list):
        print(f"Erro: {caminho.name} deve ser uma lista.", file=sys.stderr)
        return 1

    try:
        conn = conectar()
        cursor = conn.cursor()
        coleta_id = args.coleta_id if args.coleta_id is not None else ultima_coleta_id(cursor)
        if coleta_id is None:
            print(
                "Erro: nenhuma coleta no banco. Rode importar_frequencia.py antes.",
                file=sys.stderr,
            )
            return 1

        resumo = importar(vinculos, coleta_id, carregar_turmas())
    except Exception as error:  # noqa: BLE001
        print(f"Erro na importacao: {error}", file=sys.stderr)
        fechar()
        return 1
    finally:
        fechar()

    print("Chamadas importadas")
    print(f"Coleta ID: {resumo['coleta_id']}")
    print(f"Disciplinas: {resumo['disciplinas']}")
    print(f"Com data: {resumo['com_data']}")
    print(f"Sem registro: {resumo['sem_data']}")
    print(f"Datas novas no historico: {resumo['datas_novas']}")
    print(f"Turmas: {resumo['turmas']} (com data: {resumo['turmas_com_data']})")
    print(f"Datas novas no historico das turmas: {resumo['turmas_datas_novas']}")
    print(f"Datas herdadas do historico por disciplina: {resumo['turmas_datas_herdadas']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
