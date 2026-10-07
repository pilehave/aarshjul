<?php
declare(strict_types=1);

/**
 * Minimal SMTP-klient til tekstmails (nulstilling af adgangskode, invitationer, påmindelser).
 * Ingen afhængigheder. Opsætningen står i config.php (smtp_*, mail_from, mail_from_name).
 *
 * smtp_secure:  tls   STARTTLS (typisk port 587)
 *               ssl   krypteret fra start (typisk port 465)
 *               none  ukrypteret, kun til Mailpit under udvikling. Login afvises uden kryptering.
 */
final class Mailer
{
    private const TIMEOUT = 15;

    /** Sender en tekstmail. Returnerer fejl[] (tom ved succes). */
    public static function send(string $to, string $subject, string $body): array
    {
        $from = (string)config('mail_from');
        try {
            $message = self::buildMessage($to, $subject, $body, $from, (string)config('mail_from_name'));
        } catch (InvalidArgumentException $e) {
            return [$e->getMessage()];
        }

        $host   = (string)config('smtp_host');
        $port   = (int)config('smtp_port');
        $secure = (string)config('smtp_secure');
        $user   = (string)config('smtp_user');
        if (!in_array($secure, ['tls', 'ssl', 'none'], true)) {
            return ['Ugyldig smtp_secure: "' . $secure . '" (brug tls, ssl eller none).'];
        }
        if ($user !== '' && $secure === 'none') {
            return ['Afviser at sende SMTP-login ukrypteret. Brug smtp_secure tls eller ssl.'];
        }

        $context = stream_context_create(['ssl' => ['peer_name' => $host, 'verify_peer' => true, 'verify_peer_name' => true]]);
        $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $smtp = @stream_socket_client($remote, $errno, $errstr, self::TIMEOUT, STREAM_CLIENT_CONNECT, $context);
        if (!$smtp) {
            return ["Kunne ikke forbinde til mailserveren $host:$port ($errstr)."];
        }
        stream_set_timeout($smtp, self::TIMEOUT);

        try {
            self::expect($smtp, 220);
            $ehlo = self::command($smtp, 'EHLO ' . self::heloName(), 250);
            if ($secure === 'tls') {
                self::command($smtp, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($smtp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new RuntimeException('STARTTLS mislykkedes.');
                }
                $ehlo = self::command($smtp, 'EHLO ' . self::heloName(), 250);
            }
            if ($user !== '') {
                $pass = (string)config('smtp_pass');
                if (preg_match('/^AUTH\b.*\bPLAIN\b/mi', implode("\n", array_map(fn($l) => substr($l, 4), $ehlo)))) {
                    self::command($smtp, 'AUTH PLAIN ' . base64_encode("\0$user\0$pass"), 235, 'login');
                } else {
                    self::command($smtp, 'AUTH LOGIN', 334);
                    self::command($smtp, base64_encode($user), 334, 'login');
                    self::command($smtp, base64_encode($pass), 235, 'login');
                }
            }
            self::command($smtp, "MAIL FROM:<$from>", 250);
            self::command($smtp, "RCPT TO:<$to>", [250, 251]);
            self::command($smtp, 'DATA', 354);
            self::command($smtp, $message . "\r\n.", 250, 'mailens indhold');
            self::command($smtp, 'QUIT', 221);
        } catch (RuntimeException $e) {
            return ['Mailen kunne ikke sendes: ' . $e->getMessage()];
        } finally {
            fclose($smtp);
        }
        return [];
    }

    /**
     * Bygger hele mailen (headere og krop) klar til DATA. Kroppen sendes som base64, så lange linjer
     * og danske tegn ikke giver problemer, og ingen linje kan starte med punktum.
     */
    public static function buildMessage(string $to, string $subject, string $body, string $from, string $fromName): string
    {
        foreach (['Modtager' => $to, 'Afsender' => $from] as $what => $addr) {
            if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException("$what er ikke en gyldig mailadresse: \"$addr\".");
            }
        }
        if (preg_match('/[\r\n]/', $subject . $fromName)) {
            throw new InvalidArgumentException('Emne og afsendernavn må ikke indeholde linjeskift.');
        }

        $domain = substr($from, strpos($from, '@') + 1);
        $headers = [
            'Date: ' . date('r'),
            'From: ' . ($fromName !== '' ? self::encodeHeader($fromName) . ' ' : '') . "<$from>",
            "To: <$to>",
            'Subject: ' . self::encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . "@$domain>",
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $encoded = rtrim(chunk_split(base64_encode(str_replace("\n", "\r\n", $body)), 76, "\r\n"));
        return implode("\r\n", $headers) . "\r\n\r\n" . $encoded;
    }

    private static function encodeHeader(string $s): string
    {
        return preg_match('/[^\x20-\x7e]/', $s) ? mb_encode_mimeheader($s, 'UTF-8', 'B', "\r\n") : $s;
    }

    private static function heloName(): string
    {
        return parse_url((string)config('app_url'), PHP_URL_HOST) ?: 'localhost';
    }

    /** @param resource $smtp  @param int|int[] $code  $label vises i fejlbeskeder i stedet for linjen (fx ved login) */
    private static function command($smtp, string $line, int|array $code, ?string $label = null): array
    {
        fwrite($smtp, $line . "\r\n");
        return self::expect($smtp, $code, $label ?? $line);
    }

    /** Læser et (evt. flerlinjet) svar og tjekker koden. @return string[] svarlinjerne */
    private static function expect($smtp, int|array $code, string $after = 'forbindelse'): array
    {
        $lines = [];
        do {
            $line = fgets($smtp, 1024);
            if ($line === false) {
                throw new RuntimeException("Ingen svar fra mailserveren efter $after.");
            }
            $lines[] = rtrim($line);
        } while (isset($line[3]) && $line[3] === '-');
        if (!in_array((int)substr($lines[0], 0, 3), (array)$code, true)) {
            throw new RuntimeException("Mailserveren svarede \"" . end($lines) . "\" efter $after.");
        }
        return $lines;
    }
}
