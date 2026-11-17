<?php
declare(strict_types=1);

namespace Mapa\Models;

use Mapa\Core\Database;
use PDO;
use Throwable;

class EmailLogRepository
{
    private PDO $db;

    public function __construct(?PDO $pdo = null)
    {
        $this->db = $pdo ?? Database::connection();
    }

    /**
     * @param list<string> $emails
     * @param array<string, string> $nomesPorEmail email (qualquer case) => nome
     */
    public function registrarEnvios(array $emails, string $assunto = '', array $nomesPorEmail = []): void
    {
        $nomesNorm = [];
        foreach ($nomesPorEmail as $email => $nome) {
            $chave = strtolower(trim((string)$email));
            if ($chave !== '') {
                $nomesNorm[$chave] = trim((string)$nome);
            }
        }

        $insert = $this->db->prepare(
            'INSERT INTO email_envios (email, nome, assunto, enviado_em)
             VALUES (:email, :nome, :assunto, datetime(\'now\', \'localtime\'))'
        );

        foreach ($emails as $email) {
            $email = trim((string)$email);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $chave = strtolower($email);
            $nome = $nomesNorm[$chave] ?? '';
            if ($nome === '') {
                $nome = $this->resolverNome($email);
            }
            $insert->execute([
                'email' => $email,
                'nome' => $nome,
                'assunto' => trim($assunto),
            ]);
        }
    }

    /**
     * @return list<array{id: int, email: string, nome: string, assunto: string, enviado_em: string}>
     */
    public function listar(?int $dias = 30, int $limite = 500): array
    {
        $sql = 'SELECT id, email, nome, assunto, enviado_em
                FROM email_envios';
        $params = [];
        if ($dias !== null && $dias > 0) {
            $sql .= ' WHERE enviado_em >= datetime(\'now\', \'localtime\', :offset)';
            $params['offset'] = '-' . $dias . ' days';
        }
        $sql .= ' ORDER BY enviado_em DESC, id DESC LIMIT :limite';

        $statement = $this->db->prepare($sql);
        foreach ($params as $chave => $valor) {
            $statement->bindValue($chave, $valor);
        }
        $statement->bindValue('limite', $limite, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function total(?int $dias = 30): int
    {
        $sql = 'SELECT COUNT(*) FROM email_envios';
        $params = [];
        if ($dias !== null && $dias > 0) {
            $sql .= ' WHERE enviado_em >= datetime(\'now\', \'localtime\', :offset)';
            $params['offset'] = '-' . $dias . ' days';
        }
        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return (int)$statement->fetchColumn();
    }

    private function resolverNome(string $email): string
    {
        $emailNorm = strtolower(trim($email));
        if ($emailNorm === '') {
            return '';
        }

        try {
            $usuario = $this->db->prepare(
                'SELECT nome FROM usuarios
                 WHERE LOWER(TRIM(email)) = :email
                 ORDER BY ativo DESC, id ASC
                 LIMIT 1'
            );
            $usuario->execute(['email' => $emailNorm]);
            $nome = trim((string)($usuario->fetchColumn() ?: ''));
            if ($nome !== '') {
                return $nome;
            }

            $professor = $this->db->prepare(
                'SELECT nome FROM professores
                 WHERE LOWER(TRIM(email)) = :email
                 LIMIT 1'
            );
            $professor->execute(['email' => $emailNorm]);
            $nome = trim((string)($professor->fetchColumn() ?: ''));
            if ($nome !== '') {
                return $nome;
            }

            $aluno = $this->db->prepare(
                'SELECT COALESCE(NULLIF(TRIM(nome_social), \'\'), nome) AS nome
                 FROM alunos
                 WHERE LOWER(TRIM(email)) = :email
                 LIMIT 1'
            );
            $aluno->execute(['email' => $emailNorm]);
            return trim((string)($aluno->fetchColumn() ?: ''));
        } catch (Throwable) {
            return '';
        }
    }
}
