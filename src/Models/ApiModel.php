<?php
declare(strict_types=1);

namespace Mapa\Models;

use Mapa\Core\Debug;

class ApiModel
{
    /** @var Debug */
    private $debug;

    /** @var int */
    private $parallelConcurrency = 20;

    public function __construct(Debug $debug)
    {
        $this->debug = $debug;
    }

    public function fetchFirstPage(string $url, string $token): array
    {
        $this->debug->log('Buscando apenas a primeira pagina: ' . $url);
        return $this->fetch($url, $token);
    }

    public function fetch(string $url, string $token): array
    {
        return $this->fetchOnce($url, $token);
    }

    /**
     * Executa varias requisicoes em paralelo com curl_multi.
     *
     * curl_multi permite disparar N chamadas HTTP ao mesmo tempo, em vez de
     * esperar cada uma terminar antes de iniciar a proxima (foreach sequencial).
     *
     * @param array<int|string, string> $urlsById
     * @return array<int|string, array>
     */
    public function fetchParallel(array $urlsById, string $token, int $concurrency = 20): array
    {
        if ($urlsById === []) {
            return [];
        }

        $this->parallelConcurrency = max(1, $concurrency);
        $this->debug->log(
            'curl_multi: iniciando ' . count($urlsById) . ' requisicao(oes) em lotes de '
            . $this->parallelConcurrency
        );

        $results = [];
        $chunks = array_chunk($urlsById, $this->parallelConcurrency, true);
        $startedAt = microtime(true);

        foreach ($chunks as $chunkIndex => $chunk) {
            $lote = $chunkIndex + 1;
            $this->debug->log('curl_multi: lote ' . $lote . '/' . count($chunks) . ' (' . count($chunk) . ' URL(s))');
            $chunkResults = $this->fetchParallelChunk($chunk, $token);
            $results = array_merge($results, $chunkResults);
        }

        $elapsed = round(microtime(true) - $startedAt, 2);
        $this->debug->log('curl_multi: finalizado em ' . $elapsed . 's');

        return $results;
    }

    public function fetchAll(string $url, string $token): array
    {
        $collectedRows = [];
        $currentUrl = $url;
        $firstPageJson = null;
        $lastResponse = null;
        $page = 1;
        $maxPages = 200;
        $visitedUrls = [];

        $this->debug->log('Iniciando coleta paginada para trazer todos os dados');

        while ($currentUrl !== '' && $page <= $maxPages) {
            if (isset($visitedUrls[$currentUrl])) {
                $this->debug->log('Loop detectado: URL da pagina ja foi processada. Encerrando paginacao.');
                break;
            }
            $visitedUrls[$currentUrl] = true;

            $this->debug->log('Buscando pagina ' . $page . ': ' . $currentUrl);
            $response = $this->fetchOnce($currentUrl, $token);
            $lastResponse = $response;

            if (!$response['ok']) {
                $this->debug->log('Parando paginacao por erro na pagina ' . $page);
                return $response;
            }

            if (!is_array($response['json'])) {
                $this->debug->log('Resposta nao veio em JSON de array na pagina ' . $page);
                return $response;
            }

            $json = $response['json'];
            if ($firstPageJson === null) {
                $firstPageJson = $json;
            }

            if (isset($json['data']) && is_array($json['data'])) {
                $countThisPage = count($json['data']);
                $collectedRows = array_merge($collectedRows, $json['data']);
                $this->debug->log('Pagina ' . $page . ' adicionou ' . $countThisPage . ' registros');
            } else {
                $this->debug->log('JSON sem chave data paginada; usando corpo atual como retorno final');
                return $response;
            }

            $nextUrl = $this->readNextPageUrl($json);
            if ($nextUrl === '') {
                $this->debug->log('Nao ha proxima pagina. Coleta finalizada');
                break;
            }
            if ($nextUrl === $currentUrl) {
                $this->debug->log('Loop detectado: next_page_url igual a URL atual. Encerrando paginacao.');
                break;
            }

            $currentUrl = $nextUrl;
            $page++;
        }

        if ($page > $maxPages) {
            $this->debug->log('Limite maximo de paginas atingido (' . $maxPages . ')');
        }

        if ($lastResponse === null) {
            return [
                'ok' => false,
                'error' => 'Nenhuma resposta recebida da API.',
                'status' => null,
                'headers' => [],
                'body' => null,
                'json' => null,
            ];
        }

        $mergedJson = $firstPageJson !== null
            ? $this->mergePagedPayloads($firstPageJson, $collectedRows)
            : $collectedRows;

        $lastResponse['json'] = $mergedJson;
        $lastResponse['body'] = json_encode($mergedJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $this->debug->log('Total de registros coletados: ' . count($collectedRows));

        return $lastResponse;
    }

    /**
     * @param array<int|string, string> $urlsById
     * @return array<int|string, array>
     */
    private function fetchParallelChunk(array $urlsById, string $token): array
    {
        $mh = curl_multi_init();
        if ($mh === false) {
            $this->debug->log('curl_multi: falha ao iniciar multi handle');
            return [];
        }

        /** @var array<int|string, resource> $handles */
        $handles = [];

        foreach ($urlsById as $id => $url) {
            $ch = $this->createHandle($url, $token);
            if ($ch === false) {
                continue;
            }
            $handles[$id] = $ch;
            curl_multi_add_handle($mh, $ch);
        }

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $id => $ch) {
            $body = curl_multi_getcontent($ch);
            $results[$id] = $this->parseHandleResponse($ch, is_string($body) ? $body : null);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);

        return $results;
    }

