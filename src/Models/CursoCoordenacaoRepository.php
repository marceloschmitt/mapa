<?php
declare(strict_types=1);

namespace Mapa\Models;

use Mapa\Core\Database;
use PDO;

class CursoCoordenacaoRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    /**
     * @return list<array{
     *   id: int,
     *   nome_curso: string,
     *   email_coordenacao: string,
     *   coordenadores: list<array{id: int, nome: string, email: string}>
     * }>
     */
    public function listarCursosComCoordenacao(): array
    {
        $statement = $this->pdo->query(
            'SELECT c.id, c.nome_curso, COALESCE(cc.email_coordenacao, \'\') AS email_coordenacao
             FROM cursos c
             LEFT JOIN curso_coordenacao cc ON cc.curso_id = c.id
             ORDER BY c.nome_curso ASC'
        );

        $cursos = [];
        foreach ($statement->fetchAll() as $row) {
            $cursoId = (int)$row['id'];
            $cursos[$cursoId] = [
                'id' => $cursoId,
                'nome_curso' => trim((string)($row['nome_curso'] ?? '')),
                'email_coordenacao' => trim((string)($row['email_coordenacao'] ?? '')),
                'coordenadores' => [],
            ];
        }

        if ($cursos === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach (array_keys($cursos) as $index => $cursoId) {
            $key = 'curso_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $cursoId;
        }

        $statement = $this->pdo->prepare(
            'SELECT uc.curso_id, u.id AS usuario_id, u.nome, u.email
             FROM usuario_cursos uc
             INNER JOIN usuarios u ON u.id = uc.usuario_id
             WHERE uc.curso_id IN (' . implode(', ', $placeholders) . ')
               AND u.perfil = \'coordenador_curso\'
               AND u.ativo = 1
             ORDER BY u.nome ASC'
        );
        $statement->execute($params);

        foreach ($statement->fetchAll() as $row) {
            $cursoId = (int)($row['curso_id'] ?? 0);
            if (!isset($cursos[$cursoId])) {
                continue;
            }

            $cursos[$cursoId]['coordenadores'][] = [
                'id' => (int)($row['usuario_id'] ?? 0),
                'nome' => trim((string)($row['nome'] ?? '')),
                'email' => trim((string)($row['email'] ?? '')),
            ];
        }

        return array_values($cursos);
    }

    /**
     * @param array<int, string> $emailsPorCurso
     */
    public function salvarEmails(array $emailsPorCurso): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO curso_coordenacao (curso_id, email_coordenacao, atualizado_em)
             VALUES (:curso_id, :email_coordenacao, datetime(\'now\'))
             ON CONFLICT(curso_id) DO UPDATE SET
                email_coordenacao = excluded.email_coordenacao,
                atualizado_em = excluded.atualizado_em'
        );

        foreach ($emailsPorCurso as $cursoId => $email) {
            $statement->execute([
                'curso_id' => (int)$cursoId,
                'email_coordenacao' => trim($email),
            ]);
        }
    }

    /**
     * @return list<string>
     */
    public function emailsCoordenacaoCurso(int $cursoId): array
    {
        if ($cursoId <= 0) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT TRIM(email_coordenacao) AS email
             FROM curso_coordenacao
             WHERE curso_id = :curso_id
               AND TRIM(email_coordenacao) != \'\''
        );
        $statement->execute(['curso_id' => $cursoId]);
        $email = trim((string)$statement->fetchColumn());

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [$email];
        }

        return [];
    }
}
