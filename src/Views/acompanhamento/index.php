<?php

use Mapa\Models\AcompanhamentoRepository as Repo;
use Mapa\Models\EfeitoContatosRepository as Efeito;

$execucao = $execucao ?? null;
$escopo = $escopo ?? ['filtros' => [], 'aviso' => null, 'cursos' => [], 'cursoSelecionado' => 'todos', 'rotulo' => null];
$filtros = $filtros ?? [];
$query = $query ?? [];
$series = $series ?? null;
$avaliacao = $avaliacao ?? null;
$curva = $curva ?? null;
$lista = $lista ?? null;
$rotulosCanal = $rotulosCanal ?? [];
$rotulosMotivo = $rotulosMotivo ?? [];

$e = static fn(string $texto): string => htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
$num = static fn(int $valor): string => number_format($valor, 0, ',', '.');
$dataCurta = static function (?string $valor): string {
    $ts = strtotime((string)$valor);

    return $ts !== false ? date('d/m', $ts) : '';
};
$dataLonga = static function (?string $valor): string {
    $ts = strtotime((string)$valor);

    return $ts !== false ? date('d/m/Y', $ts) : '';
};
// Percentual inteiro de k em n ("—" sem base).
$pct = static fn(int $k, int $n): string => $n > 0 ? number_format(100 * $k / $n, 0, ',', '.') . '%' : '—';
$pres = static fn(?float $valor): string => $valor === null ? '—' : number_format($valor, 0, ',', '.') . '%';
// Com sinal; "−" tipográfico para não confundir com hífen.
$sinal = static function (?float $valor, int $casas = 1): string {
    if ($valor === null) {
        return '—';
    }
    $texto = number_format(abs($valor), $casas, ',', '.');
    if ((float)str_replace(',', '.', $texto) === 0.0) {
        return $texto;
    }

    return ($valor < 0 ? '−' : '+') . $texto;
};
// URL da tela com os filtros atuais trocados por $troca (null remove).
$link = static function (array $troca = [], string $ancora = '') use ($query): string {
    $novo = $query;
    unset($novo['pagina']);
    foreach ($troca as $nome => $valor) {
        if ($valor === null || $valor === '') {
            unset($novo[$nome]);
        } else {
            $novo[$nome] = (string)$valor;
        }
    }
    $qs = http_build_query($novo);

    return url('/acompanhamento') . ($qs !== '' ? '?' . $qs : '') . $ancora;
};

// Lado do efeito líquido (presença): 1 = contatados melhoraram mais, -1 = menos, 0 = pode ser acaso.
$lado = static function (?array $efeito): ?int {
    if ($efeito === null) {
        return null;
    }
    if (round($efeito['min'], 1) > 0) {
        return 1;
    }

    return round($efeito['max'], 1) < 0 ? -1 : 0;
};
$selos = [
    1 => ['Contato da equipe ajuda', 'selo-melhor'],
    0 => ['Sem diferença clara', 'selo-incerto'],
    -1 => ['Pior com contato da equipe', 'selo-pior'],
];
$selosVoltou = [
    'melhor' => ['Voltam mais com a equipe', 'selo-melhor'],
    'incerto' => ['Sem diferença clara', 'selo-incerto'],
    'pior' => ['Voltam menos com a equipe', 'selo-pior'],
    'poucos' => ['Poucos alunos', 'selo-incerto'],
];
$celulaEfeito = static function (?array $efeito) use ($lado, $sinal, $e): string {
    if ($efeito === null) {
        return '<span class="text-secondary small">poucos alunos</span>';
    }
    $classe = [1 => 'text-success', 0 => 'text-secondary', -1 => 'text-danger'][$lado($efeito)];

    return '<span class="fw-semibold ' . $classe . '">' . $e($sinal($efeito['valor'])) . ' p.p.</span>'
        . '<div class="small text-secondary text-nowrap">de ' . $e($sinal($efeito['min'])) . ' a '
        . $e($sinal($efeito['max'])) . '</div>';
};
$celulaVoltou = static function (array $voltou, array $grupo) use ($pct, $sinal, $e, $num): string {
    $base = (int)$grupo['abaixo_antes'];
    if ($base === 0) {
        return '<span class="text-secondary">—</span>';
    }

    return '<span class="fw-semibold">' . $pct((int)$grupo['voltaram'], $base) . '</span>'
        . '<div class="small text-secondary text-nowrap">' . $num((int)$grupo['voltaram']) . ' de ' . $num($base) . '</div>';
};
$celulaDiferenca = static function (array $voltou) use ($sinal, $e): string {
    if ($voltou['diferenca'] === null) {
        return '<span class="text-secondary small">poucos alunos</span>';
    }
    $classe = ['melhor' => 'text-success', 'pior' => 'text-danger'][$voltou['selo']] ?? 'text-secondary';

    return '<span class="fw-semibold ' . $classe . '">' . $e($sinal($voltou['diferenca'], 0)) . ' p.p.</span>'
        . '<div class="small text-secondary text-nowrap">margem ±' . $e(number_format((float)$voltou['margem'], 0, ',', '.')) . '</div>';
};

$semSeletorCurso = $escopo['cursos'] === [];
$cursoSelecionado = (string)$escopo['cursoSelecionado'];
$situacaoFiltro = $filtros['situacao'] ?? null;
$minAlunos = Efeito::MIN_ALUNOS;
$semanasJanela = is_array($series) ? max(1, intdiv((int)$series['janela_dias'], 7)) : 2;
$minAulas = is_array($series) ? (int)$series['min_aulas'] : 3;

