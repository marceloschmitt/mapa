<?php

use Mapa\Models\EfeitoContatosRepository;

$execucao = $execucao ?? null;
$porCanal = $porCanal ?? [];
$porCurso = $porCurso ?? [];
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
$pct = static fn(?float $valor): string => $valor === null ? '—' : number_format($valor, 1, ',', '.') . '%';
$num = static fn(int $valor): string => number_format($valor, 0, ',', '.');

$diferenca = static function (?array $linha): string {
    if ($linha === null || $linha['taxa_antes'] === null || $linha['taxa_depois'] === null) {
        return '<span class="text-secondary">—</span>';
    }
    $delta = (float)$linha['taxa_depois'] - (float)$linha['taxa_antes'];
    $classe = $delta < -0.05 ? 'text-success' : ($delta > 0.05 ? 'text-danger' : 'text-secondary');
    $sinal = $delta > 0.05 ? '+' : '';

    return '<span class="fw-semibold ' . $classe . '">' . $sinal . number_format($delta, 1, ',', '.') . ' p.p.</span>';
};
$parcela = static function (int $parte, int $total) use ($num): string {
    if ($total === 0) {
        return '<span class="text-secondary">—</span>';
    }

    return number_format(100 * $parte / $total, 0, ',', '.') . '%'
        . ' <span class="small text-secondary">(' . $num($parte) . ')</span>';
};
$analisados = static function (array $linha) use ($num): string {
    $html = $num((int)$linha['analisados']);
    if ((int)$linha['total'] > (int)$linha['analisados']) {
        $html .= ' <span class="small text-secondary">de ' . $num((int)$linha['total']) . '</span>';
    }

    return $html;
};

