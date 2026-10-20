<?php

use Mapa\Core\View;

$execucao = $execucao ?? null;
$resumo = $resumo ?? null;
$serie = $serie ?? null;
$referencia = (string)($referencia ?? 'primeiro');
$depois = (string)($depois ?? 'janela');
$semanas = (int)($semanas ?? 2);
$limiteFrequencia = (float)($limiteFrequencia ?? 75);
$minAlunos = (int)($minAlunos ?? 10);
$rotulosCanal = $rotulosCanal ?? [];
$semSeletorCurso = !empty($semSeletorCurso);
$cursoSelecionado = (string)($cursoSelecionado ?? 'todos');
$cursosDisponiveis = $cursosDisponiveis ?? [];
$podeGerar = !empty($podeGerar);

$e = static fn(string $texto): string => htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
$data = static function (?string $valor): string {
    $ts = strtotime((string)$valor);

    return $ts !== false ? date('d/m/Y', $ts) : '';
};
$geradoEm = '';
if (is_array($execucao)) {
    $ts = strtotime((string)($execucao['executado_em'] ?? ''));
    $geradoEm = $ts !== false ? date('d/m/Y H:i', $ts) : '';
}
$traco = '<span class="text-secondary">—</span>';
$num = static fn(int $valor): string => number_format($valor, 0, ',', '.');
$pct = static fn(?float $valor): string => $valor === null ? '—' : number_format($valor, 1, ',', '.') . '%';
// Com sinal; "−" tipográfico para não confundir com hífen.
$sinal = static function (float $valor): string {
    $texto = number_format(abs($valor), 1, ',', '.');
    if ($texto === '0,0') {
        return $texto;
    }

    return ($valor < 0 ? '−' : '+') . $texto;
};
$pp = static fn(?float $valor): string => $valor === null ? '—' : $sinal($valor) . ' p.p.';
$parte = static fn(int $parte, int $total): string => $total === 0
    ? '—'
    : number_format(100 * $parte / $total, 0, ',', '.') . '%';

$rotuloLimite = View::rotuloLimite($limiteFrequencia);
$rotuloLimiteFaltas = View::rotuloLimite(100 - $limiteFrequencia);
$minAulas = (int)($execucao['min_aulas'] ?? 3);
$dataCorte = $data($execucao['data_corte'] ?? '');
$comComparacao = $referencia === 'primeiro';
$rotulosReferencia = ['primeiro' => '1º contato', 'segundo' => '2º contato'];
$rotulosDepois = [
    'janela' => 'as ' . $semanas . ' semanas seguintes',
    'corte' => $dataCorte !== '' ? 'tudo até ' . $dataCorte : 'tudo até a data dos dados',
];

