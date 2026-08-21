<?php
declare(strict_types=1);

namespace Mapa\Models;

use Mapa\Core\Debug;

class MatriculadosModel
{
    /** @var Debug */
    private $debug;

    public function __construct(Debug $debug)
    {
        $this->debug = $debug;
    }

    /**
     * @return Aluno[]
     */
    public function parseFromApiResponse(?array $json): array
    {
        if ($json === null) {
            $this->debug->log('JSON vazio ou invalido para parse de alunos');
            return [];
        }

        $records = $this->extractRecords($json);
        $alunos = [];

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $login = trim((string)($record['Login'] ?? $record['login'] ?? ''));
            if ($login === '') {
                continue;
            }

            $alunos[] = Aluno::fromApiRecord($record);
        }

        $this->debug->log('Alunos parseados: ' . count($alunos));

        return $alunos;
    }

    private function extractRecords(array $json): array
    {
        if ($this->isList($json)) {
            return $json;
        }

        if (isset($json['data']) && is_array($json['data'])) {
            $data = $json['data'];
            if ($this->isList($data)) {
                return $data;
            }

            return array_values($data);
        }

        $records = [];
        foreach ($json as $value) {
            if (is_array($value)) {
                $records[] = $value;
            }
        }

        return $records;
    }

    private function isList(array $items): bool
    {
        if ($items === []) {
            return true;
        }

        return array_keys($items) === range(0, count($items) - 1);
    }
}
