#!/usr/bin/env python3
"""Importa a grade de aulas das disciplinas (turno_turma).

Le resposta_matriculas.json, expande os intervalos em datas efetivas de aula
(segunda a sabado) e popula `disciplina_grade` + `disciplina_aulas`.

Uso:
    python3 importar_grade.py
"""

from __future__ import annotations

import json
import sys
from datetime import date
from pathlib import Path
from typing import Any

from db import conectar, fechar, row_to_dict
from paths import JSON_RESPOSTA_MATRICULAS
from turno_turma import dias_para_texto, extrair_datas_aula, extrair_dias_sigaa


def carregar_matriculas(caminho: Path) -> dict[str, Any]:
    """Carrega resposta_matriculas.json (mapa matricula -> registro)."""
    dados = json.loads(caminho.read_text(encoding="utf-8"))
    if isinstance(dados, dict):
        if isinstance(dados.get("data"), list):
            print(
                "Aviso: resposta paginada/lista sem turno_turma; "
                "nenhuma grade sera importada."
            )
            return {}
        return dados

    if isinstance(dados, list):
        print(
            "Aviso: resposta em formato extracao (lista); "
            "sem turno_turma — nenhuma grade sera importada."
        )
        return {}

    raise ValueError("Formato inesperado: esperava objeto ou lista de matriculas.")


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


def normalizar_semestre(valor: Any) -> str | None:
    """Normaliza semestre_oferta_cursando para texto (ex.: '3')."""
    if valor is None:
        return None
    texto = str(valor).strip()
    if texto == "" or texto.lower() == "none":
        return None
    return texto


def extrair_grades(
    matriculas: dict[str, Any],
) -> dict[tuple[str, str], dict[str, Any]]:
    """Agrega datas efetivas de aula por (codigo_disciplina, nome_curso)."""
    grades: dict[tuple[str, str], dict[str, Any]] = {}

    for registro in matriculas.values():
        if not isinstance(registro, dict):
            continue

        nome_curso = str(registro.get("curso") or "").strip() or "Curso nao informado"
        disciplinas = registro.get("disciplinas") or []
        if not isinstance(disciplinas, list):
            continue

        for item in disciplinas:
            if not isinstance(item, dict):
                continue

            codigo = str(item.get("cod_disciplina") or "").strip()
            if codigo == "":
                continue

            nome_disc = str(item.get("disciplina") or "").strip() or codigo
            turno = item.get("turno_turma")
            datas = extrair_datas_aula(turno, incluir_sabado=True, incluir_domingo=False)
            dias = extrair_dias_sigaa(turno, incluir_sabado=True)
            semestre = normalizar_semestre(item.get("semestre_oferta_cursando"))
            chave = (codigo, nome_curso)
            atual = grades.get(chave)

            if atual is None:
                grades[chave] = {
                    "codigo_disciplina": codigo,
                    "disciplina": nome_disc,
                    "nome_curso": nome_curso,
                    "datas": set(datas),
                    "dias": set(dias),
                    "semestres": {},
                    "turno_turma": str(turno).strip() if turno else None,
                }
                if semestre is not None:
                    grades[chave]["semestres"][semestre] = 1
                continue

            atual["datas"].update(datas)
            atual["dias"].update(dias)
            if semestre is not None:
                atual["semestres"][semestre] = int(atual["semestres"].get(semestre, 0)) + 1
            if nome_disc and (
                not atual["disciplina"] or atual["disciplina"] == codigo
            ):
                atual["disciplina"] = nome_disc
            if atual.get("turno_turma") in (None, "") and turno:
                atual["turno_turma"] = str(turno).strip()

    for item in grades.values():
        contagem = item.get("semestres") or {}
        if contagem:
            item["semestre_oferta"] = max(contagem.items(), key=lambda par: par[1])[0]
        else:
            item["semestre_oferta"] = None

    return grades


