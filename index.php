<?php
session_start();
require 'config/db_connect.php';

$kioskCssVer = @filemtime(__DIR__ . '/assets/css/kiosk.css') ?: time();
$filtersJsVer = @filemtime(__DIR__ . '/assets/javascript/filters.js') ?: time();

$genreChips = [];
$hasCategoryGroup = false;
$groupColRes = $conn->query("SHOW COLUMNS FROM categories LIKE 'category_group'");
if ($groupColRes && $groupColRes->num_rows > 0) {
    $hasCategoryGroup = true;
}

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
    <link rel="stylesheet" href="assets/css/kiosk.css?v=<?= $kioskCssVer ?>">
</head>
<body>

<div id="discover-view">
    <div class="discover-section" id="discover-section">
        <div class="discover-hero">
            <div class="discover-pill">✨ AI-Powered Recommendations</div>
            <h1>Discover Your Next Read</h1>
            <p>Personalized book suggestions curated just for you</p>
            <div class="discover-search-wrap">
                <input id="discover-search-input" type="text" placeholder="Search by title, author, ISBN, or subject...">
            </div>
        </div>

        <?php if (isset($_SESSION['user_id'])): ?>
        <section class="discover-panel" data-panel-key="course_highlights">
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
            <h3 class="carousel-title">Calculus: Early Transcendentals</h3>
            <p class="carousel-author">James Stewart</p>
        </section>

        <?php endif; ?>

        <section class="discover-panel" data-panel-key="most_borrowed">
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
            <h3 class="carousel-title">Introduction to Algorithms</h3>
            <p class="carousel-author">Thomas H. Cormen</p>
        </section>
        <section class="discover-panel" data-panel-key="most_searched">
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
            <h3 class="carousel-title">Introduction to Algorithms</h3>
            <p class="carousel-author">Thomas H. Cormen</p>
        </section>
