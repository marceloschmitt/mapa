"""Consulta do intervalo em massa por status e blocos mensais (sem chamar a API).

Rodar na raiz do projeto: python3 -m unittest discover -v python/tests
"""

from __future__ import annotations

import argparse
import json
import sys
import tempfile
import unittest
from datetime import date
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import consulta_alunos_massa as cam  # noqa: E402

CONFIG = {
    "api_url_alunos_massa_intervalo": (
        "https://api.exemplo/intervalo?data_inicial={data_inicial}&data_final={data_final}"
    ),
}
ARGS = argparse.Namespace(data_inicial="03-08-2026", data_final="30-09-2026", timeout=10)


def disciplina(
    codigo: str,
    horarios: int,
    ausencias: int,
    dias: list[str] | None = None,
    ultima: str | None = None,
    id_componente: int | None = None,
) -> dict:
    return {
        "id_matricula_componente": id_componente or hash(codigo) % 100000,
        "cod_disciplina": codigo,
        "nome": codigo,
        "ultima_aula_ministrada": ultima,
        "ausencias": list(dias or []),
        "frequencia": {
            "horarios": horarios,
            "ausencias": ausencias,
            "presencas": horarios - ausencias,
            "percentual_frequencia": round(100 * (horarios - ausencias) / horarios, 2)
            if horarios
            else None,
        },
    }


def vinculo(
    login: str,
    matricula: str,
    de: str,
    ate: str,
    disciplinas: list[dict],
    status: str = "ATIVO",
    especiais: dict | None = None,
) -> dict:
    horarios = sum(d["frequencia"]["horarios"] for d in disciplinas)
    ausencias = sum(d["frequencia"]["ausencias"] for d in disciplinas)
    registro = {
        "login": login,
        "matricula": matricula,
        "status_discente": status,
        "frequencia_data_inicial": de,
        "frequencia_data_final": ate,
        "disciplinas": disciplinas,
        "total": {
            "horarios_totais": horarios,
            "ausencias_totais": ausencias,
            "presencas_totais": horarios - ausencias,
            "percentual_frequencia_total": round(100 * (horarios - ausencias) / horarios)
            if horarios
            else None,
            "frequencia_com_ausencias_justificadas": {
                "ausencias_justificadas_totais": 0,
            },
        },
    }
    if especiais:
        registro["ausencias_especiais"] = especiais
    return registro


class PeriodoTest(unittest.TestCase):
    def test_blocos_mensais_sem_buracos(self) -> None:
        self.assertEqual(
            cam.dividir_periodo(date(2026, 8, 3), date(2026, 12, 31), meses=1),
            [
                (date(2026, 8, 3), date(2026, 8, 31)),
                (date(2026, 9, 1), date(2026, 9, 30)),
                (date(2026, 10, 1), date(2026, 10, 31)),
                (date(2026, 11, 1), date(2026, 11, 30)),
                (date(2026, 12, 1), date(2026, 12, 31)),
            ],
        )


