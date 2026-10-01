#!/usr/bin/env python3
"""Importa os alunos trancados para o SQLite (ultima coleta).

Le resposta_alunos_massa_cadastro.json (consulta_alunos_massa.py) e seleciona
os cursos com status_discente TRANCADO / TRANC. AUTOMATICO. Esses alunos nao
entram em frequencia nem alarmes; ficam nesta tabela para consulta no portal.
Se o arquivo ainda nao existir, nada e gravado.

Uso:
    python3 importar_trancados.py
"""

from __future__ import annotations

import json
import sys
from datetime import datetime
from pathlib import Path
from typing import Any

from db import conectar, fechar, row_to_dict
from paths import JSON_RESPOSTA_ALUNOS_MASSA_CADASTRO
from status_aluno import status_eh_trancado

ARQUIVO_ENTRADA = JSON_RESPOSTA_ALUNOS_MASSA_CADASTRO


def _texto(valor: Any) -> str | None:
    texto = str(valor or "").strip()
    return texto or None


def carregar_trancados(caminho: Path) -> list[dict[str, Any]]:
    """Um registro por curso trancado do cadastro (mapa login → aluno)."""
    cadastro = json.loads(caminho.read_text(encoding="utf-8"))
    if not isinstance(cadastro, dict):
        raise ValueError("Formato inesperado: esperava mapa login → aluno.")

    trancados: list[dict[str, Any]] = []
    for chave, aluno in cadastro.items():
        if not isinstance(aluno, dict):
            continue
        login = _texto(aluno.get("login")) or str(chave).strip()
        for curso in aluno.get("cursos") or []:
            if not isinstance(curso, dict):
                continue
            status = _texto(curso.get("status_discente")) or ""
            if not status_eh_trancado(status):
                continue
            trancados.append({
                "nome": _texto(aluno.get("nome_civil")) or _texto(aluno.get("nome_completo")) or login,
                "nome_social": _texto(aluno.get("nome_social")),
                "login": login,
                "matricula": _texto(curso.get("matricula")) or "",
                "email": _texto(aluno.get("email")),
                "nome_curso": _texto(curso.get("nome_curso")) or "",
                "ano_semestre_ingresso": _texto(curso.get("ano_semestre_ingresso")),
                "turma_entrada": _texto(curso.get("turma_entrada")),
                "status_discente": status,
            })
    return trancados


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
    entrada = ARQUIVO_ENTRADA
    if not entrada.is_file():
        print(f"Aviso: {entrada.name} ausente (rode consulta_alunos_massa.py); trancados nao importados.")
        return 0
    try:
        registros = carregar_trancados(entrada)
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

    gerado = datetime.fromtimestamp(entrada.stat().st_mtime).strftime("%d/%m/%Y %H:%M")
    print("Importacao de trancados")
    print(f"Arquivo: {entrada.name} (consultado em {gerado})")
    print(f"Coleta ID: {coleta_id}")
    print(f"Registros: {total}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
