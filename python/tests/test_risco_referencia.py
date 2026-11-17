"""Regra de referencia de risco e indicadores por aluno.

Usa SQLite em memoria com config/schema.sql; nao le data/mapa.db nem a API.

    python3 -m unittest discover python/tests
"""

from __future__ import annotations

import sqlite3
import sys
import unittest
from datetime import date, timedelta
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import gerar_efeito_contatos  # noqa: E402
from db import garantir_schema  # noqa: E402
from risco_referencia import (  # noqa: E402
    Disciplina,
    classificar_retorno,
    primeiro_critico,
    retorno_apos,
    semana_do_semestre,
    simular_alarmes,
)

INICIO = date(2026, 8, 3)  # segunda-feira


def segundas(quantas: int, a_partir: date = INICIO) -> list[date]:
    return [a_partir + timedelta(weeks=i) for i in range(quantas)]


def dias_uteis(inicio: date, fim: date) -> list[date]:
    dias = []
    dia = inicio
    while dia <= fim:
        if dia.weekday() < 5:
            dias.append(dia)
        dia += timedelta(days=1)
    return dias


class DisciplinaTest(unittest.TestCase):
    def test_conta_aulas_e_faltas_no_intervalo(self) -> None:
        disciplina = Disciplina("X", segundas(4), {date(2026, 8, 10), date(2026, 8, 12)}, fim=date(2026, 8, 20))
        # 12/08 nao esta na grade: conta como aula a mais; 24/08 passa do fim.
        self.assertEqual(disciplina.contar(INICIO, date(2026, 8, 31)), (4, 2))
        self.assertEqual(disciplina.contar(date(2026, 8, 11), date(2026, 8, 31)), (2, 1))
        self.assertEqual(disciplina.contar(date(2026, 9, 1), date(2026, 9, 30)), (0, 0))


class SimulacaoTest(unittest.TestCase):
    def test_tres_semanas_sem_vir_e_frequencia_baixa(self) -> None:
        # Aula toda segunda; faltou 17, 24 e 31/08.
        faltas = {date(2026, 8, 17), date(2026, 8, 24), date(2026, 8, 31)}
        alarmes = simular_alarmes([Disciplina("X", segundas(12), faltas)], INICIO, date(2026, 10, 30))
        self.assertEqual(alarmes[("faltas_3semanas", "critico")], date(2026, 8, 31))
        # Carencia de 3 semanas: em 24/08, 2 faltas em 4 aulas = 50% (alto); em 31/08, 3 em 5 (critico).
        self.assertEqual(alarmes[("percentual_baixo", "alto")], date(2026, 8, 24))
        self.assertEqual(alarmes[("percentual_baixo", "critico")], date(2026, 8, 31))
        self.assertNotIn(("faltas_4dias", "critico"), alarmes)
        # No mesmo dia, vale o tipo mais grave.
        self.assertEqual(primeiro_critico(alarmes), ("faltas_3semanas", date(2026, 8, 31)))

    def test_dias_seguidos_de_falta(self) -> None:
        aulas = dias_uteis(INICIO, date(2026, 10, 30))
        faltas = set(dias_uteis(date(2026, 8, 10), date(2026, 8, 13)))  # seg a qui
        alarmes = simular_alarmes([Disciplina("X", aulas, faltas)], INICIO, date(2026, 10, 30))
        # Um alarme so, com a severidade alcancada (4 dias = critico), como em gerar_alarmes.py.
        self.assertEqual(alarmes[("faltas_4dias", "critico")], date(2026, 8, 13))
        self.assertNotIn(("faltas_4dias", "alto"), alarmes)

    def test_sem_faltas_nao_dispara(self) -> None:
        self.assertEqual(simular_alarmes([Disciplina("X", segundas(10))], INICIO, date(2026, 10, 30)), {})
        self.assertIsNone(primeiro_critico({("percentual_baixo", "alto"): INICIO}))

    def test_a_regra_nao_le_a_configuracao_do_banco(self) -> None:
        faltas = {date(2026, 8, 17), date(2026, 8, 24), date(2026, 8, 31)}
        disciplina = [Disciplina("X", segundas(12), faltas)]
        relaxada = simular_alarmes(disciplina, INICIO, date(2026, 10, 30),
                                   {**gerar_efeito_contatos.simular_alarmes.__globals__["REFERENCIA"],
                                    "faltas_semanas_total": 4, "frequencia_limite_critico": 30.0})
        self.assertNotIn(("faltas_3semanas", "critico"), relaxada)
        self.assertNotIn(("percentual_baixo", "critico"), relaxada)


