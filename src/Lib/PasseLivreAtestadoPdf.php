<?php
declare(strict_types=1);

namespace Mapa\Lib;

/**
 * Atestado de matrícula / frequência (passe livre) no formato institucional IFRS.
 */
class PasseLivreAtestadoPdf
{
    /**
     * @param array{
     *   nome: string,
     *   matricula: string,
     *   curso: string,
     *   periodo: string,
     *   ingresso: string,
     *   frequencia: mixed,
     *   data_inicial?: string,
     *   data_final?: string,
     *   disciplinas: list<array{codigo: string, nome: string, frequencia: mixed}>,
     *   numero?: int|null,
     *   ano?: int|null,
     *   data_documento?: string,
     *   assinado_em?: string,
     *   link_conferencia?: string
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

        $numero = isset($dados['numero']) ? (int)$dados['numero'] : 0;
        $ano = isset($dados['ano']) ? (int)$dados['ano'] : 0;
        $titulo = $numero > 0 && $ano > 0
            ? sprintf('ATESTADO DE MATRÍCULA Nº %d / %d', $numero, $ano)
            : 'ATESTADO DE MATRÍCULA Nº          /          ';
        $pdf->textAligned($titulo, 9.0, 'left', true);
        $pdf->spacer(6);

        $protocolo = $numero > 0 && $ano > 0
            ? sprintf('%d/%d', $numero, $ano)
            : 'PROTOCOLO INDEFINIDO';
        $dataDoc = trim((string)($dados['data_documento'] ?? ''));
        if ($dataDoc === '') {
            $dataDoc = self::dataExtenso();
        }
        $pdf->textRow('Nº do Protocolo: ' . $protocolo, $dataDoc, 9.0);
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

        $link = trim((string)($dados['link_conferencia'] ?? ''));
        if ($link !== '') {
            $pdf->spacer(10);
            $pdf->paragraph(
                'Documento assinado digitalmente. Para conferir a autenticidade da assinatura, '
                . 'acesse: ' . $link,
                8.0
            );
        }

        $pdf->spacer(44);
        self::blocoAssinatura($pdf, (string)($dados['assinado_em'] ?? ''));

        return $pdf;
    }

    private static function blocoAssinatura(SimplePdf $pdf, string $assinadoEm): void
    {
        $rotulo = self::formatarAssinadoEm($assinadoEm);
        $pdf->centeredText('(Assinado digitalmente em ' . $rotulo . ')', 9.0);
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

        $numero = isset($dados['numero']) ? (int)$dados['numero'] : 0;
        $ano = isset($dados['ano']) ? (int)$dados['ano'] : 0;
        if ($numero > 0 && $ano > 0) {
            return sprintf('passe-livre-%d-%d-%s.pdf', $numero, $ano, $matricula);
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
        return self::dataExtensoEm(new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')));
    }

    public static function dataExtensoEm(\DateTimeInterface $quando): string
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
        $mes = $meses[(int)$quando->format('n')] ?? '';

        return sprintf(
            'Porto Alegre-RS, %d de %s de %d',
            (int)$quando->format('j'),
            $mes,
            (int)$quando->format('Y')
        );
    }

    public static function formatarAssinadoEm(string $assinadoEm): string
    {
        $assinadoEm = trim($assinadoEm);
        if ($assinadoEm === '') {
            return date('d/m/Y H:i');
        }

        try {
            $dt = new \DateTimeImmutable($assinadoEm, new \DateTimeZone('America/Sao_Paulo'));

            return $dt->format('d/m/Y H:i');
        } catch (\Exception) {
            return $assinadoEm;
        }
    }
}
