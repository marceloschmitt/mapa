"""Testes da busca dos meses encerrados dos integrados (sem chamar a API).

Rodar na raiz do projeto: python3 -m unittest discover -v python/tests
"""

from __future__ import annotations

import json
import sys
import tempfile
import unittest
from datetime import date
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import consulta_integrados_anual as cia  # noqa: E402

CONFIG = {
    "integrados_data_inicio": "02-03-2026",
    "frequencia_data_inicial": "03-08-2026",
    "api_url_alunos_massa_intervalo": (
        "https://api.exemplo/intervalo?data_inicial={data_inicial}&data_final={data_final}"
    ),
}


def vinculo(nivel: str, login: str) -> dict:
    return {"login": login, "curso_nivel": nivel, "disciplinas": []}


class PeriodoTest(unittest.TestCase):
    def test_blocos_de_dois_meses_sem_buracos(self) -> None:
        inicio, fim = cia.periodo_do_ano(CONFIG)
        self.assertEqual((inicio, fim), (date(2026, 3, 2), date(2026, 8, 2)))
        self.assertEqual(cia.dividir_periodo(inicio, fim), [
            (date(2026, 3, 2), date(2026, 4, 30)),
            (date(2026, 5, 1), date(2026, 6, 30)),
            (date(2026, 7, 1), date(2026, 8, 2)),
        ])

    def test_mes_a_mes_atravessa_o_ano(self) -> None:
        self.assertEqual(cia.dividir_periodo(date(2026, 12, 15), date(2027, 1, 10), meses=1), [
            (date(2026, 12, 15), date(2026, 12, 31)),
            (date(2027, 1, 1), date(2027, 1, 10)),
        ])

    def test_semestre_que_comeca_antes_nao_tem_o_que_buscar(self) -> None:
        config = dict(CONFIG, frequencia_data_inicial="15-02-2026")
        self.assertIsNone(cia.periodo_do_ano(config))

    def test_inicio_nao_configurado(self) -> None:
        with self.assertRaises(ValueError):
            cia.periodo_do_ano(dict(CONFIG, integrados_data_inicio=""))

    def test_url_com_datas_iso_e_ativos(self) -> None:
        self.assertEqual(
            cia.url_com_ativos(CONFIG, date(2026, 3, 2), date(2026, 4, 30)),
            "https://api.exemplo/intervalo?data_inicial=2026-03-02&data_final=2026-04-30&status=ATIVO",
        )

    def test_guarda_so_integrados(self) -> None:
        vinculos = [vinculo("N", "a"), vinculo("T", "b"), vinculo(None, "c"), "lixo", vinculo("n", "d")]
        self.assertEqual([v["login"] for v in cia.somente_integrados(vinculos)], ["a", "d"])


class BuscaTest(unittest.TestCase):
    """consultar_com_tentativas simulado: falha nos periodos listados."""

    def setUp(self) -> None:
        self.dir = tempfile.TemporaryDirectory()
        self.addCleanup(self.dir.cleanup)
        self.caminho = Path(self.dir.name) / "integrados_anual_2026.json"
        patcher = mock.patch.object(cia, "caminho_arquivo", lambda inicio: self.caminho)
        patcher.start()
        self.addCleanup(patcher.stop)
        self.falhar: set[str] = set()
        self.chamadas: list[str] = []

        def consultar(url, token, config, timeout, tentativas):
            periodo = url.split("data_inicial=")[1].split("&status")[0]
            self.chamadas.append(periodo)
            if periodo in self.falhar:
                raise RuntimeError("HTTP 504")
            return [vinculo("N", periodo), vinculo("G", "outro")]

        patcher = mock.patch.object(cia, "consultar_com_tentativas", consultar)
        patcher.start()
        self.addCleanup(patcher.stop)

    def gravado(self) -> dict:
        return json.loads(self.caminho.read_text(encoding="utf-8"))

    def periodos(self) -> list[tuple[str, str]]:
        return [(b["data_inicial"], b["data_final"]) for b in self.gravado()["blocos"]]

    def test_tudo_certo(self) -> None:
        self.assertEqual(cia.executar(CONFIG, "token"), 0)
        self.assertEqual(self.periodos(), [
            ("2026-03-02", "2026-04-30"), ("2026-05-01", "2026-06-30"), ("2026-07-01", "2026-08-02"),
        ])
        self.assertEqual(self.gravado()["erros"], [])
        self.assertTrue(all(len(b["vinculos"]) == 1 for b in self.gravado()["blocos"]))

    def test_bloco_que_falha_e_tentado_mes_a_mes(self) -> None:
        self.falhar = {"2026-05-01&data_final=2026-06-30"}
        self.assertEqual(cia.executar(CONFIG, "token"), 0)
        self.assertEqual(self.periodos(), [
            ("2026-03-02", "2026-04-30"),
            ("2026-05-01", "2026-05-31"), ("2026-06-01", "2026-06-30"),
            ("2026-07-01", "2026-08-02"),
        ])

    def test_falha_mantem_a_versao_anterior(self) -> None:
        cia.executar(CONFIG, "token")
        anterior = self.gravado()["blocos"][1]

        self.falhar = {
            "2026-05-01&data_final=2026-06-30",
            "2026-06-01&data_final=2026-06-30",
        }
        self.assertEqual(cia.executar(CONFIG, "token"), 1)
        self.assertEqual(self.periodos(), [
            ("2026-03-02", "2026-04-30"), ("2026-05-01", "2026-06-30"), ("2026-07-01", "2026-08-02"),
        ])
        self.assertEqual(self.gravado()["blocos"][1], anterior)
        self.assertEqual(self.gravado()["erros"], ["01/05/2026 a 30/06/2026: falhou (mantida a versao anterior)"])

    def test_falha_sem_versao_anterior_deixa_o_buraco_registrado(self) -> None:
        self.falhar = {
            "2026-05-01&data_final=2026-06-30",
            "2026-06-01&data_final=2026-06-30",
        }
        self.assertEqual(cia.executar(CONFIG, "token"), 1)
        self.assertEqual(self.periodos(), [
            ("2026-03-02", "2026-04-30"), ("2026-05-01", "2026-05-31"), ("2026-07-01", "2026-08-02"),
        ])
        self.assertEqual(self.gravado()["erros"], ["01/06/2026 a 30/06/2026: falhou"])


if __name__ == "__main__":
    unittest.main()
