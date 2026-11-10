"""Regra de referencia de risco: os alarmes do MAPA simulados com parametros fixos.

Para avaliar a eficacia dos contatos, "aluno em risco" precisa significar a
mesma coisa em todos os semestres, inclusive nos anteriores ao MAPA e depois
de qualquer ajuste em Configuracoes -> Alarmes. Por isso a avaliacao nao usa
os alarmes gravados (que seguem a configuracao do momento) e sim esta
simulacao, com os valores padrao das regras (config_alarmes.PADRAO) fixados
em REFERENCIA.

A simulacao recebe, por aluno, as disciplinas do semestre com as datas de aula
e as faltas por dia, e avalia as tres regras duas vezes por semana (segunda e
quinta), guardando o primeiro dia em que cada alarme dispararia. Diferencas
em relacao a gerar_alarmes.py, aceitas por serem iguais em todos os
semestres: a frequencia e contada por dias de aula (a API conta horarios) e
a matricula atrasada nao adianta a carencia.

Funcoes puras, sem I/O: quem le o banco ou os JSONs monta as Disciplinas.
"""

from __future__ import annotations

from bisect import bisect_left, bisect_right
from dataclasses import dataclass, field
from datetime import date, timedelta
from typing import Any

from config_alarmes import PADRAO
from gerar_alarmes import (
    encontrar_sequencia_3_semanas,
    obter_janela_uteis,
    sequencias_faltas_uteis,
)

# Valores fixos da referencia (os padroes das regras na data em que a avaliacao
# foi definida). Mudar isto muda a definicao de risco de todos os semestres.
REFERENCIA: dict[str, Any] = {
    "frequencia_limite": float(PADRAO["frequencia_limite"]),
    "frequencia_limite_critico": float(PADRAO["frequencia_limite_critico"]),
    "frequencia_carencia_semanas": int(PADRAO["frequencia_carencia_semanas"]),
    "faltas_dias_minimo": int(PADRAO["faltas_dias_minimo"]),
    "faltas_dias_janela": int(PADRAO["faltas_dias_janela"]),
    "faltas_dias_critico": int(PADRAO["faltas_dias_critico"]),
    "faltas_semanas_total": int(PADRAO["faltas_semanas_total"]),
    "faltas_semanas_janela_dias": int(PADRAO["faltas_semanas_janela_dias"]),
}

TIPOS = ("faltas_3semanas", "faltas_4dias", "percentual_baixo")
# Quando mais de um tipo dispara no mesmo dia, a situacao do aluno e a mais grave.
PRIORIDADE_TIPO = TIPOS
# Segunda e quinta: aproxima a coleta diaria sem avaliar todos os dias.
DIAS_AVALIACAO = (0, 3)

# Retorno: presenca nas 2 semanas seguintes a referencia.
SEMANAS_RETORNO = 2
MIN_AULAS_RETORNO = 3
PRESENCA_VOLTOU = 0.75
RETORNOS = ("voltou", "parcial", "nao_voltou", "sem_aulas")

Alarmes = dict[tuple[str, str], date]


@dataclass
class Disciplina:
    """Uma disciplina do aluno no semestre, com o que a simulacao precisa."""

    codigo: str
    aulas: list[date]           # datas de aula previstas, ordenadas, sem feriados
    faltas: set[date] = field(default_factory=set)
    # Ultimo dia valido: trancamento, ultima chamada lancada ou data dos dados.
    fim: date = date.max
    situacao: str | None = None  # situacao final (semestre fechado), se conhecida

    def contar(self, inicio: date, fim: date) -> tuple[int, int]:
        """(aulas, faltas) em [inicio, fim] ate a validade; falta fora da grade conta como aula."""
        fim = min(fim, self.fim)
        if fim < inicio:
            return 0, 0
        planejadas = bisect_right(self.aulas, fim) - bisect_left(self.aulas, inicio)
        faltas = [d for d in self.faltas if inicio <= d <= fim]
        extras = 0
        for dia in faltas:
            posicao = bisect_left(self.aulas, dia)
            if posicao >= len(self.aulas) or self.aulas[posicao] != dia:
                extras += 1
        return planejadas + extras, len(faltas)


def presenca(disciplinas: list[Disciplina], inicio: date, fim: date) -> tuple[int, int]:
    """(aulas, faltas) somadas de todas as disciplinas em [inicio, fim]."""
    aulas = faltas = 0
    for disciplina in disciplinas:
        a, f = disciplina.contar(inicio, fim)
        aulas += a
        faltas += f
    return aulas, faltas


