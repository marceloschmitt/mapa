#!/usr/bin/env python3
"""Preenche aluno_cursos.modalidade_ingresso a partir do JSON da massa (cadastro).

Uso (com API fora do ar, a partir do arquivo já baixado):
  python3 python/backfill_modalidade_ingresso.py
"""

from __future__ import annotations

import json
import sys
from typing import Any

from db import conectar, fechar, nome_curso_valido
from paths import JSON_RESPOSTA_ALUNOS_MASSA_CADASTRO


def main() -> int:
    if not JSON_RESPOSTA_ALUNOS_MASSA_CADASTRO.is_file():
        print(f"Arquivo ausente: {JSON_RESPOSTA_ALUNOS_MASSA_CADASTRO}", file=sys.stderr)
        return 1

    with JSON_RESPOSTA_ALUNOS_MASSA_CADASTRO.open(encoding="utf-8") as fh:
        cadastro: dict[str, Any] = json.load(fh)

    conn = conectar()
    cur = conn.cursor()
    atualizados = 0
    sem_aluno = 0
    sem_curso = 0
    vazios = 0

    for chave, aluno in cadastro.items():
        if not isinstance(aluno, dict):
            continue
        login = str(aluno.get("login") or chave).strip()
        if login == "":
            continue
        row_aluno = cur.execute(
            "SELECT id FROM alunos WHERE login = ?", (login,)
        ).fetchone()
        if row_aluno is None:
            sem_aluno += 1
            continue
        aluno_id = int(row_aluno["id"])

        for curso in aluno.get("cursos") or []:
            if not isinstance(curso, dict):
                continue
            modalidade = str(curso.get("modalidade_ingresso") or "").strip()
            if modalidade == "":
                vazios += 1
                continue
            nome = nome_curso_valido(str(curso.get("nome_curso") or ""))
            if not nome:
                sem_curso += 1
                continue
            row_curso = cur.execute(
                "SELECT id FROM cursos WHERE nome_curso = ?", (nome,)
            ).fetchone()
            if row_curso is None:
                sem_curso += 1
                continue
            curso_id = int(row_curso["id"])
            cur.execute(
                """
                UPDATE aluno_cursos
                SET modalidade_ingresso = ?
                WHERE aluno_id = ? AND curso_id = ?
                  AND (
                    modalidade_ingresso IS NULL
                    OR TRIM(modalidade_ingresso) = ''
                    OR modalidade_ingresso <> ?
                  )
                """,
                (modalidade, aluno_id, curso_id, modalidade),
            )
            atualizados += int(cur.rowcount or 0)

    conn.commit()
    fechar()
    print(
        f"modalidade_ingresso atualizada em {atualizados} vínculo(s); "
        f"sem aluno={sem_aluno}, sem curso={sem_curso}, modalidade vazia={vazios}"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
