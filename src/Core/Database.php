<?php
declare(strict_types=1);

namespace Mapa\Core;

use PDO;
use PDOException;

class Database
{
    /** @var PDO|null */
    private static $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $dbPath = Env::get('DB_PATH', 'data/mapa.db');

        $directory = dirname($dbPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        try {
            self::$connection = new PDO('sqlite:' . $dbPath);
        } catch (PDOException $exception) {
            throw new PDOException(
                'Não foi possível conectar ao SQLite: ' . $exception->getMessage(),
                (int)$exception->getCode(),
                $exception
            );
        }

        self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        self::$connection->exec('PRAGMA foreign_keys = ON');

        self::migrate();

        return self::$connection;
    }

    private static function migrate(): void
    {
        $schemaPath = 'config/schema.sql';
        if (!is_file($schemaPath)) {
            return;
        }

        $sql = file_get_contents($schemaPath);
        if ($sql === false || trim($sql) === '') {
            return;
        }

        $lines = preg_split('/\R/', $sql) ?: [];
        $cleaned = [];
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if (strpos($trimmed, '--') === 0) {
                continue;
            }
            $cleaned[] = $line;
        }

        $statements = array_filter(
            array_map('trim', explode(';', implode("\n", $cleaned))),
            static function (string $statement): bool {
                return $statement !== '';
            }
        );

        foreach ($statements as $statement) {
            self::$connection->exec($statement);
        }

