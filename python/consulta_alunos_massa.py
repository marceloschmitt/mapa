#!/usr/bin/env python3
"""Consulta em massa (todos os alunos da unidade) e grava as respostas cruas.

As URLs vem da configuracao da API (tabela configuracoes):

1. api_url_alunos_massa_cadastro (~2 s): cadastro de cada aluno com email,
   nome_social e cursos (matricula, nome_curso, turma_entrada,
   ano_semestre_ingresso, status_discente). Salvo em
   resposta_alunos_massa_cadastro.json. Lido por importar_trancados.py e
   analisar_frequencia.py.
2. api_url_alunos_massa_intervalo: um registro por vinculo
   (aluno x curso) com status_discente, totais, disciplinas e
   ausencias_especiais (o campo so aparece quando ha dados). Salvo em
   resposta_alunos_massa_intervalo.json. Lido por analisar_frequencia.py e
   importar_chamadas.py.

   Para cada status (ATIVO e FORMANDO), o periodo do semestre e consultado
   mes a mes (o periodo inteiro com ATIVO passa do limite de ~60 s do
   Cloudflare). Os blocos sao fundidos somando horarios/faltas/presencas e
   recalculando o percentual (como nos integrados anuais). Listas de dias de
   falta, ultima aula e ausencias_especiais sao unidas. Se um mes falhar, o
   arquivo anterior e mantido.

Na URL do intervalo, {data_inicial} e {data_final} sao trocados pelas datas de
frequencia_data_inicial / frequencia_data_final (DD-MM-AAAA), convertidas para
o formato exigido pelo endpoint (AAAA-MM-DD).

Primeiro passo de executar_coleta.py. Cada arquivo so e substituido quando a
sua consulta da certo; em falha, o anterior e mantido.

Uso:
    python3 consulta_alunos_massa.py
    python3 consulta_alunos_massa.py --data-inicial 03-08-2026 --data-final 31-12-2026
"""

from __future__ import annotations

import argparse
import collections
import json
import sys
import time
from datetime import date, timedelta
from pathlib import Path
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

from api_auth import (
    USER_AGENT,
    carregar_config_api,
    obter_access_token,
    ssl_context,
    verificar_ssl,
)
from config_consultas import frequencia_data_final, frequencia_data_inicial
from integrados_anual import percentual
from paths import (
    JSON_RESPOSTA_ALUNOS_MASSA,
    JSON_RESPOSTA_ALUNOS_MASSA_CADASTRO,
    garantir_diretorios,
)

PAGINA_CONFIG = "/index.php/configuracoes/api"

TIMEOUT_SEGUNDOS = 600
STATUS_INTERVALO = ("ATIVO", "FORMANDO")
# O Cloudflare do IFRS devolve 504 acima de ~60 s. O ATIVO do semestre inteiro
# e blocos de 2 meses ja estouram; a coleta usa 1 mes.
MESES_POR_BLOCO = 1
ALERTA_SEGUNDOS = 45
TIMEOUT_CADASTRO_SEGUNDOS = 120
TENTATIVAS = 3
ESPERA_ENTRE_TENTATIVAS = 30

Periodo = tuple[date, date]


def url_cadastro(config: dict[str, str]) -> str:
    """URL do cadastro em massa configurada no banco."""
    url = (config.get("api_url_alunos_massa_cadastro") or "").strip()
    if not url:
        raise ValueError(f"URL do cadastro em massa ausente. Configure em {PAGINA_CONFIG}")
    return url


def url_intervalo(config: dict[str, str], data_inicial: str, data_final: str) -> str:
    """URL do intervalo em massa com {data_inicial} e {data_final} (AAAA-MM-DD)."""
    url = (config.get("api_url_alunos_massa_intervalo") or "").strip()
    if not url:
        raise ValueError(f"URL do intervalo em massa ausente. Configure em {PAGINA_CONFIG}")
    if "{data_inicial}" not in url or "{data_final}" not in url:
        raise ValueError(
            "URL do intervalo em massa deve conter {data_inicial} e {data_final}. "
            f"Ajuste em {PAGINA_CONFIG}"
        )
    return url.replace("{data_inicial}", data_inicial).replace("{data_final}", data_final)


