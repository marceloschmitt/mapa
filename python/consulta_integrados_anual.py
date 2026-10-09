#!/usr/bin/env python3
"""Frequencia dos meses ja encerrados do ano letivo dos integrados (manual).

Os cursos integrados ao ensino medio (curso_nivel = N) sao anuais, mas a coleta
horaria consulta so o intervalo do semestre atual. Este script busca o restante
do ano: do inicio do ano letivo dos integrados (integrados_data_inicio, tela
Configuracao da API) ate o dia anterior a frequencia_data_inicial.

A consulta do ano inteiro passa do limite de ~60 s do Cloudflare (HTTP 504).
Por isso o periodo e dividido em blocos de ate 2 meses de calendario, com
status=ATIVO; um bloco que falhar e tentado de novo mes a mes. Da resposta
ficam so os vinculos com curso_nivel = N.

Saida: data/json/integrados_anual_AAAA.json (AAAA = ano do inicio). Um periodo
que falhar mantem a versao gravada na execucao anterior, se houver.

Nao faz parte da coleta horaria: os meses encerrados quase nao mudam. Rodar de
novo so para pegar correcoes de chamadas antigas (tela Configuracao da API ->
Buscar meses encerrados, ou linha de comando).

Uso:
    python3 consulta_integrados_anual.py
"""

from __future__ import annotations

import json
import sys
from datetime import date, datetime, timedelta
from pathlib import Path
from typing import Any

from api_auth import carregar_config_api, obter_access_token, verificar_ssl
from config_consultas import parsear_data
from consulta_alunos_massa import (
    consultar_com_tentativas,
    salvar_json,
    url_intervalo,
)
from integrados_anual import caminho_arquivo, eh_integrado
from paths import garantir_diretorios

CHAVE_INICIO = "integrados_data_inicio"
MESES_POR_BLOCO = 2
TIMEOUT_SEGUNDOS = 120
TENTATIVAS = 2
PAGINA_CONFIG = "/index.php/configuracoes/api"

Periodo = tuple[date, date]


