<?php
declare(strict_types=1);

namespace Mapa\Controllers;

use Mapa\Core\Auth;
use Mapa\Core\Controller;
use Mapa\Core\Session;
use Mapa\Models\UserRepository;

class UserController extends Controller
{
    public function index(): void
    {
        $this->requireAdmin();

        $repository = new UserRepository();

        $this->render('users/index', [
            'usuarios' => $repository->all(),
            'sucesso' => Session::flash('sucesso'),
            'erro' => Session::flash('erro'),
            'isAdmin' => Auth::isAdmin(),
        ]);
    }

    public function criarProfessores(): void
    {
        $this->requireAdmin();

        $resumo = (new UserRepository())->criarUsuariosApartirDeProfessores();
        if ($resumo['criados'] === 0 && $resumo['pulados'] === 0) {
            Session::flash('erro', 'Não há professores no banco para processar.');
            $this->redirect('/usuarios');
        }

        if ($resumo['criados'] === 0) {
            Session::flash('sucesso', 'Nenhum usuário novo: todos os docentes já tinham cadastro ou login existente.');
            $this->redirect('/usuarios');
        }

        $msg = $resumo['criados'] === 1
            ? '1 usuário professor criado.'
            : $resumo['criados'] . ' usuários professores criados.';
        if ($resumo['pulados'] > 0) {
            $msg .= ' ' . $resumo['pulados'] . ' ignorado(s).';
        }
        Session::flash('sucesso', $msg);
        $this->redirect('/usuarios');
    }

    public function createForm(): void
    {
        $this->requireAdmin();
        $repository = new UserRepository();

        $this->render('users/form', [
            'usuarioForm' => null,
            'cursos' => $repository->listarCursos(),
            'cursoIdsSelecionados' => [],
            'isAdmin' => true,
            'erro' => Session::flash('erro'),
        ]);
    }

    public function create(): void
    {
        $this->requireAdmin();

        $dados = $this->dadosDoFormulario();
        $erro = $this->validar($dados, true);

        if ($erro !== null) {
            Session::flash('erro', $erro);
            $this->redirect('/usuarios/novo');
        }

        $repository = new UserRepository();
        if ($repository->usernameExists($dados['username'])) {
            Session::flash('erro', 'Já existe um usuário com este login.');
            $this->redirect('/usuarios/novo');
        }

        if ($dados['cpf'] !== null && $dados['cpf'] !== '' && $repository->cpfExists((string)$dados['cpf'])) {
            Session::flash('erro', 'Já existe um usuário com este CPF.');
            $this->redirect('/usuarios/novo');
        }

        if ($dados['auth_type'] === 'local') {
            $dados['senha_hash'] = password_hash((string)$dados['senha'], PASSWORD_DEFAULT);
        } else {
            $dados['senha_hash'] = null;
        }

        $repository->create($dados);
        Session::flash('sucesso', 'Usuário cadastrado com sucesso.');
        $this->redirect('/usuarios');
    }

    public function editForm(): void
    {
        $this->requireAdmin();

        $id = (int)($_GET['id'] ?? 0);
        $repository = new UserRepository();
        $usuario = $repository->findById($id);

        if ($usuario === null) {
            Session::flash('erro', 'Usuário não encontrado.');
            $this->redirect('/usuarios');
        }

        $this->render('users/form', [
            'usuarioForm' => $usuario,
            'cursos' => $repository->listarCursos(),
            'cursoIdsSelecionados' => $repository->cursoIdsDoUsuario($id),
            'isAdmin' => true,
            'erro' => Session::flash('erro'),
        ]);
    }

