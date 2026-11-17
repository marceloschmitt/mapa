"""Rodadas do pipeline e status da coleta (completa, falhou, em andamento).

Usa SQLite em memoria com config/schema.sql; nao le data/mapa.db nem a API.

    python3 -m unittest discover python/tests
"""

from __future__ import annotations

import os
import sqlite3
import sys
import unittest
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import executar_coleta  # noqa: E402
import gerar_alarmes  # noqa: E402
import importar_frequencia  # noqa: E402
from db import garantir_schema  # noqa: E402


def banco() -> sqlite3.Connection:
    conn = sqlite3.connect(":memory:")
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA foreign_keys = ON")
    garantir_schema(conn)
    return conn


def status(conn: sqlite3.Connection, tabela: str, id_: int) -> tuple:
    colunas = "status" if tabela == "coletas" else "status, passo_falhou, coleta_id"
    return tuple(conn.execute(f"SELECT {colunas} FROM {tabela} WHERE id = ?", (id_,)).fetchone())


class RodadaDoPipelineTest(unittest.TestCase):
    def setUp(self) -> None:
        self.conn = banco()
        self.conn.execute("INSERT INTO coletas (id) VALUES (1)")

    def test_rodada_completa_marca_a_coleta_criada(self) -> None:
        execucao, anterior = executar_coleta.iniciar_execucao(self.conn)
        self.assertEqual(anterior, 1)
        self.assertEqual(status(self.conn, "coleta_execucoes", execucao), ("em_andamento", None, None))
        self.conn.execute("INSERT INTO coletas (id, status) VALUES (2, 'em_andamento')")

        executar_coleta.finalizar_execucao(self.conn, execucao, anterior, None)

        self.assertEqual(status(self.conn, "coletas", 2), ("completa",))
        self.assertEqual(status(self.conn, "coleta_execucoes", execucao), ("concluida", None, 2))
        self.assertEqual(status(self.conn, "coletas", 1), (None,))

    def test_falha_depois_de_criar_a_coleta(self) -> None:
        execucao, anterior = executar_coleta.iniciar_execucao(self.conn)
        self.conn.execute("INSERT INTO coletas (id, status) VALUES (2, 'em_andamento')")

        executar_coleta.finalizar_execucao(self.conn, execucao, anterior, "gerar_alarmes.py")

        self.assertEqual(status(self.conn, "coletas", 2), ("falhou",))
        self.assertEqual(status(self.conn, "coleta_execucoes", execucao), ("falhou", "gerar_alarmes.py", 2))

    def test_falha_antes_de_criar_a_coleta(self) -> None:
        execucao, anterior = executar_coleta.iniciar_execucao(self.conn)

        executar_coleta.finalizar_execucao(self.conn, execucao, anterior, "consulta_alunos_massa.py")

        self.assertEqual(
            status(self.conn, "coleta_execucoes", execucao),
            ("falhou", "consulta_alunos_massa.py", None),
        )


class ColetaAnteriorTest(unittest.TestCase):
    def test_pula_coletas_que_falharam_ou_ficaram_pela_metade(self) -> None:
        conn = banco()
        conn.executemany(
            "INSERT INTO coletas (id, status) VALUES (?, ?)",
            [(1, None), (2, "completa"), (3, "falhou"), (4, "em_andamento")],
        )
        cursor = conn.cursor()
        self.assertEqual(gerar_alarmes.coleta_anterior_id(cursor, 5), 2)
        self.assertEqual(gerar_alarmes.coleta_anterior_id(cursor, 2), 1)
        self.assertIsNone(gerar_alarmes.coleta_anterior_id(cursor, 1))


class CriarColetaTest(unittest.TestCase):
    def criar(self, ambiente: dict[str, str]) -> str | None:
        conn = banco()
        with mock.patch.dict(os.environ, ambiente, clear=False), \
                mock.patch.object(importar_frequencia, "frequencia_data_inicial", return_value="03-08-2026"), \
                mock.patch.object(importar_frequencia, "frequencia_data_final", return_value="31-12-2026"), \
                mock.patch.object(importar_frequencia, "data_referencia") as referencia:
            referencia.return_value.isoformat.return_value = "2026-11-01"
            coleta_id = importar_frequencia.criar_coleta(conn.cursor(), 10)
        return conn.execute("SELECT status FROM coletas WHERE id = ?", (coleta_id,)).fetchone()[0]

    def test_no_pipeline_nasce_em_andamento(self) -> None:
        self.assertEqual(self.criar({importar_frequencia.VARIAVEL_PIPELINE: "1"}), "em_andamento")

    def test_rodado_sozinho_fica_sem_status(self) -> None:
        with mock.patch.dict(os.environ, {}, clear=False):
            os.environ.pop(importar_frequencia.VARIAVEL_PIPELINE, None)
            self.assertIsNone(self.criar({}))


if __name__ == "__main__":
    unittest.main()
