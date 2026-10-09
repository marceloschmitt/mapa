"""Consulta do intervalo em massa dividida por status (sem chamar a API).

Rodar na raiz do projeto: python3 -m unittest discover -v python/tests
"""

from __future__ import annotations

import argparse
import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import consulta_alunos_massa as cam  # noqa: E402

CONFIG = {
    "api_url_alunos_massa_intervalo": (
        "https://api.exemplo/intervalo?data_inicial={data_inicial}&data_final={data_final}"
    ),
}
ARGS = argparse.Namespace(data_inicial="03-08-2026", data_final="31-12-2026", timeout=10)


class IntervaloPorStatusTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.addCleanup(self.dir.cleanup)
        self.arquivo = Path(self.dir.name) / "resposta_alunos_massa_intervalo.json"
        self.arquivo.write_text('["anterior"]', encoding="utf-8")
        patcher = mock.patch.object(cam, "JSON_RESPOSTA_ALUNOS_MASSA", self.arquivo)
        patcher.start()
        self.addCleanup(patcher.stop)

    def test_junta_ativo_e_formando_do_periodo_inteiro(self):
        respostas = {
            "ATIVO": [{"login": "a", "status_discente": "ATIVO"}],
            "FORMANDO": [{"login": "f", "status_discente": "FORMANDO"}],
        }
        urls = []

        def consultar(url, *_args):
            urls.append(url)
            return respostas[url.rsplit("status=", 1)[1]]

        with mock.patch.object(cam, "consultar_com_tentativas", side_effect=consultar):
            cam.consultar_intervalo("token", CONFIG, ARGS, 1)

        prefixo = "https://api.exemplo/intervalo?data_inicial=2026-08-03&data_final=2026-12-31&status="
        self.assertEqual(urls, [prefixo + "ATIVO", prefixo + "FORMANDO"])
        gravado = json.loads(self.arquivo.read_text(encoding="utf-8"))
        self.assertEqual([v["login"] for v in gravado], ["a", "f"])

    def test_falha_em_um_status_mantem_o_arquivo_anterior(self):
        def consultar(url, *_args):
            if url.endswith("FORMANDO"):
                raise RuntimeError("HTTP 504")
            return [{"login": "a"}]

        with mock.patch.object(cam, "consultar_com_tentativas", side_effect=consultar):
            with self.assertRaises(RuntimeError):
                cam.consultar_intervalo("token", CONFIG, ARGS, 1)

        self.assertEqual(self.arquivo.read_text(encoding="utf-8"), '["anterior"]')


if __name__ == "__main__":
    unittest.main()
