#!/usr/bin/env python3
"""Gera candidatos a perda de vaga (manual).

Consulta os dois semestres anteriores ao periodo atual da API e grava no SQLite
os alunos que reprovaram em todas as disciplinas em ambos.

Uso:
    python3 gerar_perda_vaga.py
    python3 gerar_perda_vaga.py 2026/2
"""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

from api_auth import (
    USER_AGENT,
    carregar_config_api,
    obter_access_token,
    ssl_context,
    url_matriculados,
    verificar_ssl,
)
from db import conectar, fechar, row_to_dict
from paths import DIR_JSON, garantir_diretorios


def periodo_para_arquivo(periodo: str) -> str:
    """Converte 2026/1 em 2026_1."""
    return re.sub(r"[^\w.-]+", "_", periodo.strip())


def semestres_anteriores(periodo: str) -> tuple[str, str]:
    """Dois semestres imediatamente anteriores (mais antigo, mais recente).

    Ex.: 2026/1 -> (2025/1, 2025/2); 2026/2 -> (2025/2, 2026/1).
    """
    texto = periodo.strip()
    partes = texto.split("/")
    if len(partes) != 2:
        raise ValueError(f"Periodo invalido: {periodo!r} (use AAAA/S).")
    ano = int(partes[0])
    sem = int(partes[1])
    if sem not in (1, 2):
        raise ValueError(f"Semestre invalido em {periodo!r} (use 1 ou 2).")

    anteriores: list[str] = []
    for _ in range(2):
        if sem == 1:
            ano -= 1
            sem = 2
        else:
            sem = 1
        anteriores.append(f"{ano}/{sem}")

    # anteriores[0] = imediatamente anterior; [1] = um antes.
    # Ordem cronologica: mais antigo primeiro.
    return anteriores[1], anteriores[0]


def consultar_webservice(url: str, token: str, config: dict[str, str]) -> tuple[int, str]:
    """GET autenticado."""
    request = Request(
        url,
        headers={
            "Accept": "application/json",
            "Authorization": f"Bearer {token}",
            "User-Agent": USER_AGENT,
        },
        method="GET",
    )
    with urlopen(request, timeout=180, context=ssl_context(config)) as response:
        return response.getcode(), response.read().decode("utf-8")


def carregar_registros(dados: Any) -> list[dict[str, Any]]:
    """Normaliza resposta da API em lista."""
    if isinstance(dados, dict) and isinstance(dados.get("data"), list):
        dados = dados["data"]
    elif isinstance(dados, dict):
        dados = list(dados.values())
    if not isinstance(dados, list):
        raise ValueError("Formato inesperado na resposta de matriculados.")
    return [r for r in dados if isinstance(r, dict)]


def eh_reprovado(situacao: str) -> bool:
    """Situacao de reprovacao (qualquer variante REPROV*)."""
    return "REPROV" in (situacao or "").upper()


def disciplinas_do_periodo(
    registro: dict[str, Any],
    periodo: str,
) -> list[dict[str, Any]]:
    """Disciplinas do registro com o periodo informado."""
    return [
        d
        for d in (registro.get("disciplinas") or [])
        if isinstance(d, dict) and str(d.get("periodo") or "").strip() == periodo
    ]


def todas_reprovadas(disciplinas: list[dict[str, Any]]) -> bool:
    """True se ha >=1 disciplina e todas estao reprovadas."""
    if not disciplinas:
        return False
    return all(eh_reprovado(str(d.get("situacao_matricula") or "")) for d in disciplinas)


def chave_aluno_curso(registro: dict[str, Any]) -> tuple[str, str]:
    """Chave estavel entre semestres: login + curso."""
    login = str(registro.get("login") or "").strip()
    curso = str(registro.get("curso") or "").strip() or "Curso nao informado"
    return login, curso


