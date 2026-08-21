"""Caminhos padronizados do projeto MAPA.

Scripts Python ficam em python/.
Caches JSON da coleta ficam em data/json/.
"""

from __future__ import annotations

from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DIR_PYTHON = ROOT / "python"
DIR_CONFIG = ROOT / "config"
DIR_DATA = ROOT / "data"
DIR_JSON = DIR_DATA / "json"
DIR_DOCS = ROOT / "docs"

ARQUIVO_ENV = ROOT / ".env"
ARQUIVO_SCHEMA = DIR_CONFIG / "schema.sql"
ARQUIVO_CONSULTAS = DIR_CONFIG / "consultas.json"
ARQUIVO_USERS_CSV = DIR_DATA / "Users.csv"

# Cache da coleta SIGAA (unico JSON intermediario alem da tabela analisada)
JSON_RESPOSTA_MATRICULAS = DIR_JSON / "resposta_matriculas.json"
JSON_RESPOSTA_ALUNOS = DIR_JSON / "resposta_alunos.json"
JSON_ERROS_ALUNOS = DIR_JSON / "erros_alunos.json"
JSON_TABELA_FREQUENCIA = DIR_JSON / "tabela_frequencia.json"


def garantir_diretorios() -> None:
    """Cria data/json se nao existir."""
    DIR_JSON.mkdir(parents=True, exist_ok=True)
