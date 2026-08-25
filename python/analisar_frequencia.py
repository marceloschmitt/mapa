#!/usr/bin/env python3
"""Analisa resposta_alunos.json e gera tabela de frequencia por aluno.

Le o arquivo gerado por consulta_alunos.py. Frequencia/controle: apenas cursos
ATIVO ou FORMANDO. Trancamento: TRANCADO / TRANC. AUTOMATICO em status_discente
(2ª consulta). Demais status sao ignorados.
"""

from __future__ import annotations

import json
import sys
from datetime import date, datetime, timedelta
from pathlib import Path
from typing import Any

from paths import (
    JSON_ALUNOS_TRANCADOS,
    JSON_RESPOSTA_ALUNOS,
    JSON_TABELA_FREQUENCIA,
    garantir_diretorios,
)
from status_aluno import status_eh_controle, status_eh_trancado

ARQUIVO_ENTRADA = JSON_RESPOSTA_ALUNOS
ARQUIVO_SAIDA_JSON = JSON_TABELA_FREQUENCIA
ARQUIVO_TRANCADOS_JSON = JSON_ALUNOS_TRANCADOS


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


def fim_matricula_atrasada(frequencias: dict[str, Any]) -> date | None:
    """Ultimo dia do intervalo matricula_atrasada em ausencias_especiais, se houver."""
    especiais = frequencias.get("ausencias_especiais")
    if not isinstance(especiais, dict):
        return None
    atrasada = especiais.get("matricula_atrasada")
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


def data_inicio_contagem_aluno(frequencias: dict[str, Any]) -> str | None:
    """Dia a partir do qual as aulas passam a contar para o aluno (AAAA-MM-DD).

    Matricula atrasada: dia seguinte ao fim do intervalo da API.
    Aluno normal: None (usa o primeiro dia de aula da disciplina na grade).
    """
    fim_atraso = fim_matricula_atrasada(frequencias)
    if fim_atraso is not None:
        return (fim_atraso + timedelta(days=1)).isoformat()
    return None


def carregar_alunos(caminho: Path) -> list[dict[str, Any]]:
    """Carrega a lista de alunos do arquivo de resposta.

    Args:
        caminho: Caminho do arquivo resposta_alunos.json.

    Returns:
        Lista de registros de alunos.

    Raises:
        FileNotFoundError: Quando o arquivo de entrada nao existe.
        ValueError: Quando o conteudo nao e uma lista.
    """
    dados = json.loads(caminho.read_text(encoding="utf-8"))

    if not isinstance(dados, list):
        raise ValueError("Formato inesperado: esperava uma lista de alunos.")

    return dados


def extrair_dias_falta(disciplina: dict[str, Any]) -> list[str]:
    """Extrai as datas em que o aluno faltou na disciplina.

    Args:
        disciplina: Registro da disciplina em frequencias.disciplinas.

    Returns:
        Lista de datas no formato retornado pela API (ex.: 25/04/2025).
    """
    dias = disciplina.get("ausencias", [])
    if not isinstance(dias, list):
        return []
    return [str(dia) for dia in dias if dia]


def extrair_frequencia_geral(frequencias: dict[str, Any]) -> dict[str, Any] | None:
    """Extrai os totais gerais de frequencia do curso.

    Args:
        frequencias: Objeto frequencias do curso na resposta da API.

    Returns:
        Dicionario com periodo e totais, ou None se nao houver dados.
    """
    total = frequencias.get("total")
    if not isinstance(total, dict):
        return None

    info = frequencias.get("info", {})
    if not isinstance(info, dict):
        info = {}

    justificadas = total.get("frequencia_com_ausencias_justificadas", {})
    if not isinstance(justificadas, dict):
        justificadas = {}

    return {
        "data_inicial": info.get("data_inicial"),
        "data_final": info.get("data_final"),
        "carga_horaria_total": total.get("carga_horaria_total"),
        "horarios_totais": total.get("horarios_totais"),
        "ausencias_totais": total.get("ausencias_totais"),
        "presencas_totais": total.get("presencas_totais"),
        "percentual_frequencia_total": total.get("percentual_frequencia_total"),
        "ausencias_justificadas_totais": justificadas.get("ausencias_justificadas_totais"),
        "percentual_com_ausencias_justificadas": justificadas.get(
            "percentual_frequencia_total"
        ),
    }


def extrair_disciplinas(frequencias: dict[str, Any]) -> list[dict[str, Any]]:
    """Extrai o detalhamento de frequencia por disciplina.

    Args:
        frequencias: Objeto frequencias do curso na resposta da API.

    Returns:
        Lista de disciplinas com horarios, ausencias, presencas e dias de falta.
    """
    disciplinas = frequencias.get("disciplinas", {})
    if not isinstance(disciplinas, dict):
        return []

    linhas: list[dict[str, Any]] = []

    for codigo, disciplina in disciplinas.items():
        if not isinstance(disciplina, dict):
            continue

        freq = disciplina.get("frequencia", {})
        if not isinstance(freq, dict):
            freq = {}

        linhas.append({
            "codigo_disciplina": disciplina.get("cod_disciplina", codigo),
            "disciplina": disciplina.get("nome", ""),
            "horarios": freq.get("horarios", 0),
            "ausencias": freq.get("ausencias", 0),
            "presencas": freq.get("presencas", 0),
            "percentual_frequencia": freq.get("percentual_frequencia"),
            "dias_falta": extrair_dias_falta(disciplina),
        })

    linhas.sort(key=lambda linha: linha["disciplina"])
    return linhas


