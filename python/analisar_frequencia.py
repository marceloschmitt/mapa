#!/usr/bin/env python3
"""Gera tabela_frequencia.json a partir da consulta em massa.

Le os arquivos gravados por consulta_alunos_massa.py:

- resposta_alunos_massa_intervalo.json: um registro por vinculo (aluno x curso)
  com status, totais, disciplinas e ausencias_especiais;
- resposta_alunos_massa_cadastro.json: nome civil, nome social, e-mail e
  turma de entrada, que o intervalo nao traz;
- resposta_matriculas.json (opcional): turma (id_turma) do aluno em cada
  disciplina, casada por id_discente + codigo da disciplina;
- integrados_anual_AAAA.json (opcional): meses encerrados do ano letivo dos
  integrados. Cada integrado ganha "frequencia_anual" (intervalo atual somado a
  esses meses), usada por importar_frequencia.py nos percentuais. Os demais
  campos continuam sendo so do intervalo da coleta.

Frequencia/controle: apenas vinculos ATIVO ou FORMANDO; demais status
(inclusive trancados) sao ignorados. Vinculos sem curso (alunos especiais)
tambem ficam de fora. Os trancados sao importados por importar_trancados.py.
"""

from __future__ import annotations

import json
import math
import sys
from datetime import date, datetime, timedelta
from pathlib import Path
from typing import Any

from paths import (
    JSON_RESPOSTA_ALUNOS_MASSA,
    JSON_RESPOSTA_ALUNOS_MASSA_CADASTRO,
    JSON_RESPOSTA_MATRICULAS,
    JSON_TABELA_FREQUENCIA,
    garantir_diretorios,
)
import integrados_anual
from status_aluno import status_eh_controle
from ausencias_especiais import (
    aplicar_situacao_trancamento,
    mapear_trancamento_cancelamento,
)

ARQUIVO_INTERVALO = JSON_RESPOSTA_ALUNOS_MASSA
ARQUIVO_CADASTRO = JSON_RESPOSTA_ALUNOS_MASSA_CADASTRO
ARQUIVO_SAIDA_JSON = JSON_TABELA_FREQUENCIA

ChaveTurma = tuple[str, str]


def parsear_data_br(texto: str) -> date | None:
    """Converte DD/MM/AAAA ou DD-MM-AAAA em date."""
    valor = str(texto or "").strip()
    if valor == "":
        return None
    for fmt in ("%d/%m/%Y", "%d-%m-%Y", "%Y-%m-%d"):
        try:
            return datetime.strptime(valor, fmt).date()
        except ValueError:
            continue
    return None


def data_br(texto: Any) -> str | None:
    """AAAA-MM-DD (endpoint de intervalo) → DD-MM-AAAA (formato da tabela)."""
    data = parsear_data_br(str(texto or ""))
    return data.strftime("%d-%m-%Y") if data else None


def fim_matricula_atrasada(ausencias_especiais: Any) -> date | None:
    """Ultimo dia do intervalo matricula_atrasada em ausencias_especiais, se houver."""
    if not isinstance(ausencias_especiais, dict):
        return None
    atrasada = ausencias_especiais.get("matricula_atrasada")
    if not isinstance(atrasada, list) or not atrasada:
        return None

    fim: date | None = None
    for item in atrasada:
        textos: list[str] = []
        if isinstance(item, str):
            textos = [item]
        elif isinstance(item, list):
            textos = [str(x) for x in item if x]
        else:
            continue
        for texto in textos:
            # Ex.: "03/08/2026 a 23/08/2026"
            partes = [p.strip() for p in str(texto).replace(" até ", " a ").split(" a ")]
            if len(partes) >= 2:
                candidato = parsear_data_br(partes[-1])
            else:
                candidato = parsear_data_br(partes[0]) if partes else None
            if candidato is not None and (fim is None or candidato > fim):
                fim = candidato
    return fim


