<?php
declare(strict_types=1);

/** Administration af brugere (siden users.php). Login og roller ligger i Auth. */
final class Users
{
    /** Alle brugere med evt. personens navn, nøglet på id og sorteret efter navn. */
    public static function all(): array
    {
        $res = [];
        foreach (db()->query('SELECT u.*, p.name AS person_name FROM users u
            LEFT JOIN people p ON p.id = u.person_id ORDER BY u.active DESC, u.name') as $u) {
            $res[(int)$u['id']] = $u;
        }
        return $res;
    }

    /**
     * Gemmer brugersiden: name[id], email[id], role[id], person[id] og active[id] for eksisterende brugere,
     * new_* for en ny. Brugere slettes ikke, men deaktiveres, så deres navn kan blive stående ved flueben og noter.
     * $selfId er den indloggede administrator, som ikke kan fjerne sin egen adgang.
     * @return array{0:?int,1:string[]} [id på den nye bruger eller null, fejl[]]
     */
    public static function saveAll(array $in, int $selfId): array
    {
        $existing = self::all();
        $rows = [];
        foreach ($existing as $id => $u) {
            $rows[$id] = [
                'name'   => trim((string)($in['name'][$id] ?? $u['name'])),
                'email'  => mb_strtolower(trim((string)($in['email'][$id] ?? $u['email']))),
                'role'   => (string)($in['role'][$id] ?? $u['role']),
                'person' => (int)($in['person'][$id] ?? $u['person_id']) ?: null,
                'active' => !empty($in['active'][$id]),
            ];
        }
        $newEmail = mb_strtolower(trim((string)($in['new_email'] ?? '')));
        $newName = trim((string)($in['new_name'] ?? ''));
        if ($newEmail !== '' || $newName !== '') {
            $rows['new'] = [
                'name'   => $newName,
                'email'  => $newEmail,
                'role'   => (string)($in['new_role'] ?? 'reader'),
                'person' => (int)($in['new_person'] ?? 0) ?: null,
                'active' => true,
            ];
        }

        $errors = self::validate($rows, $selfId, array_map('intval', array_keys(People::all())));
        if ($errors) {
            return [null, $errors];
        }

        $pdo = db();
        $newId = null;
        $pdo->beginTransaction();
        try {
            // Mail og person er unikke. Frigør dem først, så to brugere kan bytte
            $free = $pdo->prepare("UPDATE users SET email = CONCAT('~tmp~', id), person_id = NULL WHERE id = ?");
            $upd = $pdo->prepare('UPDATE users SET name=?, email=?, role=?, person_id=?, active=? WHERE id=?');
            foreach ($rows as $id => $r) {
                if ($id !== 'new') {
                    $free->execute([$id]);
                }
            }
            foreach ($rows as $id => $r) {
                if ($id !== 'new') {
                    $upd->execute([$r['name'], $r['email'], $r['role'], $r['person'], (int)$r['active'], $id]);
                }
            }
            if (isset($rows['new'])) {
                $r = $rows['new'];
                $pdo->prepare('INSERT INTO users (name, email, role, person_id) VALUES (?,?,?,?)')
                    ->execute([$r['name'], $r['email'], $r['role'], $r['person']]);
                $newId = (int)$pdo->lastInsertId();
            }
            $pdo->commit();
        } catch (Throwable $t) {
            $pdo->rollBack();
            return [null, ['Kunne ikke gemme: ' . $t->getMessage()]];
        }
        return [$newId, []];
    }

    /**
     * Validerer alle rækker samlet (fx unikke mails på tværs af rækkerne). Ren funktion, så den kan testes.
     * @param array<int|string,array{name:string,email:string,role:string,person:?int,active:bool}> $rows
     * @param int[] $personIds de personer, der findes
     */
    public static function validate(array $rows, int $selfId, array $personIds): array
    {
        $errors = [];
        $emails = [];
        $persons = [];
        foreach ($rows as $id => $r) {
            $who = $r['name'] !== '' ? '"' . $r['name'] . '"' : 'Den nye bruger';
            if ($r['name'] === '' || mb_strlen($r['name']) > 150) {
                $errors[] = 'En bruger skal have et navn på højst 150 tegn.';
            }
            if (!filter_var($r['email'], FILTER_VALIDATE_EMAIL) || strlen($r['email']) > 254) {
                $errors[] = "$who har ikke en gyldig mailadresse.";
            } elseif (isset($emails[$r['email']])) {
                $errors[] = "Mailadressen {$r['email']} bruges af flere brugere.";
            }
            $emails[$r['email']] = true;
            if (!isset(Auth::ROLES[$r['role']])) {
                $errors[] = "$who har en ukendt rolle.";
            }
            if ($r['person'] !== null) {
                if (!in_array($r['person'], $personIds, true)) {
                    $errors[] = "$who er koblet til en person, der ikke findes.";
                } elseif (isset($persons[$r['person']])) {
                    $errors[] = 'En person kan kun kobles til én bruger.';
                }
                $persons[$r['person']] = true;
            }
            if ($id === $selfId && ($r['role'] !== 'admin' || !$r['active'])) {
                $errors[] = 'Du kan ikke fjerne din egen administratoradgang eller deaktivere dig selv.';
            }
        }
        if (!array_filter($rows, fn($r) => $r['role'] === 'admin' && $r['active'])) {
            $errors[] = 'Der skal være mindst én aktiv administrator.';
        }
        return array_values(array_unique($errors));
    }
}
