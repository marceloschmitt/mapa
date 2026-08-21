<?php
declare(strict_types=1);

namespace Mapa\Lib;

use RuntimeException;

/**
 * Cliente SMTP minimo (AUTH LOGIN, STARTTLS/SSL).
 */
class SmtpMailer
{
    /** @var array{host: string, port: int, encryption: string, username: string, password: string, from_address: string, from_name: string} */
    private array $config;

    /**
     * @param array{
     *   host: string,
     *   port: int,
     *   encryption: string,
     *   username: string,
     *   password: string,
     *   from_address: string,
     *   from_name: string
     * } $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * @param list<string> $to
     */
    public function send(array $to, string $subject, string $bodyText): void
    {
        $destinatarios = [];
        foreach ($to as $email) {
            $email = trim($email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $destinatarios[$email] = true;
            }
        }
        $destinatarios = array_keys($destinatarios);
        if ($destinatarios === []) {
            throw new RuntimeException('Nenhum destinatário válido.');
        }

        $host = trim($this->config['host']);
        $port = (int)$this->config['port'];
        $encryption = strtolower(trim($this->config['encryption']));
        $username = trim($this->config['username']);
        $password = (string)$this->config['password'];
        $from = trim($this->config['from_address']);
        $fromName = trim($this->config['from_name']);

        if ($host === '' || $from === '') {
            throw new RuntimeException('Host SMTP ou remetente não configurado.');
        }

        $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host;
        $socket = @stream_socket_client(
            $remote . ':' . $port,
            $errno,
            $errstr,
            30,
            STREAM_CLIENT_CONNECT
        );
        if ($socket === false) {
            throw new RuntimeException('Falha ao conectar no SMTP: ' . $errstr . ' (' . $errno . ')');
        }

        stream_set_timeout($socket, 30);

        try {
            $this->expect($socket, [220]);
            $this->command($socket, 'EHLO mapa.local', [250]);

            if ($encryption === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);
                $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                    $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                }
                if (!stream_socket_enable_crypto($socket, true, $crypto)) {
                    throw new RuntimeException('Falha ao iniciar STARTTLS.');
                }
                $this->command($socket, 'EHLO mapa.local', [250]);
            }

            if ($username !== '') {
                $this->command($socket, 'AUTH LOGIN', [334]);
                $this->command($socket, base64_encode($username), [334]);
                $this->command($socket, base64_encode($password), [235]);
            }

            $this->command($socket, 'MAIL FROM:<' . $from . '>', [250]);
            foreach ($destinatarios as $email) {
                $this->command($socket, 'RCPT TO:<' . $email . '>', [250, 251]);
            }
            $this->command($socket, 'DATA', [354]);

            $headers = [];
            $headers[] = 'From: ' . $this->formatAddress($from, $fromName);
            $headers[] = 'To: ' . implode(', ', array_map(
                static fn (string $email): string => $email,
                $destinatarios
            ));
            $headers[] = 'Subject: ' . $this->encodeHeader($subject);
            $headers[] = 'MIME-Version: 1.0';
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';
            $headers[] = 'Date: ' . date('r');

            $mensagem = implode("\r\n", $headers)
                . "\r\n\r\n"
                . $this->normalizeBody($bodyText)
                . "\r\n.";
            $this->write($socket, $mensagem);
            $this->expect($socket, [250]);
            $this->command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $socket */
    private function command($socket, string $command, array $okCodes): void
    {
        $this->write($socket, $command);
        $this->expect($socket, $okCodes);
    }

    /** @param resource $socket */
    private function write($socket, string $data): void
    {
        $payload = $data . "\r\n";
        $escrito = fwrite($socket, $payload);
        if ($escrito === false || $escrito < strlen($payload)) {
            throw new RuntimeException('Falha ao escrever no socket SMTP.');
        }
    }

    /**
     * @param resource $socket
     * @param list<int> $okCodes
     */
    private function expect($socket, array $okCodes): void
    {
        $resposta = '';
        while (($linha = fgets($socket, 515)) !== false) {
            $resposta .= $linha;
            if (isset($linha[3]) && $linha[3] === ' ') {
                break;
            }
        }

        $codigo = (int)substr($resposta, 0, 3);
        if (!in_array($codigo, $okCodes, true)) {
            throw new RuntimeException(
                'Resposta SMTP inesperada (' . $codigo . '): ' . trim($resposta)
            );
        }
    }

    private function formatAddress(string $email, string $name): string
    {
        if ($name === '') {
            return $email;
        }
        return $this->encodeHeader($name) . ' <' . $email . '>';
    }

    private function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]+$/', $value) === 1) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function normalizeBody(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = str_replace("\n", "\r\n", $body);
        // Linhas começando com '.' precisam ser escapadas (dot-stuffing).
        return (string)preg_replace('/^\./m', '..', $body);
    }
}
