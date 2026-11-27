<?php
/**
 * Helper functions for admin/manage_books.php.
 *
 * Pulled out of the main script (which mixed these with page-rendering
 * logic) so the file is easier to navigate. Behavior is unchanged --
 * this is a straight move, not a rewrite.
 */

function table_exists(mysqli $conn, string $table): bool {
    $dbRes = $conn->query('SELECT DATABASE() AS db');
    $db = $dbRes ? (string)($dbRes->fetch_assoc()['db'] ?? '') : '';
    if ($db === '') return false;

    $stmt = $conn->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $db, $table);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res && $res->num_rows > 0;
}

function column_exists(mysqli $conn, string $table, string $column): bool {
    $dbRes = $conn->query('SELECT DATABASE() AS db');
    $db = $dbRes ? (string)($dbRes->fetch_assoc()['db'] ?? '') : '';
    if ($db === '') return false;

    $stmt = $conn->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('sss', $db, $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res && $res->num_rows > 0;
}

function get_or_create_location_id(
    mysqli $conn,
    string $section,
    string $classCode,
    string $className,
    string $shelf,
    string $row,
    string $position
): int {
    $find = $conn->prepare(
        'SELECT location_id FROM library_locations
         WHERE section = ? AND class_code = ? AND shelf_code = ? AND row_label = ? AND position_label = ?
         LIMIT 1'
    );

    if ($find) {
        $find->bind_param('sssss', $section, $classCode, $shelf, $row, $position);
        $find->execute();
        $res = $find->get_result();
        if ($res && ($rowData = $res->fetch_assoc())) {
            $find->close();
            return (int)($rowData['location_id'] ?? 0);
        }
        $find->close();
    }

    $insert = $conn->prepare(
        'INSERT INTO library_locations (section, class_code, class_name, shelf_code, row_label, position_label, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())'
    );

    if (!$insert) {
        return 0;
    }

    $insert->bind_param('ssssss', $section, $classCode, $className, $shelf, $row, $position);
    $insert->execute();
    $newId = (int)$insert->insert_id;
    $insert->close();

    return $newId;
}

/**
 * Section dropdown options as value => label. A stored section that is no
 * longer in the list (e.g. "General Collection" from before the 2026-11
 * section rules) is kept as the first option, marked "(old section)", so
 * saving a book unchanged never drops or changes its section.
 */
function manage_books_section_options(array $sections, string $current): array {
    $options = [];
    $current = trim($current);
    if ($current !== '' && !in_array($current, $sections, true)) {
        $options[$current] = $current . ' (old section)';
    }
    foreach ($sections as $sec) {
        $options[(string)$sec] = (string)$sec;
    }
    return $options;
}

/**
 * Location for a book from the add/edit form. Section is the only required
 * part; class, shelf, row and position are optional (a section-only book
 * points at the placeholder row with those four empty, as created by
 * database/assign_book_sections.sql). Reuses the existing row with exactly
 * these five values, otherwise creates one. Never edits an existing row,
 * since rows are shared between books. Returns 0 when no section is given.
 */
function manage_books_resolve_location_id(
    mysqli $conn,
    array $classNames,
    string $section,
    string $classCode,
    string $shelf,
    string $row,
    string $position
): int {
    if ($section === '') {
        return 0;
    }

    $className = $classCode !== '' ? (string)($classNames[$classCode] ?? 'General Works') : '';
    return get_or_create_location_id($conn, $section, $classCode, $className, $shelf, $row, $position);
}

/**
 * Class letter for a book's location, from its call number: the first
 * letter when it is a Library of Congress class in $classLabels (e.g.
 * "HF5635 .B35 2019" -> H); E and F share the list's "E/F" entry.
 * Otherwise (a digit, a letter we don't list) ''. Add Book and Edit Book
 * both use this; shelf, row and position are left empty in both.
 */
function manage_books_class_from_call_number(string $callNumber, array $classLabels): string {
    $firstLetter = strtoupper(substr($callNumber, 0, 1));
    if ($firstLetter === 'E' || $firstLetter === 'F') {
        $firstLetter = 'E/F';
    }
    return ($firstLetter !== '' && isset($classLabels[$firstLetter])) ? $firstLetter : '';
}

function build_legacy_location_text(string $section, string $classCode, string $shelf, string $row, string $position, string $callNumber): string {
    $locationParts = [];
    if ($section !== '') $locationParts[] = 'Section: ' . $section;
    if ($classCode !== '') $locationParts[] = 'Class: ' . $classCode;
    if ($shelf !== '') $locationParts[] = 'Shelf: ' . $shelf;
    if ($row !== '') $locationParts[] = 'Row: ' . $row;
    if ($position !== '') $locationParts[] = 'Position: ' . $position;
    if ($callNumber !== '') $locationParts[] = 'Call: ' . $callNumber;
    return implode(' | ', $locationParts);
}

function manage_books_parse_accession_inputs($rawInput): array {
    $values = [];
    if (is_array($rawInput)) {
        $values = $rawInput;
    } elseif (is_string($rawInput) && trim($rawInput) !== '') {
        $split = preg_split('/[\r\n,;]+/', $rawInput);
        if (is_array($split)) {
            $values = $split;
        }
    }

    $normalized = [];
    foreach ($values as $value) {
        $normalized[] = trim((string)$value);
    }

    return $normalized;
}

function manage_books_has_duplicate_values(array $values): bool {
    $seen = [];
    foreach ($values as $value) {
        $key = strtolower(trim((string)$value));
        if ($key === '') {
            continue;
        }
        if (isset($seen[$key])) {
            return true;
        }
        $seen[$key] = true;
    }
    return false;
}

function manage_books_accession_exists(mysqli $conn, string $accessionNo): bool {
    $token = trim($accessionNo);
    if ($token === '') {
        return false;
    }

    $stmt = $conn->prepare('SELECT 1 FROM book_copies WHERE accession_no = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $token);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = (bool)($res && $res->num_rows > 0);
    $stmt->close();

    return $exists;
}

function manage_books_effective_copy_status(string $copyStatusRaw, string $loanStatusRaw): string {
    $copyStatus = strtolower(trim($copyStatusRaw));
    $loanStatus = strtolower(trim($loanStatusRaw));

    foreach ([$loanStatus, $copyStatus] as $status) {
        if (in_array($status, ['missing', 'overdue', 'borrowed'], true)) {
            return $status;
        }
    }

    if ($copyStatus === 'available') {
        return 'available';
    }

    return 'unknown';
}

function manage_books_copy_status_is_locked(string $effectiveStatus): bool {
    return in_array(strtolower(trim($effectiveStatus)), ['borrowed', 'overdue', 'missing'], true);
}

function manage_books_fetch_book_copies(mysqli $conn, int $bookId): array {
    if ($bookId <= 0) {
        return [];
    }

    $rows = [];
    $sql = "
        SELECT
            bc.copy_id,
            bc.book_id,
            COALESCE(NULLIF(TRIM(bc.accession_no), ''), CONCAT('Copy #', bc.copy_id)) AS accession_no,
            LOWER(COALESCE(NULLIF(TRIM(bc.status), ''), 'unknown')) AS copy_status,
            LOWER(COALESCE(ar.status, '')) AS loan_status
        FROM book_copies bc
        LEFT JOIN (
            SELECT br1.copy_id, br1.record_id, br1.status
            FROM borrow_records br1
            INNER JOIN (
                SELECT copy_id, MAX(record_id) AS max_record_id
                FROM borrow_records
                WHERE status IN ('borrowed', 'overdue', 'missing')
                GROUP BY copy_id
            ) latest ON latest.max_record_id = br1.record_id
        ) ar ON ar.copy_id = bc.copy_id
        WHERE bc.book_id = ?
        ORDER BY bc.copy_id ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $bookId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $copyStatus = (string)($row['copy_status'] ?? '');
            $loanStatus = (string)($row['loan_status'] ?? '');
            $effectiveStatus = manage_books_effective_copy_status($copyStatus, $loanStatus);
            $rows[] = [
                'copy_id' => (int)($row['copy_id'] ?? 0),
                'book_id' => (int)($row['book_id'] ?? 0),
                'accession_no' => trim((string)($row['accession_no'] ?? '')),
                'copy_status' => $copyStatus,
                'loan_status' => $loanStatus,
                'effective_status' => $effectiveStatus,
                'is_locked' => manage_books_copy_status_is_locked($effectiveStatus)
            ];
        }
    }
    $stmt->close();

    return $rows;
}