class FusaoTest(unittest.TestCase):
    def test_soma_contadores_e_recalcula_percentual(self) -> None:
        ago = vinculo(
            "a",
            "m1",
            "2026-08-03",
            "2026-08-31",
            [disciplina("POA-X", 10, 2, dias=["10/08/2026"], ultima="2026-08-20")],
            especiais={"trancamento_cancelamento": [["(POA-Y) - 15/08/2026"]]},
        )
        set_ = vinculo(
            "a",
            "m1",
            "2026-09-01",
            "2026-09-30",
            [disciplina("POA-X", 20, 1, dias=["05/09/2026"], ultima="2026-09-18")],
            especiais={"trancamento_cancelamento": [["(POA-Y) - 15/08/2026"]]},
        )
        fundido = cam.fundir_vinculos([[ago], [set_]], "2026-08-03", "2026-09-30")
        self.assertEqual(len(fundido), 1)
        v = fundido[0]
        self.assertEqual(v["frequencia_data_inicial"], "2026-08-03")
        self.assertEqual(v["frequencia_data_final"], "2026-09-30")
        self.assertEqual(v["total"]["horarios_totais"], 30)
        self.assertEqual(v["total"]["ausencias_totais"], 3)
        self.assertEqual(v["total"]["presencas_totais"], 27)
        self.assertEqual(v["total"]["percentual_frequencia_total"], 90)
        disc = v["disciplinas"][0]
        self.assertEqual(disc["frequencia"]["horarios"], 30)
        self.assertEqual(disc["frequencia"]["ausencias"], 3)
        self.assertEqual(disc["frequencia"]["percentual_frequencia"], 90)
        self.assertEqual(disc["ausencias"], ["10/08/2026", "05/09/2026"])
        self.assertEqual(disc["ultima_aula_ministrada"], "2026-09-18")
        self.assertEqual(
            v["ausencias_especiais"]["trancamento_cancelamento"],
            [["(POA-Y) - 15/08/2026"]],
        )


class IntervaloMensalTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.addCleanup(self.dir.cleanup)
        self.arquivo = Path(self.dir.name) / "resposta_alunos_massa_intervalo.json"
        self.arquivo.write_text('["anterior"]', encoding="utf-8")
        patcher = mock.patch.object(cam, "JSON_RESPOSTA_ALUNOS_MASSA", self.arquivo)
        patcher.start()
        self.addCleanup(patcher.stop)

    def test_consulta_mes_a_mes_por_status_e_funde(self):
        urls = []

        def consultar(url, *_args):
            urls.append(url)
            if "status=FORMANDO" in url:
                return []
            if "data_inicial=2026-08-03" in url:
                return [vinculo("a", "m1", "2026-08-03", "2026-08-31", [
                    disciplina("POA-X", 10, 0, ultima="2026-08-20"),
                ])]
            if "data_inicial=2026-09-01" in url:
                return [vinculo("a", "m1", "2026-09-01", "2026-09-30", [
                    disciplina("POA-X", 10, 2, ultima="2026-09-18"),
                ])]
            raise AssertionError(url)

        with mock.patch.object(cam, "consultar_com_tentativas", side_effect=consultar):
            cam.consultar_intervalo("token", CONFIG, ARGS, 1)

        self.assertEqual(
            urls,
            [
                "https://api.exemplo/intervalo?data_inicial=2026-08-03&data_final=2026-08-31&status=ATIVO",
                "https://api.exemplo/intervalo?data_inicial=2026-09-01&data_final=2026-09-30&status=ATIVO",
                "https://api.exemplo/intervalo?data_inicial=2026-08-03&data_final=2026-08-31&status=FORMANDO",
                "https://api.exemplo/intervalo?data_inicial=2026-09-01&data_final=2026-09-30&status=FORMANDO",
            ],
        )
        gravado = json.loads(self.arquivo.read_text(encoding="utf-8"))
        self.assertEqual(len(gravado), 1)
        self.assertEqual(gravado[0]["login"], "a")
        self.assertEqual(gravado[0]["total"]["horarios_totais"], 20)
        self.assertEqual(gravado[0]["total"]["ausencias_totais"], 2)
        self.assertEqual(gravado[0]["total"]["percentual_frequencia_total"], 90)

    def test_falha_em_um_mes_mantem_o_arquivo_anterior(self):
        def consultar(url, *_args):
            if "2026-09-01" in url and "ATIVO" in url:
                raise RuntimeError("HTTP 504")
            return []

        with mock.patch.object(cam, "consultar_com_tentativas", side_effect=consultar):
            with self.assertRaises(RuntimeError):
                cam.consultar_intervalo("token", CONFIG, ARGS, 1)

        self.assertEqual(self.arquivo.read_text(encoding="utf-8"), '["anterior"]')


if __name__ == "__main__":
    unittest.main()
