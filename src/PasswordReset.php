<?php
declare(strict_types=1);

/**
 * Engangslinks til at vælge adgangskode, sendt på mail: "Glemt adgangskode" og invitation af nye brugere.
 * Nøglen er 32 tilfældige bytes. Kun dens sha256 gemmes, og den slettes, når den er brugt.
 * En bruger har højst ét gyldigt link ad gangen.
 */
final class PasswordReset
{
    public const RESET_MINUTES = 60;
    public const INVITE_DAYS = 7;
    public const MAX_RESETS_PER_HOUR = 3;

    /**
     * "Glemt adgangskode": sender et link, hvis mailen tilhører en aktiv bruger. Kalderen viser altid
     * samme besked, så svaret ikke afslører, om mailen findes. Fejl ved afsendelse logges kun.
     */
    public static function request(string $email): void
    {
        $st = db()->prepare('SELECT * FROM users WHERE email = ? AND active = 1');
        $st->execute([mb_strtolower(trim($email))]);
        $u = $st->fetch();
        if (!$u) {
            return;
        }
        $st = db()->prepare("SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND purpose = 'reset' AND created_at > NOW() - INTERVAL 1 HOUR");
        $st->execute([$u['id']]);
        if ((int)$st->fetchColumn() >= self::MAX_RESETS_PER_HOUR) {
            return;
        }
        $token = self::create((int)$u['id'], 'reset', self::RESET_MINUTES);
        $errors = Mailer::send($u['email'], 'Ny adgangskode til Årshjul', self::resetMail($u['name'], self::link($token)));
        if ($errors) {
            error_log('Årshjul: kunne ikke sende mail om ny adgangskode til ' . $u['email'] . ': ' . implode(' ', $errors));
        }
    }

    /** Sender en invitation til en aktiv bruger. Returnerer fejl[]. */
    public static function invite(int $userId, string $invitedBy): array
    {
        $st = db()->prepare('SELECT * FROM users WHERE id = ? AND active = 1');
        $st->execute([$userId]);
        $u = $st->fetch();
        if (!$u) {
            return ['Brugeren findes ikke eller er deaktiveret.'];
        }
        $token = self::create($userId, 'invite', self::INVITE_DAYS * 24 * 60);
        $errors = Mailer::send($u['email'], 'Invitation til Årshjul', self::inviteMail($u['name'], $invitedBy, self::link($token)));
        if ($errors) {
            self::forget($userId);
        }
        return $errors;
    }

    /** Brugeren bag et gyldigt link (med 'purpose'), eller null. */
    public static function find(string $token): ?array
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        $st = db()->prepare('SELECT u.*, r.purpose FROM password_resets r JOIN users u ON u.id = r.user_id
            WHERE r.token_hash = ? AND r.expires_at > NOW() AND u.active = 1');
        $st->execute([hash('sha256', $token)]);
        return $st->fetch() ?: null;
    }

    /** Sætter en ny adgangskode via et link. Linket kan derefter ikke bruges igen. Returnerer fejl[]. */
    public static function complete(string $token, string $password, string $repeat): array
    {
        $u = self::find($token);
        if (!$u) {
            return ['Linket er udløbet eller allerede brugt. Bed om et nyt.'];
        }
        $errors = Auth::validatePassword($password, $repeat);
        if ($errors) {
            return $errors;
        }
        Auth::setPassword((int)$u['id'], $password);
        db()->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$u['email']]);
        return [];
    }

    /** Sletter brugerens links (fx når adgangskoden er skiftet). */
    public static function forget(int $userId): void
    {
        db()->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$userId]);
    }

    public static function link(string $token): string
    {
        return config('app_url') . '/reset.php?token=' . $token;
    }

    public static function resetMail(string $name, string $link): string
    {
        return "Hej $name\n\n"
            . "Nogen (forhåbentlig dig) har bedt om en ny adgangskode til Årshjul. Vælg en ny her:\n\n"
            . "$link\n\n"
            . 'Linket virker i ' . self::RESET_MINUTES . " minutter og kan kun bruges én gang.\n"
            . "Har du ikke bedt om det, kan du se bort fra denne mail. Din adgangskode er ikke ændret.\n";
    }

    public static function inviteMail(string $name, string $invitedBy, string $link): string
    {
        return "Hej $name\n\n"
            . "$invitedBy har givet dig adgang til Årshjul. Vælg din adgangskode her:\n\n"
            . "$link\n\n"
            . 'Linket virker i ' . self::INVITE_DAYS . " dage og kan kun bruges én gang.\n"
            . 'Bagefter logger du ind på ' . config('app_url') . " med din mailadresse.\n";
    }

    /** Opretter et nyt link og sletter brugerens tidligere. @return string nøglen (kun i mailen, aldrig i databasen) */
    private static function create(int $userId, string $purpose, int $minutes): string
    {
        $token = bin2hex(random_bytes(32));
        $pdo = db();
        // Gamle links slettes, men "Glemt adgangskode"-forsøg inden for den seneste time tælles stadig (se request)
        $pdo->prepare("DELETE FROM password_resets WHERE user_id = ? AND (purpose = 'invite' OR created_at <= NOW() - INTERVAL 1 HOUR)")->execute([$userId]);
        $pdo->prepare("UPDATE password_resets SET expires_at = NOW() WHERE user_id = ?")->execute([$userId]);
        $pdo->prepare('INSERT INTO password_resets (token_hash, user_id, purpose, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL ? MINUTE)')
            ->execute([hash('sha256', $token), $userId, $purpose, $minutes]);
        return $token;
    }
}
