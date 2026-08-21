#!/usr/bin/env python3
"""Importa cursos, professores e vinculos a partir de resposta_matriculas.json.

Le o cache da consulta de matriculados e popula:
  - `cursos` (todos os cursos da matricula, nao so os com frequencia)
  - `professores` e `disciplina_professores`

Uso:
    python3 importar_professores.py
"""

from __future__ import annotations

import json
import sys
from pathlib import Path
from typing import Any

from db import conectar, fechar, row_to_dict
from paths import JSON_RESPOSTA_MATRICULAS


def normalizar_cpf(valor: Any) -> str | None:
    """Converte CPF da API (int/str) para texto com 11 digitos."""
    if valor is None:
        return None

    texto = str(valor).strip()
    if texto.endswith(".0"):
        texto = texto[:-2]
    if not texto.isdigit():
        return None

    return texto.zfill(11)


def carregar_matriculas(caminho: Path) -> dict[str, Any]:
    """Carrega resposta_matriculas.json (mapa matricula -> registro).

    Aceita o formato completo (objeto chaveado) ou a extracao (lista plana).
    A lista plana nao traz disciplinas/docentes — retorna dicionario vazio.
    """
    dados = json.loads(caminho.read_text(encoding="utf-8"))
    if isinstance(dados, dict):
        if isinstance(dados.get("data"), list):
            print(
                "Aviso: resposta paginada/lista sem docentes; "
                "nenhum professor sera importado."
            )
            return {}
        return dados

    if isinstance(dados, list):
        print(
            "Aviso: resposta em formato extracao (lista Nome/Login/Matricula/Email); "
            "sem disciplinas/docentes — nenhum professor sera importado."
        )
        return {}

    raise ValueError("Formato inesperado: esperava objeto ou lista de matriculas.")


def upsert_curso(cursor: Any, nome_curso: str, curso_nivel: str | None = None) -> int:
    """Insere ou reutiliza curso e retorna o id."""
    nome = nome_curso.strip() or "Curso nao informado"
    nivel = (curso_nivel or "").strip().upper() or None
    cursor.execute(
        """
        INSERT INTO cursos (nome_curso, curso_nivel)
        VALUES (?, ?)
        ON CONFLICT(nome_curso) DO UPDATE SET
            nome_curso = excluded.nome_curso,
            curso_nivel = COALESCE(excluded.curso_nivel, cursos.curso_nivel)
        """,
        (nome, nivel),
    )
    cursor.execute("SELECT id FROM cursos WHERE nome_curso = ?", (nome,))
    row = row_to_dict(cursor.fetchone())
    return int(row["id"])


def upsert_professor(cursor: Any, cpf: str, nome: str, email: str | None = None) -> int:
    """Insere ou atualiza professor e retorna o id."""
    email_limpo = (email or "").strip() or None
    cursor.execute(
        """
        INSERT INTO professores (cpf, nome, email)
        VALUES (?, ?, ?)
        ON CONFLICT(cpf) DO UPDATE SET
            nome = excluded.nome,
            email = COALESCE(excluded.email, professores.email)
        """,
        (cpf, nome, email_limpo),
    )
    cursor.execute("SELECT id FROM professores WHERE cpf = ?", (cpf,))
    row = row_to_dict(cursor.fetchone())
    return int(row["id"])


def upsert_vinculo(
    cursor: Any,
    codigo_disciplina: str,
    disciplina: str,
    professor_id: int,
    tipo_docente: str | None,
) -> None:
    """Insere ou atualiza o vinculo disciplina/professor."""
    cursor.execute(
        """
        INSERT INTO disciplina_professores (
            codigo_disciplina, disciplina, professor_id, tipo_docente
        ) VALUES (?, ?, ?, ?)
        ON CONFLICT(codigo_disciplina, professor_id) DO UPDATE SET
            disciplina = excluded.disciplina,
            tipo_docente = COALESCE(excluded.tipo_docente, disciplina_professores.tipo_docente)
        """,
        (codigo_disciplina, disciplina, professor_id, tipo_docente),
    )


