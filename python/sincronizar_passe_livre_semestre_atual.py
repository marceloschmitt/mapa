#!/usr/bin/env python3
"""Sincroniza o semestre atual (coleta) em passe_livre_*.

Le tabela_frequencia.json (saida de analisar_frequencia.py, a partir de
resposta_alunos.json) e grava/atualiza o periodo de api_periodo_letivo
nas tabelas passe_livre_aluno_curso / passe_livre_disciplina.

Assim o relatorio de frequencia anual ve o semestre corrente sem depender
do botao "Gerar passe livre" (que cobre so semestres anteriores).

Uso (tambem chamado por executar_coleta.py apos analisar_frequencia):

    python3 sincronizar_passe_livre_semestre_atual.py
"""

from __future__ import annotations

import json
import sys
import time
from typing import Any

from api_auth import carregar_config_api
from db import fechar
from gerar_passe_livre import (
    gravar_banco,
    validar_periodo,
)
from paths import JSON_TABELA_FREQUENCIA, garantir_diretorios


def carregar_tabela_frequencia() -> list[dict[str, Any]]:
    """Le tabela_frequencia.json."""
    if not JSON_TABELA_FREQUENCIA.is_file():
        raise FileNotFoundError(
            f"Arquivo nao encontrado: {JSON_TABELA_FREQUENCIA}. "
            "Rode antes: python3 analisar_frequencia.py"
        )
    dados = json.loads(JSON_TABELA_FREQUENCIA.read_text(encoding="utf-8"))
    if not isinstance(dados, list):
        raise ValueError("tabela_frequencia.json deve ser uma lista.")
    return [r for r in dados if isinstance(r, dict)]


def normalizar_registros(linhas: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """Adapta linhas da coleta ao formato esperado por inserir_registros."""
    saida: list[dict[str, Any]] = []
    for linha in linhas:
        login = str(linha.get("login") or "").strip()
        if login == "":
            continue
        geral = linha.get("frequencia_geral")
        if not isinstance(geral, dict):
            geral = {}
        disciplinas_brutas = linha.get("disciplinas") or []
        disciplinas: list[dict[str, Any]] = []
        if isinstance(disciplinas_brutas, list):
            for disc in disciplinas_brutas:
                if not isinstance(disc, dict):
                    continue
                disciplinas.append({
                    "codigo_disciplina": str(
                        disc.get("codigo_disciplina") or ""
                    ).strip(),
                    "disciplina": str(disc.get("disciplina") or "").strip(),
                    "percentual_frequencia": disc.get("percentual_frequencia"),
                    "situacao": (
                        str(disc.get("situacao") or "").strip() or None
                    ),
                })
        saida.append({
            "login": login,
            "matricula": linha.get("matricula") or "",
            "nome": linha.get("nome") or login,
            "nome_social": linha.get("nome_social"),
            "email": linha.get("email"),
            "nome_curso": linha.get("nome_curso") or "",
            "frequencia_geral": {
                "percentual_frequencia_total": geral.get(
                    "percentual_frequencia_total"
                ),
            },
            "disciplinas": disciplinas,
        })
    return saida


def main() -> int:
    """Ponto de entrada."""
    garantir_diretorios()
    try:
        config = carregar_config_api()
        periodo = validar_periodo(
            (config.get("api_periodo_letivo") or "").strip()
        )
        data_inicial = (config.get("frequencia_data_inicial") or "").strip()
        data_final = (config.get("frequencia_data_final") or "").strip()
        if not data_inicial or not data_final:
            raise ValueError(
                "frequencia_data_inicial/final ausentes na configuracao da API."
            )
        linhas = carregar_tabela_frequencia()
        registros = normalizar_registros(linhas)
    except (FileNotFoundError, ValueError, json.JSONDecodeError) as error:
        print(f"Erro: {error}", file=sys.stderr)
        fechar()
        return 1

    print("Sincronizar semestre atual → passe_livre_*")
    print(f"Periodo: {periodo}")
    print(f"Intervalo: {data_inicial} a {data_final}")
    print(f"Registros na tabela_frequencia: {len(linhas)}")
    print(f"Registros a gravar: {len(registros)}")

    gerado_em = time.strftime("%Y-%m-%d %H:%M:%S")
    total = gravar_banco(
        registros,
        periodo=periodo,
        data_inicial=data_inicial,
        data_final=data_final,
        gerado_em=gerado_em,
    )
    print(f"Gravados em passe_livre_aluno_curso: {total}")
    fechar()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
