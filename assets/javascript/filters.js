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
        year_from: "",
        year_to: ""
    };

    let totalBooks = 0;

    const discoverView = document.getElementById("discover-view");
    const searchView = document.getElementById("search-view");
    const discoverSearchInput = document.getElementById("discover-search-input");
    const advancedSearchInput = document.getElementById("advanced-search-input");
    const bookGrid = document.getElementById("book-grid");
    const resultsCount = document.getElementById("results-count");
    const topSearchCard = advancedSearchInput?.closest(".top-search-card") || null;

    let suggestionPanel = null;
    let suggestionItems = [];
    let activeSuggestionIndex = -1;
    let suggestionDebounceTimer = null;
    let suggestionFetchToken = 0;

    const escapeSuggestionText = (value) => String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");

    const ensureSuggestionPanel = () => {
        if (!topSearchCard) return null;
        if (suggestionPanel) return suggestionPanel;

        suggestionPanel = document.createElement("div");
        suggestionPanel.className = "search-suggestions-panel";
        topSearchCard.appendChild(suggestionPanel);
        return suggestionPanel;
    };

    const closeSuggestions = () => {
        if (!suggestionPanel) return;
        suggestionPanel.classList.remove("is-open");
        suggestionPanel.innerHTML = "";
        suggestionItems = [];
        activeSuggestionIndex = -1;
    };

    const highlightActiveSuggestion = () => {
        if (!suggestionPanel) return;
        suggestionPanel.querySelectorAll(".search-suggestion-item").forEach((btn, idx) => {
            btn.classList.toggle("is-active", idx === activeSuggestionIndex);
        });
    };

    const applySuggestion = (value) => {
        const text = String(value || "").trim();
        if (text === "") return;

        setSearch(text);
        closeSuggestions();

        if (advancedSearchInput) {
            advancedSearchInput.focus();
            const caret = advancedSearchInput.value.length;
            advancedSearchInput.setSelectionRange(caret, caret);
        }
    };

    const renderSuggestions = (items) => {
        const panel = ensureSuggestionPanel();
        if (!panel) return;

        suggestionItems = Array.isArray(items)
            ? items.map(item => String(item || "").trim()).filter(Boolean).slice(0, 8)
            : [];
        activeSuggestionIndex = -1;

        if (suggestionItems.length === 0) {
            closeSuggestions();
            return;
        }

        panel.innerHTML = suggestionItems.map((text, idx) => `
            <button type="button" class="search-suggestion-item" data-index="${idx}">
                <span class="search-suggestion-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" role="presentation"><circle cx="11" cy="11" r="7"></circle><path d="M20 20l-3.4-3.4"></path></svg>
                </span>
                <span class="search-suggestion-text">${escapeSuggestionText(text)}</span>
            </button>
        `).join("");

        panel.querySelectorAll(".search-suggestion-item").forEach(btn => {
            btn.addEventListener("mousedown", e => {
                e.preventDefault();
            });

            btn.addEventListener("click", () => {
                const idx = Number.parseInt(btn.dataset.index || "-1", 10);
                if (!Number.isFinite(idx) || idx < 0 || idx >= suggestionItems.length) return;
                applySuggestion(suggestionItems[idx]);
            });
        });

        panel.classList.add("is-open");
    };

    const fetchSuggestions = (rawQuery) => {
        const query = String(rawQuery || "").trim();
        if (query.length < 1) {
            closeSuggestions();
            return;
        }

        if (!searchView || searchView.classList.contains("hidden")) {
            closeSuggestions();
            return;
        }

        const requestToken = ++suggestionFetchToken;
        fetch(`fetch_suggestions.php?q=${encodeURIComponent(query)}&limit=8`, { headers: { Accept: "application/json" } })
            .then(res => res.ok ? res.json() : Promise.reject(new Error("Suggestion request failed")))
            .then(data => {
                if (requestToken !== suggestionFetchToken) return;
                const items = Array.isArray(data?.suggestions)
                    ? data.suggestions.map(s => s?.text)
                    : [];
                renderSuggestions(items);
            })
            .catch(() => {
                if (expectsTwoFactor) {
                    pendingTwoFactor = true;
                    signinOtpWrap?.classList.remove("hidden");
                    signinForgotPinBtn?.classList.add("hidden");
                    if (signinHelper) {
                        signinHelper.textContent = "Enter the 6-digit verification code from your email.";
                    }
                    setSigninBusy(false, "Verify Code");
                    if (signinOtpInput) signinOtpInput.focus();
                    setSigninMessage("Code may have been sent. Enter the 6-digit code to continue.");
                    return;
                }

                resetTwoFactorState(false);
                setSigninMessage("Login failed.");
            });
    };

    const queueSuggestions = () => {
        if (!advancedSearchInput) return;
        window.clearTimeout(suggestionDebounceTimer);
        suggestionDebounceTimer = window.setTimeout(() => {
            fetchSuggestions(advancedSearchInput.value);
        }, 120);
    };

    const moveActiveSuggestion = (direction) => {
        if (!suggestionPanel || !suggestionPanel.classList.contains("is-open") || suggestionItems.length === 0) {
            return;
        }

        if (activeSuggestionIndex < 0) {
            activeSuggestionIndex = direction > 0 ? 0 : suggestionItems.length - 1;
        } else {
            activeSuggestionIndex = (activeSuggestionIndex + direction + suggestionItems.length) % suggestionItems.length;
        }

        highlightActiveSuggestion();
    };

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
        if (activeFilters.year_from !== "") params.append("year_from", activeFilters.year_from);
        if (activeFilters.year_to !== "") params.append("year_to", activeFilters.year_to);

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
            chip.innerHTML = `Search: "${activeFilters.search}" <span class="remove-x">x</span>`;
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
                chip.innerHTML = `${value} <span class="remove-x">x</span>`;

                chip.querySelector(".remove-x")?.addEventListener("click", () => {
                    removeFilter(type, value);
                });

                container.appendChild(chip);
            });
        }

        if (activeFilters.year_from !== "" || activeFilters.year_to !== "") {
            const fromLabel = activeFilters.year_from !== "" ? activeFilters.year_from : "Any";
            const toLabel = activeFilters.year_to !== "" ? activeFilters.year_to : "Any";
            const chip = document.createElement("div");
            chip.className = "active-chip";
            chip.innerHTML = `Year: ${fromLabel} - ${toLabel} <span class="remove-x">x</span>`;
            chip.querySelector(".remove-x")?.addEventListener("click", () => {
                activeFilters.year_from = "";
                activeFilters.year_to = "";
                if (yearFromSelect) yearFromSelect.value = "";
                if (yearToSelect) yearToSelect.value = "";
                refreshActiveFilterDisplay();
                loadBooks();
            });
            container.appendChild(chip);
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

    advancedSearchInput?.addEventListener("input", () => {
        setSearch(advancedSearchInput.value);
        queueSuggestions();
    });

    advancedSearchInput?.addEventListener("focus", () => {
        if ((advancedSearchInput.value || "").trim() !== "") {
            queueSuggestions();
        }
    });

    advancedSearchInput?.addEventListener("keydown", e => {
        const isOpen = !!suggestionPanel?.classList.contains("is-open");

        if (e.key === "ArrowDown") {
            e.preventDefault();
            if (!isOpen) {
                queueSuggestions();
                return;
            }
            moveActiveSuggestion(1);
            return;
        }

        if (e.key === "ArrowUp") {
            e.preventDefault();
            if (!isOpen) {
                queueSuggestions();
                return;
            }
            moveActiveSuggestion(-1);
            return;
        }

        if (e.key === "Enter" && isOpen && activeSuggestionIndex >= 0 && activeSuggestionIndex < suggestionItems.length) {
            e.preventDefault();
            applySuggestion(suggestionItems[activeSuggestionIndex]);
            return;
        }

        if (e.key === "Escape" && isOpen) {
            e.preventDefault();
            closeSuggestions();
        }
    });

    document.addEventListener("click", e => {
        if (!topSearchCard || !suggestionPanel) return;
        if (!topSearchCard.contains(e.target)) {
            closeSuggestions();
        }
    });

    const yearFromSelect = document.getElementById("year-from-select");
    const yearToSelect = document.getElementById("year-to-select");

    const applyYearRangeFilter = () => {
        const rawFrom = String(yearFromSelect?.value || "").trim();
        const rawTo = String(yearToSelect?.value || "").trim();

        let from = rawFrom;
        let to = rawTo;

        if (from !== "" && to !== "") {
            const fromNum = Number.parseInt(from, 10);
            const toNum = Number.parseInt(to, 10);
            if (Number.isFinite(fromNum) && Number.isFinite(toNum) && fromNum > toNum) {
                [from, to] = [to, from];
                if (yearFromSelect) yearFromSelect.value = from;
                if (yearToSelect) yearToSelect.value = to;
            }
        }

        activeFilters.year_from = from;
        activeFilters.year_to = to;
        refreshActiveFilterDisplay();
        loadBooks();
    };

    yearFromSelect?.addEventListener("change", applyYearRangeFilter);
    yearToSelect?.addEventListener("change", applyYearRangeFilter);
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
            year_from: "",
            year_to: ""
        };

        syncSearchInputs("");
        if (yearFromSelect) yearFromSelect.value = "";
        if (yearToSelect) yearToSelect.value = "";
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

    const accountTypeToggle = document.getElementById("signin-account-type-toggle");
    const accountTypeMenu = document.getElementById("signin-account-type-menu");
    const accountTypeHidden = document.getElementById("signin-account-type");
    const accountTypeLabel = document.getElementById("signin-account-type-label");
    const accountTypeSub = document.getElementById("signin-account-type-sub");
    const signinIdentifierLabel = document.getElementById("signin-identifier-label");
    const signinIdentifierInput = document.getElementById("signin-identifier");
    const signinPasswordInput = document.getElementById("signin-password");
    const signinPasswordToggle = document.getElementById("signin-password-toggle");
    const signinOtpWrap = document.getElementById("signin-2fa-wrap");
    const signinOtpInput = document.getElementById("signin-otp");
    const signinHelper = document.getElementById("signin-helper");
    const signinForgotPinBtn = document.getElementById("signin-forgot-pin");
    const signinMsgBox = document.getElementById("signin-msg");
    const signinBtn = document.getElementById("signin-btn");

    let pendingTwoFactor = false;
