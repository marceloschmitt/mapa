#!/usr/bin/env python3
"""Consulta em massa (todos os alunos da unidade) e grava as respostas cruas.

As URLs vem da configuracao da API (tabela configuracoes):

1. api_url_alunos_massa_cadastro (~2 s): cadastro de cada aluno com email,
   nome_social e cursos (matricula, nome_curso, turma_entrada,
   ano_semestre_ingresso, status_discente). Salvo em
   resposta_alunos_massa_cadastro.json. Lido por importar_trancados.py e
   analisar_frequencia.py.
2. api_url_alunos_massa_intervalo (~1 min): um registro por vinculo
   (aluno x curso) com status_discente, totais, disciplinas e
   ausencias_especiais (o campo so aparece quando ha dados). Salvo em
   resposta_alunos_massa_intervalo.json. Lido por analisar_frequencia.py.

Na URL do intervalo, {data_inicial} e {data_final} sao trocados pelas datas de
frequencia_data_inicial / frequencia_data_final (DD-MM-AAAA), convertidas para
o formato exigido pelo endpoint (AAAA-MM-DD).

Primeiro passo de executar_coleta.py. Cada arquivo so e substituido quando a
sua consulta da certo; em falha, o anterior e mantido.

Uso:
    python3 consulta_alunos_massa.py
    python3 consulta_alunos_massa.py --data-inicial 03-08-2026 --data-final 31-12-2026
"""

from __future__ import annotations

import argparse
import collections
import json
import sys
import time
from pathlib import Path
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

from api_auth import (
    USER_AGENT,
    carregar_config_api,
    obter_access_token,
    ssl_context,
    verificar_ssl,
)
from config_consultas import frequencia_data_final, frequencia_data_inicial
from paths import (
    JSON_RESPOSTA_ALUNOS_MASSA,
    JSON_RESPOSTA_ALUNOS_MASSA_CADASTRO,
    garantir_diretorios,
)

PAGINA_CONFIG = "/index.php/configuracoes/api"

# A resposta do intervalo leva cerca de 1 minuto para ~6.700 vinculos.
TIMEOUT_SEGUNDOS = 600
TIMEOUT_CADASTRO_SEGUNDOS = 120
TENTATIVAS = 3
ESPERA_ENTRE_TENTATIVAS = 30


def url_cadastro(config: dict[str, str]) -> str:
    """URL do cadastro em massa configurada no banco."""
    url = (config.get("api_url_alunos_massa_cadastro") or "").strip()
    if not url:
        raise ValueError(f"URL do cadastro em massa ausente. Configure em {PAGINA_CONFIG}")
    return url


def url_intervalo(config: dict[str, str], data_inicial: str, data_final: str) -> str:
    """URL do intervalo em massa com {data_inicial} e {data_final} (AAAA-MM-DD)."""
    url = (config.get("api_url_alunos_massa_intervalo") or "").strip()
    if not url:
        raise ValueError(f"URL do intervalo em massa ausente. Configure em {PAGINA_CONFIG}")
    if "{data_inicial}" not in url or "{data_final}" not in url:
        raise ValueError(
            "URL do intervalo em massa deve conter {data_inicial} e {data_final}. "
            f"Ajuste em {PAGINA_CONFIG}"
        )
    return url.replace("{data_inicial}", data_inicial).replace("{data_final}", data_final)


def data_iso(data: str) -> str:
    """Converte DD-MM-AAAA (configuracao) para AAAA-MM-DD (endpoint)."""
    partes = data.strip().split("-")
    if len(partes) != 3 or len(partes[2]) != 4:
        raise ValueError(f"Data invalida (esperado DD-MM-AAAA): {data!r}")
    dia, mes, ano = partes
    return f"{ano}-{mes}-{dia}"


def consultar(url: str, token: str, config: dict[str, str], timeout: int) -> tuple[int, str]:
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
    with urlopen(request, timeout=timeout, context=ssl_context(config)) as response:
        return response.getcode(), response.read().decode("utf-8")


def consultar_com_tentativas(
    url: str,
    token: str,
    config: dict[str, str],
    timeout: int,
    tentativas: int,
) -> Any:
    """Consulta com novas tentativas em HTTP 5xx, timeout ou falha de conexao."""
    for tentativa in range(1, tentativas + 1):
        inicio = time.time()
        try:
            status, body = consultar(url, token, config, timeout)
            if status != 200:
                raise RuntimeError(f"HTTP {status}")
            dados = json.loads(body)
            print(f"HTTP {status} em {time.time() - inicio:.0f}s ({len(body) / 1e6:.1f} MB)")
            return dados
        except HTTPError as error:
            detalhe = error.read().decode("utf-8", errors="replace")[:300]
            if error.code < 500 or tentativa == tentativas:
                raise RuntimeError(f"HTTP {error.code}: {detalhe}") from error
            print(f"Tentativa {tentativa}: HTTP {error.code} — nova tentativa em {ESPERA_ENTRE_TENTATIVAS}s")
        except (URLError, TimeoutError) as error:
            if tentativa == tentativas:
                raise RuntimeError(f"Falha de conexao: {error}") from error
            print(f"Tentativa {tentativa}: {error} — nova tentativa em {ESPERA_ENTRE_TENTATIVAS}s")
        time.sleep(ESPERA_ENTRE_TENTATIVAS)
    raise RuntimeError("Sem resposta da API.")


