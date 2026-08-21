"""Le datas de consulta: preferencialmente do banco, senao consultas.json."""

from __future__ import annotations

import json
from datetime import date, datetime, timedelta
from pathlib import Path
from typing import Any

from paths import ARQUIVO_CONSULTAS

FORMATOS_DATA = ("%d-%m-%Y", "%d/%m/%Y", "%Y-%m-%d")


def carregar_config(caminho: Path | None = None) -> dict[str, Any]:
    """Carrega datas: banco (configuracoes) com fallback para consultas.json."""
    dados: dict[str, Any] = {}

    try:
        from db import conectar, row_to_dict

        conn = conectar()
        cursor = conn.cursor()
        cursor.execute(
            """
            SELECT chave, valor FROM configuracoes
            WHERE chave IN (
                'frequencia_data_inicial',
                'frequencia_data_final',
                'data_referencia'
            )
            """
        )
        for row in cursor.fetchall():
            item = row_to_dict(row)
            chave = str(item.get("chave", ""))
            valor = str(item.get("valor") or "").strip()
            if chave and valor:
                dados[chave] = valor
    except Exception:
        dados = {}

    arquivo = caminho or ARQUIVO_CONSULTAS
    if arquivo.is_file():
        arquivo_dados = json.loads(arquivo.read_text(encoding="utf-8"))
        if isinstance(arquivo_dados, dict):
            for chave in (
                "frequencia_data_inicial",
                "frequencia_data_final",
                "data_referencia",
            ):
                if chave not in dados or str(dados.get(chave) or "").strip() == "":
                    valor = arquivo_dados.get(chave, "")
                    if isinstance(valor, str) and valor.strip() != "":
                        dados[chave] = valor.strip()

    if not dados:
        raise ValueError(
            "Datas de consulta ausentes. Configure em /index.php/configuracoes/api "
            "ou em config/consultas.json."
        )

    return dados


def parsear_data(texto: str) -> date:
    """Converte texto de data em objeto date."""
    texto = texto.strip()
    for formato in FORMATOS_DATA:
        try:
            return datetime.strptime(texto, formato).date()
        except ValueError:
            continue

    raise ValueError(f"Data invalida: {texto!r}")


def frequencia_data_inicial(caminho: Path | None = None) -> str:
    """Retorna a data inicial das consultas de frequencia (DD-MM-AAAA)."""
    valor = carregar_config(caminho).get("frequencia_data_inicial", "")
    if not isinstance(valor, str) or valor.strip() == "":
        raise ValueError("frequencia_data_inicial ausente na configuracao")
    return valor.strip()


def frequencia_data_final(caminho: Path | None = None) -> str:
    """Retorna a data final das consultas de frequencia (DD-MM-AAAA)."""
    valor = carregar_config(caminho).get("frequencia_data_final", "")
    if not isinstance(valor, str) or valor.strip() == "":
        raise ValueError("frequencia_data_final ausente na configuracao")
    return valor.strip()


def data_referencia(caminho: Path | None = None) -> date:
    """Retorna a data de referencia para criterios de faltas recentes."""
    valor = carregar_config(caminho).get("data_referencia", "hoje-2")
    if not isinstance(valor, str) or valor.strip() == "":
        valor = "hoje-2"

    valor = valor.strip().lower()
    if valor in ("hoje-2", "hoje_2", "auto"):
        return date.today() - timedelta(days=2)

    return parsear_data(valor)