def extrair_registros_aluno(
    aluno: dict[str, Any],
) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    """Extrai registros de frequencia e de trancamento de um aluno.

    Args:
        aluno: Registro individual de resposta_alunos.json.

    Returns:
        Tupla (registros_frequencia, registros_trancados).
    """
    if aluno.get("status") != 200:
        return [], []

    nome = str(aluno.get("nome", ""))
    login = str(aluno.get("login", ""))
    matricula = aluno.get("matricula", "")
    dados = aluno.get("dados")

    if not isinstance(dados, dict):
        return [], []

    registros: list[dict[str, Any]] = []
    trancados: list[dict[str, Any]] = []

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
            matricula_curso = curso.get("matricula")
            if matricula_curso in (None, ""):
                matricula_curso = matricula
            base = {
                "nome": nome_civil or nome or login,
                "nome_social": nome_social,
                "login": login,
                "matricula": matricula_curso,
                "email": email,
                "nome_curso": curso.get("nome_curso"),
                "ano_semestre_ingresso": str(
                    curso.get("ano_semestre_ingresso") or ""
                ).strip() or None,
                "turma_entrada": str(curso.get("turma_entrada") or "").strip() or None,
                "status_discente": status_discente,
            }

            # Trancamento confirmado na 2ª consulta (status_discente).
            if status_eh_trancado(status_discente):
                trancados.append(base)
                continue

            # Controle: apenas ATIVO e FORMANDO.
            if not status_eh_controle(status_discente):
                continue

            frequencias = curso.get("frequencias", {})
            if not isinstance(frequencias, dict):
                continue

            frequencia_geral = extrair_frequencia_geral(frequencias)
            disciplinas = extrair_disciplinas(frequencias)

            if frequencia_geral is None and disciplinas == []:
                continue

            registros.append({
                **base,
                "frequencia_geral": frequencia_geral,
                "disciplinas": disciplinas,
                "data_inicio_aulas": data_inicio_contagem_aluno(frequencias),
            })

    return registros, trancados


def montar_resultado(
    alunos: list[dict[str, Any]],
) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    """Monta frequencia e lista de trancados a partir de todos os alunos.

    Args:
        alunos: Lista de registros de resposta_alunos.json.

    Returns:
        Tupla (frequencia ordenada, trancados ordenados).
    """
    resultado: list[dict[str, Any]] = []
    trancados: list[dict[str, Any]] = []

    for aluno in alunos:
        regs, trancs = extrair_registros_aluno(aluno)
        resultado.extend(regs)
        trancados.extend(trancs)

    resultado.sort(key=lambda registro: registro["nome"])
    trancados.sort(key=lambda registro: (
        str(registro.get("nome") or ""),
        str(registro.get("nome_curso") or ""),
    ))
    return resultado, trancados

def salvar_json(resultado: list[dict[str, Any]], caminho: Path) -> None:
    """Salva o resultado em formato JSON indentado.

    Args:
        resultado: Registros de frequencia por aluno.
        caminho: Arquivo de saida JSON.
    """
    caminho.write_text(
        json.dumps(resultado, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )


def resumir(
    alunos: list[dict[str, Any]],
    resultado: list[dict[str, Any]],
    trancados: list[dict[str, Any]],
) -> str:
    """Monta um resumo textual da analise.

    Args:
        alunos: Lista original de alunos.
        resultado: Resultado gerado.
        trancados: Registros com status de trancamento.

    Returns:
        Texto com totais e contagens uteis.
    """
    alunos_com_sucesso = sum(1 for aluno in alunos if aluno.get("status") == 200)
    alunos_com_frequencia = len({registro["login"] for registro in resultado})
    total_disciplinas = sum(len(registro.get("disciplinas", [])) for registro in resultado)
    alunos_trancados = len({str(r.get("login") or "") for r in trancados})

    return (
        f"Alunos no arquivo: {len(alunos)}\n"
        f"Alunos com consulta OK: {alunos_com_sucesso}\n"
        f"Alunos com frequencia (ATIVO/FORMANDO): {alunos_com_frequencia}\n"
        f"Registros (aluno/curso): {len(resultado)}\n"
        f"Linhas de disciplina: {total_disciplinas}\n"
        f"Alunos trancados (excluidos): {alunos_trancados}\n"
        f"Registros trancados: {len(trancados)}"
    )


def main() -> int:
    """Ponto de entrada do script.

    Carrega resposta_alunos.json, monta o resultado com frequencia geral e
    por disciplina, salva tabela_frequencia.json e imprime resumo no terminal.

    Returns:
        0 em caso de sucesso, 1 em caso de erro.
    """
    try:
        alunos = carregar_alunos(ARQUIVO_ENTRADA)
    except FileNotFoundError:
        print(f"Erro: arquivo nao encontrado: {ARQUIVO_ENTRADA}", file=sys.stderr)
        print("Rode antes: python3 consulta_alunos.py", file=sys.stderr)
        return 1
    except (ValueError, json.JSONDecodeError) as error:
        print(f"Erro ao ler entrada: {error}", file=sys.stderr)
        return 1

    resultado, trancados = montar_resultado(alunos)
    garantir_diretorios()
    salvar_json(resultado, ARQUIVO_SAIDA_JSON)
    salvar_json(trancados, ARQUIVO_TRANCADOS_JSON)

    print("Analise de frequencia por aluno")
    print(resumir(alunos, resultado, trancados))
    print(f"JSON salvo em: {ARQUIVO_SAIDA_JSON}")
    print(f"Trancados salvos em: {ARQUIVO_TRANCADOS_JSON}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())