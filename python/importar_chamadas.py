#!/usr/bin/env python3
"""Importa datas de ultima aula ministrada (chamadas) a partir de resposta_alunos.json.

Para cada disciplina/curso:
  - grava snapshot em `disciplina_ultima_aula` (coleta atual)
  - acumula datas distintas em `disciplina_chamadas` (historico)

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

from db import conectar, fechar, row_to_dict
from paths import JSON_RESPOSTA_ALUNOS


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


def extrair_ultimas_aulas(alunos: list[dict[str, Any]]) -> dict[tuple[str, str], dict[str, Any]]:
    """Agrega a data mais recente de ultima_aula por (codigo, nome_curso).

    Disciplinas vistas apenas com null entram com data None.
    """
    agregado: dict[tuple[str, str], dict[str, Any]] = {}

    for aluno in alunos:
        if aluno.get("status") != 200:
            continue

        dados = aluno.get("dados")
        if not isinstance(dados, dict):
            continue

        for perfil in dados.values():
            if not isinstance(perfil, dict):
                continue

            for curso in perfil.get("cursos", []):
                if not isinstance(curso, dict):
                    continue

                nome_curso = str(curso.get("nome_curso") or "").strip() or "Curso nao informado"
                disciplinas = curso.get("disciplinas")
                if not isinstance(disciplinas, list):
                    continue

                for disciplina in disciplinas:
                    if not isinstance(disciplina, dict):
                        continue

                    codigo = str(disciplina.get("codigo_disciplina") or "").strip()
                    if codigo == "":
                        continue

                    nome = str(disciplina.get("nome_disciplina") or "").strip() or codigo
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


def ultima_coleta_id(cursor: Any) -> int | None:
    """Retorna o id da coleta mais recente."""
    cursor.execute("SELECT id FROM coletas ORDER BY id DESC LIMIT 1")
    row = cursor.fetchone()
    if row is None:
        return None
    return int(row_to_dict(row)["id"])


def importar(alunos: list[dict[str, Any]], coleta_id: int) -> dict[str, int]:
    """Persiste snapshot e historico de chamadas para a coleta."""
    agregado = extrair_ultimas_aulas(alunos)
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

    conn.commit()
    return {
        "coleta_id": coleta_id,
        "disciplinas": len(agregado),
        "com_data": com_data,
        "sem_data": sem_data,
        "datas_novas": datas_novas,
    }


def main() -> int:
    """Ponto de entrada."""
    parser = argparse.ArgumentParser(description="Importa datas de chamadas das disciplinas.")
    parser.add_argument("--coleta-id", type=int, default=None, help="ID da coleta (padrao: ultima)")
    args = parser.parse_args()

    caminho = Path(JSON_RESPOSTA_ALUNOS)
    try:
        alunos = json.loads(caminho.read_text(encoding="utf-8"))
    except FileNotFoundError:
        print(f"Erro: arquivo nao encontrado: {caminho}", file=sys.stderr)
        return 1
    except json.JSONDecodeError as error:
        print(f"Erro ao ler JSON: {error}", file=sys.stderr)
        return 1

    if not isinstance(alunos, list):
        print("Erro: resposta_alunos.json deve ser uma lista.", file=sys.stderr)
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

        resumo = importar(alunos, coleta_id)
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
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
