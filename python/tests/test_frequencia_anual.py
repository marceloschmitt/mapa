"""Testes da soma da frequencia anual dos integrados (sem chamar a API).

Rodar na raiz do projeto: python3 -m unittest discover -v python/tests
"""

from __future__ import annotations

import json
import sqlite3
import sys
import tempfile
import unittest
from datetime import date
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import analisar_frequencia  # noqa: E402
import integrados_anual as ia  # noqa: E402
from db import garantir_schema  # noqa: E402

INICIO = date(2026, 2, 15)
CONFIG = {"integrados_data_inicio": "15-02-2026", "frequencia_data_inicial": "03-08-2026"}


def disciplina(codigo: str, matricula: int, horarios: int, ausencias: int) -> dict:
    return {
        "cod_disciplina": codigo,
        "nome": f"Disciplina {codigo}",
        "id_matricula_componente": matricula,
        "frequencia": {
            "horarios": horarios,
            "ausencias": ausencias,
            "presencas": horarios - ausencias,
        },
        "ausencias": [],
    }


def vinculo(disciplinas: list[dict], justificadas: int = 0, nivel: str = "N") -> dict:
    horarios = sum(d["frequencia"]["horarios"] for d in disciplinas)
    ausencias = sum(d["frequencia"]["ausencias"] for d in disciplinas)
    return {
        "login": "aluno1",
        "matricula": "2026000001",
        "id_discente": "1",
        "curso_nivel": nivel,
        "status_discente": "ATIVO",
        "curso": "Tecnico Integrado",
        "total": {
            "horarios_totais": horarios,
            "ausencias_totais": ausencias,
            "presencas_totais": horarios - ausencias,
            "frequencia_com_ausencias_justificadas": {"ausencias_justificadas_totais": justificadas},
        },
        "disciplinas": disciplinas,
    }


def arquivo(blocos: list[tuple[str, str, list[dict]]], de: str = "2026-02-15", ate: str = "2026-08-02") -> dict:
    return {
        "data_inicial": de,
        "data_final": ate,
        "blocos": [{"data_inicial": a, "data_final": b, "vinculos": v} for a, b, v in blocos],
    }


class PercentualTest(unittest.TestCase):
    def test_arredonda_como_a_api(self):
        self.assertEqual(ia.percentual(29, 32, 2), 90.63)
        self.assertEqual(ia.percentual(29, 32, 0), 91)
        self.assertEqual(ia.percentual(1, 8, 0), 13)
        self.assertEqual(ia.percentual(32, 32, 2), 100)
        self.assertIsNone(ia.percentual(0, 0, 2))


class SomarTest(unittest.TestCase):
    def test_soma_disciplinas_e_total(self):
        atual = vinculo([disciplina("MAT", 10, 20, 2), disciplina("POR", 11, 10, 0)], justificadas=1)
        anteriores = [
            vinculo([disciplina("MAT", 10, 40, 10), disciplina("ART", 12, 8, 8)], justificadas=2),
            vinculo([disciplina("POR", 11, 30, 3)]),
        ]
        anual = ia.somar(atual, anteriores, INICIO)

        self.assertEqual(anual["desde"], "2026-02-15")
        self.assertEqual(
            anual["disciplinas"]["MAT"],
            {"horarios": 60, "ausencias": 12, "presencas": 48, "percentual_frequencia": 80},
        )
        self.assertEqual(anual["disciplinas"]["POR"]["percentual_frequencia"], 92.5)
        self.assertNotIn("ART", anual["disciplinas"])

        geral = anual["geral"]
        self.assertEqual((geral["horarios_totais"], geral["ausencias_totais"]), (108, 23))
        self.assertEqual(geral["percentual_frequencia_total"], 79)
        self.assertEqual(geral["ausencias_justificadas_totais"], 3)
        self.assertEqual(geral["percentual_com_ausencias_justificadas"], 81)

    def test_casa_por_matricula_do_componente(self):
        atual = vinculo([disciplina("MAT", 99, 10, 0)])
        anteriores = [vinculo([disciplina("MAT", 10, 10, 10)])]
        self.assertEqual(ia.somar(atual, anteriores, INICIO)["disciplinas"]["MAT"]["horarios"], 10)


class CarregarTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.caminho = Path(self.dir.name) / "integrados_anual_2026.json"
        patcher = mock.patch.object(ia, "caminho_arquivo", return_value=self.caminho)
        patcher.start()
        self.addCleanup(patcher.stop)
        self.addCleanup(self.dir.cleanup)

    def gravar(self, dados: dict) -> None:
        self.caminho.write_text(json.dumps(dados), encoding="utf-8")

    def test_sem_configuracao_ou_primeiro_semestre(self):
        self.assertEqual(ia.carregar({"frequencia_data_inicial": "03-08-2026"}), (None, {}, None))
        self.assertEqual(
            ia.carregar({"integrados_data_inicio": "15-02-2026", "frequencia_data_inicial": "15-02-2026"}),
            (None, {}, None),
        )

    def test_arquivo_ausente_avisa(self):
        inicio, anteriores, aviso = ia.carregar(CONFIG)
        self.assertEqual((inicio, anteriores), (INICIO, {}))
        self.assertIn("nao encontrado", aviso)

    def test_cobertura_completa_indexa_so_integrados(self):
        integrado = vinculo([disciplina("MAT", 10, 10, 1)])
        superior = dict(vinculo([]), login="outro", curso_nivel="G")
        self.gravar(arquivo([
            ("2026-02-15", "2026-03-31", [integrado, superior]),
            ("2026-04-01", "2026-08-02", [integrado]),
        ]))
        _, anteriores, aviso = ia.carregar(CONFIG)
        self.assertIsNone(aviso)
        self.assertEqual(list(anteriores), [("aluno1", "2026000001")])
        self.assertEqual(len(anteriores[("aluno1", "2026000001")]), 2)

    def test_buraco_ou_periodo_errado_avisa(self):
        self.gravar(arquivo([
            ("2026-02-15", "2026-03-31", []),
            ("2026-05-01", "2026-08-02", []),
        ]))
        _, anteriores, aviso = ia.carregar(CONFIG)
        self.assertEqual(anteriores, {})
        self.assertIn("01/04/2026", aviso)

        self.gravar(arquivo([("2026-02-15", "2026-07-31", [])], ate="2026-07-31"))
        _, anteriores, aviso = ia.carregar(CONFIG)
        self.assertEqual(anteriores, {})
        self.assertIn("incompleto", aviso)


class AnalisarTest(unittest.TestCase):
    def montar(self, atual: dict) -> list[dict]:
        anteriores = {("aluno1", "2026000001"): [vinculo([disciplina("MAT", 10, 40, 10)])]}
        return analisar_frequencia.montar_resultado([atual], {}, {}, INICIO, anteriores)

    def test_so_integrado_ganha_frequencia_anual(self):
        resultado = self.montar(vinculo([disciplina("MAT", 10, 20, 2)]))
        self.assertEqual(len(resultado), 1)
        registro = resultado[0]
        self.assertEqual(registro["frequencia_anual"]["disciplinas"]["MAT"]["horarios"], 60)
        self.assertEqual(registro["disciplinas"][0]["horarios"], 20)

        resultado = self.montar(vinculo([disciplina("MAT", 10, 20, 2)], nivel="G"))
        self.assertNotIn("frequencia_anual", resultado[0])


class SchemaTest(unittest.TestCase):
    def test_coluna_frequencia_desde(self):
        conn = sqlite3.connect(":memory:")
        garantir_schema(conn)
        colunas = {linha[1] for linha in conn.execute("PRAGMA table_info(frequencia_curso)")}
        self.assertIn("frequencia_desde", colunas)


if __name__ == "__main__":
    unittest.main()
