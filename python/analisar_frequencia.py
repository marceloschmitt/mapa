#!/usr/bin/env python3
"""Analisa resposta_alunos.json e gera tabela de frequencia por aluno.

Le o arquivo gerado por consulta_alunos.py, extrai os dados gerais de
frequencia e o detalhamento por disciplina (horarios, ausencias, presencas
e dias de falta), e salva em tabela_frequencia.json.

Uso:
    python3 analisar_frequencia.py
"""

from __future__ import annotations

import json
import sys
from pathlib import Path
from typing import Any

from paths import JSON_RESPOSTA_ALUNOS, JSON_TABELA_FREQUENCIA, garantir_diretorios

ARQUIVO_ENTRADA = JSON_RESPOSTA_ALUNOS
ARQUIVO_SAIDA_JSON = JSON_TABELA_FREQUENCIA

LIMITE_PREVIEW = 20


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


def extrair_registros_aluno(aluno: dict[str, Any]) -> list[dict[str, Any]]:
    """Extrai os registros de frequencia de um aluno (um por curso).

    Args:
        aluno: Registro individual de resposta_alunos.json.

    Returns:
        Lista com dados gerais e disciplinas de cada curso com frequencia.
    """
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

            frequencias = curso.get("frequencias", {})
            if not isinstance(frequencias, dict):
                continue

            frequencia_geral = extrair_frequencia_geral(frequencias)
            disciplinas = extrair_disciplinas(frequencias)

            if frequencia_geral is None and disciplinas == []:
                continue

            registros.append({
                "nome": nome_civil or nome or login,
                "nome_social": nome_social,
                "login": login,
                "matricula": matricula,
                "email": email,
                "nome_curso": curso.get("nome_curso"),
                "ano_semestre_ingresso": str(
                    curso.get("ano_semestre_ingresso") or ""
                ).strip() or None,
                "turma_entrada": str(curso.get("turma_entrada") or "").strip() or None,
                "frequencia_geral": frequencia_geral,
                "disciplinas": disciplinas,
            })

    return registros


def montar_resultado(alunos: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """Monta a lista completa de frequencia a partir de todos os alunos.

    Args:
        alunos: Lista de registros de resposta_alunos.json.

    Returns:
        Lista ordenada por nome do aluno.
    """
    resultado: list[dict[str, Any]] = []

    for aluno in alunos:
        resultado.extend(extrair_registros_aluno(aluno))

    resultado.sort(key=lambda registro: registro["nome"])
    return resultado


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


def formatar_preview(resultado: list[dict[str, Any]], limite: int = LIMITE_PREVIEW) -> str:
    """Formata as primeiras linhas para exibicao no terminal.

    Args:
        resultado: Registros de frequencia por aluno.
        limite: Quantidade maxima de disciplinas a exibir.

    Returns:
        Texto tabular com cabecalho e linhas alinhadas.
    """
    linhas_disciplina: list[dict[str, Any]] = []
    for registro in resultado:
        for disciplina in registro.get("disciplinas", []):
            linhas_disciplina.append({
                "nome": registro["nome"],
                "email": registro.get("email") or "",
                **disciplina,
            })

    if linhas_disciplina == []:
        return "Nenhuma disciplina encontrada."

    larguras = {
        "nome": 24,
        "email": 28,
        "disciplina": 24,
        "horarios": 8,
        "ausencias": 9,
        "presencas": 9,
        "dias_falta": 20,
    }

    cabecalho = (
        f"{'Nome':<{larguras['nome']}} "
        f"{'Email':<{larguras['email']}} "
        f"{'Disciplina':<{larguras['disciplina']}} "
        f"{'Horarios':>{larguras['horarios']}} "
        f"{'Ausencias':>{larguras['ausencias']}} "
        f"{'Presencas':>{larguras['presencas']}} "
        f"{'Dias falta':<{larguras['dias_falta']}}"
    )
    separador = "-" * len(cabecalho)
    linhas = [cabecalho, separador]

    for linha in linhas_disciplina[:limite]:
        nome = str(linha["nome"])[: larguras["nome"]]
        email = str(linha.get("email", ""))[: larguras["email"]]
        disciplina = str(linha["disciplina"])[: larguras["disciplina"]]
        dias_lista = linha.get("dias_falta", []) or []
        dias = "; ".join(str(d) for d in dias_lista)[: larguras["dias_falta"]]
        horarios = linha.get("horarios")
        ausencias = linha.get("ausencias")
        presencas = linha.get("presencas")
        linhas.append(
            f"{nome:<{larguras['nome']}} "
            f"{email:<{larguras['email']}} "
            f"{disciplina:<{larguras['disciplina']}} "
            f"{'' if horarios is None else horarios:>{larguras['horarios']}} "
            f"{'' if ausencias is None else ausencias:>{larguras['ausencias']}} "
            f"{'' if presencas is None else presencas:>{larguras['presencas']}} "
            f"{dias:<{larguras['dias_falta']}}"
        )

    if len(linhas_disciplina) > limite:
        linhas.append(f"... e mais {len(linhas_disciplina) - limite} linha(s).")

    return "\n".join(linhas)


def resumir(alunos: list[dict[str, Any]], resultado: list[dict[str, Any]]) -> str:
    """Monta um resumo textual da analise.

    Args:
        alunos: Lista original de alunos.
        resultado: Resultado gerado.

    Returns:
        Texto com totais e contagens uteis.
    """
    alunos_com_sucesso = sum(1 for aluno in alunos if aluno.get("status") == 200)
    alunos_com_frequencia = len({registro["login"] for registro in resultado})
    total_disciplinas = sum(len(registro.get("disciplinas", [])) for registro in resultado)

    return (
        f"Alunos no arquivo: {len(alunos)}\n"
        f"Alunos com consulta OK: {alunos_com_sucesso}\n"
        f"Alunos com frequencia: {alunos_com_frequencia}\n"
        f"Registros (aluno/curso): {len(resultado)}\n"
        f"Linhas de disciplina: {total_disciplinas}"
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

    resultado = montar_resultado(alunos)
    garantir_diretorios()
    salvar_json(resultado, ARQUIVO_SAIDA_JSON)

    print("Analise de frequencia por aluno")
    print(resumir(alunos, resultado))
    print()
    print(formatar_preview(resultado))
    print()
    print(f"JSON salvo em: {ARQUIVO_SAIDA_JSON}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
