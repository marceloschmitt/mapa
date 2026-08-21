<?php
declare(strict_types=1);

namespace Mapa\Lib;

/**
 * Gerador minimo de PDF (Helvetica, texto Latin-1) sem dependencias.
 * Suficiente para tabelas simples de exportacao, com quebra de linha nas celulas.
 */
class SimplePdf
{
    private float $pageWidth = 595.28;  // A4
    private float $pageHeight = 841.89;
    private float $margin = 36.0;
    private float $y;
    private int $pageCount = 0;
    /** @var list<string> */
    private array $pages = [];
    /** @var list<string> */
    private array $current = [];
    private float $fontSize = 10.0;
    private float $lineHeightFactor = 1.25;
    private float $cellPadX = 3.0;
    private float $cellPadY = 3.0;

    public function __construct(bool $landscape = false)
    {
        if ($landscape) {
            $this->pageWidth = 841.89;
            $this->pageHeight = 595.28;
        }
        $this->addPage();
    }

    public function addPage(): void
    {
        if ($this->pageCount > 0) {
            $this->pages[] = implode("\n", $this->current);
        }
        $this->pageCount++;
        $this->current = [];
        $this->y = $this->pageHeight - $this->margin;
        $this->fontSize = 10.0;
    }

    public function setFontSize(float $size): void
    {
        $this->fontSize = $size;
    }

    public function contentWidth(): float
    {
        return $this->pageWidth - (2 * $this->margin);
    }

    public function ensureSpace(float $needed): void
    {
        if ($this->y - $needed < $this->margin) {
            $this->addPage();
        }
    }

    /** @param list<float> $colWidths */
    public function tableHeader(array $colWidths, array $cells): void
    {
        $this->drawRow($colWidths, $cells, true);
    }

    /** @param list<float> $colWidths */
    public function tableRow(array $colWidths, array $cells): void
    {
        $this->drawRow($colWidths, $cells, false);
    }

    /** @param list<float> $colWidths */
    public function sectionTitle(array $colWidths, string $title): void
    {
        $total = array_sum($colWidths);
        $innerW = $total - (2 * $this->cellPadX);
        $lines = $this->wrapText($title, $innerW, $this->fontSize);
        $rowHeight = $this->rowHeight(count($lines));
        $this->ensureSpace($rowHeight + 8);

        $x = $this->margin;
        $yBottom = $this->y - $rowHeight;
        $this->rect($x, $yBottom, $total, $rowHeight, true, [0.86, 0.89, 0.94]);
        $this->drawLinesInCell($x + $this->cellPadX, $yBottom, $innerW, $rowHeight, $lines, true);
        $this->y = $yBottom;
    }

    public function documentTitle(string $title, float $fontSize = 13.0): void
    {
        $previous = $this->fontSize;
        $this->fontSize = $fontSize;
        $width = $this->contentWidth();
        $lines = $this->wrapText($title, $width, $fontSize);
        $blockH = (count($lines) * $fontSize * $this->lineHeightFactor) + 4;
        $this->ensureSpace($blockH + 6);

        $y = $this->y - $fontSize;
        foreach ($lines as $i => $line) {
            $this->drawText($this->margin, $y - ($i * $fontSize * $this->lineHeightFactor), $line, true);
        }
        $this->y -= $blockH;
        $this->fontSize = $previous;
    }

    public function spacer(float $h = 10.0): void
    {
        $this->ensureSpace($h);
        $this->y -= $h;
    }

    /** @param list<float> $colWidths */
    private function drawRow(array $colWidths, array $cells, bool $header): void
    {
        $wrapped = [];
        $maxLines = 1;
        foreach ($colWidths as $i => $w) {
            $innerW = $w - (2 * $this->cellPadX);
            $lines = $this->wrapText((string)($cells[$i] ?? ''), $innerW, $this->fontSize);
            $wrapped[$i] = $lines;
            $maxLines = max($maxLines, count($lines));
        }

        $rowHeight = $this->rowHeight($maxLines);
        $this->ensureSpace($rowHeight + 2);

        $x = $this->margin;
        $yBottom = $this->y - $rowHeight;
        $fill = $header ? [0.94, 0.94, 0.94] : null;

        foreach ($colWidths as $i => $w) {
            $this->rect($x, $yBottom, $w, $rowHeight, $fill !== null, $fill ?? [1, 1, 1]);
            $this->drawLinesInCell(
                $x + $this->cellPadX,
                $yBottom,
                $w - (2 * $this->cellPadX),
                $rowHeight,
                $wrapped[$i],
                $header
            );
            $x += $w;
        }
        $this->y = $yBottom;
    }

    private function rowHeight(int $lineCount): float
    {
        $lineCount = max(1, $lineCount);
        return (2 * $this->cellPadY) + ($lineCount * $this->fontSize * $this->lineHeightFactor);
    }

