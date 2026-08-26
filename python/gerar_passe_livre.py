#!/usr/bin/env python3
"""Gera dados de passe livre (frequencia do semestre anterior) — manual.

Usa alunos ATIVO/FORMANDO do semestre atual (resposta_matriculas.json ou BD)
e consulta a frequencia de cada um no intervalo de datas do semestre anterior.
Grava em passe_livre_* (sem entrar em executar_coleta.py).

Sempre consulta a API de alunos (nao usa cache JSON de frequencia).

Uso:
    python3 gerar_passe_livre.py
    python3 gerar_passe_livre.py 2026/1
    python3 gerar_passe_livre.py --concorrencia 8 --timeout 120
    python3 gerar_passe_livre.py --limite 20
"""

from __future__ import annotations

import argparse
import json
import re
import sys
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

from analisar_frequencia import extrair_disciplinas, extrair_frequencia_geral
from api_auth import (
    USER_AGENT,
    carregar_config_api,
    obter_access_token,
    ssl_context,
    url_alunos,
    verificar_ssl,
)
from db import conectar, fechar, row_to_dict
from paths import JSON_RESPOSTA_MATRICULAS
from status_aluno import status_eh_controle

# Semestre passado sobrecarrega a API com concorrencia alta (trava em timeouts).
CONCORRENCIA = 8
TIMEOUT_SEGUNDOS = 120
TENTATIVAS = 2
PAUSA_RETRY_SEG = 2.0
TAMANHO_LOTE = 64
PAUSA_LOTE_ERROS_SEG = 5.0


def semestre_anterior(periodo: str) -> str:
    """Semestre imediatamente anterior. Ex.: 2026/2 -> 2026/1; 2026/1 -> 2025/2."""
    texto = periodo.strip()
    partes = texto.split("/")
    if len(partes) != 2:
        raise ValueError(f"Periodo invalido: {periodo!r} (use AAAA/S).")
    ano = int(partes[0])
    sem = int(partes[1])
    if sem not in (1, 2):
        raise ValueError(f"Semestre invalido em {periodo!r} (use 1 ou 2).")
    if sem == 1:
        return f"{ano - 1}/2"
    return f"{ano}/1"


def validar_periodo(periodo: str) -> str:
    """Valida e normaliza AAAA/S."""
    texto = periodo.strip()
    partes = texto.split("/")
    if len(partes) != 2:
        raise ValueError(f"Periodo invalido: {periodo!r} (use AAAA/S).")
    ano = int(partes[0])
    sem = int(partes[1])
    if sem not in (1, 2):
        raise ValueError(f"Semestre invalido em {periodo!r} (use 1 ou 2).")
    return f"{ano}/{sem}"


def datas_padrao_semestre(periodo: str) -> tuple[str, str]:
    """Datas DD-MM-AAAA padrao do semestre (calendario IFRS aproximado)."""
    partes = periodo.strip().split("/")
    ano = partes[0]
    sem = int(partes[1])
    if sem == 1:
        return f"15-02-{ano}", f"31-07-{ano}"
    return f"01-08-{ano}", f"31-12-{ano}"


def consultar_webservice(
    url: str,
    token: str,
    config: dict[str, str],
    timeout: int = TIMEOUT_SEGUNDOS,
) -> tuple[int, str]:
    """GET autenticado."""
    request = Request(
        url,
        headers={
            "Accept": "application/json",
            "Authorization": f"Bearer {token}",
            "User-Agent": USER_AGENT,
        },
        method="GET",
    )
    with urlopen(request, timeout=timeout, context=ssl_context(config)) as response:
        return response.getcode(), response.read().decode("utf-8")


def montar_url_alunos_periodo(
    base: str,
    login: str,
    data_inicial: str,
    data_final: str,
) -> str:
    """URL de alunos com datas do semestre-alvo (ignora datas do config atual)."""
    url = base
    url = re.sub(r"([?&])frequencia_data_inicial=[^&]*", r"\1", url)
    url = re.sub(r"([?&])frequencia_data_final=[^&]*", r"\1", url)
    url = re.sub(r"\?&+", "?", url)
    url = re.sub(r"&&+", "&", url)
    url = re.sub(r"[?&]$", "", url)
    sep = "&" if "?" in url else "?"
    return (
        f"{url}{sep}frequencia_data_inicial={data_inicial}"
        f"&frequencia_data_final={data_final}"
    ).format(login=login)


