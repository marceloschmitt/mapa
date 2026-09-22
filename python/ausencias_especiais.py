#!/usr/bin/env python3
"""Utilitarios para ausencias_especiais.trancamento_cancelamento da API."""

from __future__ import annotations

import re
from typing import Any

# Valor gravado em passe_livre_disciplina.situacao (exibido no lugar do %).
SITUACAO_TRANCADO_CANCELADO = "TRANCADO/CANCELADO"

_RE_CODIGO = re.compile(r"\(([^)]+)\)")


def codigos_trancamento_cancelamento(ausencias_especiais: Any) -> set[str]:
    """Extrai codigos de disciplina de frequencias.ausencias_especiais.

    Formato tipico da API::

        {
          "trancamento_cancelamento": [
            ["(POA-ADM101) - 06/04/2026"],
            ["(POA-ADM108) - 06/04/2026"]
          ]
        }
    """
    if not isinstance(ausencias_especiais, dict):
        return set()
    bruto = ausencias_especiais.get("trancamento_cancelamento")
    if not isinstance(bruto, list):
        return set()

    codigos: set[str] = set()
    for item in bruto:
        textos: list[Any]
        if isinstance(item, list):
            textos = item
        else:
            textos = [item]
        for texto in textos:
            match = _RE_CODIGO.search(str(texto or ""))
            if match:
                codigo = match.group(1).strip()
                if codigo:
                    codigos.add(codigo)
    return codigos


def aplicar_situacao_trancamento(
    disciplinas: list[dict[str, Any]],
    codigos: set[str],
) -> list[dict[str, Any]]:
    """Marca disciplinas trancadas/canceladas: situacao no lugar do percentual.

    - Se o codigo ja existe na lista: situacao = TRANCADO/CANCELADO e
      percentual_frequencia = None.
    - Se so aparece em ausencias_especiais: inclui linha so com codigo/situacao.
    """
    if not codigos:
        for disc in disciplinas:
            disc.setdefault("situacao", None)
        return disciplinas

    vistos: set[str] = set()
    saida: list[dict[str, Any]] = []
    for disc in disciplinas:
        codigo = str(disc.get("codigo_disciplina") or "").strip()
        if codigo in codigos:
            saida.append({
                **disc,
                "percentual_frequencia": None,
                "situacao": SITUACAO_TRANCADO_CANCELADO,
            })
            vistos.add(codigo)
        else:
            saida.append({**disc, "situacao": disc.get("situacao")})
            if codigo:
                vistos.add(codigo)

    for codigo in sorted(codigos - vistos):
        saida.append({
            "codigo_disciplina": codigo,
            "disciplina": "",
            "horarios": 0,
            "ausencias": 0,
            "presencas": 0,
            "percentual_frequencia": None,
            "situacao": SITUACAO_TRANCADO_CANCELADO,
        })
    return saida


def _numero(valor: Any) -> int:
    """Converte valor da API em int (0 se vazio/invalido)."""
    if valor is None or valor == "":
        return 0
    try:
        return int(valor)
    except (TypeError, ValueError):
        try:
            return int(float(valor))
        except (TypeError, ValueError):
            return 0


def recalcular_frequencia_geral_sem_trancadas(
    disciplinas: list[dict[str, Any]],
) -> dict[str, Any] | None:
    """Percentual do curso excluindo disciplinas com situacao (trancadas).

    Soma horarios / ausencias / presencas so das disciplinas ativas e calcula
    percentual = 100 * presencas / horarios.
    """
    horarios = 0
    ausencias = 0
    presencas = 0
    for disc in disciplinas:
        if str(disc.get("situacao") or "").strip():
            continue
        h = _numero(disc.get("horarios"))
        a = _numero(disc.get("ausencias"))
        p = _numero(disc.get("presencas"))
        if h <= 0 and p <= 0 and a <= 0:
            continue
        horarios += h
        ausencias += a
        presencas += p

    if horarios <= 0:
        return None

    pct = round(100.0 * presencas / horarios, 1)
    return {
        "percentual_frequencia_total": pct,
        "horarios_totais": horarios,
        "ausencias_totais": ausencias,
        "presencas_totais": presencas,
    }
