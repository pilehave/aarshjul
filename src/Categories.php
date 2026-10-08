<?php
declare(strict_types=1);

final class Categories
{
    /** Alle kategorier med antal begivenheder, nøglet på id og sorteret efter navn. ring_color er null, hvis der ikke er valgt baggrund. */
    public static function all(): array
    {
        $res = [];
        foreach (db()->query('SELECT c.id, c.name, c.color, c.ring_color, COUNT(e.id) AS used FROM categories c
            LEFT JOIN events e ON e.category_id = c.id GROUP BY c.id, c.name, c.color, c.ring_color ORDER BY c.name') as $c) {
            $res[(int)$c['id']] = $c;
        }
        return $res;
    }

    /**
     * Gemmer kategorisiden: name[id]/color[id] for eksisterende, delete[] for sletning
     * og new_name/new_color for en ny kategori. Baggrunden for ringen er ring_color[id] (new_ring_color),
     * men gemmes kun, når has_ring_color[id] (new_has_ring_color) er sat. Returnerer fejl[].
     */
    public static function saveAll(array $in): array
    {
        $existing = self::all();
        $errors = [];
        $delete = array_intersect_key(array_flip(array_map('intval', (array)($in['delete'] ?? []))), $existing);
        $rows = [];
        foreach ($existing as $id => $c) {
            if (isset($delete[$id])) {
                if ($c['used'] > 0) {
                    $errors[] = 'Kategorien "' . $c['name'] . '" bruges af ' . $c['used'] . ' begivenhed(er) og kan ikke slettes.';
                }
                continue;
            }
            $rows[$id] = [
                'name'       => trim((string)($in['name'][$id] ?? $c['name'])),
                'color'      => (string)($in['color'][$id] ?? $c['color']),
                'ring_color' => !empty($in['has_ring_color'][$id]) ? (string)($in['ring_color'][$id] ?? '') : null,
            ];
        }
        $newName = trim((string)($in['new_name'] ?? ''));
        if ($newName !== '') {
            $rows['new'] = [
                'name'       => $newName,
                'color'      => (string)($in['new_color'] ?? ''),
                'ring_color' => !empty($in['new_has_ring_color']) ? (string)($in['new_ring_color'] ?? '') : null,
            ];
        }

        $seen = [];
        foreach ($rows as $r) {
            if ($r['name'] === '') {
                $errors[] = 'En kategori skal have et navn.';
            } elseif (mb_strlen($r['name']) > 100) {
                $errors[] = 'Navnet "' . $r['name'] . '" er for langt (højst 100 tegn).';
            } elseif (isset($seen[mb_strtolower($r['name'])])) {
                $errors[] = 'Der er flere kategorier med navnet "' . $r['name'] . '".';
            }
            $seen[mb_strtolower($r['name'])] = true;
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $r['color'])) {
                $errors[] = 'Ugyldig farve for "' . $r['name'] . '".';
            }
            if ($r['ring_color'] !== null && !preg_match('/^#[0-9a-fA-F]{6}$/', $r['ring_color'])) {
                $errors[] = 'Ugyldig baggrundsfarve for "' . $r['name'] . '".';
            }
        }
        if ($errors) {
            return $errors;
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare('DELETE FROM categories WHERE id=?');
            foreach (array_keys($delete) as $id) {
                $del->execute([$id]);
            }
            // Navne byttes i to trin, så den unikke nøgle ikke rammes, hvis to kategorier bytter navn
            $lower = fn(?string $c) => $c === null ? null : strtolower($c);
            $upd = $pdo->prepare('UPDATE categories SET name=?, color=?, ring_color=? WHERE id=?');
            foreach ($rows as $id => $r) {
                if ($id !== 'new') {
                    $upd->execute(["~tmp~$id", $lower($r['color']), $lower($r['ring_color']), $id]);
                }
            }
            foreach ($rows as $id => $r) {
                if ($id !== 'new') {
                    $upd->execute([$r['name'], $lower($r['color']), $lower($r['ring_color']), $id]);
                }
            }
            if (isset($rows['new'])) {
                $pdo->prepare('INSERT INTO categories (name, color, ring_color) VALUES (?, ?, ?)')
                    ->execute([$rows['new']['name'], $lower($rows['new']['color']), $lower($rows['new']['ring_color'])]);
            }
            $pdo->commit();
        } catch (Throwable $t) {
            $pdo->rollBack();
            return ['Kunne ikke gemme: ' . $t->getMessage()];
        }
        return [];
    }
}
