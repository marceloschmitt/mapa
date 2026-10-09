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
            'integradosAnual' => $this->resumoIntegradosAnual($config['integrados_data_inicio']),
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
        $urlMassaCadastro = trim((string)($_POST['api_url_alunos_massa_cadastro'] ?? ''));
        $urlMassaIntervalo = trim((string)($_POST['api_url_alunos_massa_intervalo'] ?? ''));
        $verifySsl = isset($_POST['api_verify_ssl']);
        $periodoLetivo = trim((string)($_POST['api_periodo_letivo'] ?? ''));
        $dataInicial = trim((string)($_POST['frequencia_data_inicial'] ?? ''));
        $dataFinal = trim((string)($_POST['frequencia_data_final'] ?? ''));
        $dataReferencia = trim((string)($_POST['data_referencia'] ?? 'hoje-2'));
        $integradosInicio = trim((string)($_POST['integrados_data_inicio'] ?? ''));

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

        if ($urlMassaCadastro === '') {
            Session::flash('erro', 'Informe a URL do cadastro em massa.');
            $this->redirect('/configuracoes/api');
        }

        if ($urlMassaIntervalo === '') {
            Session::flash('erro', 'Informe a URL do intervalo em massa.');
            $this->redirect('/configuracoes/api');
        }

        if (strpos($urlMassaIntervalo, '{data_inicial}') === false
            || strpos($urlMassaIntervalo, '{data_final}') === false
        ) {
            Session::flash('erro', 'A URL do intervalo em massa deve conter {data_inicial} e {data_final}.');
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

        if ($integradosInicio !== '' && self::dataDdMmAaaa($integradosInicio) === null) {
            Session::flash('erro', 'Informe o início do ano letivo dos integrados no formato DD-MM-AAAA.');
            $this->redirect('/configuracoes/api');
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
            'url_alunos_massa_cadastro' => $urlMassaCadastro,
            'url_alunos_massa_intervalo' => $urlMassaIntervalo,
            'verify_ssl' => $verifySsl,
            'periodo_letivo' => $periodoLetivo,
            'frequencia_data_inicial' => $dataInicial,
            'frequencia_data_final' => $dataFinal,
            'data_referencia' => $dataReferencia,
            'integrados_data_inicio' => $integradosInicio,
        ];

        if ($clientSecret !== '') {
            $dados['client_secret'] = $clientSecret;
        }

        $repository->saveApiConfig($dados);
        Session::flash('sucesso', 'Configurações da API salvas com sucesso.');
        $this->redirect('/configuracoes/api');
    }

    public function buscarIntegradosAnual(): void
    {
        $this->requireAdmin();

        $inicio = (new ConfigRepository())->get(ConfigRepository::INTEGRADOS_DATA_INICIO);
        if (self::dataDdMmAaaa($inicio) === null) {
            Session::flash('erro', 'Preencha e salve o início do ano letivo dos integrados antes de buscar.');
            $this->redirect('/configuracoes/api');
        }

        $erro = $this->iniciarScriptPython('consulta_integrados_anual.py', 'integrados_anual.log');
        if ($erro !== null) {
            Session::flash('erro', $erro);
        } else {
            Session::flash(
                'sucesso',
                'Busca iniciada (leva alguns minutos). Acompanhe em data/integrados_anual.log e atualize a página depois.'
            );
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $this->redirect('/configuracoes/api');
    }

    private static function dataDdMmAaaa(string $texto): ?\DateTimeImmutable
    {
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', trim($texto), $m) !== 1
            || !checkdate((int)$m[2], (int)$m[1], (int)$m[3])
        ) {
            return null;
        }

        return new \DateTimeImmutable($m[3] . '-' . $m[2] . '-' . $m[1]);
    }

    /**
     * Resumo de data/json/integrados_anual_AAAA.json (gravado por consulta_integrados_anual.py).
     *
     * @return array{
     *   executado_em: string,
     *   data_inicial: string,
     *   data_final: string,
     *   erros: list<string>,
     *   blocos: list<array{data_inicial: string, data_final: string, consultado_em: string, vinculos: int}>
     * }|null
     */
    private function resumoIntegradosAnual(string $inicio): ?array
    {
        $data = self::dataDdMmAaaa($inicio);
        if ($data === null) {
            return null;
        }

        $caminho = dirname(__DIR__, 2) . '/data/json/integrados_anual_' . $data->format('Y') . '.json';
        if (!is_file($caminho)) {
            return null;
        }
        $dados = json_decode((string)file_get_contents($caminho), true);
        if (!is_array($dados)) {
            return null;
        }

        $blocos = [];
        foreach ((array)($dados['blocos'] ?? []) as $bloco) {
            if (!is_array($bloco)) {
                continue;
            }
            $blocos[] = [
                'data_inicial' => (string)($bloco['data_inicial'] ?? ''),
                'data_final' => (string)($bloco['data_final'] ?? ''),
                'consultado_em' => (string)($bloco['consultado_em'] ?? ''),
                'vinculos' => count((array)($bloco['vinculos'] ?? [])),
            ];
        }

        return [
            'executado_em' => (string)($dados['executado_em'] ?? ''),
            'data_inicial' => (string)($dados['data_inicial'] ?? ''),
            'data_final' => (string)($dados['data_final'] ?? ''),
            'erros' => array_values(array_map('strval', (array)($dados['erros'] ?? []))),
            'blocos' => $blocos,
        ];
    }
}