def data_inicio_contagem_aluno(ausencias_especiais: Any) -> str | None:
    """Dia a partir do qual as aulas passam a contar para o aluno (AAAA-MM-DD).

    Matricula atrasada: dia seguinte ao fim do intervalo da API.
    Aluno normal: None (usa o primeiro dia de aula da disciplina na grade).
    """
    fim_atraso = fim_matricula_atrasada(ausencias_especiais)
    if fim_atraso is not None:
        return (fim_atraso + timedelta(days=1)).isoformat()
    return None


def carregar_json(caminho: Path, tipo: type) -> Any:
    """Carrega um JSON e confere o tipo da raiz (list ou dict)."""
    dados = json.loads(caminho.read_text(encoding="utf-8"))
    if not isinstance(dados, tipo):
        raise ValueError(f"{caminho.name}: formato inesperado (esperava {tipo.__name__}).")
    return dados


def percentual_inteiro(valor: Any) -> Any:
    """Arredonda para inteiro (meio para cima), como a consulta individual devolvia."""
    if isinstance(valor, float):
        return int(math.floor(valor + 0.5))
    return valor


def extrair_dias_falta(disciplina: dict[str, Any]) -> list[str]:
    """Datas em que o aluno faltou na disciplina (ex.: 25/04/2025)."""
    dias = disciplina.get("ausencias", [])
    if not isinstance(dias, list):
        return []
    return [str(dia) for dia in dias if dia]


def extrair_frequencia_geral(vinculo: dict[str, Any]) -> dict[str, Any] | None:
    """Totais de frequencia do curso, ou None se o vinculo nao tiver totais."""
    total = vinculo.get("total")
    if not isinstance(total, dict):
        return None

    justificadas = total.get("frequencia_com_ausencias_justificadas", {})
    if not isinstance(justificadas, dict):
        justificadas = {}

    return {
        "data_inicial": data_br(vinculo.get("frequencia_data_inicial")),
        "data_final": data_br(vinculo.get("frequencia_data_final")),
        "carga_horaria_total": total.get("carga_horaria_total"),
        "horarios_totais": total.get("horarios_totais"),
        "ausencias_totais": total.get("ausencias_totais"),
        "presencas_totais": total.get("presencas_totais"),
        "percentual_frequencia_total": percentual_inteiro(total.get("percentual_frequencia_total")),
        "ausencias_justificadas_totais": justificadas.get("ausencias_justificadas_totais"),
        "percentual_com_ausencias_justificadas": justificadas.get(
            "percentual_frequencia_total"
        ),
    }


def indexar_turmas(matriculas: Any) -> dict[ChaveTurma, int]:
    """id_turma por (id_discente, codigo da disciplina), a partir de resposta_matriculas.json."""
    if not isinstance(matriculas, dict) or isinstance(matriculas.get("data"), list):
        return {}

    turmas: dict[ChaveTurma, int] = {}
    for registro in matriculas.values():
        if not isinstance(registro, dict):
            continue
        id_discente = str(registro.get("id_discente") or "").strip()
        if id_discente == "":
            continue
        for item in registro.get("disciplinas") or []:
            if not isinstance(item, dict):
                continue
            codigo = str(item.get("cod_disciplina") or "").strip()
            try:
                id_turma = int(item.get("id_turma"))
            except (TypeError, ValueError):
                continue
            if codigo:
                turmas[(id_discente, codigo)] = id_turma
    return turmas


