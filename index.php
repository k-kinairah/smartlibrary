<?php
session_start();
require 'config/db_connect.php';
require 'config/csrf.php';
require_once 'config/session_guard.php';

// Signs out an account that was deactivated or deleted since login, so the
// header below shows the signed-out state; refreshes name/role otherwise.
smartlib_active_session_user_id($conn);

// Shared by login_handler.php and verify_2fa.php (sent by filters.js).
$signinCsrfToken = smartlib_csrf_token('signin_csrf');

$kioskCssVer = @filemtime(__DIR__ . '/assets/css/kiosk.css') ?: time();
$filtersJsVer = @filemtime(__DIR__ . '/assets/javascript/filters.js') ?: time();
$yearSelectJsVer = @filemtime(__DIR__ . '/assets/javascript/year-select-ui.js') ?: time();
$kioskIdleJsVer = @filemtime(__DIR__ . '/assets/javascript/kiosk-idle.js') ?: time();

$genreChips = [];
// 2026-10-10: was a live information_schema lookup on every page load.
// Root cause of the "MySQL server has gone away" crash on this line: this
// MariaDB build crashes on SHOW COLUMNS *and* information_schema queries
// alike while running in InnoDB emergency recovery mode
// (innodb_force_recovery=6) -- both paths touch the same damaged data
// dictionary. Verified against smartlib_FULL_BACKUP_20261010.sql that
// categories.category_group already exists, so there's nothing left to
// detect -- hardcoded to skip the query entirely. Revert to a live check
// once the database has been rebuilt into a fresh data directory.
$hasCategoryGroup = true;

$genreExpr = $hasCategoryGroup
    ? "TRIM(COALESCE(NULLIF(c.category_group, ''), c.category_name))"
    : "TRIM(c.category_name)";

$genreRes = $conn->query(
    "SELECT {$genreExpr} AS genre_label
     FROM categories c
     INNER JOIN books b ON b.category_id = c.category_id
     WHERE {$genreExpr} <> ''
     GROUP BY genre_label
     ORDER BY genre_label ASC"
);