        self::ensureColumn('alunos', 'nome_social', 'TEXT');
        self::migrateUsuariosTable();
        self::ensureColumn('usuarios', 'auth_type', "TEXT NOT NULL DEFAULT 'local'");
        self::ensureColumn('usuarios', 'cpf', 'TEXT');
        self::ensureColumn('alarmes', 'contato_tipo', 'TEXT');
        self::ensureColumn('alarme_emails', 'staff_avisado_em', 'TEXT');
        self::ensureColumn('alarme_emails', 'staff_piloto_avisado_em', 'TEXT');
        self::ensureColumn('disciplina_grade', 'semestre_oferta', 'TEXT');
        // Coluna legada datas_aula (CSV): mantida em bancos antigos; novas usam disciplina_aulas.
        self::ensureColumn('disciplina_grade', 'datas_aula', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn('cursos', 'curso_nivel', 'TEXT');
        self::ensureColumn('professores', 'email', 'TEXT');
        self::ensureColumn('frequencia_curso', 'data_inicio_aulas', 'TEXT');
        self::ensureColumn('perda_vaga_candidatos', 'matriculado_periodo_atual', 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn('perda_vaga_candidatos', 'status_periodo_atual', 'TEXT');
        self::migrarDatasAulaCsvParaTabela();
        self::seedAdminIfEmpty();
        self::migrateLdapConfigFromEnv();
        self::migrateApiConfigFromEnv();
        self::migrateEmailConfigDefaults();
        self::migrateAlarmesContatoEmailAutomatico();
    }

    /**
     * Garante chaves LDAP na tabela configuracoes.
     * Valores legados no .env (LDAP_*) sao copiados uma vez se a chave estiver vazia.
     */
    private static function migrateLdapConfigFromEnv(): void
    {
        $map = [
            'ldap_host' => ['env' => 'LDAP_HOST', 'default' => '', 'descricao' => 'Endereço do servidor LDAP'],
            'ldap_base_dn' => ['env' => 'LDAP_BASE_DN', 'default' => '', 'descricao' => 'Base DN para busca de usuários'],
            'ldap_bind_dn' => ['env' => 'LDAP_BIND_DN', 'default' => '', 'descricao' => 'DN para bind administrativo (opcional)'],
            'ldap_bind_password' => ['env' => 'LDAP_BIND_PASSWORD', 'default' => '', 'descricao' => 'Senha do bind administrativo LDAP'],
            'ldap_user_attribute' => [
                'env' => 'LDAP_USER_ATTRIBUTE',
                'default' => 'sAMAccountName',
                'descricao' => 'Atributo usado para buscar usuários',
            ],
        ];

        $select = self::$connection->prepare(
            'SELECT valor FROM configuracoes WHERE chave = :chave LIMIT 1'
        );
        $upsert = self::$connection->prepare(
            'INSERT INTO configuracoes (chave, valor, descricao, atualizado_em)
             VALUES (:chave, :valor, :descricao, datetime(\'now\'))
             ON CONFLICT(chave) DO UPDATE SET
                valor = excluded.valor,
                descricao = COALESCE(excluded.descricao, configuracoes.descricao),
                atualizado_em = datetime(\'now\')
             WHERE TRIM(configuracoes.valor) = \'\''
        );

        foreach ($map as $chave => $meta) {
            $select->execute(['chave' => $chave]);
            $atual = $select->fetchColumn();
            $existe = $atual !== false;
            $atual = $existe ? trim((string)$atual) : '';

            if ($existe && $atual !== '') {
                continue;
            }

            $valor = trim(Env::get($meta['env'], ''));
            if ($valor === '') {
                $valor = $meta['default'];
            }

            if (!$existe || $atual === '') {
                $upsert->execute([
                    'chave' => $chave,
                    'valor' => $valor,
                    'descricao' => $meta['descricao'],
                ]);
            }
        }
    }

    /**
     * Garante chaves da API na tabela configuracoes.
     * Valores legados no .env (API_*) sao copiados uma vez se a chave estiver vazia.
     */
    private static function migrateApiConfigFromEnv(): void
    {
        $map = [
            'api_oauth_url' => [
                'env' => 'API_OAUTH_URL',
                'default' => '',
                'descricao' => 'URL OAuth token da API SIGAA',
            ],
            'api_client_id' => [
                'env' => 'API_CLIENT_ID',
                'default' => '',
                'descricao' => 'Client ID OAuth da API SIGAA',
            ],
            'api_client_secret' => [
                'env' => 'API_CLIENT_SECRET',
                'default' => '',
                'descricao' => 'Client Secret OAuth da API SIGAA',
            ],
            'api_url_matriculados' => [
                'env' => 'API_URL_MATRICULADOS',
                'default' => '',
                'descricao' => 'URL da consulta de matriculados',
            ],
            'api_url_alunos' => [
                'env' => 'API_URL_ALUNOS',
                'default' => '',
                'descricao' => 'URL da consulta de alunos (use {login})',
            ],
            'api_verify_ssl' => [
                'env' => 'API_VERIFY_SSL',
                'default' => 'false',
                'descricao' => 'Verificar certificado SSL nas consultas Python',
            ],
        ];

        $select = self::$connection->prepare(
            'SELECT valor FROM configuracoes WHERE chave = :chave LIMIT 1'
        );
        $upsert = self::$connection->prepare(
            'INSERT INTO configuracoes (chave, valor, descricao, atualizado_em)
             VALUES (:chave, :valor, :descricao, datetime(\'now\'))
             ON CONFLICT(chave) DO UPDATE SET
                valor = excluded.valor,
                descricao = COALESCE(excluded.descricao, configuracoes.descricao),
                atualizado_em = datetime(\'now\')
             WHERE TRIM(configuracoes.valor) = \'\''
        );

        foreach ($map as $chave => $meta) {
            $select->execute(['chave' => $chave]);
            $atual = $select->fetchColumn();
            $existe = $atual !== false;
            $atual = $existe ? trim((string)$atual) : '';

            if ($existe && $atual !== '') {
                continue;
            }

            $valor = trim(Env::get($meta['env'], ''));
            if ($valor === '') {
                $valor = $meta['default'];
            }

            // Fallback: monta oauth a partir de API_BASE_URL legado.
            if ($chave === 'api_oauth_url' && $valor === '') {
                $base = rtrim(trim(Env::get('API_BASE_URL', '')), '/');
                if ($base !== '') {
                    $valor = $base . '/oauth/token';
                }
            }

            if (!$existe || $atual === '') {
                $upsert->execute([
                    'chave' => $chave,
                    'valor' => $valor,
                    'descricao' => $meta['descricao'],
                ]);
            }
        }
    }

    /** Garante chaves padrao do servidor de e-mail. */
    private static function migrateEmailConfigDefaults(): void
    {
        $defaults = [
            'email_enabled' => ['false', 'Enviar e-mails automaticos de chamadas'],
            'email_alarmes_enabled' => ['false', 'Enviar e-mails automaticos de alarmes criticos (legado)'],
            'email_alarmes_alunos_enabled' => ['false', 'Enviar e-mails automaticos de alarmes criticos aos alunos'],
            'email_alarmes_staff_enabled' => ['false', 'Enviar avisos de alarmes a professores e coordenadores'],
            'email_host' => ['', 'Host SMTP'],
            'email_port' => ['587', 'Porta SMTP'],
            'email_encryption' => ['tls', 'Criptografia SMTP (tls, ssl ou none)'],
            'email_username' => ['', 'Usuario SMTP'],
            'email_password' => ['', 'Senha SMTP'],
            'email_from_address' => ['', 'Remetente (From)'],
            'email_from_name' => ['MAPA', 'Nome do remetente'],
        ];

        $select = self::$connection->prepare(
            'SELECT valor FROM configuracoes WHERE chave = :chave LIMIT 1'
        );
        $insert = self::$connection->prepare(
            'INSERT INTO configuracoes (chave, valor, descricao, atualizado_em)
             VALUES (:chave, :valor, :descricao, datetime(\'now\'))'
        );

        foreach ($defaults as $chave => $meta) {
            $select->execute(['chave' => $chave]);
            if ($select->fetchColumn() !== false) {
                continue;
            }
            $insert->execute([
                'chave' => $chave,
                'valor' => $meta[0],
                'descricao' => $meta[1],
            ]);
        }
    }

    /** Permite contato_tipo email_automatico na tabela alarmes (CHECK legado). */
    private static function migrateAlarmesContatoEmailAutomatico(): void
    {
        $row = self::$connection->query(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'alarmes'"
        )->fetch();

        if ($row === false || empty($row['sql'])) {
            return;
        }

        $ddl = (string)$row['sql'];
        if (strpos($ddl, 'email_automatico') !== false) {
            return;
        }

        self::$connection->exec('PRAGMA foreign_keys = OFF');
        self::$connection->exec('BEGIN');

        self::$connection->exec(
            "CREATE TABLE alarmes_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                coleta_id INTEGER NOT NULL,
                aluno_id INTEGER NOT NULL,
                curso_id INTEGER NOT NULL,
                codigo_disciplina TEXT NOT NULL DEFAULT '',
                disciplina TEXT,
                tipo TEXT NOT NULL CHECK (tipo IN ('percentual_baixo', 'faltas_4dias', 'faltas_3semanas')),
                severidade TEXT NOT NULL DEFAULT 'alto' CHECK (severidade IN ('alto', 'critico')),
                mensagem TEXT NOT NULL,
                detalhe_json TEXT,
                gerado_em TEXT NOT NULL DEFAULT (datetime('now')),
                visualizado INTEGER NOT NULL DEFAULT 0,
                visualizado_em TEXT,
                visualizado_por INTEGER,
                contato_tipo TEXT CHECK (contato_tipo IS NULL OR contato_tipo IN ('email', 'email_automatico', 'whatsapp', 'telefone', 'presencial', 'assistencia')),
                FOREIGN KEY (coleta_id) REFERENCES coletas(id) ON DELETE CASCADE,
                FOREIGN KEY (aluno_id) REFERENCES alunos(id) ON DELETE CASCADE,
                FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE,
                FOREIGN KEY (visualizado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
                UNIQUE (coleta_id, aluno_id, curso_id, codigo_disciplina, tipo)
            )"
        );

        self::$connection->exec(
            'INSERT INTO alarmes_new (
                id, coleta_id, aluno_id, curso_id, codigo_disciplina, disciplina,
                tipo, severidade, mensagem, detalhe_json, gerado_em,
                visualizado, visualizado_em, visualizado_por, contato_tipo
             )
             SELECT
                id, coleta_id, aluno_id, curso_id, codigo_disciplina, disciplina,
                tipo, severidade, mensagem, detalhe_json, gerado_em,
                visualizado, visualizado_em, visualizado_por, contato_tipo
             FROM alarmes'
        );

        self::$connection->exec('DROP TABLE alarmes');
        self::$connection->exec('ALTER TABLE alarmes_new RENAME TO alarmes');
        self::$connection->exec(
            'CREATE INDEX IF NOT EXISTS idx_alarmes_visualizado ON alarmes(visualizado)'
        );
        self::$connection->exec('COMMIT');
        self::$connection->exec('PRAGMA foreign_keys = ON');
    }

    private static function ensureColumn(string $table, string $column, string $definition): void
    {
        $statement = self::$connection->query('PRAGMA table_info(' . $table . ')');
        $columns = $statement->fetchAll();
        foreach ($columns as $info) {
            if (($info['name'] ?? '') === $column) {
                return;
            }
        }

        self::$connection->exec(
            'ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition
        );
    }

    /** Migra CSV legado disciplina_grade.datas_aula → disciplina_aulas (uma vez). */
    private static function migrarDatasAulaCsvParaTabela(): void
    {
        $cols = self::$connection->query('PRAGMA table_info(disciplina_grade)')->fetchAll();
        $temCsv = false;
        foreach ($cols as $info) {
            if (($info['name'] ?? '') === 'datas_aula') {
                $temCsv = true;
                break;
            }
        }
        if (!$temCsv) {
            return;
        }

        $total = (int)self::$connection->query('SELECT COUNT(*) FROM disciplina_aulas')->fetchColumn();
        if ($total > 0) {
            return;
        }

        $rows = self::$connection->query(
            "SELECT codigo_disciplina, curso_id, datas_aula
             FROM disciplina_grade
             WHERE datas_aula IS NOT NULL AND TRIM(datas_aula) != ''"
        )->fetchAll();

        if ($rows === []) {
            return;
        }

        $insert = self::$connection->prepare(
            'INSERT OR IGNORE INTO disciplina_aulas (codigo_disciplina, curso_id, data_aula)
             VALUES (:codigo, :curso_id, :data_aula)'
        );

        foreach ($rows as $row) {
            $codigo = trim((string)($row['codigo_disciplina'] ?? ''));
            $cursoId = (int)($row['curso_id'] ?? 0);
            $bruto = trim((string)($row['datas_aula'] ?? ''));
            if ($codigo === '' || $cursoId <= 0 || $bruto === '') {
                continue;
            }
            foreach (explode(',', $bruto) as $parte) {
                $data = trim($parte);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) !== 1) {
                    continue;
                }
                $insert->execute([
                    'codigo' => $codigo,
                    'curso_id' => $cursoId,
                    'data_aula' => $data,
                ]);
            }
        }
    }

    private static function migrateUsuariosTable(): void
    {
        $row = self::$connection->query(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'usuarios'"
        )->fetch();

        if ($row === false || empty($row['sql'])) {
            return;
        }

        $ddl = (string)$row['sql'];
        $precisaMigrar = strpos($ddl, 'coordenador_curso') === false
            || strpos($ddl, 'senha_hash') === false
            || strpos($ddl, 'auth_type') === false
            || strpos($ddl, "'professor'") === false;

        if (!$precisaMigrar) {
            return;
        }

        self::$connection->exec('PRAGMA foreign_keys = OFF');
        self::$connection->exec('BEGIN');

        self::$connection->exec(
            "CREATE TABLE usuarios_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                nome TEXT NOT NULL,
                email TEXT,
                cpf TEXT UNIQUE,
                senha_hash TEXT,
                auth_type TEXT NOT NULL DEFAULT 'local' CHECK (auth_type IN ('local', 'ldap')),
                perfil TEXT NOT NULL CHECK (perfil IN ('administrador', 'coordenador_curso', 'geral', 'professor')),
                ativo INTEGER NOT NULL DEFAULT 1,
                criado_em TEXT NOT NULL DEFAULT (datetime('now'))
            )"
        );

        $temSenha = strpos($ddl, 'senha_hash') !== false;
        $senhaExpr = $temSenha ? 'senha_hash' : 'NULL';
        $temAuth = strpos($ddl, 'auth_type') !== false;
        $authExpr = $temAuth ? "CASE WHEN auth_type IN ('local','ldap') THEN auth_type ELSE 'local' END" : "'local'";
        $temCpf = strpos($ddl, 'cpf') !== false;
        $cpfExpr = $temCpf ? 'cpf' : 'NULL';

        self::$connection->exec(
            "INSERT INTO usuarios_new (id, username, nome, email, cpf, senha_hash, auth_type, perfil, ativo, criado_em)
             SELECT id, username, nome, email, {$cpfExpr}, {$senhaExpr}, {$authExpr},
                    CASE
                        WHEN perfil IN ('cae', 'ciaape') THEN 'geral'
                        WHEN perfil IN ('administrador', 'coordenador_curso', 'geral', 'professor') THEN perfil
                        ELSE 'geral'
                    END,
                    ativo, criado_em
             FROM usuarios"
        );

        self::$connection->exec('DROP TABLE usuarios');
        self::$connection->exec('ALTER TABLE usuarios_new RENAME TO usuarios');
        self::$connection->exec('COMMIT');
        self::$connection->exec('PRAGMA foreign_keys = ON');
    }

    private static function seedAdminIfEmpty(): void
    {
        $count = (int)self::$connection->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
        if ($count > 0) {
            return;
        }

        // Admin inicial sem senha: a primeira visita pede definição em /setup.
        $statement = self::$connection->prepare(
            'INSERT INTO usuarios (username, nome, email, senha_hash, auth_type, perfil, ativo)
             VALUES (:username, :nome, NULL, NULL, :auth_type, :perfil, 1)'
        );
        $statement->execute([
            'username' => 'admin',
            'nome' => 'Administrador',
            'auth_type' => 'local',
            'perfil' => 'administrador',
        ]);
    }
}