    /** @param list<string> $lines */
    private function drawLinesInCell(
        float $x,
        float $yBottom,
        float $w,
        float $h,
        array $lines,
        bool $bold
    ): void {
        if ($lines === []) {
            $lines = [''];
        }

        $lineH = $this->fontSize * $this->lineHeightFactor;
        $blockH = count($lines) * $lineH;
        $startY = $yBottom + $h - $this->cellPadY - $this->fontSize;

        // Centraliza verticalmente o bloco de linhas.
        if ($blockH + (2 * $this->cellPadY) < $h) {
            $startY = $yBottom + (($h + $blockH) / 2) - $this->fontSize;
        }

        foreach ($lines as $i => $line) {
            $textY = $startY - ($i * $lineH);
            $this->drawText($x, $textY, $line, $bold);
        }
        unset($w);
    }

    private function drawText(float $x, float $y, string $text, bool $bold): void
    {
        $encoded = $this->toWin1252($text);
        $escaped = $this->escape($encoded);
        $this->current[] = 'BT /F' . ($bold ? '2' : '1') . sprintf(
            ' %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET',
            $this->fontSize,
            $x,
            $y,
            $escaped
        );
    }

    /** @return list<string> */
    private function wrapText(string $text, float $width, float $fontSize): array
    {
        $text = preg_replace("/\s+/u", ' ', trim($text)) ?? trim($text);
        if ($text === '') {
            return [''];
        }

        // Quebra tambem apos virgula para listas de professores.
        $text = str_replace(',', ', ', $text);
        $text = preg_replace("/\s+/u", ' ', $text) ?? $text;

        $words = preg_split('/\s+/u', $text) ?: [$text];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            // Palavra maior que a largura: quebra forçada por caracteres.
            while ($this->textWidth($word, $fontSize) > $width) {
                if ($current !== '') {
                    $lines[] = $current;
                    $current = '';
                }
                $cut = $this->fitPrefix($word, $width, $fontSize);
                if ($cut <= 0) {
                    $cut = 1;
                }
                $lines[] = mb_substr($word, 0, $cut, 'UTF-8');
                $word = mb_substr($word, $cut, null, 'UTF-8');
            }

            $candidate = $current === '' ? $word : ($current . ' ' . $word);
            if ($this->textWidth($candidate, $fontSize) <= $width) {
                $current = $candidate;
                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
            }
            $current = $word;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines === [] ? [''] : $lines;
    }

    /** Largura aproximada em pontos (Helvetica; maiusculas mais largas). */
    private function textWidth(string $text, float $fontSize): float
    {
        $width = 0.0;
        $len = mb_strlen($text, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($text, $i, 1, 'UTF-8');
            $width += $this->charWidthFactor($ch) * $fontSize;
        }

        return $width;
    }

    private function charWidthFactor(string $ch): float
    {
        if ($ch === ' ') {
            return 0.28;
        }
        if ($ch === ',' || $ch === '.' || $ch === ';' || $ch === ':') {
            return 0.28;
        }
        if ($ch === '-' || $ch === '/') {
            return 0.33;
        }
        // Digitos e maiusculas (nomes SIGAA) sao mais largos.
        if (preg_match('/[A-ZÁÀÂÃÉÊÍÓÔÕÚÇ]/u', $ch) === 1) {
            return 0.72;
        }
        if (preg_match('/[0-9]/', $ch) === 1) {
            return 0.56;
        }

        return 0.50;
    }

    private function fitPrefix(string $text, float $width, float $fontSize): int
    {
        $len = mb_strlen($text, 'UTF-8');
        $acc = 0.0;
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($text, $i, 1, 'UTF-8');
            $next = $acc + ($this->charWidthFactor($ch) * $fontSize);
            if ($next > $width && $i > 0) {
                return $i;
            }
            $acc = $next;
        }

        return $len;
    }

    /** @param list<float>|null $rgb */
    private function rect(float $x, float $y, float $w, float $h, bool $fill, ?array $rgb): void
    {
        if ($fill && $rgb !== null) {
            $this->current[] = sprintf(
                '%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f 0 g',
                $rgb[0],
                $rgb[1],
                $rgb[2],
                $x,
                $y,
                $w,
                $h
            );
        }
        $this->current[] = sprintf('%.2F %.2F %.2F %.2F re S', $x, $y, $w, $h);
    }

    private function toWin1252(string $text): string
    {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        if ($converted === false) {
            $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $text);
        }
        return $converted !== false ? $converted : preg_replace('/[^\x20-\x7E]/', '?', $text) ?? $text;
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    public function output(string $filename): void
    {
        $this->pages[] = implode("\n", $this->current);
        $n = count($this->pages);

        $font1Id = 3 + ($n * 2);
        $font2Id = $font1Id + 1;

        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $pageRefs = [];
        for ($i = 0; $i < $n; $i++) {
            $pageRefs[] = (3 + $i * 2) . ' 0 R';
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $pageRefs) . '] /Count ' . $n . ' >>';

        for ($i = 0; $i < $n; $i++) {
            $pageObj = 3 + $i * 2;
            $contentObj = 4 + $i * 2;
            $stream = $this->pages[$i];
            $objs[$pageObj] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> >> /Contents %d 0 R >>',
                $this->pageWidth,
                $this->pageHeight,
                $font1Id,
                $font2Id,
                $contentObj
            );
            $objs[$contentObj] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        }

        $objs[$font1Id] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[$font2Id] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        ksort($objs);
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objs as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $maxId = max(array_keys($objs));
        $pdf .= "xref\n0 " . ($maxId + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxId; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xref . "\n%%EOF";

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        echo $pdf;
    }
}