class RetornoTest(unittest.TestCase):
    def test_classes(self) -> None:
        self.assertEqual(classificar_retorno(4, 1), "voltou")
        self.assertEqual(classificar_retorno(4, 2), "parcial")
        self.assertEqual(classificar_retorno(4, 4), "nao_voltou")
        self.assertEqual(classificar_retorno(2, 0), "sem_aulas")

    def test_duas_semanas_depois_sem_o_dia_da_referencia(self) -> None:
        aulas = dias_uteis(INICIO, date(2026, 9, 30))
        faltas = {date(2026, 8, 17)} | set(dias_uteis(date(2026, 8, 18), date(2026, 8, 21)))
        # Referencia 17/08: entram 18 a 31/08 (10 aulas uteis), 4 faltas.
        self.assertEqual(retorno_apos([Disciplina("X", aulas, faltas)], date(2026, 8, 17)), (10, 4, "parcial"))
        self.assertEqual(semana_do_semestre(INICIO, date(2026, 8, 17)), 3)


def banco() -> sqlite3.Connection:
    conn = sqlite3.connect(":memory:")
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA foreign_keys = ON")
    garantir_schema(conn)
    return conn


class IndicadoresTest(unittest.TestCase):
    CORTE = date(2026, 10, 18)

    def alunos(self) -> dict:
        aulas = dias_uteis(INICIO, self.CORTE)
        # Aluno 1: 4 dias seguidos de falta em 10-13/08, depois so presenca.
        um = Disciplina("X", aulas, set(dias_uteis(date(2026, 8, 10), date(2026, 8, 13))), fim=self.CORTE)
        # Aluno 2: sem risco, mas contatado.
        dois = Disciplina("X", aulas, set(), fim=self.CORTE)
        # Aluno 3: risco recente demais para medir o retorno.
        tres = Disciplina("X", aulas, set(dias_uteis(date(2026, 10, 12), date(2026, 10, 15))), fim=self.CORTE)
        info = {"nivel": "T", "ingressante": False, "auxilio": False, "saiu": False}
        return {
            (1, 1): {**info, "disciplinas": [um], "ingressante": True},
            (2, 1): {**info, "disciplinas": [dois]},
            (3, 1): {**info, "disciplinas": [tres]},
            (4, 1): {**info, "disciplinas": [dois]},
        }

    def test_linhas_por_aluno(self) -> None:
        contatos = {
            (1, 1): [
                {"data": date(2026, 8, 14), "canal": "email_automatico", "conseguiu_falar": None, "motivo": None},
                {"data": date(2026, 8, 20), "canal": "telefone", "conseguiu_falar": 1, "motivo": "trabalho"},
            ],
            (2, 1): [{"data": date(2026, 9, 1), "canal": "presencial", "conseguiu_falar": 1, "motivo": None}],
        }
        linhas = {l["par"]: l for l in gerar_efeito_contatos.montar_indicadores(self.alunos(), contatos, INICIO, self.CORTE)}
        # Aluno 4: sem risco e sem contato, fica de fora.
        self.assertEqual(sorted(linhas), [(1, 1), (2, 1), (3, 1)])

        um = linhas[(1, 1)]
        self.assertEqual((um["risco_tipo"], um["risco_data"], um["risco_semana"]), ("faltas_4dias", date(2026, 8, 13), 2))
        self.assertEqual(um["antes"], (8, 3))                      # 30/07 a 12/08: 8 aulas uteis, 3 faltas
        self.assertEqual(um["retorno"], (10, 0, "voltou"))        # 14 a 27/08
        self.assertEqual((um["total_contatos"], um["dias_ate_contato"]), (2, 1))
        self.assertEqual(um["ultimo"]["motivo"], "trabalho")
        self.assertEqual(um["contato_retorno"][2], "voltou")
        self.assertTrue(um["ingressante"])

        dois = linhas[(2, 1)]
        self.assertIsNone(dois["risco_tipo"])
        self.assertIsNone(dois["dias_ate_contato"])
        self.assertEqual(dois["primeiro"]["canal"], "presencial")

        tres = linhas[(3, 1)]
        self.assertEqual(tres["risco_tipo"], "faltas_4dias")
        self.assertIsNone(tres["retorno"][2])                     # 2 semanas nao cabem ate 18/10
        self.assertIsNone(tres["primeiro"])


