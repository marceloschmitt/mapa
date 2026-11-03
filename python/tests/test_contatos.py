"""Contatos com o aluno: copia do historico para contatos_aluno e leitura pelo efeito.

Usa SQLite em memoria com config/schema.sql; nao le data/mapa.db nem a API.

    python3 -m unittest discover python/tests
"""

from __future__ import annotations

import sqlite3
import sys
import unittest
from datetime import date
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

import db  # noqa: E402
import gerar_alarmes  # noqa: E402
import gerar_efeito_contatos  # noqa: E402


def banco() -> sqlite3.Connection:
    conn = sqlite3.connect(":memory:")
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA foreign_keys = ON")
    db.garantir_schema(conn)
    return conn


class CopiaDosContatosTest(unittest.TestCase):
    def setUp(self) -> None:
        self.conn = banco()
        cur = self.conn.cursor()
        cur.execute("INSERT INTO coletas (id) VALUES (1), (2)")
        cur.execute("INSERT INTO cursos (id, nome_curso) VALUES (1, 'Curso A')")
        cur.executemany("INSERT INTO alunos (id, login, matricula, nome) VALUES (?, ?, ?, ?)",
                        [(1, "a1", "1", "Um"), (2, "a2", "2", "Dois")])
        cur.execute("INSERT INTO usuarios (id, username, nome, perfil) VALUES (7, 'ana', 'Ana', 'geral')")
        cur.execute(
            "INSERT INTO alarme_emails (id, coleta_id, aluno_id, curso_id, destinatario, alarme_ids, enviado_em) "
            "VALUES (40, 1, 1, 1, 'um@x', '1', '2026-09-10 08:00:00')"
        )
        alarme = (
            "INSERT INTO alarmes (coleta_id, aluno_id, curso_id, codigo_disciplina, tipo, mensagem, "
            "visualizado, visualizado_em, visualizado_por, contato_tipo) VALUES (?, ?, 1, ?, ?, 'm', 1, ?, ?, ?)"
        )
        cur.executemany(alarme, [
            # Aluno 1: o e-mail ja vem de alarme_emails, nao do alarme marcado por ele.
            (1, 1, "X", "faltas_3semanas", "2026-09-10 08:00:01", None, "email_automatico"),
            # Aluno 2: ligacao registrada em dois alarmes e copiada para a coleta 2 = um contato.
            (1, 2, "X", "faltas_3semanas", "2026-09-12 10:00:00", 7, "telefone"),
            (1, 2, "Y", "percentual_baixo", "2026-09-12 10:00:05", 7, "telefone"),
            (2, 2, "X", "faltas_3semanas", "2026-09-12 10:00:00", 7, "telefone"),
            # Aluno 2: alarme tratado antes de existir o tipo de contato.
            (1, 2, "Z", "faltas_3semanas", "2026-09-20 15:00:00", None, None),
        ])
        # O banco ja nasceu migrado (sem dados): refaz a copia com o historico acima.
        cur.execute("DELETE FROM configuracoes WHERE chave = ?", (db.CHAVE_CONTATOS_MIGRADOS,))

    def contatos(self) -> list[tuple]:
        return [tuple(r) for r in self.conn.execute(
            "SELECT aluno_id, data_contato, canal, origem, registrado_por, alarme_email_id "
            "FROM contatos_aluno ORDER BY aluno_id, data_contato"
        )]

    def test_copia_emails_e_alarmes_tratados_uma_vez(self) -> None:
        db._migrar_contatos_aluno(self.conn)
        esperado = [
            (1, "2026-09-10", "email_automatico", "automatico", None, 40),
            (2, "2026-09-12", "telefone", "migrado", 7, None),
            (2, "2026-09-20", "nao_informado", "migrado", None, None),
        ]
        self.assertEqual(self.contatos(), esperado)

        db._migrar_contatos_aluno(self.conn)
        self.assertEqual(self.contatos(), esperado)

    def test_efeito_le_os_contatos_do_semestre(self) -> None:
        db._migrar_contatos_aluno(self.conn)
        contatos = gerar_efeito_contatos.carregar_contatos(self.conn.cursor(), date(2026, 9, 11))
        self.assertEqual(
            {par: dict(dias) for par, dias in contatos.items()},
            {(2, 1): {date(2026, 9, 12): {"telefone"}, date(2026, 9, 20): {"nao_informado"}}},
        )


