#!/usr/bin/env python3
"""Gera dados de passe livre (frequencia do semestre anterior) — manual.

Usa alunos ATIVO/FORMANDO do semestre atual (resposta_matriculas.json ou BD)
e consulta a frequencia mensal de cada um com frequencia_periodo do semestre
anterior. Grava em passe_livre_* (sem entrar em executar_coleta.py).

Sempre consulta a API de alunos (nao usa cache JSON de frequencia).

Uso:
    python3 gerar_passe_livre.py
    python3 gerar_passe_livre.py 2026/1
    python3 gerar_passe_livre.py --concorrencia 50 --timeout 120
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

from api_auth import (
    USER_AGENT,
    carregar_config_api,
    obter_access_token,
    ssl_context,
    url_alunos,
    verificar_ssl,
)
from consulta_alunos import eh_erro_http_temporario, eh_erro_temporario
from db import conectar, fechar, row_to_dict
from paths import JSON_RESPOSTA_MATRICULAS
from status_aluno import status_eh_controle

# Mesmos defaults de consulta_alunos.py (modo mensal e mais leve que intervalo).
CONCORRENCIA = 50
TIMEOUT_SEGUNDOS = 120
TENTATIVAS = 3
FREQUENCIA_GLOBAL = "FREQUÊNCIA GLOBAL"


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


def ultimos_semestres_anteriores(periodo_atual: str, quantidade: int = 3) -> list[str]:
    """Lista os N semestres imediatamente anteriores ao periodo atual."""
    if quantidade < 1:
        raise ValueError("quantidade deve ser >= 1.")
    atual = validar_periodo(periodo_atual)
    saida: list[str] = []
    cursor = atual
    for _ in range(quantidade):
        cursor = semestre_anterior(cursor)
        saida.append(cursor)
    return saida


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


def limpar_url_params(url: str) -> str:
    """Remove '&' / '?' sobrando apos editar query string."""
    url = re.sub(r"\?&+", "?", url)
    url = re.sub(r"&&+", "&", url)
    url = re.sub(r"[?&]$", "", url)
    return url


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


def montar_url_alunos_mensal(base: str, login: str, periodo: str) -> str:
    """URL de alunos em modo mensal com frequencia_periodo (sem datas)."""
    url = base
    if re.search(r"[?&]tipo_frequencia=", url):
        url = re.sub(r"([?&])tipo_frequencia=[^&]*", r"\1tipo_frequencia=mensal", url)
    else:
        sep = "&" if "?" in url else "?"
        url = f"{url}{sep}tipo_frequencia=mensal"

    for param in (
        "frequencia_data_inicial",
        "frequencia_data_final",
        "frequencia_periodo",
    ):
        url = re.sub(rf"([?&]){param}=[^&]*", r"\1", url)
    url = limpar_url_params(url)
    sep = "&" if "?" in url else "?"
    return f"{url}{sep}frequencia_periodo={periodo}".format(login=login)


def parse_nome_disciplina_mensal(chave: str) -> tuple[str, str]:
    """Separa 'NOME (CODIGO)' em (codigo, nome)."""
    texto = str(chave or "").strip()
    match = re.match(r"^(.*?)\s*\(([^)]+)\)\s*$", texto)
    if match:
        nome = match.group(1).strip()
        codigo = match.group(2).strip()
        return codigo, nome or texto
    return "", texto


def extrair_frequencia_geral_mensal(frequencias: dict[str, Any]) -> dict[str, Any] | None:
    """Percentual do curso a partir de FREQUÊNCIA GLOBAL (modo mensal)."""
    disciplinas = frequencias.get("disciplinas")
    if not isinstance(disciplinas, dict):
        return None
    global_ = disciplinas.get(FREQUENCIA_GLOBAL)
    if not isinstance(global_, dict):
        return None
    total = global_.get("total")
    if not isinstance(total, dict):
        return None
    pct = total.get("percentual_frequencia")
    if pct is None:
        return None
    return {"percentual_frequencia_total": pct}


def extrair_disciplinas_mensal(frequencias: dict[str, Any]) -> list[dict[str, Any]]:
    """Disciplinas do modo mensal (ignora FREQUÊNCIA GLOBAL)."""
    disciplinas = frequencias.get("disciplinas")
    if not isinstance(disciplinas, dict):
        return []

    saida: list[dict[str, Any]] = []
    for chave, dados in disciplinas.items():
        if str(chave).strip().upper() == FREQUENCIA_GLOBAL.upper():
            continue
        if not isinstance(dados, dict):
            continue
        total = dados.get("total")
        if not isinstance(total, dict):
            continue
        pct = total.get("percentual_frequencia")
        if pct is None and not dados.get("possui_controle_frequencia"):
            continue
        codigo, nome = parse_nome_disciplina_mensal(str(chave))
        saida.append({
            "codigo_disciplina": codigo,
            "disciplina": nome,
            "percentual_frequencia": pct,
        })
    return saida


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
            frequencia_geral = extrair_frequencia_geral_mensal(frequencias)
            disciplinas = extrair_disciplinas_mensal(frequencias)
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
    periodo: str,
    timeout: int,
    tentativas: int,
) -> dict[str, Any]:
    """Consulta um aluno (modo mensal) com retries como consulta_alunos.py."""
    login = aluno["login"]
    url = montar_url_alunos_mensal(base_url, login, periodo)
    ultimo_erro = ""
    for tentativa in range(1, tentativas + 1):
        try:
            status, body = consultar_webservice(url, token, config, timeout=timeout)
            if status == 200:
                try:
                    dados = json.loads(body)
                except json.JSONDecodeError as error:
                    ultimo_erro = str(error)
                    break
                return {
                    "login": login,
                    "nome": aluno.get("nome", ""),
                    "matricula": aluno.get("matricula", ""),
                    "status": status,
                    "dados": dados,
                    "tentativas": tentativa,
                }
            ultimo_erro = f"HTTP {status}"
            if tentativa < tentativas and eh_erro_http_temporario(status):
                continue
            break
        except HTTPError as error:
            ultimo_erro = error.read().decode("utf-8", errors="replace")
            if tentativa < tentativas and eh_erro_http_temporario(error.code):
                continue
            break
        except URLError as error:
            ultimo_erro = f"Falha de conexao: {error.reason}"
            if tentativa < tentativas and eh_erro_temporario(ultimo_erro):
                continue
            break
        except TimeoutError:
            ultimo_erro = f"Tempo limite excedido ({timeout}s)."
            if tentativa < tentativas:
                continue
            break
        except OSError as error:
            ultimo_erro = str(error)
            if tentativa < tentativas and eh_erro_temporario(ultimo_erro):
                continue
            break
    return {
        "login": login,
        "nome": aluno.get("nome", ""),
        "matricula": aluno.get("matricula", ""),
        "status": 0,
        "erro": ultimo_erro,
        "tentativas": tentativas,
    }


def consultar_alunos_periodo(
    logins: list[dict[str, str]],
    *,
    config: dict[str, str],
    token: str,
    periodo: str,
    concorrencia: int = CONCORRENCIA,
    timeout: int = TIMEOUT_SEGUNDOS,
    tentativas: int = TENTATIVAS,
    ao_aluno: Any | None = None,
) -> int:
    """Consulta frequencia mensal em paralelo; grava no BD a cada aluno (ao_aluno).

    Returns:
        Total de registros aluno/curso gravados via ao_aluno.
    """
    base = url_alunos(config)
    gravados = 0
    erros = 0
    feitos = 0
    total = len(logins)
    inicio = time.perf_counter()
    print(
        f"Consultando frequencia mensal de {total} aluno(s) "
        f"(frequencia_periodo={periodo})..."
    )
    print(
        f"  concorrencia={concorrencia}, timeout={timeout}s, "
        f"tentativas={tentativas}",
        flush=True,
    )

    with ThreadPoolExecutor(max_workers=concorrencia) as pool:
        futures = {
            pool.submit(
                consultar_um_aluno,
                aluno,
                base_url=base,
                token=token,
                config=config,
                periodo=periodo,
                timeout=timeout,
                tentativas=tentativas,
            ): aluno
            for aluno in logins
        }
        for future in as_completed(futures):
            item = future.result()
            feitos += 1
            if item.get("status") != 200:
                erros += 1
                print(
                    f"  ERRO login {item.get('login')} -> "
                    f"{item.get('erro') or item.get('status')}",
                    flush=True,
                )
            elif ao_aluno is not None:
                registros = extrair_registros_passe_livre(item)
                if registros:
                    gravados += int(ao_aluno(registros))

            if feitos % 50 == 0 or feitos == total:
                decorrido = time.perf_counter() - inicio
                ritmo = (feitos / decorrido) * 60.0 if decorrido > 0 else 0.0
                restante = total - feitos
                eta_min = (restante / ritmo) if ritmo > 0 else 0.0
                print(
                    f"  {feitos}/{total} (erros: {erros}, gravados: {gravados}, "
                    f"{ritmo:.0f}/min, {decorrido / 60:.1f} min"
                    f"{f', ~{eta_min:.0f} min restantes' if feitos < total else ''})",
                    flush=True,
                )

    return gravados


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
    parser.add_argument(
        "--semestres",
        type=int,
        default=3,
        help="Quantidade de semestres anteriores a gerar (padrao: 3).",
    )
    parser.add_argument("--data-inicial", default=None, help="DD-MM-AAAA (meta na tela)")
    parser.add_argument("--data-final", default=None, help="DD-MM-AAAA (meta na tela)")
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
            periodos = [validar_periodo(args.periodo)]
        elif periodo_ref:
            periodos = ultimos_semestres_anteriores(
                validar_periodo(periodo_ref),
                max(1, int(args.semestres)),
            )
        else:
            print(
                "Periodo atual ausente. Configure em /configuracoes/api "
                "ou passe o semestre da frequencia (ex.: 2026/1).",
                file=sys.stderr,
            )
            return 1
    except ValueError as error:
        print(f"Erro: {error}", file=sys.stderr)
        return 1

    print("Passe livre — frequencia mensal (semestres anteriores)")
    print(f"Alunos: ATIVO/FORMANDO do semestre atual ({periodo_ref or 'coleta local'})")
    print(f"Semestres: {', '.join(periodos)}")
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
        print("BD limpo; gravando a cada aluno (tela atualiza durante a execucao).")

        total_geral = 0
        for periodo in periodos:
            data_ini_padrao, data_fim_padrao = datas_padrao_semestre(periodo)
            data_inicial = (args.data_inicial or data_ini_padrao).strip()
            data_final = (args.data_final or data_fim_padrao).strip()
            print()
            print(f"--- {periodo} (tipo_frequencia=mensal) ---")
            print(f"Meta (tela): {data_inicial} a {data_final}")

            def ao_aluno(
                registros: list[dict[str, Any]],
                *,
                _periodo: str = periodo,
                _data_inicial: str = data_inicial,
                _data_final: str = data_final,
            ) -> int:
                return inserir_registros(
                    registros,
                    periodo=_periodo,
                    data_inicial=_data_inicial,
                    data_final=_data_final,
                    gerado_em=gerado_em,
                )

            total_geral += consultar_alunos_periodo(
                logins,
                config=config,
                token=token,
                periodo=periodo,
                concorrencia=concorrencia,
                timeout=timeout,
                tentativas=tentativas,
                ao_aluno=ao_aluno,
            )
        total = total_geral
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
