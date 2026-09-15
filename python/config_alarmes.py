"""Parametros das regras de alarme, definidos pelo administrador no portal.

As chaves ficam na tabela `configuracoes` (prefixo `alarme_`) e sao gravadas
em Configuracoes -> Alarmes. Sem nada gravado, valem os padroes abaixo — que
reproduzem o comportamento antigo (frequencia < 75%, 3 dias uteis seguidos,
3 semanas consecutivas).
"""

from __future__ import annotations

from typing import Any

PREFIXO_CHAVE = "alarme_"

PADRAO: dict[str, Any] = {
    "frequencia_ativo": True,
    "frequencia_limite": 75.0,
    "frequencia_limite_critico": 50.0,
    "frequencia_carencia_semanas": 3,
    "frequencia_mensagem": "Frequência {percentual}% (abaixo de {limite}%)",
    "faltas_dias_ativo": True,
    "faltas_dias_minimo": 3,
    "faltas_dias_janela": 4,
    "faltas_dias_critico": 4,
    "faltas_dias_mensagem": "{dias} dias úteis: {datas}",
    "faltas_semanas_ativo": True,
    "faltas_semanas_total": 3,
    "faltas_semanas_janela_dias": 7,
    "faltas_semanas_severidade": "critico",
    "faltas_semanas_mensagem": "Faltas em {semanas} semanas consecutivas na disciplina",
}

VERDADEIROS = ("1", "true", "yes", "on", "sim")


def _para_float(valor: Any, padrao: float) -> float:
    """Converte para float aceitando virgula decimal; padrao se invalido."""
    if isinstance(valor, bool):
        return padrao
    if isinstance(valor, (int, float)):
        return float(valor)

    texto = str(valor or "").strip().replace(",", ".")
    try:
        return float(texto)
    except ValueError:
        return padrao


def _para_int(valor: Any, padrao: int) -> int:
    """Converte para int; padrao se invalido."""
    numero = _para_float(valor, float(padrao))
    try:
        return int(round(numero))
    except (TypeError, ValueError):
        return padrao


def _para_bool(valor: Any, padrao: bool) -> bool:
    """Converte para bool aceitando 'true'/'1'/'on'; padrao se ausente."""
    if valor is None:
        return padrao
    if isinstance(valor, bool):
        return valor

    texto = str(valor).strip().lower()
    if texto == "":
        return padrao

    return texto in VERDADEIROS


def _para_texto(valor: Any, padrao: str) -> str:
    """Retorna texto nao vazio (limitado a 200 caracteres) ou o padrao."""
    texto = str(valor or "").strip()
    if texto == "":
        return padrao

    return texto[:200]


def normalizar(entrada: dict[str, Any]) -> dict[str, Any]:
    """Aplica padroes e faixas seguras (mesmos limites do portal).

    Args:
        entrada: Valores brutos (texto do banco ou ja convertidos).

    Returns:
        Configuracao completa e consistente.
    """
    limite = min(100.0, max(0.1, _para_float(
        entrada.get("frequencia_limite"), PADRAO["frequencia_limite"]
    )))
    critico = min(limite, max(0.0, _para_float(
        entrada.get("frequencia_limite_critico"), PADRAO["frequencia_limite_critico"]
    )))
    minimo = min(30, max(2, _para_int(
        entrada.get("faltas_dias_minimo"), PADRAO["faltas_dias_minimo"]
    )))
    dias_critico = min(60, max(minimo, _para_int(
        entrada.get("faltas_dias_critico"), PADRAO["faltas_dias_critico"]
    )))
    severidade = str(entrada.get("faltas_semanas_severidade") or "").strip().lower()
    if severidade not in ("alto", "critico"):
        severidade = str(PADRAO["faltas_semanas_severidade"])

    return {
        "frequencia_ativo": _para_bool(
            entrada.get("frequencia_ativo"), bool(PADRAO["frequencia_ativo"])
        ),
        "frequencia_limite": round(limite, 1),
        "frequencia_limite_critico": round(critico, 1),
        "frequencia_carencia_semanas": min(52, max(0, _para_int(
            entrada.get("frequencia_carencia_semanas"),
            PADRAO["frequencia_carencia_semanas"],
        ))),
        "frequencia_mensagem": _para_texto(
            entrada.get("frequencia_mensagem"), str(PADRAO["frequencia_mensagem"])
        ),
        "faltas_dias_ativo": _para_bool(
            entrada.get("faltas_dias_ativo"), bool(PADRAO["faltas_dias_ativo"])
        ),
        "faltas_dias_minimo": minimo,
        "faltas_dias_janela": min(30, max(1, _para_int(
            entrada.get("faltas_dias_janela"), PADRAO["faltas_dias_janela"]
        ))),
        "faltas_dias_critico": dias_critico,
        "faltas_dias_mensagem": _para_texto(
            entrada.get("faltas_dias_mensagem"), str(PADRAO["faltas_dias_mensagem"])
        ),
        "faltas_semanas_ativo": _para_bool(
            entrada.get("faltas_semanas_ativo"), bool(PADRAO["faltas_semanas_ativo"])
        ),
        "faltas_semanas_total": min(20, max(2, _para_int(
            entrada.get("faltas_semanas_total"), PADRAO["faltas_semanas_total"]
        ))),
        "faltas_semanas_janela_dias": min(90, max(1, _para_int(
            entrada.get("faltas_semanas_janela_dias"),
            PADRAO["faltas_semanas_janela_dias"],
        ))),
        "faltas_semanas_severidade": severidade,
        "faltas_semanas_mensagem": _para_texto(
            entrada.get("faltas_semanas_mensagem"),
            str(PADRAO["faltas_semanas_mensagem"]),
        ),
    }


def carregar_config_alarmes(cursor: Any | None = None) -> dict[str, Any]:
    """Le as chaves `alarme_*` do banco e devolve a configuracao normalizada.

    Args:
        cursor: Cursor SQLite ja aberto (opcional).

    Returns:
        Configuracao das regras de alarme (padroes quando nada foi gravado).
    """
    entrada: dict[str, Any] = {}

    try:
        if cursor is None:
            from db import conectar

            cursor = conectar().cursor()

        cursor.execute(
            "SELECT chave, valor FROM configuracoes WHERE chave LIKE ?",
            (PREFIXO_CHAVE + "%",),
        )
        for row in cursor.fetchall():
            item = dict(row) if not isinstance(row, dict) else row
            chave = str(item.get("chave", ""))
            campo = chave[len(PREFIXO_CHAVE):]
            if campo in PADRAO:
                entrada[campo] = item.get("valor")
    except Exception:  # noqa: BLE001 - banco novo/sem a tabela: usa padroes
        entrada = {}

    return normalizar(entrada)


def formatar_numero(valor: float) -> str:
    """Formata percentual sem casa decimal inutil (75.0 -> '75')."""
    texto = f"{float(valor):.1f}"
    if texto.endswith(".0"):
        return texto[:-2]

    return texto


def formatar_mensagem(template: str, valores: dict[str, Any]) -> str:
    """Substitui os campos {entre_chaves} do texto configurado.

    Campos desconhecidos ficam como estao (nao quebram a geracao).

    Args:
        template: Texto configurado pelo administrador.
        valores: Campos disponiveis para a regra.

    Returns:
        Mensagem final do alarme.
    """
    texto = str(template or "").strip()
    for campo, valor in valores.items():
        texto = texto.replace("{" + campo + "}", str(valor))

    return texto.strip()
