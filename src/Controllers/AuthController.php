<?php
declare(strict_types=1);

namespace Mapa\Controllers;

use Mapa\Core\Auth;
use Mapa\Core\Controller;
use Mapa\Core\Session;
use Mapa\Models\UserRepository;
use Mapa\Services\LdapAuthService;

class AuthController extends Controller
{
    public function setupForm(): void
    {
        $repository = new UserRepository();
        $admin = $repository->findAdminPendenteSenha();
        if ($admin === null) {
            $this->redirect('/login');
        }

        if (Auth::check()) {
            Auth::logout();
        }

        $this->render('auth/setup', [
            'erro' => Session::flash('erro'),
            'admin' => $admin,
        ], 'layouts/auth');
    }

    public function setupSave(): void
    {
        $repository = new UserRepository();
        $admin = $repository->findAdminPendenteSenha();
        if ($admin === null) {
            $this->redirect('/login');
        }

        $nova = (string)($_POST['senha_nova'] ?? '');
        $confirma = (string)($_POST['senha_confirma'] ?? '');

        if ($nova === '' || $confirma === '') {
            Session::flash('erro', 'Preencha a senha e a confirmação.');
            $this->redirect('/setup');
        }

        if (strlen($nova) < 6) {
            Session::flash('erro', 'A senha deve ter pelo menos 6 caracteres.');
            $this->redirect('/setup');
        }

        if ($nova !== $confirma) {
            Session::flash('erro', 'A confirmação não confere com a senha.');
            $this->redirect('/setup');
        }

        $repository->updatePassword((int)$admin['id'], password_hash($nova, PASSWORD_DEFAULT));

        $usuario = $repository->findById((int)$admin['id']);
        if ($usuario === null) {
            Session::flash('sucesso', 'Senha definida. Faça login.');
            $this->redirect('/login');
        }

        $cursoIds = $repository->cursoIdsDoUsuario((int)$usuario['id']);
        $disciplinaCodigos = $repository->disciplinaCodigosDoUsuario((int)$usuario['id']);
        Auth::login($usuario, $cursoIds, $disciplinaCodigos);
        Session::flash('sucesso', 'Senha do administrador definida. Bem-vindo ao MAPA.');
        $this->redirect('/');
    }

    public function loginForm(): void
    {
        if (Auth::check()) {
            $this->redirect('/');
        }

        $this->render('auth/login', [
            'erro' => Session::flash('erro'),
        ], 'layouts/auth');
    }

    public function login(): void
    {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            Session::flash('erro', 'Informe usuário e senha.');
            $this->redirect('/login');
        }

        $repository = new UserRepository();
        $usuario = $repository->findByUsername($username);

        if ($usuario === null) {
            Session::flash('erro', 'Usuário ou senha inválidos.');
            $this->redirect('/login');
        }

        if ((int)$usuario['ativo'] !== 1) {
            Session::flash('erro', 'Usuário inativo.');
            $this->redirect('/login');
        }

        $authType = (string)($usuario['auth_type'] ?? 'local');
        $autenticado = false;

        if ($authType === 'ldap') {
            try {
                $ldap = new LdapAuthService();
                $autenticado = $ldap->authenticate($username, $password);
                if (!$autenticado && $ldap->getLastError() !== '') {
                    Session::flash('erro', $ldap->getLastError());
                    $this->redirect('/login');
                }
            } catch (\Throwable $exception) {
                Session::flash('erro', $exception->getMessage());
                $this->redirect('/login');
            }
        } else {
            $senhaHash = trim((string)($usuario['senha_hash'] ?? ''));
            if ($senhaHash === '') {
                Session::flash('erro', 'Usuário sem senha local definida. Contate o administrador.');
                $this->redirect('/login');
            }
            $autenticado = password_verify($password, $senhaHash);
        }

        if (!$autenticado) {
            Session::flash('erro', 'Usuário ou senha inválidos.');
            $this->redirect('/login');
        }

        $cursoIds = $repository->cursoIdsDoUsuario((int)$usuario['id']);
        $disciplinaCodigos = $repository->disciplinaCodigosDoUsuario((int)$usuario['id']);
        Auth::login($usuario, $cursoIds, $disciplinaCodigos);
        $this->redirect('/');
    }

    public function logout(): void
    {
        Auth::logout();
        Session::destroy();
        Session::start();
        Session::flash('sucesso', 'Sessão encerrada.');
        $this->redirect('/login');
    }

    public function senhaForm(): void
    {
        $this->requireAuth();

        if (!Auth::usesLocalPassword()) {
            Session::flash('erro', 'Usuários LDAP não alteram senha no MAPA. Altere no servidor LDAP.');
            $this->redirect('/');
        }

        $this->render('auth/senha', [
            'erro' => Session::flash('erro'),
            'sucesso' => Session::flash('sucesso'),
            'isAdmin' => Auth::isAdmin(),
        ]);
    }

    public function senhaUpdate(): void
    {
        $user = $this->requireAuth();

        if (!Auth::usesLocalPassword()) {
            Session::flash('erro', 'Usuários LDAP não alteram senha no MAPA.');
            $this->redirect('/');
        }

        $atual = (string)($_POST['senha_atual'] ?? '');
        $nova = (string)($_POST['senha_nova'] ?? '');
        $confirma = (string)($_POST['senha_confirma'] ?? '');

        if ($atual === '' || $nova === '' || $confirma === '') {
            Session::flash('erro', 'Preencha todos os campos.');
            $this->redirect('/conta/senha');
        }

        if (strlen($nova) < 6) {
            Session::flash('erro', 'A nova senha deve ter pelo menos 6 caracteres.');
            $this->redirect('/conta/senha');
        }

        if ($nova !== $confirma) {
            Session::flash('erro', 'A confirmação não confere com a nova senha.');
            $this->redirect('/conta/senha');
        }

        $repository = new UserRepository();
        $usuario = $repository->findById((int)$user['id']);
        if ($usuario === null || (string)($usuario['auth_type'] ?? 'local') !== 'local') {
            Session::flash('erro', 'Usuário não encontrado ou sem autenticação local.');
            $this->redirect('/conta/senha');
        }

        $hashAtual = trim((string)($usuario['senha_hash'] ?? ''));
        if ($hashAtual === '' || !password_verify($atual, $hashAtual)) {
            Session::flash('erro', 'Senha atual incorreta.');
            $this->redirect('/conta/senha');
        }

        $repository->updatePassword((int)$user['id'], password_hash($nova, PASSWORD_DEFAULT));
        Session::flash('sucesso', 'Senha alterada com sucesso.');
        $this->redirect('/conta/senha');
    }
}