// -1 = intervalo todo abaixo de zero, 1 = todo acima, 0 = inclui o zero. Decide pelos
// valores arredondados que aparecem na tela ("de 0,0 a +8,2" não pode virar "faltaram mais").
$ladoEfeito = static function (array $efeito): int {
    if (round($efeito['max'], 1) < 0) {
        return -1;
    }

    return round($efeito['min'], 1) > 0 ? 1 : 0;
};
$classeEfeito = static fn(array $efeito): string => [-1 => 'text-success', 0 => 'text-secondary', 1 => 'text-danger'][$ladoEfeito($efeito)];
$veredito = static fn(array $efeito): string => [
    -1 => 'Os contatados passaram a faltar menos que a comparação.',
    0 => 'A diferença ainda não se distingue do acaso.',
    1 => 'Os contatados passaram a faltar mais que a comparação.',
][$ladoEfeito($efeito)];
$intervalo = static fn(array $efeito): string => 'de ' . $sinal($efeito['min']) . ' a ' . $sinal($efeito['max']) . ' p.p.';
$celulaEfeito = static function (?array $efeito) use ($traco, $pp, $classeEfeito, $intervalo, $e): string {
    if ($efeito === null) {
        return $traco;
    }

    return '<span class="fw-semibold ' . $classeEfeito($efeito) . '">' . $pp($efeito['valor']) . '</span>'
        . '<div class="small text-secondary">' . $e($intervalo($efeito)) . '</div>';
};
$celulaDistribuicao = static function (array $grupo) use ($traco): string {
    $total = (int)$grupo['analisados'];
    if ($total === 0) {
        return $traco;
    }

    $barras = '';
    $textos = [];
    foreach ([['melhoraram', 'bg-success'], ['iguais', 'bg-secondary'], ['pioraram', 'bg-danger']] as [$chave, $cor]) {
        $valor = 100 * (int)$grupo[$chave] / $total;
        $barras .= '<div class="progress" role="progressbar" aria-label="' . $chave . '"'
            . ' aria-valuenow="' . (int)round($valor) . '" aria-valuemin="0" aria-valuemax="100"'
            . ' style="width: ' . number_format($valor, 1, '.', '') . '%"><div class="progress-bar ' . $cor . '"></div></div>';
        $textos[] = number_format($valor, 0, ',', '.') . '%';
    }

    return '<div class="progress-stacked" style="height: 8px; min-width: 120px;">' . $barras . '</div>'
        . '<div class="small text-secondary mt-1 text-nowrap">' . implode(' · ', $textos) . '</div>';
};
$linhasCanal = static function (array $grupo) use ($rotulosCanal): array {
    $saida = [];
    foreach ($rotulosCanal as $canal => $rotulo) {
        if (isset($grupo['canais'][$canal])) {
            $saida[] = [$rotulo, $grupo['canais'][$canal]];
        }
    }
    foreach ($grupo['canais'] as $canal => $linha) {
        if (!isset($rotulosCanal[$canal])) {
            $saida[] = [(string)$canal, $linha];
        }
    }

    return $saida;
};

