<?php
declare(strict_types=1);

namespace Mapa\Controllers;

use Mapa\Core\Auth;
use Mapa\Core\Controller;
use Mapa\Core\Session;
use Mapa\Models\ConfigRepository;

class LdapConfigController extends Controller
{
    private const ATRIBUTOS = [
        'uid',
        'sAMAccountName',
        'userPrincipalName',
        'cn',
        'mail',
    ];

    public function form(): void
    {
        $this->requireAdmin();
        $repository = new ConfigRepository();
        $config = $repository->getLdapConfig();

        $this->render('ldap/config', [
            'config' => $config,
            'temSenhaBind' => $repository->hasLdapBindPassword(),
            'atributos' => self::ATRIBUTOS,
            'sucesso' => Session::flash('sucesso'),
            'erro' => Session::flash('erro'),
            'isAdmin' => Auth::isAdmin(),
        ]);
    }

    public function save(): void
    {
        $this->requireAdmin();

        $host = trim((string)($_POST['ldap_host'] ?? ''));
        $baseDn = trim((string)($_POST['ldap_base_dn'] ?? ''));
        $bindDn = trim((string)($_POST['ldap_bind_dn'] ?? ''));
        $bindPassword = (string)($_POST['ldap_bind_password'] ?? '');
        $userAttribute = trim((string)($_POST['ldap_user_attribute'] ?? ''));

        if ($host === '') {
            Session::flash('erro', 'Informe o endereço do servidor LDAP.');
            $this->redirect('/configuracoes/ldap');
        }

        if ($baseDn === '') {
            Session::flash('erro', 'Informe a Base DN.');
            $this->redirect('/configuracoes/ldap');
        }

        if ($userAttribute === '' || !in_array($userAttribute, self::ATRIBUTOS, true)) {
            Session::flash('erro', 'Selecione um atributo de usuário válido.');
            $this->redirect('/configuracoes/ldap');
        }

        $dados = [
            'host' => $host,
            'base_dn' => $baseDn,
            'bind_dn' => $bindDn,
            'user_attribute' => $userAttribute,
        ];

        if ($bindPassword !== '') {
            $dados['bind_password'] = $bindPassword;
        }

        (new ConfigRepository())->saveLdapConfig($dados);
        Session::flash('sucesso', 'Configurações LDAP salvas com sucesso.');
        $this->redirect('/configuracoes/ldap');
    }
}
