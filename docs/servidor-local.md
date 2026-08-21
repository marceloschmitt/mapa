# Servidor local (Mac)

Requisitos e instalação completa: [INSTALL.md](../INSTALL.md).

Na raiz do projeto:

```bash
php -v                    # >= 8.0
php -m | grep -i sqlite   # precisa de pdo_sqlite
php -S localhost:8080
```

| Página | URL |
|--------|-----|
| Início | http://localhost:8080/index.php |
| Setup (1ª vez) | http://localhost:8080/index.php/setup |
| Login | http://localhost:8080/index.php/login |

Na primeira visita, se o banco ainda não tiver senha de admin, o portal redireciona para `/setup`.
