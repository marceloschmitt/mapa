#!/usr/bin/env python3
"""Gera alarmes de risco de evasao a partir do SQLite.

Regras:
  - percentual_baixo: frequencia < 75% na disciplina
  - faltas_4dias: 3+ dias uteis consecutivos de falta, com a sequencia
    tocando a janela dos ultimos 4 dias uteis (segunda a sexta;
    sabado/domingo nao contam e nao quebram a sequencia — ex.:
    quinta, sexta e segunda = 3 dias seguidos)
  - faltas_3semanas: falta em 3 semanas consecutivas, ultima na janela
    (referencia ate 7 dias antes; apenas dias uteis, sabados nao contam)

Alarmes com acao (visualizado=1) nao sao apagados na regeneracao.
Na nova coleta, os tratados da coleta anterior sao copiados para
permanecerem visiveis no portal.

Uso:
    python3 gerar_alarmes.py
    python3 gerar_alarmes.py --coleta 3
"""

from __future__ import annotations

import argparse
import json
import sys
from datetime import date, timedelta
from typing import Any

from config_consultas import data_referencia
from db import conectar, fechar, row_to_dict

LIMITE_PERCENTUAL = 75.0
JANELA_DIAS_UTEIS = 4
MINIMO_FALTAS_UTEIS = 3
SEMANAS_CONSECUTIVAS = 3


def ultima_coleta_id(cursor: Any) -> int | None:
    """Retorna o id da coleta mais recente.

    Args:
        cursor: Cursor SQLite.

    Returns:
        ID da coleta ou None.
    """
    cursor.execute("SELECT id FROM coletas ORDER BY id DESC LIMIT 1")
    row = cursor.fetchone()
    if row is None:
        return None
    return int(row["id"] if isinstance(row, dict) else row[0])


def eh_dia_util(dia: date) -> bool:
    """Indica se a data e dia util (segunda a sexta; exclui sabado e domingo)."""
    return dia.weekday() < 5


def proximo_dia_util(dia: date) -> date:
    """Retorna o proximo dia util apos a data informada."""
    atual = dia + timedelta(days=1)
    while not eh_dia_util(atual):
        atual += timedelta(days=1)
    return atual


def obter_janela_uteis(referencia: date, quantidade: int = JANELA_DIAS_UTEIS) -> list[date]:
    """Lista os N dias uteis ate a data de referencia (inclusive).

    Args:
        referencia: Data final.
        quantidade: Quantidade de dias uteis.

    Returns:
        Lista de datas uteis em ordem crescente.
    """
    dias: list[date] = []
    atual = referencia
    while len(dias) < quantidade:
        if eh_dia_util(atual):
            dias.append(atual)
        atual -= timedelta(days=1)
    dias.reverse()
    return dias


def sequencias_faltas_uteis(datas: list[date], limite: date) -> list[list[date]]:
    """Agrupa faltas em sequencias de dias uteis consecutivos.

    Sabado e domingo sao ignorados: nao entram na contagem e nao
    interrompem a sequencia (quinta -> sexta -> segunda e continua).

    Args:
        datas: Datas de falta.
        limite: Ultima data considerada (inclusive).

    Returns:
        Lista de sequencias ordenadas (cada uma em ordem crescente).
    """
    uteis = sorted({d for d in datas if eh_dia_util(d) and d <= limite})
    if not uteis:
        return []

    sequencias: list[list[date]] = []
    atual = [uteis[0]]
    for dia in uteis[1:]:
        if dia == proximo_dia_util(atual[-1]):
            atual.append(dia)
        else:
            sequencias.append(atual)
            atual = [dia]
    sequencias.append(atual)
    return sequencias


def semana_iso(dia: date) -> tuple[int, int]:
    """Retorna (ano_iso, semana_iso)."""
    ano, semana, _ = dia.isocalendar()
    return ano, semana


def proxima_semana(ano: int, semana: int) -> tuple[int, int]:
    """Semana ISO seguinte."""
    segunda = date.fromisocalendar(ano, semana, 1)
    seguinte = segunda + timedelta(days=7)
    return seguinte.isocalendar()[0], seguinte.isocalendar()[1]


def limpar_alarmes_coleta(cursor: Any, coleta_id: int) -> None:
    """Remove apenas alarmes abertos da coleta antes de regenerar.

    Alarmes com acao registrada (visualizado = 1) sao preservados.

    Args:
        cursor: Cursor SQLite.
        coleta_id: ID da coleta.
    """
    cursor.execute(
        "DELETE FROM alarmes WHERE coleta_id = ? AND visualizado = 0",
        (coleta_id,),
    )


