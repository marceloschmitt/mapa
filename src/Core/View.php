<?php
declare(strict_types=1);

namespace Mapa\Core;

class View
{
    /** @param array<string, mixed>|null $coleta */
    public static function rotuloColeta(?array $coleta): string
    {
        if ($coleta === null || trim((string)($coleta['executada_em'] ?? '')) === '') {
            return 'Coleta';
        }

        $executadaEm = (string)$coleta['executada_em'];
        $timestamp = strtotime($executadaEm);
        $data = $timestamp !== false ? date('d/m/Y', $timestamp) : $executadaEm;

        return 'Coleta em ' . $data;
    }

    /** Formata limite de frequencia para exibicao (75.0 → "75"; 72.5 → "72,5"). */
    public static function rotuloLimite(mixed $valor): string
    {
        $texto = number_format((float)$valor, 1, ',', '');
        if (substr($texto, -2) === ',0') {
            $texto = substr($texto, 0, -2);
        }

        return $texto;
    }

    /** Formata semestre_oferta (ex.: "3" → "3º"). */
    public static function rotuloSemestre(mixed $semestre): string
    {
        $texto = trim((string)$semestre);
        if ($texto === '' || !ctype_digit($texto)) {
            return '';
        }

        return $texto . 'º';
    }

    public static function render(string $view, array $data = [], string $layout = 'layouts/main'): void
    {
        if (!isset($data['app'])) {
            $data['app'] = require 'src/Strings/app.php';
        }

        extract($data, EXTR_SKIP);

        ob_start();
        require 'src/Views/' . $view . '.php';
        $content = ob_get_clean();

        require 'src/Views/' . $layout . '.php';
    }
}
