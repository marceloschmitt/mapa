#!/usr/bin/env python3
"""Atualiza e-mails dos professores a partir de data/Users.csv.

Casa pelo nome (sem acentos, maiúsculas). Remove prefixos de iniciais
colados no Moodle (ex.: ANAlessandra → Alessandra).

Uso:
    python3 importar_emails_professores.py
    python3 importar_emails_professores.py --csv ../data/Users.csv
"""

from __future__ import annotations

import argparse
import csv
import re
import sys
import unicodedata
from pathlib import Path
from typing import Any

from db import conectar, fechar
from paths import ARQUIVO_USERS_CSV


def strip_accents(texto: str) -> str:
    """Remove acentos para comparar nomes."""
    decomposto = unicodedata.normalize("NFD", texto)
    return "".join(c for c in decomposto if unicodedata.category(c) != "Mn")


def normalizar_nome(texto: str) -> str:
    """Normaliza nome para chave de comparação."""
    texto = strip_accents(texto).upper()
    texto = re.sub(r"[^A-Z0-9 ]+", " ", texto)
    return re.sub(r"\s+", " ", texto).strip()


def limpar_nome_csv(nome: str) -> str:
    """Remove iniciais coladas no início do nome (export Moodle).

    Exemplos:
      ANAlessandra Nejar Bruno → Alessandra Nejar Bruno
      CdClaudia do Nascimento → Claudia do Nascimento
      Adriana Oliveira → Adriana Oliveira
    """
    nome = nome.strip().strip('"')
    # 1–3 letras + nome começando com maiúscula e resto minúsculo na 1ª palavra.
    padrao = (
        r"^([A-Za-zÀ-ÖØ-öø-ÿÁÉÍÓÚÂÊÔÃÕÇáéíóúâêôãõç]{1,3})"
        r"([A-ZÁÉÍÓÚÂÊÔÃÕÇ][a-záéíóúâêôãõç].*)$"
    )
    match = re.match(padrao, nome)
    if match:
        return match.group(2)
    return nome


def detectar_colunas(fieldnames: list[str] | None) -> tuple[str, str]:
    """Identifica colunas de nome e e-mail no CSV."""
    if not fieldnames:
        raise ValueError("CSV sem cabeçalho.")

    mapa = {campo.strip().strip('"').lower(): campo for campo in fieldnames}
    nome_col = None
    email_col = None

    for chave, original in mapa.items():
        if nome_col is None and (
            "full name" in chave or chave in {"nome", "name", "full name"}
        ):
            nome_col = original
        if email_col is None and "email" in chave:
            email_col = original

    if nome_col is None or email_col is None:
        raise ValueError(
            "Não encontrei colunas de nome/e-mail no CSV. "
            f"Cabeçalhos: {fieldnames}"
        )
    return nome_col, email_col