def coleta_anterior_id(cursor: Any, coleta_id: int) -> int | None:
    """Retorna o id da coleta imediatamente anterior.

    Args:
        cursor: Cursor SQLite.
        coleta_id: Coleta atual.

    Returns:
        ID da coleta anterior ou None.
    """
    cursor.execute(
        "SELECT id FROM coletas WHERE id < ? ORDER BY id DESC LIMIT 1",
        (coleta_id,),
    )
    row = cursor.fetchone()
    if row is None:
        return None
    return int(row["id"] if isinstance(row, dict) else row[0])


def trazer_alarmes_tratados(cursor: Any, coleta_id: int) -> int:
    """Copia alarmes ja tratados da coleta anterior para a atual.

    So copia se o aluno/curso ainda aparece na frequencia da coleta atual
    (alunos que sumiram — ex.: trancamento — nao reaparecem nos alarmes).

    Args:
        cursor: Cursor SQLite.
        coleta_id: Coleta de destino.

    Returns:
        Quantidade de alarmes copiados (ou atualizados).
    """
    anterior = coleta_anterior_id(cursor, coleta_id)
    if anterior is None:
        return 0

    cursor.execute(
        """
        INSERT INTO alarmes (
            coleta_id, aluno_id, curso_id, codigo_disciplina, disciplina,
            tipo, severidade, mensagem, detalhe_json,
            visualizado, visualizado_em, visualizado_por, contato_tipo, gerado_em
        )
        SELECT
            ?, a.aluno_id, a.curso_id, a.codigo_disciplina, a.disciplina,
            a.tipo, a.severidade, a.mensagem, a.detalhe_json,
            a.visualizado, a.visualizado_em, a.visualizado_por, a.contato_tipo, a.gerado_em
        FROM alarmes a
        WHERE a.coleta_id = ?
          AND a.visualizado = 1
          AND EXISTS (
              SELECT 1
              FROM frequencia_curso fc
              WHERE fc.coleta_id = ?
                AND fc.aluno_id = a.aluno_id
                AND fc.curso_id = a.curso_id
          )
        ON CONFLICT(coleta_id, aluno_id, curso_id, codigo_disciplina, tipo)
        DO UPDATE SET
            severidade = excluded.severidade,
            mensagem = excluded.mensagem,
            detalhe_json = excluded.detalhe_json,
            disciplina = excluded.disciplina,
            visualizado = 1,
            visualizado_em = excluded.visualizado_em,
            visualizado_por = excluded.visualizado_por,
            contato_tipo = excluded.contato_tipo,
            gerado_em = excluded.gerado_em
        """,
        (coleta_id, anterior, coleta_id),
    )
    return int(cursor.rowcount or 0)


def cancelar_avisos_staff_alunos_ausentes(cursor: Any, coleta_id: int) -> int:
    """Fecha avisos pendentes ao staff de alunos fora da coleta atual.

    Evita e-mail a professor/coordenador sobre quem provavelmente trancou.

    Args:
        cursor: Cursor SQLite.
        coleta_id: Coleta de referencia (ultima).

    Returns:
        Quantidade de registros de alarme_emails fechados.
    """
    cursor.execute(
        """
        UPDATE alarme_emails
        SET staff_avisado_em = datetime('now')
        WHERE staff_avisado_em IS NULL
          AND (
              NOT EXISTS (
                  SELECT 1
                  FROM frequencia_curso fc
                  WHERE fc.coleta_id = ?
                    AND fc.aluno_id = alarme_emails.aluno_id
                    AND fc.curso_id = alarme_emails.curso_id
              )
              OR EXISTS (
                  SELECT 1
                  FROM alunos_trancados at
                  WHERE at.coleta_id = ?
                    AND at.aluno_id = alarme_emails.aluno_id
                    AND at.curso_id = alarme_emails.curso_id
              )
          )
        """,
        (coleta_id, coleta_id),
    )
    return int(cursor.rowcount or 0)


