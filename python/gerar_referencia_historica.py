#!/usr/bin/env python3
"""Referencia historica: um semestre fechado analisado com a regra de referencia (manual).

Para saber se o MAPA muda alguma coisa, e preciso saber o que acontecia sem
ele: quantos alunos em risco voltavam sozinhos, quantos reprovavam por falta
e quantos nao voltavam no semestre seguinte. Este script reconstroi isso para
um semestre fechado:

1. matriculas do semestre (resposta_matriculas_AAAA_S.json, da coleta) dao as
   disciplinas de cada aluno, as datas de aula (turno_turma) e a situacao
   final (APROVADO, REPROVADO POR FALTAS...);
2. a API de frequencia por intervalo, consultada por status e mes a mes como
   na coleta, da as faltas por dia (cache em data/json/resposta_intervalo_AAAA_S.json);
3. as matriculas do semestre seguinte dizem quem se rematriculou;
4. risco_referencia.py simula os alarmes e classifica o retorno em 2 semanas.

Grava uma linha por aluno/curso em referencia_historica_alunos, sem
identificar o aluno. Para o semestre que acabou de fechar (ja com
acompanhamento gravado), preenche tambem reprovacao por falta e rematricula
em acompanhamento_alunos.

Uso:
    python3 gerar_referencia_historica.py 2026/1
    python3 gerar_referencia_historica.py 2025/2 --sem-api        # so o cache
    python3 gerar_referencia_historica.py 2026/1 --inicio 23-02-2026 --fim 10-07-2026
"""

from __future__ import annotations

import argparse
import json
import re
import sys
import time
from collections import Counter, defaultdict
from datetime import date, datetime, timedelta
from pathlib import Path
from typing import Any
from urllib.parse import quote

from api_auth import carregar_config_api, obter_access_token, verificar_ssl
from ausencias_especiais import mapear_trancamento_cancelamento
from consulta_alunos_massa import (
    MESES_POR_BLOCO,
    TIMEOUT_SEGUNDOS,
    consultar_com_tentativas,
    dividir_periodo,
    fundir_vinculos,
    url_intervalo,
)
from db import conectar, fechar
from paths import DIR_JSON, JSON_RESPOSTA_ALUNOS_MASSA_CADASTRO, JSON_RESPOSTA_MATRICULAS
from periodo_letivo import periodo_para_arquivo, validar_periodo
from risco_referencia import (
    SEMANAS_RETORNO,
    Disciplina,
    presenca,
    primeiro_critico,
    retorno_apos,
    semana_do_semestre,
    simular_alarmes,
)
from turno_turma import extrair_datas_aula

CONCLUIU = {"CONCLUÍDO", "CONCLUIDO", "FORMADO", "DEFENDIDO", "FORMANDO"}
SEM_FREQUENCIA = {"CANCELADO", "EXCLUIDA", "INDEFERIDO"}
SEMANAS_INICIO = 4
TENTATIVAS = 2

_RE_PERIODO_BLOCO = re.compile(r"(\d{2}/\d{2}/\d{4})\s*-\s*(\d{2}/\d{2}/\d{4})")


def data_br(texto: Any) -> date | None:
    """DD/MM/AAAA, DD-MM-AAAA ou AAAA-MM-DD → date."""
    valor = str(texto or "").strip()[:10]
    for formato in ("%d/%m/%Y", "%d-%m-%Y", "%Y-%m-%d"):
        try:
            return datetime.strptime(valor, formato).date()
        except ValueError:
            continue
    return None


def semestre_seguinte(periodo: str) -> str:
    """2025/2 -> 2026/1; 2026/1 -> 2026/2."""
    ano, semestre = validar_periodo(periodo).split("/")
    return f"{int(ano) + 1}/1" if semestre == "2" else f"{ano}/2"


def arquivo_matriculas(periodo: str, periodo_atual: str) -> Path:
    """resposta_matriculas.json para o periodo corrente; senao o cache do periodo."""
    if periodo == periodo_atual:
        return JSON_RESPOSTA_MATRICULAS
    return DIR_JSON / f"resposta_matriculas_{periodo_para_arquivo(periodo)}.json"


