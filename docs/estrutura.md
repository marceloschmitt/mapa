# Estrutura do projeto MAPA

```text
mapa/
├── index.php              # Entrada do portal PHP
├── logo.png               # Logo (DocumentRoot; login/setup)
├── INSTALL.md             # Como instalar
├── .env                   # Versionado (sem segredos): DB_PATH, EMAIL_SEND
├── config/                # Configuracao compartilhada (versionada)
│   ├── schema.sql
│   └── consultas.json     # Datas da coleta
├── src/                   # Codigo PHP (MVC)
│   ├── routes.php         # Mapa URL → controller
│   ├── Core/
│   ├── Controllers/
│   ├── Models/
│   ├── Views/
│   └── Strings/           # Textos reutilizados (nao HTML)
│       └── app.php
├── python/                # Pipeline de coleta — ver python/README.md
├── scripts/               # CLI PHP (ex.: e-mails de chamadas)
├── data/                  # Pasta versionada (.gitkeep); conteudo local
│   ├── mapa.db            # SQLite (nao versionado)
│   └── json/              # Cache da coleta (nao versionado)
└── docs/
```

| Pasta | Conteudo |
|-------|----------|
| `src/` + `index.php` | Portal PHP |
| `python/` | Coleta SIGAA → JSON → SQLite — [`python/README.md`](../python/README.md) |
| `data/` | Pasta no Git (vazia); BD/JSON/logs **fora** do Git |
| `config/` | Schema e datas — versionado |
| `docs/` | Documentacao complementar ao [INSTALL.md](../INSTALL.md) |

LDAP e API SIGAA ficam no banco (`/configuracoes/ldap`, `/configuracoes/api`), nao no `.env`.
