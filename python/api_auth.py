"""Le configuracao da API no SQLite e obtem access token OAuth."""

from __future__ import annotations

import json
import ssl
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

from db import conectar, fechar, row_to_dict

USER_AGENT = "MAPA/1.0"

CHAVES_API = (
    "api_oauth_url",
    "api_client_id",
    "api_client_secret",
    "api_url_matriculados",
    "api_url_alunos",
    "api_verify_ssl",
    "api_periodo_letivo",
    "frequencia_data_inicial",
    "frequencia_data_final",
    "data_referencia",
)


def carregar_config_api() -> dict[str, str]:
    """Le as chaves da API na tabela configuracoes."""
    conn = conectar()
    cursor = conn.cursor()
    placeholders = ",".join("?" for _ in CHAVES_API)
    cursor.execute(
        f"SELECT chave, valor FROM configuracoes WHERE chave IN ({placeholders})",
        CHAVES_API,
    )

    config: dict[str, str] = {chave: "" for chave in CHAVES_API}
    for row in cursor.fetchall():
        item = row_to_dict(row)
        chave = str(item.get("chave", ""))
        if chave in config:
            config[chave] = str(item.get("valor") or "").strip()

    return config


def verificar_ssl(config: dict[str, str] | None = None) -> bool:
    """Indica se a validacao de certificado SSL esta ativa."""
    config = config if config is not None else carregar_config_api()
    valor = (config.get("api_verify_ssl") or "false").strip().lower()
    return valor in ("1", "true", "yes", "on")


def obter_access_token(config: dict[str, str] | None = None) -> str:
    """Obtem Bearer token via OAuth client_credentials (config no banco)."""
    config = config if config is not None else carregar_config_api()

    client_id = (config.get("api_client_id") or "").strip()
    client_secret = (config.get("api_client_secret") or "").strip()
    oauth_url = (config.get("api_oauth_url") or "").strip()

    if not oauth_url or not client_id or not client_secret:
        raise ValueError(
            "API nao configurada. Acesse /index.php/configuracoes/api "
            "e informe URL OAuth, Client ID e Client Secret."
        )

    corpo = json.dumps(
        {
            "grant_type": "client_credentials",
            "client_id": client_id,
            "client_secret": client_secret,
        }
    ).encode("utf-8")

    request = Request(
        oauth_url,
        data=corpo,
        headers={
            "Accept": "application/json",
            "Content-Type": "application/json",
            "User-Agent": USER_AGENT,
        },
        method="POST",
    )

    context = None if verificar_ssl(config) else ssl._create_unverified_context()
    try:
        with urlopen(request, timeout=60, context=context) as response:
            payload: Any = json.loads(response.read().decode("utf-8"))
    except HTTPError as error:
        detalhe = error.read().decode("utf-8", errors="replace")
        raise RuntimeError(
            f"Falha OAuth HTTP {error.code}: {detalhe}"
        ) from error
    except URLError as error:
        raise RuntimeError(f"Falha de conexao OAuth: {error.reason}") from error

    token = str(payload.get("access_token") or "").strip()
    if not token:
        raise RuntimeError(f"Resposta OAuth sem access_token: {payload}")

    return token


def ssl_context(config: dict[str, str] | None = None):
    """Contexto SSL conforme api_verify_ssl no banco."""
    if verificar_ssl(config):
        return None
    return ssl._create_unverified_context()


def url_matriculados(
    config: dict[str, str] | None = None,
    periodo: str | None = None,
) -> str:
    """URL de matriculados com periodo_letivo aplicado na execucao.

    Args:
        config: Config da API (banco). Se None, le do SQLite.
        periodo: Se informado, usa este periodo em vez de api_periodo_letivo.
    """
    import re

    config = config if config is not None else carregar_config_api()
    url = (config.get("api_url_matriculados") or "").strip()
    if not url:
        raise ValueError(
            "URL de matriculados ausente. Configure em /index.php/configuracoes/api"
        )

    # URL base nao deve trazer periodo; remove se ainda estiver gravado.
    url = url.replace("{periodo_letivo}", "")
    url = re.sub(r"([?&])periodo_letivo=[^&]*", r"\1", url)
    url = re.sub(r"\?&+", "?", url)
    url = re.sub(r"&&+", "&", url)
    url = re.sub(r"[?&]$", "", url)

    periodo_final = (periodo if periodo is not None else config.get("api_periodo_letivo") or "").strip()
    if not periodo_final:
        return url

    sep = "&" if "?" in url else "?"
    return f"{url}{sep}periodo_letivo={periodo_final}"


def url_alunos(config: dict[str, str] | None = None) -> str:
    """URL de alunos configurada no banco (com {login})."""
    config = config if config is not None else carregar_config_api()
    url = (config.get("api_url_alunos") or "").strip()
    if not url:
        raise ValueError(
            "URL de alunos ausente. Configure em /index.php/configuracoes/api"
        )
    if "{login}" not in url:
        raise ValueError("URL de alunos deve conter o marcador {login}.")
    return url