def arquivo_intervalo(periodo: str) -> Path:
    return DIR_JSON / f"resposta_intervalo_{periodo_para_arquivo(periodo)}.json"


def carregar_json(caminho: Path) -> Any:
    return json.loads(caminho.read_text(encoding="utf-8"))


def disciplinas_do_periodo(registro: dict[str, Any], periodo: str) -> list[dict[str, Any]]:
    return [
        d for d in registro.get("disciplinas") or []
        if isinstance(d, dict) and d.get("periodo") == periodo and d.get("cod_disciplina")
    ]


def datas_do_semestre(matriculas: dict[str, Any], periodo: str) -> tuple[date, date]:
    """Inicio e fim mais comuns entre as disciplinas do periodo (turno_turma)."""
    inicios: Counter[date] = Counter()
    fins: Counter[date] = Counter()
    for registro in matriculas.values():
        if not isinstance(registro, dict):
            continue
        for disciplina in disciplinas_do_periodo(registro, periodo):
            blocos = _RE_PERIODO_BLOCO.findall(str(disciplina.get("turno_turma") or ""))
            datas = [(data_br(a), data_br(b)) for a, b in blocos]
            datas = [(a, b) for a, b in datas if a and b]
            if datas:
                inicios[min(a for a, _ in datas)] += 1
                fins[max(b for _, b in datas)] += 1
    if not inicios:
        raise ValueError(f"Nenhuma disciplina do periodo {periodo} com turno_turma nas matriculas.")
    return inicios.most_common(1)[0][0], fins.most_common(1)[0][0]


def buscar_intervalo(
    periodo: str,
    inicio: date,
    fim: date,
    status_lista: list[str],
    config: dict[str, str],
    token: str,
    timeout: int,
) -> list[dict[str, Any]]:
    """Frequencia por intervalo de cada status, mes a mes, fundida no semestre (como na coleta)."""
    vinculos: list[dict[str, Any]] = []
    for status in status_lista:
        partes: list[list[dict[str, Any]]] = []
        for bloco_inicio, bloco_fim in dividir_periodo(inicio, fim, meses=MESES_POR_BLOCO):
            url = url_intervalo(config, bloco_inicio.isoformat(), bloco_fim.isoformat())
            url += ("&" if "?" in url else "?") + "status=" + quote(status)
            marca = time.time()
            parte = consultar_com_tentativas(url, token, config, timeout, TENTATIVAS)
            if not isinstance(parte, list):
                raise ValueError(f"Resposta inesperada para {status} {bloco_inicio}..{bloco_fim}.")
            print(f"  {status} {bloco_inicio:%d/%m}–{bloco_fim:%d/%m}: {len(parte)} vinculos em {time.time() - marca:.0f}s")
            partes.append([v for v in parte if isinstance(v, dict)])
        vinculos.extend(fundir_vinculos(partes, inicio.isoformat(), fim.isoformat()))
    return vinculos