def carregar_registros_matriculas(dados: Any) -> list[dict[str, Any]]:
    """Normaliza JSON de matriculados em lista de registros."""
    if isinstance(dados, dict) and isinstance(dados.get("data"), list):
        dados = dados["data"]
    elif isinstance(dados, dict):
        dados = list(dados.values())
    if not isinstance(dados, list):
        raise ValueError("Formato inesperado de matriculados.")
    return [r for r in dados if isinstance(r, dict)]


def logins_unicos(matriculas: list[dict[str, Any]]) -> list[dict[str, str]]:
    """Um registro por login — apenas ATIVO e FORMANDO (como nos alarmes)."""
    por_login: dict[str, dict[str, str]] = {}
    for registro in matriculas:
        status = str(registro.get("status") or registro.get("Status") or "").strip()
        if not status_eh_controle(status):
            continue
        login = str(registro.get("login") or registro.get("Login") or "").strip()
        if login == "":
            continue
        if login in por_login:
            continue
        por_login[login] = {
            "login": login,
            "nome": str(
                registro.get("nome_completo")
                or registro.get("Nome")
                or registro.get("nome")
                or ""
            ).strip(),
            "matricula": str(
                registro.get("matricula") or registro.get("Matricula") or ""
            ).strip(),
        }
    return list(por_login.values())


def carregar_logins_semestre_atual() -> tuple[list[dict[str, str]], str]:
    """Logins ATIVO/FORMANDO do semestre atual (JSON ou ultima coleta no BD)."""
    if JSON_RESPOSTA_MATRICULAS.is_file():
        dados = json.loads(JSON_RESPOSTA_MATRICULAS.read_text(encoding="utf-8"))
        logins = logins_unicos(carregar_registros_matriculas(dados))
        if logins:
            return logins, str(JSON_RESPOSTA_MATRICULAS)

    conn = conectar()
    cursor = conn.cursor()
    cursor.execute(
        """
        SELECT a.login, a.nome, a.matricula
        FROM alunos a
        INNER JOIN frequencia_curso fc ON fc.aluno_id = a.id
        WHERE fc.coleta_id = (SELECT MAX(id) FROM coletas)
        ORDER BY a.login
        """
    )
    por_login: dict[str, dict[str, str]] = {}
    for row in cursor.fetchall():
        item = row_to_dict(row)
        login = str(item.get("login") or "").strip()
        if login == "" or login in por_login:
            continue
        por_login[login] = {
            "login": login,
            "nome": str(item.get("nome") or "").strip(),
            "matricula": str(item.get("matricula") or "").strip(),
        }
    if not por_login:
        raise FileNotFoundError(
            f"Nenhum aluno do semestre atual. Rode a coleta ou verifique "
            f"{JSON_RESPOSTA_MATRICULAS}."
        )
    return list(por_login.values()), "banco (ultima coleta)"


def extrair_registros_passe_livre(aluno: dict[str, Any]) -> list[dict[str, Any]]:
    """Extrai frequencias por curso ATIVO/FORMANDO com dados no periodo."""
    if aluno.get("status") != 200:
        return []

    nome = str(aluno.get("nome", ""))
    login = str(aluno.get("login", ""))
    matricula = aluno.get("matricula", "")
    dados = aluno.get("dados")
    if not isinstance(dados, dict):
        return []

    registros: list[dict[str, Any]] = []
    for perfil in dados.values():
        if not isinstance(perfil, dict):
            continue
        email = perfil.get("email")
        nome_social = str(perfil.get("nome_social") or "").strip()
        nome_civil = str(
            perfil.get("nome_civil")
            or perfil.get("nome_completo")
            or nome
            or ""
        ).strip()

        for curso in perfil.get("cursos", []):
            if not isinstance(curso, dict):
                continue
            status_discente = str(curso.get("status_discente") or "").strip()
            if not status_eh_controle(status_discente):
                continue
            matricula_curso = curso.get("matricula")
            if matricula_curso in (None, ""):
                matricula_curso = matricula

            frequencias = curso.get("frequencias", {})
            if not isinstance(frequencias, dict):
                continue
            frequencia_geral = extrair_frequencia_geral(frequencias)
            disciplinas = extrair_disciplinas(frequencias)
            if frequencia_geral is None and disciplinas == []:
                continue

            registros.append({
                "nome": nome_civil or nome or login,
                "nome_social": nome_social or None,
                "login": login,
                "matricula": matricula_curso,
                "email": email,
                "nome_curso": curso.get("nome_curso") or "Curso nao informado",
                "status_discente": status_discente,
                "frequencia_geral": frequencia_geral,
                "disciplinas": disciplinas,
            })
    return registros