    private function fetchOnce(string $url, string $token): array
    {
        $ch = $this->createHandle($url, $token);
        if ($ch === false) {
            $this->debug->log('Falha ao iniciar cURL');
            return $this->errorResponse('Nao foi possivel iniciar cURL.');
        }

        $body = curl_exec($ch);
        $response = $this->parseHandleResponse($ch, is_string($body) ? $body : null);
        curl_close($ch);

        return $response;
    }

    /**
     * @return resource|false
     */
    private function createHandle(string $url, string $token)
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        return $ch;
    }

    /**
     * @param resource $ch
     */
    private function parseHandleResponse($ch, ?string $body = null): array
    {
        if ($body === null) {
            $body = curl_exec($ch);
        }

        $curlError = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $json = null;
        if (is_string($body)) {
            $decoded = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $json = $decoded;
            }
        }

        if (!is_string($body)) {
            return [
                'ok' => false,
                'error' => 'Erro na chamada cURL: ' . $curlError,
                'status' => $status ?: null,
                'headers' => [],
                'body' => null,
                'json' => null,
            ];
        }

        return [
            'ok' => $status >= 200 && $status < 300,
            'error' => $curlError !== '' ? $curlError : null,
            'status' => $status ?: null,
            'headers' => [],
            'body' => $body,
            'json' => $json,
        ];
    }

    private function readNextPageUrl(array $json): string
    {
        if (isset($json['next_page_url']) && is_string($json['next_page_url']) && $json['next_page_url'] !== '') {
            return $json['next_page_url'];
        }

        if (isset($json['links']['next']) && is_string($json['links']['next']) && $json['links']['next'] !== '') {
            return $json['links']['next'];
        }

        return '';
    }

    private function mergePagedPayloads(array $firstPage, array $allRows): array
    {
        if (isset($firstPage['data']) && is_array($firstPage['data'])) {
            $firstPage['data'] = $allRows;
            $firstPage['total_coletado'] = count($allRows);
            return $firstPage;
        }

        return $allRows;
    }

    private function errorResponse(string $message): array
    {
        return [
            'ok' => false,
            'error' => $message,
            'status' => null,
            'headers' => [],
            'body' => null,
            'json' => null,
        ];
    }
}
