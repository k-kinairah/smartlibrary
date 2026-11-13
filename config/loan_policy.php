<?php
// Loan period by book section (librarian rules, 2026-11).
//
//   Circulation, Filipiniana, Fiction  -> 7 days
//   SHS                                -> 5 days
//   Reference, Thesis                  -> room use only (nobody can borrow)
//   Faculty                            -> faculty days (30) on any borrowable book
//
// Borrow limits and renewals stay per role in smartlib_loan_policy_for_role().
//
// smartlib_resolve_book_section() is the ONE place a book's section is
// worked out. get_book.php (the book modal) uses the same row logic via
// smartlib_book_section_from_row(), so the modal and checkout/renew can
// never disagree about a book's section.

require_once __DIR__ . '/borrow_fine_rules.php';

if (!function_exists('smartlib_section_key')) {
    function smartlib_section_key(string $section): string {
        $key = strtolower(trim($section));
        $key = trim((string)preg_replace('/\s+collection$/', '', $key));

        if ($key === 'general') {
            return 'circulation';
        }

        $known = ['circulation', 'filipiniana', 'fiction', 'shs', 'reference', 'thesis'];
        return in_array($key, $known, true) ? $key : '';
    }
}

if (!function_exists('smartlib_loan_policy_for_book')) {
    function smartlib_loan_policy_for_book(string $role, string $section): array {
        $rolePolicy = smartlib_loan_policy_for_role($role);
        $sectionKey = smartlib_section_key($section);
        $isFaculty = strtolower(trim($role)) === 'faculty';

        $policy = [
            'section_key' => $sectionKey,
            'room_only' => false,
            'days' => null,
            'max_borrows' => (int)($rolePolicy['max_borrows'] ?? 3),
            'max_renewals' => (int)($rolePolicy['max_renewals'] ?? 1),
            'is_faculty_rate' => false,
            'label' => ''
        ];

        if ($sectionKey === 'reference' || $sectionKey === 'thesis') {
            $policy['room_only'] = true;
            $policy['label'] = 'Room use only';
            return $policy;
        }

        $days = $sectionKey === 'shs' ? 5 : 7;

        if ($isFaculty) {
            $days = (int)(smartlib_loan_policy_for_role('faculty')['days'] ?? 30);
            $policy['is_faculty_rate'] = true;
            $policy['days'] = $days;
            $policy['label'] = "Up to {$days} days";
            return $policy;
        }

        $policy['days'] = $days;
        $policy['label'] = "{$days} days";
        return $policy;
    }
}

if (!function_exists('smartlib_legacy_location_section')) {
    // Section from the legacy free-text books.location column, e.g.
    // "Section: Reference Collection | Class: R | Shelf: ...". Moved here
    // unchanged from get_book.php's parse_location_meta().
    function smartlib_legacy_location_section(string $rawLocation): string {
        $section = 'General Collection';
        $raw = trim($rawLocation);
        if ($raw === '') {
            return $section;
        }

        foreach (explode('|', $raw) as $partRaw) {
            $part = trim((string)$partRaw);
            if ($part !== '' && stripos($part, 'Section:') === 0) {
                $section = trim(substr($part, strlen('Section:')));
            }
        }

        if ($section === 'General Collection') {
            if (stripos($raw, 'reference') !== false) $section = 'Reference Collection';
            if (stripos($raw, 'filipiniana') !== false) $section = 'Filipiniana Collection';
            if (stripos($raw, 'periodical') !== false) $section = 'Periodicals Collection';
        }

        return $section;
    }
}

if (!function_exists('smartlib_book_section_from_row')) {
    // $row may carry loc_section / loc_class_code / loc_shelf_code (joined
    // from library_locations) and location (legacy text). A book counts as
    // "structured" when any of the three loc_* values is non-blank; then its
    // library_locations.section wins, else the legacy text decides.
    function smartlib_book_section_from_row(array $row): string {
        $value = static function (string $key) use ($row): string {
            return (array_key_exists($key, $row) && $row[$key] !== null) ? (string)$row[$key] : '';
        };

        $legacySection = smartlib_legacy_location_section($value('location'));

        $hasStructuredLocation =
            trim($value('loc_section')) !== '' ||
            trim($value('loc_class_code')) !== '' ||
            trim($value('loc_shelf_code')) !== '';

        if ($hasStructuredLocation && $value('loc_section') !== '') {
            return $value('loc_section');
        }

        return $legacySection;
    }
}

if (!function_exists('smartlib_resolve_book_section')) {
    // Returns '' when the book does not exist.
    function smartlib_resolve_book_section(mysqli $conn, int $bookId): string {
        if ($bookId <= 0) {
            return '';
        }

        $queries = [
            'SELECT b.location, loc.section AS loc_section, loc.class_code AS loc_class_code, loc.shelf_code AS loc_shelf_code
             FROM books b
             LEFT JOIN library_locations loc ON b.location_id = loc.location_id
             WHERE b.book_id = ? LIMIT 1',
            // Older schema without library_locations / books.location_id.
            'SELECT b.location FROM books b WHERE b.book_id = ? LIMIT 1'
        ];

        foreach ($queries as $sql) {
            try {
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    continue;
                }
                $stmt->bind_param('i', $bookId);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                $stmt->close();
            } catch (Throwable $e) {
                continue;
            }

            return $row ? smartlib_book_section_from_row($row) : '';
        }

        return '';
    }
}