class EmailDoEpisodioTest(unittest.TestCase):
    """O e-mail enviado acompanha o alarme aberto de coleta em coleta (mesmo episodio)."""

    def setUp(self) -> None:
        self.conn = banco()
        cur = self.conn.cursor()
        cur.execute("INSERT INTO cursos (id, nome_curso) VALUES (1, 'Curso A')")
        cur.executemany("INSERT INTO alunos (id, login, matricula, nome) VALUES (?, ?, ?, ?)",
                        [(1, "a1", "1", "Um"), (2, "a2", "2", "Dois"), (3, "a3", "3", "Tres")])
        cur.executemany("INSERT INTO coletas (id, status) VALUES (?, ?)",
                        [(1, "completa"), (2, "falhou"), (3, "em_andamento")])
        # Coleta 1: aluno 1 ja recebeu e-mail; aluno 2 nao.
        self.alarme(1, 1, "X", "2026-10-01 10:00:00")
        self.alarme(1, 2, "Y", None)

    def alarme(self, coleta: int, aluno: int, codigo: str, enviado: str | None) -> None:
        self.conn.execute(
            "INSERT INTO alarmes (coleta_id, aluno_id, curso_id, codigo_disciplina, tipo, severidade, "
            "mensagem, email_enviado_em) VALUES (?, ?, 1, ?, 'faltas_3semanas', 'critico', 'm', ?)",
            (coleta, aluno, codigo, enviado),
        )

    def enviados(self, coleta: int) -> dict[int, str | None]:
        return {r[0]: r[1] for r in self.conn.execute(
            "SELECT aluno_id, email_enviado_em FROM alarmes WHERE coleta_id = ? ORDER BY aluno_id", (coleta,)
        )}

    def test_alarme_que_continua_herda_da_ultima_coleta_completa(self) -> None:
        # A coleta 2 falhou sem alarmes: a 3 herda direto da 1.
        for aluno, codigo in ((1, "X"), (2, "Y"), (3, "Z")):
            self.alarme(3, aluno, codigo, None)
        gerar_alarmes.herdar_emails_enviados(self.conn.cursor(), 3, {})
        self.assertEqual(self.enviados(3), {1: "2026-10-01 10:00:00", 2: None, 3: None})

    def test_regerar_a_mesma_coleta_nao_perde_o_envio(self) -> None:
        self.alarme(3, 3, "Z", "2026-11-03 20:00:00")
        cur = self.conn.cursor()
        guardados = gerar_alarmes.emails_enviados_abertos(cur, 3)
        gerar_alarmes.limpar_alarmes_coleta(cur, 3)
        self.alarme(3, 3, "Z", None)
        gerar_alarmes.herdar_emails_enviados(cur, 3, guardados)
        self.assertEqual(self.enviados(3), {3: "2026-11-03 20:00:00"})

    def test_alarme_que_sumiu_e_voltou_e_outro_episodio(self) -> None:
        # Coleta 4 (completa) sem o alarme do aluno 1; na 5 ele volta: novo e-mail.
        self.conn.execute("UPDATE coletas SET status = 'completa' WHERE id = 3")
        self.conn.execute("INSERT INTO coletas (id, status) VALUES (4, 'completa'), (5, 'em_andamento')")
        self.alarme(4, 2, "Y", None)
        self.alarme(5, 1, "X", None)
        gerar_alarmes.herdar_emails_enviados(self.conn.cursor(), 5, {})
        self.assertEqual(self.enviados(5), {1: None})


if __name__ == "__main__":
    unittest.main()
