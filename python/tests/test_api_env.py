"""Configuracao da API: o .env (CHAVES_ENV) vale mais que a tabela configuracoes.

Usa SQLite em memoria; nao le data/mapa.db, o .env real nem a API.

    python3 -m unittest discover python/tests
"""

from __future__ import annotations

import sqlite3
import sys
import unittest
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import api_auth  # noqa: E402
import db  # noqa: E402


def banco() -> sqlite3.Connection:
    conn = sqlite3.connect(":memory:")
    conn.row_factory = sqlite3.Row
    db.garantir_schema(conn)
    for chave, valor in (
        ("api_client_id", "id-da-tela"),
        ("api_client_secret", "secret-da-tela"),
        ("api_url_alunos", "https://tela/{login}"),
        ("api_periodo_letivo", "2026/2"),
    ):
        conn.execute(
            "INSERT INTO configuracoes (chave, valor) VALUES (?, ?) "
            "ON CONFLICT(chave) DO UPDATE SET valor = excluded.valor",
            (chave, valor),
        )
    return conn


class ConfigApiEnvTest(unittest.TestCase):
    def carregar(self, env_arquivo: dict[str, str], sistema: dict[str, str] | None = None) -> dict[str, str]:
        conn = banco()
        with mock.patch.object(db, "_ENV_CACHE", env_arquivo), \
                mock.patch.dict("os.environ", sistema or {}, clear=False), \
                mock.patch.object(api_auth, "conectar", return_value=conn):
            return api_auth.carregar_config_api()

    def test_sem_env_vale_a_tela(self) -> None:
        config = self.carregar({})
        self.assertEqual(config["api_client_id"], "id-da-tela")
        self.assertEqual(config["api_client_secret"], "secret-da-tela")

    def test_env_vale_mais_que_a_tela(self) -> None:
        config = self.carregar({"API_CLIENT_ID": "id-do-env", "API_CLIENT_SECRET": "secret-do-env"})
        self.assertEqual(config["api_client_id"], "id-do-env")
        self.assertEqual(config["api_client_secret"], "secret-do-env")
        self.assertEqual(config["api_url_alunos"], "https://tela/{login}")

    def test_variavel_do_sistema_tambem_vale(self) -> None:
        config = self.carregar({}, {"API_VERIFY_SSL": "true"})
        self.assertTrue(api_auth.verificar_ssl(config))

    def test_vazio_no_env_nao_apaga_a_tela(self) -> None:
        config = self.carregar({"API_CLIENT_ID": "  "})
        self.assertEqual(config["api_client_id"], "id-da-tela")

    def test_periodo_nao_vem_do_env(self) -> None:
        config = self.carregar({"API_PERIODO_LETIVO": "2030/1"})
        self.assertEqual(config["api_periodo_letivo"], "2026/2")


if __name__ == "__main__":
    unittest.main()