$janela = (int)($execucao['janela_dias'] ?? 14);
$minAulas = (int)($execucao['min_aulas'] ?? 3);
$semContato = $porCanal[EfeitoContatosRepository::CANAL_SEM_CONTATO] ?? null;
$linhasContato = [];
foreach ($rotulosCanal as $canal => $rotulo) {
    if (isset($porCanal[$canal])) {
        $linhasContato[$canal] = $rotulo;
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
            Faltas dos alunos nos <?= $janela ?> dias antes e depois do contato, comparadas
            com as de alunos com alarme que não foram contatados.
        </p>
        <?php if (is_array($execucao)): ?>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="badge text-bg-secondary">
                    Semestre desde <?= $e($data($execucao['data_inicio'] ?? '')) ?>
                    · dados até <?= $e($data($execucao['data_corte'] ?? '')) ?>
                </span>
                <span class="badge text-bg-secondary">Mínimo de <?= $minAulas ?> aulas em cada janela</span>
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
                Recalcula com os contatos e as faltas do semestre. Roda fora da coleta e pode levar alguns minutos.
            </p>
        </div>
    <?php endif; ?>
    <?php if (!$semSeletorCurso && $cursosDisponiveis !== []): ?>
        <form method="get" action="<?= $e(url('/efeito-contatos')) ?>"
              class="d-flex align-items-center gap-2 w-100">
            <label for="curso" class="form-label mb-0 text-nowrap">Curso</label>
            <select class="form-select w-auto" id="curso" name="curso" style="min-width: 260px;"
                    onchange="this.form.submit()">
                <option value="todos" <?= $cursoSelecionado === 'todos' ? 'selected' : '' ?>>Todos os cursos</option>
                <?php foreach ($cursosDisponiveis as $curso): ?>
                    <option value="<?= (int)$curso['id'] ?>"
                        <?= $cursoSelecionado === (string)$curso['id'] ? 'selected' : '' ?>>
                        <?= $e((string)$curso['nome_curso']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
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

<?php if (is_array($execucao) && empty($avisoCoordenador)): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h6 mb-1">Faltas antes e depois do contato</h2>
            <p class="small text-secondary mb-3">
                Quantos alunos passaram a faltar menos e, desses, a taxa de faltas que tinham antes e que têm depois.
            </p>
            <?php if ($linhasContato === [] && $semContato === null): ?>
                <p class="text-secondary mb-0">Nenhum aluno com alarme ou contato neste recorte.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th rowspan="2" class="align-bottom">Contato</th>
                                <th rowspan="2" class="text-end align-bottom">Alunos analisados</th>
                                <th rowspan="2" class="text-end align-bottom">Melhoraram</th>
                                <th colspan="3" class="text-center border-start">Faltas dos que melhoraram</th>
                            </tr>
                            <tr>
                                <th class="text-end border-start">Antes</th>
                                <th class="text-end">Depois</th>
                                <th class="text-end">Queda</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($linhasContato === []): ?>
                                <tr>
                                    <td colspan="6" class="text-secondary">Nenhum contato registrado no semestre.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($linhasContato as $canal => $rotulo): ?>
                                <?php $linha = $porCanal[$canal]; ?>
                                <tr<?= $canal === 'primeiro' ? ' class="fw-semibold"' : '' ?>>
                                    <td<?= $canal === 'primeiro' ? '' : ' class="ps-4"' ?>><?= $e($rotulo) ?></td>
                                    <td class="text-end"><?= $analisados($linha) ?></td>
                                    <td class="text-end"><?= $parcela((int)$linha['melhoraram'], (int)$linha['analisados']) ?></td>
                                    <td class="text-end border-start"><?= $pct($linha['taxa_antes']) ?></td>
                                    <td class="text-end"><?= $pct($linha['taxa_depois']) ?></td>
                                    <td class="text-end"><?= $diferenca($linha) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($semContato !== null): ?>
                                <tr class="table-light">
                                    <td>
                                        Comparação: com alarme e sem contato
                                        <div class="small text-secondary fw-normal">a partir do primeiro alarme</div>
                                    </td>
                                    <td class="text-end"><?= $analisados($semContato) ?></td>
                                    <td class="text-end"><?= $parcela((int)$semContato['melhoraram'], (int)$semContato['analisados']) ?></td>
                                    <td class="text-end border-start"><?= $pct($semContato['taxa_antes']) ?></td>
                                    <td class="text-end"><?= $pct($semContato['taxa_depois']) ?></td>
                                    <td class="text-end"><?= $diferenca($semContato) ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (count($porCurso) > 1): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h6 mb-1">Por curso</h2>
                <p class="small text-secondary mb-3">
                    Primeiro contato de cada aluno, por qualquer canal, ao lado dos alunos com alarme não contatados.
                </p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th rowspan="2" class="align-bottom">Curso</th>
                                <th colspan="3" class="text-center border-start">Contatados</th>
                                <th colspan="3" class="text-center border-start">Sem contato</th>
                            </tr>
                            <tr>
                                <th class="text-end border-start">Alunos</th>
                                <th class="text-end">Melhoraram</th>
                                <th class="text-end">Faltas antes → depois</th>
                                <th class="text-end border-start">Alunos</th>
                                <th class="text-end">Melhoraram</th>
                                <th class="text-end">Faltas antes → depois</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($porCurso as $curso): ?>
                                <tr>
                                    <td class="small"><?= $e((string)$curso['nome_curso']) ?></td>
                                    <?php foreach (['contato', 'sem_contato'] as $grupo): ?>
                                        <?php $linha = $curso[$grupo]; ?>
                                        <?php if ($linha === null || (int)$linha['analisados'] === 0): ?>
                                            <td class="text-end border-start text-secondary"><?= $linha === null ? '—' : '0' ?></td>
                                            <td class="text-end text-secondary">—</td>
                                            <td class="text-end text-secondary">—</td>
                                        <?php else: ?>
                                            <td class="text-end border-start"><?= $num((int)$linha['analisados']) ?></td>
                                            <td class="text-end text-nowrap">
                                                <?= $parcela((int)$linha['melhoraram'], (int)$linha['analisados']) ?>
                                            </td>
                                            <td class="text-end text-nowrap">
                                                <?php if ((int)$linha['melhoraram'] > 0): ?>
                                                    <?= $pct($linha['taxa_antes']) ?> → <?= $pct($linha['taxa_depois']) ?>
                                                <?php else: ?>
                                                    <span class="text-secondary">—</span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
        <div class="card-body small text-secondary">
            <h2 class="h6 text-body mb-2">Como ler</h2>
            <ul class="mb-0 ps-3">
                <li>
                    Taxa de faltas = faltas ÷ aulas da grade nos <?= $janela ?> dias antes e nos <?= $janela ?> dias
                    depois do contato (o dia do contato não entra). Dias sem chamada registrada pelo professor não contam.
                </li>
                <li>
                    "Melhoraram" = alunos cuja taxa de faltas caiu depois do contato. "Antes" e "Depois" são a média
                    das taxas desses alunos; "Queda" é a diferença, em pontos percentuais (ex.: de 40% para 15% = 25 p.p.).
                </li>
                <li>
                    Cada canal usa o primeiro contato daquele tipo com o aluno; "Qualquer contato" usa o primeiro de todos.
                    Só entram alunos com pelo menos <?= $minAulas ?> aulas em cada janela — contatos recentes ainda não
                    têm o período "depois" completo.
                </li>
                <li>
                    Compare sempre com a linha "sem contato": o aluno costuma ser contatado no pior momento, e parte da
                    melhora viria de qualquer forma. O efeito do contato é a diferença entre as duas linhas.
                </li>
                <li>Mostra associação, não prova que o contato causou a mudança.</li>
            </ul>
        </div>
    </div>
<?php endif; ?>
