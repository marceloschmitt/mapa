<?php
declare(strict_types=1);

namespace Mapa\Models;

use Mapa\Core\Debug;

class ConfigModel
{
    /** @var Debug */
    private $debug;

    public function __construct(Debug $debug)
    {
        $this->debug = $debug;
    }

    public function load(): array
    {
        $candidates = [
            '.env',
        ];

        $this->debug->log('Caminhos de .env candidatos: ' . implode(' | ', $candidates));

        $envPathUsed = null;
        $env = [];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $this->debug->log('Arquivo .env encontrado em: ' . $candidate);
                $env = $this->parseEnvFile($candidate);
                $envPathUsed = $candidate;
                break;
            }
        }

        if ($envPathUsed === null) {
            $this->debug->log('Nenhum .env encontrado nos caminhos candidatos');
        }

        $apiUrlMatriculados = $this->firstNonEmpty('API_URL_MATRICULADOS', $env);
        if ($apiUrlMatriculados === '') {
            $apiUrlMatriculados = $this->firstNonEmpty('API_URL', $env);
        }
        $apiUrlAlunos = $this->firstNonEmpty('API_URL_ALUNOS', $env);
        $apiToken = $this->firstNonEmpty('API_TOKEN', $env);

        $this->debug->log('API_URL_MATRICULADOS carregada: ' . ($apiUrlMatriculados !== '' ? 'sim' : 'nao'));
        $this->debug->log('API_URL_ALUNOS carregada: ' . ($apiUrlAlunos !== '' ? 'sim' : 'nao'));
        $this->debug->log('API_TOKEN carregada: ' . ($apiToken !== '' ? 'sim' : 'nao'));

        $missing = [];
        if ($apiUrlMatriculados === '') {
            $missing[] = 'API_URL_MATRICULADOS';
        }
        if ($apiToken === '') {
            $missing[] = 'API_TOKEN';
        }

        return [
            'api_url_matriculados' => $apiUrlMatriculados,
            'api_url_alunos' => $apiUrlAlunos,
            'api_token' => $apiToken,
            'consultas' => $this->loadConsultas(),
            'env_path_used' => $envPathUsed,
            'env_path_searched' => '.env',
            'missing' => $missing,
        ];
    }

    public function loadConsultas(): array
    {
        $path = 'config/consultas.json';
        $this->debug->log('Carregando config de consultas: ' . $path);

        if (!is_file($path)) {
            $this->debug->log('Arquivo consultas.json nao encontrado');
            return [];
        }

        $conteudo = file_get_contents($path);
        if ($conteudo === false) {
            $this->debug->log('Falha ao ler consultas.json');
            return [];
        }

        $dados = json_decode($conteudo, true);
        if (!is_array($dados)) {
            $this->debug->log('consultas.json com formato invalido');
            return [];
        }

        return $dados;
    }

    public function saveConsultas(array $consultas): bool
    {
        $path = 'config/consultas.json';
        $json = json_encode($consultas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            return false;
        }

        return file_put_contents($path, $json . PHP_EOL) !== false;
    }

    private function parseEnvFile(string $envPath): array
    {
        $vars = [];
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return $vars;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }

            $parts = explode('=', $line, 2);
            $key = trim($parts[0]);
            $value = isset($parts[1]) ? trim($parts[1]) : '';

            if ($key !== '') {
                $vars[$key] = $value;
            }
        }

        return $vars;
    }

    private function firstNonEmpty(string $key, array $env): string
    {
        if (isset($env[$key]) && trim($env[$key]) !== '') {
            return trim($env[$key]);
        }

        $systemValue = getenv($key);
        if (is_string($systemValue) && trim($systemValue) !== '') {
            return trim($systemValue);
        }

        return '';
    }
}
