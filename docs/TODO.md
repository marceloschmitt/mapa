# Coisas para fazer

## Usuários e professores

- [ ] Unificar modelo: **todo professor deve ser um usuário**.
- [ ] O **e-mail** fica vinculado ao **usuário** (`usuarios.email`), não à tabela `professores`.
- [ ] Ajustar coleta, vínculos de disciplina e e-mails automáticos de chamadas para usar o e-mail do usuário (via CPF ou FK).
- [ ] Remover ou reduzir duplicação de e-mail entre `professores` e `usuarios` (hoje há sincronização pelo CPF).

## GitHub e `.env`

- [x] `.env` **fora** do Git (`.gitignore`); modelo em `.env.example` (`DB_PATH`, `EMAIL_SEND`, opções de alarmes).
- [x] Documentar `EMAIL_SEND=true` em produção — ver `INSTALL.md`.
- [x] Primeira instalação: admin sem senha; definição em `/setup`.
- [x] Segredos (API, LDAP, SMTP) ficam no BD (`configuracoes`), não no código.

## Pasta `data/`

- [x] A pasta `data/` **não entra no GitHub** (banco, JSON da coleta, CSV, logs) — `.gitignore` ignora `/data/*` e mantém `.gitkeep`.
- [x] Pasta `data/` versionada vazia (`.gitkeep`); conteúdo (BD, JSON, logs) fora do Git e gerado na execução.
