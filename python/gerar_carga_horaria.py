#!/usr/bin/env python3
"""Monta tabela disciplina_carga_horaria a partir da API de alunos.

Consulta os 4 ultimos semestres (incluido o atual), pega logins em
matriculados de cada periodo e busca frequencia (intervalo) por aluno.
Grava o campo carga_horaria de cada disciplina (null permitido).

Reusa cache JSON quando existir:
  - matriculados: resposta_matriculas.json / resposta_matriculas_AAAA_S.json
  - alunos (semestre atual): resposta_alunos.json
  - alunos (outros): resposta_alunos_AAAA_S.json, se houver

Manual — nao entra em executar_coleta.py.

Uso:
    python3 gerar_carga_horaria.py
    python3 gerar_carga_horaria.py 2026/2
    python3 gerar_carga_horaria.py --semestres 4 --concorrencia 50
    python3 gerar_carga_horaria.py --limite 20
"""

from __future__ import annotations

import argparse
import json
import re
import sys
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone
from pathlib import Path
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

from api_auth import (
    USER_AGENT,
    carregar_config_api,
    obter_access_token,
    ssl_context,
    url_alunos,
    url_matriculados,
    verificar_ssl,
)
from consulta_alunos import eh_erro_http_temporario, eh_erro_temporario
from db import conectar, fechar
from paths import DIR_JSON, JSON_RESPOSTA_ALUNOS, JSON_RESPOSTA_MATRICULAS, garantir_diretorios
from status_aluno import status_vai_segunda_consulta

CONCORRENCIA = 50
TIMEOUT_SEGUNDOS = 120
TENTATIVAS = 3
SEMESTRES_PADRAO = 4
FLUSH_A_CADA = 50
FREQUENCIA_GLOBAL = "FREQUÊNCIA GLOBAL"


def validar_periodo(periodo: str) -> str:
    """Valida e normaliza AAAA/S."""
    texto = periodo.strip()
    partes = texto.split("/")
    if len(partes) != 2:
        raise ValueError(f"Periodo invalido: {periodo!r} (use AAAA/S).")
    ano = int(partes[0])
    sem = int(partes[1])
    if sem not in (1, 2):
        raise ValueError(f"Semestre invalido em {periodo!r} (use 1 ou 2).")
    return f"{ano}/{sem}"


def semestre_anterior(periodo: str) -> str:
    """Semestre imediatamente anterior."""
    ano_s, sem_s = validar_periodo(periodo).split("/")
    ano = int(ano_s)
    sem = int(sem_s)
    if sem == 1:
        return f"{ano - 1}/2"
    return f"{ano}/1"


def ultimos_semestres(periodo_atual: str, quantidade: int = SEMESTRES_PADRAO) -> list[str]:
    """Ultimos N semestres incluindo o atual, do mais recente ao mais antigo."""
    if quantidade < 1:
        raise ValueError("quantidade deve ser >= 1.")
    atual = validar_periodo(periodo_atual)
    saida = [atual]
    cursor = atual
    for _ in range(quantidade - 1):
        cursor = semestre_anterior(cursor)
        saida.append(cursor)
    return saida


def periodo_para_arquivo(periodo: str) -> str:
    """Converte 2026/1 em 2026_1."""
    return re.sub(r"[^\w.-]+", "_", periodo.strip())


def datas_padrao_semestre(periodo: str) -> tuple[str, str]:
    """Datas DD-MM-AAAA aproximadas do semestre (calendario IFRS)."""
    partes = validar_periodo(periodo).split("/")
    ano = partes[0]
    sem = int(partes[1])
    if sem == 1:
        return f"15-02-{ano}", f"31-07-{ano}"
    return f"01-08-{ano}", f"31-12-{ano}"


def limpar_url_params(url: str) -> str:
    """Remove '&' / '?' sobrando apos editar query string."""
    url = re.sub(r"\?&+", "?", url)
    url = re.sub(r"&&+", "&", url)
    url = re.sub(r"[?&]$", "", url)
    return url


def consultar_webservice(
    url: str,
    token: str,
    config: dict[str, str],
    timeout: int = TIMEOUT_SEGUNDOS,
) -> tuple[int, str]:
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