def substituir_datas_aula(
    cursor: Any,
    codigo: str,
    curso_id: int,
    datas: list[date],
) -> int:
    """Substitui as datas efetivas da disciplina/curso."""
    cursor.execute(
        """
        DELETE FROM disciplina_aulas
        WHERE codigo_disciplina = ? AND curso_id = ?
        """,
        (codigo, curso_id),
    )
    if not datas:
        return 0

    cursor.executemany(
        """
        INSERT INTO disciplina_aulas (codigo_disciplina, curso_id, data_aula)
        VALUES (?, ?, ?)
        """,
        [(codigo, curso_id, d.isoformat()) for d in datas],
    )
    return len(datas)


def upsert_grade(cursor: Any, curso_id: int, item: dict[str, Any]) -> int:
    """Persiste metadados da grade e datas efetivas; retorna qtd de datas."""
    datas_lista: list[date] = sorted(item.get("datas") or [])
    dias_lista = sorted(item.get("dias") or [])
    if datas_lista and not dias_lista:
        dias_lista = sorted(
            {
                ((d.weekday() + 1) % 7) + 1
                for d in datas_lista
                if ((d.weekday() + 1) % 7) + 1 != 1
            }
        )

    codigo = str(item["codigo_disciplina"])
    cursor.execute(
        """
        INSERT INTO disciplina_grade (
            codigo_disciplina, disciplina, curso_id,
            dias_semana, semestre_oferta, turno_turma
        ) VALUES (?, ?, ?, ?, ?, ?)
        ON CONFLICT(codigo_disciplina, curso_id) DO UPDATE SET
            disciplina = excluded.disciplina,
            dias_semana = excluded.dias_semana,
            semestre_oferta = COALESCE(excluded.semestre_oferta, disciplina_grade.semestre_oferta),
            turno_turma = COALESCE(excluded.turno_turma, disciplina_grade.turno_turma)
        """,
        (
            codigo,
            str(item["disciplina"]),
            curso_id,
            dias_para_texto(dias_lista),
            item.get("semestre_oferta"),
            item.get("turno_turma"),
        ),
    )

    # Limpa CSV legado, se a coluna ainda existir.
    cols = {
        str(r[1])
        for r in cursor.execute("PRAGMA table_info(disciplina_grade)").fetchall()
    }
    if "datas_aula" in cols:
        cursor.execute(
            """
            UPDATE disciplina_grade
            SET datas_aula = ''
            WHERE codigo_disciplina = ? AND curso_id = ?
            """,
            (codigo, curso_id),
        )

    return substituir_datas_aula(cursor, codigo, curso_id, datas_lista)


def importar(matriculas: dict[str, Any]) -> dict[str, int]:
    """Persiste a grade de datas efetivas das disciplinas."""
    grades = extrair_grades(matriculas)
    conn = conectar()
    cursor = conn.cursor()

    com_datas = 0
    total_datas = 0
    for item in grades.values():
        curso_id = upsert_curso(cursor, str(item["nome_curso"]))
        n = upsert_grade(cursor, curso_id, item)
        total_datas += n
        if n > 0:
            com_datas += 1

    conn.commit()
    return {
        "grades": len(grades),
        "com_datas": com_datas,
        "sem_datas": len(grades) - com_datas,
        "total_datas": total_datas,
    }


def main() -> int:
    """Ponto de entrada."""
    if not JSON_RESPOSTA_MATRICULAS.is_file():
        print(
            f"Arquivo nao encontrado: {JSON_RESPOSTA_MATRICULAS}\n"
            "Rode python3 consulta_inicial.py antes.",
            file=sys.stderr,
        )
        return 1

    try:
        matriculas = carregar_matriculas(JSON_RESPOSTA_MATRICULAS)
        if matriculas == {}:
            print("Nenhuma matricula util para grade.")
            return 0
        resumo = importar(matriculas)
    except (OSError, ValueError, json.JSONDecodeError) as error:
        print(f"Erro ao importar grade: {error}", file=sys.stderr)
        return 1
    finally:
        fechar()

    print("Grade de disciplinas importada")
    print(f"Disciplinas/curso: {resumo['grades']}")
    print(f"Com datas de aula: {resumo['com_datas']}")
    print(f"Sem datas de aula: {resumo['sem_datas']}")
    print(f"Total de datas: {resumo['total_datas']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
