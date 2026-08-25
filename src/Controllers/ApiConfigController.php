<?php
declare(strict_types=1);

namespace Mapa\Controllers;

use Mapa\Core\Auth;
use Mapa\Core\Controller;
use Mapa\Core\Session;
use Mapa\Models\ConfigRepository;

class ApiConfigController extends Controller
{
    public function form(): void
    {
        $this->requireAdmin();
        $repository = new ConfigRepository();
        $config = $repository->getApiConfig();

        $this->render('api/config', [
            'config' => $config,
            'temClientSecret' => $repository->hasApiClientSecret(),
            'sucesso' => Session::flash('sucesso'),
            'erro' => Session::flash('erro'),
            'isAdmin' => Auth::isAdmin(),
        ]);
    }

    public function save(): void
    {
        $this->requireAdmin();

        $oauthUrl = trim((string)($_POST['api_oauth_url'] ?? ''));
        $clientId = trim((string)($_POST['api_client_id'] ?? ''));
        $clientSecret = (string)($_POST['api_client_secret'] ?? '');
        $urlMatriculados = trim((string)($_POST['api_url_matriculados'] ?? ''));
        $urlAlunos = trim((string)($_POST['api_url_alunos'] ?? ''));
        $verifySsl = isset($_POST['api_verify_ssl']);
        $periodoLetivo = trim((string)($_POST['api_periodo_letivo'] ?? ''));
        $dataInicial = trim((string)($_POST['frequencia_data_inicial'] ?? ''));
        $dataFinal = trim((string)($_POST['frequencia_data_final'] ?? ''));
        $dataReferencia = trim((string)($_POST['data_referencia'] ?? 'hoje-2'));

        if ($oauthUrl === '') {
            Session::flash('erro', 'Informe a URL OAuth da API.');
            $this->redirect('/configuracoes/api');
        }

        if ($clientId === '') {
            Session::flash('erro', 'Informe o Client ID.');
            $this->redirect('/configuracoes/api');
        }

        if ($urlMatriculados === '') {
            Session::flash('erro', 'Informe a URL de matriculados.');
            $this->redirect('/configuracoes/api');
        }

        if ($urlAlunos === '') {
            Session::flash('erro', 'Informe a URL de alunos.');
            $this->redirect('/configuracoes/api');
        }

        if (strpos($urlAlunos, '{login}') === false) {
            Session::flash('erro', 'A URL de alunos deve conter o marcador {login}.');
            $this->redirect('/configuracoes/api');
        }

        if ($periodoLetivo === '') {
            Session::flash('erro', 'Informe o período letivo (ex.: 2026/2).');
            $this->redirect('/configuracoes/api');
        }

        if (preg_match('/[?&]periodo_letivo=/i', $urlMatriculados)
            || strpos($urlMatriculados, '{periodo_letivo}') !== false
        ) {
            Session::flash(
                'erro',
                'Remova periodo_letivo da URL de matriculados. Informe o período só no campo Período letivo.'
            );
            $this->redirect('/configuracoes/api');
        }

        if ($dataInicial === '' || $dataFinal === '') {
            Session::flash('erro', 'Informe o intervalo de frequência (data inicial e final).');
            $this->redirect('/configuracoes/api');
        }

        if ($dataReferencia === '') {
            $dataReferencia = 'hoje-2';
        }

        $repository = new ConfigRepository();
        if ($clientSecret === '' && !$repository->hasApiClientSecret()) {
            Session::flash('erro', 'Informe o Client Secret.');
            $this->redirect('/configuracoes/api');
        }

        $dados = [
            'oauth_url' => $oauthUrl,
            'client_id' => $clientId,
            'url_matriculados' => $urlMatriculados,
            'url_alunos' => $urlAlunos,
            'verify_ssl' => $verifySsl,
            'periodo_letivo' => $periodoLetivo,
            'frequencia_data_inicial' => $dataInicial,
            'frequencia_data_final' => $dataFinal,
            'data_referencia' => $dataReferencia,
        ];

        if ($clientSecret !== '') {
            $dados['client_secret'] = $clientSecret;
        }

        $repository->saveApiConfig($dados);
        Session::flash('sucesso', 'Configurações da API salvas com sucesso.');
        $this->redirect('/configuracoes/api');
    }
}
