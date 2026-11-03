<?php
declare(strict_types=1);

namespace Mapa\Models;

use Mapa\Core\Database;
use PDO;

/**
 * Rodadas do pipeline de coleta (coleta_execucoes, gravadas por executar_coleta.py).
 */
class ColetaRepository
{
    /** Rodada em andamento ha mais que isso e tratada como interrompida. */
    public const HORAS_SEM_TERMINAR = 3;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /** @return array<string, mixed>|null */
    public function ultimaExecucao(): ?array
    {
        $row = $this->db->query(
            'SELECT id, iniciada_em, terminada_em, status, passo_falhou, coleta_id
             FROM coleta_execucoes
             ORDER BY id DESC
             LIMIT 1'
        )->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Aviso para o topo do portal quando a ultima rodada falhou ou nao terminou;
     * null quando esta tudo certo (ou nao ha rodada registrada).
     */
    public function avisoUltimaExecucao(?int $agora = null): ?string
    {
        $execucao = $this->ultimaExecucao();
        if ($execucao === null) {
            return null;
        }

        $agora ??= time();
        $iniciada = strtotime((string)$execucao['iniciada_em']);
        $quando = $iniciada !== false ? date('d/m H:i', $iniciada) : (string)$execucao['iniciada_em'];

        if ($execucao['status'] === 'falhou') {
            $passo = trim((string)($execucao['passo_falhou'] ?? ''));
            $texto = 'A coleta de ' . $quando . ' falhou' . ($passo !== '' ? ' em ' . $passo : '') . '.';
        } elseif (
            $execucao['status'] === 'em_andamento'
            && $iniciada !== false
            && $agora - $iniciada > self::HORAS_SEM_TERMINAR * 3600
        ) {
            $texto = 'A coleta iniciada em ' . $quando . ' não terminou.';
        } else {
            return null;
        }

        $dados = $this->dataDosDadosExibidos();

        return $texto . ($dados !== null ? ' O portal continua mostrando os dados de ' . $dados . '.' : '');
    }

    /** Data de referencia (dd/mm/aaaa) da ultima coleta completa. */
    private function dataDosDadosExibidos(): ?string
    {
        $referencia = $this->db->query(
            "SELECT data_referencia FROM coletas
             WHERE status IS NULL OR status = 'completa'
             ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        if (!is_string($referencia) || $referencia === '') {
            return null;
        }
        $ts = strtotime($referencia);

        return $ts !== false ? date('d/m/Y', $ts) : $referencia;
    }
}
