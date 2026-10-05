<?php
/**
 * AH5 Office - sending email
 * Designed & Developed by Anwar Hossain - https://anwar.com.bd
 *
 * Talks SMTP over a socket, because shared cPanel hosting has no Composer
 * and mail() alone lands in spam. Falls back to mail() if SMTP is not set up.
 * Handles one attachment, which is all an invoice or a licence scan needs.
 */

declare(strict_types=1);

final class Mailer
{
    /**
     * Which way mail goes out: your Gmail account, your own SMTP server,
     * or the server's own mail() as a last resort.
     */
    public static function mode(): string
    {
        $mode = (string) DB::setting('email_mode', 'gmail');
        return in_array($mode, ['gmail', 'smtp', 'server'], true) ? $mode : 'gmail';
    }

    /** Is email switched on and configured enough to try? */
    public static function ready(): bool
    {
        if (DB::setting('email_enabled', '0') !== '1') {
            return false;
        }
        if (self::mode() === 'gmail') {
            $user = (string) DB::setting('gmail_address', '');
            $pass = (string) DB::setting('gmail_app_password', '');
            return $user !== '' && $pass !== ''
                && filter_var($user, FILTER_VALIDATE_EMAIL) !== false;
        }
        $from = (string) DB::setting('smtp_from_email', '');
        return $from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** The host, port, security and login for the chosen way. */
    private static function transport(): array
    {
        if (self::mode() === 'gmail') {
            return [
                'host'   => 'smtp.gmail.com',
                'port'   => 587,
                'secure' => 'tls',
                'user'   => (string) DB::setting('gmail_address', ''),
                'pass'   => str_replace(' ', '', (string) DB::setting('gmail_app_password', '')),
                'from'   => (string) DB::setting('gmail_address', ''),
                'name'   => (string) DB::setting('smtp_from_name',
                            (string) DB::setting('company_name', 'AH5 Office')),
            ];
        }
        return [
            'host'   => (string) DB::setting('smtp_host', ''),
            'port'   => (int) DB::setting('smtp_port', '587'),
            'secure' => (string) DB::setting('smtp_secure', 'tls'),
            'user'   => (string) DB::setting('smtp_user', ''),
            'pass'   => (string) DB::setting('smtp_pass', ''),
            'from'   => (string) DB::setting('smtp_from_email', ''),
            'name'   => (string) DB::setting('smtp_from_name',
                        (string) DB::setting('company_name', 'AH5 Office')),
        ];
    }

    /**
     * @param array $attachment ['path' => absolute file, 'name' => shown name]
     * @return array{success:bool, error?:string, transport?:string}
     */
    public static function send(string $to, string $subject, string $body, array $attachment = []): array
    {
        if (!self::ready()) {
            return ['success' => false, 'error' => 'Email is not set up in Settings'];
        }
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return ['success' => false, 'error' => 'That is not a valid email address'];
        }

        $t = self::transport();
        [$headers, $message] = self::compose($t['from'], $t['name'], $to, $subject, $body, $attachment);

        if ($t['host'] === '') {
            $sent = @mail($to, self::encodeHeader($subject), $message, implode("\r\n", $headers));
            return $sent
                ? ['success' => true, 'transport' => 'server mail']
                : ['success' => false, 'error' => 'The server refused to send the mail'];
        }

        $result = self::sendSmtp($t, $to, $subject, $headers, $message);
        if (!$result['success'] && self::mode() === 'gmail'
            && str_contains(strtolower((string) ($result['error'] ?? '')), 'password')) {
            $result['error'] = 'Gmail refused the login. Use a 16-character App Password, '
                             . 'not your normal Gmail password — Google Account → Security → App passwords.';
        }
        return $result;
    }

    /** Build the headers and the MIME body. */
    private static function compose(
        string $fromEmail, string $fromName, string $to, string $subject, string $body, array $attachment
    ): array {
        $boundary = 'ah5_' . bin2hex(random_bytes(8));
        $htmlBody = self::htmlWrap($body);

        $headers = [
            'From: ' . self::encodeHeader($fromName) . ' <' . $fromEmail . '>',
            'Reply-To: ' . $fromEmail,
            'MIME-Version: 1.0',
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . self::hostPart($fromEmail) . '>',
        ];

        $file = $attachment['path'] ?? null;
        $hasFile = is_string($file) && is_file($file);

        if (!$hasFile) {
            $alt = 'alt_' . bin2hex(random_bytes(6));
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $alt . '"';
            $message = self::textAndHtml($alt, $body, $htmlBody);
            return [$headers, $message];
        }

        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $alt = 'alt_' . bin2hex(random_bytes(6));

        $message  = "--{$boundary}\r\n";
        $message .= "Content-Type: multipart/alternative; boundary=\"{$alt}\"\r\n\r\n";
        $message .= self::textAndHtml($alt, $body, $htmlBody);
        $message .= "\r\n--{$boundary}\r\n";

        $name = (string) ($attachment['name'] ?? basename((string) $file));
        $mime = Uploads::mimeFor((string) $file);
        $data = chunk_split(base64_encode((string) file_get_contents((string) $file)));

        $message .= "Content-Type: {$mime}; name=\"{$name}\"\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n";
        $message .= "Content-Disposition: attachment; filename=\"{$name}\"\r\n\r\n";
        $message .= $data . "\r\n";
        $message .= "--{$boundary}--\r\n";

        return [$headers, $message];
    }

