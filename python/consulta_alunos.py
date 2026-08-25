#!/usr/bin/env python3
"""Segunda consulta ao webservice SIGAA (detalhes por aluno).

Le os logins salvos por consulta_inicial.py (resposta_matriculas.json), consulta o
endpoint de alunos para cada login (em paralelo) e salva o resultado
consolidado em resposta_alunos.json.

Uso:
    python3 consulta_alunos.py
"""

from __future__ import annotations

import json
import sys
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

from config_consultas import frequencia_data_final, frequencia_data_inicial
from api_auth import (
    USER_AGENT,
    carregar_config_api,
    obter_access_token,
    ssl_context,
    url_alunos,
    verificar_ssl,
)
from db import fechar
from paths import (
    JSON_ERROS_ALUNOS,
    JSON_RESPOSTA_ALUNOS,
    JSON_RESPOSTA_MATRICULAS,
    garantir_diretorios,
)
from status_aluno import (
    status_eh_controle,
    status_eh_trancado,
    status_vai_segunda_consulta,
)

# Arquivos em data/json/
ARQUIVO_ENTRADA = JSON_RESPOSTA_MATRICULAS
ARQUIVO_SAIDA = JSON_RESPOSTA_ALUNOS
ARQUIVO_ERROS = JSON_ERROS_ALUNOS

# Numero de requisicoes simultaneas.
CONCORRENCIA = 50

# Tempo maximo de espera por requisicao (segundos).
TIMEOUT_SEGUNDOS = 120

# Tentativas por aluno em caso de timeout ou falha de conexao.
TENTATIVAS = 3

# Quantidade maxima de alunos a consultar. None consulta todos.
LIMITE_CONSULTAS = None

# Preenchidos em main() a partir do banco / OAuth.
API_URL_ALUNOS_BASE = ""
API_TOKEN = ""
_CONFIG: dict[str, str] = {}


def consultar_webservice(url: str, token: str, timeout: int = TIMEOUT_SEGUNDOS) -> tuple[int, str]:
    """Executa uma requisicao GET autenticada ao webservice.

    Monta a requisicao HTTP com cabecalhos Accept e Authorization (Bearer),
    respeita a configuracao VERIFY_SSL e aguarda resposta pelo tempo limite.

    Args:
        url: URL completa do endpoint a consultar.
        token: Token JWT para autenticacao Bearer.
        timeout: Tempo maximo de espera em segundos.

    Returns:
        Tupla (status_http, corpo_resposta), onde corpo_resposta e texto UTF-8.

    Raises:
        HTTPError: Quando o servidor retorna codigo 4xx ou 5xx.
        URLError: Quando ha falha de conexao ou SSL.
        TimeoutError: Quando a requisicao excede o tempo limite.
    """
    request = Request(
        url,
        headers={
            "Accept": "application/json",
            "Authorization": f"Bearer {token}",
            "User-Agent": USER_AGENT,
        },
        method="GET",
    )

    context = ssl_context(_CONFIG)

    with urlopen(request, timeout=timeout, context=context) as response:
        status = response.getcode()
        body = response.read().decode("utf-8")

    return status, body


def montar_url_alunos(login: str) -> str:
    """Monta a URL de consulta de aluno com datas do config/consultas.json.

    Args:
        login: Login do aluno.

    Returns:
        URL completa para consulta de frequencia.
    """
    return (
        f"{API_URL_ALUNOS_BASE}"
        f"&frequencia_data_inicial={frequencia_data_inicial()}"
        f"&frequencia_data_final={frequencia_data_final()}"
    ).format(login=login)