def montar_alunos(
    matriculas: dict[str, Any],
    vinculos: list[dict[str, Any]],
    seguinte: dict[str, Any] | None,
    ingressos: dict[str, str],
    periodo: str,
    inicio: date,
    fim: date,
    feriados: set[date],
) -> tuple[list[dict[str, Any]], int]:
    """Alunos/curso com disciplinas semestrais do periodo, com Disciplinas e desfechos.

    Devolve (alunos, quantos ficaram sem faltas na API).
    """
    por_discente: dict[Any, dict[str, Any]] = {v.get("id_discente"): v for v in vinculos}
    matriculados_seguinte = None
    if seguinte is not None:
        matriculados_seguinte = {
            str(r.get("matricula")) for r in seguinte.values() if isinstance(r, dict)
        }

    alunos: list[dict[str, Any]] = []
    sem_faltas = 0
    for registro in matriculas.values():
        if not isinstance(registro, dict):
            continue
        disciplinas = disciplinas_do_periodo(registro, periodo)
        if not disciplinas:
            continue
        vinculo = por_discente.get(registro.get("id_discente"))
        if vinculo is None:
            sem_faltas += 1
            continue

        faltas_api: dict[str, set[date]] = {}
        for d in vinculo.get("disciplinas") or []:
            faltas_api[str(d.get("cod_disciplina"))] = {
                x for x in (data_br(f) for f in d.get("ausencias") or []) if x
            }
        trancadas = {
            codigo: data_br(dia)
            for codigo, dia in mapear_trancamento_cancelamento(vinculo.get("ausencias_especiais")).items()
        }

        lista: list[Disciplina] = []
        for d in disciplinas:
            codigo = str(d["cod_disciplina"])
            situacao = str(d.get("situacao_matricula") or "")
            if situacao in SEM_FREQUENCIA and codigo not in faltas_api:
                continue
            aulas = sorted(
                dia for dia in extrair_datas_aula(d.get("turno_turma"))
                if inicio <= dia <= fim and dia not in feriados
            )
            lista.append(Disciplina(codigo, aulas, faltas_api.get(codigo, set()), trancadas.get(codigo) or fim, situacao))
        if not lista:
            continue

        status = str(registro.get("status") or "")
        matricula = str(registro.get("matricula"))
        alunos.append({
            "login": str(registro.get("login") or ""),
            "nome_curso": str(registro.get("curso") or ""),
            "nivel": str(registro.get("curso_nivel") or "") or None,
            "ingressante": ingressos.get(matricula, "") in {periodo, periodo[:4] + "/0"},
            "disciplinas": lista,
            "concluiu": status in CONCLUIU,
            "rematriculou": (matricula in matriculados_seguinte) if matriculados_seguinte is not None else None,
        })
    return alunos, sem_faltas


def analisar(alunos: list[dict[str, Any]], inicio: date, fim: date) -> list[dict[str, Any]]:
    """Simula a referencia e classifica cada aluno."""
    janela = timedelta(days=7 * SEMANAS_RETORNO)
    linhas: list[dict[str, Any]] = []
    for aluno in alunos:
        disciplinas = aluno["disciplinas"]
        critico = primeiro_critico(simular_alarmes(disciplinas, inicio, fim))
        risco_tipo = risco_semana = retorno = None
        if critico is not None:
            tipo, dia = critico
            risco_tipo, risco_semana = tipo, semana_do_semestre(inicio, dia)
            if dia + janela <= fim:
                retorno = retorno_apos(disciplinas, dia)[2]
        aulas, faltas = presenca(disciplinas, inicio, inicio + timedelta(days=7 * SEMANAS_INICIO - 1))
        situacoes = [d.situacao or "" for d in disciplinas]
        linhas.append({
            **{k: aluno[k] for k in ("login", "nome_curso", "nivel", "ingressante", "concluiu", "rematriculou")},
            "risco_tipo": risco_tipo,
            "risco_semana": risco_semana,
            "retorno": retorno,
            "presenca_inicio": round(100 * (aulas - faltas) / aulas, 1) if aulas >= 3 else None,
            "rep_falta": any("FALTA" in s for s in situacoes),
            "reprovou": any(s.startswith("REPROVADO") for s in situacoes),
        })
    return linhas


def gravar(cursor: Any, periodo: str, com_mapa: bool, linhas: list[dict[str, Any]]) -> None:
    """Regrava as linhas do periodo, sem identificar o aluno."""
    cursos = {str(r[1]): int(r[0]) for r in cursor.execute("SELECT id, nome_curso FROM cursos")}
    cursor.execute("DELETE FROM referencia_historica_alunos WHERE periodo = ?", (periodo,))
    cursor.executemany(
        """
        INSERT INTO referencia_historica_alunos (
            periodo, com_mapa, curso_id, nome_curso, nivel, ingressante, risco_tipo, risco_semana,
            retorno, presenca_inicio, rep_falta, reprovou, rematriculou, concluiu
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        """,
        [
            (
                periodo, int(com_mapa), cursos.get(l["nome_curso"]), l["nome_curso"], l["nivel"],
                int(l["ingressante"]), l["risco_tipo"], l["risco_semana"], l["retorno"], l["presenca_inicio"],
                int(l["rep_falta"]), int(l["reprovou"]),
                int(l["rematriculou"]) if l["rematriculou"] is not None else None, int(l["concluiu"]),
            )
            for l in linhas
        ],
    )


