<?php
declare(strict_types=1);

namespace Mapa\Services;

use Mapa\Core\Database;
use Mapa\Lib\SmtpMailer;
use Mapa\Models\AnalyticsRepository;
use Mapa\Models\ConfigRepository;
use PDO;
use Throwable;

class ChamadaEmailService
{
    public const DIAS_ATRASO = 2;

    private PDO $pdo;
    private ConfigRepository $config;
    private AnalyticsRepository $analytics;

    public function __construct(
        ?PDO $pdo = null,
        ?ConfigRepository $config = null,
        ?AnalyticsRepository $analytics = null
    ) {
        $this->pdo = $pdo ?? Database::connection();
        $this->config = $config ?? new ConfigRepository($this->pdo);
        $this->analytics = $analytics ?? new AnalyticsRepository();
    }

    /**
     * Envia e-mails para chamadas atrasadas ha pelo menos 2 dias.
     *
     * @return array{enviados: int, ignorados: int, falhas: int, mensagens: list<string>}
     */
    public function processar(?int $coletaId = null): array
    {
        $resumo = [
            'enviados' => 0,
            'ignorados' => 0,
            'falhas' => 0,
            'mensagens' => [],
        ];

        if (!$this->config->permiteEnvioEmail()) {
            $resumo['mensagens'][] = 'Envio bloqueado neste ambiente (EMAIL_SEND=false no .env).';
            return $resumo;
        }

        $emailConfig = $this->config->getEmailConfig();
        if (!$emailConfig['enabled']) {
            $resumo['mensagens'][] = 'Envio automatico desligado na configuracao.';
            return $resumo;
        }

        if (!$this->config->isEmailConfigured()) {
            $resumo['mensagens'][] = 'Servidor de e-mail incompleto (host/remetente).';
            $resumo['falhas']++;
            return $resumo;
        }

        $coleta = $coletaId !== null
            ? ['id' => $coletaId]
            : $this->analytics->ultimaColeta();
        if ($coleta === null) {
            $resumo['mensagens'][] = 'Nenhuma coleta importada.';
            return $resumo;
        }
        $coletaId = (int)$coleta['id'];

        $disciplinas = $this->analytics->disciplinasUltimaAula($coletaId, null);
        $jaEnviados = $this->mapaEmailsEnviados();
        $mailer = new SmtpMailer($emailConfig);
        $hoje = new \DateTimeImmutable('today');

        foreach ($disciplinas as $linha) {
            if (empty($linha['atrasado'])) {
                continue;
            }

            $diaEsperado = trim((string)($linha['dia_esperado'] ?? ''));
            if ($diaEsperado === '') {
                continue;
            }

            try {
                $dataEsperada = new \DateTimeImmutable($diaEsperado);
            } catch (Throwable $e) {
                continue;
            }

            $limite = $dataEsperada->modify('+' . self::DIAS_ATRASO . ' days');
            if ($hoje < $limite) {
                continue;
            }

            $codigo = trim((string)($linha['codigo_disciplina'] ?? ''));
            $cursoId = (int)($linha['curso_id'] ?? 0);
            $idTurma = (int)($linha['id_turma'] ?? 0);
            if ($codigo === '' || $cursoId <= 0) {
                continue;
            }

            // Turma com alunos de dois cursos tem duas linhas e recebe um e-mail só.
            $enviado = $this->envioRegistrado($jaEnviados, $linha);
            if ($enviado !== null) {
                $resumo['ignorados']++;
                continue;
            }

            $nomeTurma = trim((string)($linha['nome_turma'] ?? ''));
            $destinatarios = $idTurma > 0
                ? $this->emailsProfessoresTurma($idTurma)
                : $this->emailsProfessores($codigo);
            $rotulo = $nomeTurma !== '' ? "{$codigo} ({$nomeTurma})" : $codigo;
            if ($destinatarios === []) {
                $resumo['ignorados']++;
                $resumo['mensagens'][] = "Sem e-mail de professor: {$rotulo}";
                continue;
            }

            $disciplina = trim((string)($linha['disciplina'] ?? $codigo));
            $dataFmt = $dataEsperada->format('d/m/Y');
            $assunto = 'Chamada não preenchida — ' . $disciplina
                . ($nomeTurma !== '' ? ' (' . $nomeTurma . ')' : '');
            $corpo = $this->montarMensagem($disciplina, $dataFmt, $nomeTurma);

            try {
                $mailer->send($destinatarios, $assunto, $corpo);
                if ($idTurma > 0) {
                    $this->registrarEnvioTurma($idTurma, $diaEsperado, $destinatarios, $coletaId);
                } else {
                    $this->registrarEnvio(
                        $codigo,
                        $disciplina,
                        $cursoId,
                        $diaEsperado,
                        $destinatarios,
                        $coletaId
                    );
                }
                $jaEnviados[self::chaveEnvio($linha)] = [
                    'enviado_em' => date('Y-m-d H:i:s'),
                    'destinatarios' => implode(', ', $destinatarios),
                ];
                $resumo['enviados']++;
            } catch (Throwable $e) {
                $resumo['falhas']++;
                $resumo['mensagens'][] = "Falha {$rotulo}: " . $e->getMessage();
            }
        }

        return $resumo;
    }

