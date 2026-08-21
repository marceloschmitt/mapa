#!/usr/bin/env python3
"""Utilitarios para parse de turno_turma do SIGAA (resposta_matriculas.json).

Formato tipico:
  2N1234 (29/07/2026 - 07/08/2026),  7N1234 (08/08/2026 - 09/08/2026), ...

O primeiro digito e o dia da semana no padrao SIGAA:
  1=domingo, 2=segunda, 3=terca, 4=quarta, 5=quinta, 6=sexta, 7=sabado.

Cada bloco traz um intervalo; as datas efetivas de aula sao os dias
daquele weekday dentro do intervalo (inclusive).
"""

from __future__ import annotations

import re
from datetime import date, datetime, timedelta
from typing import Any

# Dias de aula considerados na grade (seg..sab). Domingo fica de fora.
DIAS_AULA_SIGAA = (2, 3, 4, 5, 6, 7)

ROTULOS_DIA = {
    1: "domingo",
    2: "segunda",
    3: "terça",
    4: "quarta",
    5: "quinta",
    6: "sexta",
    7: "sábado",
}

# Bloco: 2N1234 (29/07/2026 - 07/08/2026)
# Tambem: 4T6  4N1234 (29/07/2026 - 28/08/2026) — o dia e o codigo imediatamente
# antes do intervalo, nao o primeiro token da serie.
_RE_BLOCO = re.compile(
    r"([1-7])[A-Za-z]\w*\s*"
    r"\((\d{2}/\d{2}/\d{4})\s*-\s*(\d{2}/\d{2}/\d{4})\)"
)

# Digito do dia no inicio de cada bloco (fallback sem intervalo)
_RE_DIA = re.compile(r"(?:^|,)\s*([1-7])[A-Za-z]")


def _sigaa_para_weekday_python(dia_sigaa: int) -> int:
    """Converte dia SIGAA (1=dom .. 7=sab) para weekday Python (0=seg .. 6=dom)."""
    return (dia_sigaa + 5) % 7


def _parse_data_br(texto: str) -> date | None:
    """Converte DD/MM/AAAA em date."""
    try:
        return datetime.strptime(texto.strip(), "%d/%m/%Y").date()
    except ValueError:
        return None


def extrair_datas_aula(
    turno_turma: Any,
    *,
    incluir_sabado: bool = True,
    incluir_domingo: bool = False,
) -> list[date]:
    """Expande turno_turma em datas efetivas de aula (ordenadas, unicas).

    Para cada bloco `DN... (inicio - fim)`, inclui todos os dias do weekday D
    dentro do intervalo fechado [inicio, fim].
    """
    if turno_turma is None:
        return []

    texto = str(turno_turma).strip()
    if texto == "":
        return []

    permitidos = set(DIAS_AULA_SIGAA)
    if not incluir_sabado:
        permitidos.discard(7)
    if incluir_domingo:
        permitidos.add(1)

    datas: set[date] = set()
    for match in _RE_BLOCO.finditer(texto):
        dia_sigaa = int(match.group(1))
        if dia_sigaa not in permitidos:
            continue

        inicio = _parse_data_br(match.group(2))
        fim = _parse_data_br(match.group(3))
        if inicio is None or fim is None:
            continue
        if fim < inicio:
            inicio, fim = fim, inicio

        alvo = _sigaa_para_weekday_python(dia_sigaa)
        atual = inicio
        while atual <= fim:
            if atual.weekday() == alvo:
                datas.add(atual)
            atual += timedelta(days=1)

    return sorted(datas)


def extrair_dias_sigaa(
    turno_turma: Any,
    *,
    apenas_uteis: bool = False,
    incluir_sabado: bool = True,
) -> list[int]:
    """Extrai dias da semana a partir de turno_turma.

    Preferencialmente deriva dos intervalos (datas efetivas). Se nao houver
    intervalo parseavel, cai no digito do codigo do turno.

    Args:
        turno_turma: Texto bruto da API.
        apenas_uteis: Se True, remove sabado (7) e domingo (1).
        incluir_sabado: Se True (e nao apenas_uteis), mantem sabado.
    """
    datas = extrair_datas_aula(
        turno_turma,
        incluir_sabado=incluir_sabado and not apenas_uteis,
        incluir_domingo=False,
    )
    if datas:
        dias: set[int] = set()
        for d in datas:
            # Python Mon=0 .. Sun=6  -> SIGAA Mon=2 .. Sat=7, Sun=1
            dia_sigaa = ((d.weekday() + 1) % 7) + 1
            if apenas_uteis and dia_sigaa not in (2, 3, 4, 5, 6):
                continue
            if not incluir_sabado and dia_sigaa == 7:
                continue
            if dia_sigaa == 1:
                continue
            dias.add(dia_sigaa)
        return sorted(dias)

    if turno_turma is None:
        return []

    texto = str(turno_turma).strip()
    if texto == "":
        return []

    dias_fb: set[int] = set()
    for match in _RE_DIA.finditer(texto):
        dia = int(match.group(1))
        if apenas_uteis and dia not in (2, 3, 4, 5, 6):
            continue
        if dia == 1:
            continue
        if not incluir_sabado and dia == 7:
            continue
        if dia not in DIAS_AULA_SIGAA and dia != 7:
            continue
        dias_fb.add(dia)

    return sorted(dias_fb)


def dias_para_texto(dias: list[int]) -> str:
    """Serializa lista de dias como '2,4,6'."""
    return ",".join(str(d) for d in dias)


def texto_para_dias(texto: Any) -> list[int]:
    """Converte '2,4,6' em lista de ints."""
    if texto is None:
        return []
    bruto = str(texto).strip()
    if bruto == "":
        return []
    dias: list[int] = []
    for parte in bruto.split(","):
        parte = parte.strip()
        if parte.isdigit():
            dias.append(int(parte))
    return dias


def datas_para_texto(datas: list[date]) -> str:
    """Serializa datas como 'YYYY-MM-DD,YYYY-MM-DD'."""
    return ",".join(d.isoformat() for d in datas)


def texto_para_datas(texto: Any) -> list[date]:
    """Converte texto CSV de datas ISO em lista de date."""
    if texto is None:
        return []
    bruto = str(texto).strip()
    if bruto == "":
        return []
    datas: list[date] = []
    for parte in bruto.split(","):
        parte = parte.strip()
        if parte == "":
            continue
        try:
            datas.append(date.fromisoformat(parte))
        except ValueError:
            continue
    return datas
