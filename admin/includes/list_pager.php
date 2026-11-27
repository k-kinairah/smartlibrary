<?php
/**
 * Shared list pager for the admin lists (Manage Books, Manage Users).
 *
 * The markup and classes (mb-pager*) are the ones Manage Books has used
 * since Prompt 32; the styling lives in admin.css and the palette files.
 * The page itself works out the page number, the filtered total and the
 * LIMIT; this file only turns them into the "Showing x-y of z" line and the
 * page links. $pageUrl(int $page) must return an already safe relative URL
 * (each page builds it with its own whitelist URL builder).
 */

/**
 * Page numbers to show: first, last and current +/- 2. A gap of exactly one
 * page shows that page instead of an ellipsis; 0 marks an ellipsis.
 *
 * @return int[]
 */
function smartlib_list_pager_items(int $page, int $totalPages): array {
    $numbers = [1, $totalPages];
    for ($n = $page - 2; $n <= $page + 2; $n++) {
        if ($n >= 1 && $n <= $totalPages) {
            $numbers[] = $n;
        }
    }
    $numbers = array_values(array_unique($numbers));
    sort($numbers);

    $items = [];
    $previous = 0;
    foreach ($numbers as $n) {
        if ($previous > 0 && $n - $previous === 2) {
            $items[] = $previous + 1;
        } elseif ($previous > 0 && $n - $previous > 2) {
            $items[] = 0;
        }
        $items[] = $n;
        $previous = $n;
    }

    return $items;
}

/**
 * Prints the pager (nothing when there is only one page). Call it as
 * "    <?php smartlib_list_pager_render(...); ?>" right after the table's
 * wrapper, inside the list's <section>.
 *
 * @param callable(int):string $pageUrl
 */
function smartlib_list_pager_render(int $page, int $totalPages, int $total, int $perPage, callable $pageUrl, string $noun): void {
    if ($totalPages <= 1) {
        return;
    }

    $firstShown = ($page - 1) * $perPage + 1;
    $lastShown = min($total, ($page - 1) * $perPage + $perPage);
    $link = static function (int $n) use ($pageUrl): string {
        return htmlspecialchars((string)$pageUrl($n));
    };

    echo '        ';
    ?>
        <nav class="mb-pager" aria-label="Pagination">
            <p class="mb-pager-summary">Showing <strong><?= number_format($firstShown) ?>&ndash;<?= number_format($lastShown) ?></strong> of <strong><?= number_format($total) ?></strong> <?= htmlspecialchars($noun) ?></p>
            <ul class="mb-pager-list">
                <li>
                    <?php if ($page > 1): ?>
                        <a class="filter-reset-btn mb-pager-btn mb-pager-step" href="<?= $link($page - 1) ?>" rel="prev">&lsaquo; Previous</a>
                    <?php else: ?>
                        <span class="filter-reset-btn mb-pager-btn mb-pager-step is-disabled" aria-disabled="true">&lsaquo; Previous</span>
                    <?php endif; ?>
                </li>
                <?php foreach (smartlib_list_pager_items($page, $totalPages) as $item): ?>
                    <?php if ($item === 0): ?>
                        <li class="mb-pager-gap" aria-hidden="true">&hellip;</li>
                    <?php elseif ($item === $page): ?>
                        <li><a class="btn-primary mb-pager-btn mb-pager-current" href="<?= $link($item) ?>" aria-current="page" aria-label="Page <?= $item ?>, current page"><?= number_format($item) ?></a></li>
                    <?php else: ?>
                        <li><a class="filter-reset-btn mb-pager-btn" href="<?= $link($item) ?>" aria-label="Page <?= $item ?>"><?= number_format($item) ?></a></li>
                    <?php endif; ?>
                <?php endforeach; ?>
                <li>
                    <?php if ($page < $totalPages): ?>
                        <a class="filter-reset-btn mb-pager-btn mb-pager-step" href="<?= $link($page + 1) ?>" rel="next">Next &rsaquo;</a>
                    <?php else: ?>
                        <span class="filter-reset-btn mb-pager-btn mb-pager-step is-disabled" aria-disabled="true">Next &rsaquo;</span>
                    <?php endif; ?>
                </li>
            </ul>
        </nav>
<?php
    echo '    ';
}
