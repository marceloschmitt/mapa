<?php
declare(strict_types=1);

/*
 * Contatos com o aluno, encerramento de casos, e-mail que não encerra e histórico
 * da configuração de alarmes, num SQLite temporário.
 * Rodar na raiz do projeto: php tests/php/contatos_test.php
 */

chdir(dirname(__DIR__, 2));
require 'src/bootstrap.php';

use Mapa\Core\Database;
use Mapa\Core\Env;
use Mapa\Models\AnalyticsRepository;
use Mapa\Models\ConfigRepository;
use Mapa\Models\ContatoRepository;
use Mapa\Services\AlarmeEmailService;

$dbPath = tempnam(sys_get_temp_dir(), 'mapa_test_');
register_shutdown_function(static function () use ($dbPath): void {
    @unlink($dbPath);
});

// Não lê o .env: o banco e o envio de e-mail ficam isolados do ambiente real.
$cache = new ReflectionProperty(Env::class, 'cache');
$cache->setAccessible(true);
$cache->setValue(null, ['DB_PATH' => $dbPath, 'EMAIL_SEND' => 'false']);

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

/** Chama um método privado (os testes olham o miolo do envio sem mandar e-mail). */
function privado(object $objeto, string $metodo, array $args = [])
{
    $ref = new ReflectionMethod($objeto, $metodo);
    $ref->setAccessible(true);

    return $ref->invokeArgs($objeto, $args);
}

