#!/usr/bin/env python3
"""Importa alunos_trancados.json para o SQLite (ultima coleta).

Alunos com status TRANCADO / TRANC. AUTOMATICO nao entram em frequencia
nem alarmes; ficam nesta tabela para consulta no portal.

Uso:
    python3 importar_trancados.py
"""

from __future__ import annotations

import json
import sys
from pathlib import Path
from typing import Any

from db import conectar, fechar, row_to_dict
from paths import JSON_ALUNOS_TRANCADOS

ARQUIVO_ENTRADA = JSON_ALUNOS_TRANCADOS


def carregar_trancados(caminho: Path) -> list[dict[str, Any]]:
    """Carrega alunos_trancados.json."""
    if not caminho.is_file():
        return []
    dados = json.loads(caminho.read_text(encoding="utf-8"))
    if not isinstance(dados, list):
        raise ValueError("Formato inesperado: esperava uma lista.")
    return dados


def ultima_coleta_id(cursor: Any) -> int | None:
    """Retorna o id da coleta mais recente."""
    cursor.execute("SELECT id FROM coletas ORDER BY id DESC LIMIT 1")
    row = cursor.fetchone()
    if row is None:
        return None
    return int(row["id"] if isinstance(row, dict) else row[0])


def upsert_aluno(cursor: Any, registro: dict[str, Any]) -> int:
    """Insere ou atualiza aluno e retorna o id."""
    login = str(registro.get("login", "")).strip()
    matricula = str(registro.get("matricula", "")).strip()
    nome = str(registro.get("nome", "")).strip() or login
    nome_social = str(registro.get("nome_social", "")).strip() or None
    email = registro.get("email")
    email_str = str(email).strip() if email else None

    cursor.execute(
        """
        INSERT INTO alunos (login, matricula, nome, nome_social, email)
        VALUES (?, ?, ?, ?, ?)
        ON CONFLICT(login, matricula) DO UPDATE SET
            nome = excluded.nome,
            nome_social = excluded.nome_social,
            email = COALESCE(excluded.email, alunos.email)
        """,
        (login, matricula, nome, nome_social, email_str),
    )
    cursor.execute(
        "SELECT id FROM alunos WHERE login = ? AND matricula = ?",
        (login, matricula),
    )
    row = row_to_dict(cursor.fetchone())
    return int(row["id"])


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


def importar(registros: list[dict[str, Any]], coleta_id: int) -> int:
    """Grava trancados da coleta (substitui os da mesma coleta)."""
    conn = conectar()
    cursor = conn.cursor()

    cursor.execute(
        "DELETE FROM alunos_trancados WHERE coleta_id = ?",
        (coleta_id,),
    )

    total = 0
    for registro in registros:
        login = str(registro.get("login", "")).strip()
        if login == "":
            continue

        aluno_id = upsert_aluno(cursor, registro)
        curso_id = upsert_curso(cursor, str(registro.get("nome_curso", "")))
        status = str(registro.get("status_discente") or "").strip() or "TRANCADO"
        ingresso = str(registro.get("ano_semestre_ingresso") or "").strip() or None
        turma = str(registro.get("turma_entrada") or "").strip() or None
        email = registro.get("email")
        email_str = str(email).strip() if email else None
        nome = str(registro.get("nome") or "").strip()
        nome_social = str(registro.get("nome_social") or "").strip() or None
        matricula = str(registro.get("matricula") or "").strip()
        nome_curso = str(registro.get("nome_curso") or "").strip()

        cursor.execute(
            """
            INSERT INTO alunos_trancados (
                coleta_id, aluno_id, curso_id,
                login, matricula, nome, nome_social, email,
                nome_curso, status_discente,
                ano_semestre_ingresso, turma_entrada
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(coleta_id, aluno_id, curso_id) DO UPDATE SET
                login = excluded.login,
                matricula = excluded.matricula,
                nome = excluded.nome,
                nome_social = excluded.nome_social,
                email = excluded.email,
                nome_curso = excluded.nome_curso,
                status_discente = excluded.status_discente,
                ano_semestre_ingresso = excluded.ano_semestre_ingresso,
                turma_entrada = excluded.turma_entrada
            """,
            (
                coleta_id,
                aluno_id,
                curso_id,
                login,
                matricula,
                nome,
                nome_social,
                email_str,
                nome_curso,
                status,
                ingresso,
                turma,
            ),
        )
        total += 1

    conn.commit()
    return total


def main() -> int:
    """Ponto de entrada."""
    try:
        registros = carregar_trancados(ARQUIVO_ENTRADA)
    except (ValueError, json.JSONDecodeError) as error:
        print(f"Erro ao ler entrada: {error}", file=sys.stderr)
        return 1

    conn = conectar()
    try:
        coleta_id = ultima_coleta_id(conn.cursor())
    finally:
        fechar()

    if coleta_id is None:
        print("Erro: nenhuma coleta encontrada. Rode importar_frequencia.py.", file=sys.stderr)
        return 1

    try:
        total = importar(registros, coleta_id)
    except Exception as error:  # noqa: BLE001
        print(f"Erro ao importar trancados: {error}", file=sys.stderr)
        fechar()
        return 1
    finally:
        fechar()

    print("Importacao de trancados")
    print(f"Coleta ID: {coleta_id}")
    print(f"Registros: {total}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