    private static function textAndHtml(string $boundary, string $text, string $html): string
    {
        $out  = "--{$boundary}\r\n";
        $out .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $out .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $out .= chunk_split(base64_encode($text)) . "\r\n";
        $out .= "--{$boundary}\r\n";
        $out .= "Content-Type: text/html; charset=UTF-8\r\n";
        $out .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $out .= chunk_split(base64_encode($html)) . "\r\n";
        $out .= "--{$boundary}--\r\n";
        return $out;
    }

    /** Plain text becomes a readable HTML mail, Bengali included. */
    private static function htmlWrap(string $text): string
    {
        $safe = nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
        $company = htmlspecialchars((string) DB::setting('company_name', ''), ENT_QUOTES, 'UTF-8');

        return '<!DOCTYPE html><html><head><meta charset="utf-8"></head>'
            . '<body style="margin:0;padding:24px;background:#f6f6f4;'
            . 'font-family:\'Segoe UI\',system-ui,sans-serif;color:#1b2233;font-size:15px;line-height:1.6">'
            . '<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:8px;padding:28px">'
            . '<p style="margin:0 0 18px">' . $safe . '</p>'
            . '<hr style="border:0;border-top:1px solid #eee;margin:22px 0">'
            . '<p style="margin:0;font-size:12px;color:#888">' . $company . '</p>'
            . '</div></body></html>';
    }

    /** Speak SMTP by hand. Returns the same shape as the other channels. */
    private static function sendSmtp(
        array $t, string $to, string $subject, array $headers, string $message
    ): array {
        $host      = (string) $t['host'];
        $port      = (int) $t['port'];
        $secure    = (string) $t['secure'];
        $user      = (string) $t['user'];
        $pass      = (string) $t['pass'];
        $fromEmail = (string) $t['from'];

        $target = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $socket = @stream_socket_client($target, $errNo, $errStr, 15);
        if ($socket === false) {
            return ['success' => false, 'error' => 'Cannot reach the mail server: ' . $errStr];
        }
        stream_set_timeout($socket, 15);

        $read = static function () use ($socket): string {
            $out = '';
            while (($line = fgets($socket, 515)) !== false) {
                $out .= $line;
                if (strlen($line) < 4 || $line[3] !== '-') {
                    break;
                }
            }
            return $out;
        };
        $say = static function (string $cmd) use ($socket, $read): string {
            fwrite($socket, $cmd . "\r\n");
            return $read();
        };
        $code = static fn (string $reply): int => (int) substr(trim($reply), 0, 3);

        $greeting = $read();
        if ($code($greeting) !== 220) {
            fclose($socket);
            return ['success' => false, 'error' => 'The mail server did not greet us: ' . trim($greeting)];
        }

        $me = self::hostPart($fromEmail) ?: 'localhost';
        $say('EHLO ' . $me);

        if ($secure === 'tls') {
            $reply = $say('STARTTLS');
            if ($code($reply) !== 220) {
                fclose($socket);
                return ['success' => false, 'error' => 'The server refused STARTTLS'];
            }
            if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($socket);
                return ['success' => false, 'error' => 'Could not start a secure connection'];
            }
            $say('EHLO ' . $me);
        }

        if ($user !== '') {
            $reply = $say('AUTH LOGIN');
            if ($code($reply) !== 334) {
                fclose($socket);
                return ['success' => false, 'error' => 'The server would not accept a login'];
            }
            $say(base64_encode($user));
            $reply = $say(base64_encode($pass));
            if ($code($reply) !== 235) {
                fclose($socket);
                return ['success' => false, 'error' => 'The mail username or password is wrong'];
            }
        }

        $reply = $say('MAIL FROM:<' . $fromEmail . '>');
        if ($code($reply) !== 250) {
            fclose($socket);
            return ['success' => false, 'error' => 'The server rejected the sender address'];
        }
        $reply = $say('RCPT TO:<' . $to . '>');
        if (!in_array($code($reply), [250, 251], true)) {
            fclose($socket);
            return ['success' => false, 'error' => 'The server rejected the recipient: ' . trim($reply)];
        }

        $reply = $say('DATA');
        if ($code($reply) !== 354) {
            fclose($socket);
            return ['success' => false, 'error' => 'The server would not take the message'];
        }

        $full = implode("\r\n", array_merge($headers, [
            'To: ' . $to,
            'Subject: ' . self::encodeHeader($subject),
        ])) . "\r\n\r\n" . $message;

        // a lone dot on its own line would end the message early
        $full = preg_replace('/^\./m', '..', $full) ?? $full;

        fwrite($socket, $full . "\r\n.\r\n");
        $reply = $read();
        $say('QUIT');
        fclose($socket);

        if ($code($reply) !== 250) {
            return ['success' => false, 'error' => 'The server did not accept the message: ' . trim($reply)];
        }
        return ['success' => true, 'transport' => 'smtp'];
    }

    private static function encodeHeader(string $value): string
    {
        return preg_match('/[\x80-\xFF]/', $value)
            ? '=?UTF-8?B?' . base64_encode($value) . '?='
            : $value;
    }

    private static function hostPart(string $email): string
    {
        $at = strrpos($email, '@');
        return $at === false ? 'localhost' : substr($email, $at + 1);
    }
}