def mapa_todas_reprovadas(
    registros: list[dict[str, Any]],
    periodo: str,
) -> dict[tuple[str, str], dict[str, Any]]:
    """Mapa (login, curso) -> dados do aluno + disciplinas reprovadas."""
    saida: dict[tuple[str, str], dict[str, Any]] = {}
    for registro in registros:
        chave = chave_aluno_curso(registro)
        if chave[0] == "":
            continue
        discs = disciplinas_do_periodo(registro, periodo)
        if not todas_reprovadas(discs):
            continue
        saida[chave] = {
            "login": chave[0],
            "matricula": str(registro.get("matricula") or "").strip(),
            "nome": str(
                registro.get("nome_completo")
                or registro.get("nome_civil")
                or ""
            ).strip(),
            "nome_social": str(registro.get("nome_social") or "").strip() or None,
            "email": str(registro.get("email") or "").strip() or None,
            "nome_curso": chave[1],
            "disciplinas": discs,
        }
    return saida


def obter_matriculas_periodo(
    periodo: str,
    config: dict[str, str],
    token: str,
    *,
    forcar: bool = False,
) -> list[dict[str, Any]]:
    """Consulta API (ou reusa JSON em cache) para o periodo."""
    garantir_diretorios()
    caminho = DIR_JSON / f"resposta_matriculas_{periodo_para_arquivo(periodo)}.json"

    if caminho.is_file() and not forcar:
        print(f"Usando cache: {caminho}")
        dados = json.loads(caminho.read_text(encoding="utf-8"))
        return carregar_registros(dados)

    url = url_matriculados(config, periodo=periodo)
    print(f"Consultando API periodo {periodo}...")
    print(f"URL: {url}")
    status, body = consultar_webservice(url, token, config)
    if status != 200:
        raise RuntimeError(f"HTTP {status} ao consultar {periodo}")
    dados = json.loads(body)
    caminho.write_text(json.dumps(dados, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"Salvo em: {caminho}")
    return carregar_registros(dados)


def upsert_aluno(cursor: Any, candidato: dict[str, Any]) -> int:
    """Insere/atualiza aluno e retorna id."""
    login = candidato["login"]
    matricula = candidato["matricula"] or login
    nome = candidato["nome"] or login
    nome_social = candidato.get("nome_social")
    email = candidato.get("email")
    cursor.execute(
        """
        INSERT INTO alunos (login, matricula, nome, nome_social, email)
        VALUES (?, ?, ?, ?, ?)
        ON CONFLICT(login, matricula) DO UPDATE SET
            nome = excluded.nome,
            nome_social = excluded.nome_social,
            email = COALESCE(excluded.email, alunos.email)
        """,
        (login, matricula, nome, nome_social, email),
    )
    cursor.execute(
        "SELECT id FROM alunos WHERE login = ? AND matricula = ?",
        (login, matricula),
    )
    return int(row_to_dict(cursor.fetchone())["id"])


def upsert_curso(cursor: Any, nome_curso: str) -> int:
    """Insere/reutiliza curso e retorna id."""
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
    return int(row_to_dict(cursor.fetchone())["id"])


def gravar_candidatos(
    periodo_atual: str,
    semestre_a: str,
    semestre_b: str,
    candidatos: list[dict[str, Any]],
) -> int:
    """Substitui snapshot anterior e grava nova execucao."""
    conn = conectar()
    cursor = conn.cursor()

    cursor.execute("DELETE FROM perda_vaga_reprovacoes")
    cursor.execute("DELETE FROM perda_vaga_candidatos")
    cursor.execute("DELETE FROM perda_vaga_execucoes")

    cursor.execute(
        """
        INSERT INTO perda_vaga_execucoes (
            periodo_atual, semestre_a, semestre_b, total_candidatos
        ) VALUES (?, ?, ?, ?)
        """,
        (periodo_atual, semestre_a, semestre_b, len(candidatos)),
    )
    execucao_id = int(cursor.lastrowid)

    for cand in candidatos:
        aluno_id = upsert_aluno(cursor, cand)
        curso_id = upsert_curso(cursor, cand["nome_curso"])
        cursor.execute(
            """
            INSERT INTO perda_vaga_candidatos (
                execucao_id, aluno_id, curso_id, login, matricula,
                nome, nome_social, email, nome_curso
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            """,
            (
                execucao_id,
                aluno_id,
                curso_id,
                cand["login"],
                cand["matricula"],
                cand["nome"],
                cand.get("nome_social"),
                cand.get("email"),
                cand["nome_curso"],
            ),
        )
        candidato_id = int(cursor.lastrowid)
        for semestre, discs in (
            (semestre_a, cand["disciplinas_a"]),
            (semestre_b, cand["disciplinas_b"]),
        ):
            for d in discs:
                cursor.execute(
                    """
                    INSERT INTO perda_vaga_reprovacoes (
                        candidato_id, semestre, disciplina, cod_disciplina,
                        id_disciplina, causa
                    ) VALUES (?, ?, ?, ?, ?, ?)
                    """,
                    (
                        candidato_id,
                        semestre,
                        str(d.get("disciplina") or "").strip(),
                        str(d.get("cod_disciplina") or "").strip(),
                        d.get("id_disciplina"),
                        str(d.get("situacao_matricula") or "").strip(),
                    ),
                )

    conn.commit()
    return execucao_id


def main(argv: list[str] | None = None) -> int:
    """Ponto de entrada."""
    argv = list(argv if argv is not None else sys.argv[1:])
    forcar = "--forcar" in argv
    argv = [a for a in argv if a != "--forcar"]

    try:
        config = carregar_config_api()
        periodo_atual = (argv[0] if argv else config.get("api_periodo_letivo") or "").strip()
        if not periodo_atual:
            print(
                "Periodo atual ausente. Configure em /configuracoes/api "
                "ou passe como argumento (ex.: 2026/2).",
                file=sys.stderr,
            )
            return 1
        semestre_a, semestre_b = semestres_anteriores(periodo_atual)
    except ValueError as error:
        print(f"Erro: {error}", file=sys.stderr)
        return 1

    print("Candidatos a perda de vaga")
    print(f"Periodo atual: {periodo_atual}")
    print(f"Semestres analisados: {semestre_a} e {semestre_b}")
    if not verificar_ssl(config):
        print("Aviso: verificacao SSL desativada (api_verify_ssl=false).")
    print()

    try:
        token = obter_access_token(config)
        print("Access token OAuth obtido.")
        regs_a = obter_matriculas_periodo(
            semestre_a, config, token, forcar=forcar
        )
        regs_b = obter_matriculas_periodo(
            semestre_b, config, token, forcar=forcar
        )
    except (ValueError, RuntimeError, HTTPError, URLError, TimeoutError, json.JSONDecodeError) as error:
        print(f"Erro na consulta: {error}", file=sys.stderr)
        fechar()
        return 1

    mapa_a = mapa_todas_reprovadas(regs_a, semestre_a)
    mapa_b = mapa_todas_reprovadas(regs_b, semestre_b)
    chaves = sorted(
        set(mapa_a) & set(mapa_b),
        key=lambda k: (
            k[1].upper(),
            (mapa_b[k]["nome_social"] or mapa_b[k]["nome"] or "").upper(),
        ),
    )

    candidatos: list[dict[str, Any]] = []
    for chave in chaves:
        a = mapa_a[chave]
        b = mapa_b[chave]
        candidatos.append({
            "login": b["login"],
            "matricula": b["matricula"] or a["matricula"],
            "nome": b["nome"] or a["nome"],
            "nome_social": b.get("nome_social") or a.get("nome_social"),
            "email": b.get("email") or a.get("email"),
            "nome_curso": b["nome_curso"],
            "disciplinas_a": a["disciplinas"],
            "disciplinas_b": b["disciplinas"],
        })

    print(
        f"\nTodas reprovadas em {semestre_a}: {len(mapa_a)}; "
        f"em {semestre_b}: {len(mapa_b)}; "
        f"nos dois: {len(candidatos)}"
    )

    execucao_id: int | None = None
    try:
        execucao_id = gravar_candidatos(
            periodo_atual, semestre_a, semestre_b, candidatos
        )
    except Exception as error:  # noqa: BLE001
        print(f"Erro ao gravar no banco: {error}", file=sys.stderr)
        return 1
    finally:
        fechar()

    print(f"Execucao #{execucao_id} gravada com {len(candidatos)} candidato(s).")
    print("Tela: /index.php/perda-vaga")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
