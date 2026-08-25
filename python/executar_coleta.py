#!/usr/bin/env python3
"""Executa o pipeline completo de coleta do MAPA.

Pode ser chamado pelo caminho absoluto, sem cd previo:

    /usr/bin/python3 /var/www/mapa/python/executar_coleta.py

Cron (a cada hora):

    0 * * * * /usr/bin/python3 /var/www/mapa/python/executar_coleta.py > /var/www/mapa/data/coleta.log 2>&1
"""

from __future__ import annotations

import subprocess
import sys
from datetime import datetime
from pathlib import Path
from zoneinfo import ZoneInfo

DIR_PYTHON = Path(__file__).resolve().parent

PASSOS = (
    "consulta_inicial.py",
    "consulta_alunos.py",
    "analisar_frequencia.py",
    "importar_frequencia.py",
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


def main() -> int:
    """Roda cada etapa do pipeline em sequencia."""
    limpar_log_coleta()
    agora = datetime.now(ZoneInfo("America/Sao_Paulo")).strftime("%d/%m/%Y %H:%M:%S")
    print(f"\n----------- Nova Coleta {agora} ------", flush=True)
    print(f"Pipeline MAPA — {DIR_PYTHON}", flush=True)

    for nome in PASSOS:
        script = DIR_PYTHON / nome
        if not script.is_file():
            print(f"Script ausente: {script}", file=sys.stderr, flush=True)
            return 1

        print(f"\n=== {nome} ===", flush=True)
        resultado = subprocess.run(
            [sys.executable, str(script)],
            cwd=str(DIR_PYTHON),
            check=False,
        )
        if resultado.returncode != 0:
            print(
                f"Falha em {nome} (codigo {resultado.returncode}).",
                file=sys.stderr,
                flush=True,
            )
            return resultado.returncode

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