    public function montarMensagem(string $disciplina, string $dataFmt, string $nomeTurma = ''): string
    {
        $alvo = $nomeTurma !== ''
            ? "da disciplina {$disciplina}, {$nomeTurma},"
            : "da disciplina {$disciplina},";

        return "Esta é uma mensagem automática, enviada quando não houve o preenchimento da lista de presença de uma disciplina.\n\n"
            . "Aparentemente, você não preencheu a chamada {$alvo} no dia {$dataFmt}.\n\n"
            . 'Obrigado.';
    }

    /**
     * Chave de envio da linha de chamada: "T<id_turma>|data" para turma,
     * "codigo|curso_id|data" para disciplina sem turma.
     *
     * @param array<string, mixed> $linha
     */
    public static function chaveEnvio(array $linha): string
    {
        $dia = trim((string)($linha['dia_esperado'] ?? ''));
        $idTurma = (int)($linha['id_turma'] ?? 0);
        if ($idTurma > 0) {
            return 'T' . $idTurma . '|' . $dia;
        }

        return trim((string)($linha['codigo_disciplina'] ?? ''))
            . '|'
            . (int)($linha['curso_id'] ?? 0)
            . '|'
            . $dia;
    }

    /**
     * Envio já registrado para a linha. Na linha de turma também vale o e-mail
     * antigo por disciplina/curso do mesmo dia: foi para todos os professores
     * do código, inclusive os da turma.
     *
     * @param array<string, array{enviado_em: string, destinatarios: string}> $mapa
     * @param array<string, mixed> $linha
     * @return array{enviado_em: string, destinatarios: string}|null
     */
    public function envioRegistrado(array $mapa, array $linha): ?array
    {
        $info = $mapa[self::chaveEnvio($linha)] ?? null;
        if ($info === null && (int)($linha['id_turma'] ?? 0) > 0) {
            $semTurma = $linha;
            $semTurma['id_turma'] = null;
            $info = $mapa[self::chaveEnvio($semTurma)] ?? null;
        }

        return $info;
    }