def data_iso(data: str) -> str:
    """Converte DD-MM-AAAA (configuracao) para AAAA-MM-DD (endpoint)."""
    partes = data.strip().split("-")
    if len(partes) != 3 or len(partes[2]) != 4:
        raise ValueError(f"Data invalida (esperado DD-MM-AAAA): {data!r}")
    dia, mes, ano = partes
    return f"{ano}-{mes}-{dia}"


def parse_iso(data: str) -> date:
    """Converte AAAA-MM-DD para date."""
    return date.fromisoformat(data.strip())


def fim_do_mes(dia: date, meses_adiante: int = 0) -> date:
    """Ultimo dia do mes de dia, deslocado meses_adiante meses."""
    indice = dia.month - 1 + meses_adiante + 1
    primeiro_seguinte = date(dia.year + indice // 12, indice % 12 + 1, 1)
    return primeiro_seguinte - timedelta(days=1)


def dividir_periodo(inicio: date, fim: date, meses: int = MESES_POR_BLOCO) -> list[Periodo]:
    """Blocos de ate `meses` meses de calendario cobrindo inicio..fim sem buracos."""
    blocos: list[Periodo] = []
    atual = inicio
    while atual <= fim:
        ultimo = min(fim_do_mes(atual, meses - 1), fim)
        blocos.append((atual, ultimo))
        atual = ultimo + timedelta(days=1)
    return blocos


def consultar(url: str, token: str, config: dict[str, str], timeout: int) -> tuple[int, str]:
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


def consultar_com_tentativas(
    url: str,
    token: str,
    config: dict[str, str],
    timeout: int,
    tentativas: int,
) -> Any:
    """Consulta com novas tentativas em HTTP 5xx, timeout ou falha de conexao."""
    for tentativa in range(1, tentativas + 1):
        inicio = time.time()
        try:
            status, body = consultar(url, token, config, timeout)
            if status != 200:
                raise RuntimeError(f"HTTP {status}")
            dados = json.loads(body)
            print(f"HTTP {status} em {time.time() - inicio:.0f}s ({len(body) / 1e6:.1f} MB)")
            return dados
        except HTTPError as error:
            detalhe = error.read().decode("utf-8", errors="replace")[:300]
            if error.code < 500 or tentativa == tentativas:
                raise RuntimeError(f"HTTP {error.code}: {detalhe}") from error
            print(f"Tentativa {tentativa}: HTTP {error.code} — nova tentativa em {ESPERA_ENTRE_TENTATIVAS}s")
        except (URLError, TimeoutError) as error:
            if tentativa == tentativas:
                raise RuntimeError(f"Falha de conexao: {error}") from error
            print(f"Tentativa {tentativa}: {error} — nova tentativa em {ESPERA_ENTRE_TENTATIVAS}s")
        time.sleep(ESPERA_ENTRE_TENTATIVAS)
    raise RuntimeError("Sem resposta da API.")


def salvar_json(dados: Any, caminho: Path) -> None:
    """Grava via arquivo temporario para nao deixar JSON truncado."""
    temporario = caminho.with_suffix(".json.tmp")
    temporario.write_text(json.dumps(dados, ensure_ascii=False, indent=2), encoding="utf-8")
    temporario.replace(caminho)


def imprimir_resumo_intervalo(registros: list[dict[str, Any]]) -> None:
    """Contagens rapidas para conferencia no terminal."""
    status = collections.Counter(str(r.get("status_discente")) for r in registros)
    com_disciplinas = sum(1 for r in registros if r.get("disciplinas"))
    com_ausencias = sum(1 for r in registros if r.get("ausencias_especiais"))
    print(f"Vinculos: {len(registros)}")
    print(f"Alunos (logins): {len({r.get('login') for r in registros})}")
    print(f"Vinculos com disciplinas: {com_disciplinas}")
    print(f"Vinculos com ausencias_especiais: {com_ausencias}")
    print("Status:")
    for nome, total in status.most_common():
        print(f"  {total:5d}  {nome}")


def _int(valor: Any) -> int:
    try:
        return int(valor or 0)
    except (TypeError, ValueError):
        return 0


def _chave_vinculo(vinculo: dict[str, Any]) -> tuple[str, str]:
    return (
        str(vinculo.get("login") or "").strip(),
        str(vinculo.get("matricula") or "").strip(),
    )


def _chave_disciplina(disciplina: dict[str, Any]) -> str:
    return str(
        disciplina.get("id_matricula_componente")
        or disciplina.get("cod_disciplina")
        or ""
    ).strip()


def _freq(disciplina: dict[str, Any]) -> dict[str, Any]:
    freq = disciplina.get("frequencia")
    return freq if isinstance(freq, dict) else {}


def _total(vinculo: dict[str, Any]) -> dict[str, Any]:
    total = vinculo.get("total")
    return total if isinstance(total, dict) else {}


def _normalizar_data_aula(valor: Any) -> str | None:
    if valor is None:
        return None
    if isinstance(valor, str):
        texto = valor.strip()
        if len(texto) >= 10 and texto[4] == "-" and texto[7] == "-":
            return texto[:10]
        return None
    if isinstance(valor, dict):
        for chave in ("ultima_aula_ministrada", "data", "data_aula", "data_registro"):
            encontrado = _normalizar_data_aula(valor.get(chave))
            if encontrado is not None:
                return encontrado
    return None


def _max_data_aula(*valores: Any) -> Any:
    """Mantem o valor original cuja data ISO for a mais recente."""
    melhor_valor: Any = None
    melhor_data: str | None = None
    for valor in valores:
        data = _normalizar_data_aula(valor)
        if data is None:
            if melhor_valor is None and valor not in (None, ""):
                melhor_valor = valor
            continue
        if melhor_data is None or data > melhor_data:
            melhor_data = data
            melhor_valor = valor
    return melhor_valor


def _unir_listas(itens: list[Any]) -> list[Any]:
    """Une listas preservando ordem e removendo duplicatas (por JSON)."""
    vistos: set[str] = set()
    saida: list[Any] = []
    for item in itens:
        chave = json.dumps(item, ensure_ascii=False, sort_keys=True, default=str)
        if chave in vistos:
            continue
        vistos.add(chave)
        saida.append(item)
    return saida


def _mesclar_ausencias_especiais(partes: list[dict[str, Any]]) -> dict[str, Any] | None:
    acumulado: dict[str, list[Any]] = {}
    for vinculo in partes:
        especiais = vinculo.get("ausencias_especiais")
        if not isinstance(especiais, dict):
            continue
        for chave, valor in especiais.items():
            if isinstance(valor, list):
                acumulado.setdefault(str(chave), []).extend(valor)
    if not acumulado:
        return None
    return {chave: _unir_listas(valores) for chave, valores in acumulado.items()}


def _mesclar_disciplina(partes: list[dict[str, Any]]) -> dict[str, Any]:
    base = dict(partes[-1])
    horarios = sum(_int(_freq(p).get("horarios")) for p in partes)
    ausencias = sum(_int(_freq(p).get("ausencias")) for p in partes)
    presencas = sum(_int(_freq(p).get("presencas")) for p in partes)
    base["frequencia"] = {
        "horarios": horarios if horarios > 0 else None,
        "ausencias": ausencias if horarios > 0 else None,
        "presencas": presencas if horarios > 0 else None,
        "percentual_frequencia": percentual(presencas, horarios, 2),
    }
    dias: list[Any] = []
    for parte in partes:
        bruto = parte.get("ausencias")
        if isinstance(bruto, list):
            dias.extend(bruto)
    if dias:
        base["ausencias"] = _unir_listas(dias)
    elif "ausencias" in base:
        del base["ausencias"]
    base["ultima_aula_ministrada"] = _max_data_aula(
        *(p.get("ultima_aula_ministrada") for p in partes)
    )
    return base


def _mesclar_total(partes: list[dict[str, Any]]) -> dict[str, Any] | None:
    totais = [_total(p) for p in partes]
    if not any(totais):
        return None
    horarios = sum(_int(t.get("horarios_totais")) for t in totais)
    ausencias = sum(_int(t.get("ausencias_totais")) for t in totais)
    presencas = sum(_int(t.get("presencas_totais")) for t in totais)
    justificadas = sum(
        _int((t.get("frequencia_com_ausencias_justificadas") or {}).get("ausencias_justificadas_totais"))
        for t in totais
        if isinstance(t.get("frequencia_com_ausencias_justificadas") or {}, dict)
    )
    carga = None
    for total in reversed(totais):
        if total.get("carga_horaria_total") is not None:
            carga = total.get("carga_horaria_total")
            break
    return {
        "carga_horaria_total": carga,
        "horarios_totais": horarios if horarios > 0 else None,
        "ausencias_totais": ausencias if horarios > 0 else None,
        "presencas_totais": presencas if horarios > 0 else None,
        "percentual_frequencia_total": percentual(presencas, horarios, 0),
        "frequencia_com_ausencias_justificadas": {
            "horarios_totais": horarios if horarios > 0 else None,
            "ausencias_justificadas_totais": justificadas if horarios > 0 else None,
            "ausencias_totais": ausencias if horarios > 0 else None,
            "presencas_totais": presencas if horarios > 0 else None,
            "percentual_frequencia_total": percentual(
                horarios - (ausencias - justificadas), horarios, 0
            ),
        },
    }


def fundir_vinculo(
    partes: list[dict[str, Any]],
    data_inicial: str,
    data_final: str,
) -> dict[str, Any]:
    """Junta blocos mensais de um mesmo vinculo no intervalo completo."""
    base = dict(partes[-1])
    base["frequencia_data_inicial"] = data_inicial
    base["frequencia_data_final"] = data_final

    por_disciplina: dict[str, list[dict[str, Any]]] = {}
    ordem: list[str] = []
    for parte in partes:
        for disciplina in parte.get("disciplinas") or []:
            if not isinstance(disciplina, dict):
                continue
            chave = _chave_disciplina(disciplina)
            if chave == "":
                continue
            if chave not in por_disciplina:
                por_disciplina[chave] = []
                ordem.append(chave)
            por_disciplina[chave].append(disciplina)
    base["disciplinas"] = [_mesclar_disciplina(por_disciplina[chave]) for chave in ordem]

    total = _mesclar_total(partes)
    if total is None:
        base.pop("total", None)
    else:
        base["total"] = total

    especiais = _mesclar_ausencias_especiais(partes)
    if especiais is None:
        base.pop("ausencias_especiais", None)
    else:
        base["ausencias_especiais"] = especiais

    return base


def fundir_vinculos(
    blocos: list[list[dict[str, Any]]],
    data_inicial: str,
    data_final: str,
) -> list[dict[str, Any]]:
    """Fundir listas mensais de vinculos no periodo completo."""
    por_chave: dict[tuple[str, str], list[dict[str, Any]]] = {}
    ordem: list[tuple[str, str]] = []
    for bloco in blocos:
        for vinculo in bloco:
            if not isinstance(vinculo, dict):
                continue
            chave = _chave_vinculo(vinculo)
            if chave[0] == "":
                continue
            if chave not in por_chave:
                por_chave[chave] = []
                ordem.append(chave)
            por_chave[chave].append(vinculo)
    return [fundir_vinculo(por_chave[chave], data_inicial, data_final) for chave in ordem]


def consultar_cadastro(token: str, config: dict[str, str], tentativas: int) -> None:
    """Cadastro de todos os alunos → resposta_alunos_massa_cadastro.json."""
    url = url_cadastro(config)
    print("Consulta em massa — cadastro de alunos")
    print(f"URL: {url}")
    cadastro = consultar_com_tentativas(url, token, config, TIMEOUT_CADASTRO_SEGUNDOS, tentativas)
    if not isinstance(cadastro, dict):
        raise ValueError("Resposta do cadastro inesperada (esperava mapa login → aluno).")
    salvar_json(cadastro, JSON_RESPOSTA_ALUNOS_MASSA_CADASTRO)
    print(f"Registros: {len(cadastro)}")
    print(f"Salvo em: {JSON_RESPOSTA_ALUNOS_MASSA_CADASTRO}")


def consultar_intervalo_status(
    status: str,
    inicio: date,
    fim: date,
    token: str,
    config: dict[str, str],
    timeout: int,
    tentativas: int,
) -> list[dict[str, Any]]:
    """Consulta um status mes a mes e devolve vinculos fundidos no periodo."""
    data_inicial = inicio.isoformat()
    data_final = fim.isoformat()
    blocos_periodo = dividir_periodo(inicio, fim, meses=MESES_POR_BLOCO)
    print(
        f"Status {status}: {len(blocos_periodo)} bloco(s) de ate {MESES_POR_BLOCO} mes(es) "
        f"({inicio:%d/%m/%Y} a {fim:%d/%m/%Y})"
    )
    partes: list[list[dict[str, Any]]] = []
    for bloco_inicio, bloco_fim in blocos_periodo:
        url = url_intervalo(config, bloco_inicio.isoformat(), bloco_fim.isoformat())
        url = url + ("&" if "?" in url else "?") + f"status={status}"
        print(f"URL: {url}")
        marca = time.time()
        parte = consultar_com_tentativas(url, token, config, timeout, tentativas)
        if not isinstance(parte, list):
            raise ValueError(
                f"Resposta do intervalo ({status} {bloco_inicio}..{bloco_fim}) "
                "inesperada (esperava lista de vinculos)."
            )
        if time.time() - marca > ALERTA_SEGUNDOS:
            print(
                f"Atencao: a consulta {status} {bloco_inicio:%d/%m/%Y}–{bloco_fim:%d/%m/%Y} "
                f"passou de {ALERTA_SEGUNDOS}s; perto do limite de 60 s do Cloudflare (HTTP 504)."
            )
        partes.append([v for v in parte if isinstance(v, dict)])
    return fundir_vinculos(partes, data_inicial, data_final)


def consultar_intervalo(
    token: str,
    config: dict[str, str],
    args: argparse.Namespace,
    tentativas: int,
) -> None:
    """Frequencia por intervalo (ATIVO e FORMANDO) → resposta_alunos_massa_intervalo.json."""
    data_inicial = data_iso(args.data_inicial or frequencia_data_inicial())
    data_final = data_iso(args.data_final or frequencia_data_final())
    inicio = parse_iso(data_inicial)
    fim = parse_iso(data_final)
    if fim < inicio:
        raise ValueError(f"Periodo invalido: {data_inicial} a {data_final}")

    print("Consulta em massa — frequencia por intervalo (blocos mensais)")
    vinculos: list[Any] = []
    for status in STATUS_INTERVALO:
        vinculos.extend(
            consultar_intervalo_status(
                status,
                inicio,
                fim,
                token,
                config,
                args.timeout,
                tentativas,
            )
        )
    salvar_json(vinculos, JSON_RESPOSTA_ALUNOS_MASSA)
    imprimir_resumo_intervalo(vinculos)
    print(f"Salvo em: {JSON_RESPOSTA_ALUNOS_MASSA}")


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    """Argumentos da linha de comando."""
    parser = argparse.ArgumentParser(
        description="Consulta em massa (cadastro + frequencia por intervalo)."
    )
    parser.add_argument("--data-inicial", default=None, help="DD-MM-AAAA (padrao: configuracao)")
    parser.add_argument("--data-final", default=None, help="DD-MM-AAAA (padrao: configuracao)")
    parser.add_argument("--timeout", type=int, default=TIMEOUT_SEGUNDOS)
    parser.add_argument("--tentativas", type=int, default=TENTATIVAS)
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    """Ponto de entrada."""
    args = parse_args(argv)
    garantir_diretorios()
    tentativas = max(1, args.tentativas)

    try:
        config = carregar_config_api()
        if not verificar_ssl(config):
            print("Aviso: verificacao SSL desativada (api_verify_ssl=false).")
        token = obter_access_token(config)
    except (ValueError, RuntimeError) as error:
        print(f"Erro: {error}", file=sys.stderr)
        return 1

    falhas = 0
    for nome, consulta, arquivo in (
        ("cadastro", lambda: consultar_cadastro(token, config, tentativas),
         JSON_RESPOSTA_ALUNOS_MASSA_CADASTRO),
        ("intervalo", lambda: consultar_intervalo(token, config, args, tentativas),
         JSON_RESPOSTA_ALUNOS_MASSA),
    ):
        try:
            consulta()
        except (ValueError, RuntimeError, json.JSONDecodeError) as error:
            falhas += 1
            print(f"Erro no {nome}: {error}", file=sys.stderr)
            print(f"Arquivo anterior mantido: {arquivo}", file=sys.stderr)

    return 1 if falhas else 0


if __name__ == "__main__":
    raise SystemExit(main())