let signinCountdownTimer = null;
let signinCountdownEndTs = 0;

    const clearSigninCountdown = () => {
        if (signinCountdownTimer) {
            clearInterval(signinCountdownTimer);
            signinCountdownTimer = null;
        }
        signinCountdownEndTs = 0;
    };

    const setSigninMessage = (text = "") => {
        if (signinMsgBox) signinMsgBox.textContent = text;
    };

    const formatSigninTimeLeft = (seconds = 0) => {
        const safe = Math.max(0, Math.floor(seconds));
        const mins = Math.floor(safe / 60);
        const secs = safe % 60;
        if (mins > 0) {
            return `${mins}m ${String(secs).padStart(2, "0")}s`;
        }
        return `${secs}s`;
    };

    const startSigninCountdown = (seconds, prefix = "Too many failed sign-in attempts. Try again in ") => {
        clearSigninCountdown();
        const safeSeconds = Math.max(0, Math.floor(Number(seconds) || 0));
        if (safeSeconds <= 0) {
            setSigninMessage(`${prefix}0s.`);
            return;
        }

        signinCountdownEndTs = Date.now() + (safeSeconds * 1000);

        const tick = () => {
            const left = Math.max(0, Math.ceil((signinCountdownEndTs - Date.now()) / 1000));
            setSigninMessage(`${prefix}${formatSigninTimeLeft(left)}.`);
            if (left <= 0) {
                clearSigninCountdown();
            }
        };

        tick();
        signinCountdownTimer = setInterval(tick, 1000);
    };

    const setSigninBusy = (busy, label) => {
        if (!signinBtn) return;
        signinBtn.disabled = !!busy;
        signinBtn.textContent = label;
    };

    const setSigninPasswordVisibility = (visible) => {
        if (!signinPasswordInput) return;
        const show = !!visible;
        signinPasswordInput.type = show ? "text" : "password";

        if (signinPasswordToggle) {
            signinPasswordToggle.classList.toggle("is-visible", show);
            signinPasswordToggle.setAttribute("aria-pressed", show ? "true" : "false");
            signinPasswordToggle.setAttribute("aria-label", show ? "Hide PIN" : "Show PIN");
        }
    };

    const resetTwoFactorState = (clearMessage = false) => {
        pendingTwoFactor = false;
        clearSigninCountdown();
        signinOtpWrap?.classList.add("hidden");
        if (signinOtpInput) signinOtpInput.value = "";
        signinForgotPinBtn?.classList.remove("hidden");
        if (signinHelper) signinHelper.textContent = " ";
        setSigninBusy(false, "Sign In");
        if (clearMessage) setSigninMessage("");
    };

    const showAccountModal = () => {
        modal?.classList.remove("hidden");
        setSigninPasswordVisibility(false);
        resetTwoFactorState(true);
    };

    const hideAccountModal = () => {
        modal?.classList.add("hidden");
        accountTypeMenu?.classList.add("hidden");
        accountTypeToggle?.setAttribute("aria-expanded", "false");
        setSigninPasswordVisibility(false);
        resetTwoFactorState(true);
    };

    if (openModal && modal) openModal.onclick = showAccountModal;
    if (closeModal && modal) closeModal.onclick = hideAccountModal;
    modalBackdrop?.addEventListener("click", hideAccountModal);

    if (signinPasswordToggle && signinPasswordInput) {
        signinPasswordToggle.addEventListener("click", () => {
            setSigninPasswordVisibility(signinPasswordInput.type === "password");
        });
    }

    document.getElementById("discover-signin")?.addEventListener("click", () => {
        showAccountModal();
    });

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
        resetTwoFactorState(true);
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

    try {
        const signinTarget = new URLSearchParams(window.location.search).get("signin");
        if (signinTarget) {
            const normalizedTarget = signinTarget.toLowerCase();
            const targetOption = accountTypeMenu?.querySelector(`.account-type-option[data-type="${normalizedTarget}"]`);
            if (targetOption) applyAccountType(targetOption);
            showAccountModal();
        }
    } catch (_err) {
        // Ignore malformed URLs; sign-in still works through the normal buttons.
    }

    const submitPasswordStep = () => {
        const identifier = signinIdentifierInput?.value.trim() || "";
        const password = signinPasswordInput?.value.trim() || "";
        const accountType = (accountTypeHidden?.value || "student").trim();
        const idLabel = signinIdentifierLabel?.textContent || "ID";
        const expectsTwoFactor = accountType === "librarian";

        clearSigninCountdown();
        setSigninMessage("");

        if (!identifier || !password) {
            setSigninMessage(`Please enter ${idLabel} and PIN.`);
            return;
        }

        if (expectsTwoFactor) {
            signinOtpWrap?.classList.remove("hidden");
            signinForgotPinBtn?.classList.add("hidden");
            if (signinHelper) signinHelper.textContent = "Checking account and sending verification code...";
            setSigninBusy(true, "Sending Code...");
        } else {
            setSigninBusy(true, "Signing In...");
        }

        fetch("login_handler.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            credentials: "same-origin",
            cache: "no-store",
            body: new URLSearchParams({ identifier, password, account_type: accountType })
        })
            .then(async (res) => {
                const raw = await res.text();
                try {
                    return JSON.parse(raw);
                } catch (_err) {
                    const snippet = String(raw || "").replace(/\s+/g, " ").trim().slice(0, 180);
                    throw new Error("NON_JSON_LOGIN::" + snippet);
                }
            })
            .then(data => {
                if (data.status === "success") {
                    window.location.href = data.role === "librarian" ? "admin/dashboard.php" : "index.php";
                    return;
                }

                if (data.status === "2fa_required") {
                    pendingTwoFactor = true;
                    signinOtpWrap?.classList.remove("hidden");
                    signinForgotPinBtn?.classList.add("hidden");
                    if (signinHelper) {
                        signinHelper.textContent = "Enter the 6-digit code sent to your email to continue.";
                    }
                    setSigninBusy(false, "Verify Code");
                    if (signinPasswordInput) signinPasswordInput.value = "";
                    if (signinOtpInput) signinOtpInput.focus();
                    clearSigninCountdown();
                    setSigninMessage(data.message || "Verification code required.");
                    return;
                }

                const maybeCodeSent = expectsTwoFactor && /verification code sent/i.test(String(data.message || ""));
                if (maybeCodeSent) {
                    pendingTwoFactor = true;
                    signinOtpWrap?.classList.remove("hidden");
                    signinForgotPinBtn?.classList.add("hidden");
                    if (signinHelper) {
                        signinHelper.textContent = "Enter the 6-digit code sent to your email to continue.";
                    }
                    setSigninBusy(false, "Verify Code");
                    if (signinPasswordInput) signinPasswordInput.value = "";
                    if (signinOtpInput) signinOtpInput.focus();
                    clearSigninCountdown();
                    setSigninMessage(data.message || "Verification code required.");
                    return;
                }

                resetTwoFactorState(false);
                const retryAfter = Number(data.retry_after_seconds || 0);
                if (Number.isFinite(retryAfter) && retryAfter > 0) {
                    startSigninCountdown(retryAfter);
                } else {
                    setSigninMessage(data.message || "Login failed.");
                }
            })
            .catch((err) => {
                resetTwoFactorState(false);
                const rawMsg = String(err && err.message ? err.message : "");
                if (rawMsg.startsWith("NON_JSON_LOGIN::")) {
                    setSigninMessage("Sign-in service returned an invalid response. Please check server PHP errors/logs.");
                    return;
                }
                setSigninMessage("Login failed. Please try again.");
            });
    };

    const submitTwoFactorStep = () => {
        const code = (signinOtpInput?.value || "").replace(/\D+/g, "").trim();
        if (code.length !== 6) {
            setSigninMessage("Enter the 6-digit verification code.");
            return;
        }

        setSigninBusy(true, "Verifying...");

        fetch("verify_2fa.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            credentials: "same-origin",
            cache: "no-store",
            body: new URLSearchParams({ code })
        })
            .then(async (res) => {
                const raw = await res.text();
                try {
                    return JSON.parse(raw);
                } catch (_err) {
                    const snippet = String(raw || "").replace(/\s+/g, " ").trim().slice(0, 180);
                    throw new Error("NON_JSON_2FA::" + snippet);
                }
            })
            .then(data => {
                if (data.status === "success") {
                    window.location.href = data.role === "librarian" ? "admin/dashboard.php" : "index.php";
                    return;
                }

                if (data.status === "expired") {
                    resetTwoFactorState(false);
                } else {
                    setSigninBusy(false, "Verify Code");
                }

                setSigninMessage(data.message || "Verification failed.");
            })
            .catch((err) => {
                setSigninBusy(false, "Verify Code");
                const rawMsg = String(err && err.message ? err.message : "");
                if (rawMsg.startsWith("NON_JSON_2FA::")) {
                    setSigninMessage("Verification endpoint returned an invalid response. Check server logs / verify_2fa.php.");
                    return;
                }
                setSigninMessage("Verification failed.");
            });
    };

    if (signinBtn) {
        signinBtn.addEventListener("click", () => {
            if (pendingTwoFactor) {
                submitTwoFactorStep();
            } else {
                submitPasswordStep();
            }
        });
    }


    signinForgotPinBtn?.addEventListener("click", () => {
        const identifier = signinIdentifierInput?.value.trim() || "";
        const accountType = (accountTypeHidden?.value || "student").trim();
        const idLabel = signinIdentifierLabel?.textContent || "ID";

        if (!identifier) {
            setSigninMessage(`Enter ${idLabel} first, then click Forgot PIN.`);
            return;
        }

        setSigninMessage("Sending reset link...");
        fetch("request_pin_reset.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: new URLSearchParams({ identifier, account_type: accountType })
        })
            .then(res => res.json())
            .then(data => {
                setSigninMessage(data.message || "If this account exists, a reset link was sent.");
            })
            .catch(() => {
                setSigninMessage("Could not process reset request right now.");
            });
    });
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

    const showReceiptStatus = (type, message, options = {}) => {
        const ui = ensureReceiptStatusUi();
        if (!ui) return;

        // Keep the email-status popup above receipt and other open modals.
        try {
            document.body.appendChild(ui.root);
            ui.root.style.zIndex = "9800";
        } catch (_) {
            // no-op
        }

        const normalizedType = type === "success" || type === "warning" || type === "error" ? type : "error";
        const isSuccess = normalizedType === "success";
        const isWarning = normalizedType === "warning";

        const defaultTitle = isSuccess
            ? "Receipt Sent to Email"
            : (isWarning ? "Borrow Limit Reached" : "Email Not Sent");
        const defaultMessage = isSuccess
            ? "Your receipt was sent successfully."
            : (isWarning ? "You have reached your borrowing limit for this account." : "Unable to send receipt email.");
        const defaultAction = isSuccess ? "Nice" : (isWarning ? "Okay" : "Close");

        ui.root.classList.remove("hidden", "is-success", "is-error", "is-warning");
        ui.root.classList.add(`is-${normalizedType}`);

        if (ui.icon) ui.icon.innerHTML = isSuccess ? "&#10003;" : (isWarning ? "&#9888;" : "!");
        if (ui.title) ui.title.textContent = options.title || defaultTitle;
        if (ui.message) ui.message.textContent = message || options.message || defaultMessage;
        if (ui.action) ui.action.textContent = options.actionLabel || defaultAction;
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
            showReceiptStatus("error", "Invalid book selection.", {
                title: "Unable to Check Out",
                actionLabel: "Close"
            });
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
            const errorMessage = String(error?.message || "Checkout failed.").trim();
            const isBorrowLimit = /borrow\s+limit\s+reached/i.test(errorMessage);

            showReceiptStatus(isBorrowLimit ? "warning" : "error", errorMessage, {
                title: isBorrowLimit ? "Borrow Limit Reached" : "Unable to Check Out",
                actionLabel: "Okay"
            });

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
        const reason = String(book.reason || book.availability_label || "").trim();
        const availability = String(book.availability_label || "").trim();
        const parsedId = Number.parseInt(String(book.book_id ?? book.id ?? ""), 10);

        return {
            src,
            cover,
            alt: title ? `${title} cover` : "Book cover",
            title,
            author,
            reason,
            availability,
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
                reason: img.dataset.reason || img.dataset.availability || "",
                availability: img.dataset.availability || "",
                bookId: Number.isFinite(parsedId) && parsedId > 0 ? parsedId : null
            };
        });
    };

    const hydratePanel = (panel, panelData) => {
        const titleNode = panel.querySelector("h2");
        const subtitleNode = panel.querySelector("p");
        const titleMetaNode = panel.querySelector(".carousel-title");
        const authorMetaNode = panel.querySelector(".carousel-author");
        const reasonMetaNode = panel.querySelector(".carousel-reason");
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
            const reasonAttr = book.reason ? ` data-reason="${escapeAttr(book.reason)}"` : "";
            const availabilityAttr = book.availability ? ` data-availability="${escapeAttr(book.availability)}"` : "";
            return `<img class="${cls}" data-cover="${escapeAttr(book.cover)}"${bookIdAttr}${titleAttr}${authorAttr}${reasonAttr}${availabilityAttr} src="${escapeAttr(book.src)}" alt="${escapeAttr(book.alt)}">`;
        }).join("");

        const centerBook = books[centerIndex] || books[0];
        if (titleMetaNode) titleMetaNode.textContent = centerBook.title || "Book";
        if (authorMetaNode) authorMetaNode.textContent = centerBook.author || "Unknown Author";
        if (reasonMetaNode) reasonMetaNode.textContent = centerBook.reason || centerBook.availability || "Available now";
    };

    const panels = Array.from(document.querySelectorAll(".discover-panel"));
    const panelMap = new Map();
    panels.forEach((panel, idx) => {
        const key = String(panel.dataset.panelKey || "").trim() || `panel_${idx}`;
        panelMap.set(key, panel);
    });

    const applyRecommendationPayload = (payload) => {
        if (!payload || !Array.isArray(payload.panels)) return;

        const orderedPanels = [];

        payload.panels.forEach((panelData, idx) => {
            const key = String(panelData?.key || "").trim();
            const panel = (key && panelMap.get(key)) || panels[idx] || null;
            if (!panel) return;

            hydratePanel(panel, panelData);
            if (!orderedPanels.includes(panel)) {
                orderedPanels.push(panel);
            }
        });

        panels.forEach((panel) => {
            if (!orderedPanels.includes(panel)) {
                orderedPanels.push(panel);
            }
        });

        const discoverSection = document.getElementById("discover-section");
        if (!discoverSection || orderedPanels.length === 0) return;

        const cta = discoverSection.querySelector(".recommend-cta");
        orderedPanels.forEach((panel) => {
            discoverSection.insertBefore(panel, cta || null);
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
            const reasonEl = panel.querySelector(".carousel-reason");

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
                    reason: (img.dataset.reason || img.dataset.availability || "").trim(),
                    availability: (img.dataset.availability || "").trim(),
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
                if (reasonEl) reasonEl.textContent = center.reason || center.availability || "Available now";
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

                    if (item.reason) img.dataset.reason = item.reason;
                    else img.removeAttribute("data-reason");

                    if (item.availability) img.dataset.availability = item.availability;
                    else img.removeAttribute("data-availability");

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
        if (s === "returned") return "Returned";
        if (s === "borrowed") return "Borrowed";
        return "Active";
    };

    const money = (value) => {
        const amount = Number(value || 0);
        return `PHP ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    };

    const dueNote = (book) => {
        const status = String(book?.status || "").toLowerCase();
        const days = Number(book?.days_until_due);
        if (status === "missing") return "Marked missing";
        if (status === "returned") return `Returned ${esc(book?.date_returned_label || book?.date_returned || "")}`;
        if (!Number.isFinite(days)) return "Due date unavailable";
        if (days < 0) return `${Math.abs(days)} day${Math.abs(days) === 1 ? "" : "s"} overdue`;
        if (days === 0) return "Due today";
        return `${days} day${days === 1 ? "" : "s"} left`;
    };

    const forceMyBooksLayout = () => {
        if (!myBooksModal || !myBooksContent) return;

        if (myBooksPanel) {
            myBooksPanel.style.width = "min(920px, 94vw)";
            myBooksPanel.style.maxHeight = "86vh";
            myBooksPanel.style.overflow = "auto";
        }
    };

    const renderBookCard = (book, compact = false) => `
        <div class="my-book-item ${compact ? 'compact' : ''}">
            <img src="${esc(book.cover || 'assets/covers/default.jpg')}" alt="Book cover" class="my-book-cover">
            <div class="my-book-meta">
                <h4>${esc(book.title || 'Untitled')}</h4>
                <p class="my-book-author">${esc(book.author || 'Unknown Author')}</p>
                <div class="my-book-details">
                    <span><strong>Accession:</strong> ${esc(book.accession_no || 'N/A')}</span>
                    <span><strong>Borrowed:</strong> ${esc(book.date_borrowed_label || book.date_borrowed || 'N/A')}</span>
                    <span><strong>Due:</strong> ${esc(book.due_date_label || book.due_date || 'N/A')}</span>
                    ${book.date_returned_label ? `<span><strong>Returned:</strong> ${esc(book.date_returned_label)}</span>` : ''}
                    ${Number(book.fine || 0) > 0 ? `<span><strong>Fine:</strong> ${money(book.fine)}</span>` : ''}
                </div>
                <p class="my-book-due-note ${esc(String(book.status || '').toLowerCase())}">${esc(dueNote(book))}</p>
            </div>
            <div class="my-book-actions">
                <span class="my-book-status ${esc(String(book.status || '').toLowerCase())}">${esc(statusLabel(book.status))}</span>
                ${book.can_renew ? `<button type="button" class="my-book-renew-btn" data-record-id="${esc(book.record_id)}">Renew</button>` : `<small>${esc(book.renew_note || '')}</small>`}
            </div>
        </div>
    `;

    const renderHistoryRows = (books = []) => {
        if (!Array.isArray(books) || books.length === 0) {
            return '<div class="my-books-empty slim"><strong>No returned books yet.</strong><p>Your completed loans will appear here.</p></div>';
        }

        return `
            <div class="my-history-table">
                ${books.slice(0, 25).map((book) => `
                    <div class="my-history-row">
                        <strong>${esc(book.title || 'Untitled')}</strong>
                        <span>${esc(book.date_borrowed_label || book.date_borrowed || 'N/A')}</span>
                        <span>${esc(book.date_returned_label || book.date_returned || 'N/A')}</span>
                        <span>${Number(book.fine || 0) > 0 ? money(book.fine) : 'No fine'}</span>
                    </div>
                `).join("")}
            </div>
        `;
    };

    const renderMyBooks = (payload = {}) => {
        if (!myBooksContent) return;

        const legacyBooks = Array.isArray(payload) ? payload : null;
        const account = legacyBooks ? { active_books: legacyBooks, books: legacyBooks } : payload;
        const summary = account?.summary || {};
        const borrower = account?.borrower || {};
        const activeBooks = Array.isArray(account?.active_books) ? account.active_books : [];
        const missingBooks = Array.isArray(account?.missing_books) ? account.missing_books : [];
        const returnedBooks = Array.isArray(account?.returned_books) ? account.returned_books : [];
        const hasAnyRecords = Number(summary.total_records || 0) > 0 || activeBooks.length || missingBooks.length || returnedBooks.length;

        const borrowerMeta = [
            borrower.user_number ? `ID ${esc(borrower.user_number)}` : '',
            borrower.role ? esc(String(borrower.role).charAt(0).toUpperCase() + String(borrower.role).slice(1)) : '',
            borrower.program ? esc(borrower.program) : ''
        ].filter(Boolean).map((item) => `<span>${item}</span>`).join("");

        myBooksContent.innerHTML = `
            <section class="my-account-head">
                <div>
                    <p class="my-account-kicker">Borrower</p>
                    <h4>${esc(borrower.name || 'Library Account')}</h4>
                    <div class="my-account-meta">${borrowerMeta}</div>
                </div>
                <div class="my-account-fine ${Number(summary.active_fines || 0) > 0 ? 'has-fine' : ''}">
                    <span>Active Fine</span>
                    <strong>${money(summary.active_fines)}</strong>
                </div>
            </section>

            <section class="my-account-summary">
                <article><span>Current Loans</span><strong>${Number(summary.current_loans || activeBooks.length)}</strong></article>
                <article><span>Overdue</span><strong>${Number(summary.overdue_books || 0)}</strong></article>
                <article><span>Missing</span><strong>${Number(summary.missing_books || missingBooks.length)}</strong></article>
                <article><span>Returned</span><strong>${Number(summary.returned_books || returnedBooks.length)}</strong></article>
            </section>

            ${!hasAnyRecords ? `
                <div class="my-books-empty">
                    <strong>No borrowing history yet.</strong>
                    <p>Borrow a book from the catalog and your account activity will appear here.</p>
                </div>
            ` : `
                <section class="my-account-section">
                    <div class="my-account-section-head">
                        <h5>Current Loans</h5>
                        <span>${activeBooks.length} active</span>
                    </div>
                    ${activeBooks.length ? activeBooks.map((book) => renderBookCard(book)).join("") : '<div class="my-books-empty slim"><strong>No current borrowed books.</strong><p>You do not have active loans right now.</p></div>'}
                </section>

                ${missingBooks.length ? `
                    <section class="my-account-section alert">
                        <div class="my-account-section-head">
                            <h5>Missing Books</h5>
                            <span>${missingBooks.length} item${missingBooks.length === 1 ? '' : 's'}</span>
                        </div>
                        ${missingBooks.map((book) => renderBookCard(book, true)).join("")}
                    </section>
                ` : ''}

                <section class="my-account-section">
                    <div class="my-account-section-head">
                        <h5>Returned History</h5>
                        <span>${returnedBooks.length} returned</span>
                    </div>
                    ${renderHistoryRows(returnedBooks)}
                </section>
            `}
        `;

        forceMyBooksLayout();
    };
    const renewLoan = async (recordId, button = null) => {
        if (!recordId || !myBooksContent) return;

        if (button) {
            button.disabled = true;
            button.textContent = "Renewing...";
        }

        try {
            const res = await fetch("renew_loan.php", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams({ record_id: String(recordId) }),
                credentials: "same-origin"
            });
            const data = await res.json();

            if (!res.ok || data.status !== "success") {
                throw new Error(data.message || "Unable to renew loan.");
            }

            myBooksContent.innerHTML = `<div class="my-books-loading success">Renewed. New due date: ${esc(data.due_date_label || data.due_date || '')}</div>`;
            await openMyBooks();
        } catch (err) {
            if (button) {
                button.disabled = false;
                button.textContent = "Renew";
            }
            const message = document.createElement("div");
            message.className = "my-books-empty error renewal-error";
            message.innerHTML = `<strong>Could not renew this loan.</strong><p>${esc(err?.message || 'Please try again.')}</p>`;
            myBooksContent.prepend(message);
        }
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

            renderMyBooks(data);
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

    myBooksContent?.addEventListener("click", (e) => {
        const renewButton = e.target.closest(".my-book-renew-btn");
        if (!renewButton) return;
        e.preventDefault();
        renewLoan(renewButton.dataset.recordId, renewButton);
    });
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



























