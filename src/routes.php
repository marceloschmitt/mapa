<?php
declare(strict_types=1);

use Mapa\Controllers\AlarmeController;
use Mapa\Controllers\AnalyticsController;
use Mapa\Controllers\ApiConfigController;
use Mapa\Controllers\AuthController;
use Mapa\Controllers\ChamadasController;
use Mapa\Controllers\DashboardController;
use Mapa\Controllers\CoordenacaoConfigController;
use Mapa\Controllers\EmailConfigController;
use Mapa\Controllers\IngressantesController;
use Mapa\Controllers\LdapConfigController;
use Mapa\Controllers\TrancadosController;
use Mapa\Controllers\PerdaVagaController;
use Mapa\Controllers\PasseLivreController;
use Mapa\Controllers\UserController;
use Mapa\Core\Router;

return static function (Router $router): void {
    $router->get('/', [DashboardController::class, 'index']);

    $router->get('/login', [AuthController::class, 'loginForm']);
    $router->post('/login', [AuthController::class, 'login']);
    $router->get('/logout', [AuthController::class, 'logout']);
    $router->get('/setup', [AuthController::class, 'setupForm']);
    $router->post('/setup', [AuthController::class, 'setupSave']);
    $router->get('/conta/senha', [AuthController::class, 'senhaForm']);
    $router->post('/conta/senha', [AuthController::class, 'senhaUpdate']);

    $router->get('/analytics', [AnalyticsController::class, 'index']);
    $router->get('/alarmes', [AlarmeController::class, 'index']);
    $router->post('/alarmes/visualizar', [AlarmeController::class, 'visualizar']);
    $router->post('/alarmes/enviar-email', [AlarmeController::class, 'enviarEmail']);
    $router->get('/ingressantes', [IngressantesController::class, 'index']);
    $router->get('/trancados', [TrancadosController::class, 'index']);
    $router->get('/perda-vaga', [PerdaVagaController::class, 'index']);
    $router->get('/passe-livre', [PasseLivreController::class, 'index']);
    $router->post('/passe-livre/gerar', [PasseLivreController::class, 'gerar']);
    $router->get('/chamadas', [ChamadasController::class, 'index']);
    $router->get('/chamadas/exportar-atrasadas-1-semestre', [ChamadasController::class, 'exportarAtrasadasPrimeiroSemestre']);

    $router->get('/usuarios', [UserController::class, 'index']);
    $router->get('/usuarios/novo', [UserController::class, 'createForm']);
    $router->post('/usuarios', [UserController::class, 'create']);
    $router->post('/usuarios/criar-professores', [UserController::class, 'criarProfessores']);
    $router->get('/usuarios/editar', [UserController::class, 'editForm']);
    $router->post('/usuarios/atualizar', [UserController::class, 'update']);
    $router->post('/usuarios/excluir', [UserController::class, 'delete']);

    $router->get('/configuracoes/ldap', [LdapConfigController::class, 'form']);
    $router->post('/configuracoes/ldap', [LdapConfigController::class, 'save']);
    $router->get('/configuracoes/api', [ApiConfigController::class, 'form']);
    $router->post('/configuracoes/api', [ApiConfigController::class, 'save']);
    $router->get('/configuracoes/email', [EmailConfigController::class, 'form']);
    $router->post('/configuracoes/email', [EmailConfigController::class, 'save']);
    $router->get('/configuracoes/coordenacao', [CoordenacaoConfigController::class, 'form']);
    $router->post('/configuracoes/coordenacao', [CoordenacaoConfigController::class, 'save']);
};