def extrair_disciplinas(
    vinculo: dict[str, Any],
    turmas: dict[ChaveTurma, int] | None = None,
) -> list[dict[str, Any]]:
    """Frequencia por disciplina do vinculo.

    Disciplinas em ausencias_especiais.trancamento_cancelamento ficam com
    situacao Trancada e percentual_frequencia None.
    """
    disciplinas = vinculo.get("disciplinas") or []
    if not isinstance(disciplinas, list):
        disciplinas = []
    turmas = turmas or {}
    id_discente = str(vinculo.get("id_discente") or "").strip()

    linhas: list[dict[str, Any]] = []
    for disciplina in disciplinas:
        if not isinstance(disciplina, dict):
            continue

        freq = disciplina.get("frequencia", {})
        if not isinstance(freq, dict):
            freq = {}

        codigo = str(disciplina.get("cod_disciplina") or "").strip()
        linhas.append({
            "codigo_disciplina": disciplina.get("cod_disciplina", ""),
            "id_turma": turmas.get((id_discente, codigo)),
            "disciplina": disciplina.get("nome", ""),
            "horarios": freq.get("horarios", 0),
            "ausencias": freq.get("ausencias", 0),
            "presencas": freq.get("presencas", 0),
            "percentual_frequencia": freq.get("percentual_frequencia"),
            "dias_falta": extrair_dias_falta(disciplina),
            "situacao": None,
            "data_trancamento": None,
        })

    linhas = aplicar_situacao_trancamento(
        linhas,
        mapear_trancamento_cancelamento(vinculo.get("ausencias_especiais")),
    )
    linhas.sort(
        key=lambda linha: (
            str(linha.get("disciplina") or ""),
            str(linha.get("codigo_disciplina") or ""),
        )
    )
    return linhas


def indexar_cursos(cadastro: dict[str, Any]) -> dict[tuple[str, str], dict[str, Any]]:
    """Cursos do cadastro por (login, matricula)."""
    cursos: dict[tuple[str, str], dict[str, Any]] = {}
    for chave, aluno in cadastro.items():
        if not isinstance(aluno, dict):
            continue
        login = str(aluno.get("login") or chave).strip()
        for curso in aluno.get("cursos") or []:
            if isinstance(curso, dict):
                cursos[(login, str(curso.get("matricula") or "").strip())] = curso
    return cursos


def _matricula(valor: Any) -> Any:
    try:
        return int(valor)
    except (TypeError, ValueError):
        return valor


def montar_resultado(
    vinculos: list[dict[str, Any]],
    cadastro: dict[str, Any],
    turmas: dict[ChaveTurma, int] | None = None,
    inicio_anual: date | None = None,
    anteriores: dict[integrados_anual.ChaveVinculo, list[dict[str, Any]]] | None = None,
) -> list[dict[str, Any]]:
    """Tabela de frequencia (um registro por vinculo ATIVO/FORMANDO com curso).

    Integrados com meses encerrados em `anteriores` recebem "frequencia_anual".
    """
    cursos = indexar_cursos(cadastro)
    anteriores = anteriores or {}
    resultado: list[dict[str, Any]] = []

    for vinculo in vinculos:
        if not isinstance(vinculo, dict):
            continue
        status_discente = str(vinculo.get("status_discente") or "").strip()
        if not status_eh_controle(status_discente):
            continue

        login = str(vinculo.get("login") or "").strip()
        matricula = str(vinculo.get("matricula") or "").strip()
        aluno = cadastro.get(login) or {}
        curso = cursos.get((login, matricula), {})
        nome_curso = curso.get("nome_curso") or vinculo.get("curso")
        if not nome_curso:
            continue

        frequencia_geral = extrair_frequencia_geral(vinculo)
        disciplinas = extrair_disciplinas(vinculo, turmas)
        if frequencia_geral is None and disciplinas == []:
            continue

        resultado.append({
            "nome": str(
                aluno.get("nome_civil")
                or aluno.get("nome_completo")
                or vinculo.get("nome_completo")
                or login
            ).strip(),
            "nome_social": str(aluno.get("nome_social") or "").strip(),
            "login": login,
            "matricula": _matricula(curso.get("matricula") or matricula),
            "email": aluno.get("email"),
            "nome_curso": nome_curso,
            "ano_semestre_ingresso": str(
                curso.get("ano_semestre_ingresso")
                or vinculo.get("ano_semestre_ingresso")
                or ""
            ).strip() or None,
            "turma_entrada": str(curso.get("turma_entrada") or "").strip() or None,
            "status_discente": status_discente,
            "frequencia_geral": frequencia_geral,
            "disciplinas": disciplinas,
            "data_inicio_aulas": data_inicio_contagem_aluno(vinculo.get("ausencias_especiais")),
        })
        chave = integrados_anual.chave_vinculo(vinculo)
        if inicio_anual is not None and integrados_anual.eh_integrado(vinculo) and chave in anteriores:
            resultado[-1]["frequencia_anual"] = integrados_anual.somar(
                vinculo, anteriores[chave], inicio_anual
            )

    resultado.sort(key=lambda registro: registro["nome"])
    return resultado