$pdo = Database::connection();
$pdo->exec("INSERT INTO coletas (id) VALUES (1)");
$pdo->exec("INSERT INTO cursos (id, nome_curso) VALUES (1, 'Curso A')");
$pdo->exec("INSERT INTO alunos (id, login, matricula, nome, email) VALUES
            (1, 'a1', '1', 'Um', 'um@exemplo.br'), (2, 'a2', '2', 'Dois', 'dois@exemplo.br')");
$pdo->exec("INSERT INTO usuarios (id, username, nome, perfil) VALUES (7, 'ana', 'Ana', 'geral')");

echo "== Cópia do histórico (lado PHP)\n";
$pdo->exec("INSERT INTO alarme_emails (id, coleta_id, aluno_id, curso_id, destinatario, alarme_ids, enviado_em)
            VALUES (40, 1, 1, 1, 'um@exemplo.br', '1', '2026-09-10 08:00:00')");
$pdo->exec("INSERT INTO alarmes (coleta_id, aluno_id, curso_id, codigo_disciplina, tipo, mensagem,
                                 visualizado, visualizado_em, visualizado_por, contato_tipo) VALUES
            (1, 2, 1, 'X', 'faltas_3semanas', 'm', 1, '2026-09-12 10:00:00', 7, 'telefone'),
            (1, 2, 1, 'Y', 'percentual_baixo', 'm', 1, '2026-09-12 10:00:05', 7, 'telefone'),
            (1, 1, 1, 'X', 'faltas_3semanas', 'm', 1, '2026-09-10 08:00:01', NULL, 'email_automatico')");
$pdo->exec("DELETE FROM configuracoes WHERE chave = 'contatos_aluno_migrado'");
$conexao = new ReflectionProperty(Database::class, 'connection');
$conexao->setAccessible(true);
$conexao->setValue(null, null);
$pdo = Database::connection();
verificar('e-mail e contato manual copiados, sem repetir', [
    ['1', '2026-09-10', 'email_automatico', 'automatico', '40'],
    ['2', '2026-09-12', 'telefone', 'migrado', null],
], array_map(
    static fn(array $r): array => [(string)$r['aluno_id'], $r['data_contato'], $r['canal'], $r['origem'],
        $r['alarme_email_id'] !== null ? (string)$r['alarme_email_id'] : null],
    $pdo->query('SELECT * FROM contatos_aluno ORDER BY aluno_id')->fetchAll()
));
$pdo->exec('DELETE FROM contatos_aluno');
$pdo->exec('DELETE FROM alarmes');

echo "== Validação do formulário\n";
$valido = ContatoRepository::validar(
    ['canal' => 'telefone', 'data_contato' => '2026-11-02', 'conseguiu_falar' => '0', 'motivo' => 'trabalho'],
    '2026-11-03'
);
verificar('contato válido', [], $valido['erros']);
verificar('não conseguiu falar vira 0', 0, $valido['dados']['conseguiu_falar']);
verificar('data futura recusada', ['A data do contato não pode ser futura.'],
    ContatoRepository::validar(['canal' => 'telefone', 'data_contato' => '2026-11-04'], '2026-11-03')['erros']);
verificar('data antiga demais recusada', ['A data do contato pode ser de até 60 dias atrás.'],
    ContatoRepository::validar(['canal' => 'telefone', 'data_contato' => '2026-08-01'], '2026-11-03')['erros']);
verificar('canal automático não se registra à mão', ['Escolha como foi o contato.'],
    ContatoRepository::validar(['canal' => 'email_automatico', 'data_contato' => '2026-11-03'], '2026-11-03')['erros']);
verificar('motivo fora da lista recusado', ['Motivo inválido.'],
    ContatoRepository::validar(['canal' => 'telefone', 'data_contato' => '2026-11-03', 'motivo' => 'x'], '2026-11-03')['erros']);

echo "== Registro e resumo\n";
$contatos = new ContatoRepository($pdo);
$contatos->registrar(['aluno_id' => 2, 'curso_id' => 1, 'data_contato' => '2026-10-20', 'canal' => 'telefone',
    'conseguiu_falar' => 0, 'registrado_por' => 7]);
$contatos->registrar(['aluno_id' => 2, 'curso_id' => 1, 'data_contato' => '2026-10-27', 'canal' => 'presencial',
    'conseguiu_falar' => 1, 'motivo' => 'trabalho', 'registrado_por' => 7]);
$resumo = $contatos->resumoPorAluno([1, 2]);
verificar('só quem tem contato aparece', ['2|1'], array_keys($resumo));
verificar('total e último contato', [2, '2026-10-27', 'presencial', 'Ana'], [
    $resumo['2|1']['total'], $resumo['2|1']['ultimo']['data_contato'],
    $resumo['2|1']['ultimo']['canal'], $resumo['2|1']['ultimo']['registrado_por_nome'],
]);
$primeiro = $contatos->registrar(['aluno_id' => 1, 'curso_id' => 1, 'data_contato' => '2026-10-28',
    'canal' => 'email_automatico', 'origem' => 'automatico', 'alarme_email_id' => 99]);
$repetido = $contatos->registrar(['aluno_id' => 1, 'curso_id' => 1, 'data_contato' => '2026-10-28',
    'canal' => 'email_automatico', 'origem' => 'automatico', 'alarme_email_id' => 99]);
verificar('o mesmo envio vira um contato só', [true, 0, 1], [
    $primeiro > 0, $repetido,
    (int)$pdo->query('SELECT COUNT(*) FROM contatos_aluno WHERE alarme_email_id = 99')->fetchColumn(),
]);

echo "== Encerrar caso\n";
$pdo->exec("INSERT INTO alarmes (id, coleta_id, aluno_id, curso_id, codigo_disciplina, tipo, severidade, mensagem) VALUES
            (101, 1, 2, 1, 'X', 'faltas_3semanas', 'critico', 'm'),
            (102, 1, 2, 1, 'Y', 'percentual_baixo', 'alto', 'm')");
$repo = new AnalyticsRepository($pdo);
verificar('registrar contato não encerra', 2,
    (int)$pdo->query('SELECT COUNT(*) FROM alarmes WHERE aluno_id = 2 AND visualizado = 0')->fetchColumn());
verificar('encerra um alarme', true, $repo->encerrarAlarme(101, 7));
verificar('encerra o resto do caso', 1, $repo->encerrarAlarmesAluno(1, 2, 1, 7));
verificar('encerrados à mão, sem tipo de contato', [['1', 'manual', null], ['1', 'manual', null]], array_map(
    static fn(array $r): array => [(string)$r['visualizado'], $r['fechamento'], $r['contato_tipo']],
    $pdo->query('SELECT visualizado, fechamento, contato_tipo FROM alarmes WHERE aluno_id = 2')->fetchAll()
));

echo "== E-mail ao aluno não encerra e não se repete no episódio\n";
$pdo->exec("INSERT INTO alarmes (id, coleta_id, aluno_id, curso_id, codigo_disciplina, tipo, severidade, mensagem) VALUES
            (201, 1, 1, 1, 'X', 'faltas_3semanas', 'critico', 'm'),
            (202, 1, 1, 1, 'Y', 'percentual_baixo', 'alto', 'm')");
$servico = new AlarmeEmailService($pdo);
$grupos = privado($servico, 'agruparAlarmesParaEnvio', [1]);
verificar('crítico ainda não avisado entra no envio, com os dois alarmes', [[201, 202]],
    array_map(static fn(array $g): array => $g['alarme_ids'], array_values($grupos)));
privado($servico, 'registrarEnvio', [1, array_values($grupos)[0], 'um@exemplo.br', 7]);
privado($servico, 'marcarAlarmesEnviados', [[201, 202]]);
verificar('alarmes seguem abertos', 2,
    (int)$pdo->query('SELECT COUNT(*) FROM alarmes WHERE aluno_id = 1 AND visualizado = 0')->fetchColumn());
verificar('envio virou contato automático', ['email_automatico', 'automatico', '7'], array_values(array_map(
    'strval',
    $pdo->query("SELECT canal, origem, registrado_por FROM contatos_aluno
                 WHERE aluno_id = 1 AND alarme_email_id IS NOT NULL AND alarme_email_id <> 99")->fetch()
)));
verificar('não entra de novo no envio', [], privado($servico, 'agruparAlarmesParaEnvio', [1]));
verificar('a tela explica que o e-mail já foi enviado', ['1|1'],
    array_keys(privado($servico, 'criticosJaAvisados', [1])));

echo "== Histórico da configuração de alarmes\n";
$config = new ConfigRepository($pdo);
$config->saveAlarmeConfig(['frequencia_limite' => 70] + ConfigRepository::ALARME_PADRAO, 7);
$config->saveAlarmeConfig(ConfigRepository::ALARME_PADRAO, 7);
$historico = $pdo->query('SELECT config_json, salvo_por, observacao FROM alarme_config_historico ORDER BY id')->fetchAll();
verificar('primeira gravação guarda antes a que estava em vigor', 3, count($historico));
verificar('quem salvou e o valor de cada gravação', [[null, 75], ['7', 70], ['7', 75]], array_map(
    static fn(array $r): array => [
        $r['salvo_por'] !== null ? (string)$r['salvo_por'] : null,
        (int)json_decode($r['config_json'], true)['frequencia_limite'],
    ],
    $historico
));

echo "\n{$total} verificações, {$falhas} falha(s).\n";
exit($falhas > 0 ? 1 : 0);
