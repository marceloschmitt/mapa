# Servidor Apache (produção)

Instalação geral (PHP, `.env`, setup, coleta): [INSTALL.md](../INSTALL.md).

Não use `.htaccess` nem `mod_rewrite`.

O Apache aponta o DocumentRoot para a pasta do MAPA e serve `index.php`
como página padrão (`DirectoryIndex index.php`).

## Requisitos no servidor

- PHP **8.0+** com `pdo_sqlite` (Ubuntu: pacote `php-sqlite3` ou `php8.x-sqlite3`)
- Extensão `ldap` só se for login LDAP
- Pasta `data/` gravável pelo usuário do Apache

```bash
php -m | grep -i sqlite
# Se nao listar pdo_sqlite:

sudo apt install php-sqlite3
sudo systemctl reload apache2
```

## URLs

Todas as rotas passam por `index.php`:

| Rota | URL |
|------|-----|
| Início | `/` ou `/index.php` |
| Setup (1ª vez) | `/index.php/setup` |
| Login | `/index.php/login` |
| Alarmes | `/index.php/alarmes` |
| Analytics | `/index.php/analytics` |

O PHP lê o caminho após `index.php` (`PATH_INFO` ou a própria URI).

## VirtualHost mínimo

```apache
<VirtualHost *:443>
    ServerName mapa.exemplo.edu.br
    DocumentRoot /var/www/mapa
    DirectoryIndex index.php

    <Directory /var/www/mapa>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
        AcceptPathInfo On
    </Directory>
</VirtualHost>
```

`AcceptPathInfo On` permite `/index.php/login` sem rewrite.

## Permissões do banco

O Apache precisa gravar o `.db` e os arquivos `-wal`/`-shm`/`-journal` em `data/`:

```bash
sudo chown -R www-data:www-data /var/www/mapa/data
sudo chmod 775 /var/www/mapa/data
```

Se o erro for `attempt to write a readonly database`, revise dono/permissão de `data/` (e de `mapa.db`, se já existir).

## E-mail automático

No `.env` **do servidor**: `EMAIL_SEND=true` (detalhes em [INSTALL.md](../INSTALL.md)).