def extrair_cursos(matriculas: dict[str, Any]) -> dict[str, str | None]:
    """Mapa nome_curso -> curso_nivel (mais frequente na API)."""
    contagem: dict[str, dict[str, int]] = {}
    for registro in matriculas.values():
        if not isinstance(registro, dict):
            continue
        nome = str(registro.get("curso") or "").strip()
        if nome == "":
            continue
        nivel = str(registro.get("curso_nivel") or "").strip().upper() or ""
        bucket = contagem.setdefault(nome, {})
        bucket[nivel] = int(bucket.get(nivel, 0)) + 1

    resultado: dict[str, str | None] = {}
    for nome, niveis in contagem.items():
        # Prefere nivel nao vazio com maior contagem.
        ordenado = sorted(
            niveis.items(),
            key=lambda par: (par[0] == "", -par[1], par[0]),
        )
        melhor = ordenado[0][0]
        resultado[nome] = melhor if melhor != "" else None
    return resultado


def extrair_pares(
    matriculas: dict[str, Any],
) -> list[tuple[str, str, str, str, str | None, str | None]]:
    """Extrai pares unicos codigo/cpf com nome da disciplina e do professor.

    Returns:
        Lista de (codigo, disciplina, cpf, nome_professor, tipo_docente, email).
    """
    vistos: dict[tuple[str, str], tuple[str, str, str | None, str | None]] = {}

    for registro in matriculas.values():
        if not isinstance(registro, dict):
            continue
        disciplinas = registro.get("disciplinas") or []
        if not isinstance(disciplinas, list):
            continue

        for item in disciplinas:
            if not isinstance(item, dict):
                continue

            codigo = str(item.get("cod_disciplina") or "").strip()
            nome_disc = str(item.get("disciplina") or "").strip() or codigo
            if codigo == "":
                continue

            docentes = item.get("docentes") or []
            if not isinstance(docentes, list):
                continue

            for docente in docentes:
                if not isinstance(docente, dict):
                    continue

                cpf = normalizar_cpf(docente.get("cpf_docente"))
                nome = str(docente.get("docente") or "").strip()
                tipo = str(docente.get("tipo_docente") or "").strip() or None
                email = str(docente.get("email_docente") or "").strip() or None
                if cpf is None or nome == "":
                    continue

                chave = (codigo, cpf)
                # Mantem o nome/disciplina/email mais recentes encontrados.
                vistos[chave] = (nome_disc, nome, tipo, email)

    pares: list[tuple[str, str, str, str, str | None, str | None]] = []
    for (codigo, cpf), (nome_disc, nome, tipo, email) in vistos.items():
        pares.append((codigo, nome_disc, cpf, nome, tipo, email))
    return pares


def importar(matriculas: dict[str, Any]) -> dict[str, int]:
    """Persiste cursos, professores e vinculos no SQLite."""
    cursos = extrair_cursos(matriculas)
    pares = extrair_pares(matriculas)
    conn = conectar()
    cursor = conn.cursor()

    for nome_curso, curso_nivel in sorted(cursos.items()):
        upsert_curso(cursor, nome_curso, curso_nivel)

    professores_ids: set[int] = set()
    vinculos = 0

    for codigo, disciplina, cpf, nome, tipo, email in pares:
        professor_id = upsert_professor(cursor, cpf, nome, email)
        professores_ids.add(professor_id)
        upsert_vinculo(cursor, codigo, disciplina, professor_id, tipo)
        vinculos += 1

    conn.commit()
    return {
        "cursos": len(cursos),
        "pares": len(pares),
        "professores": len(professores_ids),
        "vinculos": vinculos,
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
        resumo = importar(matriculas)
    except (OSError, ValueError, json.JSONDecodeError) as error:
        print(f"Erro ao importar professores: {error}", file=sys.stderr)
        return 1
    finally:
        fechar()

    print(f"Cursos importados: {resumo['cursos']}")
    print(
        f"Professores importados: {resumo['professores']} "
        f"({resumo['vinculos']} vinculos disciplina/professor)."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
