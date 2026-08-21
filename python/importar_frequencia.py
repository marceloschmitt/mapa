#!/usr/bin/env python3
"""Importa tabela_frequencia.json para o SQLite.

Cria uma nova coleta e popula alunos, cursos, frequencia_disciplina
e faltas_dia. O JSON permanece como cache da coleta.

Uso:
    python3 importar_frequencia.py
"""

from __future__ import annotations

import json
import sys
from pathlib import Path
from typing import Any

from config_consultas import (
    data_referencia,
    frequencia_data_final,
    frequencia_data_inicial,
)
from db import conectar, fechar, parsear_data_sql, row_to_dict

from paths import JSON_TABELA_FREQUENCIA

ARQUIVO_ENTRADA = JSON_TABELA_FREQUENCIA


def carregar_tabela(caminho: Path) -> list[dict[str, Any]]:
    """Carrega tabela_frequencia.json."""
    dados = json.loads(caminho.read_text(encoding="utf-8"))
    if not isinstance(dados, list):
        raise ValueError("Formato inesperado: esperava uma lista.")
    return dados


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


def upsert_aluno_curso(
    cursor: Any,
    aluno_id: int,
    curso_id: int,
    registro: dict[str, Any],
) -> None:
    """Persiste ano/semestre de ingresso e turma de entrada do aluno no curso."""
    ingresso = str(registro.get("ano_semestre_ingresso") or "").strip() or None
    turma = str(registro.get("turma_entrada") or "").strip() or None
    cursor.execute(
        """
        INSERT INTO aluno_cursos (
            aluno_id, curso_id, ano_semestre_ingresso, turma_entrada
        ) VALUES (?, ?, ?, ?)
        ON CONFLICT(aluno_id, curso_id) DO UPDATE SET
            ano_semestre_ingresso = COALESCE(
                excluded.ano_semestre_ingresso,
                aluno_cursos.ano_semestre_ingresso
            ),
            turma_entrada = COALESCE(
                excluded.turma_entrada,
                aluno_cursos.turma_entrada
            )
        """,
        (aluno_id, curso_id, ingresso, turma),
    )


def criar_coleta(cursor: Any, total_alunos: int) -> int:
    """Cria registro de coleta com datas de config/consultas.json."""
    cursor.execute(
        """
        INSERT INTO coletas (
            data_inicial, data_final, data_referencia,
            total_alunos, origem
        ) VALUES (?, ?, ?, ?, ?)
        """,
        (
            parsear_data_sql(frequencia_data_inicial()),
            parsear_data_sql(frequencia_data_final()),
            data_referencia().isoformat(),
            total_alunos,
            ARQUIVO_ENTRADA.name,
        ),
    )
    return int(cursor.lastrowid)


def importar(registros: list[dict[str, Any]]) -> dict[str, int]:
    """Importa registros para o SQLite em uma nova coleta."""
    conn = conectar()
    cursor = conn.cursor()
    total_disciplinas = 0
    total_faltas = 0

    coleta_id = criar_coleta(cursor, len(registros))

    for registro in registros:
        login = str(registro.get("login", "")).strip()
        if login == "":
            continue

        aluno_id = upsert_aluno(cursor, registro)
        curso_id = upsert_curso(cursor, str(registro.get("nome_curso", "")))
        upsert_aluno_curso(cursor, aluno_id, curso_id, registro)

        geral = registro.get("frequencia_geral")
        if isinstance(geral, dict):
            percentual_curso = geral.get("percentual_frequencia_total")
            cursor.execute(
                """
                INSERT INTO frequencia_curso (
                    coleta_id, aluno_id, curso_id,
                    horarios, ausencias, presencas, percentual_frequencia
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(coleta_id, aluno_id, curso_id) DO UPDATE SET
                    horarios = excluded.horarios,
                    ausencias = excluded.ausencias,
                    presencas = excluded.presencas,
                    percentual_frequencia = excluded.percentual_frequencia
                """,
                (
                    coleta_id,
                    aluno_id,
                    curso_id,
                    int(geral.get("horarios_totais") or 0),
                    int(geral.get("ausencias_totais") or 0),
                    int(geral.get("presencas_totais") or 0),
                    float(percentual_curso) if percentual_curso is not None else None,
                ),
            )

        for disciplina in registro.get("disciplinas", []):
            if not isinstance(disciplina, dict):
                continue

            codigo = str(disciplina.get("codigo_disciplina", "")).strip()
            if codigo == "":
                continue

            nome_disc = str(disciplina.get("disciplina", "")).strip()
            percentual = disciplina.get("percentual_frequencia")

            cursor.execute(
                """
                INSERT INTO frequencia_disciplina (
                    coleta_id, aluno_id, curso_id,
                    codigo_disciplina, disciplina,
                    horarios, ausencias, presencas, percentual_frequencia
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(coleta_id, aluno_id, curso_id, codigo_disciplina)
                DO UPDATE SET
                    disciplina = excluded.disciplina,
                    horarios = excluded.horarios,
                    ausencias = excluded.ausencias,
                    presencas = excluded.presencas,
                    percentual_frequencia = excluded.percentual_frequencia
                """,
                (
                    coleta_id,
                    aluno_id,
                    curso_id,
                    codigo,
                    nome_disc,
                    int(disciplina.get("horarios") or 0),
                    int(disciplina.get("ausencias") or 0),
                    int(disciplina.get("presencas") or 0),
                    float(percentual) if percentual is not None else None,
                ),
            )
            total_disciplinas += 1

            dias = disciplina.get("dias_falta", [])
            if not isinstance(dias, list):
                continue

            for dia in dias:
                data_sql = parsear_data_sql(str(dia))
                if data_sql is None:
                    continue

                cursor.execute(
                    """
                    INSERT OR IGNORE INTO faltas_dia (
                        coleta_id, aluno_id, curso_id,
                        codigo_disciplina, data_falta
                    ) VALUES (?, ?, ?, ?, ?)
                    """,
                    (coleta_id, aluno_id, curso_id, codigo, data_sql),
                )
                total_faltas += 1

    cursor.execute(
        """
        UPDATE coletas
        SET total_disciplinas = ?, total_faltas_dia = ?
        WHERE id = ?
        """,
        (total_disciplinas, total_faltas, coleta_id),
    )

    conn.commit()
    return {
        "coleta_id": coleta_id,
        "alunos": len(registros),
        "disciplinas": total_disciplinas,
        "faltas_dia": total_faltas,
    }


def main() -> int:
    """Ponto de entrada do importador."""
    try:
        registros = carregar_tabela(ARQUIVO_ENTRADA)
    except FileNotFoundError:
        print(f"Erro: arquivo nao encontrado: {ARQUIVO_ENTRADA}", file=sys.stderr)
        print("Rode antes: python3 analisar_frequencia.py", file=sys.stderr)
        return 1
    except (ValueError, json.JSONDecodeError) as error:
        print(f"Erro ao ler entrada: {error}", file=sys.stderr)
        return 1

    try:
        resumo = importar(registros)
    except Exception as error:  # noqa: BLE001
        print(f"Erro na importacao SQLite: {error}", file=sys.stderr)
        fechar()
        return 1
    finally:
        fechar()

    print("Importacao concluida")
    print(f"Coleta ID: {resumo['coleta_id']}")
    print(f"Alunos/cursos: {resumo['alunos']}")
    print(f"Disciplinas: {resumo['disciplinas']}")
    print(f"Faltas (dias): {resumo['faltas_dia']}")
    print(f"Banco: data/mapa.db")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
