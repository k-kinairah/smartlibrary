(function () {
    if (typeof window.smartlibTrackRecommendation === "function") return;

    const sentImpressions = new Set();

    window.smartlibTrackRecommendation = function (eventType, panelKey, bookId) {
        const event = String(eventType || "").toLowerCase().trim();
        if (!["impression", "open", "checkout"].includes(event)) return;

        const panel = String(panelKey || "").trim() || "unknown";
        const parsedBookId = Number.parseInt(String(bookId || ""), 10);
        const safeBookId = Number.isFinite(parsedBookId) && parsedBookId > 0 ? parsedBookId : null;

        if (event === "impression" && safeBookId !== null) {
            const impressionKey = `${event}|${panel}|${safeBookId}`;
            if (sentImpressions.has(impressionKey)) return;
            sentImpressions.add(impressionKey);
        }

        fetch("track_recommendation_event.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                event_type: event,
                panel_key: panel,
                book_id: safeBookId
            })
        }).catch(() => {
            // Non-blocking analytics call.
        });
    };
})();
document.addEventListener("DOMContentLoaded", () => {
    let activeFilters = {
        search: "",
        genre: [],
        year_published: []
    };

    let totalBooks = 0;

    const discoverView = document.getElementById("discover-view");
    const searchView = document.getElementById("search-view");
    const discoverSearchInput = document.getElementById("discover-search-input");
    const advancedSearchInput = document.getElementById("advanced-search-input");
    const bookGrid = document.getElementById("book-grid");
    const resultsCount = document.getElementById("results-count");

    function attachTypingPlaceholder(input, phrases, config = {}) {
        if (!input || !Array.isArray(phrases) || phrases.length === 0) return;

        const typeDelay = config.typeDelay || 62;
        const deleteDelay = config.deleteDelay || 38;
        const phrasePause = config.phrasePause || 1250;
        const restartDelay = config.restartDelay || 260;

        let phraseIndex = 0;
        let charIndex = 0;
        let deleting = false;
        let timerId = null;

        const tick = () => {
            const activeValue = (input.value || "").trim();
            if (activeValue !== "") {
                input.setAttribute("placeholder", "");
                timerId = null;
                return;
            }

            const phrase = phrases[phraseIndex] || "";

            if (!deleting) {
                charIndex = Math.min(charIndex + 1, phrase.length);
                input.setAttribute("placeholder", phrase.slice(0, charIndex));

                if (charIndex >= phrase.length) {
                    deleting = true;
                    timerId = window.setTimeout(tick, phrasePause);
                    return;
                }

                timerId = window.setTimeout(tick, typeDelay + Math.floor(Math.random() * 30));
                return;
            }

            charIndex = Math.max(charIndex - 1, 0);
            input.setAttribute("placeholder", phrase.slice(0, charIndex));

            if (charIndex === 0) {
                deleting = false;
                phraseIndex = (phraseIndex + 1) % phrases.length;
                timerId = window.setTimeout(tick, restartDelay);
                return;
            }

            timerId = window.setTimeout(tick, deleteDelay + Math.floor(Math.random() * 22));
        };

        const ensureRunning = () => {
            if (timerId !== null) return;
            timerId = window.setTimeout(tick, 250);
        };

        input.addEventListener("input", () => {
            if ((input.value || "").trim() === "") {
                if (timerId === null) {
                    ensureRunning();
                }
                return;
            }

            input.setAttribute("placeholder", "");
        });

        input.addEventListener("blur", () => {
            if ((input.value || "").trim() === "") {
                ensureRunning();
            }
        });

        ensureRunning();
    }

    attachTypingPlaceholder(discoverSearchInput, [
        "Find a book you will actually finish...",
        "Search the catalog by title or author...",
        "Try a topic like business, nursing, or cybercrime...",
        "Discover your next favorite read..."
    ]);

    attachTypingPlaceholder(advancedSearchInput, [
        "Find a title in seconds...",
        "Search the shelves by author name...",
        "Look up an ISBN or keyword...",
        "Try: Criminology, Marketing, or Psychology...",
        "Search the catalog like a librarian..."
    ]);

    function showSearchView(seed = "") {
        if (discoverView) discoverView.classList.add("hidden");
        if (searchView) searchView.classList.remove("hidden");

        if (seed.trim() !== "") {
            if (advancedSearchInput) advancedSearchInput.value = seed;
            activeFilters.search = seed;
            loadBooks();
        } else {
            if (advancedSearchInput) advancedSearchInput.focus();
        }
    }

    function showDiscoverView() {
        clearAllFilters();

        if (searchView) searchView.classList.add("hidden");
        if (discoverView) discoverView.classList.remove("hidden");

        if (advancedSearchInput) advancedSearchInput.blur();
        if (discoverSearchInput) {
            discoverSearchInput.value = "";
            discoverSearchInput.blur();
        }

        window.scrollTo({ top: 0, behavior: "smooth" });
    }

    function shouldReturnToDiscover(target) {
        if (!(target instanceof Element)) return false;
        if (!searchView || searchView.classList.contains("hidden")) return false;
        if (document.querySelector(".modal:not(.hidden)")) return false;

        if (target.closest(".search-filters-top, .filter-core, .results-head, .book-card, .book-modal-v2, .btn-login, .logout-form, .logout-inline, .user-header-right, .user-menu-toggle, .user-menu-dropdown, .user-menu-item")) {
            return false;
        }

        return target === searchView || target.classList.contains("search-layout") || target.id === "book-grid";
    }

    function syncSearchInputs(value) {
        if (advancedSearchInput && advancedSearchInput.value !== value) advancedSearchInput.value = value;
        if (discoverSearchInput && discoverSearchInput.value !== value) discoverSearchInput.value = value;
    }

    function buildParams() {
        const params = new URLSearchParams();

        if (activeFilters.search.trim() !== "") {
            params.append("search", activeFilters.search.trim());
        }

        activeFilters.genre.forEach(v => params.append("genre[]", v));
        activeFilters.year_published.forEach(v => params.append("year_published[]", v));

        return params;
    }

    function updateResultCount() {
        if (!resultsCount || !bookGrid) return;
        const found = bookGrid.querySelectorAll(".book-card").length;

        if (totalBooks > 0) {
            resultsCount.textContent = `Found ${found} of ${totalBooks} books`;
        } else {
            resultsCount.textContent = `Found ${found} books`;
        }
    }

    function refreshActiveFilterDisplay() {
        const container = document.getElementById("active-filters");
        if (!container) return;

        container.innerHTML = "";

        if (activeFilters.search.trim() !== "") {
            const chip = document.createElement("div");
            chip.className = "active-chip";
            chip.innerHTML = `Search: \"${activeFilters.search}\" <span class=\"remove-x\">x</span>`;
            chip.querySelector(".remove-x")?.addEventListener("click", () => {
                activeFilters.search = "";
                syncSearchInputs("");
                loadBooks();
                refreshActiveFilterDisplay();
            });
            container.appendChild(chip);
        }

        for (const [type, values] of Object.entries(activeFilters)) {
            if (!Array.isArray(values)) continue;

            values.forEach(value => {
                const chip = document.createElement("div");
                chip.className = "active-chip";
                chip.innerHTML = `${value} <span class=\"remove-x\">x</span>`;

                chip.querySelector(".remove-x")?.addEventListener("click", () => {
                    removeFilter(type, value);
                });

                container.appendChild(chip);
            });
        }
    }

    function loadBooks() {
        const params = buildParams();

        fetch("fetch_books.php?" + params.toString())
            .then(res => res.text())
            .then(html => {
                if (bookGrid) bookGrid.innerHTML = html;
                updateResultCount();
            });
    }

    function computeTotalBooks() {
        fetch("fetch_books.php")
            .then(res => res.text())
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, "text/html");
                totalBooks = doc.querySelectorAll(".book-card").length;
                updateResultCount();
            });
    }

    function removeFilter(type, value) {
        activeFilters[type] = activeFilters[type].filter(v => v !== value);

        document.querySelectorAll(`.chip[data-filter='${type}'][data-value='${value}']`)
            .forEach(c => c.classList.remove("chip-active"));

        refreshActiveFilterDisplay();
        loadBooks();
    }

    function setSearch(value) {
        activeFilters.search = value.trim();
        syncSearchInputs(activeFilters.search);
        loadBooks();
        refreshActiveFilterDisplay();
    }

    discoverSearchInput?.addEventListener("focus", () => {
        showSearchView(discoverSearchInput.value.trim());
    });

    discoverSearchInput?.addEventListener("keydown", e => {
        if (e.key === "Enter") {
            e.preventDefault();
            showSearchView(discoverSearchInput.value.trim());
        }
    });

    searchView?.addEventListener("click", e => {
        if (shouldReturnToDiscover(e.target)) {
            showDiscoverView();
        }
    });

    advancedSearchInput?.addEventListener("input", () => setSearch(advancedSearchInput.value));
    document.querySelectorAll(".chip").forEach(chip => {
        chip.addEventListener("click", () => {
            const type = chip.dataset.filter;
            const value = chip.dataset.value;

            if (!type || !value || !Array.isArray(activeFilters[type])) return;

            if (chip.classList.contains("chip-active")) {
                chip.classList.remove("chip-active");
                activeFilters[type] = activeFilters[type].filter(v => v !== value);
            } else {
                chip.classList.add("chip-active");
                activeFilters[type].push(value);
            }

            refreshActiveFilterDisplay();
            loadBooks();
        });
    });

    document.querySelectorAll(".filter-top-tab").forEach(tab => {
        tab.addEventListener("click", () => {
            document.querySelectorAll(".filter-top-tab").forEach(t => t.classList.remove("active"));
            tab.classList.add("active");

            document.querySelectorAll(".filter-box-p").forEach(box => box.classList.add("hidden"));
            const box = document.getElementById(`${tab.dataset.type}-box`);
            if (box) box.classList.remove("hidden");
        });
    });

    function clearAllFilters() {
        activeFilters = {
            search: "",
            genre: [],
            year_published: []
        };

        syncSearchInputs("");
        document.querySelectorAll(".chip").forEach(c => c.classList.remove("chip-active"));

        refreshActiveFilterDisplay();
        loadBooks();
    }

    document.getElementById("clear-filters")?.addEventListener("click", clearAllFilters);
    document.getElementById("clear-active")?.addEventListener("click", clearAllFilters);

    const openModal = document.getElementById("open-account-modal");
    const closeModal = document.getElementById("close-modal");
    const modal = document.getElementById("account-modal");
    const modalBackdrop = modal?.querySelector(".modal-backdrop") || null;

    const showAccountModal = () => {
        modal?.classList.remove("hidden");
    };

    const hideAccountModal = () => {
        modal?.classList.add("hidden");
        const accountTypeMenu = document.getElementById("signin-account-type-menu");
        const accountTypeToggle = document.getElementById("signin-account-type-toggle");
        accountTypeMenu?.classList.add("hidden");
        accountTypeToggle?.setAttribute("aria-expanded", "false");
    };

    if (openModal && modal) openModal.onclick = showAccountModal;
    if (closeModal && modal) closeModal.onclick = hideAccountModal;
    modalBackdrop?.addEventListener("click", hideAccountModal);

    document.getElementById("discover-signin")?.addEventListener("click", () => {
        showAccountModal();
    });

    const accountTypeToggle = document.getElementById("signin-account-type-toggle");
    const accountTypeMenu = document.getElementById("signin-account-type-menu");
    const accountTypeHidden = document.getElementById("signin-account-type");
    const accountTypeLabel = document.getElementById("signin-account-type-label");
    const accountTypeSub = document.getElementById("signin-account-type-sub");
    const signinIdentifierLabel = document.getElementById("signin-identifier-label");
    const signinIdentifierInput = document.getElementById("signin-identifier");

    const applyAccountType = (optionEl) => {
        if (!optionEl) return;

        const selectedType = optionEl.dataset.type || "student";
        const selectedLabel = optionEl.dataset.label || "Student";
        const selectedSub = optionEl.dataset.sub || "";
        const idLabel = optionEl.dataset.idLabel || "Student ID";
        const idPlaceholder = optionEl.dataset.idPlaceholder || "20232243";

        if (accountTypeHidden) accountTypeHidden.value = selectedType;
        if (accountTypeLabel) accountTypeLabel.textContent = selectedLabel;
        if (accountTypeSub) accountTypeSub.textContent = selectedSub;
        if (signinIdentifierLabel) signinIdentifierLabel.textContent = idLabel;
        if (signinIdentifierInput) signinIdentifierInput.placeholder = idPlaceholder;

        accountTypeMenu?.querySelectorAll(".account-type-option").forEach(btn => {
            btn.classList.toggle("active", btn === optionEl);
        });

        accountTypeMenu?.classList.add("hidden");
        accountTypeToggle?.setAttribute("aria-expanded", "false");
    };

    if (accountTypeToggle && accountTypeMenu) {
        accountTypeToggle.addEventListener("click", (e) => {
            e.preventDefault();
            e.stopPropagation();
            const opening = accountTypeMenu.classList.contains("hidden");
            accountTypeMenu.classList.toggle("hidden", !opening);
            accountTypeToggle.setAttribute("aria-expanded", opening ? "true" : "false");
        });

        accountTypeMenu.querySelectorAll(".account-type-option").forEach(option => {
            option.addEventListener("click", (e) => {
                e.preventDefault();
                applyAccountType(option);
            });
        });

        document.addEventListener("click", (e) => {
            if (!modal || modal.classList.contains("hidden")) return;
            if (!accountTypeMenu.contains(e.target) && !accountTypeToggle.contains(e.target)) {
                accountTypeMenu.classList.add("hidden");
                accountTypeToggle.setAttribute("aria-expanded", "false");
            }
        });

        const initialOption =
            accountTypeMenu.querySelector(`.account-type-option[data-type="${accountTypeHidden?.value || "student"}"]`) ||
            accountTypeMenu.querySelector(".account-type-option.active") ||
            accountTypeMenu.querySelector(".account-type-option");

        applyAccountType(initialOption);
    }

    const signinBtn = document.getElementById("signin-btn");
    if (signinBtn) {
        signinBtn.addEventListener("click", () => {
            const identifier = signinIdentifierInput?.value.trim() || "";
            const password = document.getElementById("signin-password")?.value.trim() || "";
            const accountType = (accountTypeHidden?.value || "student").trim();
            const msgBox = document.getElementById("signin-msg");
            const idLabel = signinIdentifierLabel?.textContent || "ID";

            if (msgBox) msgBox.textContent = "";

            if (!identifier || !password) {
                if (msgBox) msgBox.textContent = `Please enter ${idLabel} and PIN.`;
                return;
            }

            fetch("login_handler.php", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams({ identifier, password, account_type: accountType })
            })
                .then(res => res.json())
                .then(data => {
                    if (data.status === "success") {
                        window.location.href = data.role === "librarian" ? "admin/dashboard.php" : "index.php";
                    } else if (msgBox) {
                        msgBox.textContent = data.message;
                    }
                })
                .catch(() => {
                    if (msgBox) msgBox.textContent = "Login failed.";
                });
        });
    }

    document.addEventListener("click", e => {
        const discoverImg = e.target.closest(".discover-book, .discover-carousel img");
        if (discoverImg) {
            const sourcePanelKey = discoverImg.closest(".discover-panel")?.dataset.panelKey || "discover";
            const rawBookId = String(discoverImg.dataset.bookId || discoverImg.dataset.id || "").trim();
            const discoverBookId = Number.parseInt(rawBookId, 10);

            if (Number.isFinite(discoverBookId) && discoverBookId > 0) {
                if (typeof window.smartlibTrackRecommendation === "function") {
                    window.smartlibTrackRecommendation("open", sourcePanelKey, discoverBookId);
                }

                fetch("get_book.php?id=" + discoverBookId)
                    .then(res => res.text())
                    .then(html => {
                        const content = document.getElementById("book-modal-content");
                        const modalBook = document.getElementById("book-modal");
                        if (content && modalBook) {
                            content.innerHTML = html;
                            const modalRoot = content.querySelector(".book-modal-v2");
                            if (modalRoot) {
                                modalRoot.dataset.sourcePanel = sourcePanelKey;
                            }
                            modalBook.classList.remove("hidden");
                        }
                    });
                return;
            }

            const src = discoverImg.getAttribute("src") || "";
            const srcCover = src ? src.split("/").pop() : "";
            const cover = (discoverImg.dataset.cover || srcCover || "").trim();

            if (cover) {
                fetch("get_book.php?cover=" + encodeURIComponent(cover))
                    .then(res => res.text())
                    .then(html => {
                        const content = document.getElementById("book-modal-content");
                        const modalBook = document.getElementById("book-modal");
                        if (content && modalBook) {
                            content.innerHTML = html;
                            const modalRoot = content.querySelector(".book-modal-v2");
                            if (modalRoot) {
                                modalRoot.dataset.sourcePanel = sourcePanelKey;
                                const modalBookId = Number.parseInt(String(modalRoot.dataset.bookId || ""), 10);
                                if (Number.isFinite(modalBookId) && modalBookId > 0 && typeof window.smartlibTrackRecommendation === "function") {
                                    window.smartlibTrackRecommendation("open", sourcePanelKey, modalBookId);
                                }
                            }
                            modalBook.classList.remove("hidden");
                        }
                    });
            }
            return;
        }

        const card = e.target.closest(".book-card");
        if (!card) return;

        const cardBookId = Number.parseInt(String(card.dataset.id || ""), 10);
        if (Number.isFinite(cardBookId) && cardBookId > 0 && typeof window.smartlibTrackRecommendation === "function") {
            window.smartlibTrackRecommendation("open", "search_results", cardBookId);
        }

        fetch("get_book.php?id=" + card.dataset.id)
            .then(res => res.text())
            .then(html => {
                const content = document.getElementById("book-modal-content");
                const modalBook = document.getElementById("book-modal");
                if (content && modalBook) {
                    content.innerHTML = html;
                    const modalRoot = content.querySelector(".book-modal-v2");
                    if (modalRoot) {
                        modalRoot.dataset.sourcePanel = "search_results";
                    }
                    modalBook.classList.remove("hidden");
                }
            });
    });

    document.getElementById("close-book-modal")?.addEventListener("click", () => {
        document.getElementById("book-modal")?.classList.add("hidden");
    });

    window.smartlibRefreshBooks = () => {
        computeTotalBooks();
        loadBooks();
    };

    window.smartlibRefreshBooks();
});