// Curva semanal (presença): semanas com menos de MIN_ALUNOS alunos ficam de fora.
$grafico = null;
if (is_array($curva)) {
    $validas = [];
    foreach ($curva as $pontos) {
        foreach ($pontos as $semana => $ponto) {
            if ($ponto['alunos'] >= $minAlunos) {
                $validas[] = (int)$semana;
            }
        }
    }
    if ($validas !== []) {
        $primeira = min(min($validas), -1);
        $ultima = max(max($validas), 1);
        // Não existe semana 0 (o dia do contato fica entre -1 e +1).
        $indice = static fn(int $semana): int => $semana < 0 ? $semana - $primeira : $semana - $primeira - 1;
        $rotulos = [];
        $valores = ['contatado' => [], 'comparacao' => []];
        $alunos = ['contatado' => [], 'comparacao' => []];
        for ($semana = $primeira; $semana <= $ultima; $semana++) {
            if ($semana === 0) {
                continue;
            }
            $rotulos[] = ($semana < 0 ? '−' : '+') . abs($semana);
            foreach (array_keys($valores) as $grupo) {
                $ponto = $curva[$grupo][$semana] ?? null;
                $valores[$grupo][] = $ponto !== null && $ponto['alunos'] >= $minAlunos ? round($ponto['presenca'], 1) : null;
                $alunos[$grupo][] = $ponto['alunos'] ?? 0;
            }
        }
        $grafico = [
            'rotulos' => $rotulos,
            'valores' => $valores,
            'alunos' => $alunos,
            'indiceReferencia' => $indice(-1),
            'antes' => [$indice(max(-$semanasJanela, $primeira)), $indice(-1)],
            'depois' => [$indice(1), $indice(min($semanasJanela, $ultima))],
            'limite' => Efeito::PRESENCA_VOLTOU,
        ];
    }
}
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
    <div class="flex-grow-1" style="flex-basis: 0; min-width: 280px;">
        <h1 class="h4 mb-1">Eficácia dos alarmes</h1>
        <?php if ($escopo['rotulo'] !== null): ?>
            <p class="mb-1">
                <span class="badge text-bg-primary text-wrap text-start fw-normal" style="font-size: 0.85rem;">
                    <?= $e((string)$escopo['rotulo']) ?>
                </span>
            </p>
        <?php endif; ?>
        <p class="text-secondary mb-0">
            Os contatos com alunos em alarme crítico fazem eles voltarem a frequentar?
            <?php if (is_array($execucao)): ?>
                <br><?= $e((string)($execucao['periodo'] ?: 'Semestre atual')) ?>
                · desde <?= $e($dataLonga($execucao['data_inicio'] ?? '')) ?>
                · dados até <?= $e($dataLonga($execucao['data_corte'] ?? '')) ?>
                · análise gerada em <?= $e(date('d/m/Y H:i', strtotime((string)$execucao['executado_em']) ?: time())) ?>
            <?php endif; ?>
        </p>
    </div>
    <?php if (!empty($podeGerar)): ?>
        <form method="post" action="<?= $e(url('/acompanhamento/gerar')) ?>" class="text-md-end">
            <button type="submit" class="btn btn-outline-primary">Gerar análise</button>
            <p class="small text-secondary mb-0 mt-1" style="max-width: 260px;">
                Recalcula com os contatos, os alarmes e as faltas da última coleta. Pode levar alguns minutos.
            </p>
        </form>
    <?php endif; ?>
</div>