def carregar_logins(caminho: Path) -> tuple[list[dict[str, Any]], dict[str, int]]:
    """Le matriculados e seleciona quem vai à 2ª consulta.

    Inclui ATIVO, FORMANDO e trancados da 1ª consulta (um login por pessoa).
    Cancelados, concluídos etc. da 1ª não são consultados de novo.

    Aceita:
      - lista plana (extracao Nome/Login/Matricula/Email);
      - dicionario paginado com chave 'data';
      - mapa completo matricula -> registro (com disciplinas/docentes).

    Args:
        caminho: Caminho do arquivo JSON gerado por consulta_inicial.py.

    Returns:
        Tupla (lista normalizada Login/Nome/Matricula/Email, contagens).

    Raises:
        FileNotFoundError: Quando o arquivo de entrada nao existe.
        ValueError: Quando o conteudo nao e uma lista de alunos valida.
    """
    dados = json.loads(caminho.read_text(encoding="utf-8"))

    if isinstance(dados, dict) and isinstance(dados.get("data"), list):
        dados = dados["data"]
    elif isinstance(dados, dict):
        dados = list(dados.values())

    if not isinstance(dados, list):
        raise ValueError("Formato inesperado: esperava lista ou mapa de alunos.")

    por_login: dict[str, dict[str, Any]] = {}
    total_entrada = 0
    elegiveis = 0
    ignorados = 0

    for aluno in dados:
        if not isinstance(aluno, dict):
            continue
        total_entrada += 1
        status = str(aluno.get("status") or aluno.get("Status") or "").strip()
        if not status_vai_segunda_consulta(status):
            ignorados += 1
            continue

        login = str(aluno.get("Login") or aluno.get("login") or "").strip()
        if login == "":
            ignorados += 1
            continue

        elegiveis += 1
        nome = (
            aluno.get("Nome")
            or aluno.get("nome_completo")
            or aluno.get("nome")
            or ""
        )
        matricula = aluno.get("Matricula") or aluno.get("matricula") or ""
        email = aluno.get("Email") or aluno.get("email") or ""
        novo = {
            "Login": login,
            "Nome": nome,
            "Matricula": matricula,
            "Email": email,
            "Status": status,
        }
        atual = por_login.get(login)
        # Preferir vínculo de controle se o mesmo login também vier trancado.
        if atual is None or (
            status_eh_controle(status)
            and status_eh_trancado(str(atual.get("Status") or ""))
        ):
            por_login[login] = novo

    contagens = {
        "entrada": total_entrada,
        "elegiveis": elegiveis,
        "ignorados": ignorados,
        "consultas": len(por_login),
    }
    return list(por_login.values()), contagens


def eh_erro_http_temporario(status: int) -> bool:
    """Indica se o codigo HTTP pode ser resolvido com nova tentativa."""
    return status >= 500


def eh_erro_temporario(erro: str) -> bool:
    """Indica se o erro pode ser resolvido com nova tentativa."""
    texto = erro.lower()
    return (
        "tempo limite" in texto
        or "timed out" in texto
        or "falha de conexao" in texto
    )


def descrever_resultado(resultado: dict[str, Any]) -> str:
    """Monta texto curto do status para exibicao no terminal."""
    if resultado.get("status") == 200:
        return "200"
    if "erro" in resultado:
        erro = str(resultado["erro"]).strip()
        status = resultado.get("status")
        try:
            parsed = json.loads(erro)
            if isinstance(parsed, dict) and "message" in parsed:
                mensagem = str(parsed["message"])
                return f"HTTP {status}: {mensagem}" if status else mensagem
        except json.JSONDecodeError:
            pass
        if len(erro) > 80:
            erro = erro[:77] + "..."
        if status:
            return f"HTTP {status}: {erro}"
        return f"erro: {erro}"
    return str(resultado.get("status", "erro"))


def resumir_falhas(resultados: list[dict[str, Any]]) -> dict[str, int]:
    """Agrupa falhas por tipo de erro."""
    resumo: dict[str, int] = {}
    for resultado in resultados:
        if resultado.get("status") == 200:
            continue
        chave = str(resultado.get("erro", f"HTTP {resultado.get('status', 'desconhecido')}"))
        resumo[chave] = resumo.get(chave, 0) + 1
    return resumo


def consultar_aluno(aluno: dict[str, Any]) -> dict[str, Any]:
    """Consulta o endpoint de alunos para um unico aluno.

    Substitui {login} no modelo de URL e interpreta a resposta como JSON
    quando possivel. Erros de rede ou HTTP sao capturados e devolvidos no
    proprio resultado, para nao interromper as demais consultas. Em caso de
    timeout ou falha de conexao, tenta novamente ate TENTATIVAS vezes.

    Args:
        aluno: Dicionario do aluno, deve conter a chave 'Login'.

    Returns:
        Dicionario com login, nome, status HTTP e os dados (ou o erro).
    """
    login = str(aluno.get("Login") or aluno.get("login") or "").strip()
    resultado: dict[str, Any] = {
        "login": login,
        "nome": aluno.get("Nome") or aluno.get("nome_completo") or aluno.get("nome"),
        "matricula": aluno.get("Matricula") or aluno.get("matricula"),
    }

    if login == "":
        resultado["erro"] = "Aluno sem login."
        return resultado

    url = montar_url_alunos(login)
    ultimo_erro = ""

    for tentativa in range(1, TENTATIVAS + 1):
        try:
            status, body = consultar_webservice(url, API_TOKEN)
        except HTTPError as error:
            corpo = error.read().decode("utf-8", errors="replace")
            ultimo_erro = corpo
            resultado["status"] = error.code
            if tentativa < TENTATIVAS and eh_erro_http_temporario(error.code):
                continue
            resultado["erro"] = corpo
            resultado["tentativas"] = tentativa
            return resultado
        except URLError as error:
            ultimo_erro = f"Falha de conexao: {error.reason}"
            if tentativa < TENTATIVAS and eh_erro_temporario(ultimo_erro):
                continue
            resultado["erro"] = ultimo_erro
            resultado["tentativas"] = tentativa
            return resultado
        except TimeoutError:
            ultimo_erro = f"Tempo limite excedido ({TIMEOUT_SEGUNDOS}s)."
            if tentativa < TENTATIVAS:
                continue
            resultado["erro"] = ultimo_erro
            resultado["tentativas"] = tentativa
            return resultado
        else:
            resultado["status"] = status
            resultado["tentativas"] = tentativa
            try:
                resultado["dados"] = json.loads(body)
            except json.JSONDecodeError:
                resultado["dados_brutos"] = body
            return resultado

    resultado["erro"] = ultimo_erro or "Falha desconhecida."
    resultado["tentativas"] = TENTATIVAS
    return resultado


