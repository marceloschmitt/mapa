<?php
declare(strict_types=1);

namespace Mapa\Models;

use Mapa\Core\Database;
use PDO;

class DisciplinaCargaHorariaRepository
{
    /**
     * @return array{total:int, com_carga:int, sem_carga:int, atualizado_em:?string}
     */
    public function resumo(?string $nomeCurso = null): array
    {
        $pdo = Database::connection();
        $curso = $this->normalizarCurso($nomeCurso);

        if ($curso === null) {
            $total = (int)$pdo->query('SELECT COUNT(*) FROM disciplina_carga_horaria')->fetchColumn();
            $comCarga = (int)$pdo->query(
                'SELECT COUNT(*) FROM disciplina_carga_horaria WHERE carga_horaria IS NOT NULL'
            )->fetchColumn();
            $atualizado = $pdo->query(
                'SELECT MAX(atualizado_em) FROM disciplina_carga_horaria'
            )->fetchColumn();
        } else {
            $statement = $pdo->prepare(
                'SELECT COUNT(*) FROM disciplina_carga_horaria WHERE nome_curso = :curso'
            );
            $statement->execute([':curso' => $curso]);
            $total = (int)$statement->fetchColumn();

            $statement = $pdo->prepare(
                'SELECT COUNT(*) FROM disciplina_carga_horaria
                 WHERE nome_curso = :curso AND carga_horaria IS NOT NULL'
            );
            $statement->execute([':curso' => $curso]);
            $comCarga = (int)$statement->fetchColumn();

            $statement = $pdo->prepare(
                'SELECT MAX(atualizado_em) FROM disciplina_carga_horaria WHERE nome_curso = :curso'
            );
            $statement->execute([':curso' => $curso]);
            $atualizado = $statement->fetchColumn();
        }

        return [
            'total' => $total,
            'com_carga' => $comCarga,
            'sem_carga' => max(0, $total - $comCarga),
            'atualizado_em' => $atualizado !== false && $atualizado !== null
                ? (string)$atualizado
                : null,
        ];
    }

    /**
     * @return list<string>
     */
    public function listarCursos(): array
    {
        $pdo = Database::connection();
        $statement = $pdo->query(
            "SELECT DISTINCT nome_curso
             FROM disciplina_carga_horaria
             WHERE TRIM(COALESCE(nome_curso, '')) <> ''
             ORDER BY nome_curso ASC"
        );

        $cursos = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $nome) {
            $cursos[] = (string)$nome;
        }

        return $cursos;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listar(?string $nomeCurso = null, int $limite = 500): array
    {
        $limite = max(1, min(5000, $limite));
        $pdo = Database::connection();
        $curso = $this->normalizarCurso($nomeCurso);

        if ($curso === null) {
            $statement = $pdo->prepare(
                'SELECT codigo_disciplina, disciplina, nome_curso, carga_horaria,
                        origem_periodo, atualizado_em
                 FROM disciplina_carga_horaria
                 ORDER BY nome_curso ASC, codigo_disciplina ASC
                 LIMIT :limite'
            );
            $statement->bindValue(':limite', $limite, PDO::PARAM_INT);
            $statement->execute();
        } else {
            $statement = $pdo->prepare(
                'SELECT codigo_disciplina, disciplina, nome_curso, carga_horaria,
                        origem_periodo, atualizado_em
                 FROM disciplina_carga_horaria
                 WHERE nome_curso = :curso
                 ORDER BY codigo_disciplina ASC
                 LIMIT :limite'
            );
            $statement->bindValue(':curso', $curso);
            $statement->bindValue(':limite', $limite, PDO::PARAM_INT);
            $statement->execute();
        }

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function normalizarCurso(?string $nomeCurso): ?string
    {
        $curso = trim((string)$nomeCurso);
        return $curso === '' ? null : $curso;
    }
}
