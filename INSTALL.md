# Instalação do MAPA

## Requisitos

### PHP

- **PHP 8.0 ou superior**
- Extensão **PDO SQLite** (`pdo_sqlite`) — obrigatória
- Extensão **LDAP** (`ldap`) — só se for usar login LDAP

Verificar:

```bash
php -v
php -m | grep -i sqlite
# Precisa listar pdo_sqlite (e em geral sqlite3)
```

Se faltar no Ubuntu/Debian:

```bash
sudo apt install php-sqlite3
# ou, alinhado à versão do PHP do Apache, ex.:
# sudo apt install php8.3-sqlite3
sudo systemctl reload apache2   # só em produção com Apache
```

No macOS (Homebrew), o PHP costuma já trazer SQLite; se não:

```bash
brew install php
```

### Python

- **Python 3.9 ou superior** (coleta SIGAA)
- **Sem módulos extras** (`pip install` não é necessário): usa só a biblioteca padrão (`sqlite3`, `urllib`, etc.)
- O binário `php` precisa estar no `PATH` (a coleta chama o script de e-mail ao final)

### Servidor

- Desenvolvimento: `php -S` basta — ver [docs/servidor-local.md](docs/servidor-local.md)
- Produção: Apache — ver [docs/servidor-apache.md](docs/servidor-apache.md)

---

## Passos

### 1. Clonar

```bash
git clone <url-do-repositorio> mapa
cd mapa
```

### 2. Criar o `.env`

O `.env` **não** vai no Git (contém opções locais). Use o modelo:

```bash
cp .env.example .env
```

| Ambiente | O que fazer |
|----------|-------------|
| Desenvolvimento | Em geral basta o padrão (`EMAIL_SEND=false`) |
| Produção | Altere para `EMAIL_SEND=true` |

Sem `EMAIL_SEND=true`, nenhum e-mail é enviado — mesmo com SMTP ligado no portal.

A pasta `data/` também **não** vai no Git (só o `.gitkeep`). Na primeira conexão o sistema cria `data/` (se faltar) e o arquivo SQLite em `DB_PATH`, aplica o schema e cria o usuário `admin` sem senha.

Piloto de avisos ao staff (opcional), no `.env`:

```env
EMAIL_ALARMES_STAFF_APENAS=seu.email@instituicao.edu.br
```

Com essa variável, só esse endereço recebe avisos — e apenas sobre alunos das disciplinas/cursos em que ele atua. Demais professores e coordenadores não recebem nada.

Ao remover ou comentar a variável, na próxima execução do fluxo staff (quarta-feira, se configurado) **todos os contatos ainda pendentes** — inclusive os do período piloto — serão comunicados à equipe completa.

Periodicidade do staff (alunos não têm dia fixo — só a regra de 7 dias):

```env
EMAIL_ALARMES_DIA_STAFF=quarta
```

Enquanto não for `todos`, avisos ao staff só saem no dia indicado (fuso `America/Sao_Paulo`).

### 3. Subir o portal e definir a senha do admin

```bash
# desenvolvimento
php -S localhost:8080
# abra http://localhost:8080/index.php
```

Na primeira visita o sistema cria o usuário `admin` sem senha e pede a senha em `/setup`.

Em produção, garanta que o usuário do Apache possa **gravar** em `data/`:

```bash
sudo chown -R www-data:www-data /var/www/mapa/data
sudo chmod 775 /var/www/mapa/data
```

### 4. Configurar no portal (como admin)

Gravado no banco (não no `.env`):

1. **Configurações → API** — OAuth/SIGAA (necessário para a coleta)
2. **Configurações → LDAP** — opcional
3. **Configurações → E-mail** — SMTP + interruptor (só envia se `EMAIL_SEND=true`)

### 5. Rodar a coleta

Manual:

```bash
python3 python/executar_coleta.py
```

Cron (a cada hora), com caminhos absolutos:

```cron
0 * * * * /usr/bin/python3 /var/www/mapa/python/executar_coleta.py > /var/www/mapa/data/coleta.log 2>&1
```

Ajuste `/var/www/mapa` e o caminho do `python3` conforme o servidor.  
Pipeline (ordem e arquivos): [python/README.md](python/README.md).

---

## Checklist (produção)

- [ ] PHP ≥ 8.0 com `pdo_sqlite` (pacote `php-sqlite3` no Ubuntu, se necessário)
- [ ] Python ≥ 3.9 (sem pip)
- [ ] `EMAIL_SEND=true` no `.env` do servidor
- [ ] `data/` gravável pelo servidor web
- [ ] Senha do `admin` definida em `/setup`
- [ ] API SIGAA configurada
- [ ] E-mail/SMTP (se for usar avisos)
- [ ] Cron da coleta