def main() -> int:
    """Ponto de entrada do script.

    Carrega os logins do arquivo de entrada, consulta o endpoint de alunos
    em paralelo (respeitando LIMITE_CONSULTAS e CONCORRENCIA), salva os
    resultados em ARQUIVO_SAIDA e imprime um resumo no terminal.

    Returns:
        0 em caso de sucesso, 1 em caso de erro.
    """
    global API_URL_ALUNOS_BASE, API_TOKEN, _CONFIG

    try:
        _CONFIG = carregar_config_api()
        API_URL_ALUNOS_BASE = url_alunos(_CONFIG)
    except ValueError as error:
        print(f"Erro de configuracao: {error}", file=sys.stderr)
        return 1

    if not verificar_ssl(_CONFIG):
        print("Aviso: verificacao SSL desativada (api_verify_ssl=false).")

    try:
        API_TOKEN = obter_access_token(_CONFIG)
        print("Access token OAuth obtido.")
    except (ValueError, RuntimeError) as error:
        print(f"Erro de autenticacao: {error}", file=sys.stderr)
        return 1

    try:
        alunos, contagens = carregar_logins(ARQUIVO_ENTRADA)
    except FileNotFoundError:
        print(f"Erro: arquivo nao encontrado: {ARQUIVO_ENTRADA}", file=sys.stderr)
        print("Rode antes: python3 consulta_inicial.py", file=sys.stderr)
        return 1
    except (ValueError, json.JSONDecodeError) as error:
        print(f"Erro ao ler entrada: {error}", file=sys.stderr)
        return 1

    print(
        "Filtro 1ª→2ª consulta (ATIVO, FORMANDO, trancados): "
        f"{contagens['entrada']} na 1ª, "
        f"{contagens['elegiveis']} elegíveis, "
        f"{contagens['ignorados']} ignorados, "
        f"{contagens['consultas']} login(s) únicos."
    )

    if LIMITE_CONSULTAS is not None:
        alunos = alunos[:LIMITE_CONSULTAS]

    total = len(alunos)
    print(
        f"Consultando {total} aluno(s) com concorrencia {CONCORRENCIA}, "
        f"timeout {TIMEOUT_SEGUNDOS}s, ate {TENTATIVAS} tentativa(s)..."
    )

    resultados: list[dict[str, Any]] = []
    sucessos = 0
    falhas = 0

    with ThreadPoolExecutor(max_workers=CONCORRENCIA) as executor:
        futuros = {executor.submit(consultar_aluno, aluno): aluno for aluno in alunos}
        for futuro in as_completed(futuros):
            resultado = futuro.result()
            resultados.append(resultado)
            if resultado.get("status") == 200:
                sucessos += 1
            else:
                falhas += 1
                print(
                    f"  ERRO login {resultado.get('login')} -> "
                    f"{descrever_resultado(resultado)}",
                    flush=True,
                )

    erros = [item for item in resultados if item.get("status") != 200]
    garantir_diretorios()
    ARQUIVO_SAIDA.write_text(
        json.dumps(resultados, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    ARQUIVO_ERROS.write_text(
        json.dumps(erros, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )

    print()
    print(f"Concluido: {sucessos} sucesso(s), {falhas} falha(s).")
    print(f"Resposta completa salva em: {ARQUIVO_SAIDA}")

    if erros:
        print(f"Falhas detalhadas salvas em: {ARQUIVO_ERROS}")
        print("Resumo das falhas:")
        for tipo_erro, quantidade in sorted(
            resumir_falhas(resultados).items(),
            key=lambda item: item[1],
            reverse=True,
        ):
            print(f"  - {quantidade}x {tipo_erro}")

    fechar()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
