#!/usr/bin/env python3
"""Manipulacao de periodo letivo no formato AAAA/S.

Funcoes puras, sem I/O. Os scripts manuais (gerar_passe_livre, gerar_perda_vaga)
ainda mantem copias proprias destas regras.
"""

from __future__ import annotations

import re


def validar_periodo(periodo: str) -> str:
    """Valida e normaliza AAAA/S."""
    texto = str(periodo or "").strip()
    partes = texto.split("/")
    if len(partes) != 2:
        raise ValueError(f"Periodo invalido: {periodo!r} (use AAAA/S).")
    ano = int(partes[0])
    sem = int(partes[1])
    if sem not in (1, 2):
        raise ValueError(f"Semestre invalido em {periodo!r} (use 1 ou 2).")
    return f"{ano}/{sem}"


def semestre_anterior(periodo: str) -> str:
    """Semestre imediatamente anterior (2026/1 -> 2025/2)."""
    ano, sem = validar_periodo(periodo).split("/")
    ano_int = int(ano)
    if int(sem) == 1:
        return f"{ano_int - 1}/2"
    return f"{ano_int}/1"


def ultimos_semestres_anteriores(periodo_atual: str, quantidade: int) -> list[str]:
    """Os N semestres imediatamente anteriores, do mais recente ao mais antigo."""
    if quantidade < 0:
        raise ValueError("quantidade deve ser >= 0.")
    saida: list[str] = []
    cursor = validar_periodo(periodo_atual)
    for _ in range(quantidade):
        cursor = semestre_anterior(cursor)
        saida.append(cursor)
    return saida


def periodo_para_arquivo(periodo: str) -> str:
    """Converte 2026/1 em 2026_1 (sufixo de cache JSON)."""
    return re.sub(r"[^\w.-]+", "_", str(periodo or "").strip())
