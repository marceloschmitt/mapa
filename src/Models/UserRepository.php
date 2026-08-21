<?php
declare(strict_types=1);

namespace Mapa\Models;

use Mapa\Core\Auth;
use Mapa\Core\Database;
use PDO;

class UserRepository
{
    /** @var PDO */
    private $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        $statement = $this->db->query(
            'SELECT id, username, nome, email, cpf, auth_type, perfil, ativo, criado_em
             FROM usuarios
             ORDER BY nome ASC'
        );

        return $statement->fetchAll();
    }

    /**
     * Admin local ativo ainda sem senha (primeira instalação).
     *
     * @return array<string, mixed>|null
     */
    public function findAdminPendenteSenha(): ?array
    {
        $statement = $this->db->query(
            "SELECT id, username, nome, email, cpf, senha_hash, auth_type, perfil, ativo, criado_em
             FROM usuarios
             WHERE perfil = 'administrador'
               AND auth_type = 'local'
               AND ativo = 1
               AND (senha_hash IS NULL OR TRIM(senha_hash) = '')
             ORDER BY id ASC
             LIMIT 1"
        );
        $user = $statement->fetch();

        return $user !== false ? $user : null;
    }

    /** @return array<string, mixed>|null */
    public function findByUsername(string $username): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, username, nome, email, cpf, senha_hash, auth_type, perfil, ativo, criado_em
             FROM usuarios
             WHERE username = :username
             LIMIT 1'
        );
        $statement->execute(['username' => trim($username)]);
        $user = $statement->fetch();

        return $user !== false ? $user : null;
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, username, nome, email, cpf, senha_hash, auth_type, perfil, ativo, criado_em
             FROM usuarios
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $user = $statement->fetch();

        return $user !== false ? $user : null;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $authType = (string)($data['auth_type'] ?? 'local');
        $senhaHash = $authType === 'local' ? (string)($data['senha_hash'] ?? '') : null;
        if ($authType === 'local' && $senhaHash === '') {
            throw new \InvalidArgumentException('Senha local obrigatória.');
        }

        $statement = $this->db->prepare(
            'INSERT INTO usuarios (username, nome, email, cpf, senha_hash, auth_type, perfil, ativo)
             VALUES (:username, :nome, :email, :cpf, :senha_hash, :auth_type, :perfil, :ativo)'
        );
        $statement->execute([
            'username' => trim((string)$data['username']),
            'nome' => trim((string)$data['nome']),
            'email' => trim((string)($data['email'] ?? '')) ?: null,
            'cpf' => $this->normalizarCpf($data['cpf'] ?? null),
            'senha_hash' => $senhaHash,
            'auth_type' => $authType,
            'perfil' => (string)$data['perfil'],
            'ativo' => !empty($data['ativo']) ? 1 : 0,
        ]);

        $id = (int)$this->db->lastInsertId();
        $this->syncCursos($id, $data['curso_ids'] ?? []);

        return $id;
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $authType = (string)($data['auth_type'] ?? 'local');
        $params = [
            'id' => $id,
            'username' => trim((string)$data['username']),
            'nome' => trim((string)$data['nome']),
            'email' => trim((string)($data['email'] ?? '')) ?: null,
            'cpf' => $this->normalizarCpf($data['cpf'] ?? null),
            'auth_type' => $authType,
            'perfil' => (string)$data['perfil'],
            'ativo' => !empty($data['ativo']) ? 1 : 0,
        ];

        if ($authType === 'ldap') {
            $statement = $this->db->prepare(
                'UPDATE usuarios
                 SET username = :username,
                     nome = :nome,
                     email = :email,
                     cpf = :cpf,
                     auth_type = :auth_type,
                     perfil = :perfil,
                     ativo = :ativo,
                     senha_hash = NULL
                 WHERE id = :id'
            );
            $statement->execute($params);
        } elseif (!empty($data['senha_hash'])) {
            $params['senha_hash'] = (string)$data['senha_hash'];
            $statement = $this->db->prepare(
                'UPDATE usuarios
                 SET username = :username,
                     nome = :nome,
                     email = :email,
                     cpf = :cpf,
                     auth_type = :auth_type,
                     perfil = :perfil,
                     ativo = :ativo,
                     senha_hash = :senha_hash
                 WHERE id = :id'
            );
            $statement->execute($params);
        } else {
            $statement = $this->db->prepare(
                'UPDATE usuarios
                 SET username = :username,
                     nome = :nome,
                     email = :email,
                     cpf = :cpf,
                     auth_type = :auth_type,
                     perfil = :perfil,
                     ativo = :ativo
                 WHERE id = :id'
            );
            $statement->execute($params);
        }

        $this->syncCursos($id, $data['curso_ids'] ?? []);
    }

    public function updatePassword(int $id, string $senhaHash): void
    {
        $statement = $this->db->prepare(
            "UPDATE usuarios
             SET senha_hash = :senha_hash,
                 auth_type = 'local'
             WHERE id = :id AND auth_type = 'local'"
        );
        $statement->execute([
            'id' => $id,
            'senha_hash' => $senhaHash,
        ]);
    }

    public function delete(int $id): void
    {
        $current = Auth::user();
        if ($current !== null && (int)$current['id'] === $id) {
            throw new \InvalidArgumentException('Você não pode excluir o próprio usuário.');
        }

        $statement = $this->db->prepare('DELETE FROM usuarios WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function usernameExists(string $username, ?int $ignoreId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM usuarios WHERE username = :username';
        $params = ['username' => trim($username)];

        if ($ignoreId !== null) {
            $sql .= ' AND id != :id';
            $params['id'] = $ignoreId;
        }

        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return (int)$statement->fetchColumn() > 0;
    }

    public function cpfExists(string $cpf, ?int $ignoreId = null): bool
    {
        $normalizado = $this->normalizarCpf($cpf);
        if ($normalizado === null) {
            return false;
        }

        $sql = 'SELECT COUNT(*) FROM usuarios WHERE cpf = :cpf';
        $params = ['cpf' => $normalizado];

        if ($ignoreId !== null) {
            $sql .= ' AND id != :id';
            $params['id'] = $ignoreId;
        }

        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return (int)$statement->fetchColumn() > 0;
    }

    public function normalizarCpf(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $digitos = preg_replace('/\D+/', '', (string)$valor) ?? '';
        if ($digitos === '') {
            return null;
        }

        return str_pad($digitos, 11, '0', STR_PAD_LEFT);
    }

    /**
     * Professores do banco que ainda nao tem usuario com o mesmo CPF.
     *
     * @return list<array{id: int, cpf: string, nome: string, email: ?string}>
     */
    public function professoresSemUsuario(): array
    {
        $statement = $this->db->query(
            'SELECT p.id, p.cpf, p.nome, p.email
             FROM professores p
             LEFT JOIN usuarios u ON u.cpf = p.cpf
             WHERE u.id IS NULL
             ORDER BY p.nome ASC'
        );

        return $statement->fetchAll();
    }

    /**
     * Cria usuarios LDAP/professor a partir dos docentes sem cadastro.
     *
     * @return array{criados: int, pulados: int, detalhes: list<string>}
     */
    public function criarUsuariosApartirDeProfessores(): array
    {
        $criados = 0;
        $pulados = 0;
        $detalhes = [];

        foreach ($this->professoresSemUsuario() as $professor) {
            $cpf = $this->normalizarCpf($professor['cpf'] ?? null);
            $nomeBruto = trim((string)($professor['nome'] ?? ''));
            if ($cpf === null || $nomeBruto === '') {
                $pulados++;
                $detalhes[] = 'Ignorado (sem CPF ou nome): ' . $nomeBruto;
                continue;
            }

            $nome = $this->formatarNomePessoa($nomeBruto);
            $username = $this->gerarLoginDeNome($nomeBruto);
            if ($username === '') {
                $pulados++;
                $detalhes[] = 'Ignorado (login inválido): ' . $nome;
                continue;
            }

            if ($this->usernameExists($username)) {
                $pulados++;
                $detalhes[] = 'Ignorado (login ja existe): ' . $nome . ' → ' . $username;
                continue;
            }

            $email = trim((string)($professor['email'] ?? ''));
            $this->create([
                'username' => $username,
                'nome' => $nome,
                'email' => $email !== '' ? $email : null,
                'cpf' => $cpf,
                'auth_type' => 'ldap',
                'perfil' => Auth::PERFIL_PROFESSOR,
                'ativo' => 1,
                'curso_ids' => [],
            ]);
            $criados++;
            $detalhes[] = $nome . ' → ' . $username;
        }

        return [
            'criados' => $criados,
            'pulados' => $pulados,
            'detalhes' => $detalhes,
        ];
    }

    public function formatarNomePessoa(string $nome): string
    {
        $nome = trim(preg_replace('/\s+/u', ' ', $nome) ?? $nome);
        if ($nome === '') {
            return '';
        }

        $minusculo = mb_strtolower($nome, 'UTF-8');

        return mb_convert_case($minusculo, MB_CASE_TITLE, 'UTF-8');
    }

    public function gerarLoginDeNome(string $nome): string
    {
        $nome = trim(preg_replace('/\s+/u', ' ', $nome) ?? $nome);
        if ($nome === '') {
            return '';
        }

        $partes = preg_split('/\s+/u', $nome) ?: [];
        $partes = array_values(array_filter($partes, static function (string $parte): bool {
            return $parte !== '';
        }));
        if ($partes === []) {
            return '';
        }

        $primeiro = $this->somenteAsciiMinusculo($partes[0]);
        $ultimo = $this->somenteAsciiMinusculo($partes[count($partes) - 1]);

        return $primeiro . $ultimo;
    }

    private function somenteAsciiMinusculo(string $texto): string
    {
        $texto = mb_strtolower(trim($texto), 'UTF-8');
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
        if ($ascii === false) {
            $ascii = $texto;
        }

        return preg_replace('/[^a-z0-9]/', '', $ascii) ?? '';
    }

    /** @return list<int> */
    public function cursoIdsDoUsuario(int $usuarioId): array
    {
        $statement = $this->db->prepare(
            'SELECT curso_id FROM usuario_cursos WHERE usuario_id = :usuario_id ORDER BY curso_id'
        );
        $statement->execute(['usuario_id' => $usuarioId]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Codigos de disciplina do docente cujo CPF coincide com o do usuario.
     *
     * @return list<string>
     */
    public function disciplinaCodigosDoUsuario(int $usuarioId): array
    {
        $usuario = $this->findById($usuarioId);
        if ($usuario === null) {
            return [];
        }

        $cpf = $this->normalizarCpf($usuario['cpf'] ?? null);
        if ($cpf === null) {
            return [];
        }

        $statement = $this->db->prepare(
            'SELECT DISTINCT dp.codigo_disciplina
             FROM professores p
             INNER JOIN disciplina_professores dp ON dp.professor_id = p.id
             WHERE p.cpf = :cpf
             ORDER BY dp.codigo_disciplina'
        );
        $statement->execute(['cpf' => $cpf]);

        $codigos = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $codigo) {
            $codigo = trim((string)$codigo);
            if ($codigo !== '') {
                $codigos[] = $codigo;
            }
        }

        return $codigos;
    }

    /** @return list<string> */
    public function nomesDisciplinasDoUsuario(int $usuarioId): array
    {
        $usuario = $this->findById($usuarioId);
        if ($usuario === null) {
            return [];
        }

        $cpf = $this->normalizarCpf($usuario['cpf'] ?? null);
        if ($cpf === null) {
            return [];
        }

        $statement = $this->db->prepare(
            'SELECT DISTINCT dp.disciplina
             FROM professores p
             INNER JOIN disciplina_professores dp ON dp.professor_id = p.id
             WHERE p.cpf = :cpf
             ORDER BY dp.disciplina'
        );
        $statement->execute(['cpf' => $cpf]);

        $nomes = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $nome) {
            $nome = trim((string)$nome);
            if ($nome !== '') {
                $nomes[] = $nome;
            }
        }

        return $nomes;
    }

    /** @param list<int|string> $cursoIds */
    public function syncCursos(int $usuarioId, array $cursoIds): void
    {
        $this->db->prepare('DELETE FROM usuario_cursos WHERE usuario_id = :usuario_id')
            ->execute(['usuario_id' => $usuarioId]);

        $usuario = $this->findById($usuarioId);
        if ($usuario === null || (string)$usuario['perfil'] !== Auth::PERFIL_COORDENADOR) {
            return;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $cursoIds), static function (int $id): bool {
            return $id > 0;
        })));

        if ($ids === []) {
            return;
        }

        $statement = $this->db->prepare(
            'INSERT OR IGNORE INTO usuario_cursos (usuario_id, curso_id) VALUES (:usuario_id, :curso_id)'
        );

        foreach ($ids as $cursoId) {
            $statement->execute([
                'usuario_id' => $usuarioId,
                'curso_id' => $cursoId,
            ]);
        }
    }

    /** @return list<array{id: int, nome_curso: string}> */
    public function listarCursos(): array
    {
        $statement = $this->db->query(
            'SELECT id, nome_curso FROM cursos ORDER BY nome_curso ASC'
        );

        return $statement->fetchAll();
    }
}
