<?php
declare(strict_types=1);

namespace Mapa\Lib;

/**
 * Atestado de matrícula / frequência (passe livre) no formato institucional IFRS.
 */
class PasseLivreAtestadoPdf
{
    private const TITULO_ATESTADO = 'ATESTADO DE MATRÍCULA Nº          /          ';
    private const PROTOCOLO = 'PROTOCOLO INDEFINIDO';

    /**
     * @param array{
     *   nome: string,
     *   matricula: string,
     *   curso: string,
     *   periodo: string,
     *   ingresso: string,
     *   frequencia: mixed,
     *   data_inicial: string,
     *   data_final: string,
     *   disciplinas: list<array{codigo: string, nome: string, frequencia: mixed}>
     * } $dados
     */
    public static function gerar(array $dados): SimplePdf
    {
        $pdf = new SimplePdf(false);
        $larguras = [55.0, 70.0, 280.0, 80.0];

        $brasao = self::brasaoPath();
        if (is_file($brasao)) {
            $pdf->drawImageCentered($brasao, 64.0, 2.0);
        } else {
            $pdf->spacer(4);
        }

        $pdf->centeredText('MINISTÉRIO DA EDUCAÇÃO', 9.0, true);
        $pdf->centeredText('SECRETARIA DE EDUCAÇÃO PROFISSIONAL E TECNOLÓGICA', 9.0, true);
        $pdf->centeredText(
            'INSTITUTO FEDERAL DE EDUCAÇÃO, CIÊNCIA E TECNOLOGIA DO RIO GRANDE DO SUL',
            9.0,
            true
        );
        $pdf->spacer(12);
        $pdf->textAligned(self::TITULO_ATESTADO, 9.0, 'left', true);
        $pdf->spacer(6);

        $pdf->textRow('Nº do Protocolo: ' . self::PROTOCOLO, self::dataExtenso(), 9.0);
        $pdf->spacer(10);

        $ingresso = trim($dados['ingresso']) !== '' ? trim($dados['ingresso']) : '---';
        $texto = sprintf(
            'Atestamos, para os devidos fins, que o(a) aluno(a) %s, matrícula nº %s, '
            . 'com ingresso em %s, no curso %s obteve, no semestre letivo %s, '
            . 'a frequência abaixo discriminada:',
            trim($dados['nome']),
            trim($dados['matricula']) !== '' ? trim($dados['matricula']) : '---',
            $ingresso,
            trim($dados['curso']),
            trim($dados['periodo'])
        );
        $pdf->paragraph($texto, 10.0);
        $pdf->spacer(8);

        $pdf->setFontSize(9);
        $pdf->tableHeader($larguras, [
            'Semestre',
            'Código',
            'Disciplina',
            'Frequência*',
        ]);

        $periodo = trim($dados['periodo']);
        foreach ($dados['disciplinas'] as $disc) {
            $pdf->tableRow($larguras, [
                $periodo,
                (string)($disc['codigo'] ?? ''),
                (string)($disc['nome'] ?? ''),
                self::fmtPct($disc['frequencia'] ?? null),
            ]);
        }

        $pdf->spacer(4);
        $pdf->paragraph(
            '* A frequência é o percentual de presença em relação ao número de aulas ministradas.',
            8.0
        );
        $pdf->spacer(6);
        $pdf->paragraph(
            'Frequência* global no curso: ' . self::fmtPct($dados['frequencia'] ?? null),
            10.0,
            true
        );

        $pdf->spacer(44);
        self::blocoAssinatura($pdf);

        return $pdf;
    }

    private static function blocoAssinatura(SimplePdf $pdf): void
    {
        $pdf->centeredText('(Assinado digitalmente em ' . date('d/m/Y H:i') . ')', 9.0);
        $pdf->centeredText('GRACIELA DA SILVA LEITES', 9.0, true);
        $pdf->centeredText('COORDENADOR (TITULAR) - TITULAR', 9.0);
        $pdf->centeredText('COORD. DE REGISTROS ESTUDANTIS (PORTO ALEGRE)', 9.0);
        $pdf->centeredText('Matrícula: 1760610', 9.0);
    }

    public static function brasaoPath(): string
    {
        return dirname(__DIR__, 2) . '/assets/img/brasao.jpeg';
    }

    /**
     * @param array<string, mixed> $dados
     */
    public static function nomeArquivo(array $dados): string
    {
        $matricula = preg_replace('/[^0-9A-Za-z_-]+/', '', (string)($dados['matricula'] ?? '')) ?? '';
        if ($matricula === '') {
            $matricula = 'aluno';
        }

        return 'passe-livre-' . $matricula . '-' . date('Y-m-d') . '.pdf';
    }

    /** @param mixed $valor */
    private static function fmtPct($valor): string
    {
        if ($valor === null || $valor === '' || !is_numeric($valor)) {
            return '—';
        }

        return number_format((float)$valor, 1, ',', '.') . '%';
    }

    public static function dataExtenso(): string
    {
        $meses = [
            1 => 'janeiro',
            2 => 'fevereiro',
            3 => 'março',
            4 => 'abril',
            5 => 'maio',
            6 => 'junho',
            7 => 'julho',
            8 => 'agosto',
            9 => 'setembro',
            10 => 'outubro',
            11 => 'novembro',
            12 => 'dezembro',
        ];
        $mes = $meses[(int)date('n')] ?? '';

        return sprintf('Porto Alegre-RS, %d de %s de %d', (int)date('j'), $mes, (int)date('Y'));
    }
}
