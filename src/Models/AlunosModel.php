<?php
declare(strict_types=1);

namespace Mapa\Models;

use Mapa\Core\Debug;

class AlunosModel
{
    /** @var int */
    private $parallelConcurrency = 20;

    /** @var Debug */
    private $debug;

    public function __construct(Debug $debug)
    {
        $this->debug = $debug;
    }

    /**
     * @param Aluno[] $alunos
     * @param array<string, string> $consultas
     */
    public function fetchDetalhes(
        ApiModel $apiModel,
        string $apiUrlAlunos,
        string $token,
        array $alunos,
        array $consultas = []
    ): array {
        if ($alunos === []) {
            return [];
        }

        $this->debug->log(
            'Iniciando consultas paralelas (curl_multi) em ' . $apiUrlAlunos
            . ' para ' . count($alunos) . ' aluno(s)'
        );

        $urlsById = [];
        $alunosById = [];

        foreach ($alunos as $indice => $aluno) {
            $login = trim($aluno->login);
            if ($login === '') {
                $this->debug->log('Aluno sem login ignorado na segunda consulta: ' . $aluno->nome);
                continue;
            }

            $url = $this->buildAlunosUrl($apiUrlAlunos, $login, $consultas);
            if ($urlsById === []) {
                $this->debug->log('Exemplo de URL (login da primeira query): ' . $url);
            }
            $urlsById[$indice] = $url;
            $alunosById[$indice] = $aluno;
        }

        if ($urlsById === []) {
            $this->debug->log('Nenhuma URL gerada: alunos sem login valido');
            return [];
        }

        $responses = $apiModel->fetchParallel($urlsById, $token, $this->parallelConcurrency);
        $consultas = [];

        foreach ($alunosById as $indice => $aluno) {
            $response = $responses[$indice] ?? $this->emptyErrorResponse();
            $consultas[] = [
                'matricula' => $aluno->matricula,
                'login' => $aluno->login,
                'nome' => $aluno->nome,
                'url' => $urlsById[$indice],
                'ok' => $response['ok'],
                'status' => $response['status'],
                'error' => $response['error'],
                'json' => $response['json'],
                'body' => $response['body'],
            ];
        }

        $this->debug->log('Consultas individuais finalizadas: ' . count($consultas));

        return $consultas;
    }

    /**
     * @param array<string, string> $consultas
     */
    private function buildAlunosUrl(string $apiUrlAlunos, string $login, array $consultas = []): string
    {
        if (strpos($apiUrlAlunos, '{login}') !== false) {
            $url = str_replace('{login}', rawurlencode($login), $apiUrlAlunos);
        } else {
            $separator = strpos($apiUrlAlunos, '?') === false ? '?' : '&';
            $url = $apiUrlAlunos . $separator . 'login=' . rawurlencode($login);
        }

        return $this->aplicarDatasFrequencia($url, $consultas);
    }

    /**
     * @param array<string, string> $consultas
     */
    private function aplicarDatasFrequencia(string $url, array $consultas): string
    {
        $inicial = trim($consultas['frequencia_data_inicial'] ?? '');
        $final = trim($consultas['frequencia_data_final'] ?? '');

        if ($inicial !== '') {
            if (preg_match('/frequencia_data_inicial=/', $url) === 1) {
                $url = preg_replace(
                    '/frequencia_data_inicial=[^&]*/',
                    'frequencia_data_inicial=' . rawurlencode($inicial),
                    $url
                ) ?? $url;
            } else {
                $url .= (strpos($url, '?') === false ? '?' : '&')
                    . 'frequencia_data_inicial=' . rawurlencode($inicial);
            }
        }

        if ($final !== '') {
            if (preg_match('/frequencia_data_final=/', $url) === 1) {
                $url = preg_replace(
                    '/frequencia_data_final=[^&]*/',
                    'frequencia_data_final=' . rawurlencode($final),
                    $url
                ) ?? $url;
            } else {
                $url .= '&frequencia_data_final=' . rawurlencode($final);
            }
        }

        return $url;
    }

    private function emptyErrorResponse(): array
    {
        return [
            'ok' => false,
            'error' => 'Resposta nao recebida para esta consulta.',
            'status' => null,
            'json' => null,
            'body' => null,
        ];
    }
}
