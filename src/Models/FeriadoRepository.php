<?php
declare(strict_types=1);

namespace Mapa\Models;

use Mapa\Core\Database;
use PDO;

class FeriadoRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * @return list<array{data: string, descricao: string, criado_em: string}>
     */
    public function listar(?int $ano = null): array
    {
        if ($ano !== null && $ano > 0) {
            $statement = $this->db->prepare(
                'SELECT data, descricao, criado_em
                 FROM feriados
                 WHERE data >= :inicio AND data <= :fim
                 ORDER BY data ASC'
            );
            $statement->execute([
                'inicio' => sprintf('%04d-01-01', $ano),
                'fim' => sprintf('%04d-12-31', $ano),
            ]);
        } else {
            $statement = $this->db->query(
                'SELECT data, descricao, criado_em
                 FROM feriados
                 ORDER BY data ASC'
            );
        }

        $linhas = [];
        foreach ($statement->fetchAll() as $row) {
            $linhas[] = [
                'data' => (string)$row['data'],
                'descricao' => (string)($row['descricao'] ?? ''),
                'criado_em' => (string)($row['criado_em'] ?? ''),
            ];
        }

        return $linhas;
    }

    /**
     * Datas ISO YYYY-MM-DD indexadas para lookup rápido.
     *
     * @return array<string, true>
     */
    public function mapaDatas(): array
    {
        $statement = $this->db->query('SELECT data FROM feriados');
        $mapa = [];
        foreach ($statement->fetchAll() as $row) {
            $data = trim((string)($row['data'] ?? ''));
            if ($data !== '') {
                $mapa[$data] = true;
            }
        }

        return $mapa;
    }

    /**
     * @return array{ok: bool, erro: string|null}
     */
    public function adicionar(string $dataIso, string $descricao): array
    {
        $dataIso = $this->normalizarData($dataIso);
        if ($dataIso === null) {
            return ['ok' => false, 'erro' => 'Data inválida. Use o formato AAAA-MM-DD.'];
        }

        $descricao = trim($descricao);
        if (function_exists('mb_substr')) {
            $descricao = mb_substr($descricao, 0, 120);
        } else {
            $descricao = substr($descricao, 0, 120);
        }

        try {
            $statement = $this->db->prepare(
                'INSERT INTO feriados (data, descricao, criado_em)
                 VALUES (:data, :descricao, datetime(\'now\'))'
            );
            $statement->execute([
                'data' => $dataIso,
                'descricao' => $descricao,
            ]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'PRIMARY')) {
                return ['ok' => false, 'erro' => 'Este feriado já está cadastrado.'];
            }
            throw $e;
        }

        $this->removerDataDaGrade($dataIso);

        return ['ok' => true, 'erro' => null];
    }

    public function excluir(string $dataIso): bool
    {
        $dataIso = $this->normalizarData($dataIso);
        if ($dataIso === null) {
            return false;
        }

        $statement = $this->db->prepare('DELETE FROM feriados WHERE data = :data');
        $statement->execute(['data' => $dataIso]);

        return $statement->rowCount() > 0;
    }

    /**
     * Remove a data das aulas previstas (efeito imediato nas chamadas).
     */
    private function removerDataDaGrade(string $dataIso): void
    {
        $statement = $this->db->prepare(
            'DELETE FROM disciplina_aulas WHERE data_aula = :data'
        );
        $statement->execute(['data' => $dataIso]);
    }

    private function normalizarData(string $valor): ?string
    {
        $valor = trim($valor);
        if ($valor === '') {
            return null;
        }

        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $valor, $m) === 1) {
            $valor = $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) !== 1) {
            return null;
        }

        $partes = explode('-', $valor);
        if (!checkdate((int)$partes[1], (int)$partes[2], (int)$partes[0])) {
            return null;
        }

        return $valor;
    }
}