// Curva semanal: pontos com menos de $minAlunos alunos ficam de fora (null).
$grafico = null;
if (is_array($serie)) {
    $validas = [];
    foreach ($serie as $pontos) {
        foreach ($pontos as $semana => $ponto) {
            if ($ponto['alunos'] >= $minAlunos) {
                $validas[] = (int)$semana;
            }
        }
    }
    if ($validas !== []) {
        $primeira = min(min($validas), -1);
        $ultima = max(max($validas), 1);
        // Não existe semana 0 (o dia da referência fica entre -1 e +1).
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
                $ponto = $serie[$grupo][$semana] ?? null;
                $valores[$grupo][] = $ponto !== null && $ponto['alunos'] >= $minAlunos ? round($ponto['taxa'], 1) : null;
                $alunos[$grupo][] = $ponto['alunos'] ?? 0;
            }
        }
        $grafico = [
            'rotulos' => $rotulos,
            'valores' => $valores,
            'alunos' => $alunos,
            'comComparacao' => $comComparacao,
            'rotuloReferencia' => $rotulosReferencia[$referencia] ?? 'contato',
            'indiceReferencia' => $indice(-1),
            'antes' => [$indice(max(-$semanas, $primeira)), $indice(-1)],
            'depois' => [$indice(1), $depois === 'corte' ? count($rotulos) - 1 : $indice(min($semanas, $ultima))],
            'limiteFaltas' => 100 - $limiteFrequencia,
            'rotuloLimite' => 'frequência de ' . $rotuloLimite . '%',
        ];
    }
}
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div class="flex-grow-1" style="flex-basis: 0; min-width: 280px;">
        <h1 class="h4 mb-1">Efeito dos contatos</h1>
        <?php if ($semSeletorCurso): ?>
            <p class="mb-1">
                <span class="badge text-bg-primary text-wrap text-start fw-normal" style="font-size: 0.85rem;">
                    <?= $e((string)($cursoExibido ?? 'Todos os cursos')) ?>
                </span>
            </p>
        <?php endif; ?>
        <p class="text-secondary mb-2">
            Faltas dos alunos semana a semana antes e depois do contato, comparadas com as de alunos com alarme
            crítico que ainda não tinham recebido contato.
        </p>
        <?php if (is_array($execucao)): ?>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="badge text-bg-secondary">
                    Semestre desde <?= $e($data($execucao['data_inicio'] ?? '')) ?>
                    · dados até <?= $e($dataCorte) ?>
                </span>
                <span class="badge text-bg-secondary">Mínimo de <?= $minAulas ?> aulas antes e depois</span>
                <span class="badge text-bg-light text-dark border">
                    Gerado em <?= $e($geradoEm) ?>
                </span>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($podeGerar): ?>
        <div class="text-md-end">
            <form method="post" action="<?= $e(url('/efeito-contatos/gerar')) ?>">
                <button type="submit" class="btn btn-primary">Gerar análise</button>
            </form>
            <p class="small text-secondary mb-0 mt-1" style="max-width: 280px;">
                Recalcula com os contatos, os alarmes e as faltas do semestre. Roda fora da coleta e pode levar
                alguns minutos.
            </p>
        </div>
    <?php endif; ?>
    <?php if (is_array($execucao)): ?>
        <form method="get" action="<?= $e(url('/efeito-contatos')) ?>"
              class="d-flex flex-wrap align-items-center gap-3 w-100">
            <?php if (!$semSeletorCurso && $cursosDisponiveis !== []): ?>
                <div class="d-flex align-items-center gap-2">
                    <label for="curso" class="form-label mb-0 text-nowrap">Curso</label>
                    <select class="form-select w-auto" id="curso" name="curso" style="min-width: 260px; max-width: 420px;"
                            onchange="this.form.submit()">
                        <option value="todos" <?= $cursoSelecionado === 'todos' ? 'selected' : '' ?>>Todos os cursos</option>
                        <?php foreach ($cursosDisponiveis as $curso): ?>
                            <option value="<?= (int)$curso['id'] ?>"
                                <?= $cursoSelecionado === (string)$curso['id'] ? 'selected' : '' ?>>
                                <?= $e((string)$curso['nome_curso']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="d-flex align-items-center gap-2">
                <label for="ref" class="form-label mb-0 text-nowrap">Alinhar no</label>
                <select class="form-select w-auto" id="ref" name="ref" onchange="this.form.submit()">
                    <?php foreach ($rotulosReferencia as $valor => $rotulo): ?>
                        <option value="<?= $e($valor) ?>" <?= $referencia === $valor ? 'selected' : '' ?>>
                            <?= $e($rotulo) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="d-flex align-items-center gap-2">
                <label for="depois" class="form-label mb-0 text-nowrap">
                    Comparar as <?= $semanas ?> semanas antes com
                </label>
                <select class="form-select w-auto" id="depois" name="depois" onchange="this.form.submit()">
                    <?php foreach ($rotulosDepois as $valor => $rotulo): ?>
                        <option value="<?= $e($valor) ?>" <?= $depois === $valor ? 'selected' : '' ?>>
                            <?= $e($rotulo) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php if (!empty($sucesso)): ?>
    <div class="alert alert-success"><?= $e((string)$sucesso) ?></div>
<?php endif; ?>

<?php if (!empty($erro)): ?>
    <div class="alert alert-danger"><?= $e((string)$erro) ?></div>
<?php endif; ?>

<?php if (!empty($avisoCoordenador)): ?>
    <div class="alert alert-warning"><?= $e((string)$avisoCoordenador) ?></div>
<?php endif; ?>

<?php if (is_array($execucao) && is_array($resumo)): ?>
    <?php
    $contatado = $resumo['contatado'];
    $comparacao = $resumo['comparacao'];
    $efeito = $contatado['efeito'] ?? null;
    ?>
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-secondary small">
                        <?= $referencia === 'segundo' ? 'Alunos com 2º contato' : 'Alunos contatados' ?>
                    </div>
                    <div class="fs-3 fw-semibold"><?= $num((int)$contatado['alunos']) ?></div>
                    <div class="small text-secondary">
                        <?= $num((int)$resumo['contatos']) ?> contatos
                        <?php if ($referencia === 'primeiro'): ?>
                            · <?= $num((int)$resumo['varios']) ?> com dois ou mais
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-secondary small">Faltas antes → depois</div>
                    <div class="fs-4 fw-semibold text-nowrap">
                        <?= $pct($contatado['taxa_antes']) ?> → <?= $pct($contatado['taxa_depois']) ?>
                    </div>
                    <div class="small text-secondary">
                        Contatados: <?= $e($pp($contatado['variacao'])) ?>
                        <?php if ($comComparacao): ?>
                            <br>Comparação: <?= $e($pp($comparacao['variacao'])) ?>
                            (<?= $pct($comparacao['taxa_antes']) ?> → <?= $pct($comparacao['taxa_depois']) ?>)
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-secondary small">Com frequência de <?= $e($rotuloLimite) ?>% ou mais</div>
                    <div class="fs-4 fw-semibold text-nowrap">
                        <?= $parte((int)$contatado['frequentes_antes'], (int)$contatado['analisados']) ?>
                        → <?= $parte((int)$contatado['frequentes_depois'], (int)$contatado['analisados']) ?>
                    </div>
                    <div class="small text-secondary">
                        dos contatados analisados
                        <?php if ($comComparacao): ?>
                            <br>Comparação:
                            <?= $parte((int)$comparacao['frequentes_antes'], (int)$comparacao['analisados']) ?>
                            → <?= $parte((int)$comparacao['frequentes_depois'], (int)$comparacao['analisados']) ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-secondary small">Efeito líquido nas faltas</div>
                    <?php if (!$comComparacao): ?>
                        <div class="fs-4 fw-semibold text-secondary">—</div>
                        <div class="small text-secondary">Sem grupo de comparação para o 2º contato.</div>
                    <?php elseif ($efeito === null): ?>
                        <div class="fs-4 fw-semibold text-secondary">—</div>
                        <div class="small text-secondary">
                            Precisa de pelo menos <?= $minAlunos ?> alunos analisados em cada grupo
                            (comparação: <?= $num((int)$comparacao['analisados']) ?>).
                        </div>
                    <?php else: ?>
                        <div class="fs-4 fw-semibold <?= $classeEfeito($efeito) ?>"><?= $e($pp($efeito['valor'])) ?></div>
                        <div class="small text-secondary">
                            Intervalo de 95%: <?= $e($intervalo($efeito)) ?><br><?= $e($veredito($efeito)) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h6 mb-1">Faltas semana a semana</h2>
            <p class="small text-secondary mb-3">
                <?php if ($comComparacao): ?>
                    Média da taxa de faltas dos alunos em cada semana antes e depois do 1º contato (contatados) ou do
                    dia em que apareceu o alarme crítico (comparação). As faixas sombreadas são os períodos
                    comparados nos números acima.
                <?php else: ?>
                    Média da taxa de faltas dos alunos em cada semana antes e depois do 2º contato. Não há grupo de
                    comparação: o 2º contato costuma vir de um novo alarme, no pico de faltas, e parte da queda depois
                    dele aconteceria de qualquer forma.
                <?php endif; ?>
            </p>
            <?php if ($grafico === null): ?>
                <p class="text-secondary mb-0">
                    Poucos alunos neste recorte para montar a curva (mínimo de <?= $minAlunos ?> por semana).
                </p>
            <?php else: ?>
                <div style="position: relative; width: 100%; height: 320px;">
                    <canvas id="chartEfeito" aria-label="Taxa de faltas por semana em torno do contato"></canvas>
                </div>
                <p class="small text-secondary mb-0 mt-2">
                    Semanas com menos de <?= $minAlunos ?> alunos ficam de fora. A linha vermelha tracejada marca
                    <?= $e($rotuloLimiteFaltas) ?>% de faltas (frequência de <?= $e($rotuloLimite) ?>%).
                </p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h6 mb-1">Por canal do contato</h2>
            <p class="small text-secondary mb-3">
                Faltas nas <?= $semanas ?> semanas antes do <?= $e($rotulosReferencia[$referencia] ?? 'contato') ?>
                comparadas com <?= $e($rotulosDepois[$depois]) ?>.
            </p>
            <?php if ((int)$contatado['alunos'] === 0): ?>
                <p class="text-secondary mb-0">Nenhum aluno contatado neste recorte.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Grupo</th>
                                <th class="text-end">Alunos</th>
                                <th class="text-end">Analisados</th>
                                <th class="text-end">Faltas antes → depois</th>
                                <th class="text-end">Variação</th>
                                <th class="text-end">Frequência ≥ <?= $e($rotuloLimite) ?>%</th>
                                <th>Melhoraram · iguais · pioraram</th>
                                <?php if ($comComparacao): ?>
                                    <th class="text-end">Efeito líquido</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $linhas = [['Contatados — todos os canais', $contatado, 'fw-semibold', true]];
                            foreach ($linhasCanal($contatado) as [$rotulo, $linha]) {
                                $linhas[] = [$rotulo, $linha, 'ps-4', true];
                            }
                            if ($comComparacao) {
                                $linhas[] = ['Comparação: alarme crítico ainda sem contato', $comparacao, 'fst-italic', false];
                            }
                            ?>
                            <?php foreach ($linhas as [$rotulo, $linha, $classe, $ehContatado]): ?>
                                <?php $analisados = (int)$linha['analisados']; ?>
                                <tr<?= $ehContatado ? '' : ' class="border-top border-2"' ?>>
                                    <td class="<?= $classe ?>"><?= $e((string)$rotulo) ?></td>
                                    <td class="text-end"><?= $num((int)$linha['alunos']) ?></td>
                                    <td class="text-end text-nowrap">
                                        <?= $num($analisados) ?>
                                        <?php if ($analisados > 0 && $analisados < $minAlunos): ?>
                                            <span class="badge text-bg-light border fw-normal">poucos</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <?php if ($analisados === 0): ?>
                                            <?= $traco ?>
                                        <?php else: ?>
                                            <?= $pct($linha['taxa_antes']) ?> → <?= $pct($linha['taxa_depois']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-nowrap"><?= $analisados === 0 ? $traco : $e($pp($linha['variacao'])) ?></td>
                                    <td class="text-end text-nowrap">
                                        <?php if ($analisados === 0): ?>
                                            <?= $traco ?>
                                        <?php else: ?>
                                            <?= $parte((int)$linha['frequentes_antes'], $analisados) ?>
                                            → <?= $parte((int)$linha['frequentes_depois'], $analisados) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $celulaDistribuicao($linha) ?></td>
                                    <?php if ($comComparacao): ?>
                                        <td class="text-end text-nowrap">
                                            <?= $ehContatado ? $celulaEfeito($linha['efeito'] ?? null) : '<span class="small text-secondary">referência</span>' ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body small text-secondary">
            <h2 class="h6 text-body mb-2">Como ler</h2>
            <ul class="mb-0 ps-3">
                <li>
                    Um contato = um dia em que o aluno recebeu algum contato (e-mail automático ou contato registrado
                    na tela de alarmes). Mais de um canal no mesmo dia conta como um contato.
                </li>
                <li>
                    Taxa de faltas = faltas ÷ aulas da turma do aluno no período. Dias sem chamada registrada pelo
                    professor não contam. Semana −1 = os 7 dias antes do contato; +1 = os 7 dias depois; o dia do
                    contato não entra.
                </li>
                <li>
                    Comparação = alunos com alarme crítico que ainda não tinham recebido contato, contados a partir do
                    dia em que o alarme apareceu. Quem recebeu contato depois sai da comparação a partir dessa semana.
                    O alarme dispara no pico de faltas, e depois do pico as faltas tendem a cair sozinhas: a comparação
                    mostra quanto da queda aconteceria sem o contato.
                </li>
                <li>
                    "Faltas antes → depois" é a média das taxas de todos os alunos analisados: os que melhoraram, os que
                    ficaram iguais e os que pioraram.
                </li>
                <li>
                    "Frequência ≥ <?= $e($rotuloLimite) ?>%" = parte dos analisados com no máximo
                    <?= $e($rotuloLimiteFaltas) ?>% de faltas no período (o limite do alarme).
                </li>
                <li>
                    Efeito líquido = variação dos contatados menos a da comparação. Negativo (verde) = os contatados
                    passaram a faltar menos que a comparação. O intervalo de 95% mostra a margem: se ele inclui o zero,
                    a diferença ainda pode ser acaso. Só aparece com pelo menos <?= $minAlunos ?> alunos analisados em
                    cada grupo.
                </li>
                <li>
                    Analisados = alunos com pelo menos <?= $minAulas ?> aulas antes e depois; contatos recentes ainda
                    não têm aulas suficientes depois.
                </li>
                <li>
                    O canal é o do dia do contato. Quem recebeu mais de um canal nesse dia aparece em cada um, por isso
                    a soma dos canais pode passar do total.
                </li>
                <li>
                    A comparação melhora a leitura, mas não prova causa: quem não foi contatado pode ser diferente de
                    quem foi (ex.: aluno sem e-mail cadastrado).
                </li>
            </ul>
        </div>
    </div>

    <?php if ($grafico !== null): ?>
        <script type="application/json" id="dadosEfeito"><?= json_encode(
            $grafico,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
        ) ?></script>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@3.0.1/dist/chartjs-plugin-annotation.min.js"></script>
        <script>
        (function () {
            const dados = JSON.parse(document.getElementById('dadosEfeito').textContent);
            const formatar = (valor) => valor.toLocaleString('pt-BR', {
                minimumFractionDigits: 1,
                maximumFractionDigits: 1
            }) + '%';

            const datasets = [{
                label: 'Contatados',
                data: dados.valores.contatado,
                alunos: dados.alunos.contatado,
                borderColor: '#2b6cb0',
                backgroundColor: '#2b6cb0',
                cubicInterpolationMode: 'monotone',
                pointRadius: 3,
                pointHoverRadius: 5
            }];
            if (dados.comComparacao) {
                datasets.push({
                    label: 'Comparação: alarme crítico ainda sem contato',
                    data: dados.valores.comparacao,
                    alunos: dados.alunos.comparacao,
                    borderColor: '#718096',
                    backgroundColor: '#718096',
                    borderDash: [6, 4],
                    cubicInterpolationMode: 'monotone',
                    pointRadius: 3,
                    pointHoverRadius: 5
                });
            }

            const faixa = (inicio, fim, rotulo) => ({
                type: 'box',
                xMin: inicio - 0.5,
                xMax: fim + 0.5,
                backgroundColor: 'rgba(43, 108, 176, 0.07)',
                borderWidth: 0,
                label: {
                    display: true,
                    content: rotulo,
                    position: { x: 'center', y: 'start' },
                    color: '#4a5568',
                    font: { size: 10 }
                }
            });

            new Chart(document.getElementById('chartEfeito'), {
                type: 'line',
                data: { labels: dados.rotulos, datasets: datasets },
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
                                    borderColor: 'rgba(26, 54, 93, 0.7)',
                                    borderWidth: 2,
                                    label: {
                                        display: true,
                                        content: dados.rotuloReferencia,
                                        position: 'end',
                                        backgroundColor: 'rgba(26, 54, 93, 0.85)',
                                        color: '#fff',
                                        font: { size: 10 }
                                    }
                                },
                                limite: {
                                    type: 'line',
                                    yMin: dados.limiteFaltas,
                                    yMax: dados.limiteFaltas,
                                    borderColor: 'rgba(197, 48, 48, 0.85)',
                                    borderWidth: 1.5,
                                    borderDash: [4, 4]
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            title: { display: true, text: 'Semanas antes (−) e depois (+) do ' + dados.rotuloReferencia }
                        },
                        y: {
                            beginAtZero: true,
                            // Folga no topo para os rótulos das faixas não ficarem sob as linhas.
                            grace: '15%',
                            title: { display: true, text: 'Taxa de faltas' },
                            ticks: { callback: (valor) => valor + '%' }
                        }
                    }
                }
            });
        })();
        </script>
    <?php endif; ?>
<?php endif; ?>
