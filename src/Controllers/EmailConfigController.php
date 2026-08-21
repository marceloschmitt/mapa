<?php
declare(strict_types=1);

namespace Mapa\Controllers;

use Mapa\Core\Auth;
use Mapa\Core\Controller;
use Mapa\Core\Env;
use Mapa\Core\Session;
use Mapa\Models\ConfigRepository;

class EmailConfigController extends Controller
{
    public function form(): void
    {
        $this->requireAdmin();
        $repository = new ConfigRepository();
        $config = $repository->getEmailConfig();

        $this->render('email/config', [
            'config' => $config,
            'temSenha' => $repository->hasEmailPassword(),
            'envioPermitido' => $repository->permiteEnvioEmail(),
            'staffApenasEmail' => trim(Env::get('EMAIL_ALARMES_STAFF_APENAS', '')),
            'alarmesDiaStaff' => trim(Env::get('EMAIL_ALARMES_DIA_STAFF', Env::get('EMAIL_ALARMES_DIA', 'quarta'))),
            'sucesso' => Session::flash('sucesso'),
            'erro' => Session::flash('erro'),
            'isAdmin' => Auth::isAdmin(),
        ]);
    }

    public function save(): void
    {
        $this->requireAdmin();

        $host = trim((string)($_POST['email_host'] ?? ''));
        $port = (int)($_POST['email_port'] ?? 587);
        $encryption = strtolower(trim((string)($_POST['email_encryption'] ?? 'tls')));
        $username = trim((string)($_POST['email_username'] ?? ''));
        $password = (string)($_POST['email_password'] ?? '');
        $fromAddress = trim((string)($_POST['email_from_address'] ?? ''));
        $fromName = trim((string)($_POST['email_from_name'] ?? 'MAPA'));
        $enabled = isset($_POST['email_enabled']);
        $alarmesAlunosEnabled = isset($_POST['email_alarmes_alunos_enabled']);
        $alarmesStaffEnabled = isset($_POST['email_alarmes_staff_enabled']);

        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            $encryption = 'tls';
        }

        if ($port <= 0 || $port > 65535) {
            Session::flash('erro', 'Informe uma porta SMTP válida.');
            $this->redirect('/configuracoes/email');
        }

        if ($host === '') {
            Session::flash('erro', 'Informe o host SMTP.');
            $this->redirect('/configuracoes/email');
        }

        if ($fromAddress === '' || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            Session::flash('erro', 'Informe um e-mail remetente válido.');
            $this->redirect('/configuracoes/email');
        }

        $repository = new ConfigRepository();
        if ($password === '' && !$repository->hasEmailPassword() && $username !== '') {
            Session::flash('erro', 'Informe a senha SMTP.');
            $this->redirect('/configuracoes/email');
        }

        $dados = [
            'enabled' => $enabled,
            'alarmes_alunos_enabled' => $alarmesAlunosEnabled,
            'alarmes_staff_enabled' => $alarmesStaffEnabled,
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'username' => $username,
            'from_address' => $fromAddress,
            'from_name' => $fromName !== '' ? $fromName : 'MAPA',
        ];
        if ($password !== '') {
            $dados['password'] = $password;
        }

        $repository->saveEmailConfig($dados);
        Session::flash('sucesso', 'Configurações de e-mail salvas com sucesso.');
        $this->redirect('/configuracoes/email');
    }
}
