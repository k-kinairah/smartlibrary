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

    // Kiosk Polish v160 (Typing Placeholder: Blinking Caret)
    // attachTypingPlaceholder (v57, extended v138/v139) types rotating
    // phrases into the search bars' native `placeholder` attribute, which
    // reads fine once a few characters are in, but has no visual tell that
    // it IS mid-type -- text just appears/disappears with no cursor, so a
    // glance at the kiosk mid-cycle (e.g. the "Loo" of "Look up a book
    // number or keyword...") can look like stray leftover text rather than
    // an animation in progress. A real <input> caret can't help here since
    // the box is empty (no value, only placeholder), so this adds a
    // synthetic one: a small span positioned over the input, repositioned
    // on every simulated keystroke by measuring the current substring with
    // a scratch <canvas> 2D context using the input's own live computed
    // font, so it tracks the font-size's clamp()'d responsive changes
    // without any hardcoded widths. It stays solid while characters are
    // being added or removed and only blinks (.is-paused, styled in
    // kiosk.css's companion v190) during the hold after a phrase finishes
    // typing -- the same distinction a real text cursor makes between
    // moving and waiting. It hides the instant there's real typed text,
    // same as the placeholder itself.
    //
    // The caret is appended to <body> and positioned in document
    // coordinates (getBoundingClientRect() + window.scrollX/Y), not as a
    // child of the input's own wrapper. First attempt did append it inside
    // the wrapper with `position: absolute`, which looked right in
    // isolation but broke specifically on #advanced-search-input: its
    // wrapper (.top-search-card) carries
    // "#search-view .search-filters-top .top-search-card > * { position:
    // relative; z-index: 1; }" from an earlier pass (an ID-qualified
    // selector), which outranks a plain .typing-caret class rule and
    // silently flipped `position: absolute` back to `relative` on the
    // caret the instant it became a child of that card -- turning it into
    // a stray flex item offset hundreds of pixels past the end of the bar
    // instead of an overlay. Anchoring to <body> in document coordinates
    // sidesteps that card's cascade entirely (verified against the live
    // page: confirmed broken inside the wrapper, confirmed correct once
    // moved to body-anchored coordinates) and also means no element needs
    // position:relative set on it for this to work.
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

        let caret = null;
        let measureCtx = null;

        const measureWidth = (text) => {
            if (!measureCtx) {
                measureCtx = document.createElement("canvas").getContext("2d");
            }
            const cs = getComputedStyle(input);
            measureCtx.font = `${cs.fontStyle} ${cs.fontWeight} ${cs.fontSize} ${cs.fontFamily}`;
            return measureCtx.measureText(text).width;
        };

        const ensureCaret = () => {
            if (caret) return;
            caret = document.createElement("span");
            caret.className = "typing-caret";
            caret.setAttribute("aria-hidden", "true");
            document.body.appendChild(caret);
        };

        const positionCaret = (text) => {
            if (!caret) return;
            const inputRect = input.getBoundingClientRect();
            const paddingLeft = parseFloat(getComputedStyle(input).paddingLeft) || 0;
            const left = inputRect.left + window.scrollX + paddingLeft + measureWidth(text);
            const top = inputRect.top + window.scrollY;
            caret.style.left = `${Math.max(0, left)}px`;
            caret.style.top = `${top}px`;
            caret.style.height = `${inputRect.height}px`;
        };

        const showCaret = (text, paused) => {
            ensureCaret();
            if (!caret) return;
            caret.style.display = "";
            caret.classList.toggle("is-paused", !!paused);
            positionCaret(text);
        };

        const hideCaret = () => {
            if (caret) caret.style.display = "none";
        };

        const tick = () => {
            const activeValue = (input.value || "").trim();
            if (activeValue !== "") {
                input.setAttribute("placeholder", "");
                hideCaret();
                timerId = null;
                return;
            }

            const phrase = phrases[phraseIndex] || "";

            if (!deleting) {
                charIndex = Math.min(charIndex + 1, phrase.length);
                const shown = phrase.slice(0, charIndex);
                input.setAttribute("placeholder", shown);

                if (charIndex >= phrase.length) {
                    deleting = true;
                    showCaret(shown, true);
                    timerId = window.setTimeout(tick, phrasePause);
                    return;
                }

                showCaret(shown, false);
                timerId = window.setTimeout(tick, typeDelay + Math.floor(Math.random() * 30));
                return;
            }

            charIndex = Math.max(charIndex - 1, 0);
            const shown = phrase.slice(0, charIndex);
            input.setAttribute("placeholder", shown);
            showCaret(shown, false);

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
            hideCaret();
        });

        input.addEventListener("blur", () => {
            if ((input.value || "").trim() === "") {
                ensureRunning();
            }
        });

        window.addEventListener("resize", () => {
            if (caret && caret.style.display !== "none") {
                positionCaret(phrases[phraseIndex] ? phrases[phraseIndex].slice(0, charIndex) : "");
            }
        });

        ensureRunning();
    }

    // Kiosk Polish v161 (Typing Placeholder: Content Refresh)
    // The original phrase lists (v57) leaned on generic instructional
    // copy -- "Search the shelves by author name...", "Try: Criminology,
    // Marketing, or Psychology..." -- which reads like UI help text rather
    // than something a student would actually be looking for. Rewritten to
    // sound like real reasons someone taps into this search: a thesis/
    // research reference, required reading for a class, a specific author
    // or subject, matched against this catalog's real subject taxonomy
    // (the same 20 genre names behind the filter chips -- Health &
    // Nursing, Criminology & Public Safety, Business & Management, etc.,
    // shortened here the same casual way the old copy already did for
    // "nursing"/"cybercrime") and two real, currently-popular titles/
    // authors confirmed live on the homepage's own "Most Borrowed"/"Most
    // Searched" panels (Earl Babbie's "Research Methods in the Social
    // Sciences", Thomas H. Cormen's "Introduction to Algorithms") rather
    // than invented examples. The discover bar also now mentions the
    // catalog's real size (2,000+ books) instead of a vague "our library".
    // Still five and six phrases respectively (plus one net-new each) --
    // only the wording changed, none of attachTypingPlaceholder's timing
    // or caret logic (v160) was touched.
    attachTypingPlaceholder(discoverSearchInput, [
        "Find a book you will actually finish...",
        "Search our library by title or author...",
        "Explore subjects like Nursing, Criminology, or Business...",
        "Discover your next favorite read...",
        "Search over 2,000 books in seconds..."
    ]);

    attachTypingPlaceholder(advancedSearchInput, [
        "Find a title in seconds...",
        "Look up a book number or keyword...",
        "Search by subject, like Nursing or Criminology...",
        "Need sources for your thesis? Try research methods...",
        "Search by author, like Earl Babbie or Thomas Cormen...",
        "Looking for required reading? Just search the title..."
    ]);

    // Kiosk Polish v138 (Discover <-> Search: Fade+Slide View Transition)
    // showSearchView/showDiscoverView used to toggle "hidden" on the two
    // main views directly -- an instant jump-cut with no visual continuity,
    // most noticeable since showSearchView fires on every single tap into
    // the homepage search bar (not just on Enter), making it one of the
    // most frequent transitions in the whole kiosk. This crossfades the
    // outgoing view down to nothing, then fades/slides the incoming view
    // up into place, using the CSS transition already defined on
    // #discover-view/#search-view (see kiosk.css) -- this function just
    // sequences the class changes so the browser actually animates them
    // instead of snapping straight to the end state. Sequential (leave
    // fully finishes, then enter starts) rather than a simultaneous
    // crossfade so the two full-page views are never both on-screen at
    // once, which would otherwise stack instead of overlap.
    //
    // nextFrame races requestAnimationFrame against a short setTimeout
    // instead of relying on rAF alone: rAF is what a foreground kiosk
    // display normally paints on, but browsers freely pause rAF the
    // moment a tab isn't the visible one (backgrounded, minimized, a
    // brief focus loss), and a paused rAF here would leave the incoming
    // view stuck invisible instead of just running the transition a touch
    // later than ideal.
    function nextFrame(fn) {
        let done = false;
        const run = () => {
            if (done) return;
            done = true;
            fn();
        };
        requestAnimationFrame(run);
        setTimeout(run, 50);
    }

    function switchMainView(hideView, showView, onShown) {
        const LEAVE_MS = 280; // keep this lined up with the CSS transition
                               // duration on #discover-view/#search-view

        // Both views get "view-transitioning" for the whole crossfade (not
        // just their own brief enter/leave moment) -- see kiosk.css -- so
        // they overlap instead of one shoving the other down the page for
        // however long both are technically un-hidden at once.
        if (hideView) hideView.classList.add("view-transitioning");
        if (showView) showView.classList.add("view-transitioning");

        // Start the incoming view fading in RIGHT NOW, at the same moment
        // the outgoing one starts fading out, instead of waiting for the
        // leave to fully finish first. Fixed a real bug: waiting left a
        // stretch in the middle where neither view had any opacity to
        // speak of -- just bare page background -- which read as the
        // transition breaking rather than playing.
        if (showView) {
            showView.classList.remove("hidden");
            showView.classList.add("view-anim-enter");
            void showView.offsetWidth; // force a reflow so the browser commits
                                        // to the "just appeared" state below
                                        // before we transition away from it
            nextFrame(() => {
                showView.classList.remove("view-anim-enter");
            });
        }

        if (hideView && !hideView.classList.contains("hidden")) {
            hideView.classList.add("view-anim-leave");

            let finished = false;
            const finish = () => {
                if (finished) return;
                finished = true;
                hideView.classList.add("hidden");
                hideView.classList.remove("view-anim-leave", "view-transitioning");
                if (showView) showView.classList.remove("view-transitioning");
                hideView.removeEventListener("transitionend", onTransitionEnd);
            };
            const onTransitionEnd = (e) => {
                if (e.target === hideView) finish();
            };

            hideView.addEventListener("transitionend", onTransitionEnd);
            setTimeout(finish, LEAVE_MS + 100); // safety net in case
                                                 // transitionend never fires
        } else {
            // Nothing is actually leaving (e.g. the outgoing view was
            // already hidden) -- there's no leave transition to wait on,
            // so drop the transitioning class(es) immediately instead of
            // leaving them stuck on.
            if (hideView) hideView.classList.remove("view-transitioning");
            if (showView) showView.classList.remove("view-transitioning");
        }

        if (typeof onShown === "function") onShown();
    }

    function showSearchView(seed = "") {
        // Kiosk Polish v139 (fix): this used to run inline, right after
        // switchMainView() was kicked off -- but at that point search-view
        // still carries the "hidden" class (display:none) until the leave
        // animation finishes and playEnter() lifts it, ~200-300ms later.
        // A display:none element can't take real focus, so
        // advancedSearchInput.focus() was a silent no-op almost every time:
        // tapping the homepage search bar landed you on the search screen
        // with nothing actually focused, so typing did nothing until you
        // tapped the search box a second time. Running this from
        // switchMainView's onShown callback instead means it only fires
        // once search-view is genuinely visible and focusable.
        switchMainView(discoverView, searchView, () => {
            if (seed.trim() !== "") {
                if (advancedSearchInput) advancedSearchInput.value = seed;
                activeFilters.search = seed;
                loadBooks();
            } else {
                if (advancedSearchInput) advancedSearchInput.focus();
            }
        });
    }

    function showDiscoverView() {
        clearAllFilters();

        switchMainView(searchView, discoverView);

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
                window.YearSelectUI?.syncAll();
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
        window.YearSelectUI?.syncAll();
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
        window.YearSelectUI?.syncAll();
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
    const signinPasswordLabel = document.getElementById("signin-password-label");
    const signinPasswordToggle = document.getElementById("signin-password-toggle");
    const signinOtpWrap = document.getElementById("signin-2fa-wrap");
    const signinOtpInput = document.getElementById("signin-otp");
    const signinHelper = document.getElementById("signin-helper");
    const signinForgotPinBtn = document.getElementById("signin-forgot-pin");
    const signinForgotLabel = document.getElementById("signin-forgot-label");
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

    // Student/Faculty sign in with a numeric PIN; Librarian/Admin accounts use a
    // full password (see admin/manage_users.php). This reads whichever label is
    // currently showing so messages/aria-labels say the right thing either way.
    const currentSecretLabel = () => signinPasswordLabel?.textContent || "PIN";

    const setSigninPasswordVisibility = (visible) => {
        if (!signinPasswordInput) return;
        const show = !!visible;
        signinPasswordInput.type = show ? "text" : "password";

        if (signinPasswordToggle) {
            signinPasswordToggle.classList.toggle("is-visible", show);
            signinPasswordToggle.setAttribute("aria-pressed", show ? "true" : "false");
            signinPasswordToggle.setAttribute("aria-label", show ? `Hide ${currentSecretLabel()}` : `Show ${currentSecretLabel()}`);
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
        const secretLabel = optionEl.dataset.secretLabel || "PIN";
        const secretPlaceholder = optionEl.dataset.secretPlaceholder || "2026";

        if (accountTypeHidden) accountTypeHidden.value = selectedType;
        if (accountTypeLabel) accountTypeLabel.textContent = selectedLabel;
        if (accountTypeSub) accountTypeSub.textContent = selectedSub;
        if (signinIdentifierLabel) signinIdentifierLabel.textContent = idLabel;
        if (signinIdentifierInput) signinIdentifierInput.placeholder = idPlaceholder;
        if (signinPasswordLabel) signinPasswordLabel.textContent = secretLabel;
        if (signinPasswordInput) signinPasswordInput.placeholder = secretPlaceholder;
        if (signinForgotLabel) signinForgotLabel.textContent = secretLabel;
        if (signinPasswordToggle) {
            const isVisible = signinPasswordInput?.type === "text";
            signinPasswordToggle.setAttribute("aria-label", isVisible ? `Hide ${secretLabel}` : `Show ${secretLabel}`);
        }

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
            setSigninMessage(`Please enter ${idLabel} and ${currentSecretLabel()}.`);
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
            setSigninMessage(`Enter ${idLabel} first, then click Forgot ${currentSecretLabel()}.`);
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
    // Favorites (heart icon). One toggle handler covers every surface the
    // heart button can appear on -- the search results grid, the book
    // detail modal, and the "My Favorites" list inside the account modal --
    // since all of them ultimately render into the DOM under `document`
    // and this listener is delegated there. Kept as a plain async function
    // (not a click handler itself) so the guard below can `await` it and
    // `return` before the discover-carousel/book-card open-modal logic
    // further down ever sees the click.
    const toggleFavorite = async (button) => {
        const bookId = Number.parseInt(button.dataset.bookId || "", 10);
        if (!Number.isFinite(bookId) || bookId <= 0 || button.dataset.favBusy === "1") {
            return;
        }

        button.dataset.favBusy = "1";
        const wasFavorited = button.classList.contains("is-favorited");
        const nextFavorited = !wasFavorited;

        // Optimistic update across every heart button for this book (the
        // same book can be showing in the grid AND the open modal AND the
        // favorites list at once) plus a quick "pop" so the toggle feels
        // immediate instead of waiting on the round trip.
        document.querySelectorAll(`.fav-heart-btn[data-book-id="${bookId}"]`).forEach(btn => {
            btn.classList.toggle("is-favorited", nextFavorited);
            btn.setAttribute("aria-pressed", String(nextFavorited));
        });
        button.classList.add("fav-pop");
        window.setTimeout(() => button.classList.remove("fav-pop"), 380);

        try {
            const res = await fetch("toggle_favorite.php", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams({ book_id: String(bookId) }),
                credentials: "same-origin"
            });

            if (res.status === 401) {
                document.querySelectorAll(`.fav-heart-btn[data-book-id="${bookId}"]`).forEach(btn => {
                    btn.classList.toggle("is-favorited", wasFavorited);
                    btn.setAttribute("aria-pressed", String(wasFavorited));
                });
                // #book-modal renders at z-index 9430 (Kiosk Polish v57) while
                // the sign-in modal is a plain .modal at 2000, so opening the
                // sign-in modal while the book modal is still up would stack
                // it silently underneath -- close the book modal first so the
                // "please sign in" prompt is actually visible.
                document.getElementById("book-modal")?.classList.add("hidden");
                showAccountModal();
                return;
            }

            const data = await res.json().catch(() => null);
            if (!res.ok || !data || data.status !== "success") {
                throw new Error(data?.message || "Could not update favorites.");
            }

            document.querySelectorAll(`.fav-heart-btn[data-book-id="${bookId}"]`).forEach(btn => {
                btn.classList.toggle("is-favorited", data.favorited);
                btn.setAttribute("aria-pressed", String(data.favorited));
            });

            // Un-favoriting from inside the "My Favorites" list itself should
            // drop that card right away instead of leaving a favorited-off
            // heart sitting in a list that's now lying about its contents;
            // window.smartlibRefreshMyBooks (defined where that list is
            // rendered) re-fetches so counts/pagination stay correct too.
            if (!data.favorited && button.closest("#my-books-content") && typeof window.smartlibRefreshMyBooks === "function") {
                window.smartlibRefreshMyBooks();
            }
        } catch (err) {
            document.querySelectorAll(`.fav-heart-btn[data-book-id="${bookId}"]`).forEach(btn => {
                btn.classList.toggle("is-favorited", wasFavorited);
                btn.setAttribute("aria-pressed", String(wasFavorited));
            });
        } finally {
            button.dataset.favBusy = "";
        }
    };

    document.addEventListener("click", e => {
        const heartBtn = e.target.closest(".fav-heart-btn");
        if (heartBtn) {
            e.preventDefault();
            e.stopPropagation();
            toggleFavorite(heartBtn);
            return;
        }

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

    const RECEIPT_STATUS_ICONS = {
        success: '<svg viewBox="0 0 24 24" role="presentation"><path d="M5 12.5l4.5 4.5L19 7"></path></svg>',
        warning: '<svg viewBox="0 0 24 24" role="presentation"><path d="M12 3.5l9.2 16.2H2.8z"></path><path d="M12 9.5v4.2"></path><path d="M12 16.6v.1"></path></svg>',
        error: '<svg viewBox="0 0 24 24" role="presentation"><circle cx="12" cy="12" r="9"></circle><path d="M12 7.5v5.5"></path><path d="M12 16.4v.1"></path></svg>'
    };

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
                    <div class="receipt-status-icon" aria-hidden="true">${RECEIPT_STATUS_ICONS.success}</div>
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

        if (ui.icon) ui.icon.innerHTML = RECEIPT_STATUS_ICONS[normalizedType] || RECEIPT_STATUS_ICONS.error;
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

            <div class="receipt-section-title">
                <span class="receipt-section-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" role="presentation">
                        <circle cx="12" cy="8" r="3.2"></circle>
                        <path d="M6.2 18.2c.9-2.7 3-4.2 5.8-4.2s4.9 1.5 5.8 4.2"></path>
                    </svg>
                </span>
                Student Information
            </div>
            <div class="receipt-grid">
                <span>Name:</span><span>${escapeHtml(data.userName)}</span>
                <span>ID Number:</span><span>${escapeHtml(data.userId)}</span>
                <span>Course:</span><span>${escapeHtml(data.course)}</span>
            </div>

            <div class="receipt-divider"></div>

            <div class="receipt-section-title">
                <span class="receipt-section-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" role="presentation">
                        <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path>
                        <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path>
                    </svg>
                </span>
                Book Information
            </div>
            <div class="receipt-grid">
                <span>Title:</span><span>${escapeHtml(data.title)}</span>
                <span>Author:</span><span>${escapeHtml(data.author)}</span>
                <span>Accession Number:</span><span>${escapeHtml(data.accession)}</span>
                <span>ISBN:</span><span>${escapeHtml(data.isbn)}</span>
            </div>

            <div class="receipt-divider"></div>

            <div class="receipt-section-title">
                <span class="receipt-section-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" role="presentation">
                        <circle cx="12" cy="12" r="8.5"></circle>
                        <path d="M12 7.5V12l3.2 2"></path>
                    </svg>
                </span>
                Loan Details
            </div>
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

    // Checkout confirmation: a tap on "Check Out" used to fire the borrow
    // request immediately. Route it through an explicit confirm step first
    // (mirrors the logout-confirm pattern below) -- the actual POST to
    // checkout_book.php only fires once the user confirms.
    const checkoutConfirmModal = document.getElementById("checkout-confirm-modal");
    const checkoutConfirmBackdrop = checkoutConfirmModal?.querySelector(".modal-backdrop") || null;
    const checkoutConfirmCancelBtn = document.getElementById("checkout-confirm-cancel-btn");
    const checkoutConfirmSubmitBtn = document.getElementById("checkout-confirm-submit-btn");
    const checkoutConfirmTitleEl = document.getElementById("checkout-confirm-book-title");

    let pendingCheckout = null;

    const closeCheckoutConfirm = () => {
        checkoutConfirmModal?.classList.add("hidden");
        pendingCheckout = null;
    };

    const runCheckout = async ({ modalRoot, bookId, checkoutBtn }) => {
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
    };

    document.addEventListener("click", (e) => {
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

        if (!checkoutConfirmModal) {
            runCheckout({ modalRoot, bookId, checkoutBtn });
            return;
        }

        pendingCheckout = { modalRoot, bookId, checkoutBtn };

        const title = modalRoot?.dataset.title
            || modalRoot?.querySelector(".book-modal-title")?.textContent.trim()
            || "this book";
        if (checkoutConfirmTitleEl) checkoutConfirmTitleEl.textContent = title;

        checkoutConfirmModal.classList.remove("hidden");
    });

    checkoutConfirmCancelBtn?.addEventListener("click", closeCheckoutConfirm);
    checkoutConfirmBackdrop?.addEventListener("click", closeCheckoutConfirm);

    checkoutConfirmSubmitBtn?.addEventListener("click", () => {
        if (!pendingCheckout) return;
        const job = pendingCheckout;
        checkoutConfirmModal?.classList.add("hidden");
        pendingCheckout = null;
        runCheckout(job);
    });

    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && checkoutConfirmModal && !checkoutConfirmModal.classList.contains("hidden")) {
            closeCheckoutConfirm();
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

            // Kiosk Polish v154: rotating used to rewrite each slot's image/dataset
            // immediately, so new cover art popped into place the instant the
            // size/position transition finished. `animate` (true from
            // rotateNext/rotatePrev, false for the very first paint) briefly
            // toggles a .carousel-swap class -- a quick scale+fade out -- before
            // applying the new content and fading back in, so a rotate reads as a
            // deliberate shuffle instead of an abrupt swap. See kiosk.css for the
            // .carousel-swap rule and the reduced-motion override.
            const applySequence = () => {
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

            const render = (animate = false) => {
                if (!animate) {
                    applySequence();
                    return;
                }

                images.forEach(img => img.classList.add("carousel-swap"));

                window.setTimeout(() => {
                    applySequence();
                    // Force a reflow so removing the class right after re-triggers
                    // the fade/scale-in transition instead of it being coalesced
                    // away with the class addition above.
                    void booksWrap.offsetWidth;
                    images.forEach(img => img.classList.remove("carousel-swap"));
                }, 140);
            };

            const rotateNext = () => {
                sequence.push(sequence.shift());
                render(true);
            };

            const rotatePrev = () => {
                sequence.unshift(sequence.pop());
                render(true);
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
            let pointerCaptured = false;
            const CAPTURE_THRESHOLD = 5;

            booksWrap.addEventListener("pointerdown", e => {
                activePointerId = e.pointerId;
                startX = e.clientX;
                lastX = e.clientX;
                swiped = false;
                pointerCaptured = false;
                booksWrap.classList.add("dragging");
                // Pointer capture is deferred until real drag movement is seen (see
                // pointermove below). Capturing immediately on pointerdown retargets
                // the resulting mouseup/click event to booksWrap instead of the book
                // cover the user actually clicked, which silently broke opening the
                // book detail modal from a plain (non-drag) click.
            });

            booksWrap.addEventListener("pointermove", e => {
                if (activePointerId !== e.pointerId) return;
                lastX = e.clientX;

                if (!pointerCaptured && Math.abs(lastX - startX) >= CAPTURE_THRESHOLD) {
                    pointerCaptured = true;
                    if (booksWrap.setPointerCapture) {
                        booksWrap.setPointerCapture(e.pointerId);
                    }
                }
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

                if (pointerCaptured && booksWrap.releasePointerCapture) {
                    try { booksWrap.releasePointerCapture(e.pointerId); } catch (_) {}
                }

                activePointerId = null;
                pointerCaptured = false;

                if (swiped) {
                    setTimeout(() => { swiped = false; }, 0);
                }
            };

            booksWrap.addEventListener("pointerup", endDrag);
            booksWrap.addEventListener("pointercancel", e => {
                if (activePointerId !== e.pointerId) return;
                booksWrap.classList.remove("dragging");
                if (pointerCaptured && booksWrap.releasePointerCapture) {
                    try { booksWrap.releasePointerCapture(e.pointerId); } catch (_) {}
                }
                activePointerId = null;
                pointerCaptured = false;
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

    const LOC_ICONS = {
        section: '<svg viewBox="0 0 24 24" role="presentation"><rect x="3.5" y="3.5" width="7.5" height="7.5" rx="1.3"></rect><rect x="13" y="3.5" width="7.5" height="7.5" rx="1.3"></rect><rect x="3.5" y="13" width="7.5" height="7.5" rx="1.3"></rect><rect x="13" y="13" width="7.5" height="7.5" rx="1.3"></rect></svg>',
        shelf: '<svg viewBox="0 0 24 24" role="presentation"><path d="M4 20V4M20 20V4M4 20h16"></path><path d="M8 4v13M12 4v16M16 4v13"></path></svg>',
        row: '<svg viewBox="0 0 24 24" role="presentation"><path d="M4 7h16M4 12h16M4 17h16"></path></svg>',
        position: '<svg viewBox="0 0 24 24" role="presentation"><path d="M12 3v18"></path><path d="M7.5 7.5L12 3l4.5 4.5"></path><path d="M7.5 16.5L12 21l4.5-4.5"></path></svg>',
        availability: '<svg viewBox="0 0 24 24" role="presentation"><circle cx="12" cy="12" r="7.5"></circle><path d="M12 8.3v4.2l2.7 1.6"></path></svg>',
        guide: '<svg viewBox="0 0 24 24" role="presentation"><path d="M9 5l7 7-7 7"></path></svg>',
        check: '<svg viewBox="0 0 24 24" role="presentation"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg>',
        unavailable: '<svg viewBox="0 0 24 24" role="presentation"><circle cx="12" cy="12" r="8"></circle><path d="M8 12h8"></path></svg>'
    };

    const getStockState = (availability, status) => {
        const text = String(availability || "").toLowerCase();
        const s = String(status || "").toLowerCase();
        if (s === "borrowed" || s === "unavailable" || text.startsWith("0 of")) {
            return { label: "Checked Out", cls: "warn", icon: LOC_ICONS.unavailable };
        }
        return { label: "In Stock", cls: "ok", icon: LOC_ICONS.check };
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
                    <span class="loc-guide-icon">${LOC_ICONS.guide}</span>
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
                <div class="loc-meta-card"><div class="loc-meta-label"><span class="loc-meta-icon">${LOC_ICONS.section}</span>Section</div><div class="loc-meta-value">${escapeHtml(section)}</div></div>
                <div class="loc-meta-card"><div class="loc-meta-label"><span class="loc-meta-icon">${LOC_ICONS.shelf}</span>Shelf</div><div class="loc-meta-value">${escapeHtml(shelf)}</div></div>
                <div class="loc-meta-card"><div class="loc-meta-label"><span class="loc-meta-icon">${LOC_ICONS.row}</span>Row</div><div class="loc-meta-value">${escapeHtml(toTitle(row))}</div></div>
                <div class="loc-meta-card"><div class="loc-meta-label"><span class="loc-meta-icon">${LOC_ICONS.position}</span>Position</div><div class="loc-meta-value">${escapeHtml(toTitle(position))}</div></div>
            </div>

            <div class="loc-availability-card">
                <div>
                    <div class="loc-meta-label"><span class="loc-meta-icon">${LOC_ICONS.availability}</span>Availability</div>
                    <div class="loc-meta-value">${escapeHtml(availability)}</div>
                </div>
                <span class="loc-stock-chip ${stock.cls}"><span class="loc-stock-chip-icon">${stock.icon}</span>${escapeHtml(stock.label)}</span>
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
    const myBooksTitleEl = myBooksModal?.querySelector(".my-books-title") || null;
    const myBooksSubtitleEl = myBooksModal?.querySelector(".my-books-subtitle") || null;
    const myBooksHeadingIconEl = document.getElementById("my-books-heading-icon");

    // Same two icons already used elsewhere for these exact concepts (the
    // account-menu "My Books" item and the heart used for every favorite
    // heart/section heading in the app) -- swapped into the modal's title
    // badge by mode instead of a third icon being invented for this spot.
    const MY_BOOKS_HEADING_ICONS = {
        books: '<svg viewBox="0 0 24 24" role="presentation"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg>',
        favorites: '<svg viewBox="0 0 24 24" role="presentation"><path d="M12 20.2s-7.2-4.6-10-9.2C0.3 8 1.4 4.6 4.6 3.5c2.1-0.7 4.3 0 5.6 1.7L12 7l1.8-1.8c1.3-1.7 3.5-2.4 5.6-1.7 3.2 1.1 4.3 4.5 2.6 7.5-2.8 4.6-10 9.2-10 9.2z"></path></svg>'
    };

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

    const logoutForm = document.getElementById("account-logout-form");
    const logoutConfirmModal = document.getElementById("logout-confirm-modal");
    const logoutConfirmBackdrop = logoutConfirmModal?.querySelector(".modal-backdrop") || null;
    const logoutCancelBtn = document.getElementById("logout-confirm-cancel-btn");
    const logoutConfirmBtn = document.getElementById("logout-confirm-submit-btn");

    const openLogoutConfirm = () => {
        closeMenu();
        logoutConfirmModal?.classList.remove("hidden");
    };

    const closeLogoutConfirm = () => {
        logoutConfirmModal?.classList.add("hidden");
    };

    // Students were signing themselves out with a single accidental tap on
    // "Log Out" inside the account menu. Intercept the form's submit and
    // route it through a confirm step instead; the actual POST to
    // logout.php only fires once they explicitly confirm.
    logoutForm?.addEventListener("submit", (e) => {
        e.preventDefault();
        openLogoutConfirm();
    });

    logoutCancelBtn?.addEventListener("click", closeLogoutConfirm);
    logoutConfirmBackdrop?.addEventListener("click", closeLogoutConfirm);

    logoutConfirmBtn?.addEventListener("click", () => {
        // HTMLFormElement.submit() does not re-dispatch a "submit" event,
        // so this bypasses the interceptor above instead of looping back
        // into the confirm dialog.
        logoutForm?.submit();
    });

    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && logoutConfirmModal && !logoutConfirmModal.classList.contains("hidden")) {
            closeLogoutConfirm();
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

    // Small, fixed per-section page sizes chosen so the three account
    // sections (Current Loans / Missing Books / Returned History) fit
    // inside the modal's max-height without needing to scroll in the
    // common case. myBooksPageState persists across re-renders of the
    // SAME fetched payload (paging clicks re-render from the cached
    // payload below instead of re-fetching), and is reset to page 1
    // every time the modal is freshly opened via openMyBooks().
    const MY_BOOKS_PAGE_SIZE = { active: 2, missing: 3, history: 5, favorites: 4 };
    let myBooksPageState = { active: 1, missing: 1, history: 1, favorites: 1 };
    let myBooksLastPayload = null;

    const paginateSlice = (items, section) => {
        const perPage = MY_BOOKS_PAGE_SIZE[section];
        const totalPages = Math.max(1, Math.ceil(items.length / perPage));
        myBooksPageState[section] = Math.min(Math.max(1, myBooksPageState[section] || 1), totalPages);
        const page = myBooksPageState[section];
        const pageItems = items.slice((page - 1) * perPage, page * perPage);
        return { pageItems, page, totalPages };
    };

    const renderPagerControls = (section, page, totalPages) => {
        if (totalPages <= 1) return '';
        return `
            <div class="my-books-pager" data-pager-section="${section}">
                <button type="button" class="my-books-pager-btn" data-pager-action="prev" data-pager-section="${section}" ${page <= 1 ? 'disabled' : ''} aria-label="Previous page">
                    <svg viewBox="0 0 24 24" role="presentation"><path d="M15 6l-6 6 6 6"></path></svg>
                </button>
                <span class="my-books-pager-info">Page ${page} of ${totalPages}</span>
                <button type="button" class="my-books-pager-btn" data-pager-action="next" data-pager-section="${section}" ${page >= totalPages ? 'disabled' : ''} aria-label="Next page">
                    <svg viewBox="0 0 24 24" role="presentation"><path d="M9 6l6 6-6 6"></path></svg>
                </button>
            </div>
        `;
    };

    // Small line icon per loan status, matching this app's established
    // "premium chip" language (id/role/program/fine chips, favorite stock
    // chip) where a status pill carries an icon rather than text alone.
    // Same three states statusLabel() already recognizes as visually
    // distinct (Active/Borrowed/Returned all read as the default "good"
    // state here), just rendered as a glyph instead of only a color.
    const statusIcon = (status) => {
        const s = String(status || "").toLowerCase();
        if (s === "overdue") {
            return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2 20h20L12 3Z"></path><path d="M12 9v5"></path><path d="M12 17h.01"></path></svg>';
        }
        if (s === "missing") {
            return '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M9 9l6 6M15 9l-6 6"></path></svg>';
        }
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z"></path><path d="m8.5 12.5 2.5 2.5 5-5"></path></svg>';
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
                <span class="my-book-status ${esc(String(book.status || '').toLowerCase())}">
                    ${statusIcon(book.status)}
                    ${esc(statusLabel(book.status))}
                </span>
                ${book.can_renew ? `<button type="button" class="my-book-renew-btn" data-record-id="${esc(book.record_id)}">Renew</button>` : `<small><svg class="my-book-note-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 10.5v5.5M12 7.6v.01"></path></svg>${esc(book.renew_note || '')}</small>`}
            </div>
        </div>
    `;

    // Kiosk Polish v159: favorites got their own dedicated meta row instead
    // of reusing .my-book-details/.my-book-due-note (the plain bold-colored
    // text line the active-loans cards use for "3 days left" etc.) -- a
    // "Saved <date>" caption plus a proper availability chip (solid
    // gradient + dot, the same recipe as the book-detail modal's location
    // stock chip) reads as a finished, considered card instead of a status
    // label borrowed from a different context. renderBookCard (My Books)
    // is untouched -- only this favorites card changes.
    const renderFavoriteCard = (book) => `
        <div class="my-book-item favorite-item">
            <img src="${esc(book.cover || 'assets/covers/default.jpg')}" alt="Book cover" class="my-book-cover">
            <div class="my-book-meta">
                <h4>${esc(book.title || 'Untitled')}</h4>
                <p class="my-book-author">${esc(book.author || 'Unknown Author')}</p>
                <div class="favorite-meta-row">
                    <span class="favorite-saved-label">
                        <svg class="favorite-saved-icon" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M7 3v4M17 3v4M4.5 9h15M5.5 6h13a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1h-13a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1Z"></path>
                        </svg>
                        Saved ${esc(book.added_at_label || 'N/A')}
                    </span>
                    <span class="favorite-stock-chip ${book.is_available ? 'ok' : 'warn'}">
                        <span class="favorite-stock-dot" aria-hidden="true"></span>
                        ${esc(book.availability_label || '')}
                    </span>
                </div>
            </div>
            <div class="my-book-actions">
                <button type="button" class="fav-heart-btn is-favorited" data-book-id="${esc(book.book_id)}" aria-pressed="true" aria-label="Remove from favorites">
                    <svg class="fav-heart-icon" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M12 20.2s-7.2-4.6-10-9.2C0.3 8 1.4 4.6 4.6 3.5c2.1-0.7 4.3 0 5.6 1.7L12 7l1.8-1.8c1.3-1.7 3.5-2.4 5.6-1.7 3.2 1.1 4.3 4.5 2.6 7.5-2.8 4.6-10 9.2-10 9.2z"></path>
                    </svg>
                </button>
            </div>
        </div>
    `;

    const renderHistoryRows = (books = []) => {
        if (!Array.isArray(books) || books.length === 0) {
            return '<div class="my-books-empty slim"><strong>No returned books yet.</strong><p>Your completed loans will appear here.</p></div>';
        }

        return `
            <div class="my-history-table">
                ${books.map((book) => `
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

    const renderMyBooks = (payload = {}, mode = "books") => {
        if (!myBooksContent) return;

        // Cache the fetched payload so pagination clicks (below) can
        // re-render a different page instantly from memory instead of
        // re-fetching fetch_my_checked_out.php every time.
        myBooksLastPayload = payload;

        const legacyBooks = Array.isArray(payload) ? payload : null;
        const account = legacyBooks ? { active_books: legacyBooks, books: legacyBooks } : payload;
        const summary = account?.summary || {};
        const borrower = account?.borrower || {};
        const activeBooks = Array.isArray(account?.active_books) ? account.active_books : [];
        const favoriteBooks = Array.isArray(account?.favorite_books) ? account.favorite_books : [];

        // Missing Books and Returned History used to be paginated here too
        // (paginateSlice(missingBooks, "missing") / (returnedBooks,
        // "history")) back when "My Books" was one combined view. Neither
        // is rendered any more -- see the comment further down where the
        // two modes branch -- so there's nothing left to paginate for them.
        const activePage = paginateSlice(activeBooks, "active");
        const favoritesPage = paginateSlice(favoriteBooks, "favorites");

        // Icon-and-color-coded meta facts shared by BOTH account-modal
        // headers (My Books and My Favorites) -- each chip gets its own
        // small line icon (ID badge / graduation cap / open book) and its
        // own soft accent tint instead of a flat neutral pill, so the row
        // reads as distinct facts rather than one data dump.
        const headChips = [
            borrower.user_number ? `
                <span class="head-chip id">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2.5"></rect><circle cx="9" cy="11" r="1.8"></circle><path d="M6 16c.7-1.6 2-2.4 3-2.4s2.3.8 3 2.4M14 9.5h4M14 13h4"></path></svg>
                    ID ${esc(borrower.user_number)}
                </span>` : '',
            borrower.role ? `
                <span class="head-chip role">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4 2 9l10 5 8-4v6"></path><path d="M6 11.5V16c0 1.4 2.7 3 6 3s6-1.6 6-3v-4.5"></path></svg>
                    ${esc(String(borrower.role).charAt(0).toUpperCase() + String(borrower.role).slice(1))}
                </span>` : '',
            borrower.program ? `
                <span class="head-chip program">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5C4 4.7 4.7 4 5.5 4H12v16H5.5c-.8 0-1.5-.7-1.5-1.5z"></path><path d="M20 5.5c0-.8-.7-1.5-1.5-1.5H12v16h6.5c.8 0 1.5-.7 1.5-1.5z"></path><path d="M12 4v16"></path></svg>
                    ${esc(borrower.program)}
                </span>` : ''
        ].filter(Boolean).join("");

        // Fourth chip, My Books only: the account's active-fine status,
        // recast from the old boxed "Active Fine" mini-card into the same
        // pill language as the three facts above -- green + checkmark when
        // clear, amber/red + warning triangle when something's owed, so the
        // header reads as one lively row instead of a card-within-a-card.
        const hasActiveFine = Number(summary.active_fines || 0) > 0;
        const fineChip = `
            <span class="head-chip fine ${hasActiveFine ? 'warn' : 'ok'}">
                <svg viewBox="0 0 24 24" aria-hidden="true">${hasActiveFine
                    ? '<path d="M12 3 2 20h20L12 3Z"></path><path d="M12 9v5"></path><path d="M12 17h.01"></path>'
                    : '<path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z"></path><path d="m8.5 12.5 2.5 2.5 5-5"></path>'}</svg>
                ${hasActiveFine ? `${money(summary.active_fines)} due` : 'No active fees'}
            </span>`;

        const favoritesSection = `
            <section class="my-account-section favorites-section">
                <div class="my-account-section-head">
                    <h5>
                        <svg class="my-account-section-heart" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M12 20.2s-7.2-4.6-10-9.2C0.3 8 1.4 4.6 4.6 3.5c2.1-0.7 4.3 0 5.6 1.7L12 7l1.8-1.8c1.3-1.7 3.5-2.4 5.6-1.7 3.2 1.1 4.3 4.5 2.6 7.5-2.8 4.6-10 9.2-10 9.2z"></path>
                        </svg>
                        My Favorites
                    </h5>
                    <span>${favoriteBooks.length} saved</span>
                </div>
                ${favoriteBooks.length ? favoritesPage.pageItems.map((book) => renderFavoriteCard(book)).join("") : '<div class="my-books-empty slim"><strong>No favorites yet.</strong><p>Tap the heart on any book to save it here.</p></div>'}
                ${renderPagerControls("favorites", favoritesPage.page, favoritesPage.totalPages)}
            </section>
        `;

        // Both "My Books" and "My Favorites" now share the exact same
        // avatar-and-chips header component (monogram avatar, no boxed
        // card, icon-tipped meta chips) -- My Books' chip row just adds a
        // fourth "fine status" chip on the end since that's account info
        // only it carries. They're still two separate entries in the
        // account dropdown, each opening this same modal/content area but
        // rendering only its own slice: My Books is scoped to books
        // currently borrowed (active + overdue loans) and nothing else,
        // My Favorites to the saved-books list and nothing else. Missing
        // Books and Returned History (previously shown alongside Current
        // Loans in one combined "My Books" view) aren't rendered by either
        // mode any more -- there's currently no menu entry that surfaces
        // them. If those need a home again, they'd want their own entry
        // (or a "My Books" that keeps its 3-section combined layout) rather
        // than being folded back into this borrowed-only view.
        // Plain profile-silhouette glyph, not a name-derived monogram -- it
        // reads as "account" immediately (same idea as a default Apple ID
        // avatar) without depending on the borrower's name parsing cleanly
        // into two initials, and it's the same icon already used in the
        // "Borrower" kicker line just to its right.
        const accountHead = (chipsHtml) => `
            <section class="my-account-head head-premium">
                <div class="head-avatar" aria-hidden="true">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"></path><path d="M4.5 20a7.5 7.5 0 0 1 15 0"></path></svg>
                </div>
                <div class="head-info">
                    <p class="my-account-kicker">
                        <svg class="head-kicker-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"></path><path d="M4.5 20a7.5 7.5 0 0 1 15 0"></path></svg>
                        Borrower
                    </p>
                    <h4>${esc(borrower.name || 'Library Account')}</h4>
                    <div class="my-account-meta head-meta">${chipsHtml}</div>
                </div>
            </section>
        `;

        if (mode === "favorites") {
            myBooksContent.innerHTML = `
                ${accountHead(headChips)}
                ${favoritesSection}
            `;

            forceMyBooksLayout();
            return;
        }

        myBooksContent.innerHTML = `
            ${accountHead(headChips + fineChip)}

            <section class="my-account-section">
                <div class="my-account-section-head">
                    <h5>Borrowed Books</h5>
                    <span>${activeBooks.length} active</span>
                </div>
                ${activeBooks.length ? activePage.pageItems.map((book) => renderBookCard(book)).join("") : '<div class="my-books-empty slim"><strong>No borrowed books right now.</strong><p>Borrow a book from the catalog and it will show up here.</p></div>'}
                ${renderPagerControls("active", activePage.page, activePage.totalPages)}
            </section>
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
    // "My Books" and "My Favorites" are separate entries in the account
    // dropdown but share this one modal/content area and one fetch --
    // myBooksCurrentMode remembers which slice is currently on screen so
    // pagination clicks and the un-favorite-triggered refresh (both below)
    // re-render the same view instead of silently jumping back to "books".
    let myBooksCurrentMode = "books";

    const openMyBooks = async ({ mode = "books" } = {}) => {
        closeMenu();

        if (!myBooksModal || !myBooksContent) return;

        myBooksCurrentMode = mode;
        if (myBooksTitleEl) {
            myBooksTitleEl.textContent = mode === "favorites" ? "My Favorites" : "My Books";
        }
        if (myBooksSubtitleEl) {
            myBooksSubtitleEl.textContent = mode === "favorites"
                ? "Books you've saved to read later"
                : "Everything you currently have borrowed";
        }
        if (myBooksHeadingIconEl) {
            myBooksHeadingIconEl.classList.toggle("favorites", mode === "favorites");
            myBooksHeadingIconEl.classList.toggle("books", mode !== "favorites");
            myBooksHeadingIconEl.innerHTML = MY_BOOKS_HEADING_ICONS[mode === "favorites" ? "favorites" : "books"];
        }

        // Always start a freshly-opened account modal on page 1 of every
        // section, even if it was left mid-pagination the last time it
        // was closed.
        myBooksPageState = { active: 1, missing: 1, history: 1, favorites: 1 };

        myBooksContent.innerHTML = '<div class="my-books-loading">Loading your books...</div>';
        forceMyBooksLayout();
        myBooksModal.classList.remove("hidden");

        try {
            const res = await fetch("fetch_my_checked_out.php", { credentials: "same-origin" });
            const data = await res.json();

            if (!res.ok || data.status !== "success") {
                throw new Error(data.message || "Unable to load books.");
            }

            renderMyBooks(data, myBooksCurrentMode);
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

    // Exposed so toggleFavorite (defined in the earlier DOMContentLoaded
    // block that owns the search grid / book modal click delegate) can
    // silently resync this modal's "My Favorites" section -- counts and
    // pagination included -- right after a heart is un-favorited from
    // inside the list itself, without the two blocks needing to share a
    // closure. Re-opens whichever view (books/favorites) is currently up
    // instead of always defaulting back to "books".
    window.smartlibRefreshMyBooks = () => openMyBooks({ mode: myBooksCurrentMode });

    myBooksContent?.addEventListener("click", (e) => {
        const renewButton = e.target.closest(".my-book-renew-btn");
        if (renewButton) {
            e.preventDefault();
            renewLoan(renewButton.dataset.recordId, renewButton);
            return;
        }

        const pagerButton = e.target.closest(".my-books-pager-btn");
        if (pagerButton) {
            e.preventDefault();
            if (pagerButton.disabled) return;
            const section = pagerButton.dataset.pagerSection;
            const action = pagerButton.dataset.pagerAction;
            if (section && myBooksPageState[section] !== undefined && myBooksLastPayload) {
                myBooksPageState[section] += action === "next" ? 1 : -1;
                renderMyBooks(myBooksLastPayload, myBooksCurrentMode);
            }
        }
    });
    openMyBooksBtn?.addEventListener("click", (e) => {
        e.preventDefault();
        openMyBooks({ mode: "books" });
    });

    document.getElementById("open-my-favorites")?.addEventListener("click", (e) => {
        e.preventDefault();
        openMyBooks({ mode: "favorites" });
    });

    const closeMyBooks = () => {
        myBooksModal?.classList.add("hidden");
    };

    closeMyBooksBtn?.addEventListener("click", closeMyBooks);
    myBooksBackdrop?.addEventListener("click", closeMyBooks);
});



