def consultar_um_aluno(
    aluno: dict[str, str],
    *,
    base_url: str,
    token: str,
    config: dict[str, str],
    data_inicial: str,
    data_final: str,
    timeout: int,
    tentativas: int,
) -> dict[str, Any]:
    """Consulta um aluno com retries e pausa entre tentativas."""
    login = aluno["login"]
    url = montar_url_alunos_periodo(base_url, login, data_inicial, data_final)
    ultimo_erro = ""
    for tentativa in range(1, tentativas + 1):
        try:
            status, body = consultar_webservice(url, token, config, timeout=timeout)
            if status == 200:
                return {
                    "login": login,
                    "nome": aluno.get("nome", ""),
                    "matricula": aluno.get("matricula", ""),
                    "status": status,
                    "dados": json.loads(body),
                }
            ultimo_erro = f"HTTP {status}"
            if status < 500:
                break
        except (HTTPError, URLError, TimeoutError, json.JSONDecodeError, OSError) as error:
            ultimo_erro = str(error)
            if isinstance(error, HTTPError) and error.code < 500:
                break
        if tentativa < tentativas:
            time.sleep(PAUSA_RETRY_SEG)
    return {
        "login": login,
        "nome": aluno.get("nome", ""),
        "matricula": aluno.get("matricula", ""),
        "status": 0,
        "erro": ultimo_erro,
    }