<?php if (is_array($execucao) && $escopo['aviso'] === null): ?>
    <form method="get" action="<?= $e(url('/acompanhamento')) ?>" class="d-flex flex-wrap align-items-end gap-3 mb-4">
        <?php foreach (['contatos', 'prioridade', 'por_pagina'] as $mantido): ?>
            <?php if (isset($query[$mantido])): ?>
                <input type="hidden" name="<?= $e($mantido) ?>" value="<?= $e($query[$mantido]) ?>">
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if (!$semSeletorCurso): ?>
            <div>
                <label class="form-label small fw-semibold text-secondary mb-1" for="filtroCurso">Curso</label>
                <select class="form-select" id="filtroCurso" name="curso" style="min-width: 240px; max-width: 420px;" onchange="this.form.submit()">
                    <option value="todos">Todos os cursos</option>
                    <?php foreach ($escopo['cursos'] as $curso): ?>
                        <option value="<?= (int)$curso['id'] ?>" <?= $cursoSelecionado === (string)$curso['id'] ? 'selected' : '' ?>>
                            <?= $e((string)$curso['nome_curso']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div>
            <label class="form-label small fw-semibold text-secondary mb-1" for="filtroSituacao">Tipo de alarme</label>
            <select class="form-select" id="filtroSituacao" name="situacao" onchange="this.form.submit()">
                <option value="">Todos</option>
                <?php foreach (Repo::ROTULOS_SITUACAO as $valor => $rotulo): ?>
                    <option value="<?= $e($valor) ?>" <?= $situacaoFiltro === $valor ? 'selected' : '' ?>>
                        <?= $e($valor === 'sem_risco' ? $rotulo . ' (só na lista)' : $rotulo) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label small fw-semibold text-secondary mb-1" for="filtroIngressante">Ingresso</label>
            <select class="form-select" id="filtroIngressante" name="ingressante" onchange="this.form.submit()">
                <option value="">Todos</option>
                <option value="1" <?= ($filtros['ingressante'] ?? null) === true ? 'selected' : '' ?>>Ingressantes</option>
                <option value="0" <?= ($filtros['ingressante'] ?? null) === false ? 'selected' : '' ?>>Veteranos</option>
            </select>
        </div>
        <div>
            <label class="form-label small fw-semibold text-secondary mb-1" for="filtroAuxilio">Auxílio estudantil</label>
            <select class="form-select" id="filtroAuxilio" name="auxilio" onchange="this.form.submit()">
                <option value="">Todos</option>
                <option value="1" <?= ($filtros['auxilio'] ?? null) === true ? 'selected' : '' ?>>Com auxílio</option>
                <option value="0" <?= ($filtros['auxilio'] ?? null) === false ? 'selected' : '' ?>>Sem auxílio</option>
            </select>
        </div>
        <noscript><button type="submit" class="btn btn-primary">Filtrar</button></noscript>
    </form>
<?php endif; ?>

<?php if (!empty($sucesso)): ?>
    <div class="alert alert-success"><?= $e((string)$sucesso) ?></div>
<?php endif; ?>
<?php if (!empty($erro)): ?>
    <div class="alert alert-danger"><?= $e((string)$erro) ?></div>
<?php endif; ?>
<?php if ($escopo['aviso'] !== null): ?>
    <div class="alert alert-warning"><?= $e((string)$escopo['aviso']) ?></div>
<?php endif; ?>

<?php if (is_array($avaliacao)): ?>
    <?php
    $cobertura = $cobertura ?? ['com_alarme' => 0, 'email_automatico' => 0, 'equipe' => 0, 'sem_contato' => 0, 'equipe_dois' => 0, 'dias_mediana' => null];
    $contatado = $avaliacao['contatado'];
    $comparacao = $avaliacao['comparacao'];
    $efeitoTotal = $avaliacao['efeito'];
    $voltouTotal = $avaliacao['voltou'];
    $comAlarme = (int)$cobertura['com_alarme'];
    $intervaloEquipe = (int)($series['intervalo_equipe'] ?? 3);
    $diasEquipe = $cobertura['dias_mediana'];
    ?>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <div class="text-secondary small">Alunos com alarme crítico</div>
                <div class="fs-3 fw-semibold"><?= $num($comAlarme) ?></div>
                <div class="small text-secondary">
                    no semestre, pela tela Alarmes<?php if ((int)$cobertura['sem_contato'] > 0): ?>;
                        <?= $num((int)$cobertura['sem_contato']) ?> sem nenhum contato<?php endif; ?>
                </div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <div class="text-secondary small">Receberam o e-mail automático</div>
                <div class="fs-3 fw-semibold"><?= $num((int)$cobertura['email_automatico']) ?></div>
                <div class="small text-secondary"><?= $pct((int)$cobertura['email_automatico'], $comAlarme) ?> dos alunos com alarme crítico</div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <div class="text-secondary small">Receberam contato da equipe</div>
                <div class="fs-3 fw-semibold"><?= $num((int)$cobertura['equipe']) ?></div>
                <div class="small text-secondary">
                    <?= $pct((int)$cobertura['equipe'], $comAlarme) ?> dos alunos com alarme crítico;
                    <?= $num((int)$cobertura['equipe_dois']) ?> com dois ou mais
                </div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <div class="text-secondary small">Do alarme ao contato da equipe</div>
                <div class="fs-3 fw-semibold">
                    <?= $diasEquipe === null ? '—' : $num((int)round($diasEquipe)) . ' ' . ((int)round($diasEquipe) === 1 ? 'dia' : 'dias') ?>
                </div>
                <div class="small text-secondary">mediana</div>
            </div></div>
        </div>
    </div>

    <?php if ((int)$cobertura['equipe'] === 0): ?>
        <div class="alert alert-info">
            Nenhum contato da equipe registrado com alunos em alarme crítico neste recorte. As análises abaixo comparam
            quem recebeu contato da equipe (ligação, WhatsApp, conversa, e-mail, assistência, registrados na tela
            Alarmes) com quem ficou só com o e-mail automático.
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <section class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex flex-column gap-3">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <h2 class="h6 fw-bold mb-1">1. Contato da equipe: presença antes e depois</h2>
                            <p class="small text-secondary mb-0">
                                Alunos ainda abaixo de <?= Efeito::PRESENCA_VOLTOU ?>%: média de presença nas
                                <?= $semanasJanela ?> semanas antes e nas <?= $semanasJanela ?> depois do 1º contato da
                                equipe. Comparação: quem não tinha contato da equipe <?= $num($intervaloEquipe) ?>
                                <?= $intervaloEquipe === 1 ? 'dia' : 'dias' ?> depois do alarme (o intervalo mais comum até
                                o contato da equipe), só com o e-mail automático ou nada.
                            </p>
                        </div>
                        <?php if ($lado($efeitoTotal) !== null): ?>
                            <?php [$seloTexto, $seloClasse] = $selos[$lado($efeitoTotal)]; ?>
                            <span class="selo-comparacao <?= $seloClasse ?>"><?= $e($seloTexto) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="row g-3 text-center">
                        <?php foreach ([['Contato da equipe', $contatado], ['Só e-mail automático', $comparacao]] as [$rotulo, $grupo]): ?>
                            <div class="col-6">
                                <div class="border rounded-3 p-2 h-100">
                                    <div class="small text-secondary"><?= $e($rotulo) ?> (<?= $num((int)$grupo['analisados']) ?>)</div>
                                    <div class="fs-5 fw-semibold text-nowrap">
                                        <?= $pres($grupo['presenca_antes']) ?> → <?= $pres($grupo['presenca_depois']) ?>
                                    </div>
                                    <div class="small text-secondary">
                                        <?= $e($sinal($grupo['variacao'])) ?> p.p.
                                        <?php if ((int)$grupo['analisados'] > 0): ?>
                                            · <?= $pct((int)$grupo['melhoraram'], (int)$grupo['analisados']) ?> melhoraram
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="mb-0">
                        <?php if ($efeitoTotal === null): ?>
                            <span class="text-secondary">
                                Precisa de pelo menos <?= $minAlunos ?> alunos analisados em cada grupo para medir o efeito.
                            </span>
                        <?php else: ?>
                            <strong>Efeito do contato da equipe: <?= $e($sinal($efeitoTotal['valor'])) ?> p.p. de presença</strong>
                            (margem de 95%: de <?= $e($sinal($efeitoTotal['min'])) ?> a <?= $e($sinal($efeitoTotal['max'])) ?>).
                            <?= [1 => 'Quem recebeu contato da equipe passou a frequentar mais que quem ficou só com o e-mail.',
                                0 => 'A diferença ainda pode ser acaso.',
                                -1 => 'Quem recebeu contato da equipe passou a frequentar menos que quem ficou só com o e-mail.'][$lado($efeitoTotal)] ?>
                        <?php endif; ?>
                    </p>
                </div>
            </section>
        </div>
        <div class="col-lg-6">
            <section class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex flex-column gap-3">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <h2 class="h6 fw-bold mb-1">2. Contato da equipe: voltaram a frequentar</h2>
                            <p class="small text-secondary mb-0">
                                Dos que estavam abaixo de <?= Efeito::PRESENCA_VOLTOU ?>% de presença antes, quantos
                                chegaram a <?= Efeito::PRESENCA_VOLTOU ?>% ou mais nas <?= $semanasJanela ?> semanas depois.
                            </p>
                        </div>
                        <?php [$seloTexto, $seloClasse] = $selosVoltou[$voltouTotal['selo']]; ?>
                        <span class="selo-comparacao <?= $seloClasse ?>"><?= $e($seloTexto) ?></span>
                    </div>
                    <div class="row g-3 text-center">
                        <?php foreach ([['Contato da equipe', $contatado], ['Só e-mail automático', $comparacao]] as [$rotulo, $grupo]): ?>
                            <div class="col-6">
                                <div class="border rounded-3 p-2 h-100">
                                    <div class="small text-secondary"><?= $e($rotulo) ?></div>
                                    <div class="fs-5 fw-semibold"><?= $pct((int)$grupo['voltaram'], (int)$grupo['abaixo_antes']) ?></div>
                                    <div class="small text-secondary">
                                        <?= $num((int)$grupo['voltaram']) ?> de <?= $num((int)$grupo['abaixo_antes']) ?> alunos
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="mb-0">
                        <?php if ($voltouTotal['diferenca'] === null): ?>
                            <span class="text-secondary">
                                Precisa de pelo menos <?= Repo::MIN_ALUNOS ?> alunos abaixo de <?= Efeito::PRESENCA_VOLTOU ?>%
                                em cada grupo para comparar.
                            </span>
                        <?php else: ?>
                            <strong>Diferença: <?= $e($sinal($voltouTotal['diferenca'], 0)) ?> p.p.</strong>
                            (margem de ±<?= $e(number_format((float)$voltouTotal['margem'], 0, ',', '.')) ?>).
                            <?= ['melhor' => 'Com contato da equipe, mais alunos voltam.',
                                'incerto' => 'A diferença ainda pode ser acaso.',
                                'pior' => 'Com contato da equipe, menos alunos voltam.'][$voltouTotal['selo']] ?>
                        <?php endif; ?>
                    </p>
                </div>
            </section>
        </div>
    </div>

    <?php if (is_array($segundo ?? null)): ?>
        <?php
        $s2Contatado = $segundo['contatado'];
        $s2Comparacao = $segundo['comparacao'];
        $s2Efeito = $segundo['efeito'];
        $s2Voltou = $segundo['voltou'];
        $intervalo = (int)($series['intervalo_segundo'] ?? 7);
        ?>
        <section class="card border-0 shadow-sm mb-4" id="segundo-contato">
            <div class="card-body d-flex flex-column gap-3">
                <div>
                    <h2 class="h6 fw-bold mb-1">3. Vale insistir? O 2º contato da equipe</h2>
                    <p class="small text-secondary mb-0">
                        Alunos que receberam o 2º contato da equipe e ainda estavam abaixo de <?= Efeito::PRESENCA_VOLTOU ?>% de
                        presença nas <?= $semanasJanela ?> semanas antes dele. Comparação: alunos com um contato da equipe só que,
                        <?= $num($intervalo) ?> <?= $intervalo === 1 ? 'dia' : 'dias' ?> depois dele (o intervalo mais
                        comum entre o 1º e o 2º contato da equipe), também estavam abaixo de <?= Efeito::PRESENCA_VOLTOU ?>%.
                    </p>
                </div>
                <?php if ((int)$s2Contatado['alunos'] === 0): ?>
                    <p class="text-secondary mb-0">Nenhum aluno com 2º contato da equipe neste recorte.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0" style="min-width: 640px;">
                            <thead class="table-light">
                                <tr class="small">
                                    <th scope="col">Grupo</th>
                                    <th scope="col" class="text-end">Alunos (analisados)</th>
                                    <th scope="col" class="text-end">Presença antes → depois</th>
                                    <th scope="col" class="text-end">Melhoraram</th>
                                    <th scope="col" class="text-end">Chegaram a <?= Efeito::PRESENCA_VOLTOU ?>%</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ([['Com 2º contato da equipe', $s2Contatado], ['Só o 1º contato da equipe', $s2Comparacao]] as [$rotulo, $grupo]): ?>
                                    <tr>
                                        <th scope="row" class="fw-normal"><?= $e($rotulo) ?></th>
                                        <td class="text-end"><?= $num((int)$grupo['alunos']) ?> <span class="text-secondary">(<?= $num((int)$grupo['analisados']) ?>)</span></td>
                                        <td class="text-end text-nowrap"><?= $pres($grupo['presenca_antes']) ?> → <?= $pres($grupo['presenca_depois']) ?></td>
                                        <td class="text-end"><?= $pct((int)$grupo['melhoraram'], (int)$grupo['analisados']) ?></td>
                                        <td class="text-end">
                                            <?= $pct((int)$grupo['voltaram'], (int)$grupo['abaixo_antes']) ?>
                                            <div class="small text-secondary"><?= $num((int)$grupo['voltaram']) ?> de <?= $num((int)$grupo['abaixo_antes']) ?></div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="mb-0">
                        <?php if ($s2Efeito === null): ?>
                            <span class="text-secondary">
                                Precisa de pelo menos <?= $minAlunos ?> alunos analisados em cada grupo para medir o efeito do 2º contato.
                            </span>
                        <?php else: ?>
                            <strong>Efeito do 2º contato da equipe: <?= $e($sinal($s2Efeito['valor'])) ?> p.p. de presença</strong>
                            (margem de 95%: de <?= $e($sinal($s2Efeito['min'])) ?> a <?= $e($sinal($s2Efeito['max'])) ?>).
                            <?= [1 => 'Insistir ajudou: quem recebeu o 2º contato da equipe passou a frequentar mais.',
                                0 => 'A diferença ainda pode ser acaso.',
                                -1 => 'Quem recebeu o 2º contato da equipe passou a frequentar menos que quem teve um só.'][$lado($s2Efeito)] ?>
                        <?php endif; ?>
                        <?php if ($s2Voltou['diferenca'] !== null): ?>
                            Chegaram a <?= Efeito::PRESENCA_VOLTOU ?>%: <?= $e($sinal($s2Voltou['diferenca'], 0)) ?> p.p.
                            (margem de ±<?= $e(number_format((float)$s2Voltou['margem'], 0, ',', '.')) ?>).
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h6 fw-bold mb-1">Presença semana a semana</h2>
            <p class="small text-secondary mb-3">
                Média de presença dos alunos analisados (abaixo de <?= Efeito::PRESENCA_VOLTOU ?>% antes) em cada semana antes e depois do 1º
                contato da equipe ou, para quem ficou só com o e-mail, do mesmo ponto depois do alarme. As faixas sombreadas são as semanas comparadas acima.
            </p>
            <?php if ($grafico === null): ?>
                <p class="text-secondary mb-0">
                    Poucos alunos neste recorte para montar a curva (mínimo de <?= $minAlunos ?> por semana).
                </p>
            <?php else: ?>
                <div style="position: relative; width: 100%; height: 320px;">
                    <canvas id="chartPresenca" aria-label="Presença por semana em torno do contato"></canvas>
                </div>
                <p class="small text-secondary mb-0 mt-2">
                    Semanas com menos de <?= $minAlunos ?> alunos ficam de fora. A linha tracejada marca
                    <?= Efeito::PRESENCA_VOLTOU ?>% de presença.
                </p>
            <?php endif; ?>
        </div>
    </section>

    <?php
    $rotulosSimNao = [
        'ingressante' => ['sim' => 'Ingressantes', 'nao' => 'Veteranos'],
        'auxilio' => ['sim' => 'Com auxílio estudantil', 'nao' => 'Sem auxílio'],
    ];
    $abas = [
        'canal' => ['Tipo de contato', $rotulosCanal, 'O tipo do 1º contato da equipe. Quem recebeu mais de um no mesmo dia aparece em cada um. A comparação é a de todos que ficaram só com o e-mail.'],
        'falou' => ['Conseguiu falar?', Efeito::ROTULOS_FALOU, 'Se a equipe conseguiu falar com o aluno no 1º contato. A comparação é a de todos que ficaram só com o e-mail.'],
        'tipo' => ['Tipo de alarme', Efeito::ROTULOS_TIPO, 'O tipo do 1º alarme crítico do aluno; a comparação é a dos alunos com o mesmo tipo.'],
        'rapidez' => ['Rapidez', Efeito::ROTULOS_RAPIDEZ, 'Dias do 1º alarme crítico ao 1º contato da equipe. A comparação é a de todos que ficaram só com o e-mail.'],
        'ingressante' => ['Ingresso', $rotulosSimNao['ingressante'], 'A comparação é a dos alunos do mesmo grupo.'],
        'auxilio' => ['Auxílio estudantil', $rotulosSimNao['auxilio'], 'A comparação é a dos alunos do mesmo grupo.'],
        'curso' => ['Curso', [], 'Poucos alunos por curso: serve para descrever, não para concluir. A comparação é a do mesmo curso.'],
    ];
    ?>
    <section class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h6 fw-bold mb-1">Por recorte</h2>
            <p class="small text-secondary mb-3">
                As duas análises do contato da equipe separadas por tipo de contato, alarme, rapidez e perfil. Não é sorteado: um recorte
                que se sai melhor é um indício, não uma prova (a ligação, por exemplo, costuma ir para os casos mais graves).
            </p>
            <ul class="nav nav-tabs mb-3" role="tablist">
                <?php $primeiraAba = true; ?>
                <?php foreach ($abas as $chave => [$titulo]): ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link<?= $primeiraAba ? ' active' : '' ?>" id="aba-<?= $chave ?>" data-bs-toggle="tab"
                                data-bs-target="#recorte-<?= $chave ?>" type="button" role="tab"
                                aria-controls="recorte-<?= $chave ?>" aria-selected="<?= $primeiraAba ? 'true' : 'false' ?>">
                            <?= $e($titulo) ?>
                        </button>
                    </li>
                    <?php $primeiraAba = false; ?>
                <?php endforeach; ?>
            </ul>
            <div class="tab-content">
                <?php $primeiraAba = true; ?>
                <?php foreach ($abas as $chave => [$titulo, $rotulos, $nota]): ?>
                    <?php
                    $grupos = $avaliacao['recortes'][$chave] ?? [];
                    // Ordem dos rótulos conhecidos; o resto (ex.: cursos) na ordem que veio.
                    $ordenados = [];
                    foreach ($rotulos as $valor => $rotulo) {
                        if (isset($grupos[$valor])) {
                            $ordenados[$valor] = [$rotulo, $grupos[$valor]];
                        }
                    }
                    foreach ($grupos as $valor => $grupo) {
                        if (!isset($ordenados[$valor])) {
                            $ordenados[$valor] = [$rotulos[$valor] ?? (string)$valor, $grupo];
                        }
                    }
                    ?>
                    <div class="tab-pane fade<?= $primeiraAba ? ' show active' : '' ?>" id="recorte-<?= $chave ?>"
                         role="tabpanel" aria-labelledby="aba-<?= $chave ?>" tabindex="0">
                        <p class="small text-secondary"><?= $e($nota) ?></p>
                        <?php if ($ordenados === []): ?>
                            <p class="text-secondary mb-0">Nenhum aluno neste recorte.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0" style="min-width: 760px;">
                                    <thead class="table-light">
                                        <tr class="small">
                                            <th scope="col">Grupo</th>
                                            <th scope="col" class="text-end">Contato da equipe (analisados)</th>
                                            <th scope="col" class="text-end">Presença antes → depois</th>
                                            <th scope="col" class="text-end">Efeito do contato</th>
                                            <th scope="col" class="text-end">Voltaram: equipe</th>
                                            <th scope="col" class="text-end">Voltaram: só e-mail</th>
                                            <th scope="col" class="text-end">Diferença</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($ordenados as [$rotulo, $grupo]): ?>
                                            <?php $c = $grupo['contatado']; ?>
                                            <tr>
                                                <th scope="row" class="fw-normal"><?= $e((string)$rotulo) ?></th>
                                                <td class="text-end"><?= $num((int)$c['alunos']) ?> <span class="text-secondary">(<?= $num((int)$c['analisados']) ?>)</span></td>
                                                <td class="text-end text-nowrap"><?= $pres($c['presenca_antes']) ?> → <?= $pres($c['presenca_depois']) ?></td>
                                                <td class="text-end"><?= $celulaEfeito($grupo['efeito']) ?></td>
                                                <td class="text-end"><?= $celulaVoltou($grupo['voltou'], $c) ?></td>
                                                <td class="text-end"><?= $celulaVoltou($grupo['voltou'], $grupo['comparacao']) ?></td>
                                                <td class="text-end"><?= $celulaDiferenca($grupo['voltou']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php $primeiraAba = false; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <?php if ($grafico !== null): ?>
        <script type="application/json" id="dadosPresenca"><?= json_encode($grafico, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@3.0.1/dist/chartjs-plugin-annotation.min.js"></script>
        <script>
        (function () {
            const dados = JSON.parse(document.getElementById('dadosPresenca').textContent);
            const formatar = (valor) => valor.toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + '%';
            const faixa = (inicio, fim, rotulo) => ({
                type: 'box',
                xMin: inicio - 0.5,
                xMax: fim + 0.5,
                backgroundColor: 'rgba(44, 74, 122, 0.07)',
                borderWidth: 0,
                label: { display: true, content: rotulo, position: { x: 'center', y: 'start' }, color: '#4a5568', font: { size: 10 } }
            });
            new Chart(document.getElementById('chartPresenca'), {
                type: 'line',
                data: {
                    labels: dados.rotulos,
                    datasets: [{
                        label: 'Contato da equipe',
                        data: dados.valores.contatado,
                        alunos: dados.alunos.contatado,
                        borderColor: '#2c4a7a',
                        backgroundColor: '#2c4a7a',
                        cubicInterpolationMode: 'monotone',
                        pointRadius: 3
                    }, {
                        label: 'Só e-mail automático (sem contato da equipe)',
                        data: dados.valores.comparacao,
                        alunos: dados.alunos.comparacao,
                        borderColor: '#d97b29',
                        backgroundColor: '#d97b29',
                        borderDash: [6, 4],
                        cubicInterpolationMode: 'monotone',
                        pointRadius: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { position: 'bottom', labels: { usePointStyle: true, pointStyle: 'line' } },
                        tooltip: {
                            callbacks: {
                                title: (itens) => 'Semana ' + itens[0].label,
                                label: (ctx) => ctx.parsed.y === null ? null : ctx.dataset.label + ': '
                                    + formatar(ctx.parsed.y) + ' (' + ctx.dataset.alunos[ctx.dataIndex] + ' alunos)'
                            }
                        },
                        annotation: {
                            annotations: {
                                antes: faixa(dados.antes[0], dados.antes[1], 'antes'),
                                depois: faixa(dados.depois[0], dados.depois[1], 'depois'),
                                referencia: {
                                    type: 'line',
                                    xMin: dados.indiceReferencia + 0.5,
                                    xMax: dados.indiceReferencia + 0.5,
                                    borderColor: 'rgba(19, 41, 75, 0.7)',
                                    borderWidth: 2,
                                    label: { display: true, content: 'contato da equipe', position: 'end',
                                        backgroundColor: 'rgba(19, 41, 75, 0.85)', color: '#fff', font: { size: 10 } }
                                },
                                limite: {
                                    type: 'line',
                                    yMin: dados.limite,
                                    yMax: dados.limite,
                                    borderColor: 'rgba(214, 69, 69, 0.85)',
                                    borderWidth: 1.5,
                                    borderDash: [4, 4]
                                }
                            }
                        }
                    },
                    scales: {
                        x: { title: { display: true, text: 'Semanas antes (−) e depois (+) do contato da equipe' } },
                        y: {
                            min: 0,
                            max: 100,
                            title: { display: true, text: 'Presença' },
                            ticks: { callback: (valor) => valor + '%' }
                        }
                    }
                }
            });
        })();
        </script>
    <?php endif; ?>
<?php endif; ?>

<?php if (is_array($lista)): ?>
    <?php
    $porPagina = (int)$filtros['por_pagina'];
    $paginaAtual = (int)$filtros['pagina'];
    $totalLista = (int)$lista['total'];
    $paginas = max(1, (int)ceil($totalLista / $porPagina));
    $totalSemFiltro = array_sum($lista['por_prioridade']);
    $limiteData = date('Y-m-d', strtotime((string)$execucao['data_corte'] . ' -' . Repo::DIAS_NOVA_ABORDAGEM . ' days'));
    $contatosFiltro = (int)$filtros['contatos'];
    $prioridadeFiltro = $filtros['prioridade'];
    $voltar = http_build_query($query);
    ?>
    <section class="card border-0 shadow-sm mb-4" id="nova-abordagem">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                <div>
                    <h2 class="h6 fw-bold mb-1">Precisam de nova abordagem</h2>
                    <p class="small text-secondary mb-0">
                        Contatados até <?= $e($dataLonga($limiteData)) ?> (há 14 dias ou mais) que não voltaram a
                        frequentar desde o último contato: <strong><?= $num($totalSemFiltro) ?>
                        <?= $totalSemFiltro === 1 ? 'aluno' : 'alunos' ?></strong>.
                        Quem recebe um novo contato sai da lista.
                    </p>
                </div>
                <?php if (!empty($podeExportar) && $totalLista > 0): ?>
                    <a class="btn btn-outline-primary btn-sm" href="<?= $e(url('/acompanhamento/exportar') . ($voltar !== '' ? '?' . $voltar : '')) ?>">
                        <i class="bi bi-download"></i> Exportar CSV
                    </a>
                <?php endif; ?>
            </div>

            <div class="d-flex flex-wrap align-items-center gap-2 mb-2" aria-label="Filtrar por situação">
                <span class="small text-secondary me-1">Situação:</span>
                <a class="btn btn-sm <?= $situacaoFiltro === null ? 'btn-primary' : 'btn-outline-secondary' ?>"
                   href="<?= $e($link(['situacao' => null], '#nova-abordagem')) ?>">Todas (<?= $num($totalSemFiltro) ?>)</a>
                <?php foreach (Repo::ROTULOS_SITUACAO as $valor => $rotulo): ?>
                    <?php $qtd = (int)($lista['por_situacao'][$valor] ?? 0); ?>
                    <?php if ($qtd === 0 && $situacaoFiltro !== $valor) { continue; } ?>
                    <a class="btn btn-sm <?= $situacaoFiltro === $valor ? 'btn-primary' : 'btn-outline-secondary' ?>"
                       href="<?= $e($link(['situacao' => $valor], '#nova-abordagem')) ?>"><?= $e($rotulo) ?> (<?= $num($qtd) ?>)</a>
                <?php endforeach; ?>
            </div>
            <form method="get" action="<?= $e(url('/acompanhamento')) ?>#nova-abordagem"
                  class="d-flex flex-wrap align-items-center gap-3 mb-3">
                <?php foreach (['curso', 'situacao', 'ingressante', 'auxilio'] as $mantido): ?>
                    <?php if (isset($query[$mantido])): ?>
                        <input type="hidden" name="<?= $e($mantido) ?>" value="<?= $e($query[$mantido]) ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                <div class="d-flex align-items-center gap-2">
                    <label class="small text-secondary" for="filtroPrioridade">Prioridade</label>
                    <select class="form-select form-select-sm w-auto" id="filtroPrioridade" name="prioridade" onchange="this.form.submit()">
                        <option value="">Todas</option>
                        <option value="alta" <?= $prioridadeFiltro === 'alta' ? 'selected' : '' ?>>Alta (<?= $num((int)$lista['por_prioridade']['alta']) ?>)</option>
                        <option value="media" <?= $prioridadeFiltro === 'media' ? 'selected' : '' ?>>Média (<?= $num((int)$lista['por_prioridade']['media']) ?>)</option>
                    </select>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <label class="small text-secondary" for="filtroContatos">Contatos</label>
                    <select class="form-select form-select-sm w-auto" id="filtroContatos" name="contatos" onchange="this.form.submit()">
                        <option value="">Qualquer número</option>
                        <option value="1" <?= $contatosFiltro === 1 ? 'selected' : '' ?>>1 contato</option>
                        <option value="2" <?= $contatosFiltro === 2 ? 'selected' : '' ?>>2 contatos</option>
                        <option value="3" <?= $contatosFiltro === 3 ? 'selected' : '' ?>>3 ou mais</option>
                    </select>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <label class="small text-secondary" for="filtroPorPagina">Por página</label>
                    <select class="form-select form-select-sm w-auto" id="filtroPorPagina" name="por_pagina" onchange="this.form.submit()">
                        <?php foreach (Repo::POR_PAGINA as $opcao): ?>
                            <option value="<?= $opcao ?>" <?= $porPagina === $opcao ? 'selected' : '' ?>><?= $opcao ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <noscript><button type="submit" class="btn btn-sm btn-primary">Filtrar</button></noscript>
            </form>

            <?php if ($totalLista === 0): ?>
                <p class="text-secondary mb-0">Nenhum aluno nesta lista com os filtros escolhidos.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0" style="min-width: 760px;">
                        <thead>
                            <tr class="small text-secondary text-uppercase">
                                <th scope="col">Estudante</th>
                                <th scope="col">Situação</th>
                                <th scope="col">Último contato</th>
                                <th scope="col" class="text-end">Presença desde então</th>
                                <th scope="col"><span class="visually-hidden">Ação</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $cursoAnterior = null; ?>
                            <?php foreach ($lista['linhas'] as $linha): ?>
                                <?php
                                if ($cursoAnterior !== $linha['nome_curso']):
                                    $cursoAnterior = $linha['nome_curso'];
                                ?>
                                    <tr class="table-light">
                                        <th colspan="5" scope="colgroup" class="small"><?= $e((string)$linha['nome_curso']) ?></th>
                                    </tr>
                                <?php endif; ?>
                                <?php
                                $nome = (string)$linha['nome_social'] !== '' ? (string)$linha['nome_social'] : (string)$linha['nome'];
                                $partesContato = [$dataCurta($linha['ultimo_contato'])];
                                $partesContato[] = mb_strtolower($rotulosCanal[(string)$linha['ultimo_canal']] ?? (string)$linha['ultimo_canal']);
                                if ($linha['ultimo_falou'] !== null) {
                                    $partesContato[] = (int)$linha['ultimo_falou'] === 1 ? 'conseguiu falar' : 'não conseguiu falar';
                                }
                                $motivo = (string)($linha['ultimo_motivo'] ?? '');
                                $alta = (string)$linha['prioridade'] === 'alta';
                                ?>
                                <tr data-aluno-id="<?= (int)$linha['aluno_id'] ?>" data-curso-id="<?= (int)$linha['curso_id'] ?>"
                                    data-nome="<?= $e($nome) ?>">
                                    <td>
                                        <span class="fw-semibold"><?= $e($nome) ?></span>
                                        <span class="small text-secondary"><?= $e((string)$linha['matricula']) ?></span>
                                        <div class="d-flex flex-wrap gap-1 mt-1">
                                            <?php if ($alta): ?>
                                                <span class="badge rounded-pill text-bg-warning">prioridade alta</span>
                                            <?php endif; ?>
                                            <?php if ((int)$linha['ingressante'] === 1): ?>
                                                <span class="badge rounded-pill text-bg-light border">ingressante</span>
                                            <?php endif; ?>
                                            <?php if ((int)$linha['auxilio'] === 1): ?>
                                                <span class="badge rounded-pill text-bg-light border">auxílio estudantil</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="small"><?= $e(Repo::ROTULOS_SITUACAO[(string)$linha['situacao']] ?? (string)$linha['situacao']) ?></td>
                                    <td class="small">
                                        <?= $e(implode(' · ', $partesContato)) ?>
                                        <?php if ($motivo !== ''): ?>
                                            <div class="text-secondary">motivo: <?= $e(mb_strtolower($rotulosMotivo[$motivo] ?? $motivo)) ?></div>
                                        <?php endif; ?>
                                        <div class="text-secondary">
                                            <?= (int)$linha['total_contatos'] ?> <?= (int)$linha['total_contatos'] === 1 ? 'contato' : 'contatos' ?> no semestre
                                        </div>
                                    </td>
                                    <td class="text-end fw-bold <?= $alta ? 'text-danger' : 'text-warning-emphasis' ?>">
                                        <?= number_format((float)$linha['presenca_desde'], 0, ',', '.') ?>%
                                        <div class="small fw-normal text-secondary">
                                            <?= (int)$linha['desde_contato_aulas'] ?> aulas
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-outline-primary js-novo-contato">Registrar contato realizado fora do sistema</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($paginas > 1): ?>
                    <nav class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3" aria-label="Páginas da lista">
                        <span class="small text-secondary">
                            <?= $num(($paginaAtual - 1) * $porPagina + 1) ?>–<?= $num(min($totalLista, $paginaAtual * $porPagina)) ?>
                            de <?= $num($totalLista) ?>
                        </span>
                        <ul class="pagination pagination-sm mb-0">
                            <?php
                            $paginaLink = static fn(int $p): string => $link(['pagina' => $p > 1 ? $p : null], '#nova-abordagem');                            $mostrar = array_unique(array_filter(
                                [1, $paginaAtual - 1, $paginaAtual, $paginaAtual + 1, $paginas],
                                static fn(int $p): bool => $p >= 1 && $p <= $paginas
                            ));
                            sort($mostrar);
                            $anterior = 0;
                            ?>
                            <li class="page-item<?= $paginaAtual <= 1 ? ' disabled' : '' ?>">
                                <a class="page-link" href="<?= $e($paginaLink(max(1, $paginaAtual - 1))) ?>">Anterior</a>
                            </li>
                            <?php foreach ($mostrar as $p): ?>
                                <?php if ($p - $anterior > 1): ?>
                                    <li class="page-item disabled"><span class="page-link">…</span></li>
                                <?php endif; ?>
                                <li class="page-item<?= $p === $paginaAtual ? ' active' : '' ?>">
                                    <a class="page-link" href="<?= $e($paginaLink($p)) ?>"<?= $p === $paginaAtual ? ' aria-current="page"' : '' ?>><?= $p ?></a>
                                </li>
                                <?php $anterior = $p; ?>
                            <?php endforeach; ?>
                            <li class="page-item<?= $paginaAtual >= $paginas ? ' disabled' : '' ?>">
                                <a class="page-link" href="<?= $e($paginaLink(min($paginas, $paginaAtual + 1))) ?>">Próxima</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>

    <div class="modal fade" id="modalContato" tabindex="-1" aria-labelledby="modalContatoTitulo" aria-hidden="true">
        <div class="modal-dialog">
            <form class="modal-content" method="post" action="<?= $e(url('/acompanhamento/contato')) ?>">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="modalContatoTitulo">Registrar contato realizado fora do sistema</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body d-flex flex-column gap-3">
                    <p class="small text-secondary mb-0" id="modalContatoAluno"></p>
                    <input type="hidden" name="aluno_id" value="">
                    <input type="hidden" name="curso_id" value="">
                    <input type="hidden" name="curso" value="<?= $e($cursoSelecionado) ?>">
                    <input type="hidden" name="voltar" value="<?= $e($voltar) ?>">
                    <div class="row g-3">
                        <div class="col-sm-7">
                            <label class="form-label" for="contatoCanal">Como foi o contato</label>
                            <select class="form-select" id="contatoCanal" name="canal" required>
                                <option value="">Escolha…</option>
                                <?php foreach ($rotulosContato as $valor => $rotulo): ?>
                                    <option value="<?= $e($valor) ?>"><?= $e($rotulo) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-5">
                            <label class="form-label" for="contatoData">Quando</label>
                            <input class="form-control" type="date" id="contatoData" name="data_contato" required
                                   value="<?= $e($hoje) ?>" min="<?= $e($contatoMaisAntigo) ?>" max="<?= $e($hoje) ?>">
                        </div>
                    </div>
                    <fieldset>
                        <legend class="form-label fs-6 mb-1">Conseguiu falar com o aluno?</legend>
                        <div class="d-flex flex-wrap gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="conseguiu_falar" id="falouSim" value="1">
                                <label class="form-check-label" for="falouSim">Sim</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="conseguiu_falar" id="falouNao" value="0">
                                <label class="form-check-label" for="falouNao">Não</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="conseguiu_falar" id="falouNa" value="" checked>
                                <label class="form-check-label" for="falouNa">Não se aplica</label>
                            </div>
                        </div>
                    </fieldset>
                    <div>
                        <label class="form-label" for="contatoMotivo">Motivo principal das faltas <span class="text-secondary">(se souber)</span></label>
                        <select class="form-select" id="contatoMotivo" name="motivo">
                            <option value="">Não informado</option>
                            <?php foreach ($rotulosMotivo as $valor => $rotulo): ?>
                                <option value="<?= $e($valor) ?>"><?= $e($rotulo) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" for="contatoObservacao">Observação <span class="text-secondary">(opcional)</span></label>
                        <textarea class="form-control" id="contatoObservacao" name="observacao" rows="3"
                                  maxlength="<?= (int)$maxObservacao ?>"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Registrar contato realizado fora do sistema</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    document.addEventListener('click', function (evento) {
        const botao = evento.target.closest('.js-novo-contato');
        const linha = botao ? botao.closest('[data-aluno-id]') : null;
        if (!linha) {
            return;
        }
        const modal = document.getElementById('modalContato');
        const form = modal.querySelector('form');
        form.reset();
        form.elements.aluno_id.value = linha.dataset.alunoId;
        form.elements.curso_id.value = linha.dataset.cursoId;
        document.getElementById('modalContatoAluno').textContent = linha.dataset.nome || '';
        bootstrap.Modal.getOrCreateInstance(modal).show();
    });
    </script>
<?php endif; ?>

<?php if (is_array($execucao) && $escopo['aviso'] === null): ?>
    <section class="card border-0 shadow-sm mb-4">
        <div class="card-body small">
            <h2 class="h6 fw-bold">Como ler</h2>
            <ul class="mb-0 ps-3 d-flex flex-column gap-1">
                <li><strong>Quem entra:</strong> alunos com alarme crítico no semestre, os mesmos da tela Alarmes
                    (frequência abaixo de 50%, 3 semanas sem aparecer ou dias seguidos de falta). Alarmes não críticos
                    ficam de fora. Data do alarme = dia da coleta em que ele apareceu.</li>
                <li><strong>Por que o contato da equipe:</strong> o e-mail automático sai para quase todos no dia do
                    alarme, então "recebeu contato x não recebeu" quase não separa ninguém. A pergunta útil é se o
                    contato da equipe (ligação, WhatsApp, conversa, e-mail, assistência, registrados na tela Alarmes)
                    muda algo além do e-mail.</li>
                <li><strong>Comparação:</strong> alunos sem contato da equipe no mesmo ponto depois do alarme (o
                    intervalo mais comum até o contato da equipe). Quem recebe contato da equipe depois sai da
                    comparação a partir daí.</li>
                <li><strong>Só quem não tinha voltado:</strong> nos dois grupos entram só alunos abaixo de
                    <?= Efeito::PRESENCA_VOLTOU ?>% nas semanas antes, porque a equipe procura justamente quem não voltou.</li>
                <li><strong>Analisados:</strong> alunos com pelo menos <?= $minAulas ?> aulas antes e
                    <?= $minAulas ?> depois. Contatos recentes ainda não têm aulas suficientes depois.</li>
                <li><strong>Efeito do contato da equipe:</strong> quanto a presença de quem recebeu o contato mudou menos
                    quanto mudou a de quem ficou só com o e-mail, em pontos percentuais, com margem de 95%. Se a margem
                    inclui o zero, a diferença ainda pode ser acaso. Só aparece com pelo menos <?= $minAlunos ?>
                    analisados em cada grupo.</li>
                <li><strong>Voltaram:</strong> quantos chegaram a <?= Efeito::PRESENCA_VOLTOU ?>% depois. Precisa de
                    <?= Repo::MIN_ALUNOS ?> alunos em cada grupo para comparar.</li>
                <li><strong>2º contato da equipe:</strong> comparado com quem teve um contato da equipe só. Comparar
                    "1 contato" com "2 ou mais" direto enganaria: quem continua faltando é quem recebe mais contatos.</li>
                <li>A comparação ajuda, mas não prova causa: a equipe pode escolher quem procurar (ex.: os casos mais
                    graves, ou quem tem telefone cadastrado).</li>
                <li><strong>Nova abordagem:</strong> prioridade alta quando a presença desde o último contato ficou
                    abaixo de <?= Repo::PRESENCA_ALTA ?>%; ingressantes e dias seguidos de falta aparecem primeiro.</li>
            </ul>
        </div>
    </section>
<?php endif; ?>