def inserir_alarme(
    cursor: Any,
    *,
    coleta_id: int,
    aluno_id: int,
    curso_id: int,
    codigo_disciplina: str | None,
    disciplina: str | None,
    tipo: str,
    severidade: str,
    mensagem: str,
    detalhe: dict[str, Any],
) -> None:
    """Insere um alarme preservando acao ja registrada em conflito.

    Args:
        cursor: Cursor SQLite.
        coleta_id: Coleta de origem.
        aluno_id: Aluno.
        curso_id: Curso.
        codigo_disciplina: Codigo ou None para alarmes agregados.
        disciplina: Nome da disciplina.
        tipo: Tipo do alarme.
        severidade: alto ou critico.
        mensagem: Texto curto.
        detalhe: Payload JSON.
    """
    cursor.execute(
        """
        INSERT INTO alarmes (
            coleta_id, aluno_id, curso_id, codigo_disciplina, disciplina,
            tipo, severidade, mensagem, detalhe_json
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(coleta_id, aluno_id, curso_id, codigo_disciplina, tipo)
        DO UPDATE SET
            severidade = excluded.severidade,
            mensagem = excluded.mensagem,
            detalhe_json = excluded.detalhe_json,
            disciplina = COALESCE(excluded.disciplina, alarmes.disciplina),
            gerado_em = datetime('now'),
            visualizado = alarmes.visualizado,
            visualizado_em = alarmes.visualizado_em,
            visualizado_por = alarmes.visualizado_por,
            contato_tipo = alarmes.contato_tipo
        """,
        (
            coleta_id,
            aluno_id,
            curso_id,
            codigo_disciplina or "",
            disciplina,
            tipo,
            severidade,
            mensagem,
            json.dumps(detalhe, ensure_ascii=False),
        ),
    )


def gerar_percentual_baixo(cursor: Any, coleta_id: int) -> int:
    """Gera alarmes de frequencia abaixo de 75%.

    Args:
        cursor: Cursor SQLite.
        coleta_id: Coleta.

    Returns:
        Quantidade de alarmes gerados.
    """
    cursor.execute(
        """
        SELECT aluno_id, curso_id, codigo_disciplina, disciplina,
               percentual_frequencia, ausencias, horarios
        FROM frequencia_disciplina
        WHERE coleta_id = ?
          AND percentual_frequencia IS NOT NULL
          AND percentual_frequencia < ?
        """,
        (coleta_id, LIMITE_PERCENTUAL),
    )
    rows = cursor.fetchall()
    total = 0

    for row in rows:
        percentual = float(row["percentual_frequencia"])
        severidade = "critico" if percentual < 50 else "alto"
        inserir_alarme(
            cursor,
            coleta_id=coleta_id,
            aluno_id=int(row["aluno_id"]),
            curso_id=int(row["curso_id"]),
            codigo_disciplina=row["codigo_disciplina"],
            disciplina=row["disciplina"],
            tipo="percentual_baixo",
            severidade=severidade,
            mensagem=f"Frequencia {percentual:.1f}% (abaixo de {LIMITE_PERCENTUAL:.0f}%)",
            detalhe={
                "percentual_frequencia": percentual,
                "ausencias": int(row["ausencias"]),
                "horarios": int(row["horarios"]),
            },
        )
        total += 1

    return total


def carregar_faltas_por_aluno(cursor: Any, coleta_id: int) -> dict[tuple[int, int], list[date]]:
    """Agrupa datas de falta por (aluno_id, curso_id).

    Args:
        cursor: Cursor SQLite.
        coleta_id: Coleta.

    Returns:
        Mapa (aluno, curso) -> lista de datas.
    """
    cursor.execute(
        """
        SELECT aluno_id, curso_id, data_falta
        FROM faltas_dia
        WHERE coleta_id = ?
        ORDER BY aluno_id, curso_id, data_falta
        """,
        (coleta_id,),
    )
    mapa: dict[tuple[int, int], list[date]] = {}
    for row in cursor.fetchall():
        chave = (int(row["aluno_id"]), int(row["curso_id"]))
        dia = row["data_falta"]
        if isinstance(dia, date):
            data = dia
        else:
            data = date.fromisoformat(str(dia)[:10])
        mapa.setdefault(chave, []).append(data)
    return mapa


