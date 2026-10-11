<?php
// ── Paged lists with a server-side search ───────────────────────────────
// Long lists (students, enrolments) show LIST_PAGE_SIZE rows per page. The
// search box still filters the rows on screen as you type; pressing Enter
// searches every row on the server (?q=), so paging never hides a match.
//
// Usage:
//   $list = list_params();                       // ['q', 'page', 'limit', 'offset']
//   ... COUNT(*) with the same WHERE → $total
//   $list = list_clamp($list, $total);           // page within range
//   ... SELECT ... LIMIT ? OFFSET ?  with $list['limit'], $list['offset']
//   render_pagination($list, $total);            // page links, keeps other ?params

define('LIST_PAGE_SIZE', 50);

function list_params() {
    $q    = is_string($_GET['q'] ?? null) ? trim(mb_substr($_GET['q'], 0, 100)) : '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    return ['q' => $q, 'page' => $page, 'limit' => LIST_PAGE_SIZE, 'offset' => ($page - 1) * LIST_PAGE_SIZE];
}

// Pulls a page number past the end (e.g. after deletions) back to the last page.
function list_clamp(array $list, $total) {
    $pages = max(1, (int)ceil($total / $list['limit']));
    $list['page']   = min($list['page'], $pages);
    $list['offset'] = ($list['page'] - 1) * $list['limit'];
    return $list;
}

// A LIKE pattern matching $q anywhere, with % and _ in the search taken
// literally rather than as wildcards.
function like_pattern($q) {
    return '%' . addcslashes($q, '%_\\') . '%';
}

// "Showing 51–100 of 230" plus Previous / page numbers / Next. Links keep
// every other query parameter (subject_id, q, ...). Echoes nothing when
// everything fits on one page.
function render_pagination(array $list, $total) {
    $pages = max(1, (int)ceil($total / $list['limit']));
    if ($pages <= 1) {
        return;
    }
    $page = $list['page'];
    $url  = function ($p) {
        return '?' . h(http_build_query(['page' => $p] + array_diff_key($_GET, ['page' => 1])));
    };
    $from = $list['offset'] + 1;
    $to   = min($list['offset'] + $list['limit'], $total);

    // First, last, and two either side of the current page; gaps become "…".
    $show = array_unique(array_filter(
        [1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages],
        fn($p) => $p >= 1 && $p <= $pages
    ));
    sort($show);

    echo '<nav class="d-flex flex-wrap justify-content-between align-items-center gap-2 px-3 py-3" aria-label="Pages">';
    echo '<span class="text-muted small">Showing ' . $from . '–' . $to . ' of ' . (int)$total . '</span>';
    echo '<ul class="pagination pagination-sm mb-0 flex-wrap">';
    echo '<li class="page-item' . ($page <= 1 ? ' disabled' : '') . '"><a class="page-link" href="' . $url(max(1, $page - 1)) . '">Previous</a></li>';
    $prev = 0;
    foreach ($show as $p) {
        if ($p > $prev + 1) {
            echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
        echo '<li class="page-item' . ($p === $page ? ' active" aria-current="page' : '') . '"><a class="page-link" href="' . $url($p) . '">' . $p . '</a></li>';
        $prev = $p;
    }
    echo '<li class="page-item' . ($page >= $pages ? ' disabled' : '') . '"><a class="page-link" href="' . $url(min($pages, $page + 1)) . '">Next</a></li>';
    echo '</ul></nav>';
}
?>