<section class="discover-panel" data-panel-key="new_arrivals">
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
            <h3 class="carousel-title">Calculus: Early Transcendentals</h3>
            <p class="carousel-author">James Stewart</p>
        </section>
        <?php if (!isset($_SESSION['user_id'])): ?>
        <section class="recommend-cta">
            <div class="sparkle-icon">A+</div>
            <h3>Unlock Personalized Recommendations</h3>
            <p>Sign in to see recommendations tailored to your course and interests</p>
            <button id="discover-signin" class="discover-signin-btn">Sign In Now</button>
        </section>
        <?php endif; ?>
    </div>

    <footer class="discover-footer">
        <p>Carlos Trinidad Avenue, Salitran IV, City of Dasmarinas, Cavite, Philippines</p>
        <p>sjcdc.phinma.edu.ph | +63917 630 1064 | (046) 41-SJCDC or (046) 417-5232</p>
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
                        <strong><?php echo $_SESSION['name']; ?></strong>
                        <span>ID: <?php echo $_SESSION['user_number']; ?></span>
                    </span>
                    <span class="user-menu-caret" aria-hidden="true">
                        <svg viewBox="0 0 24 24" role="presentation">
                            <path d="M7 10l5 5 5-5"></path>
                        </svg>
                    </span>
                </button>

                <div id="user-menu-dropdown" class="user-menu-dropdown hidden">
                    <button type="button" id="open-my-books" class="user-menu-item">
                        <span class="menu-item-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" role="presentation">
                                <path d="M4 6.5A2.5 2.5 0 0 1 6.5 4H10v16H6.5A2.5 2.5 0 0 1 4 17.5z"></path>
                                <path d="M14 4h3.5A2.5 2.5 0 0 1 20 6.5v11A2.5 2.5 0 0 1 17.5 20H14z"></path>
                                <path d="M10 6h4"></path>
                            </svg>
                        </span>
                        <span>My Checked Out Books</span>
                    </button>
                    <form action="logout.php" method="POST" class="user-menu-form">
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
            <button id="open-account-modal" class="btn-login">Sign In</button>
        <?php endif; ?>
    </header>

    <div class="search-layout">
        <div class="filters-wrapper">
            <div class="search-filters-top">
                <div class="top-search-card">
                    <span class="advanced-search-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" role="presentation">
                            <circle cx="11" cy="11" r="7"></circle>
                            <path d="M20 20l-3.4-3.4"></path>
                        </svg>
                    </span>
                    <input type="text" id="advanced-search-input" autocomplete="off" placeholder="Search by title, author, ISBN, or subject...">
                </div>
            </div>

                        <div class="filter-card filter-core">
                <div class="filter-top-tabs">
                    <button class="filter-top-tab active" data-type="genre">Genre</button>
                    <button class="filter-top-tab" data-type="year_published">Year</button>
                </div>

                <div class="filter-box-p" id="genre-box">
                    <label class="filter-label">Filter by subject/genre:</label>
                    <div class="chip-container">
                        <?php if (!empty($genreChips)): ?>
                            <?php foreach ($genreChips as $genreName): ?>
                                <div class="chip" data-filter="genre" data-value="<?= htmlspecialchars($genreName) ?>"><?= htmlspecialchars($genreName) ?></div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="chip" aria-disabled="true">No genres available</div>
                        <?php endif; ?>
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
            <img src="assets/images/phinma.png" alt="PHINMA Education">
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
                    <button type="button" class="account-type-option active" data-type="student" data-label="Student" data-sub="3 books - 7 days" data-id-label="Student ID" data-id-placeholder="20232243">
                        <span class="option-title">Student</span>
                        <span class="option-sub">Borrow up to 3 books for 7 days</span>
                    </button>
                    <button type="button" class="account-type-option" data-type="faculty" data-label="Faculty" data-sub="5 books - 30 days" data-id-label="Faculty ID" data-id-placeholder="FAC0001">
                        <span class="option-title">Faculty</span>
                        <span class="option-sub">Borrow up to 5 books for 30 days</span>
                    </button>
                    <button type="button" class="account-type-option" data-type="librarian" data-label="Librarian" data-sub="5 books - 7 days" data-id-label="Librarian ID" data-id-placeholder="LIB0001">
                        <span class="option-title">Librarian</span>
                        <span class="option-sub">Staff access - up to 5 books for 7 days</span>
                    </button>
                </div>
            </div>
            <input type="hidden" id="signin-account-type" value="student">

            <div class="floating-field">
                <label id="signin-identifier-label" class="floating-label">Student ID</label>
                <input type="text" id="signin-identifier" placeholder="20232243">
            </div>

            <div class="floating-field">
                <label class="floating-label">PIN</label>
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
                <label class="floating-label">Verification Code</label>
                <input type="text" id="signin-otp" inputmode="numeric" maxlength="6" placeholder="123456">
            </div>

            <p id="signin-helper" class="modal-helper"> </p>
            <button type="button" id="signin-btn" class="primary-btn">Sign In</button>

            <div class="signin-links-row">
                <button type="button" id="signin-forgot-pin" class="signin-text-btn">Forgot your PIN?</button>
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
            <span class="receipt-check-icon">&#10003;</span>
            <div>
                <h3>Book Checked Out Successfully!</h3>
                <p>Your borrowing receipt</p>
            </div>
        </div>

        <div id="receipt-content" class="receipt-paper"></div>

        <div class="receipt-actions-row">
            <button type="button" id="receipt-print-btn" class="receipt-secondary-btn">Print Receipt</button>
            <button type="button" id="receipt-download-btn" class="receipt-secondary-btn">Download</button>
        </div>

        <button type="button" id="receipt-done-btn" class="primary-btn receipt-done-btn">Done</button>
    </div>
</div>

<div id="my-books-modal" class="modal hidden">
    <div class="modal-backdrop"></div>
    <div class="modal-panel my-books-modal-panel">
        <button class="close-btn" id="close-my-books-modal">&times;</button>
        <h3 class="my-books-title">My Checked Out Books</h3>
        <div id="my-books-content" class="my-books-content"></div>
    </div>
</div>
<script src="assets/javascript/filters.js?v=<?= $filtersJsVer ?>"></script>
</body>
</html>


















