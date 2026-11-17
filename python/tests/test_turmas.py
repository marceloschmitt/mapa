"""Separacao por turma na coleta: identificacao, chamadas, alarmes e efeito.

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

import gerar_alarmes  # noqa: E402
import gerar_efeito_contatos  # noqa: E402
import importar_chamadas  # noqa: E402
from analisar_frequencia import extrair_disciplinas, indexar_turmas  # noqa: E402
from db import garantir_schema, nome_curso_valido  # noqa: E402
from importar_grade import extrair_grades, extrair_turmas, upsert_curso, upsert_grade  # noqa: E402
from importar_professores import extrair_turmas_docentes  # noqa: E402

CPF_ANA = "11111111111"
CPF_BRUNO = "22222222222"

MATRICULAS = {
    "aluno1": {
        "id_discente": "101",
        "disciplinas": [
            {
                "cod_disciplina": "POA-X01",
                "disciplina": "Disciplina X",
                "id_turma": 1,
                "turma": "Turma 01",
                "turno_turma": None,
                "docentes": [{"cpf_docente": CPF_ANA, "docente": "Ana", "tipo_docente": "Docente"}],
            }
        ],
    },
    "aluno2": {
        "id_discente": "102",
        "disciplinas": [
            {
                "cod_disciplina": "POA-X01",
                "disciplina": "Disciplina X",
                "id_turma": "2",
                "turma": "Turma 02",
                "turno_turma": None,
                "docentes": [{"cpf_docente": CPF_BRUNO, "docente": "Bruno", "tipo_docente": "Docente"}],
            },
            {"cod_disciplina": "POA-Y01", "disciplina": "Disciplina Y", "id_turma": None},
        ],
    },
}


def banco() -> sqlite3.Connection:
    conn = sqlite3.connect(":memory:")
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA foreign_keys = ON")
    garantir_schema(conn)
    return conn


class IdentificacaoTurmaTest(unittest.TestCase):
    def test_indexa_por_discente_e_codigo(self) -> None:
        self.assertEqual(indexar_turmas(MATRICULAS), {("101", "POA-X01"): 1, ("102", "POA-X01"): 2})

    def test_formato_lista_da_api_fica_sem_turma(self) -> None:
        self.assertEqual(indexar_turmas({"data": []}), {})
        self.assertEqual(indexar_turmas(None), {})

    def test_disciplina_recebe_turma_do_aluno(self) -> None:
        vinculo = {
            "id_discente": "102",
            "disciplinas": [
                {"cod_disciplina": "POA-X01", "nome": "Disciplina X", "frequencia": {}},
                {"cod_disciplina": "POA-Y01", "nome": "Disciplina Y", "frequencia": {}},
            ],
        }
        linhas = {d["codigo_disciplina"]: d for d in extrair_disciplinas(vinculo, indexar_turmas(MATRICULAS))}
        self.assertEqual(linhas["POA-X01"]["id_turma"], 2)
        self.assertIsNone(linhas["POA-Y01"]["id_turma"])


class GradeEProfessoresTest(unittest.TestCase):
    def test_turmas_da_mesma_disciplina_ficam_separadas(self) -> None:
        turmas = extrair_turmas(MATRICULAS)
        self.assertEqual(sorted(turmas), [1, 2])
        self.assertEqual(turmas[1]["nome_turma"], "Turma 01")
        self.assertEqual(turmas[2]["codigo_disciplina"], "POA-X01")

    def test_cada_turma_tem_seus_professores(self) -> None:
        turmas = extrair_turmas_docentes(MATRICULAS)
        self.assertEqual(list(turmas[1]["docentes"]), [CPF_ANA])
        self.assertEqual(list(turmas[2]["docentes"]), [CPF_BRUNO])


class ChamadasTurmaTest(unittest.TestCase):
    TURMAS = {("101", "POA-X01"): 1, ("102", "POA-X01"): 1, ("103", "POA-X01"): 2}

    @staticmethod
    def vinculo(id_discente: str, curso: str, data: str | None) -> dict:
        return {
            "id_discente": id_discente,
            "curso": curso,
            "disciplinas": [{"cod_disciplina": "POA-X01", "nome": "Disciplina X", "ultima_aula_ministrada": data}],
        }

    def test_chamada_da_turma_e_a_maior_entre_os_alunos(self) -> None:
        vinculos = [
            self.vinculo("101", "Curso A", "2026-09-10"),
            self.vinculo("102", "Curso B", "2026-09-17"),
            self.vinculo("103", "Curso A", None),
        ]
        agregado = importar_chamadas.extrair_ultimas_aulas_turma(vinculos, self.TURMAS)
        self.assertEqual(agregado[1]["data_ultima_aula"], "2026-09-17")
        self.assertEqual(agregado[1]["cursos"], {"Curso A", "Curso B"})
        self.assertIsNone(agregado[2]["data_ultima_aula"])

    def test_turma_unica_herda_historico_da_disciplina(self) -> None:
        conn = banco()
        cur = conn.cursor()
        cur.execute("INSERT INTO coletas (id) VALUES (1), (2)")
        curso_a = importar_chamadas.upsert_curso(cur, "Curso A")
        curso_b = importar_chamadas.upsert_curso(cur, "Curso B")
        cur.executemany(
            "INSERT INTO disciplina_chamadas (codigo_disciplina, disciplina, curso_id, data_chamada, coleta_id) "
            "VALUES (?, 'Disciplina X', ?, ?, 1)",
            [("POA-X01", curso_a, "2026-09-01"), ("POA-X01", curso_b, "2026-09-02"), ("POA-X01", curso_b, "2026-09-03")],
        )
        # Curso A: so a turma 1. Curso B: turmas 1 e 2 (historico misturado, nao herda).
        agregado = {
            1: {"codigo_disciplina": "POA-X01", "disciplina": "Disciplina X",
                "cursos": {"Curso A", "Curso B"}, "data_ultima_aula": "2026-09-10"},
            2: {"codigo_disciplina": "POA-X01", "disciplina": "Disciplina X",
                "cursos": {"Curso B"}, "data_ultima_aula": None},
        }
        resumo = importar_chamadas.importar_turmas(cur, agregado, 2)

        datas = [r[0] for r in cur.execute(
            "SELECT data_chamada FROM turma_chamadas WHERE id_turma = 1 ORDER BY data_chamada"
        )]
        self.assertEqual(datas, ["2026-09-01", "2026-09-10"])
        self.assertEqual(cur.execute("SELECT COUNT(*) FROM turma_chamadas WHERE id_turma = 2").fetchone()[0], 0)
        self.assertEqual(resumo["turmas_datas_herdadas"], 1)
        snapshot = cur.execute(
            "SELECT id_turma, curso_id, data_ultima_aula FROM turma_ultima_aula WHERE coleta_id = 2 "
            "ORDER BY id_turma, curso_id"
        ).fetchall()
        self.assertEqual(
            [tuple(r) for r in snapshot],
            [(1, curso_a, "2026-09-10"), (1, curso_b, "2026-09-10"), (2, curso_b, None)],
        )

        # Segunda coleta: turma ja tem historico, nao herda de novo.
        cur.execute("INSERT INTO disciplina_chamadas (codigo_disciplina, disciplina, curso_id, data_chamada, coleta_id) "
                    "VALUES ('POA-X01', 'Disciplina X', ?, '2026-08-20', 1)", (curso_a,))
        resumo = importar_chamadas.importar_turmas(cur, agregado, 2)
        self.assertEqual(resumo["turmas_datas_herdadas"], 0)


class AlarmeTresSemanasTest(unittest.TestCase):
    """Turma 1 tem aula na segunda, turma 2 na quarta; a grade da disciplina une as duas."""

    SEGUNDAS = ["2026-09-07", "2026-09-14", "2026-09-21"]
    QUARTAS = ["2026-09-09", "2026-09-16", "2026-09-23"]
    CONFIG = {
        "faltas_semanas_ativo": True,
        "faltas_semanas_total": 3,
        "faltas_semanas_janela_dias": 7,
        "faltas_semanas_severidade": "critico",
        "faltas_semanas_mensagem": "{semanas} semanas",
    }

    def setUp(self) -> None:
        self.conn = banco()
        cur = self.conn.cursor()
        cur.execute("INSERT INTO coletas (id) VALUES (1)")
        cur.execute("INSERT INTO cursos (id, nome_curso) VALUES (1, 'Curso A')")
        cur.executemany("INSERT INTO alunos (id, login, matricula, nome) VALUES (?, ?, ?, ?)",
                        [(1, "a1", "1", "Com turma"), (2, "a2", "2", "Sem turma")])
        cur.executemany("INSERT INTO turmas (id_turma, codigo_disciplina, disciplina) VALUES (?, 'POA-X01', 'X')",
                        [(1,), (2,)])
        cur.executemany("INSERT INTO turma_aulas (id_turma, data_aula) VALUES (?, ?)",
                        [(1, d) for d in self.SEGUNDAS] + [(2, d) for d in self.QUARTAS])
        cur.executemany("INSERT INTO disciplina_aulas (codigo_disciplina, curso_id, data_aula) VALUES ('POA-X01', 1, ?)",
                        [(d,) for d in self.SEGUNDAS + self.QUARTAS])
        cur.executemany(
            "INSERT INTO frequencia_disciplina (coleta_id, aluno_id, curso_id, codigo_disciplina, disciplina, id_turma) "
            "VALUES (1, ?, 1, 'POA-X01', 'X', ?)",
            [(1, 1), (2, None)],
        )
        # Os dois faltaram todas as segundas e foram a todas as quartas.
        cur.executemany(
            "INSERT INTO faltas_dia (coleta_id, aluno_id, curso_id, codigo_disciplina, data_falta) "
            "VALUES (1, ?, 1, 'POA-X01', ?)",
            [(aluno, d) for aluno in (1, 2) for d in self.SEGUNDAS],
        )

    def test_usa_aulas_da_turma_e_grava_a_turma(self) -> None:
        cur = self.conn.cursor()
        total = gerar_alarmes.gerar_faltas_3semanas(cur, 1, date(2026, 9, 25), self.CONFIG)
        self.assertEqual(total, 1)
        alarmes = cur.execute("SELECT aluno_id, id_turma, tipo FROM alarmes").fetchall()
        # Sem turma vale a grade da disciplina: as quartas tiveram presenca.
        self.assertEqual([tuple(r) for r in alarmes], [(1, 1, "faltas_3semanas")])


class EfeitoContatosTest(unittest.TestCase):
    """Turma 1 so tem chamada ate 21/09; a disciplina (outra turma) ate 28/09."""

    def setUp(self) -> None:
        self.conn = banco()
        cur = self.conn.cursor()
        cur.execute("INSERT INTO coletas (id) VALUES (1)")
        cur.execute("INSERT INTO cursos (id, nome_curso) VALUES (1, 'Curso A')")
        cur.executemany("INSERT INTO alunos (id, login, matricula, nome) VALUES (?, ?, ?, ?)",
                        [(1, "a1", "1", "Com turma"), (2, "a2", "2", "Sem turma")])
        cur.execute("INSERT INTO turmas (id_turma, codigo_disciplina, disciplina) VALUES (1, 'POA-X01', 'X')")
        segundas = ["2026-09-07", "2026-09-14", "2026-09-21", "2026-09-28"]
        quartas = ["2026-09-02", "2026-09-09", "2026-09-16", "2026-09-23"]
        cur.executemany("INSERT INTO turma_aulas (id_turma, data_aula) VALUES (1, ?)", [(d,) for d in segundas])
        cur.executemany("INSERT INTO disciplina_aulas (codigo_disciplina, curso_id, data_aula) VALUES ('POA-X01', 1, ?)",
                        [(d,) for d in segundas + quartas])
        cur.execute("INSERT INTO turma_ultima_aula (coleta_id, id_turma, curso_id, data_ultima_aula) "
                    "VALUES (1, 1, 1, '2026-09-21')")
        cur.execute("INSERT INTO disciplina_ultima_aula (coleta_id, codigo_disciplina, disciplina, curso_id, "
                    "data_ultima_aula) VALUES (1, 'POA-X01', 'X', 1, '2026-09-28')")
        for aluno, turma in ((1, 1), (2, None)):
            cur.execute("INSERT INTO frequencia_curso (coleta_id, aluno_id, curso_id) VALUES (1, ?, 1)", (aluno,))
            cur.execute(
                "INSERT INTO frequencia_disciplina (coleta_id, aluno_id, curso_id, codigo_disciplina, disciplina, "
                "id_turma) VALUES (1, ?, 1, 'POA-X01', 'X', ?)",
                (aluno, turma),
            )
            cur.execute("INSERT INTO faltas_dia (coleta_id, aluno_id, curso_id, codigo_disciplina, data_falta) "
                        "VALUES (1, ?, 1, 'POA-X01', '2026-09-21')", (aluno,))

    def test_grade_sem_curso_nao_cria_placeholder(self) -> None:
        self.assertIsNone(nome_curso_valido(""))
        self.assertIsNone(nome_curso_valido("Curso nao informado"))
        self.assertIsNone(upsert_curso(self.conn.cursor(), ""))

        grades = extrair_grades(
            {
                "especial": {
                    "curso": "",
                    "disciplinas": [
                        {
                            "cod_disciplina": "POA-SEM01",
                            "disciplina": "Sem Curso",
                            "turno_turma": None,
                        }
                    ],
                }
            }
        )
        self.assertIn(("POA-SEM01", ""), grades)
        item = grades[("POA-SEM01", "")]
        upsert_grade(self.conn.cursor(), None, item)
        self.conn.commit()

        row = self.conn.execute(
            "SELECT curso_id, disciplina FROM disciplina_grade WHERE codigo_disciplina = 'POA-SEM01'"
        ).fetchone()
        self.assertIsNotNone(row)
        self.assertIsNone(row["curso_id"])
        self.assertEqual(row["disciplina"], "Sem Curso")
        placeholder = self.conn.execute(
            "SELECT COUNT(*) AS n FROM cursos WHERE LOWER(nome_curso) = LOWER('Curso nao informado')"
        ).fetchone()["n"]
        self.assertEqual(int(placeholder), 0)

    def test_aulas_param_na_ultima_chamada_da_turma(self) -> None:
        contato = {date(2026, 9, 15): {"email"}}
        alarme = gerar_efeito_contatos.AlarmeCritico(date(2026, 9, 14), "percentual_baixo")
        series = gerar_efeito_contatos.montar_series(
            {(1, 1): contato, (2, 1): contato}, {(1, 1): alarme, (2, 1): alarme}, date(2026, 9, 30)
        )
        series = [s for s in series if s["grupo"] == "contatado"]
        semestre = {"primeira_id": 1, "ultima_id": 1, "inicio": date(2026, 9, 1), "cortes": {1: date(2026, 9, 30)}}
        gerar_efeito_contatos.calcular_semanas(self.conn.cursor(), series, semestre)
        por_aluno = {s["par"][0]: s["contagem"] for s in series}

        # Com turma: so segundas, e depois do contato so ate 21/09.
        self.assertEqual(por_aluno[1], {-2: [1, 0], -1: [1, 0], 1: [1, 1]})
        # Sem turma: grade e ultima chamada da disciplina.
        self.assertEqual(por_aluno[2], {-2: [2, 0], -1: [2, 0], 1: [2, 1], 2: [2, 0]})


class AlarmesCriticosTest(unittest.TestCase):
    def test_primeiro_alarme_critico_real(self) -> None:
        conn = banco()
        cur = conn.cursor()
        cur.executemany(
            "INSERT INTO coletas (id, executada_em, status) VALUES (?, ?, ?)",
            [(1, "2026-09-01 08:00:00", "completa"), (2, "2026-09-08 08:00:00", "falhou"),
             (3, "2026-09-15 08:00:00", "completa")],
        )
        cur.execute("INSERT INTO cursos (id, nome_curso) VALUES (1, 'Curso A')")
        cur.executemany("INSERT INTO alunos (id, login, matricula, nome) VALUES (?, ?, ?, ?)",
                        [(1, "a1", "1", "A"), (2, "a2", "2", "B"), (3, "a3", "3", "C")])
        cur.executemany(
            "INSERT INTO alarmes (coleta_id, aluno_id, curso_id, tipo, severidade, mensagem) VALUES (?, ?, 1, ?, ?, '')",
            [
                (1, 1, "percentual_baixo", "critico"),  # mesmo dia: vale dias seguidos de falta
                (1, 1, "faltas_4dias", "critico"),
                (3, 1, "faltas_3semanas", "critico"),
                (2, 2, "faltas_4dias", "critico"),      # coleta que falhou: fica de fora
                (3, 2, "percentual_baixo", "critico"),
                (1, 3, "percentual_baixo", "alto"),     # nao critico: fora
            ],
        )
        alarmes = gerar_efeito_contatos.carregar_alarmes_criticos(cur, 1)
        critico = gerar_efeito_contatos.AlarmeCritico
        self.assertEqual(alarmes, {
            (1, 1): critico(date(2026, 9, 1), "faltas_4dias"),
            (2, 1): critico(date(2026, 9, 15), "percentual_baixo"),
        })


class SeriesEfeitoTest(unittest.TestCase):
    CORTE = date(2026, 9, 30)

    def test_contato_da_equipe_e_comparacao(self) -> None:
        critico = gerar_efeito_contatos.AlarmeCritico
        d = lambda dia: date(2026, 9, dia)  # noqa: E731
        contatos = {
            (1, 1): {d(1): {"email_automatico"}, d(3): {"telefone"}, d(15): {"whatsapp"}},
            (2, 1): {d(1): {"email_automatico"}},                   # so o e-mail automatico
            (3, 1): {d(1): {"email_automatico"}, d(10): {"whatsapp"}},
            (5, 1): {d(5): {"telefone"}},                           # sem alarme critico: fora
            (6, 1): {d(18): {"presencial"}},                        # contato da equipe antes do alarme
        }
        alarmes = {
            (1, 1): critico(d(1), "faltas_4dias"),
            (2, 1): critico(d(1), "faltas_3semanas"),
            (3, 1): critico(d(1), "percentual_baixo"),
            (4, 1): critico(d(28), "percentual_baixo"),             # alarme + 3 dias passa do corte
            (6, 1): critico(d(20), "percentual_baixo"),
        }
        self.assertEqual(gerar_efeito_contatos.intervalo_equipe(contatos, alarmes), 6)   # mediana de 2 e 9
        self.assertEqual(gerar_efeito_contatos.intervalo_segundo_contato(contatos, alarmes), 12)

        perfis = {(1, 1): {"ingressante": True, "auxilio": True}}
        falou = {(1, 1): {d(3): 1}}
        series = gerar_efeito_contatos.montar_series(contatos, alarmes, self.CORTE, perfis, 10, 3, falou)
        chaves = {(s["par"][0], s["grupo"], s["referencia"]): s for s in series}

        self.assertEqual(sorted(chaves), [
            (1, "comparacao", "segundo"), (1, "contatado", "primeiro"), (1, "contatado", "segundo"),
            (2, "comparacao", "primeiro"),
            (3, "comparacao", "primeiro"), (3, "comparacao", "segundo"), (3, "contatado", "primeiro"),
            (6, "comparacao", "segundo"), (6, "contatado", "primeiro"),
        ])
        # Contato da equipe: o e-mail automatico nao conta, nem como canal.
        um = chaves[(1, "contatado", "primeiro")]
        self.assertEqual(
            (um["data"], um["canais"], um["total"], um["dias_ate_contato"], um["falou"], um["ingressante"]),
            (d(3), "telefone", 2, 2, 1, True),
        )
        self.assertEqual(chaves[(1, "contatado", "segundo")]["data"], d(15))
        # Comparacao: sem contato da equipe 3 dias depois do alarme; para antes do contato que vier.
        self.assertEqual(chaves[(2, "comparacao", "primeiro")]["data"], d(4))
        tres = chaves[(3, "comparacao", "primeiro")]
        self.assertEqual((tres["data"], [n for n, _, _ in tres["semanas"] if n > 0]), (d(4), []))
        self.assertIsNone(tres["dias_ate_contato"])
        # Comparacao do 2o: um contato da equipe so, 10 dias depois dele; para antes do 2o (15/09).
        self.assertEqual(chaves[(1, "comparacao", "segundo")]["data"], d(13))
        self.assertEqual(chaves[(3, "comparacao", "segundo")]["data"], d(20))
        self.assertEqual(chaves[(6, "contatado", "primeiro")]["dias_ate_contato"], -2)

    def test_semanas_em_torno_da_referencia(self) -> None:
        semanas = gerar_efeito_contatos.semanas_da_serie(date(2026, 9, 15), self.CORTE)
        por_numero = {s: (inicio, fim) for s, inicio, fim in semanas}
        self.assertEqual(por_numero[-1], ("2026-09-08", "2026-09-14"))
        self.assertEqual(por_numero[1], ("2026-09-16", "2026-09-22"))
        # O dia da referencia nao entra; depois dela vai ate a semana que comeca no corte.
        self.assertEqual([s for s, _, _ in semanas if s > 0], [1, 2, 3])
        self.assertEqual(min(por_numero), -gerar_efeito_contatos.SEMANAS_ANTES)


if __name__ == "__main__":
    unittest.main()