function manage_books_ensure_activity_log_table(mysqli $conn): bool {
    return smartlib_admin_audit_ensure_table($conn);
}

function manage_books_actor_user_id(): int {
    return smartlib_admin_audit_actor_user_id();
}

function manage_books_actor_name(): string {
    return smartlib_admin_audit_actor_name();
}

function manage_books_log_activity(mysqli $conn, string $actionType, int $entityId, string $entityTitle, array $metadata = []): void {
    smartlib_admin_audit_log($conn, $actionType, 'book', $entityId, $entityTitle, $metadata);
}


/* ------------------------------------------------------------------
 * Several programs per book (Add Book, Edit Book, Bulk Import).
 *
 * books.program_id stays the MAIN program (dashboards, reports and charts
 * keep reading it). Every program the book is linked to, the main one
 * included, is a row in book_programs (book_id, program_id, source):
 * the main one is 'primary', programs a librarian ticks are 'manual', and
 * tools/auto_categorize_books.php writes 'auto'. That tool builds its
 * program profiles from 'primary' rows only and its --reset deletes only
 * 'auto' rows, so extra programs picked here are always 'manual'.
 * Callers check table_exists($conn, 'book_programs') first; without the
 * table only the main program is saved, exactly as before.
 * ------------------------------------------------------------------ */

