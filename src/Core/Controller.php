<?php
declare(strict_types=1);

namespace Mapa\Core;

abstract class Controller
{
    protected function render(string $view, array $data = [], string $layout = 'layouts/main'): void
    {
        $data['usuario'] = Auth::user();
        $data['isAdmin'] = $data['isAdmin'] ?? Auth::isAdmin();
        $data['podeVerChamadas'] = $data['podeVerChamadas'] ?? Auth::canVerChamadas();
        $data['podeVerPasseLivre'] = $data['podeVerPasseLivre'] ?? Auth::canVerPasseLivre();
        $data['podeVerFrequenciaAnual'] = $data['podeVerFrequenciaAnual'] ?? Auth::canVerFrequenciaAnual();
        // Limites e textos das regras de alarme: as telas nao fixam mais 75%.
        $data['alarmeConfig'] = $data['alarmeConfig'] ?? self::alarmeConfig();

        View::render($view, $data, $layout);
    }

    /**
     * Configuracao das regras de alarme (uma leitura por requisicao).
     *
     * @return array<string, mixed>
     */
    protected static function alarmeConfig(): array
    {
        static $config = null;
        if ($config === null) {
            $config = (new \Mapa\Models\ConfigRepository())->getAlarmeConfig();
        }

        return $config;
    }

    protected function redirect(string $path): void
    {
        header('Location: ' . Url::to($path));
        exit;
    }

    /** @return array<string, mixed> */
    protected function requireAuth(): array
    {
        $user = Auth::user();
        if ($user === null) {
            Session::flash('erro', 'Faça login para continuar.');
            $this->redirect('/login');
        }

        (new \Mapa\Models\AccessLogRepository())->registrarAcessoAtual();

        return $user;
    }

    /** @return array<string, mixed> */
    protected function requireAdmin(): array
    {
        $user = $this->requireAuth();
        if (!Auth::canManageUsers()) {
            http_response_code(403);
            Session::flash('erro', 'Acesso restrito a administradores.');
            $this->redirect('/');
        }

        return $user;
    }

    /** @return array<string, mixed> */
    protected function requireAdminOuGeral(): array
    {
        $user = $this->requireAuth();
        if (!Auth::canVerChamadas()) {
            http_response_code(403);
            Session::flash('erro', 'Acesso restrito a administradores, perfil geral e coordenadores.');
            $this->redirect('/');
        }

        return $user;
    }

    protected function resolverPython3(): string
    {
        // No macOS, /usr/bin/python3 e um atalho do xcrun que falha quando o PHP roda como x86_64.
        $configurado = trim(Env::get('PYTHON_BIN', ''));
        if ($configurado !== '') {
            return $configurado;
        }

        foreach (['/usr/bin/python3', '/usr/local/bin/python3'] as $caminho) {
            if (is_executable($caminho)) {
                return $caminho;
            }
        }

        return 'python3';
    }

    /**
     * Inicia python/<script> em segundo plano, com a saída acrescentada a data/<log>.
     * Retorna a mensagem de erro, ou null se o processo foi disparado.
     */
    protected function iniciarScriptPython(string $script, string $log): ?string
    {
        $root = dirname(__DIR__, 2);
        $caminhoScript = $root . '/python/' . $script;
        if (!is_file($caminhoScript)) {
            return 'Script python/' . $script . ' não encontrado.';
        }

        $dataDir = $root . '/data';
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0775, true);
        }
        $caminhoLog = $dataDir . '/' . $log;
        $python = $this->resolverPython3();
        $cabecalho = sprintf("[%s] Geração solicitada via web (python: %s).\n", date('Y-m-d H:i:s'), $python);
        if (@file_put_contents($caminhoLog, $cabecalho, FILE_APPEND | LOCK_EX) === false) {
            return 'Não foi possível gravar em data/' . $log . '. Verifique permissões da pasta data/.';
        }

        if (!$this->dispararEmSegundoPlano(sprintf(
            'cd %s && nohup %s %s >> %s 2>&1 < /dev/null',
            escapeshellarg($root),
            escapeshellarg($python),
            escapeshellarg($caminhoScript),
            escapeshellarg($caminhoLog)
        ))) {
            @file_put_contents(
                $caminhoLog,
                sprintf("[%s] ERRO: não foi possível iniciar o processo em segundo plano.\n", date('Y-m-d H:i:s')),
                FILE_APPEND | LOCK_EX
            );

            return 'Não foi possível iniciar a geração. Verifique se popen/proc_open estão habilitados no PHP.';
        }

        return null;
    }

    protected function dispararEmSegundoPlano(string $comando): bool
    {
        if ($this->funcaoPhpDesabilitada('popen')) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $handle = @popen('start /B ' . $comando, 'r');
            if (!is_resource($handle)) {
                return false;
            }
            pclose($handle);

            return true;
        }

        // Subshell em background: PHP não espera o Python terminar (exec() com & bloqueia).
        $handle = @popen('(' . $comando . ') > /dev/null 2>&1 &', 'r');
        if (!is_resource($handle)) {
            return false;
        }
        pclose($handle);

        return true;
    }

    private function funcaoPhpDesabilitada(string $funcao): bool
    {
        $desabilitadas = ini_get('disable_functions');
        if (!is_string($desabilitadas) || trim($desabilitadas) === '') {
            return false;
        }

        $lista = array_map('trim', explode(',', strtolower($desabilitadas)));

        return in_array(strtolower($funcao), $lista, true);
    }
}
