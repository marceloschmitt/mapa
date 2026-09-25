#!/usr/bin/env python3
"""Utilitarios para ausencias_especiais.trancamento_cancelamento da API."""

from __future__ import annotations

import re
from datetime import datetime
from typing import Any

# Valor gravado em passe_livre_disciplina.situacao (exibido no lugar do %).
SITUACAO_TRANCADO_CANCELADO = "Trancada"

_RE_CODIGO = re.compile(r"\(([^)]+)\)")
_RE_DATA = re.compile(r"(\d{2}/\d{2}/\d{4})")


def _parse_data_br(texto: str) -> datetime | None:
    match = _RE_DATA.search(texto)
    if not match:
        return None
    try:
        return datetime.strptime(match.group(1), "%d/%m/%Y")
    except ValueError:
        return None


def mapear_trancamento_cancelamento(
    ausencias_especiais: Any,
) -> dict[str, str | None]:
    """Mapa codigo → data (DD/MM/YYYY) de frequencias.ausencias_especiais.

    Formato tipico da API::

        {
          "trancamento_cancelamento": [
            ["(POA-ADM101) - 06/04/2026"],
            ["(POA-ADM108) - 06/04/2026"]
          ]
        }

    Se o mesmo codigo aparecer mais de uma vez, fica a data mais recente.
    """
    if not isinstance(ausencias_especiais, dict):
        return {}
    bruto = ausencias_especiais.get("trancamento_cancelamento")
    if not isinstance(bruto, list):
        return {}

    melhores: dict[str, tuple[datetime | None, str | None]] = {}
    for item in bruto:
        textos: list[Any]
        if isinstance(item, list):
            textos = item
        else:
            textos = [item]
        for texto_bruto in textos:
            texto = str(texto_bruto or "").strip()
            if texto == "":
                continue
            match = _RE_CODIGO.search(texto)
            if not match:
                continue
            codigo = match.group(1).strip()
            if codigo == "":
                continue
            dt = _parse_data_br(texto)
            data_str = dt.strftime("%d/%m/%Y") if dt else None
            atual = melhores.get(codigo)
            if atual is None:
                melhores[codigo] = (dt, data_str)
                continue
            dt_atual, _ = atual
            if dt is not None and (dt_atual is None or dt > dt_atual):
                melhores[codigo] = (dt, data_str)

    return {codigo: data for codigo, (_dt, data) in melhores.items()}


def codigos_trancamento_cancelamento(ausencias_especiais: Any) -> set[str]:
    """Extrai codigos de disciplina de frequencias.ausencias_especiais."""
    return set(mapear_trancamento_cancelamento(ausencias_especiais))


def aplicar_situacao_trancamento(
    disciplinas: list[dict[str, Any]],
    trancamentos: set[str] | dict[str, str | None],
) -> list[dict[str, Any]]:
    """Marca disciplinas trancadas/canceladas: situacao (+ data) no lugar do %.

    - Se o codigo ja existe na lista: situacao = Trancada,
      percentual_frequencia = None e data_trancamento quando houver.
    - Se so aparece em ausencias_especiais: inclui linha so com codigo/situacao.
    """
    if isinstance(trancamentos, dict):
        datas = dict(trancamentos)
        codigos = set(trancamentos)
    else:
        datas = {c: None for c in trancamentos}
        codigos = set(trancamentos)

    if not codigos:
        for disc in disciplinas:
            disc.setdefault("situacao", None)
            disc.setdefault("data_trancamento", None)
        return disciplinas

    vistos: set[str] = set()
    saida: list[dict[str, Any]] = []
    for disc in disciplinas:
        codigo = str(disc.get("codigo_disciplina") or "").strip()
        if codigo in codigos:
            saida.append({
                **disc,
                "percentual_frequencia": None,
                "dias_falta": [],
                "situacao": SITUACAO_TRANCADO_CANCELADO,
                "data_trancamento": datas.get(codigo),
            })
            vistos.add(codigo)
        else:
            saida.append({
                **disc,
                "situacao": disc.get("situacao"),
                "data_trancamento": disc.get("data_trancamento"),
            })
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
            "dias_falta": [],
            "situacao": SITUACAO_TRANCADO_CANCELADO,
            "data_trancamento": datas.get(codigo),
        })
    return saida
