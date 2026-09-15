<?php
declare(strict_types=1);

namespace Mapa\Controllers;

use Mapa\Core\Controller;
use Mapa\Core\Session;
use Mapa\Models\ConfigRepository;

/**
 * Parametros das regras de alarme (limites, janelas e mensagens).
 *
 * O que for salvo aqui vale para o portal e para a proxima execucao de
 * gerar_alarmes.py — nao ha mais limite fixo de 75% no codigo.
 */
class AlarmeConfigController extends Controller
{
    public function form(): void
    {
        $this->requireAdmin();
        $config = (new ConfigRepository())->getAlarmeConfig();

        $this->render('alarmes/config', [
            'config' => $config,
            'padrao' => ConfigRepository::ALARME_PADRAO,
            'alarmeConfig' => $config,
            'sucesso' => Session::flash('sucesso'),
            'erro' => Session::flash('erro'),
        ]);
    }

    public function save(): void
    {
        $this->requireAdmin();
        $repository = new ConfigRepository();

        if (isset($_POST['restaurar'])) {
            $repository->saveAlarmeConfig(ConfigRepository::ALARME_PADRAO);
            Session::flash('sucesso', 'Valores padrão restaurados. Rode a coleta para regerar os alarmes.');
            $this->redirect('/configuracoes/alarmes');
        }

        $entrada = [
            'frequencia_ativo' => isset($_POST['frequencia_ativo']),
            'frequencia_limite' => $_POST['frequencia_limite'] ?? '',
            'frequencia_limite_critico' => $_POST['frequencia_limite_critico'] ?? '',
            'frequencia_carencia_semanas' => $_POST['frequencia_carencia_semanas'] ?? '',
            'frequencia_mensagem' => $_POST['frequencia_mensagem'] ?? '',
            'faltas_dias_ativo' => isset($_POST['faltas_dias_ativo']),
            'faltas_dias_minimo' => $_POST['faltas_dias_minimo'] ?? '',
            'faltas_dias_janela' => $_POST['faltas_dias_janela'] ?? '',
            'faltas_dias_critico' => $_POST['faltas_dias_critico'] ?? '',
            'faltas_dias_mensagem' => $_POST['faltas_dias_mensagem'] ?? '',
            'faltas_semanas_ativo' => isset($_POST['faltas_semanas_ativo']),
            'faltas_semanas_total' => $_POST['faltas_semanas_total'] ?? '',
            'faltas_semanas_janela_dias' => $_POST['faltas_semanas_janela_dias'] ?? '',
            'faltas_semanas_severidade' => $_POST['faltas_semanas_severidade'] ?? '',
            'faltas_semanas_mensagem' => $_POST['faltas_semanas_mensagem'] ?? '',
        ];

        $resultado = $repository->validarAlarmeConfig($entrada);
        if ($resultado['erros'] !== []) {
            Session::flash('erro', implode(' ', $resultado['erros']));
            $this->redirect('/configuracoes/alarmes');
        }

        $repository->saveAlarmeConfig($resultado['config']);
        Session::flash(
            'sucesso',
            'Regras de alarme salvas. Os alarmes passam a usar os novos critérios na próxima coleta '
            . '(ou ao rodar python3 python/gerar_alarmes.py).'
        );
        $this->redirect('/configuracoes/alarmes');
    }
}
