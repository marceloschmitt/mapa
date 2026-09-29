#!/usr/bin/env python3
"""Consulta inicial ao webservice SIGAA.

Le URL e credenciais OAuth da tabela configuracoes (tela /configuracoes/api).

Consulta o periodo corrente (api_periodo_letivo) e tambem os SEMESTRES_RETROATIVOS
anteriores, em duas variantes cada: matriculado=sim (padrao da URL gravada) e
matriculado=nao. Arquivos gerados em data/json/:

    resposta_matriculas.json                        corrente, matriculados
    resposta_matriculas_AAAA_S.json                 anteriores, matriculados
    resposta_matriculas_AAAA_S_naomatriculados.json todos, nao-matriculados

Tudo isso serve a 2a consulta, que e quem revela vinculos trancados. Quem trancou
nao tem matricula em disciplina, entao some da consulta padrao e do periodo
corrente — o TRANC. AUTOMATICO so e aplicado no fechamento do semestre.

A API exige periodo_letivo quando matriculado=nao (sem ele devolve HTTP 500), por
isso a varredura e periodo a periodo.

Uso:
    python3 consulta_inicial.py
    python3 consulta_inicial.py --semestres-retroativos 0
"""

from __future__ import annotations

import argparse
import json
import sys
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
from paths import DIR_JSON, JSON_RESPOSTA_MATRICULAS, garantir_diretorios
from periodo_letivo import periodo_para_arquivo, ultimos_semestres_anteriores

ARQUIVO_SAIDA = JSON_RESPOSTA_MATRICULAS

# Semestres anteriores consultados alem do corrente.
SEMESTRES_RETROATIVOS = 2


def consultar_webservice(url: str, token: str, config: dict[str, str] | None = None) -> tuple[int, str]:
    """Executa uma requisicao GET autenticada ao webservice."""
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
        status = response.getcode()
        body = response.read().decode("utf-8")

    return status, body


def formatar_resposta(body: str) -> str:
    """Formata o corpo da resposta para exibicao."""
    try:
        data: Any = json.loads(body)
    except json.JSONDecodeError:
        return body

    return json.dumps(data, ensure_ascii=False, indent=2)


def resumir_resposta(body: str) -> str:
    """Monta um resumo curto da resposta para exibicao no terminal."""
    try:
        data: Any = json.loads(body)
    except json.JSONDecodeError:
        return f"Resposta nao e JSON valido ({len(body)} caracteres)."

    linhas = []

    if isinstance(data, dict):
        if isinstance(data.get("data"), list):
            linhas.append(f"Registros na chave 'data': {len(data['data'])}")
        elif data and all(isinstance(v, dict) for v in list(data.values())[:3]):
            linhas.append(f"Registros (mapa completo): {len(data)}")
            com_docentes = 0
            for registro in data.values():
                disciplinas = registro.get("disciplinas") or []
                if not isinstance(disciplinas, list):
                    continue
                if any(
                    isinstance(d, dict) and d.get("docentes")
                    for d in disciplinas
                ):
                    com_docentes += 1
            linhas.append(f"Com docentes em disciplinas: {com_docentes}")
        for chave in ("total", "per_page", "current_page", "last_page", "next_page_url"):
            if chave in data:
                linhas.append(f"{chave}: {data[chave]}")
    elif isinstance(data, list):
        linhas.append(f"Registros na lista: {len(data)}")

    if not linhas:
        linhas.append(f"JSON valido com {len(body)} caracteres.")

    return "\n".join(linhas)


def _baixar_matriculados(
    config: dict[str, str],
    token: str,
    periodo: str,
    caminho,
    *,
    matriculado: str | None = None,
) -> bool:
    """Grava um JSON de matriculados. Devolve False em falha (sem interromper)."""
    try:
        url = url_matriculados(config, periodo=periodo, matriculado=matriculado)
        status, body = consultar_webservice(url, token, config)
        if status != 200:
            raise RuntimeError(f"HTTP {status}")
        json.loads(body)
    except (ValueError, RuntimeError, HTTPError, URLError, TimeoutError) as error:
        print(f"  {periodo} ({matriculado or 'padrao'}): falhou ({error}) — cache anterior mantido.")
        return False

    caminho.write_text(formatar_resposta(body), encoding="utf-8")
    print(f"  {periodo} ({matriculado or 'padrao'}): {caminho.name}")
    return True


def coletar_cobertura_extra(
    config: dict[str, str],
    token: str,
    quantidade: int,
) -> None:
    """Amplia a cobertura da 2a consulta com semestres anteriores e nao-matriculados.

    Para cada periodo (corrente + anteriores) grava tambem a variante
    matriculado=nao, onde vivem os vinculos trancados: quem trancou nao tem
    matricula em disciplina e por isso some da consulta padrao.

    O periodo corrente ja foi gravado em resposta_matriculas.json e nao e
    reescrito aqui — importar_professores e importar_grade dependem dele
    contendo apenas matriculados. Falhas nao interrompem a coleta.
    """
    periodo_atual = (config.get("api_periodo_letivo") or "").strip()
    if periodo_atual == "":
        return

    try:
        periodos = [periodo_atual, *ultimos_semestres_anteriores(periodo_atual, quantidade)]
    except ValueError as error:
        print(f"Aviso: periodo letivo invalido ({error}).", file=sys.stderr)
        return

    sufixo = periodo_para_arquivo
    for indice, periodo in enumerate(periodos):
        if indice > 0:
            _baixar_matriculados(
                config,
                token,
                periodo,
                DIR_JSON / f"resposta_matriculas_{sufixo(periodo)}.json",
            )
        _baixar_matriculados(
            config,
            token,
            periodo,
            DIR_JSON / f"resposta_matriculas_{sufixo(periodo)}_naomatriculados.json",
            matriculado="nao",
        )


def main() -> int:
    """Ponto de entrada do script."""
    parser = argparse.ArgumentParser(description="Consulta matriculados no SIGAA.")
    parser.add_argument(
        "--semestres-retroativos",
        type=int,
        default=SEMESTRES_RETROATIVOS,
        help=f"Semestres anteriores alem do corrente (padrao: {SEMESTRES_RETROATIVOS}).",
    )
    args = parser.parse_args()

    garantir_diretorios()

    try:
        config = carregar_config_api()
        url = url_matriculados(config)
    except ValueError as error:
        print(f"Erro de configuracao: {error}", file=sys.stderr)
        return 1

    print("Consulta inicial ao webservice SIGAA")
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

    ARQUIVO_SAIDA.write_text(formatar_resposta(body), encoding="utf-8")

    print(f"Status HTTP: {status}")
    print(resumir_resposta(body))
    print()
    print(f"Resposta completa salva em: {ARQUIVO_SAIDA}")

    print(
        f"\nCobertura extra ({args.semestres_retroativos} semestre(s) anterior(es) "
        "+ nao-matriculados de cada periodo):"
    )
    coletar_cobertura_extra(config, token, max(0, args.semestres_retroativos))

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