def salvar_json(dados: Any, caminho: Path) -> None:
    """Grava via arquivo temporario para nao deixar JSON truncado."""
    temporario = caminho.with_suffix(".json.tmp")
    temporario.write_text(json.dumps(dados, ensure_ascii=False, indent=2), encoding="utf-8")
    temporario.replace(caminho)


def imprimir_resumo_intervalo(registros: list[dict[str, Any]]) -> None:
    """Contagens rapidas para conferencia no terminal."""
    status = collections.Counter(str(r.get("status_discente")) for r in registros)
    com_disciplinas = sum(1 for r in registros if r.get("disciplinas"))
    com_ausencias = sum(1 for r in registros if r.get("ausencias_especiais"))
    print(f"Vinculos: {len(registros)}")
    print(f"Alunos (logins): {len({r.get('login') for r in registros})}")
    print(f"Vinculos com disciplinas: {com_disciplinas}")
    print(f"Vinculos com ausencias_especiais: {com_ausencias}")
    print("Status:")
    for nome, total in status.most_common():
        print(f"  {total:5d}  {nome}")


def consultar_cadastro(token: str, config: dict[str, str], tentativas: int) -> None:
    """Cadastro de todos os alunos → resposta_alunos_massa_cadastro.json."""
    url = url_cadastro(config)
    print("Consulta em massa — cadastro de alunos")
    print(f"URL: {url}")
    cadastro = consultar_com_tentativas(url, token, config, TIMEOUT_CADASTRO_SEGUNDOS, tentativas)
    if not isinstance(cadastro, dict):
        raise ValueError("Resposta do cadastro inesperada (esperava mapa login → aluno).")
    salvar_json(cadastro, JSON_RESPOSTA_ALUNOS_MASSA_CADASTRO)
    print(f"Registros: {len(cadastro)}")
    print(f"Salvo em: {JSON_RESPOSTA_ALUNOS_MASSA_CADASTRO}")


def consultar_intervalo(
    token: str,
    config: dict[str, str],
    args: argparse.Namespace,
    tentativas: int,
) -> None:
    """Frequencia por intervalo de todos → resposta_alunos_massa_intervalo.json."""
    data_inicial = data_iso(args.data_inicial or frequencia_data_inicial())
    data_final = data_iso(args.data_final or frequencia_data_final())
    url = url_intervalo(config, data_inicial, data_final)
    print("Consulta em massa — frequencia por intervalo")
    print(f"URL: {url}")
    vinculos = consultar_com_tentativas(url, token, config, args.timeout, tentativas)
    if not isinstance(vinculos, list):
        raise ValueError("Resposta do intervalo inesperada (esperava lista de vinculos).")
    salvar_json(vinculos, JSON_RESPOSTA_ALUNOS_MASSA)
    imprimir_resumo_intervalo(vinculos)
    print(f"Salvo em: {JSON_RESPOSTA_ALUNOS_MASSA}")


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    """Argumentos da linha de comando."""
    parser = argparse.ArgumentParser(
        description="Consulta em massa (cadastro + frequencia por intervalo)."
    )
    parser.add_argument("--data-inicial", default=None, help="DD-MM-AAAA (padrao: configuracao)")
    parser.add_argument("--data-final", default=None, help="DD-MM-AAAA (padrao: configuracao)")
    parser.add_argument("--timeout", type=int, default=TIMEOUT_SEGUNDOS)
    parser.add_argument("--tentativas", type=int, default=TENTATIVAS)
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    """Ponto de entrada."""
    args = parse_args(argv)
    garantir_diretorios()
    tentativas = max(1, args.tentativas)

    try:
        config = carregar_config_api()
        if not verificar_ssl(config):
            print("Aviso: verificacao SSL desativada (api_verify_ssl=false).")
        token = obter_access_token(config)
    except (ValueError, RuntimeError) as error:
        print(f"Erro: {error}", file=sys.stderr)
        return 1

    falhas = 0
    for nome, consulta, arquivo in (
        ("cadastro", lambda: consultar_cadastro(token, config, tentativas),
         JSON_RESPOSTA_ALUNOS_MASSA_CADASTRO),
        ("intervalo", lambda: consultar_intervalo(token, config, args, tentativas),
         JSON_RESPOSTA_ALUNOS_MASSA),
    ):
        try:
            consulta()
        except (ValueError, RuntimeError, json.JSONDecodeError) as error:
            falhas += 1
            print(f"Erro no {nome}: {error}", file=sys.stderr)
            print(f"Arquivo anterior mantido: {arquivo}", file=sys.stderr)

    return 1 if falhas else 0


if __name__ == "__main__":
    raise SystemExit(main())