def atualizar_desfecho_acompanhamento(cursor: Any, periodo: str, linhas: list[dict[str, Any]]) -> int:
    """No semestre que fechou, leva reprovacao por falta e rematricula para acompanhamento_alunos."""
    execucao = cursor.execute(
        "SELECT id FROM acompanhamento_execucoes WHERE periodo = ? ORDER BY id DESC LIMIT 1", (periodo,)
    ).fetchone()
    if execucao is None:
        return 0
    alunos = {str(r[1]): int(r[0]) for r in cursor.execute("SELECT id, login FROM alunos")}
    cursos = {str(r[1]): int(r[0]) for r in cursor.execute("SELECT id, nome_curso FROM cursos")}
    total = 0
    for linha in linhas:
        aluno_id, curso_id = alunos.get(linha["login"]), cursos.get(linha["nome_curso"])
        if aluno_id is None or curso_id is None:
            continue
        cursor.execute(
            """
            UPDATE acompanhamento_alunos SET rep_falta = ?, rematriculou = ?
            WHERE execucao_id = ? AND aluno_id = ? AND curso_id = ?
            """,
            (
                int(linha["rep_falta"]),
                int(linha["rematriculou"]) if linha["rematriculou"] is not None else None,
                int(execucao[0]), aluno_id, curso_id,
            ),
        )
        total += int(cursor.rowcount or 0)
    return total