if ($genreRes) {
    while ($g = $genreRes->fetch_assoc()) {
        $name = trim((string)($g['genre_label'] ?? ''));
        if ($name !== '') {
            $genreChips[] = $name;
        }
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartLib</title>
    <link rel="icon" href="assets/images/stjude_seal_64.png" type="image/png">
    <link rel="stylesheet" href="assets/css/kiosk.css?v=<?= $kioskCssVer ?>">
</head>
<body>

<div id="discover-view">
    <div class="discover-section" id="discover-section">
        <div class="discover-hero">
            <div class="discover-pill">
                <span class="discover-pill-glow" aria-hidden="true"></span>
                <span class="discover-pill-emboss" aria-hidden="true"></span>
                <span class="discover-pill-sheen" aria-hidden="true"></span>
                <span class="discover-pill-gem" aria-hidden="true"><span class="discover-pill-spark"></span></span>
                <span class="discover-pill-text">AI-Powered Recommendations</span>
            </div>
            <h1>Discover Your Next Read</h1>
            <p>Personalized book suggestions curated just for you</p>
            <div class="discover-hero-divider" aria-hidden="true"></div>
            <div class="discover-search-wrap">
                <input id="discover-search-input" type="text" placeholder="Search by title, author, subject, or book number...">
            </div>
        </div>

        <?php if (isset($_SESSION['user_id'])): ?>
        <section class="discover-panel" data-panel-key="course_highlights">
            <span class="panel-kicker">Picked for your course</span>
            <h2>Course-Specific Highlights</h2>
            <p>AI picks matched to your course and borrowing activity</p>
            <div class="discover-carousel">
                <button class="carousel-arrow">&#8249;</button>
                <div class="carousel-books">
                    <img class="side discover-book" data-cover="tech2.jpg" src="assets/covers/tech2.jpg" alt="Book cover">
                    <img class="side discover-book" data-cover="adventure1.jpg" src="assets/covers/adventure1.jpg" alt="Book cover">
                    <img class="center discover-book" data-cover="atkinson.png" src="assets/covers/atkinson.png" alt="Book cover">
                    <img class="side discover-book" data-cover="adventure2.jpg" src="assets/covers/adventure2.jpg" alt="Book cover">
                    <img class="side discover-book" data-cover="databasedesign.jpg" src="assets/covers/databasedesign.jpg" alt="Book cover">
                </div>
                <button class="carousel-arrow">&#8250;</button>
            </div>
            <div class="carousel-divider" aria-hidden="true"><span class="seg-left"></span><span class="gem"></span><span class="seg-right"></span></div>
            <h3 class="carousel-title">Calculus: Early Transcendentals</h3>
            <p class="carousel-author">James Stewart</p>
            <p class="carousel-reason reason--availability" data-reason-type="availability">Available now</p>
        </section>

        <?php endif; ?>

        <section class="discover-panel" data-panel-key="new_arrivals">
            <span class="panel-kicker">Newly added</span>
            <h2>New Arrivals</h2>
            <p>Latest books and journals added to our collection</p>
            <div class="discover-carousel">
                <button class="carousel-arrow">&#8249;</button>
                <div class="carousel-books">
                    <img class="side discover-book" data-cover="adventure2.jpg" src="assets/covers/adventure2.jpg" alt="Book cover">
                    <img class="side discover-book" data-cover="adventure1.jpg" src="assets/covers/adventure1.jpg" alt="Book cover">
                    <img class="center discover-book" data-cover="atkinson.png" src="assets/covers/atkinson.png" alt="Book cover">
                    <img class="side discover-book" data-cover="databasedesign.jpg" src="assets/covers/databasedesign.jpg" alt="Book cover">
                    <img class="side discover-book" data-cover="tech2.jpg" src="assets/covers/tech2.jpg" alt="Book cover">
                </div>
                <button class="carousel-arrow">&#8250;</button>
            </div>
            <div class="carousel-divider" aria-hidden="true"><span class="seg-left"></span><span class="gem"></span><span class="seg-right"></span></div>
            <h3 class="carousel-title">Calculus: Early Transcendentals</h3>
            <p class="carousel-author">James Stewart</p>
            <p class="carousel-reason reason--availability" data-reason-type="availability">Available now</p>
        </section>
        <section class="discover-panel" data-panel-key="most_searched">
            <span class="panel-kicker">Trending now</span>
            <h2>Most Searched</h2>
            <p>Topics and titles students are actively looking for</p>
            <div class="discover-carousel">
                <button class="carousel-arrow">&#8249;</button>
                <div class="carousel-books">
                    <img class="side discover-book" data-cover="anatomy.jpg" src="assets/covers/anatomy.jpg" alt="Book cover">
                    <img class="side discover-book" data-cover="drug2023.png" src="assets/covers/drug2023.png" alt="Book cover">
                    <img class="center discover-book" data-cover="tech2.jpg" src="assets/covers/tech2.jpg" alt="Book cover">
                    <img class="side discover-book" data-cover="atkinson.png" src="assets/covers/atkinson.png" alt="Book cover">
                    <img class="side discover-book" data-cover="databasedesign.jpg" src="assets/covers/databasedesign.jpg" alt="Book cover">
                </div>
                <button class="carousel-arrow">&#8250;</button>
            </div>
            <div class="carousel-divider" aria-hidden="true"><span class="seg-left"></span><span class="gem"></span><span class="seg-right"></span></div>
            <h3 class="carousel-title">Introduction to Algorithms</h3>
            <p class="carousel-author">Thomas H. Cormen</p>
            <p class="carousel-reason reason--availability" data-reason-type="availability">Available now</p>
        </section>
<section class="discover-panel" data-panel-key="most_borrowed">
            <span class="panel-kicker">Reader favorites</span>
            <h2>Most Borrowed Books</h2>
            <p>Popular titles frequently checked out by students</p>
            <div class="discover-carousel">
                <button class="carousel-arrow">&#8249;</button>
                <div class="carousel-books">
                    <img class="side discover-book" data-cover="atkinson.png" src="assets/covers/atkinson.png" alt="Book cover">
                    <img class="side discover-book" data-cover="databasedesign.jpg" src="assets/covers/databasedesign.jpg" alt="Book cover">
                    <img class="center discover-book" data-cover="tech2.jpg" src="assets/covers/tech2.jpg" alt="Book cover">
                    <img class="side discover-book" data-cover="anatomy.jpg" src="assets/covers/anatomy.jpg" alt="Book cover">
                    <img class="side discover-book" data-cover="drug2023.png" src="assets/covers/drug2023.png" alt="Book cover">
                </div>
                <button class="carousel-arrow">&#8250;</button>
            </div>
            <div class="carousel-divider" aria-hidden="true"><span class="seg-left"></span><span class="gem"></span><span class="seg-right"></span></div>
            <h3 class="carousel-title">Introduction to Algorithms</h3>
            <p class="carousel-author">Thomas H. Cormen</p>
            <p class="carousel-reason reason--availability" data-reason-type="availability">Available now</p>
        </section>
        <?php if (!isset($_SESSION['user_id'])): ?>
        <section class="recommend-cta recommend-cta--split" aria-label="Sign in or get your library card">
            <div class="cta-path cta-path--signin">
                <div class="sparkle-icon" aria-hidden="true">✨</div>
                <p class="cta-path-kicker">Already have an account?</p>
                <h3>Unlock Personalized Recommendations</h3>
                <p>Sign in to see recommendations tailored to your course and interests</p>
                <button id="discover-signin" class="discover-signin-btn">Sign In Now</button>
            </div>
            <div class="cta-path-or" aria-hidden="true"><span>or</span></div>
            <div class="cta-path cta-path--card">
                <div class="sparkle-icon" aria-hidden="true">🪪</div>
                <p class="cta-path-kicker">New here?</p>
                <h3>Get Your Library Card</h3>
                <p>Sign up with your PHINMA email, then print your card to enter the library</p>
                <a class="discover-signin-btn" href="library_card_signup.php">Get My Card</a>
            </div>
        </section>
        <?php else: ?>
        <section class="recommend-cta library-card-cta">
            <div class="sparkle-icon" aria-hidden="true">🪪</div>
            <h3>Get Your Library Card</h3>
            <p>Sign up with your PHINMA email, then print your card to enter the library</p>
            <a class="discover-signin-btn" href="library_card_signup.php">Get My Card</a>
        </section>
        <?php endif; ?>
    </div>

    <footer class="discover-footer">
        <div class="discover-footer-brand-pill">
            <img class="brand-mark-primary" src="assets/images/stjude_seal_64.png" alt="St. Jude College" width="40" height="40">
            <span class="discover-footer-brand-pill-divider" aria-hidden="true"></span>
            <img class="brand-mark-secondary" src="assets/images/phinma-icon.png" alt="PHINMA Education" width="26" height="26">
        </div>
        <p class="discover-footer-brand"><span>SmartLib&nbsp;·</span> <span>PHINMA-SJC Dasma Library</span></p>
        <p>Carlos Trinidad Avenue, Salitran IV, City of Dasmarinas, Cavite, Philippines&nbsp;<span class="discover-footer-sep" aria-hidden="true">·</span> <span>sjcdc.phinma.edu.ph</span>&nbsp;<span class="discover-footer-sep" aria-hidden="true">·</span> <span>+63917 630 1064</span>&nbsp;<span class="discover-footer-sep" aria-hidden="true">·</span> <span>(046) 41-SJCDC</span> or&nbsp;<span>(046) 417-5232</span></p>
    </footer>
</div>

<div id="search-view" class="hidden">
    <header class="header search-header">
        <div class="logo-area">
            <img src="assets/images/stjude_logo.jpg" class="logo" alt="School logo">
            <div class="title">
                <h2>SmartLib</h2>
                <p>Self-Service Library System</p>
            </div>
        </div>

        <?php if (isset($_SESSION['user_id'])): ?>
            <div class="user-header-right">
                <button type="button" id="user-menu-toggle" class="user-menu-toggle" aria-expanded="false" aria-controls="user-menu-dropdown">
                    <span class="user-profile-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" role="presentation">
                            <circle cx="12" cy="8" r="3.2"></circle>
                            <path d="M6.2 18.2c.9-2.7 3-4.2 5.8-4.2s4.9 1.5 5.8 4.2"></path>
                        </svg>
                    </span>
                    <span class="user-info">
                        <strong><?php echo htmlspecialchars((string)($_SESSION['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                        <span>ID: <?php echo htmlspecialchars((string)($_SESSION['user_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                    </span>
                    <span class="user-menu-caret" aria-hidden="true">
                        <svg viewBox="0 0 24 24" role="presentation">
                            <path d="M7 10l5 5 5-5"></path>
                        </svg>
                    </span>
                </button>

                <div id="user-menu-dropdown" class="user-menu-dropdown hidden">
                    <div class="user-menu-profile">
                        <span class="user-menu-avatar" aria-hidden="true">
                            <svg viewBox="0 0 24 24" role="presentation">
                                <path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"></path>
                                <path d="M4.5 20a7.5 7.5 0 0 1 15 0"></path>
                            </svg>
                        </span>
                        <div class="user-menu-profile-info">
                            <p class="user-menu-profile-name">
                                <?php echo htmlspecialchars((string)($_SESSION['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                            <p class="user-menu-profile-id">ID: <?php echo htmlspecialchars((string)($_SESSION['user_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                        </div>
                    </div>

                    <div class="user-menu-divider" role="separator" aria-hidden="true"></div>

                    <button type="button" id="open-my-books" class="user-menu-item">
                        <span class="menu-item-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" role="presentation">
                                <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path>
                                <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path>
                            </svg>
                        </span>
                        <span>My Books</span>
                    </button>
                    <button type="button" id="open-my-favorites" class="user-menu-item">
                        <span class="menu-item-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" role="presentation">
                                <path d="M12 20.2s-7.2-4.6-10-9.2C0.3 8 1.4 4.6 4.6 3.5c2.1-0.7 4.3 0 5.6 1.7L12 7l1.8-1.8c1.3-1.7 3.5-2.4 5.6-1.7 3.2 1.1 4.3 4.5 2.6 7.5-2.8 4.6-10 9.2-10 9.2z"></path>
                            </svg>
                        </span>
                        <span>My Favorites</span>
                    </button>
                    <?php if (in_array(strtolower(trim((string)($_SESSION['role'] ?? ''))), ['librarian', 'admin'], true)): ?>
                        <?php // Mirrors the admin panel's "Go back to kiosk" link: a plain link on the same session; admin/layout_top.php re-checks the role. ?>
                        <a href="admin/dashboard.php" class="user-menu-item user-menu-item-admin">
                            <span class="menu-item-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" role="presentation">
                                    <rect x="4" y="4" width="6" height="6" rx="1.2"></rect>
                                    <rect x="14" y="4" width="6" height="6" rx="1.2"></rect>
                                    <rect x="4" y="14" width="6" height="6" rx="1.2"></rect>
                                    <rect x="14" y="14" width="6" height="6" rx="1.2"></rect>
                                </svg>
                            </span>
                            <span>Admin Panel</span>
                        </a>
                    <?php endif; ?>

                    <div class="user-menu-divider" role="separator" aria-hidden="true"></div>

                    <form action="logout.php" method="POST" class="user-menu-form" id="account-logout-form">
                        <button class="user-menu-item danger" type="submit">
                            <span class="menu-item-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" role="presentation">
                                    <path d="M10 4H6.5A2.5 2.5 0 0 0 4 6.5v11A2.5 2.5 0 0 0 6.5 20H10"></path>
                                    <path d="M13 8l5 4-5 4"></path>
                                    <path d="M18 12H9"></path>
                                </svg>
                            </span>
                            <span>Log Out</span>
                        </button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <button id="open-account-modal" class="btn-login">
                <span class="btn-login-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" role="presentation">
                        <path d="M14 4H17.5A2.5 2.5 0 0 1 20 6.5v11A2.5 2.5 0 0 1 17.5 20H14"></path>
                        <path d="M3 12H15"></path>
                        <path d="M10 8l5 4-5 4"></path>
                    </svg>
                </span>
                <span>Sign In</span>
            </button>
        <?php endif; ?>
    </header>

    <div class="search-layout">
        <div class="search-hero">
            <!-- Kiosk Polish v195 (Search Hero Design Option 1: Grouped Brand Pill) --
                 trial markup, see assets/css/kiosk.css for the matching CSS block and
                 full rationale. Supersedes the v194 (Option 2: Compact Banner Card)
                 trial markup that was here before; its unused CSS has since been removed. -->
            <div class="search-hero-brand search-hero-brand--pill">
                <img src="assets/images/stjude_seal_64.png" alt="St. Jude College" class="search-hero-brand-icon search-hero-brand-icon--primary">
                <div class="search-hero-brand-pill-divider" aria-hidden="true"></div>
                <img src="assets/images/phinma-icon.png" alt="PHINMA Education" class="search-hero-brand-icon search-hero-brand-icon--secondary">
            </div>
            <h2>Find What You're Looking For</h2>
            <p>Search our library by title, author, subject, or program</p>
            <div class="search-hero-divider search-hero-divider--dots" aria-hidden="true"></div>
        </div>
        <div class="filters-wrapper">
            <div class="search-filters-top">
                <div class="top-search-card">
                    <span class="advanced-search-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" role="presentation">
                            <circle cx="11" cy="11" r="7"></circle>
                            <path d="M20 20l-3.4-3.4"></path>
                        </svg>
                    </span>
                    <input type="text" id="advanced-search-input" autocomplete="off" placeholder="Search by title, author, subject, or book number...">
                </div>
            </div>

                        <div class="filter-card filter-core">
                <div class="filter-top-tabs">
                    <button class="filter-top-tab active" data-type="genre">
                        <span class="filter-top-tab-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" role="presentation">
                                <path d="M12.4 3.5h5.1A2 2 0 0 1 19.5 5.6v5.1a2 2 0 0 1-.6 1.4l-8 8a2 2 0 0 1-2.8 0l-4.6-4.6a2 2 0 0 1 0-2.8l8-8a2 2 0 0 1 1.4-.6z"></path>
                                <circle cx="16" cy="8" r="1.1"></circle>
                            </svg>
                        </span>
                        <span>Genre</span>
                    </button>
                    <button class="filter-top-tab" data-type="year_published">
                        <span class="filter-top-tab-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" role="presentation">
                                <rect x="3.5" y="5.5" width="17" height="15" rx="2"></rect>
                                <path d="M8 3.8v3.4M16 3.8v3.4M3.5 9.2h17"></path>
                            </svg>
                        </span>
                        <span>Year</span>
                    </button>
                </div>

                <div class="filter-box-p" id="genre-box">
                    <label class="filter-label">Filter by subject/genre:</label>
                    <div class="chip-carousel">
                        <button type="button" class="chip-scroll-btn prev" aria-label="Scroll subjects left" disabled>&lsaquo;</button>
                        <div class="chip-container">
                            <?php if (!empty($genreChips)): ?>
                                <?php foreach ($genreChips as $genreName): ?>
                                    <div class="chip" data-filter="genre" data-value="<?= htmlspecialchars($genreName) ?>"><?= htmlspecialchars($genreName) ?></div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="chip" aria-disabled="true">No genres available</div>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="chip-scroll-btn next" aria-label="Scroll subjects right">&rsaquo;</button>
                    </div>
                </div>
                <div class="filter-box-p hidden" id="year_published-box">
                    <div class="year-range-wrap">
                        <div class="year-range-field">
                            <label class="year-range-label" for="year-from-select">From Year:</label>
                            <select id="year-from-select" class="year-range-select">
                                <option value="">Any</option>
                                <option value="1800">1800</option>
                                <option value="1900">1900</option>
                                <option value="1950">1950</option>
                                <option value="1980">1980</option>
                                <option value="2000">2000</option>
                                <option value="2010">2010</option>
                                <option value="2020">2020</option>
                            </select>
                        </div>
                        <div class="year-range-field">
                            <label class="year-range-label" for="year-to-select">To Year:</label>
                            <select id="year-to-select" class="year-range-select">
                                <option value="">Any</option>
                                <option value="1900">1900</option>
                                <option value="1950">1950</option>
                                <option value="1980">1980</option>
                                <option value="2000">2000</option>
                                <option value="2010">2010</option>
                                <option value="2020">2020</option>
                                <option value="2026">2026</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="active-filter-row">
                    <span>Active filters:</span>
                    <div id="active-filters"></div>
                    <button id="clear-active" class="clear-all">Clear</button>
                </div>
            </div>
        </div>

        <div class="results-head">
            <h3 class="section-title" id="search-title">Search Results</h3>
            <span id="results-count">Found 0 books</span>
        </div>
        <div class="book-grid" id="book-grid"></div>
    </div>
</div>

<div id="account-modal" class="modal hidden">
    <div class="modal-backdrop"></div>
    <div class="modal-panel account-modal-panel">
        <button class="close-btn" id="close-modal">&times;</button>
        <div class="account-brand-mark">
            <img class="account-brand-seal" src="assets/images/stjude_seal_64.png" alt="St. Jude College" width="56" height="56">
            <span class="account-brand-divider" aria-hidden="true"></span>
            <img class="account-brand-phinma" src="assets/images/phinma-dark-theme.png" alt="PHINMA Education">
        </div>
        <h3 class="modal-title">Welcome to SJCDC Library</h3>
        <p class="modal-subtitle">Sign in to borrow books and access personalized recommendations</p>

        <form id="signin-form" class="modal-form account-form">
            <label>Account Type</label>
            <div class="account-type-picker">
                <button type="button" class="account-type-select" id="signin-account-type-toggle" aria-expanded="false">
                    <span class="account-type-leading-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" role="presentation">
                            <path d="M8 8V7a4 4 0 0 1 8 0v1"></path>
                            <rect x="4" y="8" width="16" height="11" rx="3"></rect>
                            <path d="M4 13h16"></path>
                            <path d="M12 11v4"></path>
                        </svg>
                    </span>
                    <div class="account-type-text">
                        <strong id="signin-account-type-label">Student</strong>
                        <div class="muted-sub" id="signin-account-type-sub">3 books - 7 days</div>
                    </div>
                    <span class="chev-circle" aria-hidden="true">
                        <svg class="chev" viewBox="0 0 24 24" role="presentation">
                            <path d="M7 10l5 5 5-5"></path>
                        </svg>
                    </span>
                </button>

                <div id="signin-account-type-menu" class="account-type-menu hidden">
                    <button type="button" class="account-type-option active" data-type="student" data-label="Student" data-sub="3 books - 7 days" data-id-label="Student ID" data-id-placeholder="20232243" data-secret-label="PIN" data-secret-placeholder="2026">
                        <span class="option-title">Student</span>
                        <span class="option-sub">Borrow up to 3 books for 7 days</span>
                    </button>
                    <button type="button" class="account-type-option" data-type="faculty" data-label="Faculty" data-sub="5 books - 30 days" data-id-label="Faculty ID" data-id-placeholder="Your faculty ID" data-secret-label="PIN" data-secret-placeholder="2026">
                        <span class="option-title">Faculty</span>
                        <span class="option-sub">Borrow up to 5 books for 30 days</span>
                    </button>
                    <button type="button" class="account-type-option" data-type="librarian" data-label="Librarian" data-sub="5 books - 7 days" data-id-label="Librarian ID" data-id-placeholder="Your librarian ID" data-secret-label="Password" data-secret-placeholder="Enter your password">
                        <span class="option-title">Librarian</span>
                        <span class="option-sub">Staff access - up to 5 books for 7 days</span>
                    </button>
                </div>
            </div>
            <input type="hidden" id="signin-account-type" value="student">

            <div class="floating-field">
                <span class="floating-field-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" role="presentation">
                        <circle cx="12" cy="8" r="3.4"></circle>
                        <path d="M5 19c0-3.2 2.8-5.2 7-5.2s7 2 7 5.2"></path>
                    </svg>
                </span>
                <label id="signin-identifier-label" class="floating-label">Student ID</label>
                <input type="text" id="signin-identifier" placeholder="20232243">
            </div>

            <div class="floating-field">
                <span class="floating-field-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" role="presentation">
                        <rect x="5" y="10.5" width="14" height="9" rx="2"></rect>
                        <path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"></path>
                    </svg>
                </span>
                <label class="floating-label" id="signin-password-label">PIN</label>
                <div class="password-field-wrap">
                    <input type="password" id="signin-password" placeholder="2026">
                    <button
                        type="button"
                        id="signin-password-toggle"
                        class="password-toggle-btn"
                        aria-controls="signin-password"
                        aria-label="Show PIN"
                        aria-pressed="false">
                        <svg class="icon-eye" viewBox="0 0 24 24" role="presentation" aria-hidden="true">
                            <path d="M1.5 12s3.6-6.5 10.5-6.5S22.5 12 22.5 12s-3.6 6.5-10.5 6.5S1.5 12 1.5 12z"></path>
                            <circle cx="12" cy="12" r="3.2"></circle>
                        </svg>
                        <svg class="icon-eye-off" viewBox="0 0 24 24" role="presentation" aria-hidden="true">
                            <path d="M1.5 12s3.6-6.5 10.5-6.5c2.1 0 3.9.6 5.4 1.5"></path>
                            <path d="M22.5 12s-3.6 6.5-10.5 6.5c-2.1 0-3.9-.6-5.4-1.5"></path>
                            <circle cx="12" cy="12" r="3.2"></circle>
                            <path d="M3 21L21 3"></path>
                        </svg>
                    </button>
                </div>
            </div>

            <div id="signin-2fa-wrap" class="floating-field hidden">
                <span class="floating-field-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" role="presentation">
                        <path d="M12 3.5l6.5 2.6v5.4c0 4.4-2.8 7.3-6.5 8.6-3.7-1.3-6.5-4.2-6.5-8.6V6.1z"></path>
                    </svg>
                </span>
                <label class="floating-label">Verification Code</label>
                <input type="text" id="signin-otp" inputmode="numeric" maxlength="6" placeholder="123456">
            </div>

            <p id="signin-helper" class="modal-helper"> </p>
            <button type="button" id="signin-btn" class="primary-btn">Sign In</button>

            <div class="signin-links-row">
                <button type="button" id="signin-forgot-pin" class="signin-text-btn">Forgot your <span id="signin-forgot-label">PIN</span>?</button>
            </div>

            <div id="signin-msg" class="form-msg"></div>
        </form>
    </div>
</div>

<div id="book-modal" class="modal hidden">
    <div class="modal-backdrop"></div>
    <div class="modal-panel" style="max-width:650px;">
        <button class="close-btn" id="close-book-modal">&times;</button>
        <div id="book-modal-content"></div>
    </div>
</div>

<div id="location-modal" class="modal hidden">
    <div class="modal-backdrop"></div>
    <div class="modal-panel location-modal-panel">
        <button class="close-btn" id="close-location-modal">&times;</button>
        <div id="location-modal-content"></div>
    </div>
</div>

<div id="receipt-modal" class="modal hidden">
    <div class="modal-backdrop"></div>
    <div class="modal-panel receipt-modal-panel">
        <button class="close-btn" id="close-receipt-modal">&times;</button>

        <div class="receipt-success-head">
            <span class="receipt-check-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" role="presentation">
                    <path d="M5 12.5l4.5 4.5L19 7"></path>
                </svg>
            </span>
            <div>
                <h3>Book Checked Out Successfully!</h3>
                <p>Your borrowing receipt</p>
            </div>
        </div>

        <div id="receipt-content" class="receipt-paper"></div>

        <div class="receipt-actions-row">
            <button type="button" id="receipt-print-btn" class="receipt-secondary-btn">
                <span class="receipt-btn-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" role="presentation">
                        <path d="M7 8.5V4h10v4.5"></path>
                        <rect x="4.5" y="8.5" width="15" height="7" rx="2"></rect>
                        <path d="M7 14.5h10V20H7z"></path>
                    </svg>
                </span>
                Print Receipt
            </button>
            <button type="button" id="receipt-download-btn" class="receipt-secondary-btn">
                <span class="receipt-btn-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" role="presentation">
                        <path d="M12 4v11"></path>
                        <path d="M7.5 11L12 15.5 16.5 11"></path>
                        <path d="M4.5 18.5h15"></path>
                    </svg>
                </span>
                Download
            </button>
        </div>

        <button type="button" id="receipt-done-btn" class="primary-btn receipt-done-btn">
            <span class="receipt-btn-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" role="presentation">
                    <path d="M5 12.5l4.5 4.5L19 7"></path>
                </svg>
            </span>
            Done
        </button>
    </div>
</div>

<div id="my-books-modal" class="modal hidden">
    <div class="modal-backdrop"></div>
    <div class="modal-panel my-books-modal-panel">
        <button class="close-btn" id="close-my-books-modal">&times;</button>
        <div class="my-books-heading">
            <span id="my-books-heading-icon" class="my-books-heading-icon books" aria-hidden="true">
                <svg viewBox="0 0 24 24" role="presentation">
                    <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path>
                    <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path>
                </svg>
            </span>
            <div class="my-books-heading-text">
                <h3 class="my-books-title">My Books</h3>
                <p class="my-books-subtitle">Everything you currently have borrowed</p>
            </div>
        </div>
        <div id="my-books-content" class="my-books-content"></div>
    </div>
</div>

<div id="logout-confirm-modal" class="modal hidden logout-confirm-modal">
    <div class="modal-backdrop"></div>
    <div class="modal-panel logout-confirm-panel">
        <span class="logout-confirm-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" role="presentation">
                <path d="M10 4H6.5A2.5 2.5 0 0 0 4 6.5v11A2.5 2.5 0 0 0 6.5 20H10"></path>
                <path d="M13 8l5 4-5 4"></path>
                <path d="M18 12H9"></path>
            </svg>
        </span>
        <h3 class="logout-confirm-title">Log out of SmartLib?</h3>
        <p class="logout-confirm-text">You'll need to sign in again with your Student ID and PIN to search, borrow, or view your account.</p>
        <div class="logout-confirm-actions">
            <button type="button" id="logout-confirm-cancel-btn" class="logout-confirm-cancel">Stay Signed In</button>
            <button type="button" id="logout-confirm-submit-btn" class="logout-confirm-confirm">Log Out</button>
        </div>
    </div>
</div>

<div id="checkout-confirm-modal" class="modal hidden checkout-confirm-modal">
    <div class="modal-backdrop"></div>
    <div class="modal-panel checkout-confirm-panel">
        <span class="checkout-confirm-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" role="presentation">
                <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path>
                <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path>
            </svg>
        </span>
        <h3 class="checkout-confirm-title">Confirm Checkout</h3>
        <p class="checkout-confirm-text">You're about to borrow <strong id="checkout-confirm-book-title">this book</strong>. Please return it by the due date shown on your receipt.</p>
        <div class="checkout-confirm-actions">
            <button type="button" id="checkout-confirm-cancel-btn" class="checkout-confirm-cancel">Cancel</button>
            <button type="button" id="checkout-confirm-submit-btn" class="checkout-confirm-confirm">Yes, Check Out</button>
        </div>
    </div>
</div>
<script>window.SMARTLIB_SIGNIN_CSRF = <?= json_encode($signinCsrfToken) ?>;</script>
<script src="assets/javascript/year-select-ui.js?v=<?= $yearSelectJsVer ?>"></script>
<script src="assets/javascript/filters.js?v=<?= $filtersJsVer ?>"></script>
<script src="assets/javascript/kiosk-idle.js?v=<?= $kioskIdleJsVer ?>" data-home="1" data-signed-in="<?= isset($_SESSION['user_id']) ? '1' : '0' ?>"></script>
</body>
</html>


