document.addEventListener("DOMContentLoaded", () => {
    const receiptModal = document.getElementById("receipt-modal");
    const receiptContent = document.getElementById("receipt-content");
    const closeReceiptBtn = document.getElementById("close-receipt-modal");
    const doneReceiptBtn = document.getElementById("receipt-done-btn");
    const printReceiptBtn = document.getElementById("receipt-print-btn");
    const downloadReceiptBtn = document.getElementById("receipt-download-btn");
    const receiptBackdrop = receiptModal?.querySelector(".modal-backdrop");

    let lastReceiptData = null;
    let receiptStatusUi = null;
    const RECEIPT_LOGO_SRC = "assets/images/sjcdc.png";

    const escapeHtml = (value) => {
        const div = document.createElement("div");
        div.textContent = value == null ? "" : String(value);
        return div.innerHTML;
    };

    const ensureReceiptStatusUi = () => {
        if (receiptStatusUi) return receiptStatusUi;

        let root = document.getElementById("receipt-status-modal");
        if (!root) {
            root = document.createElement("div");
            root.id = "receipt-status-modal";
            root.className = "receipt-status-modal hidden";
            root.setAttribute("role", "alertdialog");
            root.setAttribute("aria-modal", "true");
            root.innerHTML = `
                <div class="receipt-status-backdrop"></div>
                <div class="receipt-status-card">
                    <button type="button" class="receipt-status-close" aria-label="Close">&times;</button>
                    <div class="receipt-status-icon" aria-hidden="true">&#10003;</div>
                    <h4 class="receipt-status-title">Receipt Sent</h4>
                    <p class="receipt-status-message"></p>
                    <button type="button" class="receipt-status-btn">Got it</button>
                </div>
            `;
            document.body.appendChild(root);
        }

        const close = () => {
            root.classList.add("hidden");
        };

        root.querySelector(".receipt-status-close")?.addEventListener("click", close);
        root.querySelector(".receipt-status-btn")?.addEventListener("click", close);
        root.querySelector(".receipt-status-backdrop")?.addEventListener("click", close);

        receiptStatusUi = {
            root,
            icon: root.querySelector(".receipt-status-icon"),
            title: root.querySelector(".receipt-status-title"),
            message: root.querySelector(".receipt-status-message"),
            action: root.querySelector(".receipt-status-btn")
        };

        return receiptStatusUi;
    };

    const showReceiptStatus = (type, message) => {
        const ui = ensureReceiptStatusUi();
        if (!ui) return;

        const isSuccess = type === "success";
        ui.root.classList.remove("hidden", "is-success", "is-error");
        ui.root.classList.add(isSuccess ? "is-success" : "is-error");
        if (ui.icon) ui.icon.innerHTML = isSuccess ? "&#10003;" : "!";
        if (ui.title) ui.title.textContent = isSuccess ? "Receipt Sent to Email" : "Email Not Sent";
        if (ui.message) ui.message.textContent = message || (isSuccess ? "Your receipt was sent successfully." : "Unable to send receipt email.");
        if (ui.action) ui.action.textContent = isSuccess ? "Nice" : "Close";
    };

    const formatDate = (d) => d.toLocaleDateString("en-US", {
        month: "numeric",
        day: "numeric",
        year: "numeric"
    });

    const formatDateTime = (d) => d.toLocaleString("en-US", {
        month: "numeric",
        day: "numeric",
        year: "numeric",
        hour: "numeric",
        minute: "2-digit",
        second: "2-digit"
    });

    const getUserInfo = () => {
        const name = document.querySelector(".user-info strong")?.textContent.trim() || "Student User";
        const idLine = document.querySelector(".user-info span")?.textContent.trim() || "ID: 432";
        const idMatch = idLine.match(/ID:\s*(.+)$/i);
        const userId = (idMatch ? idMatch[1] : idLine).trim() || "432";

        return { name, userId };
    };

    const generateTransactionId = (userId) => {
        const now = new Date();
        const yyyy = now.getFullYear();
        const mm = String(now.getMonth() + 1).padStart(2, "0");
        const dd = String(now.getDate()).padStart(2, "0");
        const cleanId = String(userId).replace(/[^0-9A-Za-z-]/g, "") || "432";
        return `${cleanId}-2-${yyyy}-${mm}-${dd}`;
    };

    const CODE39_PATTERNS = {
        "0": "nnnwwnwnn", "1": "wnnwnnnnw", "2": "nnwwnnnnw", "3": "wnwwnnnnn",
        "4": "nnnwwnnnw", "5": "wnnwwnnnn", "6": "nnwwwnnnn", "7": "nnnwnnwnw",
        "8": "wnnwnnwnn", "9": "nnwwnnwnn", "A": "wnnnnwnnw", "B": "nnwnnwnnw",
        "C": "wnwnnwnnn", "D": "nnnnwwnnw", "E": "wnnnwwnnn", "F": "nnwnwwnnn",
        "G": "nnnnnwwnw", "H": "wnnnnwwnn", "I": "nnwnnwwnn", "J": "nnnnwwwnn",
        "K": "wnnnnnnww", "L": "nnwnnnnww", "M": "wnwnnnnwn", "N": "nnnnwnnww",
        "O": "wnnnwnnwn", "P": "nnwnwnnwn", "Q": "nnnnnnwww", "R": "wnnnnnwwn",
        "S": "nnwnnnwwn", "T": "nnnnwnwwn", "U": "wwnnnnnnw", "V": "nwwnnnnnw",
        "W": "wwwnnnnnn", "X": "nwnnwnnnw", "Y": "wwnnwnnnn", "Z": "nwwnwnnnn",
        "-": "nwnnnnwnw", ".": "wwnnnnwnn", " ": "nwwnnnwnn", "$": "nwnwnwnnn",
        "/": "nwnwnnnwn", "+": "nwnnnwnwn", "%": "nnnwnwnwn", "*": "nwnnwnwnn"
    };
    


    const normalizeCode39Value = (value) => {
        return String(value || "")
            .toUpperCase()
            .replace(/[^0-9A-Z\-\.\ \$\/\+%]/g, "")
            .trim() || "0";
    };

    const makeBarcodeSvg = (value) => {
        const normalized = normalizeCode39Value(value);
        const encoded = `*${normalized}*`;
        const narrow = 2;
        const wide = 5;
        const gap = 2;
        const quietZone = 12;
        const height = 56;

        let x = quietZone;
        let bars = "";

        for (let c = 0; c < encoded.length; c += 1) {
            const ch = encoded[c];
            const pattern = CODE39_PATTERNS[ch] || CODE39_PATTERNS['-'];

            for (let i = 0; i < pattern.length; i += 1) {
                const unit = pattern[i] === 'w' ? wide : narrow;
                const isBar = i % 2 === 0;

                if (isBar) {
                    bars += `<rect x="${x}" y="0" width="${unit}" height="${height}"></rect>`;
                }

                x += unit;
            }

            if (c < encoded.length - 1) {
                x += gap;
            }
        }

        const width = x + quietZone;
        return `<svg viewBox="0 0 ${width} ${height}" role="img" aria-label="Code 39 barcode for ${normalized}" preserveAspectRatio="none"><rect x="0" y="0" width="${width}" height="${height}" fill="#ffffff"></rect><g fill="#102b22">${bars}</g></svg>`;
    };

    const buildReceiptData = (modalRoot, checkoutReceipt = {}) => {
        const now = new Date();
        const due = new Date(now);
        const roleLoanDays = Number(checkoutReceipt.loan_days);
        const normalizedLoanDays = Number.isFinite(roleLoanDays) && roleLoanDays > 0 ? Math.round(roleLoanDays) : 7;
        due.setDate(due.getDate() + normalizedLoanDays);

        const user = getUserInfo();

        const title = modalRoot?.dataset.title || modalRoot?.querySelector(".book-modal-title")?.textContent.trim() || "Book";
        const author = modalRoot?.dataset.author || modalRoot?.querySelector(".book-modal-author-sub")?.textContent.trim() || "Unknown Author";
        const isbn = checkoutReceipt.isbn || modalRoot?.dataset.isbn || "N/A";
        const course = checkoutReceipt.course || modalRoot?.dataset.course || "N/A";
        const accession = checkoutReceipt.accession_no || modalRoot?.dataset.accession || "N/A";

        const receiptUserName = checkoutReceipt.user_name || user.name;
        const receiptUserId = checkoutReceipt.user_number || user.userId;
        const transactionId = checkoutReceipt.transaction_id || generateTransactionId(receiptUserId);
        const barcode = normalizeCode39Value(transactionId);
        const barcodeSvg = makeBarcodeSvg(barcode);

        return {
            kiosk: "SmartLib",
            library: "PHINMA-SJCDC Library",
            issuedAt: checkoutReceipt.issued_at || formatDateTime(now),
            userName: receiptUserName,
            userId: receiptUserId,
            course,
            title,
            author,
            accession,
            isbn,
            borrowedDate: checkoutReceipt.borrowed_date || formatDate(now),
            dueDate: checkoutReceipt.due_date || formatDate(due),
            loanDays: normalizedLoanDays,
            transactionId,
            barcode,
            barcodeSvg
        };
    };

    const renderReceipt = (data) => {
        if (!receiptContent) return;

        receiptContent.innerHTML = `
            <div class="receipt-paper-head">
                <img class="receipt-brand-logo" src="${escapeHtml(RECEIPT_LOGO_SRC)}" alt="SJCDC Logo">
                <div>${escapeHtml(data.kiosk)}</div>
                <div>${escapeHtml(data.library)}</div>
                <div>${escapeHtml(data.issuedAt)}</div>
            </div>

            <div class="receipt-divider"></div>

            <div class="receipt-section-title">Student Information</div>
            <div class="receipt-grid">
                <span>Name:</span><span>${escapeHtml(data.userName)}</span>
                <span>ID Number:</span><span>${escapeHtml(data.userId)}</span>
                <span>Course:</span><span>${escapeHtml(data.course)}</span>
            </div>

            <div class="receipt-divider"></div>

            <div class="receipt-section-title">Book Information</div>
            <div class="receipt-grid">
                <span>Title:</span><span>${escapeHtml(data.title)}</span>
                <span>Author:</span><span>${escapeHtml(data.author)}</span>
                <span>Accession Number:</span><span>${escapeHtml(data.accession)}</span>
                <span>ISBN:</span><span>${escapeHtml(data.isbn)}</span>
            </div>

            <div class="receipt-divider"></div>

            <div class="receipt-grid">
                <span>Borrowed:</span><span>${escapeHtml(data.borrowedDate)}</span>
                <span>Due Date:</span><span>${escapeHtml(data.dueDate)}</span>
            </div>

            <div class="receipt-divider"></div>

            <div class="receipt-transaction-title">Transaction ID</div>
            <div class="receipt-transaction-id">${escapeHtml(data.transactionId)}</div>
            <div class="receipt-barcode">${data.barcodeSvg || ""}</div>
            <div class="receipt-barcode-text">${escapeHtml(data.barcode)}</div>

            <div class="receipt-divider"></div>

            <div class="receipt-note">Please return this book on or before the due date</div>
            <div class="receipt-note">Late returns may incur fines</div>
        `;
    };

    const closeReceipt = () => {
        receiptModal?.classList.add("hidden");
    };

    document.addEventListener("click", async (e) => {
        const checkoutBtn = e.target.closest(".checkout-btn");
        if (!checkoutBtn) return;

        e.preventDefault();
        if (checkoutBtn.disabled) return;

        const modalRoot = checkoutBtn.closest(".book-modal-v2");
        const bookId = Number(modalRoot?.dataset.bookId || 0);

        if (!bookId) {
            alert("Invalid book selection.");
            return;
        }

        const originalText = checkoutBtn.textContent || "Check Out";
        checkoutBtn.disabled = true;
        checkoutBtn.textContent = "Processing...";

        try {
            const response = await fetch("checkout_book.php", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams({ book_id: String(bookId) })
            });

            const data = await response.json();
            if (!response.ok || data.status !== "success") {
                throw new Error(data.message || "Checkout failed.");
            }

            const receipt = data.receipt || {};
            if (modalRoot) {
                if (receipt.availability_text) {
                    modalRoot.dataset.availability = receipt.availability_text;
                }

                if (typeof receipt.available_copies !== "undefined") {
                    modalRoot.dataset.status = Number(receipt.available_copies) > 0 ? "available" : "borrowed";
                }
            }

            const receiptData = buildReceiptData(modalRoot, receipt);
            lastReceiptData = receiptData;
            renderReceipt(receiptData);

            document.getElementById("book-modal")?.classList.add("hidden");
            receiptModal?.classList.remove("hidden");

            const checkoutPanel = modalRoot?.dataset.sourcePanel || "kiosk_modal";
            if (typeof window.smartlibTrackRecommendation === "function") {
                window.smartlibTrackRecommendation("checkout", checkoutPanel, bookId);
            }

            if (typeof window.smartlibRefreshBooks === "function") {
                window.smartlibRefreshBooks();
            }
        } catch (error) {
            alert(error?.message || "Checkout failed.");
            checkoutBtn.disabled = false;
            checkoutBtn.textContent = originalText;
        }
    });

    closeReceiptBtn?.addEventListener("click", closeReceipt);
    doneReceiptBtn?.addEventListener("click", closeReceipt);
    receiptBackdrop?.addEventListener("click", closeReceipt);

    printReceiptBtn?.addEventListener("click", () => {
        if (!lastReceiptData || !receiptContent) return;

        const printWindow = window.open("", "_blank", "width=460,height=760");
        if (!printWindow) return;

        printWindow.document.write(`
            <html>
                <head>
                    <title>SmartLib Receipt</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 24px; color: #1f352b; }
                        .receipt { border: 1px dashed #b8c8bf; padding: 16px; border-radius: 10px; }
                        .brand { display:block; width:70px; height:70px; object-fit:contain; margin:0 auto 8px; }
                        .title { text-align: center; font-weight: 700; margin-bottom: 8px; }
                        .muted { text-align: center; color: #4d6459; margin-bottom: 12px; }
                        .line { border-top: 1px solid #d5e1da; margin: 10px 0; }
                        .grid { display: grid; grid-template-columns: 1fr auto; gap: 6px 10px; font-size: 13px; }
                        .section { font-weight: 700; margin-top: 6px; margin-bottom: 6px; }
                        .tx { background: #edf4ef; padding: 8px; border-radius: 8px; text-align: center; font-family: monospace; }
                        .barcode { text-align: center; margin-top: 8px; }
                        .barcode svg { width: 100%; height: 56px; display: block; }
                        .barcode-text { text-align: center; font-family: monospace; letter-spacing: 1px; margin-top: 6px; font-size: 12px; }
                    </style>
                </head>
                <body>
                    <div class="receipt">
                        <img class="brand" src="${escapeHtml(RECEIPT_LOGO_SRC)}" alt="SJCDC Logo" />
                        <div class="title">${escapeHtml(lastReceiptData.kiosk)}</div>
                        <div class="muted">${escapeHtml(lastReceiptData.library)}<br>${escapeHtml(lastReceiptData.issuedAt)}</div>
                        <div class="line"></div>
                        <div class="section">Student Information</div>
                        <div class="grid">
                            <span>Name:</span><span>${escapeHtml(lastReceiptData.userName)}</span>
                            <span>ID Number:</span><span>${escapeHtml(lastReceiptData.userId)}</span>
                            <span>Course:</span><span>${escapeHtml(lastReceiptData.course)}</span>
                        </div>
                        <div class="line"></div>
                        <div class="section">Book Information</div>
                        <div class="grid">
                            <span>Title:</span><span>${escapeHtml(lastReceiptData.title)}</span>
                            <span>Author:</span><span>${escapeHtml(lastReceiptData.author)}</span>
                            <span>Accession Number:</span><span>${escapeHtml(lastReceiptData.accession)}</span>
                            <span>ISBN:</span><span>${escapeHtml(lastReceiptData.isbn)}</span>
                            <span>Borrowed:</span><span>${escapeHtml(lastReceiptData.borrowedDate)}</span>
                            <span>Due Date:</span><span>${escapeHtml(lastReceiptData.dueDate)}</span>
                        </div>
                        <div class="line"></div>
                        <div class="section" style="text-align:center;">Transaction ID</div>
                        <div class="tx">${escapeHtml(lastReceiptData.transactionId)}</div>
                        <div class="barcode">${lastReceiptData.barcodeSvg || ""}</div>
                        <div class="barcode-text">${escapeHtml(lastReceiptData.barcode)}</div>
                    </div>
                </body>
            </html>
        `);

        printWindow.document.close();
        printWindow.focus();
        printWindow.print();
    });
    downloadReceiptBtn?.addEventListener("click", async () => {
        if (!lastReceiptData) return;

        const originalHtml = downloadReceiptBtn.innerHTML || "Download";
        downloadReceiptBtn.disabled = true;
        downloadReceiptBtn.textContent = "Sending...";

        try {
            const response = await fetch("send_receipt_email.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ receipt: lastReceiptData })
            });

            let data = {};
            try {
                data = await response.json();
            } catch (_) {
                data = {};
            }

            if (!response.ok || data.status !== "success") {
                throw new Error(data.message || "Unable to send receipt email.");
            }

            showReceiptStatus("success", data.message || "Receipt sent to your email.");
        } catch (error) {
            showReceiptStatus("error", error?.message || "Unable to send receipt email.");
        } finally {
            downloadReceiptBtn.disabled = false;
            downloadReceiptBtn.innerHTML = originalHtml;
        }
    });
});
document.addEventListener("DOMContentLoaded", () => {
    const discoverMeta = {
        "adventure1.jpg": { title: "Journey Across The Sea", author: "Elena Parker" },
        "adventure2.jpg": { title: "The Hobbit", author: "J.R.R. Tolkien" },
        "databasedesign.jpg": { title: "Beginning Database Design", author: "Clare Churcher" },
        "tech2.jpg": { title: "Introduction to Algorithms", author: "Thomas H. Cormen" },
        "tech1.jpg": { title: "Introduction to Programming", author: "Alan Ford" },
        "atkinson.png": { title: "Introduction to Psychology", author: "Susan Nolen-Hoeksema" },
        "anatomy.jpg": { title: "Human Anatomy Essentials", author: "Sarah L. Greene" },
        "drug2023.png": { title: "Drug Handbook 2023", author: "M. Reyes" },
        "csci1.jpg": { title: "Algorithms Simplified", author: "J. Ramirez" },
        "ai2022.jpg": { title: "AI in Education", author: "John Peterson" },
        "biz2023.jpg": { title: "Modern Business Trends 2023", author: "Carlos Reyes" },
        "business1.jpg": { title: "Business Fundamentals", author: "James Cooper" },
        "business2.jpg": { title: "Modern Entrepreneurship", author: "Linda Reyes" },
        "classic1.jpg": { title: "Pride and Prejudice", author: "Jane Austen" },
        "classic2.jpg": { title: "The Scarlet Letter", author: "Nathaniel Hawthorne" },
        "ninth.jpg": { title: "Psychiatric-Mental Health Nursing", author: "Unknown" }
    };

    const SWIPE_THRESHOLD = 45;

    const escapeAttr = (value) => {
        return String(value == null ? "" : value)
            .replace(/&/g, "&amp;")
            .replace(/"/g, "&quot;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;");
    };

    const prettifyFromCover = (cover = "") => {
        const base = cover.replace(/\.[^/.]+$/, "").replace(/[_-]+/g, " ").trim();
        if (!base) return { title: "Book", author: "Unknown Author" };
        const title = base.replace(/\b\w/g, c => c.toUpperCase());
        return { title, author: "Unknown Author" };
    };

    const normalizeCoverSrc = (rawValue = "") => {
        const value = String(rawValue || "").trim();
        if (value === "") return "assets/covers/default.jpg";
        if (/^(https?:)?\/\//i.test(value)) return value;
        if (value.startsWith("assets/")) return value;
        if (value.includes("/")) return value;
        return `assets/covers/${value}`;
    };

    const normalizeApiBook = (book) => {
        if (!book || typeof book !== "object") return null;

        const src = normalizeCoverSrc(book.cover_url || book.cover || "");
        const fileFromSrc = src.split("/").pop() || "";
        const cover = String(book.cover || fileFromSrc).trim();
        const title = String(book.title || "").trim();
        const author = String(book.author || "").trim();
        const parsedId = Number.parseInt(String(book.book_id ?? book.id ?? ""), 10);

        return {
            src,
            cover,
            alt: title ? `${title} cover` : "Book cover",
            title,
            author,
            bookId: Number.isFinite(parsedId) && parsedId > 0 ? parsedId : null
        };
    };

    const dedupeBooks = (items) => {
        const seen = new Set();
        const out = [];

        items.forEach(item => {
            if (!item || !item.src) return;
            const key = item.bookId ? `id:${item.bookId}` : `cover:${item.cover}:${item.src}`;
            if (seen.has(key)) return;
            seen.add(key);
            out.push(item);
        });

        return out;
    };

    const ensureMinBooks = (items, minCount = 5) => {
        const list = dedupeBooks(items);
        if (list.length === 0) return list;

        const padded = [...list];
        let cursor = 0;
        while (padded.length < minCount && list.length > 0) {
            padded.push({ ...list[cursor % list.length] });
            cursor += 1;
        }

        return padded;
    };

    const getFallbackFromPanel = (panel) => {
        const rawImages = Array.from(panel.querySelectorAll(".carousel-books img"));
        return rawImages.map(img => {
            const src = img.getAttribute("src") || "assets/covers/default.jpg";
            const cover = (img.dataset.cover || src.split("/").pop() || "").trim();
            const meta = discoverMeta[cover] || prettifyFromCover(cover);
            const bookIdRaw = String(img.dataset.bookId || img.dataset.id || "").trim();
            const parsedId = Number.parseInt(bookIdRaw, 10);

            return {
                src,
                cover,
                alt: img.getAttribute("alt") || "Book cover",
                title: img.dataset.title || meta.title || "Book",
                author: img.dataset.author || meta.author || "Unknown Author",
                bookId: Number.isFinite(parsedId) && parsedId > 0 ? parsedId : null
            };
        });
    };

    const hydratePanel = (panel, panelData) => {
        const titleNode = panel.querySelector("h2");
        const subtitleNode = panel.querySelector("p");
        const titleMetaNode = panel.querySelector(".carousel-title");
        const authorMetaNode = panel.querySelector(".carousel-author");
        const booksWrap = panel.querySelector(".carousel-books");

        if (!booksWrap) return;

        if (panelData && typeof panelData === "object") {
            if (titleNode && panelData.title) titleNode.textContent = String(panelData.title);
            if (subtitleNode && panelData.subtitle) subtitleNode.textContent = String(panelData.subtitle);
        }

        const apiBooks = Array.isArray(panelData?.books)
            ? panelData.books.map(normalizeApiBook).filter(Boolean)
            : [];

        const fallbackBooks = getFallbackFromPanel(panel);
        let books = apiBooks.length > 0 ? apiBooks : fallbackBooks;
        books = ensureMinBooks(books, 5).slice(0, 5);

        if (books.length === 0) return;

        const centerIndex = Math.floor(books.length / 2);

        booksWrap.innerHTML = books.map((book, idx) => {
            const cls = idx === centerIndex ? "center discover-book" : "side discover-book";
            const bookIdAttr = book.bookId ? ` data-book-id="${escapeAttr(book.bookId)}"` : "";
            const titleAttr = book.title ? ` data-title="${escapeAttr(book.title)}"` : "";
            const authorAttr = book.author ? ` data-author="${escapeAttr(book.author)}"` : "";
            return `<img class="${cls}" data-cover="${escapeAttr(book.cover)}"${bookIdAttr}${titleAttr}${authorAttr} src="${escapeAttr(book.src)}" alt="${escapeAttr(book.alt)}">`;
        }).join("");

        const centerBook = books[centerIndex] || books[0];
        if (titleMetaNode) titleMetaNode.textContent = centerBook.title || "Book";
        if (authorMetaNode) authorMetaNode.textContent = centerBook.author || "Unknown Author";
    };

    const panels = Array.from(document.querySelectorAll(".discover-panel"));
    const panelMap = new Map();
    panels.forEach((panel, idx) => {
        const key = String(panel.dataset.panelKey || "").trim() || `panel_${idx}`;
        panelMap.set(key, panel);
    });

    const applyRecommendationPayload = (payload) => {
        if (!payload || !Array.isArray(payload.panels)) return;

        payload.panels.forEach((panelData, idx) => {
            const key = String(panelData?.key || "").trim();
            const panel = (key && panelMap.get(key)) || panels[idx] || null;
            if (!panel) return;
            hydratePanel(panel, panelData);
        });
    };

    const initializeCarousels = () => {
        document.querySelectorAll(".discover-panel").forEach(panel => {
            const booksWrap = panel.querySelector(".carousel-books");
            const images = Array.from(booksWrap?.querySelectorAll("img") || []);
            const arrows = panel.querySelectorAll(".carousel-arrow");
            const prevBtn = arrows[0] || null;
            const nextBtn = arrows[1] || null;
            const titleEl = panel.querySelector(".carousel-title");
            const authorEl = panel.querySelector(".carousel-author");

            if (!booksWrap || images.length < 3 || !prevBtn || !nextBtn) return;

            const centerIndex = Math.floor(images.length / 2);
            let sequence = images.map(img => {
                const src = img.getAttribute("src") || "";
                const cover = (img.dataset.cover || src.split("/").pop() || "").trim();
                const meta = discoverMeta[cover] || prettifyFromCover(cover);
                const parsedBookId = Number.parseInt(String(img.dataset.bookId || img.dataset.id || "").trim(), 10);

                return {
                    src,
                    cover,
                    alt: img.getAttribute("alt") || "Book cover",
                    title: (img.dataset.title || meta.title || "Book").trim(),
                    author: (img.dataset.author || meta.author || "Unknown Author").trim(),
                    bookId: Number.isFinite(parsedBookId) && parsedBookId > 0 ? parsedBookId : null
                };
            });

            const updateMeta = () => {
                const center = sequence[centerIndex] || {};
                const cover = center.cover || (center.src ? center.src.split("/").pop() : "");
                const fallback = discoverMeta[cover] || prettifyFromCover(cover);
                const title = (center.title || fallback.title || "Book").trim();
                const author = (center.author || fallback.author || "Unknown Author").trim();

                if (titleEl) titleEl.textContent = title;
                if (authorEl) authorEl.textContent = author;
            };

            const render = () => {
                images.forEach((img, idx) => {
                    const item = sequence[idx] || {};
                    if (item.src) img.src = item.src;
                    img.dataset.cover = item.cover || (item.src ? item.src.split("/").pop() : "");
                    img.alt = item.alt || "Book cover";

                    if (item.bookId) img.dataset.bookId = String(item.bookId);
                    else img.removeAttribute("data-book-id");

                    if (item.title) img.dataset.title = item.title;
                    else img.removeAttribute("data-title");

                    if (item.author) img.dataset.author = item.author;
                    else img.removeAttribute("data-author");

                    img.classList.remove("center", "side");
                    img.classList.add(idx === centerIndex ? "center" : "side");
                });

                updateMeta();

                const center = sequence[centerIndex] || null;
                const centerBookId = Number.parseInt(String(center?.bookId || ""), 10);
                const panelKey = panel.dataset.panelKey || "discover";
                if (Number.isFinite(centerBookId) && centerBookId > 0 && typeof window.smartlibTrackRecommendation === "function") {
                    window.smartlibTrackRecommendation("impression", panelKey, centerBookId);
                }
            };

            const rotateNext = () => {
                sequence.push(sequence.shift());
                render();
            };

            const rotatePrev = () => {
                sequence.unshift(sequence.pop());
                render();
            };

            prevBtn.addEventListener("click", e => {
                e.preventDefault();
                e.stopPropagation();
                rotatePrev();
            });

            nextBtn.addEventListener("click", e => {
                e.preventDefault();
                e.stopPropagation();
                rotateNext();
            });

            let activePointerId = null;
            let startX = 0;
            let lastX = 0;
            let swiped = false;

            booksWrap.addEventListener("pointerdown", e => {
                activePointerId = e.pointerId;
                startX = e.clientX;
                lastX = e.clientX;
                swiped = false;
                booksWrap.classList.add("dragging");

                if (booksWrap.setPointerCapture) {
                    booksWrap.setPointerCapture(e.pointerId);
                }
            });

            booksWrap.addEventListener("pointermove", e => {
                if (activePointerId !== e.pointerId) return;
                lastX = e.clientX;
            });

            const endDrag = (e) => {
                if (activePointerId !== e.pointerId) return;
                const delta = lastX - startX;

                if (Math.abs(delta) >= SWIPE_THRESHOLD) {
                    if (delta < 0) rotateNext();
                    else rotatePrev();
                    swiped = true;
                }

                booksWrap.classList.remove("dragging");

                if (booksWrap.releasePointerCapture) {
                    try { booksWrap.releasePointerCapture(e.pointerId); } catch (_) {}
                }

                activePointerId = null;

                if (swiped) {
                    setTimeout(() => { swiped = false; }, 0);
                }
            };

            booksWrap.addEventListener("pointerup", endDrag);
            booksWrap.addEventListener("pointercancel", e => {
                if (activePointerId !== e.pointerId) return;
                booksWrap.classList.remove("dragging");
                activePointerId = null;
                swiped = false;
            });

            booksWrap.addEventListener("click", e => {
                if (swiped) {
                    e.preventDefault();
                    e.stopPropagation();
                    swiped = false;
                }
            }, true);

            render();
        });
    };

    fetch("recommend_books.php", { headers: { "Accept": "application/json" } })
        .then(res => {
            if (!res.ok) throw new Error("Recommendation request failed");
            return res.json();
        })
        .then(payload => {
            if (payload && payload.status === "success") {
                applyRecommendationPayload(payload);
            }
        })
        .catch(() => {
            // Keep static discover content if recommendation endpoint is unavailable.
        })
        .finally(() => {
            initializeCarousels();
        });
});
document.addEventListener("DOMContentLoaded", () => {
    const locationModal = document.getElementById("location-modal");
    const locationContent = document.getElementById("location-modal-content");
    const closeLocationBtn = document.getElementById("close-location-modal");
    const locationBackdrop = locationModal?.querySelector(".modal-backdrop");

    if (!locationModal || !locationContent) return;

    // Location modal scope helper
    const escapeHtml = (value) => {
        const div = document.createElement("div");
        div.textContent = value == null ? "" : String(value);
        return div.innerHTML;
    };



    const toTitle = (value) => {
        return String(value || "")
            .replace(/[_-]+/g, " ")
            .trim()
            .replace(/\b\w/g, (c) => c.toUpperCase());
    };

    const getStockState = (availability, status) => {
        const text = String(availability || "").toLowerCase();
        const s = String(status || "").toLowerCase();
        if (s === "borrowed" || s === "unavailable" || text.startsWith("0 of")) {
            return { label: "Checked Out", cls: "warn" };
        }
        return { label: "In Stock", cls: "ok" };
    };

    const buildLocationModal = (bookRoot) => {
        const title = bookRoot.dataset.title || "Book";
        const author = bookRoot.dataset.author || "Unknown Author";
        const section = bookRoot.dataset.locationSection || "General Collection";
        const classCode = bookRoot.dataset.locationClassCode || "T";
        const className = bookRoot.dataset.locationClassName || "Technology";
        const shelf = bookRoot.dataset.locationShelf || "YA-05";
        const row = bookRoot.dataset.locationRow || "First row";
        const position = bookRoot.dataset.locationPosition || "Eye Level";
        const call = bookRoot.dataset.locationCall || "";
        const availability = bookRoot.dataset.availability || "1 of 1 available";
        const status = bookRoot.dataset.status || "available";

        const stock = getStockState(availability, status);
        const callLine = call ? `<div class="loc-call-number">Call Number: ${escapeHtml(call)}</div>` : "";

        locationContent.innerHTML = `
            <div class="loc-head">
                <h3>Book Location</h3>
                <p>${escapeHtml(title)} by ${escapeHtml(author)}</p>
            </div>

            <div class="loc-guide-card">
                <div class="loc-guide-title-wrap">
                    <span class="loc-guide-icon">&gt;</span>
                    <div>
                        <h4>How to Find This Book</h4>
                        <p>Follow these directions from the kiosk</p>
                    </div>
                </div>

                <div class="loc-steps">
                    <div class="loc-step"><span>1</span><p>Find the <strong>${escapeHtml(section)}</strong> section</p></div>
                    <div class="loc-step"><span>2</span><p>Go to <strong>Class ${escapeHtml(classCode)}</strong> (${escapeHtml(className)}), Shelf <strong>${escapeHtml(shelf)}</strong></p></div>
                    <div class="loc-step"><span>3</span><p>Look at <strong>${escapeHtml(toTitle(row))}</strong>, <strong>${escapeHtml(toTitle(position))}</strong></p></div>
                </div>
                ${callLine}
            </div>

            <div class="loc-meta-grid">
                <div class="loc-meta-card"><div class="loc-meta-label">Section</div><div class="loc-meta-value">${escapeHtml(section)}</div></div>
                <div class="loc-meta-card"><div class="loc-meta-label">Shelf</div><div class="loc-meta-value">${escapeHtml(shelf)}</div></div>
                <div class="loc-meta-card"><div class="loc-meta-label">Row</div><div class="loc-meta-value">${escapeHtml(toTitle(row))}</div></div>
                <div class="loc-meta-card"><div class="loc-meta-label">Position</div><div class="loc-meta-value">${escapeHtml(toTitle(position))}</div></div>
            </div>

            <div class="loc-availability-card">
                <div>
                    <div class="loc-meta-label">Availability</div>
                    <div class="loc-meta-value">${escapeHtml(availability)}</div>
                </div>
                <span class="loc-stock-chip ${stock.cls}">${escapeHtml(stock.label)}</span>
            </div>
        `;

        locationModal.classList.remove("hidden");
    };

    const closeLocation = () => {
        locationModal.classList.add("hidden");
    };

    document.addEventListener("click", (e) => {
        const findBtn = e.target.closest(".find-book-btn");
        if (!findBtn) return;

        e.preventDefault();
        const bookRoot = findBtn.closest(".book-modal-v2");
        if (!bookRoot) return;

        buildLocationModal(bookRoot);
    });

    closeLocationBtn?.addEventListener("click", closeLocation);
    locationBackdrop?.addEventListener("click", closeLocation);
});


document.addEventListener("DOMContentLoaded", () => {
    const menuToggle = document.getElementById("user-menu-toggle");
    const menuDropdown = document.getElementById("user-menu-dropdown");
    const openMyBooksBtn = document.getElementById("open-my-books");

    const myBooksModal = document.getElementById("my-books-modal");
    const myBooksPanel = myBooksModal?.querySelector(".my-books-modal-panel") || null;
    const myBooksBackdrop = myBooksModal?.querySelector(".modal-backdrop") || null;
    const closeMyBooksBtn = document.getElementById("close-my-books-modal");
    const myBooksContent = document.getElementById("my-books-content");

    if (!menuToggle || !menuDropdown) return;

    const setMenuOpen = (open) => {
        menuDropdown.classList.toggle("hidden", !open);
        menuToggle.setAttribute("aria-expanded", open ? "true" : "false");
    };

    const closeMenu = () => setMenuOpen(false);

    menuToggle.addEventListener("click", (e) => {
        e.preventDefault();
        e.stopPropagation();
        setMenuOpen(menuDropdown.classList.contains("hidden"));
    });

    document.addEventListener("click", (e) => {
        if (!menuDropdown.contains(e.target) && !menuToggle.contains(e.target)) {
            closeMenu();
        }
    });

    const esc = (value) => {
        const div = document.createElement("div");
        div.textContent = value == null ? "" : String(value);
        return div.innerHTML;
    };

    const statusLabel = (status) => {
        const s = String(status || "").toLowerCase();
        if (s === "overdue") return "Overdue";
        if (s === "missing") return "Missing";
        if (s === "borrowed") return "Borrowed";
        return "Active";
    };

    const forceMyBooksLayout = () => {
        if (!myBooksModal || !myBooksContent) return;

        if (myBooksPanel) {
            myBooksPanel.style.width = "min(720px, 92vw)";
            myBooksPanel.style.maxHeight = "82vh";
            myBooksPanel.style.overflow = "auto";
        }

        myBooksContent.style.display = "flex";
        myBooksContent.style.flexDirection = "column";
        myBooksContent.style.gap = "10px";

        myBooksContent.querySelectorAll(".my-book-item").forEach((item) => {
            item.style.display = "grid";
            item.style.gridTemplateColumns = "64px minmax(0, 1fr) auto";
            item.style.gap = "12px";
            item.style.alignItems = "center";
            item.style.background = "#f5faf7";
            item.style.border = "1px solid #d5e3db";
            item.style.borderRadius = "12px";
            item.style.padding = "10px";
        });

        myBooksContent.querySelectorAll(".my-book-cover").forEach((img) => {
            img.style.width = "64px";
            img.style.minWidth = "64px";
            img.style.maxWidth = "64px";
            img.style.height = "92px";
            img.style.maxHeight = "92px";
            img.style.objectFit = "cover";
            img.style.borderRadius = "6px";
            img.style.boxShadow = "0 3px 10px rgba(0, 0, 0, 0.18)";
            img.style.display = "block";
        });
    };

    const renderMyBooks = (books = []) => {
        if (!myBooksContent) return;

        if (!Array.isArray(books) || books.length === 0) {
            myBooksContent.innerHTML = `
                <div class="my-books-empty">
                    <strong>No checked out books yet.</strong>
                    <p>Borrow a book from the catalog and it will appear here.</p>
                </div>
            `;
            forceMyBooksLayout();
            return;
        }

        myBooksContent.innerHTML = books.map((book) => `
            <div class="my-book-item">
                <img src="${esc(book.cover || 'assets/covers/default.jpg')}" alt="Book cover" class="my-book-cover">
                <div class="my-book-meta">
                    <h4>${esc(book.title || 'Untitled')}</h4>
                    <p class="my-book-author">${esc(book.author || 'Unknown Author')}</p>
                    <div class="my-book-details">
                        <span><strong>Accession:</strong> ${esc(book.accession_no || 'N/A')}</span>
                        <span><strong>Borrowed:</strong> ${esc(book.date_borrowed || 'N/A')}</span>
                        <span><strong>Due:</strong> ${esc(book.due_date || 'N/A')}</span>
                    </div>
                </div>
                <span class="my-book-status ${esc(String(book.status || '').toLowerCase())}">${esc(statusLabel(book.status))}</span>
            </div>
        `).join("");

        forceMyBooksLayout();
    };

    const openMyBooks = async () => {
        closeMenu();

        if (!myBooksModal || !myBooksContent) return;

        myBooksContent.innerHTML = '<div class="my-books-loading">Loading your books...</div>';
        forceMyBooksLayout();
        myBooksModal.classList.remove("hidden");

        try {
            const res = await fetch("fetch_my_checked_out.php", { credentials: "same-origin" });
            const data = await res.json();

            if (!res.ok || data.status !== "success") {
                throw new Error(data.message || "Unable to load books.");
            }

            renderMyBooks(data.books || []);
        } catch (err) {
            myBooksContent.innerHTML = `
                <div class="my-books-empty error">
                    <strong>Could not load your checked out books.</strong>
                    <p>${esc(err?.message || 'Please try again.')}</p>
                </div>
            `;
            forceMyBooksLayout();
        }
    };

    openMyBooksBtn?.addEventListener("click", (e) => {
        e.preventDefault();
        openMyBooks();
    });

    const closeMyBooks = () => {
        myBooksModal?.classList.add("hidden");
    };

    closeMyBooksBtn?.addEventListener("click", closeMyBooks);
    myBooksBackdrop?.addEventListener("click", closeMyBooks);
});






