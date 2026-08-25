#!/usr/bin/env python3
"""Status de discente usados na coleta MAPA.

A 1ª consulta (matriculados) pode trazer vários status. A 2ª consulta e o
controle de frequência/trancamento usam as regras abaixo.
"""

from __future__ import annotations


def normalizar_status(status: str) -> str:
    """Normaliza status para comparação (maiúsculas, espaços, sem acento)."""
    texto = " ".join(str(status or "").strip().upper().split())
    return (
        texto.replace("Á", "A")
        .replace("À", "A")
        .replace("Ã", "A")
        .replace("Â", "A")
        .replace("É", "E")
        .replace("Ê", "E")
        .replace("Í", "I")
        .replace("Ó", "O")
        .replace("Ô", "O")
        .replace("Õ", "O")
        .replace("Ú", "U")
        .replace("Ç", "C")
    )


# Controle de frequência / alarmes / e-mails (via status_discente da 2ª consulta).
STATUS_CONTROLE = frozenset({
    "ATIVO",
    "FORMANDO",
})

# Trancamento (confirmado na 2ª consulta).
STATUS_TRANCADOS = frozenset({
    "TRANC. AUTOMATICO",
    "TRANCADO",
})


def status_eh_controle(status: str) -> bool:
    """ATIVO ou FORMANDO — entram em frequência e alarmes."""
    return normalizar_status(status) in STATUS_CONTROLE


def status_eh_trancado(status: str) -> bool:
    """TRANCADO / TRANC. AUTOMÁTICO."""
    return normalizar_status(status) in STATUS_TRANCADOS


def status_vai_segunda_consulta(status: str) -> bool:
    """Status da 1ª consulta que disparam a consulta por login."""
    return status_eh_controle(status) or status_eh_trancado(status)