def carregar_csv(caminho: Path) -> list[tuple[str, str]]:
    """Lê o CSV e devolve lista (nome_limpo, email)."""
    pares: list[tuple[str, str]] = []
    with caminho.open(encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        nome_col, email_col = detectar_colunas(reader.fieldnames)
        for row in reader:
            nome = limpar_nome_csv(str(row.get(nome_col) or ""))
            email = str(row.get(email_col) or "").strip()
            if nome == "" or email == "" or "@" not in email:
                continue
            pares.append((nome, email))
    return pares


def carregar_professores(cursor: Any) -> list[dict[str, Any]]:
    """Lista professores do banco."""
    cursor.execute("SELECT id, cpf, nome, email FROM professores ORDER BY nome")
    return [dict(row) for row in cursor.fetchall()]


def escolher_professor(
    nome_csv: str,
    por_nome: dict[str, list[dict[str, Any]]],
) -> dict[str, Any] | None:
    """Encontra professor único para o nome do CSV."""
    chave = normalizar_nome(nome_csv)
    if not chave:
        return None

    exatos = por_nome.get(chave, [])
    if len(exatos) == 1:
        return exatos[0]
    if len(exatos) > 1:
        return None

    tokens = set(chave.split())
    if len(tokens) < 2:
        return None

    candidatos: dict[int, dict[str, Any]] = {}
    for chave_prof, lista in por_nome.items():
        tokens_prof = set(chave_prof.split())
        if tokens.issubset(tokens_prof) or tokens_prof.issubset(tokens):
            for item in lista:
                candidatos[int(item["id"])] = item

    if len(candidatos) == 1:
        return next(iter(candidatos.values()))
    return None


def sincronizar_usuarios(cursor: Any) -> dict[str, int]:
    """Copia e-mails de professores para usuarios pelo CPF."""
    cursor.execute(
        """
        SELECT u.id, u.email AS email_usuario, p.email AS email_professor
        FROM usuarios u
        INNER JOIN professores p ON p.cpf = u.cpf
        WHERE p.email IS NOT NULL
          AND TRIM(p.email) != ''
        """
    )
    atualizados = 0
    ja_iguais = 0
    for row in cursor.fetchall():
        email_prof = str(row["email_professor"] or "").strip()
        email_user = str(row["email_usuario"] or "").strip()
        if email_prof == "":
            continue
        if email_user.lower() == email_prof.lower():
            ja_iguais += 1
            continue
        cursor.execute(
            "UPDATE usuarios SET email = ? WHERE id = ?",
            (email_prof, int(row["id"])),
        )
        atualizados += 1

    return {"atualizados": atualizados, "ja_iguais": ja_iguais}


def atualizar(
    cursor: Any,
    pares_csv: list[tuple[str, str]],
) -> dict[str, int | list[str]]:
    """Atualiza e-mails dos professores casados pelo nome."""
    professores = carregar_professores(cursor)
    por_nome: dict[str, list[dict[str, Any]]] = {}
    for professor in professores:
        chave = normalizar_nome(str(professor["nome"]))
        por_nome.setdefault(chave, []).append(professor)

    atualizados = 0
    ja_iguais = 0
    sem_match = 0
    ambiguos = 0
    avisos: list[str] = []
    vistos_prof: set[int] = set()

    for nome_csv, email in pares_csv:
        chave = normalizar_nome(nome_csv)
        exatos = por_nome.get(chave, [])
        if len(exatos) > 1:
            ambiguos += 1
            avisos.append(f"Ambíguo: {nome_csv} ({email})")
            continue

        professor = escolher_professor(nome_csv, por_nome)
        if professor is None:
            sem_match += 1
            continue

        professor_id = int(professor["id"])
        if professor_id in vistos_prof:
            continue
        vistos_prof.add(professor_id)

        email_atual = (professor.get("email") or "").strip()
        if email_atual.lower() == email.lower():
            ja_iguais += 1
            continue

        cursor.execute(
            "UPDATE professores SET email = ? WHERE id = ?",
            (email, professor_id),
        )
        atualizados += 1

    sync = sincronizar_usuarios(cursor)

    return {
        "csv": len(pares_csv),
        "professores": len(professores),
        "atualizados": atualizados,
        "ja_iguais": ja_iguais,
        "sem_match": sem_match,
        "ambiguos": ambiguos,
        "usuarios_atualizados": sync["atualizados"],
        "usuarios_iguais": sync["ja_iguais"],
        "avisos": avisos,
    }


def main() -> int:
    """Ponto de entrada."""
    parser = argparse.ArgumentParser(
        description="Atualiza e-mails de professores a partir de Users.csv"
    )
    parser.add_argument(
        "--csv",
        type=Path,
        default=ARQUIVO_USERS_CSV,
        help=f"Caminho do CSV (padrão: {ARQUIVO_USERS_CSV})",
    )
    args = parser.parse_args()
    caminho: Path = args.csv

    if not caminho.is_file():
        print(f"Arquivo não encontrado: {caminho}", file=sys.stderr)
        return 1

    try:
        pares = carregar_csv(caminho)
    except (OSError, ValueError, csv.Error) as error:
        print(f"Erro ao ler CSV: {error}", file=sys.stderr)
        return 1

    if not pares:
        print("Nenhum e-mail válido encontrado no CSV.", file=sys.stderr)
        return 1

    try:
        conn = conectar()
        cursor = conn.cursor()
        # Garante coluna email em bancos antigos.
        cols = {row[1] for row in cursor.execute("PRAGMA table_info(professores)")}
        if "email" not in cols:
            cursor.execute("ALTER TABLE professores ADD COLUMN email TEXT")
        resumo = atualizar(cursor, pares)
        conn.commit()
    except Exception as error:  # noqa: BLE001
        print(f"Erro ao atualizar: {error}", file=sys.stderr)
        return 1
    finally:
        fechar()

    print(f"CSV: {resumo['csv']} linhas com e-mail")
    print(f"Professores no banco: {resumo['professores']}")
    print(f"E-mails em professores atualizados: {resumo['atualizados']}")
    print(f"Professores já iguais: {resumo['ja_iguais']}")
    print(f"Usuários atualizados (pelo CPF): {resumo['usuarios_atualizados']}")
    print(f"Usuários já iguais: {resumo['usuarios_iguais']}")
    print(f"Sem correspondência no banco: {resumo['sem_match']}")
    print(f"Nomes ambíguos: {resumo['ambiguos']}")
    for aviso in resumo["avisos"]:
        print(f"  - {aviso}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