def salvar_json(resultado: list[dict[str, Any]], caminho: Path) -> None:
    """Salva o resultado em formato JSON indentado."""
    caminho.write_text(
        json.dumps(resultado, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )


def resumir(vinculos: list[dict[str, Any]], resultado: list[dict[str, Any]]) -> str:
    """Resumo textual da analise."""
    alunos_com_frequencia = len({registro["login"] for registro in resultado})
    linhas = [d for registro in resultado for d in registro.get("disciplinas", [])]
    com_turma = sum(1 for d in linhas if d.get("id_turma") is not None)
    anuais = sum(1 for registro in resultado if registro.get("frequencia_anual"))
    return (
        f"Vinculos no arquivo: {len(vinculos)}\n"
        f"Alunos com frequencia (ATIVO/FORMANDO): {alunos_com_frequencia}\n"
        f"Registros (aluno/curso): {len(resultado)}\n"
        f"Linhas de disciplina: {len(linhas)} (com turma identificada: {com_turma})\n"
        f"Integrados com frequencia anual: {anuais}"
    )


def main() -> int:
    """Ponto de entrada: le os arquivos da massa e grava tabela_frequencia.json."""
    try:
        vinculos = carregar_json(ARQUIVO_INTERVALO, list)
        cadastro = carregar_json(ARQUIVO_CADASTRO, dict)
    except FileNotFoundError as error:
        print(f"Erro: arquivo nao encontrado: {error.filename}", file=sys.stderr)
        print("Rode antes: python3 consulta_alunos_massa.py", file=sys.stderr)
        return 1
    except (ValueError, json.JSONDecodeError) as error:
        print(f"Erro ao ler entrada: {error}", file=sys.stderr)
        return 1

    turmas: dict[ChaveTurma, int] = {}
    if JSON_RESPOSTA_MATRICULAS.is_file():
        try:
            turmas = indexar_turmas(json.loads(JSON_RESPOSTA_MATRICULAS.read_text(encoding="utf-8")))
        except (OSError, json.JSONDecodeError) as error:
            print(f"Aviso: turmas nao identificadas ({JSON_RESPOSTA_MATRICULAS.name}: {error})")
    else:
        print(f"Aviso: {JSON_RESPOSTA_MATRICULAS.name} nao encontrado; disciplinas sem turma.")

    inicio_anual = None
    anteriores: dict[integrados_anual.ChaveVinculo, list[dict[str, Any]]] = {}
    try:
        from api_auth import carregar_config_api

        inicio_anual, anteriores, aviso = integrados_anual.carregar(carregar_config_api())
        if aviso:
            print(f"Aviso: {aviso}")
    except Exception as error:  # noqa: BLE001
        print(f"Aviso: frequencia anual dos integrados nao carregada ({error}).")

    resultado = montar_resultado(vinculos, cadastro, turmas, inicio_anual, anteriores)
    garantir_diretorios()
    salvar_json(resultado, ARQUIVO_SAIDA_JSON)

    print("Analise de frequencia por aluno (consulta em massa)")
    for caminho in (ARQUIVO_INTERVALO, ARQUIVO_CADASTRO):
        consultado = datetime.fromtimestamp(caminho.stat().st_mtime).strftime("%d/%m/%Y %H:%M")
        print(f"Entrada: {caminho.name} (consultado em {consultado})")
    print(resumir(vinculos, resultado))
    print(f"JSON salvo em: {ARQUIVO_SAIDA_JSON}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
