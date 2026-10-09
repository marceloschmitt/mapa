"""Frequencia anual dos integrados (curso_nivel = N).

A coleta consulta so o intervalo do semestre (frequencia_data_inicial a
frequencia_data_final). Os meses ja encerrados do ano letivo dos integrados
ficam em data/json/integrados_anual_AAAA.json, gravado por
consulta_integrados_anual.py. Aqui eles sao somados ao intervalo atual.

O arquivo so e usado se cobrir, sem buracos, do inicio do ano letivo
configurado (integrados_data_inicio) ate a vespera da frequencia_data_inicial.

Percentuais calculados como a API: presencas / horarios * 100, com
arredondamento meio para cima (2 casas na disciplina, inteiro no total do
curso). Faltas justificadas saem das faltas no percentual "com justificadas".
"""

from __future__ import annotations

import json
from datetime import date, timedelta
from decimal import ROUND_HALF_UP, Decimal
from pathlib import Path
from typing import Any

from config_consultas import parsear_data
from paths import DIR_JSON

NIVEL_INTEGRADO = "N"

ChaveVinculo = tuple[str, str]


def caminho_arquivo(inicio: date) -> Path:
    """Arquivo do ano letivo que comeca em inicio."""
    return DIR_JSON / f"integrados_anual_{inicio.year}.json"


def eh_integrado(vinculo: dict[str, Any]) -> bool:
    return str(vinculo.get("curso_nivel") or "").strip().upper() == NIVEL_INTEGRADO


def chave_vinculo(vinculo: dict[str, Any]) -> ChaveVinculo:
    return (str(vinculo.get("login") or "").strip(), str(vinculo.get("matricula") or "").strip())


def percentual(presencas: int, horarios: int, casas: int) -> float | int | None:
    """presencas / horarios * 100, meio para cima; None sem horarios."""
    if horarios <= 0:
        return None
    valor = (Decimal(presencas) * 100 / Decimal(horarios)).quantize(
        Decimal(1).scaleb(-casas), rounding=ROUND_HALF_UP
    )
    return int(valor) if valor == valor.to_integral_value() else float(valor)


def validar_cobertura(dados: Any, inicio: date, fim: date) -> list[dict[str, Any]]:
    """Blocos do arquivo, se cobrirem inicio..fim sem buracos nem sobreposicao."""
    if not isinstance(dados, dict) or not isinstance(dados.get("blocos"), list):
        raise ValueError("formato inesperado")

    blocos = sorted(
        (b for b in dados["blocos"] if isinstance(b, dict) and isinstance(b.get("vinculos"), list)),
        key=lambda b: str(b.get("data_inicial")),
    )
    esperado = inicio
    for bloco in blocos:
        de = date.fromisoformat(str(bloco.get("data_inicial")))
        ate = date.fromisoformat(str(bloco.get("data_final")))
        if de != esperado:
            raise ValueError(f"periodo sem dados a partir de {esperado:%d/%m/%Y}")
        esperado = ate + timedelta(days=1)
    if esperado != fim + timedelta(days=1):
        raise ValueError(f"periodo sem dados a partir de {esperado:%d/%m/%Y}")
    return blocos


