<?php
declare(strict_types=1);

namespace Mapa\Models;

use Mapa\Core\Database;
use PDO;

/**
 * Contatos com o aluno (contatos_aluno): registrados na tela de alarmes ou
 * pelo e-mail automatico. Registrar um contato nao encerra o caso.
 */
class ContatoRepository
{
    /** Canais que a equipe registra na tela (o automatico e 'email_automatico'). */
    public const CANAIS = [
        'email' => 'E-mail enviado',
        'whatsapp' => 'WhatsApp',
        'telefone' => 'Ligação telefônica',
        'presencial' => 'Conversa presencial',
        'assistencia' => 'Encaminhamento para Assistência Estudantil',
    ];

    public const ROTULOS_CANAL = self::CANAIS + [
        'email_automatico' => 'E-mail automático',
        'nao_informado' => 'Contato sem tipo registrado',
    ];

    /**
     * Motivo principal da falta. Lista provisória: a confirmar com a
     * Assistência Estudantil (a chave é o que fica gravado).
     */
    public const MOTIVOS = [
        'trabalho' => 'Trabalho',
        'saude' => 'Saúde',
        'transporte' => 'Transporte',
        'financeiro' => 'Situação financeira',
        'familia' => 'Questões familiares',
        'dificuldade' => 'Dificuldade nas disciplinas',
        'desmotivacao' => 'Desmotivação com o curso',
        'ja_decidiu_sair' => 'Já decidiu sair do curso',
        'outro' => 'Outro',
    ];

    /** Contato registrado na tela pode ser de até tantos dias atrás. */
    public const DIAS_RETROATIVOS = 60;

    public const MAX_OBSERVACAO = 500;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * Valida o formulário de contato. $hoje no formato AAAA-MM-DD.
     *
     * @param array<string, mixed> $entrada
     * @return array{dados: array<string, mixed>, erros: list<string>}
     */
    public static function validar(array $entrada, string $hoje): array
    {
        $erros = [];

        $canal = trim((string)($entrada['canal'] ?? ''));
        if (!array_key_exists($canal, self::CANAIS)) {
            $erros[] = 'Escolha como foi o contato.';
        }

        $data = trim((string)($entrada['data_contato'] ?? ''));
        $dia = \DateTimeImmutable::createFromFormat('!Y-m-d', $data);
        if ($dia === false || $dia->format('Y-m-d') !== $data) {
            $erros[] = 'Informe a data do contato.';
        } else {
            $limite = (new \DateTimeImmutable($hoje))->modify('-' . self::DIAS_RETROATIVOS . ' days')->format('Y-m-d');
            if ($data > $hoje) {
                $erros[] = 'A data do contato não pode ser futura.';
            } elseif ($data < $limite) {
                $erros[] = 'A data do contato pode ser de até ' . self::DIAS_RETROATIVOS . ' dias atrás.';
            }
        }

        $falou = trim((string)($entrada['conseguiu_falar'] ?? ''));
        $conseguiuFalar = match ($falou) {
            '1' => 1,
            '0' => 0,
            default => null,
        };

        $motivo = trim((string)($entrada['motivo'] ?? ''));
        if ($motivo !== '' && !array_key_exists($motivo, self::MOTIVOS)) {
            $erros[] = 'Motivo inválido.';
        }

        $observacao = trim((string)($entrada['observacao'] ?? ''));
        if (mb_strlen($observacao) > self::MAX_OBSERVACAO) {
            $erros[] = 'A observação pode ter até ' . self::MAX_OBSERVACAO . ' caracteres.';
        }

        return [
            'dados' => [
                'canal' => $canal,
                'data_contato' => $data,
                'conseguiu_falar' => $conseguiuFalar,
                'motivo' => $motivo !== '' ? $motivo : null,
                'observacao' => $observacao !== '' ? $observacao : null,
            ],
            'erros' => $erros,
        ];
    }

    /**
     * @param array{
     *   aluno_id: int, curso_id: int, data_contato: string, canal: string,
     *   origem?: string, conseguiu_falar?: int|null, motivo?: string|null,
     *   observacao?: string|null, codigo_disciplina?: string|null,
     *   registrado_por?: int|null, alarme_email_id?: int|null
     * } $contato
     */
    public function registrar(array $contato): int
    {
        // O mesmo envio (alarme_email_id) vira um contato so, mesmo reenviado na coleta.
        $statement = $this->db->prepare(
            'INSERT INTO contatos_aluno (
                aluno_id, curso_id, data_contato, canal, origem, conseguiu_falar, motivo,
                observacao, codigo_disciplina, registrado_por, alarme_email_id
             ) VALUES (
                :aluno_id, :curso_id, :data_contato, :canal, :origem, :conseguiu_falar, :motivo,
                :observacao, :codigo_disciplina, :registrado_por, :alarme_email_id
             )
             ON CONFLICT(alarme_email_id) DO NOTHING'
        );
        $registradoPor = $contato['registrado_por'] ?? null;
        $statement->execute([
            'aluno_id' => (int)$contato['aluno_id'],
            'curso_id' => (int)$contato['curso_id'],
            'data_contato' => $contato['data_contato'],
            'canal' => $contato['canal'],
            'origem' => $contato['origem'] ?? 'manual',
            'conseguiu_falar' => $contato['conseguiu_falar'] ?? null,
            'motivo' => $contato['motivo'] ?? null,
            'observacao' => $contato['observacao'] ?? null,
            'codigo_disciplina' => ($contato['codigo_disciplina'] ?? '') !== '' ? $contato['codigo_disciplina'] : null,
            'registrado_por' => $registradoPor !== null && $registradoPor > 0 ? $registradoPor : null,
            'alarme_email_id' => $contato['alarme_email_id'] ?? null,
        ]);

        return $statement->rowCount() > 0 ? (int)$this->db->lastInsertId() : 0;
    }

    /**
     * Quantos contatos e o mais recente de cada aluno/curso (chave "aluno|curso").
     *
     * @param list<int> $alunoIds
     * @return array<string, array{total: int, ultimo: array<string, mixed>}>
     */
    public function resumoPorAluno(array $alunoIds): array
    {
        $alunoIds = array_values(array_unique(array_map('intval', $alunoIds)));
        if ($alunoIds === []) {
            return [];
        }

        $saida = [];
        foreach (array_chunk($alunoIds, 500) as $lote) {
            $placeholders = implode(', ', array_fill(0, count($lote), '?'));
            $statement = $this->db->prepare(
                'SELECT c.aluno_id, c.curso_id, c.data_contato, c.canal, c.origem,
                        c.conseguiu_falar, c.motivo, c.observacao, c.registrado_em,
                        u.nome AS registrado_por_nome
                 FROM contatos_aluno c
                 LEFT JOIN usuarios u ON u.id = c.registrado_por
                 WHERE c.aluno_id IN (' . $placeholders . ')
                 ORDER BY c.data_contato, c.registrado_em, c.id'
            );
            $statement->execute($lote);
            foreach ($statement->fetchAll() as $row) {
                $chave = (int)$row['aluno_id'] . '|' . (int)$row['curso_id'];
                $saida[$chave] = [
                    'total' => ($saida[$chave]['total'] ?? 0) + 1,
                    'ultimo' => $row,
                ];
            }
        }

        return $saida;
    }
}