def fim_do_mes(dia: date, meses_adiante: int = 0) -> date:
    """Ultimo dia do mes de dia, deslocado meses_adiante meses."""
    indice = dia.month - 1 + meses_adiante + 1
    primeiro_seguinte = date(dia.year + indice // 12, indice % 12 + 1, 1)
    return primeiro_seguinte - timedelta(days=1)


def dividir_periodo(inicio: date, fim: date, meses: int = MESES_POR_BLOCO) -> list[Periodo]:
    """Blocos de ate `meses` meses de calendario cobrindo inicio..fim sem buracos."""
    blocos: list[Periodo] = []
    atual = inicio
    while atual <= fim:
        ultimo = min(fim_do_mes(atual, meses - 1), fim)
        blocos.append((atual, ultimo))
        atual = ultimo + timedelta(days=1)
    return blocos


def periodo_do_ano(config: dict[str, str]) -> Periodo | None:
    """Inicio do ano letivo dos integrados ate a vespera do intervalo da coleta.

    None quando nao ha meses encerrados (o intervalo da coleta ja comeca antes
    ou no inicio do ano letivo).
    """
    texto_inicio = (config.get(CHAVE_INICIO) or "").strip()
    if texto_inicio == "":
        raise ValueError(
            f"Inicio do ano letivo dos integrados nao configurado. Preencha em {PAGINA_CONFIG}"
        )
    texto_semestre = (config.get("frequencia_data_inicial") or "").strip()
    if texto_semestre == "":
        raise ValueError(f"Data inicial da frequencia ausente. Configure em {PAGINA_CONFIG}")

    inicio = parsear_data(texto_inicio)
    fim = parsear_data(texto_semestre) - timedelta(days=1)
    if fim < inicio:
        return None
    return inicio, fim


def url_com_ativos(config: dict[str, str], inicio: date, fim: date) -> str:
    """URL do intervalo em massa para o periodo, so com alunos ATIVO."""
    url = url_intervalo(config, inicio.isoformat(), fim.isoformat())
    return url + ("&" if "?" in url else "?") + "status=ATIVO"


def somente_integrados(vinculos: Any) -> list[dict[str, Any]]:
    """Vinculos de cursos integrados (curso_nivel = N)."""
    if not isinstance(vinculos, list):
        raise ValueError("Resposta do intervalo inesperada (esperava lista de vinculos).")
    return [v for v in vinculos if isinstance(v, dict) and eh_integrado(v)]


def chave_periodo(periodo: Periodo) -> str:
    return f"{periodo[0].isoformat()}|{periodo[1].isoformat()}"


def carregar_anterior(caminho: Path) -> dict[str, dict[str, Any]]:
    """Blocos gravados na execucao anterior, por 'inicio|fim'."""
    if not caminho.is_file():
        return {}
    try:
        dados = json.loads(caminho.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {}
    blocos = dados.get("blocos") if isinstance(dados, dict) else None
    if not isinstance(blocos, list):
        return {}
    return {
        f"{b.get('data_inicial')}|{b.get('data_final')}": b
        for b in blocos
        if isinstance(b, dict) and isinstance(b.get("vinculos"), list)
    }


def anteriores_cobrindo(
    periodo: Periodo,
    anteriores: dict[str, dict[str, Any]],
) -> list[dict[str, Any]] | None:
    """Blocos antigos que cobrem exatamente o periodo (inteiro ou mes a mes)."""
    for divisao in ([periodo], dividir_periodo(periodo[0], periodo[1], meses=1)):
        blocos = [anteriores.get(chave_periodo(p)) for p in divisao]
        if all(b is not None for b in blocos):
            return blocos  # type: ignore[return-value]
    return None


def consultar_periodo(
    periodo: Periodo,
    token: str,
    config: dict[str, str],
) -> dict[str, Any]:
    """Consulta um periodo e devolve o bloco com os vinculos dos integrados."""
    inicio, fim = periodo
    url = url_com_ativos(config, inicio, fim)
    print(f"\n{inicio:%d/%m/%Y} a {fim:%d/%m/%Y}")
    print(f"URL: {url}")
    vinculos = somente_integrados(
        consultar_com_tentativas(url, token, config, TIMEOUT_SEGUNDOS, TENTATIVAS)
    )
    print(f"Vinculos de integrados: {len(vinculos)}")
    return {
        "data_inicial": inicio.isoformat(),
        "data_final": fim.isoformat(),
        "consultado_em": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        "vinculos": vinculos,
    }


def buscar_bloco(
    periodo: Periodo,
    token: str,
    config: dict[str, str],
    anteriores: dict[str, dict[str, Any]],
) -> tuple[list[dict[str, Any]], list[str], bool]:
    """Blocos que cobrem o periodo, erros encontrados e se o periodo ficou coberto.

    Falhando o periodo inteiro, tenta mes a mes. Se ainda faltar algum mes,
    usa a versao anterior do periodo, se existir.
    """
    try:
        return [consultar_periodo(periodo, token, config)], [], True
    except (ValueError, RuntimeError, json.JSONDecodeError) as erro:
        print(f"Erro: {erro}", file=sys.stderr)

    rotulo = f"{periodo[0]:%d/%m/%Y} a {periodo[1]:%d/%m/%Y}"
    blocos: list[dict[str, Any]] = []
    erros: list[str] = []
    coberto = False
    meses = dividir_periodo(periodo[0], periodo[1], meses=1)
    if len(meses) > 1:
        print("Tentando mes a mes.")
        coberto = True
        for mes in meses:
            novos, erros_mes, mes_coberto = buscar_bloco(mes, token, config, anteriores)
            blocos.extend(novos)
            erros.extend(erros_mes)
            coberto = coberto and mes_coberto
        if coberto:
            return blocos, erros, True

    antigos = anteriores_cobrindo(periodo, anteriores)
    if antigos is not None:
        print(f"Mantida a versao anterior de {rotulo}.", file=sys.stderr)
        return antigos, [f"{rotulo}: falhou (mantida a versao anterior)"], True
    if len(meses) == 1:
        return [], [f"{rotulo}: falhou"], False
    return blocos, erros, False


def executar(config: dict[str, str], token: str) -> int:
    """Busca todos os blocos e grava o arquivo do ano. Retorna o codigo de saida."""
    periodo = periodo_do_ano(config)
    if periodo is None:
        print(
            "Nada a buscar: o intervalo da coleta ja comeca no inicio do ano letivo "
            "dos integrados (ou antes)."
        )
        return 0

    inicio, fim = periodo
    caminho = caminho_arquivo(inicio)
    anteriores = carregar_anterior(caminho)
    print(f"Ano letivo dos integrados: {inicio:%d/%m/%Y} a {fim:%d/%m/%Y} (meses encerrados)")

    blocos: list[dict[str, Any]] = []
    erros: list[str] = []
    for bloco in dividir_periodo(inicio, fim):
        novos, erros_bloco, _ = buscar_bloco(bloco, token, config, anteriores)
        blocos.extend(novos)
        erros.extend(erros_bloco)

    blocos.sort(key=lambda b: str(b.get("data_inicial")))
    salvar_json(
        {
            "data_inicial": inicio.isoformat(),
            "data_final": fim.isoformat(),
            "executado_em": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
            "erros": erros,
            "blocos": blocos,
        },
        caminho,
    )

    print(f"\nSalvo em: {caminho}")
    for bloco in blocos:
        print(
            f"  {bloco['data_inicial']} a {bloco['data_final']}: "
            f"{len(bloco['vinculos'])} vinculos (consultado em {bloco['consultado_em']})"
        )
    if erros:
        print("Periodos com erro:", file=sys.stderr)
        for erro in erros:
            print(f"  {erro}", file=sys.stderr)
        return 1
    return 0


def main() -> int:
    """Ponto de entrada."""
    garantir_diretorios()
    try:
        config = carregar_config_api()
        if not verificar_ssl(config):
            print("Aviso: verificacao SSL desativada (api_verify_ssl=false).")
        periodo_do_ano(config)
        token = obter_access_token(config)
        return executar(config, token)
    except (ValueError, RuntimeError) as erro:
        print(f"Erro: {erro}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
