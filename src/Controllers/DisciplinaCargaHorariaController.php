<?php
declare(strict_types=1);

namespace Mapa\Controllers;

use Mapa\Core\Controller;
use Mapa\Core\Session;
use Mapa\Models\DisciplinaCargaHorariaRepository;

class DisciplinaCargaHorariaController extends Controller
{
    public function index(): void
    {
        $this->requireAdmin();

        $curso = trim((string)($_GET['curso'] ?? ''));
        $repo = new DisciplinaCargaHorariaRepository();
        $this->render('carga_horaria/index', [
            'resumo' => $repo->resumo($curso !== '' ? $curso : null),
            'disciplinas' => $repo->listar($curso !== '' ? $curso : null),
            'cursos' => $repo->listarCursos(),
            'cursoSelecionado' => $curso,
            'sucesso' => Session::flash('sucesso'),
            'erro' => Session::flash('erro'),
        ]);
    }

    public function gerar(): void
    {
        $this->requireAdmin();

        $root = dirname(__DIR__, 2);
        $script = $root . '/python/gerar_carga_horaria.py';
        if (!is_file($script)) {
            Session::flash('erro', 'Script python/gerar_carga_horaria.py não encontrado.');
            $this->redirect('/configuracoes/carga-horaria');
        }

        $dataDir = $root . '/data';
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0775, true);
        }
        $log = $dataDir . '/carga_horaria.log';
        $python = $this->resolverPython3();
        $iniciadoEm = date('Y-m-d H:i:s');
        $cabecalho = sprintf("[%s] Geração solicitada via web (python: %s).\n", $iniciadoEm, $python);
        if (@file_put_contents($log, $cabecalho, FILE_APPEND | LOCK_EX) === false) {
            Session::flash(
                'erro',
                'Não foi possível gravar em data/carga_horaria.log. Verifique permissões da pasta data/.'
            );
            $this->redirect('/configuracoes/carga-horaria');
        }

        if (!$this->dispararEmSegundoPlano(sprintf(
            'cd %s && nohup %s %s --semestres 4 >> %s 2>&1 < /dev/null',
            escapeshellarg($root),
            escapeshellarg($python),
            escapeshellarg($script),
            escapeshellarg($log)
        ))) {
            @file_put_contents(
                $log,
                sprintf("[%s] ERRO: não foi possível iniciar o processo em segundo plano.\n", date('Y-m-d H:i:s')),
                FILE_APPEND | LOCK_EX
            );
            Session::flash(
                'erro',
                'Não foi possível iniciar a geração. Verifique se popen/proc_open estão habilitados no PHP.'
            );
            $this->redirect('/configuracoes/carga-horaria');
        }

        Session::flash(
            'sucesso',
            'Atualização de carga horária iniciada (4 últimos semestres). Acompanhe em data/carga_horaria.log e atualize a página em alguns minutos.'
        );
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $this->redirect('/configuracoes/carga-horaria');
    }

    private function resolverPython3(): string
    {
        foreach (['/usr/bin/python3', '/usr/local/bin/python3'] as $caminho) {
            if (is_executable($caminho)) {
                return $caminho;
            }
        }

        return 'python3';
    }

    private function dispararEmSegundoPlano(string $comando): bool
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

        $lista = array_map('trim', explode(',', $desabilitadas));

        return in_array($funcao, $lista, true);
    }
}