def imprimir_resumo(periodo: str, linhas: list[dict[str, Any]]) -> None:
    total = len(linhas)
    em_risco = [l for l in linhas if l["risco_tipo"]]
    rem = [l for l in linhas if l["rematriculou"] is not None]
    print(f"\n{periodo}: {total} alunos; em risco {len(em_risco)} ({100 * len(em_risco) / max(1, total):.0f}%); "
          f"reprovou por falta {100 * sum(l['rep_falta'] for l in linhas) / max(1, total):.0f}%"
          + (f"; nao voltou nem concluiu {100 * sum(1 for l in rem if not (l['rematriculou'] or l['concluiu'])) / len(rem):.0f}%"
             if rem else "; rematricula ainda sem dados"))
    print("Situacao              Alunos  Medidos  Voltou  Parcial  Nao voltou  Rep. falta")
    for tipo in ("faltas_3semanas", "faltas_4dias", "percentual_baixo"):
        grupo = [l for l in em_risco if l["risco_tipo"] == tipo]
        medidos = Counter(l["retorno"] for l in grupo if l["retorno"])
        print(f"{tipo:<21} {len(grupo):>6}  {sum(medidos.values()):>7}  "
              + "  ".join(f"{medidos[c]:>6}" for c in ("voltou", "parcial", "nao_voltou"))
              + f"  {100 * sum(l['rep_falta'] for l in grupo) / max(1, len(grupo)):>9.0f}%")


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Referencia historica de um semestre fechado.")
    parser.add_argument("periodo", help="AAAA/S")
    parser.add_argument("--inicio", help="DD-MM-AAAA (padrao: das matriculas)")
    parser.add_argument("--fim", help="DD-MM-AAAA (padrao: das matriculas)")
    parser.add_argument("--sem-api", action="store_true", help="Usa so o cache da frequencia.")
    parser.add_argument("--timeout", type=int, default=TIMEOUT_SEGUNDOS)
    args = parser.parse_args(argv)

    inicio_execucao = time.monotonic()
    conn = conectar()
    cursor = conn.cursor()
    try:
        periodo = validar_periodo(args.periodo)
        config = carregar_config_api()
        periodo_atual = (config.get("api_periodo_letivo") or "").strip()

        caminho = arquivo_matriculas(periodo, periodo_atual)
        if not caminho.is_file():
            print(f"Erro: {caminho.name} nao encontrado (a coleta guarda os 2 semestres anteriores).", file=sys.stderr)
            return 1
        matriculas = carregar_json(caminho)
        caminho_seguinte = arquivo_matriculas(semestre_seguinte(periodo), periodo_atual)
        seguinte = carregar_json(caminho_seguinte) if caminho_seguinte.is_file() else None

        inicio, fim = datas_do_semestre(matriculas, periodo)
        if args.inicio:
            inicio = data_br(args.inicio) or inicio
        if args.fim:
            fim = data_br(args.fim) or fim
        print(f"Referencia historica {periodo}: {inicio:%d/%m/%Y} a {fim:%d/%m/%Y}"
              + ("" if seguinte is not None else f" (sem {caminho_seguinte.name}: rematricula fica em aberto)"))

        cache = arquivo_intervalo(periodo)
        if args.sem_api or not cache.is_file():
            if args.sem_api:
                if not cache.is_file():
                    print(f"Erro: cache {cache.name} nao existe; rode sem --sem-api.", file=sys.stderr)
                    return 1
            else:
                if not verificar_ssl(config):
                    print("Aviso: verificacao SSL desativada (api_verify_ssl=false).")
                status_lista = sorted({
                    str(r.get("status") or "") for r in matriculas.values() if isinstance(r, dict)
                } - {""})
                print(f"Buscando faltas por dia na API ({len(status_lista)} status, blocos de {MESES_POR_BLOCO} mes)...")
                vinculos = buscar_intervalo(periodo, inicio, fim, status_lista, config,
                                            obter_access_token(config), args.timeout)
                cache.write_text(json.dumps(vinculos, ensure_ascii=False), encoding="utf-8")
                print(f"Cache salvo em: {cache}")
        vinculos = carregar_json(cache)

        ingressos: dict[str, str] = {}
        if JSON_RESPOSTA_ALUNOS_MASSA_CADASTRO.is_file():
            for aluno in carregar_json(JSON_RESPOSTA_ALUNOS_MASSA_CADASTRO).values():
                for curso in (aluno.get("cursos") or []) if isinstance(aluno, dict) else []:
                    ingressos[str(curso.get("matricula"))] = str(curso.get("ano_semestre_ingresso") or "")
        else:
            print("Aviso: sem cadastro em massa; ingressante fica em branco.")
        feriados = {d for d in (data_br(r[0]) for r in cursor.execute("SELECT data FROM feriados")) if d}

        alunos, sem_faltas = montar_alunos(matriculas, vinculos, seguinte, ingressos, periodo, inicio, fim, feriados)
        if sem_faltas:
            print(f"Alunos sem faltas na API (ignorados): {sem_faltas}")
        linhas = analisar(alunos, inicio, fim)
        com_mapa = cursor.execute(
            "SELECT 1 FROM contatos_aluno WHERE data_contato BETWEEN ? AND ? LIMIT 1",
            (inicio.isoformat(), fim.isoformat()),
        ).fetchone() is not None
        gravar(cursor, periodo, com_mapa, linhas)
        atualizados = atualizar_desfecho_acompanhamento(cursor, periodo, linhas)
        conn.commit()
    except Exception as error:  # noqa: BLE001
        conn.rollback()
        print(f"Erro: {error}", file=sys.stderr)
        return 1
    finally:
        fechar()

    imprimir_resumo(periodo, linhas)
    if atualizados:
        print(f"Desfecho levado ao acompanhamento de {periodo}: {atualizados} alunos.")
    print(f"\nGravado em {time.monotonic() - inicio_execucao:.0f}s ({'com' if com_mapa else 'sem'} MAPA).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