def carregar_faltas_disciplina(
    cursor: Any,
    coleta_id: int,
) -> tuple[dict[tuple[int, int, str], list[date]], dict[tuple[int, int, str], str]]:
    """Agrupa datas de falta por aluno/curso/disciplina.

    Args:
        cursor: Cursor SQLite.
        coleta_id: Coleta.

    Returns:
        Tupla (mapa de datas, mapa de nomes de disciplina).
    """
    cursor.execute(
        """
        SELECT fd.aluno_id, fd.curso_id, fd.codigo_disciplina, fd.data_falta,
               COALESCE(f.disciplina, fd.codigo_disciplina) AS disciplina
        FROM faltas_dia fd
        LEFT JOIN frequencia_disciplina f
          ON f.coleta_id = fd.coleta_id
         AND f.aluno_id = fd.aluno_id
         AND f.curso_id = fd.curso_id
         AND f.codigo_disciplina = fd.codigo_disciplina
        WHERE fd.coleta_id = ?
        ORDER BY fd.aluno_id, fd.curso_id, fd.codigo_disciplina, fd.data_falta
        """,
        (coleta_id,),
    )
    mapa: dict[tuple[int, int, str], list[date]] = {}
    nomes: dict[tuple[int, int, str], str] = {}

    for row in cursor.fetchall():
        chave = (
            int(row["aluno_id"]),
            int(row["curso_id"]),
            str(row["codigo_disciplina"]),
        )
        dia = row["data_falta"]
        data = dia if isinstance(dia, date) else date.fromisoformat(str(dia)[:10])
        mapa.setdefault(chave, []).append(data)
        nomes[chave] = str(row["disciplina"])

    return mapa, nomes


def gerar_faltas_4dias(cursor: Any, coleta_id: int, referencia: date) -> int:
    """Gera alarmes de 3+ dias uteis consecutivos de falta (recentes).

    A sequencia e medida em dias uteis: quinta, sexta e segunda contam
    como 3 dias seguidos. Sabados nao entram nem quebram a sequencia.
    So dispara se a sequencia toca a janela dos ultimos 4 dias uteis.

    Args:
        cursor: Cursor SQLite.
        coleta_id: Coleta.
        referencia: Data de referencia.

    Returns:
        Quantidade de alarmes.
    """
    janela = set(obter_janela_uteis(referencia))
    faltas = carregar_faltas_por_aluno(cursor, coleta_id)
    total = 0

    for (aluno_id, curso_id), datas in faltas.items():
        candidatas = [
            seq
            for seq in sequencias_faltas_uteis(datas, referencia)
            if len(seq) >= MINIMO_FALTAS_UTEIS and any(d in janela for d in seq)
        ]
        if not candidatas:
            continue

        # Prefere a sequencia mais longa; em empate, a que termina mais tarde.
        sequencia = max(candidatas, key=lambda seq: (len(seq), seq[-1]))
        severidade = "critico" if len(sequencia) >= JANELA_DIAS_UTEIS else "alto"
        dias_fmt = ", ".join(d.strftime("%d/%m") for d in sequencia)
        inserir_alarme(
            cursor,
            coleta_id=coleta_id,
            aluno_id=aluno_id,
            curso_id=curso_id,
            codigo_disciplina=None,
            disciplina=None,
            tipo="faltas_4dias",
            severidade=severidade,
            mensagem=(
                f"{len(sequencia)} dias úteis: {dias_fmt}"
            ),
            detalhe={
                "dias_falta": [d.isoformat() for d in sequencia],
                "janela": [d.isoformat() for d in sorted(janela)],
            },
        )
        total += 1

    return total


def encontrar_sequencia_3_semanas(
    datas: list[date],
    referencia: date,
) -> list[tuple[int, int]] | None:
    """Encontra 3 semanas consecutivas com falta e ultima na janela recente.

    Sabados e domingos nao entram na contagem: so faltas em dias uteis
    (segunda a sexta) abrem ou mantem uma semana na sequencia.

    Args:
        datas: Datas de falta da disciplina.
        referencia: Data de referencia.

    Returns:
        Sequencia de semanas ou None.
    """
    inicio_janela = referencia - timedelta(days=7)
    datas_uteis = [d for d in datas if d <= referencia and eh_dia_util(d)]
    semanas = {semana_iso(d) for d in datas_uteis}
    if len(semanas) < SEMANAS_CONSECUTIVAS:
        return None

    ordenadas = sorted(semanas)
    candidatas: list[tuple[date, list[tuple[int, int]]]] = []

    for indice in range(len(ordenadas) - SEMANAS_CONSECUTIVAS + 1):
        sequencia = [ordenadas[indice]]
        valida = True
        for offset in range(1, SEMANAS_CONSECUTIVAS):
            esperada = proxima_semana(*sequencia[-1])
            if ordenadas[indice + offset] != esperada:
                valida = False
                break
            sequencia.append(esperada)

        if not valida:
            continue

        datas_seq = [d for d in datas_uteis if semana_iso(d) in set(sequencia)]
        if not datas_seq:
            continue
        ultima = max(datas_seq)
        if inicio_janela <= ultima <= referencia:
            candidatas.append((ultima, sequencia))

    if not candidatas:
        return None

    candidatas.sort(key=lambda item: item[0], reverse=True)
    return candidatas[0][1]


