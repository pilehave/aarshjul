<?php
declare(strict_types=1);

/**
 * Login og roller. Sessionen gemmer kun bruger-id og et fingeraftryk af adgangskoden,
 * så en ny adgangskode eller en deaktivering logger andre sessioner ud med det samme.
 */
final class Auth
{
    /** Rollerne i stigende rækkefølge. En rolle kan alt, hvad rollerne før den kan. */
    public const ROLES = [
        'reader'      => 'Læser',
        'contributor' => 'Bidragyder',
        'admin'       => 'Administrator',
    ];

    public const MAX_ATTEMPTS = 5;      // mislykkede forsøg pr. mail eller IP ...
    public const ATTEMPT_WINDOW = 15;   // ... inden for så mange minutter

    private static ?array $user = null;
    private static bool $loaded = false;

    /** Den indloggede bruger, eller null. */
    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = (int)($_SESSION['user_id'] ?? 0);
            if ($id) {
                $st = db()->prepare('SELECT * FROM users WHERE id = ? AND active = 1');
                $st->execute([$id]);
                $u = $st->fetch() ?: null;
                if ($u && hash_equals((string)($_SESSION['auth'] ?? ''), self::fingerprint($u))) {
                    self::$user = $u;
                } else {
                    unset($_SESSION['user_id'], $_SESSION['auth']);
                }
            }
        }
        return self::$user;
    }

    /** Har brugeren mindst rollen $role? */
    public static function can(string $role, ?array $user = null): bool
    {
        $user ??= self::user();
        return $user !== null && self::rank($user['role']) >= self::rank($role);
    }

    /** Logger ind. Returnerer fejl[] (tom ved succes). */
    public static function login(string $email, string $password, string $ip): array
    {
        $email = mb_strtolower(trim($email));
        $pdo = db();
        try {
            $st = $pdo->prepare('SELECT
                (SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > NOW() - INTERVAL ? MINUTE),
                (SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > NOW() - INTERVAL ? MINUTE)');
            $st->execute([$email, self::ATTEMPT_WINDOW, $ip, self::ATTEMPT_WINDOW]);
            [$byEmail, $byIp] = array_map('intval', $st->fetch(PDO::FETCH_NUM));
            if ($byEmail >= self::MAX_ATTEMPTS || $byIp >= self::MAX_ATTEMPTS * 4) {
                return ['For mange forsøg. Vent ' . self::ATTEMPT_WINDOW . ' minutter, og prøv igen.'];
            }
            $st = $pdo->prepare('SELECT * FROM users WHERE email = ?');
            $st->execute([$email]);
            $u = $st->fetch() ?: null;
        } catch (PDOException) {
            return ['Brugertabellen findes ikke. Kør migrationen sql/migrations/2026-10-07-users.sql.'];
        }

        // password_verify køres altid, så svartiden ikke afslører, om mailen findes
        $ok = password_verify($password, $u['password_hash'] ?? self::dummyHash());
        if (!$u || !$ok || !$u['active'] || $u['password_hash'] === null) {
            $pdo->prepare('INSERT INTO login_attempts (email, ip) VALUES (?, ?)')->execute([$email, $ip]);
            return ['Forkert mail eller adgangskode.'];
        }

        if (password_needs_rehash($u['password_hash'], self::algorithm())) {
            $u['password_hash'] = self::hash($password);
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$u['password_hash'], $u['id']]);
        }
        $pdo->prepare('DELETE FROM login_attempts WHERE email = ? OR attempted_at < NOW() - INTERVAL 1 DAY')->execute([$email]);
        $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$u['id']]);

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$u['id'];
        $_SESSION['auth'] = self::fingerprint($u);
        self::$user = $u;
        self::$loaded = true;
        return [];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        self::$user = null;
    }

    /** Krav til en ny adgangskode. Returnerer fejl[]. */
    public static function validatePassword(string $password, string $repeat): array
    {
        $errors = [];
        $len = mb_strlen($password);
        if ($len < 12 || $len > 256) {
            $errors[] = 'Adgangskoden skal være mellem 12 og 256 tegn.';
        }
        if (!preg_match('/\p{Lu}/u', $password) || !preg_match('/\p{Ll}/u', $password) || !preg_match('/\d/', $password)) {
            $errors[] = 'Adgangskoden skal indeholde mindst ét stort bogstav, ét lille bogstav og ét tal.';
        }
        if ($password !== $repeat) {
            $errors[] = 'De to adgangskoder er ikke ens.';
        }
        return $errors;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, self::algorithm());
    }

    /** Argon2id, hvis PHP har den. bcrypt (standarden) ignorerer alt efter 72 bytes. */
    private static function algorithm(): string|int|null
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    private static function rank(string $role): int
    {
        $i = array_search($role, array_keys(self::ROLES), true);
        return $i === false ? -1 : $i;
    }

    private static function fingerprint(array $u): string
    {
        return hash_hmac('sha256', (string)$u['password_hash'], 'aarshjul-session|' . $u['id']);
    }

    private static function dummyHash(): string
    {
        static $h = null;
        return $h ??= self::hash(bin2hex(random_bytes(8)));
    }
}