/**
 * The programs posted by the Add/Edit form: program_ids[] (every ticked
 * id, in form order) and main_program_id (the one ticked first). An old
 * cached page that still posts a single program_id counts as a one-item
 * list whose item is the main. Ids must be whole numbers that exist in
 * $validIds (program_id => true); others are dropped, duplicates removed,
 * and the list is capped at the number of programs. The main is
 * main_program_id only when it is among the ticked ids, else 0 (none).
 *
 * @return array{ids:int[], main:int}
 */
function manage_books_program_selection(array $post, array $validIds): array {
    $isWholeNumber = static function ($value): bool {
        return (is_int($value) && $value > 0) || (is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/', trim($value)) === 1);
    };

    $raw = [];
    $main = 0;
    if (isset($post['program_ids'])) {
        $raw = is_array($post['program_ids']) ? array_values($post['program_ids']) : [];
        $mainRaw = $post['main_program_id'] ?? '';
        $main = $isWholeNumber($mainRaw) ? (int)trim((string)$mainRaw) : 0;
    } elseif (isset($post['program_id']) && !isset($post['main_program_id'])) {
        // Old single-select form.
        $raw = [$post['program_id']];
        $main = $isWholeNumber($post['program_id']) ? (int)trim((string)$post['program_id']) : 0;
    }

    $ids = [];
    $cap = count($validIds);
    foreach ($raw as $value) {
        if (count($ids) >= $cap) {
            break;
        }
        if (!$isWholeNumber($value)) {
            continue;
        }
        $id = (int)trim((string)$value);
        if (isset($validIds[$id]) && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }

    if (!in_array($main, $ids, true)) {
        $main = 0;
    }

    return ['ids' => $ids, 'main' => $main];
}

/**
 * Makes a book's book_programs rows equal $ids. Rows for programs no
 * longer ticked are deleted (whatever their source, 'auto' included). A
 * ticked program that already has a row keeps its source, except that the
 * main program's row becomes 'primary' and a 'primary' row that is no
 * longer the main one becomes 'manual'. New rows are 'primary' for the
 * main program and 'manual' for the others. For a new book (no rows yet)
 * this gives: main 'primary', the rest 'manual'. Runs inside the caller's
 * transaction; throws on a failed statement so the caller rolls back.
 */
function manage_books_sync_book_programs(mysqli $conn, int $bookId, array $ids, int $mainId): void {
    if ($bookId <= 0) {
        return;
    }

    $existing = [];
    $sel = $conn->prepare('SELECT program_id, source FROM book_programs WHERE book_id = ?');
    if (!$sel) {
        throw new RuntimeException('Unable to read the book\'s programs.');
    }
    $sel->bind_param('i', $bookId);
    $sel->execute();
    $res = $sel->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $existing[(int)$row['program_id']] = (string)$row['source'];
    }
    $sel->close();

    $keep = array_flip($ids);

    $del = $conn->prepare('DELETE FROM book_programs WHERE book_id = ? AND program_id = ?');
    $upd = $conn->prepare('UPDATE book_programs SET source = ? WHERE book_id = ? AND program_id = ?');
    $ins = $conn->prepare('INSERT INTO book_programs (book_id, program_id, source) VALUES (?, ?, ?)');
    if (!$del || !$upd || !$ins) {
        throw new RuntimeException('Unable to save the book\'s programs.');
    }

    foreach ($existing as $programId => $source) {
        if (!isset($keep[$programId])) {
            $del->bind_param('ii', $bookId, $programId);
            if (!$del->execute()) {
                throw new RuntimeException('Unable to save the book\'s programs.');
            }
        }
    }

    foreach ($ids as $programId) {
        $programId = (int)$programId;
        $wanted = null;
        if (isset($existing[$programId])) {
            $current = $existing[$programId];
            if ($programId === $mainId && $current !== 'primary') {
                $wanted = 'primary';
            } elseif ($programId !== $mainId && $current === 'primary') {
                $wanted = 'manual';
            }
            if ($wanted !== null) {
                $upd->bind_param('sii', $wanted, $bookId, $programId);
                if (!$upd->execute()) {
                    throw new RuntimeException('Unable to save the book\'s programs.');
                }
            }
        } else {
            $source = $programId === $mainId ? 'primary' : 'manual';
            $ins->bind_param('iis', $bookId, $programId, $source);
            if (!$ins->execute()) {
                throw new RuntimeException('Unable to save the book\'s programs.');
            }
        }
    }

    $del->close();
    $upd->close();
    $ins->close();
}

/**
 * book_programs rows for many books in one query:
 * [book_id => [program_id => source, ...], ...].
 */
function manage_books_fetch_book_programs(mysqli $conn, array $bookIds): array {
    $ids = [];
    foreach ($bookIds as $bookId) {
        $bookId = (int)$bookId;
        if ($bookId > 0) {
            $ids[$bookId] = $bookId;
        }
    }
    if (empty($ids)) {
        return [];
    }

    $map = [];
    $res = $conn->query(
        'SELECT book_id, program_id, source FROM book_programs WHERE book_id IN (' . implode(',', $ids) . ') ORDER BY book_id ASC, program_id ASC'
    );
    while ($res && ($row = $res->fetch_assoc())) {
        $map[(int)$row['book_id']][(int)$row['program_id']] = (string)$row['source'];
    }
    return $map;
}

/**
 * A book's ticked programs for the picker: the main program first, then
 * the other linked programs by name. $linked is program_id => source;
 * $nameById is program_id => name (programs that no longer exist drop out).
 */
function manage_books_ordered_program_ids(int $mainId, array $linked, array $nameById): array {
    $ids = [];
    if ($mainId > 0 && isset($nameById[$mainId])) {
        $ids[] = $mainId;
    }
    $others = [];
    foreach (array_keys($linked) as $programId) {
        $programId = (int)$programId;
        if ($programId !== $mainId && isset($nameById[$programId])) {
            $others[$programId] = (string)$nameById[$programId];
        }
    }
    natcasesort($others);
    foreach (array_keys($others) as $programId) {
        $ids[] = (int)$programId;
    }
    return $ids;
}

/**
 * What the closed picker field shows: the main program's name (or, with no
 * main, the first ticked name alphabetically) plus "+N" for the others;
 * "All programs" when everything is ticked and there is no main; nothing
 * (placeholder) when none is ticked. Same rules as manage_books_admin.js.
 *
 * @return array{text:string, more:int, empty:bool}
 */
function manage_books_program_field_summary(array $ids, int $mainId, array $nameById): array {
    $count = count($ids);
    if ($count === 0) {
        return ['text' => 'Select programs', 'more' => 0, 'empty' => true];
    }
    if ($mainId <= 0 && $count === count($nameById)) {
        return ['text' => 'All programs', 'more' => 0, 'empty' => false];
    }
    if ($mainId > 0 && isset($nameById[$mainId])) {
        return ['text' => (string)$nameById[$mainId], 'more' => $count - 1, 'empty' => false];
    }
    $names = [];
    foreach ($ids as $id) {
        if (isset($nameById[$id])) {
            $names[] = (string)$nameById[$id];
        }
    }
    natcasesort($names);
    return ['text' => (string)(reset($names) ?: ''), 'more' => $count - 1, 'empty' => false];
}

/**
 * The program picker used by the Add Book and Edit Book windows (one
 * shared markup; behaviour in assets/javascript/manage_books_admin.js,
 * look in admin.css / admin_palette_green.css, pp- classes).
 *
 * A button shows the choice; the panel under it holds a search box, an
 * "All programs" row and one real checkbox per program, named
 * program_ids[], so the form still posts without JavaScript (the panel is
 * then shown as a plain list). main_program_id carries the program ticked
 * first. The script turns the panel into a popover so the window's
 * scrolling never clips it.
 *
 * $args: id (unique prefix), context ('add' or 'edit'), label_id (the id of
 * the visible "Program" label), programs (program_id / program_name rows,
 * sorted by name), ids (ticked ids, main first), main (main program id).
 */
function manage_books_render_program_picker(array $args): string {
    $h = static function ($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    };

    $id = (string)($args['id'] ?? 'programPicker');
    $context = ($args['context'] ?? 'add') === 'edit' ? 'edit' : 'add';
    $labelId = (string)($args['label_id'] ?? '');
    $programs = is_array($args['programs'] ?? null) ? $args['programs'] : [];
    $ids = array_values(array_map('intval', is_array($args['ids'] ?? null) ? $args['ids'] : []));
    $main = (int)($args['main'] ?? 0);

    $nameById = [];
    foreach ($programs as $program) {
        $programId = (int)($program['program_id'] ?? 0);
        if ($programId > 0) {
            $nameById[$programId] = (string)($program['program_name'] ?? '');
        }
    }
    $ids = array_values(array_filter($ids, static function ($programId) use ($nameById) {
        return isset($nameById[$programId]);
    }));
    if (!in_array($main, $ids, true)) {
        $main = 0;
    }
    $ticked = array_flip($ids);
    $total = count($nameById);
    $summary = manage_books_program_field_summary($ids, $main, $nameById);
    $selectedCount = count($ids);
    $allState = $selectedCount === 0 ? 'none' : ($selectedCount === $total ? 'all' : 'some');

    $out = [];
    $out[] = '<div class="pp-picker pp-ctx-' . $context . '" id="' . $h($id) . '" data-pp-total="' . $total . '">';
    $out[] = '    <input type="hidden" name="main_program_id" class="pp-main-input" value="' . ($main > 0 ? $main : '') . '">';
    $out[] = '    <button type="button" class="pp-field' . ($summary['empty'] ? ' is-empty' : '') . '" id="' . $h($id) . '-field" aria-haspopup="dialog" aria-expanded="false" aria-controls="' . $h($id) . '-panel" aria-labelledby="' . $h($labelId) . ' ' . $h($id) . '-summary">';
    $out[] = '        <span class="pp-summary" id="' . $h($id) . '-summary"><span class="pp-summary-text">' . $h($summary['text']) . '</span><span class="pp-sr pp-summary-sr">' . ($summary['more'] > 0 ? ', plus ' . (int)$summary['more'] . ' more' : '') . '</span></span>';
    $out[] = '        <span class="pp-more-pill" aria-hidden="true"' . ($summary['more'] > 0 ? '' : ' hidden') . '>+' . (int)$summary['more'] . '</span>';
    $out[] = '        <span class="pp-chevron" aria-hidden="true"></span>';
    $out[] = '    </button>';
    $out[] = '    <div class="pp-panel" id="' . $h($id) . '-panel" role="dialog" aria-label="Programs">';
    $out[] = '        <div class="pp-search">';
    $out[] = '            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"></circle><path d="m16 16 4 4"></path></svg>';
    $out[] = '            <input type="text" class="pp-search-input" placeholder="Search programs" aria-label="Search programs" autocomplete="off" spellcheck="false" aria-controls="' . $h($id) . '-list">';
    $out[] = '        </div>';
    $out[] = '        <label class="pp-row pp-all-row">';
    $out[] = '            <input type="checkbox" class="pp-check pp-all-check"' . ($allState === 'all' ? ' checked' : '') . ' data-pp-state="' . $allState . '">';
    $out[] = '            <span class="pp-name">All programs</span>';
    $out[] = '            <span class="pp-count" aria-hidden="true">' . $total . '</span>';
    $out[] = '        </label>';
    $out[] = '        <div class="pp-sep" aria-hidden="true"></div>';
    $out[] = '        <div class="pp-list" id="' . $h($id) . '-list" role="group" aria-label="Programs">';
    foreach ($programs as $program) {
        $programId = (int)($program['program_id'] ?? 0);
        if ($programId <= 0) {
            continue;
        }
        $name = (string)($program['program_name'] ?? '');
        $isTicked = isset($ticked[$programId]);
        $out[] = '            <label class="pp-row pp-program-row' . ($isTicked ? ' is-checked' : '') . '" data-pp-name="' . $h(function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name)) . '">'
            . '<input type="checkbox" class="pp-check pp-program-check" name="program_ids[]" value="' . $programId . '"' . ($isTicked ? ' checked' : '') . '>'
            . '<span class="pp-name">' . $h($name) . '</span>'
            . '<span class="pp-main-pill"' . ($programId === $main ? '' : ' hidden') . '>Main</span>'
            . '</label>';
    }
    $out[] = '            <p class="pp-empty" hidden>No programs match.</p>';
    $out[] = '        </div>';
    $out[] = '        <div class="pp-foot">';
    $out[] = '            <div class="pp-foot-text">';
    $out[] = '                <strong class="pp-selected-count" aria-live="polite">' . $selectedCount . ' selected</strong>';
    $out[] = '                <span class="pp-help">The first one ticked is the main program</span>';
    $out[] = '            </div>';
    $out[] = '            <button type="button" class="pp-clear">Clear</button>';
    $out[] = '            <button type="button" class="pp-done">Done</button>';
    $out[] = '        </div>';
    $out[] = '    </div>';
    $out[] = '</div>';

    return implode("\n", $out) . "\n";
}