def gerar_faltas_3semanas(cursor: Any, coleta_id: int, referencia: date) -> int:
    """Gera alarmes de 3 semanas consecutivas com falta.

    Args:
        cursor: Cursor SQLite.
        coleta_id: Coleta.
        referencia: Data de referencia.

    Returns:
        Quantidade de alarmes.
    """
    faltas, nomes = carregar_faltas_disciplina(cursor, coleta_id)
    total = 0

    for chave, datas in faltas.items():
        aluno_id, curso_id, codigo = chave
        sequencia = encontrar_sequencia_3_semanas(datas, referencia)
        if sequencia is None:
            continue

        semanas_rotulo = [
            date.fromisocalendar(ano, semana, 1).isoformat()
            for ano, semana in sequencia
        ]
        inserir_alarme(
            cursor,
            coleta_id=coleta_id,
            aluno_id=aluno_id,
            curso_id=curso_id,
            codigo_disciplina=codigo,
            disciplina=nomes.get(chave, codigo),
            tipo="faltas_3semanas",
            severidade="critico",
            mensagem="Faltas em 3 semanas consecutivas na disciplina",
            detalhe={"semanas": semanas_rotulo},
        )
        total += 1

    return total


def gerar(coleta_id: int | None = None) -> dict[str, int]:
    """Executa todas as regras de alarme para uma coleta.

    Args:
        coleta_id: Coleta especifica ou a mais recente.

    Returns:
        Contagens por tipo.
    """
    conn = conectar()
    referencia = data_referencia()
    cursor = conn.cursor()

    if coleta_id is None:
        coleta_id = ultima_coleta_id(cursor)
    if coleta_id is None:
        raise RuntimeError("Nenhuma coleta encontrada. Rode importar_frequencia.py.")

    limpar_alarmes_coleta(cursor, coleta_id)
    n_tratados = trazer_alarmes_tratados(cursor, coleta_id)
    n_percentual = gerar_percentual_baixo(cursor, coleta_id)
    n_4dias = gerar_faltas_4dias(cursor, coleta_id, referencia)
    n_3semanas = gerar_faltas_3semanas(cursor, coleta_id, referencia)
    n_staff_cancelados = cancelar_avisos_staff_alunos_ausentes(cursor, coleta_id)

    conn.commit()
    return {
        "coleta_id": coleta_id,
        "tratados_preservados": n_tratados,
        "percentual_baixo": n_percentual,
        "faltas_4dias": n_4dias,
        "faltas_3semanas": n_3semanas,
        "staff_cancelados_ausentes": n_staff_cancelados,
        "total": n_percentual + n_4dias + n_3semanas,
    }


def main() -> int:
    """Ponto de entrada.

    Returns:
        0 em sucesso, 1 em erro.
    """
    parser = argparse.ArgumentParser(description="Gera alarmes a partir do SQLite")
    parser.add_argument("--coleta", type=int, default=None, help="ID da coleta")
    args = parser.parse_args()

    try:
        resumo = gerar(args.coleta)
    except Exception as error:  # noqa: BLE001
        print(f"Erro ao gerar alarmes: {error}", file=sys.stderr)
        fechar()
        return 1
    finally:
        fechar()

    print("Alarmes gerados")
    print(f"Coleta ID: {resumo['coleta_id']}")
    print(f"Tratados preservados: {resumo['tratados_preservados']}")
    print(f"Percentual < 75%: {resumo['percentual_baixo']}")
    print(f"Faltas 4 dias: {resumo['faltas_4dias']}")
    print(f"Faltas 3 semanas: {resumo['faltas_3semanas']}")
    print(f"Avisos staff cancelados (aluno ausente): {resumo['staff_cancelados_ausentes']}")
    print(f"Total (regras): {resumo['total']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