class CarregarAlunosTest(unittest.TestCase):
    def test_recortes_da_tela(self) -> None:
        conn = banco()
        cur = conn.cursor()
        cur.executemany("INSERT INTO coletas (id, status) VALUES (?, ?)", [(1, "completa"), (2, "completa")])
        cur.execute("INSERT INTO cursos (id, nome_curso, curso_nivel) VALUES (1, 'Curso A', 'T')")
        cur.executemany("INSERT INTO alunos (id, login, matricula, nome) VALUES (?, ?, ?, ?)",
                        [(1, "a1", "1", "Ingressante com auxilio"), (2, "a2", "2", "Veterano que trancou"),
                         (3, "a3", "3", "Sumiu da coleta")])
        cur.executemany("INSERT INTO aluno_cursos (aluno_id, curso_id, ano_semestre_ingresso) VALUES (?, 1, ?)",
                        [(1, "2026/2"), (2, "2025/1"), (3, "2026/2")])
        cur.execute("INSERT INTO alunos_auxilio_estudantil (login) VALUES ('a1')")
        cur.execute("INSERT INTO alunos_trancados (coleta_id, aluno_id, curso_id, login, status_discente) "
                    "VALUES (2, 2, 1, 'a2', 'TRANCADO')")
        cur.executemany("INSERT INTO frequencia_curso (coleta_id, aluno_id, curso_id) VALUES (?, ?, 1)",
                        [(2, 1), (2, 2), (1, 3)])
        cur.executemany("INSERT INTO disciplina_aulas (codigo_disciplina, curso_id, data_aula) VALUES ('X', 1, ?)",
                        [(d.isoformat(),) for d in segundas(6)])
        cur.executemany("INSERT INTO disciplina_ultima_aula (coleta_id, codigo_disciplina, disciplina, curso_id, "
                        "data_ultima_aula) VALUES (?, 'X', 'X', 1, ?)", [(1, "2026-08-24"), (2, "2026-09-07")])
        cur.executemany("INSERT INTO frequencia_disciplina (coleta_id, aluno_id, curso_id, codigo_disciplina, "
                        "disciplina) VALUES (?, ?, 1, 'X', 'X')", [(2, 1), (2, 2), (1, 3)])
        cur.execute("INSERT INTO faltas_dia (coleta_id, aluno_id, curso_id, codigo_disciplina, data_falta) "
                    "VALUES (1, 3, 1, 'X', '2026-08-17')")

        semestre = {"primeira_id": 1, "ultima_id": 2, "inicio": INICIO, "periodo": "2026/2",
                    "cortes": {1: date(2026, 8, 25), 2: date(2026, 9, 10)}}
        alunos = gerar_efeito_contatos.carregar_alunos(cur, semestre)

        self.assertEqual({p: (a["ingressante"], a["auxilio"], a["saiu"], a["nivel"]) for p, a in alunos.items()}, {
            (1, 1): (True, True, False, "T"),
            (2, 1): (False, False, True, "T"),
            (3, 1): (True, False, True, "T"),
        })
        # Aluno 3 ficou na coleta 1: aulas ate a ultima chamada dessa coleta (24/08).
        tres = alunos[(3, 1)]["disciplinas"][0]
        self.assertEqual((tres.fim, tres.faltas), (date(2026, 8, 24), {date(2026, 8, 17)}))
        self.assertEqual(alunos[(1, 1)]["disciplinas"][0].fim, date(2026, 9, 7))


if __name__ == "__main__":
    unittest.main()