def consultar_alunos_periodo(
    logins: list[dict[str, str]],
    *,
    config: dict[str, str],
    token: str,
    data_inicial: str,
    data_final: str,
    concorrencia: int = CONCORRENCIA,
    timeout: int = TIMEOUT_SEGUNDOS,
    tentativas: int = TENTATIVAS,
    ao_lote: Any | None = None,
) -> tuple[list[dict[str, Any]], int]:
    """Consulta frequencia em lotes; opcionalmente grava a cada lote (ao_lote).

    Returns:
        Tupla (respostas brutas da API, total de registros gravados via ao_lote).
    """
    base = url_alunos(config)
    resultados: list[dict[str, Any]] = []
    gravados = 0
    erros = 0
    total = len(logins)
    inicio = time.perf_counter()
    print(
        f"Consultando frequencia de {total} aluno(s) ({data_inicial} a {data_final})..."
    )
    print(
        f"  concorrencia={concorrencia}, timeout={timeout}s, "
        f"tentativas={tentativas}, lote={TAMANHO_LOTE}",
        flush=True,
    )

    for offset in range(0, total, TAMANHO_LOTE):
        lote = logins[offset : offset + TAMANHO_LOTE]
        erros_lote = 0
        respostas_lote: list[dict[str, Any]] = []
        with ThreadPoolExecutor(max_workers=concorrencia) as pool:
            futures = [
                pool.submit(
                    consultar_um_aluno,
                    aluno,
                    base_url=base,
                    token=token,
                    config=config,
                    data_inicial=data_inicial,
                    data_final=data_final,
                    timeout=timeout,
                    tentativas=tentativas,
                )
                for aluno in lote
            ]
            for future in as_completed(futures):
                item = future.result()
                if item.get("status") != 200:
                    erros += 1
                    erros_lote += 1
                respostas_lote.append(item)
                resultados.append(item)
                feitos = len(resultados)
                if feitos % 25 == 0 or feitos == total:
                    decorrido = time.perf_counter() - inicio
                    ritmo = (feitos / decorrido) * 60.0 if decorrido > 0 else 0.0
                    restante = total - feitos
                    eta_min = (restante / ritmo) if ritmo > 0 else 0.0
                    print(
                        f"  {feitos}/{total} (erros: {erros}, "
                        f"{ritmo:.0f}/min, {decorrido / 60:.1f} min"
                        f"{f', ~{eta_min:.0f} min restantes' if feitos < total else ''})",
                        flush=True,
                    )

        if ao_lote is not None:
            registros_lote: list[dict[str, Any]] = []
            for aluno in respostas_lote:
                registros_lote.extend(extrair_registros_passe_livre(aluno))
            n = int(ao_lote(registros_lote))
            gravados += n
            print(f"  Gravados neste lote: {n} (acumulado BD: {gravados})", flush=True)

        if erros_lote > max(3, len(lote) // 4):
            print(
                f"  Lote com muitos erros ({erros_lote}/{len(lote)}); "
                f"pausa {PAUSA_LOTE_ERROS_SEG:.0f}s...",
                flush=True,
            )
            time.sleep(PAUSA_LOTE_ERROS_SEG)

    return resultados, gravados


def upsert_aluno(cursor: Any, registro: dict[str, Any]) -> int:
    """Insere/atualiza aluno e retorna id."""
    login = str(registro.get("login", "")).strip()
    matricula = str(registro.get("matricula", "")).strip()
    nome = str(registro.get("nome", "")).strip() or login
    nome_social = str(registro.get("nome_social") or "").strip() or None
    email = registro.get("email")
    email_str = str(email).strip() if email else None
    cursor.execute(
        """
        INSERT INTO alunos (login, matricula, nome, nome_social, email)
        VALUES (?, ?, ?, ?, ?)
        ON CONFLICT(login, matricula) DO UPDATE SET
            nome = excluded.nome,
            nome_social = excluded.nome_social,
            email = COALESCE(excluded.email, alunos.email)
        """,
        (login, matricula, nome, nome_social, email_str),
    )
    cursor.execute(
        "SELECT id FROM alunos WHERE login = ? AND matricula = ?",
        (login, matricula),
    )
    return int(row_to_dict(cursor.fetchone())["id"])


def upsert_curso(cursor: Any, nome_curso: str) -> int:
    """Insere/reutiliza curso."""
    nome = (nome_curso or "").strip() or "Curso nao informado"
    cursor.execute(
        """
        INSERT INTO cursos (nome_curso)
        VALUES (?)
        ON CONFLICT(nome_curso) DO UPDATE SET nome_curso = excluded.nome_curso
        """,
        (nome,),
    )
    cursor.execute("SELECT id FROM cursos WHERE nome_curso = ?", (nome,))
    return int(row_to_dict(cursor.fetchone())["id"])


def limpar_passe_livre() -> None:
    """Remove dados anteriores de passe livre."""
    conn = conectar()
    cursor = conn.cursor()
    cursor.execute("DELETE FROM passe_livre_disciplina")
    cursor.execute("DELETE FROM passe_livre_aluno_curso")
    conn.commit()


def inserir_registros(
    registros: list[dict[str, Any]],
    *,
    periodo: str,
    data_inicial: str,
    data_final: str,
    gerado_em: str,
) -> int:
    """Insere registros no BD (sem limpar)."""
    if not registros:
        return 0

    conn = conectar()
    cursor = conn.cursor()
    total = 0
    for registro in registros:
        aluno_id = upsert_aluno(cursor, registro)
        curso_id = upsert_curso(cursor, str(registro.get("nome_curso") or ""))
        geral = registro.get("frequencia_geral") or {}
        percentual = geral.get("percentual_frequencia_total")
        cursor.execute(
            """
            INSERT INTO passe_livre_aluno_curso (
                periodo, data_inicial, data_final, gerado_em,
                aluno_id, curso_id, login, matricula,
                nome, nome_social, email, nome_curso, frequencia
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            """,
            (
                periodo,
                data_inicial,
                data_final,
                gerado_em,
                aluno_id,
                curso_id,
                registro["login"],
                str(registro.get("matricula") or ""),
                registro.get("nome") or "",
                registro.get("nome_social"),
                str(registro["email"]).strip() if registro.get("email") else None,
                registro.get("nome_curso") or "",
                float(percentual) if percentual is not None else None,
            ),
        )
        aluno_curso_id = int(cursor.lastrowid)
        total += 1
        for disc in registro.get("disciplinas") or []:
            pct = disc.get("percentual_frequencia")
            cursor.execute(
                """
                INSERT INTO passe_livre_disciplina (
                    aluno_curso_id, codigo_disciplina, disciplina, frequencia
                ) VALUES (?, ?, ?, ?)
                """,
                (
                    aluno_curso_id,
                    str(disc.get("codigo_disciplina") or ""),
                    str(disc.get("disciplina") or ""),
                    float(pct) if pct is not None else None,
                ),
            )

    conn.commit()
    return total


def gravar_banco(
    registros: list[dict[str, Any]],
    *,
    periodo: str,
    data_inicial: str,
    data_final: str,
    gerado_em: str | None = None,
) -> int:
    """Substitui dados de passe livre (so percentual de frequencia)."""
    limpar_passe_livre()
    if gerado_em is None:
        gerado_em = time.strftime("%Y-%m-%d %H:%M:%S")
    return inserir_registros(
        registros,
        periodo=periodo,
        data_inicial=data_inicial,
        data_final=data_final,
        gerado_em=gerado_em,
    )


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    """Argumentos da linha de comando."""
    parser = argparse.ArgumentParser(
        description=(
            "Frequencia do semestre anterior para alunos matriculados "
            "no semestre atual (passe livre)."
        )
    )
    parser.add_argument(
        "periodo",
        nargs="?",
        default=None,
        help=(
            "Semestre da frequencia (AAAA/S). "
            "Padrao: imediatamente anterior ao periodo da API."
        ),
    )
    parser.add_argument("--data-inicial", default=None, help="DD-MM-AAAA")
    parser.add_argument("--data-final", default=None, help="DD-MM-AAAA")
    parser.add_argument(
        "--concorrencia",
        type=int,
        default=CONCORRENCIA,
        help=f"Consultas em paralelo (padrao: {CONCORRENCIA}).",
    )
    parser.add_argument(
        "--timeout",
        type=int,
        default=TIMEOUT_SEGUNDOS,
        help=f"Timeout por tentativa em segundos (padrao: {TIMEOUT_SEGUNDOS}).",
    )
    parser.add_argument(
        "--tentativas",
        type=int,
        default=TENTATIVAS,
        help=f"Tentativas por login (padrao: {TENTATIVAS}).",
    )
    parser.add_argument(
        "--limite",
        type=int,
        default=None,
        help="Limita quantos logins consultar (teste).",
    )
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    """Ponto de entrada."""
    args = parse_args(argv)
    try:
        config = carregar_config_api()
        periodo_ref = (config.get("api_periodo_letivo") or "").strip()
        if not periodo_ref and not args.periodo:
            print(
                "Periodo atual ausente. Configure em /configuracoes/api "
                "ou passe o semestre da frequencia (ex.: 2026/1).",
                file=sys.stderr,
            )
            return 1

        if args.periodo:
            periodo = validar_periodo(args.periodo)
        else:
            periodo = semestre_anterior(validar_periodo(periodo_ref))

        data_ini_padrao, data_fim_padrao = datas_padrao_semestre(periodo)
        data_inicial = (args.data_inicial or data_ini_padrao).strip()
        data_final = (args.data_final or data_fim_padrao).strip()
    except ValueError as error:
        print(f"Erro: {error}", file=sys.stderr)
        return 1

    print("Passe livre — frequencia do semestre anterior")
    print(f"Alunos: ATIVO/FORMANDO do semestre atual ({periodo_ref or 'coleta local'})")
    print(f"Frequencia: semestre {periodo} ({data_inicial} a {data_final})")
    if not verificar_ssl(config):
        print("Aviso: verificacao SSL desativada (api_verify_ssl=false).")
    print()

    try:
        logins, origem = carregar_logins_semestre_atual()
        print(f"Logins ATIVO/FORMANDO: {len(logins)} (fonte: {origem})")
        if args.limite is not None and args.limite > 0:
            logins = logins[: args.limite]
            print(f"Limite aplicado: {len(logins)} login(s)")

        token = obter_access_token(config)
        print("Access token OAuth obtido.")
        concorrencia = max(1, int(args.concorrencia))
        timeout = max(10, int(args.timeout))
        tentativas = max(1, int(args.tentativas))

        gerado_em = time.strftime("%Y-%m-%d %H:%M:%S")
        limpar_passe_livre()
        print("BD limpo; gravando a cada lote (tela atualiza durante a execucao).")

        def ao_lote(registros: list[dict[str, Any]]) -> int:
            return inserir_registros(
                registros,
                periodo=periodo,
                data_inicial=data_inicial,
                data_final=data_final,
                gerado_em=gerado_em,
            )

        _respostas, total = consultar_alunos_periodo(
            logins,
            config=config,
            token=token,
            data_inicial=data_inicial,
            data_final=data_final,
            concorrencia=concorrencia,
            timeout=timeout,
            tentativas=tentativas,
            ao_lote=ao_lote,
        )
    except (
        FileNotFoundError,
        ValueError,
        RuntimeError,
        HTTPError,
        URLError,
        TimeoutError,
        json.JSONDecodeError,
    ) as error:
        print(f"Erro na consulta: {error}", file=sys.stderr)
        fechar()
        return 1

    fechar()
    print(f"\n{total} registro(s) gravado(s).")
    print("Tela: /index.php/passe-livre")
    return 0


if __name__ == "__main__":
    sys.exit(main())
