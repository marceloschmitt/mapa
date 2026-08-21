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
            if ($codigo === '' || $cursoId <= 0) {
                continue;
            }

            $chave = $codigo . '|' . $cursoId . '|' . $diaEsperado;
            if (isset($jaEnviados[$chave])) {
                $resumo['ignorados']++;
                continue;
            }

            $destinatarios = $this->emailsProfessores($codigo);
            if ($destinatarios === []) {
                $resumo['ignorados']++;
                $resumo['mensagens'][] = "Sem e-mail de professor: {$codigo}";
                continue;
            }

            $disciplina = trim((string)($linha['disciplina'] ?? $codigo));
            $dataFmt = $dataEsperada->format('d/m/Y');
            $assunto = 'Chamada não preenchida — ' . $disciplina;
            $corpo = $this->montarMensagem($disciplina, $dataFmt);

            try {
                $mailer->send($destinatarios, $assunto, $corpo);
                $this->registrarEnvio(
                    $codigo,
                    $disciplina,
                    $cursoId,
                    $diaEsperado,
                    $destinatarios,
                    $coletaId
                );
                $jaEnviados[$chave] = true;
                $resumo['enviados']++;
            } catch (Throwable $e) {
                $resumo['falhas']++;
                $resumo['mensagens'][] = "Falha {$codigo}: " . $e->getMessage();
            }
        }

        return $resumo;
    }

    public function montarMensagem(string $disciplina, string $dataFmt): string
    {
        return "Esta é uma mensagem automática, enviada quando não houve o preenchimento da lista de presença de uma disciplina.\n\n"
            . "Aparentemente, você não preencheu a chamada da disciplina {$disciplina}, no dia {$dataFmt}.\n\n"
            . 'Obrigado.';
    }

    /**
     * @return array<string, array{enviado_em: string, destinatarios: string}>
     */
    public function mapaEmailsEnviados(): array
    {
        $statement = $this->pdo->query(
            'SELECT codigo_disciplina, curso_id, data_esperada, enviado_em, destinatarios
             FROM chamada_emails'
        );
        $mapa = [];
        foreach ($statement->fetchAll() as $row) {
            $chave = trim((string)$row['codigo_disciplina'])
                . '|'
                . (int)$row['curso_id']
                . '|'
                . trim((string)$row['data_esperada']);
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
                :destinatarios, datetime(\'now\'), :coleta_id
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
}