def carregar(config: dict[str, str]) -> tuple[date | None, dict[ChaveVinculo, list[dict[str, Any]]], str | None]:
    """(inicio do ano, vinculos anteriores por (login, matricula), aviso).

    Sem inicio configurado, ou quando o intervalo da coleta ja comeca no inicio
    do ano letivo, devolve (None, {}, None): nao ha o que somar. Arquivo ausente
    ou incompleto devolve aviso e nenhum vinculo.
    """
    texto_inicio = (config.get("integrados_data_inicio") or "").strip()
    texto_semestre = (config.get("frequencia_data_inicial") or "").strip()
    if texto_inicio == "" or texto_semestre == "":
        return None, {}, None

    inicio = parsear_data(texto_inicio)
    fim = parsear_data(texto_semestre) - timedelta(days=1)
    if fim < inicio:
        return None, {}, None

    caminho = caminho_arquivo(inicio)
    if not caminho.is_file():
        return inicio, {}, (
            f"{caminho.name} nao encontrado: integrados ficam so com o semestre. "
            "Rode a busca dos meses encerrados (Configuracao da API)."
        )
    try:
        dados = json.loads(caminho.read_text(encoding="utf-8"))
        if (
            str(dados.get("data_inicial")) != inicio.isoformat()
            or str(dados.get("data_final")) != fim.isoformat()
        ):
            raise ValueError(
                f"cobre {dados.get('data_inicial')} a {dados.get('data_final')}, "
                f"esperado {inicio.isoformat()} a {fim.isoformat()}"
            )
        blocos = validar_cobertura(dados, inicio, fim)
    except (OSError, ValueError, TypeError, AttributeError, json.JSONDecodeError) as erro:
        return inicio, {}, (
            f"{caminho.name} incompleto ({erro}): integrados ficam so com o semestre. "
            "Rode de novo a busca dos meses encerrados."
        )

    anteriores: dict[ChaveVinculo, list[dict[str, Any]]] = {}
    for bloco in blocos:
        for vinculo in bloco["vinculos"]:
            if isinstance(vinculo, dict) and eh_integrado(vinculo):
                anteriores.setdefault(chave_vinculo(vinculo), []).append(vinculo)
    return inicio, anteriores, None


def _int(valor: Any) -> int:
    try:
        return int(valor or 0)
    except (TypeError, ValueError):
        return 0


def _frequencia(disciplina: dict[str, Any]) -> dict[str, Any]:
    freq = disciplina.get("frequencia")
    return freq if isinstance(freq, dict) else {}


def _total(vinculo: dict[str, Any]) -> dict[str, Any]:
    total = vinculo.get("total")
    return total if isinstance(total, dict) else {}


def somar(
    atual: dict[str, Any],
    anteriores: list[dict[str, Any]],
    inicio: date,
) -> dict[str, Any]:
    """Frequencia anual do vinculo: intervalo atual + meses encerrados.

    Disciplinas casadas por id_matricula_componente (ou codigo, na falta dele).
    So entram as disciplinas do intervalo atual; o total do curso soma os
    totais de todos os periodos.
    """
    por_matricula: dict[str, list[dict[str, Any]]] = {}
    for vinculo in anteriores:
        for disciplina in vinculo.get("disciplinas") or []:
            if not isinstance(disciplina, dict):
                continue
            chave = str(disciplina.get("id_matricula_componente") or disciplina.get("cod_disciplina") or "")
            por_matricula.setdefault(chave, []).append(disciplina)

    disciplinas: dict[str, dict[str, Any]] = {}
    for disciplina in atual.get("disciplinas") or []:
        if not isinstance(disciplina, dict):
            continue
        codigo = str(disciplina.get("cod_disciplina") or "").strip()
        if codigo == "":
            continue
        chave = str(disciplina.get("id_matricula_componente") or codigo)
        partes = [disciplina] + por_matricula.get(chave, [])
        horarios = sum(_int(_frequencia(p).get("horarios")) for p in partes)
        ausencias = sum(_int(_frequencia(p).get("ausencias")) for p in partes)
        presencas = sum(_int(_frequencia(p).get("presencas")) for p in partes)
        disciplinas[codigo] = {
            "horarios": horarios,
            "ausencias": ausencias,
            "presencas": presencas,
            "percentual_frequencia": percentual(presencas, horarios, 2),
        }

    totais = [_total(v) for v in [atual] + anteriores]
    horarios = sum(_int(t.get("horarios_totais")) for t in totais)
    ausencias = sum(_int(t.get("ausencias_totais")) for t in totais)
    presencas = sum(_int(t.get("presencas_totais")) for t in totais)
    justificadas = sum(
        _int((t.get("frequencia_com_ausencias_justificadas") or {}).get("ausencias_justificadas_totais"))
        for t in totais
        if isinstance(t.get("frequencia_com_ausencias_justificadas") or {}, dict)
    )

    return {
        "desde": inicio.isoformat(),
        "geral": {
            "horarios_totais": horarios,
            "ausencias_totais": ausencias,
            "presencas_totais": presencas,
            "percentual_frequencia_total": percentual(presencas, horarios, 0),
            "ausencias_justificadas_totais": justificadas,
            "percentual_com_ausencias_justificadas": percentual(
                horarios - (ausencias - justificadas), horarios, 0
            ),
        },
        "disciplinas": disciplinas,
    }
