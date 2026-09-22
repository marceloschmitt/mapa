#!/usr/bin/env python3
"""Gera alarmes de risco de evasao a partir do SQLite.

Os limites, janelas e mensagens vem de Configuracoes -> Alarmes no portal
(tabela `configuracoes`, prefixo `alarme_`) — ver python/config_alarmes.py.
Sem configuracao gravada valem os padroes, iguais ao comportamento antigo.

Regras (parametros padrao entre parenteses):
  - percentual_baixo: frequencia abaixo do limite (75%) na disciplina, so
    apos a carencia (3 semanas) contada do primeiro dia de aula da
    disciplina (ou do primeiro dia apos matricula atrasada), e com
    horarios > 0; abaixo do limite critico (50%) a severidade e critica
  - faltas_4dias: minimo de dias uteis consecutivos de falta (3), com a
    sequencia tocando a janela dos ultimos dias uteis (4) (segunda a
    sexta; sabado/domingo nao contam e nao quebram a sequencia — ex.:
    quinta, sexta e segunda = 3 dias seguidos); a partir de certo tamanho
    (4) a severidade e critica
  - faltas_3semanas: N semanas ISO consecutivas em que o aluno faltou em
    todas as aulas previstas da disciplina (grade em disciplina_aulas);
    a ultima falta da sequencia na janela de recencia (7 dias). Semana
    incompleta (ainda ha aula futura na semana) nao conta. Sem grade,
    a regra nao gera alarme para aquela disciplina.

Cada regra pode ser desligada no portal: sem a regra ativa, os alarmes
abertos daquele tipo somem na proxima geracao.

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

from config_alarmes import (
    PADRAO,
    carregar_config_alarmes,
    formatar_mensagem,
    formatar_numero,
)
from config_consultas import data_referencia
from db import conectar, fechar, row_to_dict

# Padroes usados quando o administrador ainda nao configurou as regras.
LIMITE_PERCENTUAL = float(PADRAO["frequencia_limite"])
JANELA_DIAS_UTEIS = int(PADRAO["faltas_dias_janela"])
MINIMO_FALTAS_UTEIS = int(PADRAO["faltas_dias_minimo"])
SEMANAS_CONSECUTIVAS = int(PADRAO["faltas_semanas_total"])


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


def gerar_percentual_baixo(
    cursor: Any,
    coleta_id: int,
    referencia: date,
    config: dict[str, Any] | None = None,
) -> int:
    """Gera alarmes de frequencia abaixo do limite configurado.

    So conta apos a carencia configurada (padrao 3 semanas) do inicio
    efetivo da disciplina para o aluno:
    - primeiro dia de aula da disciplina na grade; ou
    - se houver matricula atrasada, o primeiro dia de aula a partir do
      dia seguinte ao fim do intervalo de atraso.

    Args:
        cursor: Cursor SQLite.
        coleta_id: Coleta.
        referencia: Data de referencia dos alarmes.
        config: Parametros das regras (padrao: os gravados no banco).

    Returns:
        Quantidade de alarmes gerados.
    """
    config = config or carregar_config_alarmes(cursor)
    if not config["frequencia_ativo"]:
        return 0

    limite = float(config["frequencia_limite"])
    limite_critico = float(config["frequencia_limite_critico"])
    carencia = int(config["frequencia_carencia_semanas"])
    cursor.execute(
        """
        SELECT fd.aluno_id, fd.curso_id, fd.codigo_disciplina, fd.disciplina,
               fd.percentual_frequencia, fd.ausencias, fd.horarios,
               fc.data_inicio_aulas,
               (
                   SELECT MIN(da.data_aula)
                   FROM disciplina_aulas da
                   WHERE da.codigo_disciplina = fd.codigo_disciplina
                     AND da.curso_id = fd.curso_id
                     AND (
                         fc.data_inicio_aulas IS NULL
                         OR TRIM(fc.data_inicio_aulas) = ''
                         OR da.data_aula >= fc.data_inicio_aulas
                     )
               ) AS primeira_aula
        FROM frequencia_disciplina fd
        LEFT JOIN frequencia_curso fc
          ON fc.coleta_id = fd.coleta_id
         AND fc.aluno_id = fd.aluno_id
         AND fc.curso_id = fd.curso_id
        WHERE fd.coleta_id = ?
          AND fd.percentual_frequencia IS NOT NULL
          AND fd.percentual_frequencia < ?
          AND fd.horarios > 0
        """,
        (coleta_id, limite),
    )
    rows = cursor.fetchall()
    total = 0

    for row in rows:
        primeira_txt = row["primeira_aula"]
        if not primeira_txt:
            continue
        try:
            primeira = date.fromisoformat(str(primeira_txt)[:10])
        except ValueError:
            continue

        if referencia < primeira + timedelta(weeks=carencia):
            continue

        percentual = float(row["percentual_frequencia"])
        severidade = "critico" if percentual < limite_critico else "alto"
        mensagem = formatar_mensagem(
            str(config["frequencia_mensagem"]),
            {
                "percentual": f"{percentual:.1f}",
                "limite": formatar_numero(limite),
                "limite_critico": formatar_numero(limite_critico),
                "ausencias": int(row["ausencias"]),
                "horarios": int(row["horarios"]),
                "disciplina": str(row["disciplina"] or ""),
                "codigo_disciplina": str(row["codigo_disciplina"] or ""),
                "carencia_semanas": carencia,
            },
        )
        inserir_alarme(
            cursor,
            coleta_id=coleta_id,
            aluno_id=int(row["aluno_id"]),
            curso_id=int(row["curso_id"]),
            codigo_disciplina=row["codigo_disciplina"],
            disciplina=row["disciplina"],
            tipo="percentual_baixo",
            severidade=severidade,
            mensagem=mensagem,
            detalhe={
                "percentual_frequencia": percentual,
                "ausencias": int(row["ausencias"]),
                "horarios": int(row["horarios"]),
                "primeira_aula": str(primeira_txt),
                "data_inicio_aluno": row["data_inicio_aulas"],
                "limite": limite,
                "limite_critico": limite_critico,
                "carencia_semanas": carencia,
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


def carregar_aulas_por_disciplina(
    cursor: Any,
) -> dict[tuple[int, str], set[date]]:
    """Carrega datas de aula da grade por (curso_id, codigo_disciplina).

    Args:
        cursor: Cursor SQLite.

    Returns:
        Mapa (curso_id, codigo) -> conjunto de datas de aula.
    """
    cursor.execute(
        """
        SELECT curso_id, codigo_disciplina, data_aula
        FROM disciplina_aulas
        ORDER BY curso_id, codigo_disciplina, data_aula
        """
    )
    mapa: dict[tuple[int, str], set[date]] = {}
    for row in cursor.fetchall():
        chave = (int(row["curso_id"]), str(row["codigo_disciplina"]))
        dia = row["data_aula"]
        data = dia if isinstance(dia, date) else date.fromisoformat(str(dia)[:10])
        mapa.setdefault(chave, set()).add(data)
    return mapa


def semanas_perdidas(
    faltas: set[date],
    aulas: set[date],
    referencia: date,
) -> dict[tuple[int, int], list[date]]:
    """Semanas ISO em que o aluno faltou em todas as aulas da disciplina.

    Uma semana so conta se:
      - ha pelo menos uma aula prevista na grade naquela semana;
      - todas as aulas da semana ja ocorreram ate a referencia;
      - cada uma dessas aulas tem falta registrada.

    Assim, disciplina com aula na segunda e na quarta: faltar so na
    segunda nao marca a semana (houve comparecimento na quarta).

    Args:
        faltas: Datas de falta do aluno na disciplina.
        aulas: Datas de aula previstas na grade.
        referencia: Data de referencia dos alarmes.

    Returns:
        Mapa (ano_iso, semana_iso) -> aulas da semana (todas com falta).
    """
    aulas_por_semana: dict[tuple[int, int], list[date]] = {}
    for aula in aulas:
        aulas_por_semana.setdefault(semana_iso(aula), []).append(aula)

    perdidas: dict[tuple[int, int], list[date]] = {}
    for chave_semana, aulas_semana in aulas_por_semana.items():
        # Semana incompleta: ainda ha aula futura — nao fecha como perdida.
        if any(aula > referencia for aula in aulas_semana):
            continue
        ocorridas = sorted(aula for aula in aulas_semana if aula <= referencia)
        if not ocorridas:
            continue
        if all(aula in faltas for aula in ocorridas):
            perdidas[chave_semana] = ocorridas

    return perdidas


def gerar_faltas_4dias(
    cursor: Any,
    coleta_id: int,
    referencia: date,
    config: dict[str, Any] | None = None,
) -> int:
    """Gera alarmes de faltas em dias uteis consecutivos (recentes).

    A sequencia e medida em dias uteis: quinta, sexta e segunda contam
    como 3 dias seguidos. Sabados nao entram nem quebram a sequencia.
    So dispara se a sequencia toca a janela de dias uteis configurada.

    Args:
        cursor: Cursor SQLite.
        coleta_id: Coleta.
        referencia: Data de referencia.
        config: Parametros das regras (padrao: os gravados no banco).

    Returns:
        Quantidade de alarmes.
    """
    config = config or carregar_config_alarmes(cursor)
    if not config["faltas_dias_ativo"]:
        return 0

    minimo = int(config["faltas_dias_minimo"])
    dias_janela = int(config["faltas_dias_janela"])
    dias_critico = int(config["faltas_dias_critico"])

    janela = set(obter_janela_uteis(referencia, dias_janela))
    faltas = carregar_faltas_por_aluno(cursor, coleta_id)
    total = 0

    for (aluno_id, curso_id), datas in faltas.items():
        candidatas = [
            seq
            for seq in sequencias_faltas_uteis(datas, referencia)
            if len(seq) >= minimo and any(d in janela for d in seq)
        ]
        if not candidatas:
            continue

        # Prefere a sequencia mais longa; em empate, a que termina mais tarde.
        sequencia = max(candidatas, key=lambda seq: (len(seq), seq[-1]))
        severidade = "critico" if len(sequencia) >= dias_critico else "alto"
        dias_fmt = ", ".join(d.strftime("%d/%m") for d in sequencia)
        mensagem = formatar_mensagem(
            str(config["faltas_dias_mensagem"]),
            {
                "dias": len(sequencia),
                "datas": dias_fmt,
                "primeira_falta": sequencia[0].strftime("%d/%m"),
                "ultima_falta": sequencia[-1].strftime("%d/%m"),
                "minimo": minimo,
                "janela": dias_janela,
            },
        )
        inserir_alarme(
            cursor,
            coleta_id=coleta_id,
            aluno_id=aluno_id,
            curso_id=curso_id,
            codigo_disciplina=None,
            disciplina=None,
            tipo="faltas_4dias",
            severidade=severidade,
            mensagem=mensagem,
            detalhe={
                "dias_falta": [d.isoformat() for d in sequencia],
                "janela": [d.isoformat() for d in sorted(janela)],
                "minimo_dias": minimo,
                "dias_criticos": dias_critico,
            },
        )
        total += 1

    return total


def encontrar_sequencia_3_semanas(
    datas: list[date],
    aulas: set[date],
    referencia: date,
    semanas_consecutivas: int = SEMANAS_CONSECUTIVAS,
    janela_dias: int = 7,
) -> tuple[list[tuple[int, int]], list[date]] | None:
    """Encontra N semanas consecutivas perdidas (todas as aulas com falta).

    Usa a grade (`disciplina_aulas`): a semana so entra se o aluno faltou
    em todas as aulas previstas da disciplina naquela semana. Sem aulas
    na grade, nao ha sequencia.

    Args:
        datas: Datas de falta da disciplina.
        aulas: Datas de aula previstas na grade da disciplina/curso.
        referencia: Data de referencia.
        semanas_consecutivas: Quantas semanas seguidas exigir.
        janela_dias: Recencia maxima da ultima falta da sequencia.

    Returns:
        (sequencia de semanas, faltas dessas semanas) ou None.
    """
    if not aulas:
        return None

    inicio_janela = referencia - timedelta(days=janela_dias)
    faltas = {d for d in datas if d <= referencia}
    perdidas = semanas_perdidas(faltas, aulas, referencia)
    if len(perdidas) < semanas_consecutivas:
        return None

    ordenadas = sorted(perdidas.keys())
    candidatas: list[tuple[date, list[tuple[int, int]], list[date]]] = []

    for indice in range(len(ordenadas) - semanas_consecutivas + 1):
        sequencia = [ordenadas[indice]]
        valida = True
        for offset in range(1, semanas_consecutivas):
            esperada = proxima_semana(*sequencia[-1])
            if ordenadas[indice + offset] != esperada:
                valida = False
                break
            sequencia.append(esperada)

        if not valida:
            continue

        datas_seq = sorted(
            dia for chave in sequencia for dia in perdidas.get(chave, [])
        )
        if not datas_seq:
            continue
        ultima = datas_seq[-1]
        if inicio_janela <= ultima <= referencia:
            candidatas.append((ultima, sequencia, datas_seq))

    if not candidatas:
        return None

    candidatas.sort(key=lambda item: item[0], reverse=True)
    melhor = candidatas[0]
    return melhor[1], melhor[2]


def gerar_faltas_3semanas(
    cursor: Any,
    coleta_id: int,
    referencia: date,
    config: dict[str, Any] | None = None,
) -> int:
    """Gera alarmes de N semanas consecutivas com todas as aulas faltadas.

    Args:
        cursor: Cursor SQLite.
        coleta_id: Coleta.
        referencia: Data de referencia.
        config: Parametros das regras (padrao: os gravados no banco).

    Returns:
        Quantidade de alarmes.
    """
    config = config or carregar_config_alarmes(cursor)
    if not config["faltas_semanas_ativo"]:
        return 0

    semanas_consecutivas = int(config["faltas_semanas_total"])
    janela_dias = int(config["faltas_semanas_janela_dias"])
    severidade = str(config["faltas_semanas_severidade"])

    faltas, nomes = carregar_faltas_disciplina(cursor, coleta_id)
    aulas_por_disc = carregar_aulas_por_disciplina(cursor)
    total = 0

    for chave, datas in faltas.items():
        aluno_id, curso_id, codigo = chave
        aulas = aulas_por_disc.get((curso_id, codigo), set())
        resultado = encontrar_sequencia_3_semanas(
            datas,
            aulas,
            referencia,
            semanas_consecutivas,
            janela_dias,
        )
        if resultado is None:
            continue

        sequencia, faltas_seq = resultado
        semanas_rotulo = [
            date.fromisocalendar(ano, semana, 1).isoformat()
            for ano, semana in sequencia
        ]
        ultima = faltas_seq[-1]
        mensagem = formatar_mensagem(
            str(config["faltas_semanas_mensagem"]),
            {
                "semanas": semanas_consecutivas,
                "ultima_falta": ultima.strftime("%d/%m"),
                "disciplina": nomes.get(chave, codigo),
                "codigo_disciplina": codigo,
                "janela_dias": janela_dias,
            },
        )
        inserir_alarme(
            cursor,
            coleta_id=coleta_id,
            aluno_id=aluno_id,
            curso_id=curso_id,
            codigo_disciplina=codigo,
            disciplina=nomes.get(chave, codigo),
            tipo="faltas_3semanas",
            severidade=severidade,
            mensagem=mensagem,
            detalhe={
                "semanas": semanas_rotulo,
                "semanas_consecutivas": semanas_consecutivas,
                "janela_dias": janela_dias,
                "ultima_falta": ultima.isoformat(),
                "faltas_sequencia": [d.isoformat() for d in faltas_seq],
            },
        )
        total += 1

    return total


def gerar(coleta_id: int | None = None) -> dict[str, Any]:
    """Executa todas as regras de alarme ativas para uma coleta.

    Os parametros (limites, janelas e mensagens) vem da configuracao
    gravada pelo administrador no portal.

    Args:
        coleta_id: Coleta especifica ou a mais recente.

    Returns:
        Contagens por tipo e a configuracao usada.
    """
    conn = conectar()
    referencia = data_referencia()
    cursor = conn.cursor()

    if coleta_id is None:
        coleta_id = ultima_coleta_id(cursor)
    if coleta_id is None:
        raise RuntimeError("Nenhuma coleta encontrada. Rode importar_frequencia.py.")

    config = carregar_config_alarmes(cursor)

    limpar_alarmes_coleta(cursor, coleta_id)
    n_tratados = trazer_alarmes_tratados(cursor, coleta_id)
    n_percentual = gerar_percentual_baixo(cursor, coleta_id, referencia, config)
    n_4dias = gerar_faltas_4dias(cursor, coleta_id, referencia, config)
    n_3semanas = gerar_faltas_3semanas(cursor, coleta_id, referencia, config)
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
        "config": config,
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

    config = resumo["config"]
    rotulo_percentual = (
        f"Percentual < {formatar_numero(config['frequencia_limite'])}%"
        if config["frequencia_ativo"]
        else "Percentual (regra desligada)"
    )
    rotulo_dias = (
        f"Faltas {config['faltas_dias_minimo']}+ dias uteis"
        f" (janela {config['faltas_dias_janela']})"
        if config["faltas_dias_ativo"]
        else "Faltas em dias uteis (regra desligada)"
    )
    rotulo_semanas = (
        f"Faltas {config['faltas_semanas_total']} semanas"
        if config["faltas_semanas_ativo"]
        else "Faltas em semanas (regra desligada)"
    )

    print("Alarmes gerados")
    print(f"Coleta ID: {resumo['coleta_id']}")
    print(f"Tratados preservados: {resumo['tratados_preservados']}")
    print(f"{rotulo_percentual}: {resumo['percentual_baixo']}")
    print(f"{rotulo_dias}: {resumo['faltas_4dias']}")
    print(f"{rotulo_semanas}: {resumo['faltas_3semanas']}")
    print(f"Avisos staff cancelados (aluno ausente): {resumo['staff_cancelados_ausentes']}")
    print(f"Total (regras): {resumo['total']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
