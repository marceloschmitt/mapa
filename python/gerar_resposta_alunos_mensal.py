#!/usr/bin/env python3
"""Gera cache JSON de alunos em modo mensal (frequencia_periodo).

Uso:
    python3 gerar_resposta_alunos_mensal.py 2026/1
    python3 gerar_resposta_alunos_mensal.py 2026/1 --limite 20
"""

from __future__ import annotations

import argparse
import sys
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from typing import Any

from api_auth import carregar_config_api, obter_access_token, url_alunos, verificar_ssl
from db import fechar
from gerar_passe_livre import (
    CONCORRENCIA,
    TENTATIVAS,
    TIMEOUT_SEGUNDOS,
    carregar_logins_semestre_atual,
    consultar_um_aluno,
    salvar_cache_mensal,
    validar_periodo,
)
from paths import garantir_diretorios


def main() -> int:
    """Ponto de entrada."""
    parser = argparse.ArgumentParser(
        description="Consulta alunos em modo mensal e grava JSON."
    )
    parser.add_argument("periodo", help="Semestre AAAA/S (ex.: 2026/1).")
    parser.add_argument("--concorrencia", type=int, default=CONCORRENCIA)
    parser.add_argument("--timeout", type=int, default=TIMEOUT_SEGUNDOS)
    parser.add_argument("--tentativas", type=int, default=TENTATIVAS)
    parser.add_argument("--limite", type=int, default=0)
    args = parser.parse_args()

    garantir_diretorios()
    try:
        periodo = validar_periodo(args.periodo)
        config = carregar_config_api()
        logins, origem = carregar_logins_semestre_atual()
    except (FileNotFoundError, ValueError) as error:
        print(f"Erro: {error}", file=sys.stderr)
        fechar()
        return 1

    if args.limite > 0:
        logins = logins[: args.limite]

    print(f"Periodo (frequencia_periodo): {periodo}")
    print(f"Logins: {len(logins)} (origem: {origem})")
    if not verificar_ssl(config):
        print("Aviso: verificacao SSL desativada (api_verify_ssl=false).")

    token = obter_access_token(config)
    print("Access token OAuth obtido.\n")
    base = url_alunos(config)

    resultados: list[dict[str, Any]] = []
    erros = 0
    feitos = 0
    total = len(logins)
    inicio = time.perf_counter()

    with ThreadPoolExecutor(max_workers=max(1, args.concorrencia)) as pool:
        futures = {
            pool.submit(
                consultar_um_aluno,
                aluno,
                base_url=base,
                token=token,
                config=config,
                periodo=periodo,
                timeout=args.timeout,
                tentativas=args.tentativas,
            ): aluno
            for aluno in logins
        }
        for future in as_completed(futures):
            item = future.result()
            feitos += 1
            resultados.append(item)
            if item.get("status") != 200:
                erros += 1
                print(
                    f"  ERRO login {item.get('login')} -> "
                    f"{item.get('erro') or item.get('status')}",
                    flush=True,
                )
            if feitos % 50 == 0 or feitos == total:
                decorrido = time.perf_counter() - inicio
                ritmo = (feitos / decorrido) * 60.0 if decorrido > 0 else 0.0
                print(
                    f"  {feitos}/{total} (erros: {erros}, "
                    f"{ritmo:.0f}/min, {decorrido / 60:.1f} min)",
                    flush=True,
                )

    resultados.sort(key=lambda r: str(r.get("login") or ""))
    saida = salvar_cache_mensal(periodo, resultados)
    ok = sum(1 for r in resultados if r.get("status") == 200)
    print(f"\nConcluido: {ok} sucesso(s), {erros} falha(s).")
    print(f"Arquivo: {saida}")
    fechar()
    return 0 if erros == 0 else 1


if __name__ == "__main__":
    raise SystemExit(main())