def simular_alarmes(
    disciplinas: list[Disciplina],
    inicio: date,
    ultimo_dia: date,
    referencia: dict[str, Any] | None = None,
) -> Alarmes:
    """Primeiro dia em que cada (tipo, severidade) dispararia, de inicio+7 a ultimo_dia."""
    regras = referencia or REFERENCIA
    limite = float(regras["frequencia_limite"])
    critico = float(regras["frequencia_limite_critico"])
    carencia = timedelta(weeks=int(regras["frequencia_carencia_semanas"]))
    dias_minimo = int(regras["faltas_dias_minimo"])
    dias_janela = int(regras["faltas_dias_janela"])
    dias_critico = int(regras["faltas_dias_critico"])
    semanas_total = int(regras["faltas_semanas_total"])
    semanas_janela = int(regras["faltas_semanas_janela_dias"])

    alarmes: Alarmes = {}
    com_aulas = [d for d in disciplinas if d.aulas]
    dia = inicio + timedelta(days=7)
    while dia <= ultimo_dia:
        if dia.weekday() in DIAS_AVALIACAO:
            faltas_aluno = sorted({f for d in com_aulas for f in d.faltas if f <= min(dia, d.fim)})
            janela = set(obter_janela_uteis(dia, dias_janela))
            sequencias = [
                s for s in sequencias_faltas_uteis(faltas_aluno, dia)
                if len(s) >= dias_minimo and any(x in janela for x in s)
            ]
            if sequencias:
                severidade = "critico" if max(len(s) for s in sequencias) >= dias_critico else "alto"
                alarmes.setdefault(("faltas_4dias", severidade), dia)

            for disciplina in com_aulas:
                if dia > disciplina.fim:
                    continue
                aulas, faltas = disciplina.contar(inicio, dia)
                if aulas and dia >= disciplina.aulas[0] + carencia:
                    percentual = 100 * (aulas - faltas) / aulas
                    if percentual < critico:
                        alarmes.setdefault(("percentual_baixo", "critico"), dia)
                    if percentual < limite:
                        alarmes.setdefault(("percentual_baixo", "alto"), dia)
                if ("faltas_3semanas", "critico") not in alarmes:
                    faltas_validas = [f for f in disciplina.faltas if f <= disciplina.fim]
                    aulas_validas = {a for a in disciplina.aulas if a <= disciplina.fim}
                    if encontrar_sequencia_3_semanas(
                        faltas_validas, aulas_validas, dia, semanas_total, semanas_janela
                    ):
                        alarmes[("faltas_3semanas", "critico")] = dia
        dia += timedelta(days=1)
    return alarmes


def primeiro_critico(alarmes: Alarmes) -> tuple[str, date] | None:
    """(tipo, dia) do primeiro alarme critico; no mesmo dia vale o tipo mais grave."""
    criticos = {tipo: dia for (tipo, severidade), dia in alarmes.items() if severidade == "critico"}
    if not criticos:
        return None
    dia = min(criticos.values())
    tipo = next(t for t in PRIORIDADE_TIPO if criticos.get(t) == dia)
    return tipo, dia


def classificar_retorno(aulas: int, faltas: int, min_aulas: int = MIN_AULAS_RETORNO) -> str:
    """voltou (presenca >= 75%), parcial, nao_voltou (0%) ou sem_aulas (poucas aulas)."""
    if aulas < min_aulas:
        return "sem_aulas"
    presenca_ = (aulas - faltas) / aulas
    if presenca_ >= PRESENCA_VOLTOU:
        return "voltou"
    return "parcial" if presenca_ > 0 else "nao_voltou"


def retorno_apos(
    disciplinas: list[Disciplina],
    referencia: date,
    semanas: int = SEMANAS_RETORNO,
) -> tuple[int, int, str]:
    """(aulas, faltas, classe) nas `semanas` semanas seguintes a referencia (o dia dela nao entra)."""
    aulas, faltas = presenca(
        disciplinas, referencia + timedelta(days=1), referencia + timedelta(days=7 * semanas)
    )
    return aulas, faltas, classificar_retorno(aulas, faltas)


def semana_do_semestre(inicio: date, dia: date) -> int:
    """Semana do semestre em que o dia cai (1 = a semana que comeca em inicio)."""
    return (dia - inicio).days // 7 + 1