    public function update(): void
    {
        $this->requireAdmin();

        $id = (int)($_POST['id'] ?? 0);
        $repository = new UserRepository();
        $usuario = $repository->findById($id);

        if ($usuario === null) {
            Session::flash('erro', 'Usuário não encontrado.');
            $this->redirect('/usuarios');
        }

        $dados = $this->dadosDoFormulario();
        $erro = $this->validar($dados, false, $usuario);

        if ($erro !== null) {
            Session::flash('erro', $erro);
            $this->redirect('/usuarios/editar?id=' . $id);
        }

        if ($repository->usernameExists($dados['username'], $id)) {
            Session::flash('erro', 'Já existe um usuário com este login.');
            $this->redirect('/usuarios/editar?id=' . $id);
        }

        if (
            $dados['cpf'] !== null
            && $dados['cpf'] !== ''
            && $repository->cpfExists((string)$dados['cpf'], $id)
        ) {
            Session::flash('erro', 'Já existe um usuário com este CPF.');
            $this->redirect('/usuarios/editar?id=' . $id);
        }

        if ($dados['auth_type'] === 'local' && $dados['senha'] !== '') {
            $dados['senha_hash'] = password_hash((string)$dados['senha'], PASSWORD_DEFAULT);
        } else {
            $dados['senha_hash'] = null;
        }

        $repository->update($id, $dados);

        $atual = Auth::user();
        if ($atual !== null && (int)$atual['id'] === $id) {
            $atualizado = $repository->findById($id);
            if ($atualizado !== null) {
                Auth::login(
                    $atualizado,
                    $repository->cursoIdsDoUsuario($id),
                    $repository->disciplinaCodigosDoUsuario($id)
                );
            }
        }

        Session::flash('sucesso', 'Usuário atualizado com sucesso.');
        $this->redirect('/usuarios');
    }

    public function delete(): void
    {
        $this->requireAdmin();

        $id = (int)($_POST['id'] ?? 0);
        $repository = new UserRepository();

        try {
            $repository->delete($id);
            Session::flash('sucesso', 'Usuário excluído.');
        } catch (\InvalidArgumentException $exception) {
            Session::flash('erro', $exception->getMessage());
        }

        $this->redirect('/usuarios');
    }

    /** @return array<string, mixed> */
    private function dadosDoFormulario(): array
    {
        $cursoIds = $_POST['curso_ids'] ?? [];
        if (!is_array($cursoIds)) {
            $cursoIds = [];
        }

        return [
            'username' => trim((string)($_POST['username'] ?? '')),
            'nome' => trim((string)($_POST['nome'] ?? '')),
            'email' => trim((string)($_POST['email'] ?? '')),
            'cpf' => trim((string)($_POST['cpf'] ?? '')),
            'perfil' => trim((string)($_POST['perfil'] ?? '')),
            'auth_type' => trim((string)($_POST['auth_type'] ?? 'local')),
            'ativo' => isset($_POST['ativo']) ? 1 : 0,
            'senha' => (string)($_POST['senha'] ?? ''),
            'curso_ids' => array_map('intval', $cursoIds),
        ];
    }

    /**
     * @param array<string, mixed> $dados
     * @param array<string, mixed>|null $usuarioAtual
     */
    private function validar(array $dados, bool $criar, ?array $usuarioAtual = null): ?string
    {
        if (trim((string)($dados['username'] ?? '')) === '') {
            return 'Informe o login do usuário.';
        }

        if (trim((string)$dados['nome']) === '') {
            return 'Informe o nome do usuário.';
        }

        $cpfInformado = trim((string)($dados['cpf'] ?? ''));
        if ($cpfInformado !== '') {
            $digitos = preg_replace('/\D+/', '', $cpfInformado) ?? '';
            if (strlen($digitos) !== 11) {
                return 'CPF inválido. Informe 11 dígitos.';
            }
        }

        if (!in_array($dados['perfil'], Auth::PERFIS, true)) {
            return 'Perfil inválido.';
        }

        if (!in_array($dados['auth_type'], ['local', 'ldap'], true)) {
            return 'Tipo de autenticação inválido.';
        }

        if ($dados['auth_type'] === 'local') {
            $authAnterior = (string)($usuarioAtual['auth_type'] ?? 'local');
            $precisaSenha = $criar
                || $authAnterior !== 'local'
                || trim((string)($usuarioAtual['senha_hash'] ?? '')) === '';

            if ($precisaSenha && $dados['senha'] === '') {
                return 'Informe a senha local do usuário.';
            }
            if ($dados['senha'] !== '' && strlen((string)$dados['senha']) < 6) {
                return 'A senha deve ter pelo menos 6 caracteres.';
            }
        }

        if ($dados['perfil'] === Auth::PERFIL_COORDENADOR && ($dados['curso_ids'] ?? []) === []) {
            return 'Selecione ao menos um curso para o coordenador.';
        }

        return null;
    }
}
