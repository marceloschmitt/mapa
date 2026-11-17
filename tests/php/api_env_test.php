<?php
declare(strict_types=1);

/*
 * Configuração da API pelo .env: vale mais que a tela, não é gravada no banco
 * e o secret não aparece na página. SQLite temporário.
 * Rodar na raiz do projeto: php tests/php/api_env_test.php
 */

chdir(dirname(__DIR__, 2));
require 'src/bootstrap.php';

use Mapa\Controllers\ApiConfigController;
use Mapa\Core\Auth;
use Mapa\Core\Database;
use Mapa\Core\Env;
use Mapa\Models\ConfigRepository;

$dbPath = tempnam(sys_get_temp_dir(), 'mapa_test_');
// saveApiConfig() também sincroniza config/consultas.json: guarda e devolve o arquivo real.
$consultas = 'config/consultas.json';
$consultasOriginal = is_file($consultas) ? file_get_contents($consultas) : null;
register_shutdown_function(static function () use ($dbPath, $consultas, $consultasOriginal): void {
    @unlink($dbPath);
    if ($consultasOriginal !== null) {
        file_put_contents($consultas, $consultasOriginal);
    }
});

$cache = new ReflectionProperty(Env::class, 'cache');
$cache->setAccessible(true);
$cache->setValue(null, [
    'DB_PATH' => $dbPath,
    'EMAIL_SEND' => 'false',
    'API_CLIENT_ID' => 'id-do-env',
    'API_CLIENT_SECRET' => 'segredo-do-env',
    'API_URL_ALUNOS' => 'https://env.exemplo/alunos?login={login}',
    'API_VERIFY_SSL' => 'true',
]);

$falhas = 0;
$total = 0;

function verificar(string $nome, $esperado, $obtido): void
{
    global $falhas, $total;
    $total++;
    if ($esperado === $obtido) {
        echo "ok   {$nome}\n";
        return;
    }
    $falhas++;
    echo "FALHA {$nome}\n      esperado: " . var_export($esperado, true)
        . "\n      obtido:   " . var_export($obtido, true) . "\n";
}

$pdo = Database::connection();
$repo = new ConfigRepository($pdo);
$doBanco = static fn(string $chave): string => (string)$pdo
    ->query("SELECT valor FROM configuracoes WHERE chave = '{$chave}'")->fetchColumn();

echo "== Abertura do banco\n";
verificar('o .env não é copiado para o banco', ['', ''], [$doBanco('api_client_id'), $doBanco('api_client_secret')]);

echo "== Leitura\n";
$config = $repo->getApiConfig();
verificar('env vale; secret não vai para a tela', ['id-do-env', '', 'https://env.exemplo/alunos?login={login}', true], [
    $config['client_id'], $config['client_secret'], $config['url_alunos'], $config['verify_ssl'],
]);
verificar('campos do .env', [
    'api_client_id' => 'API_CLIENT_ID',
    'api_client_secret' => 'API_CLIENT_SECRET',
    'api_url_alunos' => 'API_URL_ALUNOS',
    'api_verify_ssl' => 'API_VERIFY_SSL',
], $config['do_env']);
verificar('tem secret pelo .env', true, $repo->hasApiClientSecret());

echo "== Gravação\n";
$repo->saveApiConfig([
    'oauth_url' => 'https://tela/oauth/token',
    'client_id' => 'id-da-tela',
    'client_secret' => 'segredo-da-tela',
    'url_matriculados' => 'https://tela/matriculados',
    'url_alunos' => 'https://tela/{login}',
    'url_alunos_massa_cadastro' => 'https://tela/cadastro',
    'url_alunos_massa_intervalo' => 'https://tela/{data_inicial}/{data_final}',
    'verify_ssl' => false,
    'periodo_letivo' => '2026/2',
    'frequencia_data_inicial' => '03-08-2026',
    'frequencia_data_final' => '19-12-2026',
    'data_referencia' => 'hoje-2',
    'integrados_data_inicio' => '',
]);
verificar('campos do .env não são gravados', ['', '', '', 'false'], [
    $doBanco('api_client_id'), $doBanco('api_client_secret'), $doBanco('api_url_alunos'), $doBanco('api_verify_ssl'),
]);
verificar('os outros são gravados', ['https://tela/oauth/token', 'https://tela/cadastro', '2026/2'], [
    $doBanco('api_oauth_url'), $doBanco('api_url_alunos_massa_cadastro'), $doBanco('api_periodo_letivo'),
]);
verificar('a leitura continua com o .env', ['id-do-env', 'https://tela/oauth/token'], [
    $repo->getApiConfig()['client_id'], $repo->getApiConfig()['oauth_url'],
]);

echo "== Tela\n";
set_error_handler(static function (int $nivel, string $mensagem, string $arquivo, int $linha): bool {
    throw new ErrorException($mensagem, 0, $nivel, $arquivo, $linha);
});
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
Auth::login(['id' => 1, 'username' => 'admin', 'nome' => 'Admin', 'perfil' => 'administrador']);
$_SERVER['REQUEST_URI'] = '/configuracoes/api';
ob_start();
try {
    (new ApiConfigController())->form();
} finally {
    $html = (string)ob_get_clean();
}
restore_error_handler();
verificar('secret do .env nunca aparece na página', false, str_contains($html, 'segredo-do-env'));
verificar('campos do .env só leitura, com a variável', [true, true, true], [
    (bool)preg_match('/name="api_client_id"[^>]*readonly/s', $html),
    str_contains($html, '.env: API_CLIENT_ID'),
    str_contains($html, 'Definido no .env'),
]);
verificar('campo fora do .env continua editável', false,
    (bool)preg_match('/name="api_oauth_url"[^>]*readonly/s', $html));

echo "\n{$total} verificações, {$falhas} falha(s).\n";
exit($falhas > 0 ? 1 : 0);
