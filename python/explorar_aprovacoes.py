#!/usr/bin/env python3
"""Consulta matriculados de um periodo e resume aprovacoes/reprovacoes.

Uso exploratorio (ainda fora do pipeline de coleta):

    python3 explorar_aprovacoes.py
    python3 explorar_aprovacoes.py 2026/1
    python3 explorar_aprovacoes.py 2025/2

Salva em data/json/resposta_matriculas_<periodo>.json e imprime contagens
de situacao_matricula (todas as disciplinas e so as do periodo pedido).
"""

from __future__ import annotations

import json
import re
import sys
from collections import Counter
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
from db import fechar
from paths import DIR_JSON, garantir_diretorios

PERIODO_PADRAO = "2026/1"


def periodo_para_arquivo(periodo: str) -> str:
    """Converte 2026/1 em 2026_1 para nome de arquivo."""
    return re.sub(r"[^\w.-]+", "_", periodo.strip())


def consultar_webservice(url: str, token: str, config: dict[str, str]) -> tuple[int, str]:
    """GET autenticado ao webservice."""
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
    """Normaliza a resposta da API em lista de registros."""
    if isinstance(dados, dict) and isinstance(dados.get("data"), list):
        dados = dados["data"]
    elif isinstance(dados, dict):
        dados = list(dados.values())
    if not isinstance(dados, list):
        raise ValueError("Formato inesperado: esperava lista ou mapa de matriculas.")
    return [r for r in dados if isinstance(r, dict)]


def eh_reprovado(situacao: str) -> bool:
    """Indica se a situacao e de reprovacao."""
    return "REPROV" in (situacao or "").upper()


def eh_aprovado(situacao: str) -> bool:
    """Indica se a situacao e de aprovacao."""
    texto = (situacao or "").strip().upper()
    return texto == "APROVADO" or texto.startswith("APROVADO")


def analisar(registros: list[dict[str, Any]], periodo: str) -> None:
    """Imprime resumo de status e situacao_matricula."""
    status_aluno = Counter(str(r.get("status") or "") for r in registros)

    sit_todas: Counter[str] = Counter()
    sit_periodo: Counter[str] = Counter()
    periodos_disc: Counter[str] = Counter()
    alunos_com_disc_periodo = 0
    alunos_todas_reprovadas = 0
    alunos_so_aprovadas = 0
    alunos_misto = 0
    alunos_sem_resultado = 0
    exemplos_todas_reprov: list[str] = []

    for r in registros:
        discs = [d for d in (r.get("disciplinas") or []) if isinstance(d, dict)]
        do_periodo = [d for d in discs if str(d.get("periodo") or "").strip() == periodo]

        for d in discs:
            sit = str(d.get("situacao_matricula") or "")
            sit_todas[sit] += 1
            periodos_disc[str(d.get("periodo") or "(vazio)")] += 1

        if not do_periodo:
            continue

        alunos_com_disc_periodo += 1
        for d in do_periodo:
            sit_periodo[str(d.get("situacao_matricula") or "")] += 1

        situacoes = [str(d.get("situacao_matricula") or "") for d in do_periodo]
        # Ignora MATRICULADO / aguardando ao classificar "todas reprovadas"
        finais = [s for s in situacoes if s and "MATRICULADO" not in s.upper()
                  and "AGUARDANDO" not in s.upper()]
        if not finais:
            alunos_sem_resultado += 1
            continue

        reprov = [s for s in finais if eh_reprovado(s)]
        aprov = [s for s in finais if eh_aprovado(s)]
        if reprov and len(reprov) == len(finais):
            alunos_todas_reprovadas += 1
            if len(exemplos_todas_reprov) < 5:
                nome = r.get("nome_completo") or r.get("nome_civil") or "?"
                exemplos_todas_reprov.append(
                    f"{r.get('matricula')} {nome} ({len(finais)} disc.)"
                )
        elif aprov and len(aprov) == len(finais):
            alunos_so_aprovadas += 1
        else:
            alunos_misto += 1

    print(f"\nMatriculas na resposta: {len(registros)}")
    print("Status do aluno (campo status):")
    for k, n in status_aluno.most_common():
        print(f"  {n:5d}  {k}")

    print("\nPeriodos nas disciplinas (todas):")
    for k, n in periodos_disc.most_common(15):
        print(f"  {n:5d}  {k}")
    if len(periodos_disc) > 15:
        print(f"  ... +{len(periodos_disc) - 15} periodos")

    print("\nsituacao_matricula — todas as disciplinas da resposta:")
    for k, n in sit_todas.most_common():
        print(f"  {n:5d}  {k or '(vazio)'}")

    print(f"\nsituacao_matricula — so disciplinas com periodo={periodo}:")
    if not sit_periodo:
        print("  (nenhuma disciplina com esse periodo no JSON)")
    else:
        for k, n in sit_periodo.most_common():
            print(f"  {n:5d}  {k or '(vazio)'}")

    print(f"\nAlunos com >=1 disciplina em {periodo}: {alunos_com_disc_periodo}")
    print(f"  so finais APROVADO*: {alunos_so_aprovadas}")
    print(f"  so finais REPROV*: {alunos_todas_reprovadas}")
    print(f"  misto aprov/reprov/outros: {alunos_misto}")
    print(f"  so MATRICULADO/aguardando (sem resultado final): {alunos_sem_resultado}")
    if exemplos_todas_reprov:
        print("  exemplos todas reprovadas:")
        for ex in exemplos_todas_reprov:
            print(f"    - {ex}")


def main(argv: list[str] | None = None) -> int:
    """Consulta um periodo e resume aprovacoes/reprovacoes."""
    argv = argv if argv is not None else sys.argv[1:]
    periodo = (argv[0] if argv else PERIODO_PADRAO).strip()
    if not periodo:
        print("Informe o periodo (ex.: 2026/1).", file=sys.stderr)
        return 1

    garantir_diretorios()
    saida = DIR_JSON / f"resposta_matriculas_{periodo_para_arquivo(periodo)}.json"

    try:
        config = carregar_config_api()
        url = url_matriculados(config, periodo=periodo)
    except ValueError as error:
        print(f"Erro de configuracao: {error}", file=sys.stderr)
        return 1

    print(f"Explorar aprovacoes/reprovacoes — periodo {periodo}")
    print(f"URL: {url}")
    if not verificar_ssl(config):
        print("Aviso: verificacao SSL desativada (api_verify_ssl=false).")
    print()

    try:
        token = obter_access_token(config)
        print("Access token OAuth obtido.")
        status, body = consultar_webservice(url, token, config)
    except (ValueError, RuntimeError) as error:
        print(f"Erro de autenticacao: {error}", file=sys.stderr)
        return 1
    except HTTPError as error:
        print(f"Erro HTTP: {error.code}", file=sys.stderr)
        print(error.read().decode("utf-8", errors="replace"), file=sys.stderr)
        return 1
    except URLError as error:
        print(f"Erro de conexao: {error.reason}", file=sys.stderr)
        return 1
    except TimeoutError:
        print("Erro: tempo limite excedido.", file=sys.stderr)
        return 1
    finally:
        fechar()

    try:
        dados = json.loads(body)
        registros = carregar_registros(dados)
    except (json.JSONDecodeError, ValueError) as error:
        print(f"Erro ao interpretar resposta: {error}", file=sys.stderr)
        return 1

    saida.write_text(json.dumps(dados, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"Status HTTP: {status}")
    print(f"Salvo em: {saida}")
    analisar(registros, periodo)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