    /**
     * @return array<string, array{enviado_em: string, destinatarios: string}>
     */
    public function mapaEmailsEnviados(): array
    {
        $mapa = [];
        $statement = $this->pdo->query(
            'SELECT codigo_disciplina, curso_id, data_esperada, enviado_em, destinatarios
             FROM chamada_emails'
        );
        foreach ($statement->fetchAll() as $row) {
            $chave = self::chaveEnvio([
                'codigo_disciplina' => $row['codigo_disciplina'],
                'curso_id' => $row['curso_id'],
                'dia_esperado' => $row['data_esperada'],
            ]);
            $mapa[$chave] = [
                'enviado_em' => (string)$row['enviado_em'],
                'destinatarios' => (string)$row['destinatarios'],
            ];
        }

        $statement = $this->pdo->query(
            'SELECT id_turma, data_esperada, enviado_em, destinatarios
             FROM turma_chamada_emails'
        );
        foreach ($statement->fetchAll() as $row) {
            $chave = self::chaveEnvio([
                'id_turma' => $row['id_turma'],
                'dia_esperado' => $row['data_esperada'],
            ]);
            $mapa[$chave] = [
                'enviado_em' => (string)$row['enviado_em'],
                'destinatarios' => (string)$row['destinatarios'],
            ];
        }

        return $mapa;
    }

    /**
     * @return list<string>
     */
    private function emailsProfessoresTurma(int $idTurma): array
    {
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT TRIM(p.email) AS email
             FROM turma_professores tp
             INNER JOIN professores p ON p.id = tp.professor_id
             WHERE tp.id_turma = :id_turma
               AND p.email IS NOT NULL
               AND TRIM(p.email) != \'\''
        );
        $statement->execute(['id_turma' => $idTurma]);

        return $this->filtrarEmails($statement->fetchAll());
    }

    /**
     * @return list<string>
     */
    private function emailsProfessores(string $codigoDisciplina): array
    {
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT TRIM(p.email) AS email
             FROM disciplina_professores dp
             INNER JOIN professores p ON p.id = dp.professor_id
             WHERE dp.codigo_disciplina = :codigo
               AND p.email IS NOT NULL
               AND TRIM(p.email) != \'\''
        );
        $statement->execute(['codigo' => $codigoDisciplina]);

        return $this->filtrarEmails($statement->fetchAll());
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function filtrarEmails(array $rows): array
    {
        $emails = [];
        foreach ($statement->fetchAll() as $row) {
            $email = trim((string)($row['email'] ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[$email] = true;
            }
        }

        return array_keys($emails);
    }

    /**
     * @param list<string> $destinatarios
     */
    private function registrarEnvio(
        string $codigo,
        string $disciplina,
        int $cursoId,
        string $dataEsperada,
        array $destinatarios,
        int $coletaId
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO chamada_emails (
                codigo_disciplina, disciplina, curso_id, data_esperada,
                destinatarios, enviado_em, coleta_id
             ) VALUES (
                :codigo, :disciplina, :curso_id, :data_esperada,
                :destinatarios, datetime(\'now\', \'localtime\'), :coleta_id
             )
             ON CONFLICT(codigo_disciplina, curso_id, data_esperada) DO UPDATE SET
                disciplina = excluded.disciplina,
                destinatarios = excluded.destinatarios,
                enviado_em = excluded.enviado_em,
                coleta_id = excluded.coleta_id'
        );
        $statement->execute([
            'codigo' => $codigo,
            'disciplina' => $disciplina,
            'curso_id' => $cursoId,
            'data_esperada' => $dataEsperada,
            'destinatarios' => implode(', ', $destinatarios),
            'coleta_id' => $coletaId,
        ]);
    }

    /**
     * @param list<string> $destinatarios
     */
    private function registrarEnvioTurma(
        int $idTurma,
        string $dataEsperada,
        array $destinatarios,
        int $coletaId
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO turma_chamada_emails (
                id_turma, data_esperada, destinatarios, enviado_em, coleta_id
             ) VALUES (
                :id_turma, :data_esperada, :destinatarios, datetime(\'now\', \'localtime\'), :coleta_id
             )
             ON CONFLICT(id_turma, data_esperada) DO UPDATE SET
                destinatarios = excluded.destinatarios,
                enviado_em = excluded.enviado_em,
                coleta_id = excluded.coleta_id'
        );
        $statement->execute([
            'id_turma' => $idTurma,
            'data_esperada' => $dataEsperada,
            'destinatarios' => implode(', ', $destinatarios),
            'coleta_id' => $coletaId,
        ]);
    }
}
