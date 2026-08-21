# MAPA

Monitor de Acompanhamento da Permanência Acadêmica — portal PHP + coleta Python (SIGAA).

## Instalação

Veja **[INSTALL.md](INSTALL.md)** (clone, `.env`, `EMAIL_SEND` em produção, primeiro login, API e coleta).

## O que vai / não vai no Git

| Versionado | Local apenas |
|------------|--------------|
| `src/`, `python/`, `config/`, `docs/`, `.env.example` | `.env` (copie do exemplo) |
| `schema.sql`, `consultas.json`, `data/.gitkeep` | `data/mapa.db`, `data/json/*`, CSV, logs |

## Documentação

- [Instalação](INSTALL.md)
- [Estrutura](docs/estrutura.md)
- [Banco de dados](docs/banco-de-dados.md)
- [Servidor local](docs/servidor-local.md) / [Apache](docs/servidor-apache.md)
- [Pipeline Python](python/README.md)
