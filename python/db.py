"""Conexao SQLite compartilhada pelos scripts Python do MAPA.

Le DB_PATH do .env (padrao: data/mapa.db) e aplica config/schema.sql.
"""

from __future__ import annotations

import sqlite3
from pathlib import Path
from typing import Any

from paths import ARQUIVO_ENV, ARQUIVO_SCHEMA, ROOT, garantir_diretorios

_ENV_CACHE: dict[str, str] | None = None
_CONN: sqlite3.Connection | None = None


def carregar_env(caminho: Path | None = None) -> dict[str, str]:
    """Carrega variaveis do arquivo .env."""
    global _ENV_CACHE
    if _ENV_CACHE is not None and caminho is None:
        return _ENV_CACHE

    arquivo = caminho or ARQUIVO_ENV
    vars_env: dict[str, str] = {}

    if arquivo.is_file():
        for linha in arquivo.read_text(encoding="utf-8").splitlines():
            linha = linha.strip()
            if linha == "" or linha.startswith("#") or "=" not in linha:
                continue
            chave, valor = linha.split("=", 1)
            vars_env[chave.strip()] = valor.strip()

    if caminho is None:
        _ENV_CACHE = vars_env

    return vars_env


def env(chave: str, padrao: str = "") -> str:
    """Retorna variavel do .env ou do sistema."""
    import os

    valor = carregar_env().get(chave, "")
    if valor != "":
        return valor

    sistema = os.environ.get(chave, "")
    if sistema != "":
        return sistema

    return padrao


def caminho_banco() -> Path:
    """Resolve o caminho do arquivo SQLite."""
    garantir_diretorios()
    relativo = env("DB_PATH", "data/mapa.db")
    caminho = Path(relativo)
    if not caminho.is_absolute():
        caminho = ROOT / caminho
    caminho.parent.mkdir(parents=True, exist_ok=True)
    return caminho


def conectar(forcar_novo: bool = False) -> sqlite3.Connection:
    """Abre (ou reutiliza) conexao SQLite com row_factory dict-like."""
    global _CONN

    if _CONN is not None and not forcar_novo:
        return _CONN

    _CONN = sqlite3.connect(str(caminho_banco()))
    _CONN.row_factory = sqlite3.Row
    _CONN.execute("PRAGMA foreign_keys = ON")
    garantir_schema(_CONN)
    return _CONN


def _ensure_column(conn: sqlite3.Connection, tabela: str, coluna: str, definicao: str) -> None:
    """Adiciona coluna se ainda nao existir (bancos ja criados)."""
    rows = conn.execute(f"PRAGMA table_info({tabela})").fetchall()
    nomes = {str(r["name"] if isinstance(r, sqlite3.Row) else r[1]) for r in rows}
    if coluna in nomes:
        return
    conn.execute(f"ALTER TABLE {tabela} ADD COLUMN {coluna} {definicao}")


def _migrar_datas_aula_csv(conn: sqlite3.Connection) -> None:
    """Copia datas_aula (CSV legado) para disciplina_aulas, se ainda vazio."""
    cols = {
        str(r["name"] if isinstance(r, sqlite3.Row) else r[1])
        for r in conn.execute("PRAGMA table_info(disciplina_grade)").fetchall()
    }
    if "datas_aula" not in cols:
        return

    total = conn.execute("SELECT COUNT(*) FROM disciplina_aulas").fetchone()[0]
    if int(total) > 0:
        return

    rows = conn.execute(
        """
        SELECT codigo_disciplina, curso_id, datas_aula
        FROM disciplina_grade
        WHERE datas_aula IS NOT NULL AND TRIM(datas_aula) != ''
        """
    ).fetchall()
    if not rows:
        return

    pares: list[tuple[str, int, str]] = []
    for row in rows:
        codigo = str(row["codigo_disciplina"] if isinstance(row, sqlite3.Row) else row[0])
        curso_id = int(row["curso_id"] if isinstance(row, sqlite3.Row) else row[1])
        bruto = str(row["datas_aula"] if isinstance(row, sqlite3.Row) else row[2])
        for parte in bruto.split(","):
            data = parte.strip()
            if len(data) == 10 and data[4] == "-" and data[7] == "-":
                pares.append((codigo, curso_id, data))

    if pares:
        conn.executemany(
            """
            INSERT OR IGNORE INTO disciplina_aulas
                (codigo_disciplina, curso_id, data_aula)
            VALUES (?, ?, ?)
            """,
            pares,
        )


def garantir_schema(conexao: sqlite3.Connection | None = None) -> None:
    """Executa config/schema.sql e migracoes leves de colunas."""
    conn = conexao or conectar()
    if not ARQUIVO_SCHEMA.is_file():
        return

    sql = ARQUIVO_SCHEMA.read_text(encoding="utf-8")
    conn.executescript(sql)
    _ensure_column(conn, "disciplina_grade", "semestre_oferta", "TEXT")
    # Coluna legada: mantida se existir; novas instalações não a criam.
    _ensure_column(conn, "disciplina_grade", "datas_aula", "TEXT NOT NULL DEFAULT ''")
    _ensure_column(conn, "cursos", "curso_nivel", "TEXT")
    _ensure_column(conn, "frequencia_curso", "data_inicio_aulas", "TEXT")
    _migrar_datas_aula_csv(conn)
    conn.commit()


def fechar() -> None:
    """Fecha a conexao global."""
    global _CONN
    if _CONN is not None:
        _CONN.close()
        _CONN = None


def parsear_data_sql(texto: str | None) -> str | None:
    """Converte DD-MM-AAAA / DD/MM/AAAA para AAAA-MM-DD."""
    if texto is None:
        return None

    valor = str(texto).strip()
    if valor == "":
        return None

    for separador in ("-", "/"):
        partes = valor.split(separador)
        if len(partes) == 3 and len(partes[0]) == 2:
            dia, mes, ano = partes
            return f"{ano}-{mes}-{dia}"
        if len(partes) == 3 and len(partes[0]) == 4:
            return f"{partes[0]}-{partes[1]}-{partes[2]}"

    return None


def row_to_dict(row: Any) -> dict[str, Any]:
    """Converte sqlite3.Row em dict."""
    if row is None:
        return {}
    if isinstance(row, dict):
        return row
    return dict(row)
