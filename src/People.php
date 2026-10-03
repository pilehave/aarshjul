<?php
declare(strict_types=1);

final class People
{
    /** Alle personer med antal begivenheder, nøglet på id og sorteret efter navn. */
    public static function all(): array
    {
        $res = [];
        foreach (db()->query('SELECT p.id, p.name, COUNT(ep.event_id) AS used FROM people p
            LEFT JOIN event_people ep ON ep.person_id = p.id GROUP BY p.id, p.name ORDER BY p.name') as $p) {
            $res[(int)$p['id']] = $p;
        }
        return $res;
    }

    /**
     * Gemmer personsiden: name[id] for eksisterende, delete[] for sletning og new_name for en ny person.
     * Slettes en person, fjernes vedkommende fra sine begivenheder. Returnerer fejl[].
     */
    public static function saveAll(array $in): array
    {
        $existing = self::all();
        $errors = [];
        $delete = array_intersect_key(array_flip(array_map('intval', (array)($in['delete'] ?? []))), $existing);
        $rows = [];
        foreach ($existing as $id => $p) {
            if (!isset($delete[$id])) {
                $rows[$id] = trim((string)($in['name'][$id] ?? $p['name']));
            }
        }
        $newName = trim((string)($in['new_name'] ?? ''));
        if ($newName !== '') {
            $rows['new'] = $newName;
        }

        $seen = [];
        foreach ($rows as $name) {
            if ($name === '') {
                $errors[] = 'En person skal have et navn.';
            } elseif (mb_strlen($name) > 150) {
                $errors[] = 'Navnet "' . $name . '" er for langt (højst 150 tegn).';
            } elseif (isset($seen[mb_strtolower($name)])) {
                $errors[] = 'Der er flere personer med navnet "' . $name . '".';
            }
            $seen[mb_strtolower($name)] = true;
        }
        if ($errors) {
            return $errors;
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare('DELETE FROM people WHERE id=?');
            foreach (array_keys($delete) as $id) {
                $del->execute([$id]);
            }
            // Navne byttes i to trin, så den unikke nøgle ikke rammes, hvis to personer bytter navn
            $upd = $pdo->prepare('UPDATE people SET name=? WHERE id=?');
            foreach ($rows as $id => $name) {
                if ($id !== 'new') {
                    $upd->execute(["~tmp~$id", $id]);
                }
            }
            foreach ($rows as $id => $name) {
                if ($id !== 'new') {
                    $upd->execute([$name, $id]);
                }
            }
            if (isset($rows['new'])) {
                $pdo->prepare('INSERT INTO people (name) VALUES (?)')->execute([$rows['new']]);
            }
            $pdo->commit();
        } catch (Throwable $t) {
            $pdo->rollBack();
            return ['Kunne ikke gemme: ' . $t->getMessage()];
        }
        return [];
    }
}