def carregar_registros_matriculas(dados: Any) -> list[dict[str, Any]]:
    """Normaliza JSON de matriculados em lista."""
    if isinstance(dados, dict) and isinstance(dados.get("data"), list):
        dados = dados["data"]
    elif isinstance(dados, dict):
        dados = list(dados.values())
    if not isinstance(dados, list):
        raise ValueError("Formato inesperado de matriculados.")
    return [r for r in dados if isinstance(r, dict)]


def logins_do_periodo(registros: list[dict[str, Any]]) -> list[str]:
    """Logins elegiveis a 2a consulta (ATIVO/FORMANDO/trancado)."""
    vistos: set[str] = set()
    saida: list[str] = []
    for registro in registros:
        status = str(registro.get("status") or registro.get("Status") or "").strip()
        if not status_vai_segunda_consulta(status):
            continue
        login = str(registro.get("login") or registro.get("Login") or "").strip()
        if login == "" or login in vistos:
            continue
        vistos.add(login)
        saida.append(login)
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
    periodo_config = (config.get("api_periodo_letivo") or "").strip()

    if (
        not forcar
        and periodo == periodo_config
        and JSON_RESPOSTA_MATRICULAS.is_file()
    ):
        print(f"  cache coleta: {JSON_RESPOSTA_MATRICULAS.name}")
        dados = json.loads(JSON_RESPOSTA_MATRICULAS.read_text(encoding="utf-8"))
        return carregar_registros_matriculas(dados)

    if caminho.is_file() and not forcar:
        print(f"  cache: {caminho.name}")
        dados = json.loads(caminho.read_text(encoding="utf-8"))
        return carregar_registros_matriculas(dados)

    url = url_matriculados(config, periodo=periodo)
    print(f"  API matriculados: {url}")
    status, body = consultar_webservice(url, token, config)
    if status != 200:
        raise RuntimeError(f"HTTP {status} ao consultar matriculados {periodo}")
    dados = json.loads(body)
    caminho.write_text(json.dumps(dados, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"  salvo: {caminho.name}")
    return carregar_registros_matriculas(dados)


def caminho_cache_alunos(periodo: str, periodo_config: str) -> Path | None:
    """JSON de alunos da coleta, se existir para o periodo."""
    if periodo == periodo_config and JSON_RESPOSTA_ALUNOS.is_file():
        return JSON_RESPOSTA_ALUNOS
    caminho = DIR_JSON / f"resposta_alunos_{periodo_para_arquivo(periodo)}.json"
    if caminho.is_file():
        return caminho
    return None


def carregar_cargas_do_cache_alunos(
    caminho: Path,
) -> list[tuple[str, str, str, int | None]]:
    """Extrai cargas de resposta_alunos.json (lista envelopada ou mapa cru)."""
    dados = json.loads(caminho.read_text(encoding="utf-8"))
    saida: list[tuple[str, str, str, int | None]] = []
    if isinstance(dados, list):
        for item in dados:
            if not isinstance(item, dict):
                continue
            if item.get("status") not in (200, "200"):
                continue
            payload = item.get("dados")
            saida.extend(extrair_cargas_de_resposta(payload))
        return saida
    if isinstance(dados, dict):
        return extrair_cargas_de_resposta(dados)
    return saida


def montar_url_alunos_intervalo(
    base: str,
    login: str,
    data_inicial: str,
    data_final: str,
) -> str:
    """URL de alunos em modo intervalo com datas do semestre."""
    url = base
    if re.search(r"[?&]tipo_frequencia=", url):
        url = re.sub(
            r"([?&])tipo_frequencia=[^&]*",
            r"\1tipo_frequencia=intervalo",
            url,
        )
    else:
        sep = "&" if "?" in url else "?"
        url = f"{url}{sep}tipo_frequencia=intervalo"

    for param in (
        "frequencia_data_inicial",
        "frequencia_data_final",
        "frequencia_periodo",
    ):
        url = re.sub(rf"([?&]){param}=[^&]*", r"\1", url)
    url = limpar_url_params(url)
    sep = "&" if "?" in url else "?"
    return (
        f"{url}{sep}frequencia_data_inicial={data_inicial}"
        f"&frequencia_data_final={data_final}"
    ).format(login=login)


def parse_int_ou_none(valor: Any) -> int | None:
    """Converte carga_horaria da API; None permanece None."""
    if valor is None:
        return None
    if isinstance(valor, bool):
        return None
    if isinstance(valor, int):
        return valor
    if isinstance(valor, float):
        return int(valor)
    texto = str(valor).strip().replace(",", ".")
    if texto == "":
        return None
    try:
        return int(float(texto))
    except ValueError:
        return None


def extrair_cargas_de_resposta(
    dados: Any,
) -> list[tuple[str, str, str, int | None]]:
    """Extrai (codigo, nome, nome_curso, carga_horaria) das disciplinas."""
    saida: list[tuple[str, str, str, int | None]] = []
    if not isinstance(dados, dict):
        return saida

    for perfil in dados.values():
        if not isinstance(perfil, dict):
            continue
        for curso in perfil.get("cursos") or []:
            if not isinstance(curso, dict):
                continue
            nome_curso = str(curso.get("nome_curso") or "").strip() or "Curso nao informado"
            freqs = curso.get("frequencias")
            if not isinstance(freqs, dict):
                continue
            discs = freqs.get("disciplinas")
            if isinstance(discs, dict):
                itens = discs.items()
            elif isinstance(discs, list):
                itens = ((None, d) for d in discs)
            else:
                continue
            for chave, disc in itens:
                if not isinstance(disc, dict):
                    continue
                nome_chave = str(chave or "").strip()
                if nome_chave.upper() == FREQUENCIA_GLOBAL.upper():
                    continue
                codigo = str(
                    disc.get("cod_disciplina") or disc.get("codigo") or ""
                ).strip()
                nome = str(
                    disc.get("nome") or disc.get("disciplina") or ""
                ).strip()
                if not codigo and nome_chave:
                    # modo mensal: "NOME (COD)"
                    match = re.match(r"^(.*?)\s*\(([^)]+)\)\s*$", nome_chave)
                    if match:
                        nome = nome or match.group(1).strip()
                        codigo = match.group(2).strip()
                    else:
                        codigo = nome_chave
                if not codigo:
                    continue
                carga = parse_int_ou_none(disc.get("carga_horaria"))
                if carga is None:
                    freq = disc.get("frequencia")
                    if isinstance(freq, dict):
                        carga = parse_int_ou_none(freq.get("carga_horaria"))
                saida.append((codigo, nome or codigo, nome_curso, carga))
    return saida


def consultar_aluno_cargas(
    login: str,
    periodo: str,
    base_url: str,
    token: str,
    config: dict[str, str],
    timeout: int,
) -> tuple[str, str, list[tuple[str, str, str, int | None]], str | None]:
    """Consulta um aluno/periodo; retorna (login, periodo, cargas, erro)."""
    di, df = datas_padrao_semestre(periodo)
    url = montar_url_alunos_intervalo(base_url, login, di, df)
    ultimo_erro: str | None = None

    for tentativa in range(1, TENTATIVAS + 1):
        try:
            status, body = consultar_webservice(url, token, config, timeout=timeout)
            if status != 200:
                if eh_erro_http_temporario(status) and tentativa < TENTATIVAS:
                    time.sleep(1.5 * tentativa)
                    continue
                return login, periodo, [], f"HTTP {status}"
            dados = json.loads(body)
            return login, periodo, extrair_cargas_de_resposta(dados), None
        except HTTPError as error:
            ultimo_erro = f"HTTP {error.code}"
            if eh_erro_http_temporario(error.code) and tentativa < TENTATIVAS:
                time.sleep(1.5 * tentativa)
                continue
            return login, periodo, [], ultimo_erro
        except (URLError, TimeoutError, json.JSONDecodeError, OSError) as error:
            ultimo_erro = str(error)
            if eh_erro_temporario(ultimo_erro) and tentativa < TENTATIVAS:
                time.sleep(1.5 * tentativa)
                continue
            return login, periodo, [], ultimo_erro

    return login, periodo, [], ultimo_erro or "falha"


def mesclar_carga(
    acumulado: dict[tuple[str, str], dict[str, Any]],
    codigo: str,
    nome: str,
    nome_curso: str,
    carga: int | None,
    periodo: str,
) -> None:
    """Acumula por (codigo, curso).

    Processamento esperado: semestre mais atual -> mais antigo.
    origem_periodo fica na primeira ocorrencia (semestre mais recente).
    Carga null pode ser preenchida por semestre mais antigo; origem nao muda.
    """
    chave = (codigo, nome_curso)
    atual = acumulado.get(chave)
    if atual is None:
        acumulado[chave] = {
            "codigo_disciplina": codigo,
            "disciplina": nome,
            "nome_curso": nome_curso,
            "carga_horaria": carga,
            "origem_periodo": periodo,
        }
        return

    if nome and (not atual.get("disciplina") or atual["disciplina"] == atual["codigo_disciplina"]):
        atual["disciplina"] = nome

    # Nao altera origem_periodo (ja fixada no semestre mais atual em que apareceu).
    if atual.get("carga_horaria") is None and carga is not None:
        atual["carga_horaria"] = carga


def gravar_cargas(acumulado: dict[tuple[str, str], dict[str, Any]]) -> tuple[int, int]:
    """Upsert na tabela disciplina_carga_horaria. Retorna (inseridos/atualizados, com_null)."""
    agora = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
    conn = conectar()
    cursor = conn.cursor()
    com_null = 0
    total = 0
    for item in acumulado.values():
        carga = item.get("carga_horaria")
        if carga is None:
            com_null += 1
        cursor.execute(
            """
            INSERT INTO disciplina_carga_horaria (
                codigo_disciplina, disciplina, nome_curso, carga_horaria,
                origem_periodo, atualizado_em
            ) VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT(codigo_disciplina, nome_curso) DO UPDATE SET
                disciplina = excluded.disciplina,
                carga_horaria = CASE
                    WHEN excluded.carga_horaria IS NOT NULL
                        THEN excluded.carga_horaria
                    ELSE disciplina_carga_horaria.carga_horaria
                END,
                origem_periodo = CASE
                    WHEN disciplina_carga_horaria.origem_periodo IS NULL
                         OR disciplina_carga_horaria.origem_periodo = ''
                        THEN excluded.origem_periodo
                    ELSE disciplina_carga_horaria.origem_periodo
                END,
                atualizado_em = excluded.atualizado_em
            """,
            (
                item["codigo_disciplina"],
                item.get("disciplina") or item["codigo_disciplina"],
                item.get("nome_curso") or "",
                carga,
                item.get("origem_periodo"),
                agora,
            ),
        )
        total += 1
    conn.commit()
    return total, com_null


def parse_args(argv: list[str]) -> argparse.Namespace:
    """Argumentos CLI."""
    parser = argparse.ArgumentParser(
        description="Gera disciplina_carga_horaria a partir da API de alunos."
    )
    parser.add_argument(
        "periodo",
        nargs="?",
        default=None,
        help="Periodo atual AAAA/S (default: api_periodo_letivo).",
    )
    parser.add_argument(
        "--semestres",
        type=int,
        default=SEMESTRES_PADRAO,
        help=f"Quantidade de semestres incluindo o atual (default {SEMESTRES_PADRAO}).",
    )
    parser.add_argument("--concorrencia", type=int, default=CONCORRENCIA)
    parser.add_argument("--timeout", type=int, default=TIMEOUT_SEGUNDOS)
    parser.add_argument("--limite", type=int, default=None, help="Limite de alunos por semestre.")
    parser.add_argument(
        "--forcar",
        action="store_true",
        help="Ignora cache de matriculados e consulta a API de novo.",
    )
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    """Ponto de entrada."""
    args = parse_args(list(argv if argv is not None else sys.argv[1:]))

    try:
        config = carregar_config_api()
        periodo_atual = (args.periodo or config.get("api_periodo_letivo") or "").strip()
        if not periodo_atual:
            print(
                "Periodo atual ausente. Configure em /configuracoes/api "
                "ou passe como argumento (ex.: 2026/2).",
                file=sys.stderr,
            )
            return 1
        semestres = ultimos_semestres(periodo_atual, args.semestres)
        base_alunos = url_alunos(config)
    except ValueError as error:
        print(f"Erro: {error}", file=sys.stderr)
        return 1

    print("Carga horaria das disciplinas")
    print(f"Periodo atual: {periodo_atual}")
    print(f"Semestres: {', '.join(semestres)}")
    if not verificar_ssl(config):
        print("Aviso: verificacao SSL desativada (api_verify_ssl=false).")
    print()

    try:
        token = obter_access_token(config)
        print("Access token OAuth obtido.")
    except (ValueError, RuntimeError) as error:
        print(f"Erro de autenticacao: {error}", file=sys.stderr)
        fechar()
        return 1

    # Consulta semestre a semestre (mais atual -> mais antigo) para fixar origem.
    # acumulado fica em memoria; gravar_cargas persiste a cada FLUSH_A_CADA alunos.
    acumulado: dict[tuple[str, str], dict[str, Any]] = {}
    erros = 0
    total_tarefas = 0
    inicio = time.time()
    periodo_config = (config.get("api_periodo_letivo") or "").strip()

    for periodo in semestres:
        print(f"\nMatriculados {periodo}:")
        try:
            regs = obter_matriculas_periodo(
                periodo, config, token, forcar=args.forcar
            )
        except (RuntimeError, HTTPError, URLError, TimeoutError, json.JSONDecodeError) as error:
            print(f"  erro: {error}", file=sys.stderr)
            fechar()
            return 1
        logins = logins_do_periodo(regs)
        if args.limite is not None:
            logins = logins[: max(0, args.limite)]
        print(f"  logins elegiveis: {len(logins)}")

        cache_alunos = None if args.forcar else caminho_cache_alunos(periodo, periodo_config)
        if cache_alunos is not None:
            print(f"  cache alunos: {cache_alunos.name} (sem consulta API)")
            try:
                cargas_cache = carregar_cargas_do_cache_alunos(cache_alunos)
            except (OSError, json.JSONDecodeError, ValueError) as error:
                print(f"  erro ao ler cache: {error}", file=sys.stderr)
                fechar()
                return 1
            for codigo, nome, nome_curso, carga in cargas_cache:
                mesclar_carga(acumulado, codigo, nome, nome_curso, carga, periodo)
            gravar_cargas(acumulado)
            elapsed = time.time() - inicio
            print(
                f"  progresso {periodo} cache "
                f"(total {elapsed:.0f}s) — disciplinas unicas: {len(acumulado)} "
                f"(gravado)"
            )
            continue

        if not logins:
            continue

        total_tarefas += len(logins)
        concluidas_periodo = 0
        print(f"  consultando alunos (intervalo) — {periodo}...")
        with ThreadPoolExecutor(max_workers=max(1, args.concorrencia)) as pool:
            futuros = [
                pool.submit(
                    consultar_aluno_cargas,
                    login,
                    periodo,
                    base_alunos,
                    token,
                    config,
                    args.timeout,
                )
                for login in logins
            ]
            for futuro in as_completed(futuros):
                login, _periodo, cargas, erro = futuro.result()
                concluidas_periodo += 1
                if erro:
                    erros += 1
                    if erros <= 10:
                        print(f"  erro {login} {periodo}: {erro}")
                else:
                    for codigo, nome, nome_curso, carga in cargas:
                        mesclar_carga(
                            acumulado, codigo, nome, nome_curso, carga, periodo
                        )
                deve_flush = (
                    concluidas_periodo % FLUSH_A_CADA == 0
                    or concluidas_periodo == len(logins)
                )
                if deve_flush:
                    gravar_cargas(acumulado)
                    elapsed = time.time() - inicio
                    print(
                        f"  progresso {periodo} {concluidas_periodo}/{len(logins)} "
                        f"(total {elapsed:.0f}s) — disciplinas unicas: {len(acumulado)} "
                        f"(gravado)"
                    )

    if total_tarefas == 0:
        print("\nNada a consultar.")
        fechar()
        return 0

    total, com_null = gravar_cargas(acumulado)
    print()
    print(f"Gravadas/atualizadas: {total} (com carga null: {com_null})")
    print(f"Consultas com erro: {erros}/{total_tarefas}")
    fechar()
    return 0 if erros < total_tarefas else 1


if __name__ == "__main__":
    raise SystemExit(main())
