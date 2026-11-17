#!/usr/bin/env python3
"""Executa o pipeline completo de coleta do MAPA.

Pode ser chamado pelo caminho absoluto, sem cd previo:

    /usr/bin/python3 /var/www/mapa/python/executar_coleta.py

Cron (a cada hora):

    0 * * * * /usr/bin/python3 /var/www/mapa/python/executar_coleta.py > /var/www/mapa/data/coleta.log 2>&1

Cada rodada fica em coleta_execucoes (concluida ou falhou, com o passo que
parou). A coleta criada na rodada nasce em_andamento e so passa a completa
quando todos os passos Python terminam; o portal ignora as que falharam, e os
e-mails (PHP, no fim) ja encontram a coleta completa.
"""

from __future__ import annotations

import os
import sqlite3
import subprocess
import sys
from datetime import datetime
from pathlib import Path
from zoneinfo import ZoneInfo

from db import conectar, fechar
from importar_frequencia import VARIAVEL_PIPELINE

DIR_PYTHON = Path(__file__).resolve().parent

PASSOS = (
    "consulta_alunos_massa.py",
    "consulta_inicial.py",
    "analisar_frequencia.py",
    "importar_frequencia.py",
    "importar_trancados.py",
    "importar_professores.py",
    "importar_grade.py",
    "importar_chamadas.py",
    "gerar_alarmes.py",
)

DIR_RAIZ = DIR_PYTHON.parent
ARQUIVO_LOG = DIR_RAIZ / "data" / "coleta.log"
SCRIPT_EMAILS_CHAMADAS = DIR_RAIZ / "scripts" / "enviar_emails_chamadas.php"
SCRIPT_EMAILS_ALARMES_ALUNOS = DIR_RAIZ / "scripts" / "enviar_emails_alarmes_alunos.php"
SCRIPT_EMAILS_ALARMES_STAFF = DIR_RAIZ / "scripts" / "enviar_emails_alarmes_staff.php"


def limpar_log_coleta() -> None:
    """Zera data/coleta.log para nao acumular execucoes anteriores."""
    try:
        ARQUIVO_LOG.parent.mkdir(parents=True, exist_ok=True)
        ARQUIVO_LOG.write_text("", encoding="utf-8")
    except OSError as exc:
        print(f"Aviso: nao foi possivel limpar {ARQUIVO_LOG}: {exc}", file=sys.stderr, flush=True)


def iniciar_execucao(conn: sqlite3.Connection) -> tuple[int, int]:
    """Abre a rodada em coleta_execucoes; devolve (id da rodada, ultima coleta antes dela)."""
    ultima = conn.execute("SELECT COALESCE(MAX(id), 0) FROM coletas").fetchone()[0]
    cursor = conn.execute("INSERT INTO coleta_execucoes DEFAULT VALUES")
    conn.commit()
    return int(cursor.lastrowid), int(ultima)


def finalizar_execucao(
    conn: sqlite3.Connection,
    execucao_id: int,
    coleta_anterior: int,
    passo_falhou: str | None,
) -> None:
    """Fecha a rodada e marca a coleta criada nela como completa ou falhou."""
    status_coleta = "falhou" if passo_falhou else "completa"
    coleta_id = conn.execute(
        "SELECT MAX(id) FROM coletas WHERE id > ?", (coleta_anterior,)
    ).fetchone()[0]
    conn.execute(
        "UPDATE coletas SET status = ? WHERE id > ? AND status = 'em_andamento'",
        (status_coleta, coleta_anterior),
    )
    conn.execute(
        """
        UPDATE coleta_execucoes
        SET terminada_em = datetime('now', 'localtime'),
            status = ?,
            passo_falhou = ?,
            coleta_id = ?
        WHERE id = ?
        """,
        ("falhou" if passo_falhou else "concluida", passo_falhou, coleta_id, execucao_id),
    )
    conn.commit()


def registrar_fim(execucao_id: int, coleta_anterior: int, passo_falhou: str | None) -> None:
    """finalizar_execucao com uma conexao propria (os passos rodam em outros processos)."""
    try:
        finalizar_execucao(conectar(forcar_novo=True), execucao_id, coleta_anterior, passo_falhou)
    finally:
        fechar()


def main() -> int:
    """Roda cada etapa do pipeline em sequencia."""
    limpar_log_coleta()
    agora = datetime.now(ZoneInfo("America/Sao_Paulo")).strftime("%d/%m/%Y %H:%M:%S")
    print(f"\n----------- Nova Coleta {agora} ------", flush=True)
    print(f"Pipeline MAPA — {DIR_PYTHON}", flush=True)

    try:
        execucao_id, coleta_anterior = iniciar_execucao(conectar(forcar_novo=True))
    finally:
        fechar()
    ambiente = {**os.environ, VARIAVEL_PIPELINE: "1"}

    for nome in PASSOS:
        script = DIR_PYTHON / nome
        if not script.is_file():
            print(f"Script ausente: {script}", file=sys.stderr, flush=True)
            registrar_fim(execucao_id, coleta_anterior, nome)
            return 1

        print(f"\n=== {nome} ===", flush=True)
        resultado = subprocess.run(
            [sys.executable, str(script)],
            cwd=str(DIR_PYTHON),
            env=ambiente,
            check=False,
        )
        if resultado.returncode != 0:
            print(
                f"Falha em {nome} (codigo {resultado.returncode}).",
                file=sys.stderr,
                flush=True,
            )
            registrar_fim(execucao_id, coleta_anterior, nome)
            return resultado.returncode

    registrar_fim(execucao_id, coleta_anterior, None)

    for script in (
        SCRIPT_EMAILS_CHAMADAS,
        SCRIPT_EMAILS_ALARMES_ALUNOS,
        SCRIPT_EMAILS_ALARMES_STAFF,
    ):
        if not script.is_file():
            continue
        print(f"\n=== {script.name} ===", flush=True)
        resultado = subprocess.run(
            ["php", str(script)],
            cwd=str(DIR_RAIZ),
            check=False,
        )
        if resultado.returncode != 0:
            print(
                f"Aviso: {script.name} retornou codigo {resultado.returncode}.",
                file=sys.stderr,
                flush=True,
            )

    print("\nPipeline concluido.", flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
