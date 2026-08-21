#!/usr/bin/env python3
"""Consulta inicial ao webservice SIGAA.

Le URL e credenciais OAuth da tabela configuracoes (tela /configuracoes/api).

Uso:
    python3 consulta_inicial.py
"""

from __future__ import annotations

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
from paths import JSON_RESPOSTA_MATRICULAS, garantir_diretorios

ARQUIVO_SAIDA = JSON_RESPOSTA_MATRICULAS


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


def main() -> int:
    """Ponto de entrada do script."""
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

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
