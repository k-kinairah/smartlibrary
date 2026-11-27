/**
 * JS for admin/manage_books.php (add/edit book modals, accession field
 * generation, delete confirmation). Moved out of an inline <script> block
 * into its own file so it can be cached by the browser and edited without
 * touching the PHP.
 */

// Add Book window: the element that opened it gets focus back on close.
let bookModalOpener = null;

function openBookModal() {
    const modal = document.getElementById('bookModal');
    if (!modal) return;
    if (document.activeElement && !modal.contains(document.activeElement)) {
        bookModalOpener = document.activeElement;
    }
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    focusAddBookTitle();
    refreshAddBookCoverPreview(true);
}

function closeBookModal() {
    const modal = document.getElementById('bookModal');
    if (!modal) return;
    closeAllProgramPickers();
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    if (bookModalOpener && typeof bookModalOpener.focus === 'function' && document.contains(bookModalOpener)) {
        bookModalOpener.focus();
    }
    bookModalOpener = null;
}

// True when el is a select whose popup is open, or sits inside one.
// ':open' is unknown to older browsers: matches() then throws, and their
// native popups do not pass Escape to the page anyway.
function addBookSelectIsOpen(el) {
    const select = el && el.closest ? el.closest('select') : null;
    if (!select) return false;
    try {
        return select.matches(':open');
    } catch (error) {
        return false;
    }
}

function focusAddBookTitle() {
    const title = document.getElementById('abTitleInput');
    if (!title) return;
    // After the window is displayed, so the focus actually lands.
    window.requestAnimationFrame(function () {
        title.focus({ preventScroll: true });
    });
}

// Escape closes the Add Book window (the delete confirmation has its own
// Escape handler further down and is never open at the same time). With a
// dropdown open, Escape only closes the dropdown: the select (or the
// option focused in its popup) still matches :open when the key arrives.
// Tab and Shift+Tab wrap around inside the window instead of moving to
// the page behind it.
document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape' && event.key !== 'Tab') return;
    const modal = document.getElementById('bookModal');
    if (!modal || !modal.classList.contains('show')) return;
    if (event.key === 'Escape') {
        if (event.defaultPrevented || addBookSelectIsOpen(document.activeElement)) return;
        event.preventDefault();
        closeBookModal();
        return;
    }
    const focusable = Array.from(modal.querySelectorAll(
        'button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'
    )).filter(function (el) {
        return el.offsetParent !== null && !(el.tagName !== 'SELECT' && el.closest('select'));
    });
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && (document.activeElement === first || !modal.contains(document.activeElement))) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && (document.activeElement === last || !modal.contains(document.activeElement))) {
        event.preventDefault();
        first.focus();
    }
});

// Live cover preview: the same generated cover book_cover.php serves, drawn
// by book_cover_preview.php from the title, author and category typed so
// far. 250ms debounce; a newer request aborts the one in flight; if a
// request fails the last good image stays. Never blocks typing or saving.
const addBookCoverImg = document.getElementById('abCoverImg');
let addBookCoverTimer = null;
let addBookCoverAbort = null;
let addBookCoverObjectUrl = null;
let addBookCoverLastQuery = null;

function addBookCoverQuery() {
    const form = document.getElementById('addBookForm');
    if (!form) return null;
    const read = function (name) {
        const el = form.elements.namedItem(name);
        return el && typeof el.value === 'string' ? el.value.trim().slice(0, 255) : '';
    };
    const params = new URLSearchParams();
    params.set('title', read('title'));
    params.set('author', read('author'));
    params.set('category_id', read('category_id'));
    return params.toString();
}

function refreshAddBookCoverPreview(immediate) {
    if (!addBookCoverImg || !window.fetch) return;
    clearTimeout(addBookCoverTimer);
    const run = function () {
        const query = addBookCoverQuery();
        if (query === null) return;
        if (query === addBookCoverLastQuery) return;
        if (addBookCoverAbort) addBookCoverAbort.abort();
        const controller = window.AbortController ? new AbortController() : null;
        addBookCoverAbort = controller;
        fetch('book_cover_preview.php?' + query, {
            credentials: 'same-origin',
            cache: 'no-store',
            signal: controller ? controller.signal : undefined
        }).then(function (response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.blob();
        }).then(function (blob) {
            if (controller && controller.signal.aborted) return;
            const url = URL.createObjectURL(blob);
            addBookCoverImg.src = url;
            if (addBookCoverObjectUrl) URL.revokeObjectURL(addBookCoverObjectUrl);
            addBookCoverObjectUrl = url;
            addBookCoverLastQuery = query;
        }).catch(function () {
            // Keep the last good image; the next change tries again.
        }).finally(function () {
            if (addBookCoverAbort === controller) addBookCoverAbort = null;
        });
    };
    if (immediate) {
        run();
    } else {
        addBookCoverTimer = setTimeout(run, 250);
    }
}

(function () {
    const form = document.getElementById('addBookForm');
    if (!form) return;
    ['title', 'author', 'category_id'].forEach(function (name) {
        const el = form.elements.namedItem(name);
        if (!el) return;
        el.addEventListener('input', function () { refreshAddBookCoverPreview(false); });
        el.addEventListener('change', function () { refreshAddBookCoverPreview(false); });
    });

    // Copies stepper: the number stays a plain text input (copyCountInput);
    // the buttons step it within 1-200 (the server's range) and dispatch an
    // input event so the accession fields follow.
    form.querySelectorAll('.ab-step').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const input = document.getElementById('copyCountInput');
            if (!input) return;
            const step = Number.parseInt(btn.getAttribute('data-step') || '0', 10);
            const current = /^\d+$/.test(input.value.trim()) ? Number.parseInt(input.value.trim(), 10) : 0;
            let next = current + step;
            if (current < 1) next = 1;
            next = Math.min(200, Math.max(1, next));
            input.value = String(next);
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });

    // Reopened by the server after a failed save: same as opening it.
    const modal = document.getElementById('bookModal');
    if (modal && modal.classList.contains('show')) {
        focusAddBookTitle();
        refreshAddBookCoverPreview(true);
    }
})();

window.addEventListener('click', function (e) {
    if (e.target.id === 'bookModal') {
        closeBookModal();
    }
});

const copyCountInput = document.getElementById('copyCountInput');
const accessionInputsWrap = document.getElementById('accessionInputsWrap');

function normalizeCopyCount(value) {
    const text = String(value || '').trim();
    if (text === '') return null;
    if (!/^\d+$/.test(text)) return null;

    const parsed = Number.parseInt(text, 10);
    if (Number.isNaN(parsed) || parsed < 1 || parsed > 200) return null;

    return parsed;
}

function renderAccessionInputs() {
    if (!copyCountInput || !accessionInputsWrap) return;

    const targetCount = normalizeCopyCount(copyCountInput.value);

    const existingValues = Array.from(
        accessionInputsWrap.querySelectorAll('input[name="accession_numbers[]"]')
    ).map(function (input) {
        return input.value || '';
    });

    accessionInputsWrap.innerHTML = '';

    if (targetCount === null) {
        return;
    }

    for (let i = 0; i < targetCount; i += 1) {
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'ab-input';
        input.name = 'accession_numbers[]';
        input.placeholder = 'Accession #' + (i + 1);
        input.setAttribute('aria-label', 'Accession number, copy ' + (i + 1));
        input.value = existingValues[i] || '';
        accessionInputsWrap.appendChild(input);
    }
}

if (copyCountInput && accessionInputsWrap) {
    copyCountInput.addEventListener('input', renderAccessionInputs);
    copyCountInput.addEventListener('change', renderAccessionInputs);
    copyCountInput.addEventListener('blur', function () {
        const parsed = normalizeCopyCount(copyCountInput.value);
        if (parsed !== null) {
            copyCountInput.value = String(parsed);
        }
    });
    renderAccessionInputs();
}

const editCopyCountInput = document.getElementById('editCopyCountInput');
const editCurrentCopyCountInput = document.getElementById('editCurrentCopyCount');
const editNewAccessionInputsWrap = document.getElementById('editNewAccessionInputsWrap');
const editCopyAdjustmentHint = document.getElementById('editCopyAdjustmentHint');
const editNewCopiesBlock = document.getElementById('editNewCopiesBlock');

function getEditCurrentCopyCount() {
    if (!editCurrentCopyCountInput) {
        return 0;
    }
    const raw = String(editCurrentCopyCountInput.value || '').trim();
    if (!/^\d+$/.test(raw)) {
        return 0;
    }
    return Number.parseInt(raw, 10);
}

function setEditCopyHint(message) {
    if (!editCopyAdjustmentHint) return;
    editCopyAdjustmentHint.textContent = message;
}

function renderEditNewAccessionInputs() {
    if (!editCopyCountInput || !editNewAccessionInputsWrap) return;

    const desiredCount = normalizeCopyCount(editCopyCountInput.value);
    const currentCount = getEditCurrentCopyCount();

    const existingValues = Array.from(
        editNewAccessionInputsWrap.querySelectorAll('input[name="new_accession_numbers[]"]')
    ).map(function (input) {
        return input.value || '';
    });

    editNewAccessionInputsWrap.innerHTML = '';
    // The "New accession numbers" block shows only while new copies are added.
    const showNewBlock = function (show) {
        if (editNewCopiesBlock) editNewCopiesBlock.hidden = !show;
    };

    if (desiredCount === null) {
        showNewBlock(false);
        setEditCopyHint('Enter a number of copies from 1 to 200.');
        return;
    }

    const additionalCount = Math.max(0, desiredCount - currentCount);

    if (additionalCount === 0) {
        showNewBlock(false);
        if (desiredCount < currentCount) {
            setEditCopyHint('Lowering the count removes available copies first; borrowed, overdue and missing copies are kept.');
        } else {
            setEditCopyHint('');
        }
        return;
    }

    showNewBlock(true);
    setEditCopyHint('Add an accession number for ' + (additionalCount === 1 ? 'the new copy.' : 'each of the ' + additionalCount + ' new copies.'));

    for (let i = 0; i < additionalCount; i += 1) {
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'ab-input';
        input.name = 'new_accession_numbers[]';
        input.placeholder = 'New accession #' + (i + 1);
        input.setAttribute('aria-label', 'Accession number, new copy ' + (i + 1));
        input.value = existingValues[i] || '';
        editNewAccessionInputsWrap.appendChild(input);
    }
}

// Edit Book copies stepper: the same 1-200 steps as Add Book's, on the
// Edit form's own count field (the button's aria-controls).
document.querySelectorAll('#editBookForm .ab-step').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const input = document.getElementById(btn.getAttribute('aria-controls') || '');
        if (!input) return;
        const step = Number.parseInt(btn.getAttribute('data-step') || '0', 10);
        const current = /^\d+$/.test(input.value.trim()) ? Number.parseInt(input.value.trim(), 10) : 0;
        let next = current + step;
        if (current < 1) next = 1;
        next = Math.min(200, Math.max(1, next));
        input.value = String(next);
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
});

// Edit Book window: Tab and Shift+Tab wrap around inside it, as in Add
// Book. Escape is left as it was (it does not close this window: that
// would reload the list and drop unsaved edits); with a dropdown open,
// Escape still closes just the dropdown.
document.addEventListener('keydown', function (event) {
    if (event.key !== 'Tab') return;
    const modal = document.getElementById('editBookModal');
    if (!modal || !modal.classList.contains('show')) return;
    const focusable = Array.from(modal.querySelectorAll(
        'button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'
    )).filter(function (el) {
        return el.offsetParent !== null && !(el.tagName !== 'SELECT' && el.closest('select'));
    });
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && (document.activeElement === first || !modal.contains(document.activeElement))) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && (document.activeElement === last || !modal.contains(document.activeElement))) {
        event.preventDefault();
        first.focus();
    }
});

if (editCopyCountInput && editNewAccessionInputsWrap) {
    editCopyCountInput.addEventListener('input', renderEditNewAccessionInputs);
    editCopyCountInput.addEventListener('change', renderEditNewAccessionInputs);
    editCopyCountInput.addEventListener('blur', function () {
        const parsed = normalizeCopyCount(editCopyCountInput.value);
        if (parsed !== null) {
            editCopyCountInput.value = String(parsed);
        }
        renderEditNewAccessionInputs();
    });
    renderEditNewAccessionInputs();
}
// Edit Book cover picker: show the chosen file's name in the drop zone and
// highlight it while a file is dragged over (the transparent native input
// covers the zone, so the browser handles the click and the drop itself).
const editCoverFileInput = document.getElementById('editCoverFileInput');
const editCoverDrop = document.getElementById('editCoverDrop');
const editCoverFileName = document.getElementById('editCoverFileName');
const editCoverFileHint = document.getElementById('editCoverFileHint');
if (editCoverFileInput && editCoverDrop && editCoverFileName) {
    const defaultCoverLabel = editCoverFileName.textContent;
    const defaultCoverHint = editCoverFileHint ? editCoverFileHint.textContent : '';
    editCoverFileInput.addEventListener('change', function () {
        const file = editCoverFileInput.files && editCoverFileInput.files[0];
        editCoverFileName.textContent = file ? file.name : defaultCoverLabel;
        if (editCoverFileHint) {
            editCoverFileHint.textContent = file ? 'Ready · click to change' : defaultCoverHint;
        }
        editCoverDrop.classList.toggle('has-file', !!file);
    });
    ['dragenter', 'dragover'].forEach(function (evt) {
        editCoverFileInput.addEventListener(evt, function () {
            editCoverDrop.classList.add('is-dragging');
        });
    });
    ['dragleave', 'drop'].forEach(function (evt) {
        editCoverFileInput.addEventListener(evt, function () {
            editCoverDrop.classList.remove('is-dragging');
        });
    });
}

// Copies panel under a book row: the icon-only chevron (.rr-expand) shows or
// hides the row named by data-target. Only the hidden attribute, aria-expanded,
// aria-label and title change; the button's content (the chevron) never does.
// It is a real <button>, so Enter and Space work without extra handlers.
document.querySelectorAll('.rr-expand').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const rowId = btn.getAttribute('data-target');
        if (!rowId) return;

        const detailRow = document.getElementById(rowId);
        if (!detailRow) return;

        const willShow = detailRow.hasAttribute('hidden');
        if (willShow) {
            detailRow.removeAttribute('hidden');
        } else {
            detailRow.setAttribute('hidden', '');
        }
        const label = willShow ? 'Hide copies' : 'Show copies';
        btn.setAttribute('aria-expanded', willShow ? 'true' : 'false');
        btn.setAttribute('aria-label', label);
        btn.setAttribute('title', label);
    });
});

const deleteConfirmModal = document.getElementById('deleteBookConfirmModal');
const deleteConfirmText = document.getElementById('deleteBookConfirmText');
const deleteConfirmCancel = document.querySelector('.confirm-delete-cancel');
const deleteConfirmButton = document.querySelector('.confirm-delete-confirm');
let pendingDeleteForm = null;

function openDeleteBookConfirm(formEl) {
    if (!deleteConfirmModal || !deleteConfirmText || !deleteConfirmButton) return;

    pendingDeleteForm = formEl;
    const rawTitle = (formEl && formEl.dataset && formEl.dataset.bookTitle) ? formEl.dataset.bookTitle.trim() : '';
    const displayTitle = rawTitle !== '' ? '"' + rawTitle + '"' : 'this book';
    deleteConfirmText.textContent = 'Delete ' + displayTitle + ' from Manage Books?';

    deleteConfirmModal.classList.add('show');
    deleteConfirmModal.setAttribute('aria-hidden', 'false');
    deleteConfirmButton.focus();
}

function closeDeleteBookConfirm() {
    if (!deleteConfirmModal) return;
    deleteConfirmModal.classList.remove('show');
    deleteConfirmModal.setAttribute('aria-hidden', 'true');
    pendingDeleteForm = null;
}

document.querySelectorAll('.delete-book-form').forEach(function (formEl) {
    formEl.addEventListener('submit', function (event) {
        event.preventDefault();
        openDeleteBookConfirm(formEl);
    });
});

if (deleteConfirmCancel) {
    deleteConfirmCancel.addEventListener('click', function () {
        closeDeleteBookConfirm();
    });
}

if (deleteConfirmButton) {
    deleteConfirmButton.addEventListener('click', function () {
        if (!pendingDeleteForm) {
            closeDeleteBookConfirm();
            return;
        }

        const formToSubmit = pendingDeleteForm;
        pendingDeleteForm = null;
        deleteConfirmModal.classList.remove('show');
        deleteConfirmModal.setAttribute('aria-hidden', 'true');
        formToSubmit.submit();
    });
}

window.addEventListener('click', function (event) {
    if (event.target === deleteConfirmModal) {
        closeDeleteBookConfirm();
    }
});

window.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && deleteConfirmModal && deleteConfirmModal.classList.contains('show')) {
        closeDeleteBookConfirm();
    }
});

// ---------------------------------------------------------------------
// Program picker (Add Book and Edit Book): several programs per book.
// Markup: manage_books_render_program_picker() in
// admin/includes/manage_books_functions.php; look: pp- rules in admin.css
// and admin_palette_green.css.
//
// The checkboxes (program_ids[]) are real form fields, so without this
// script the list simply posts. With it, the list becomes a panel under a
// button. The order programs are ticked in is remembered: the first one
// ticked is the main program (hidden main_program_id); unticking it makes
// the next-earliest ticked program the main. "All programs" ticks the rest
// without choosing a main by itself.
// ---------------------------------------------------------------------
const programPickers = [];

function closeAllProgramPickers() {
    programPickers.forEach(function (picker) {
        picker.close(false);
    });
}

function initProgramPicker(root) {
    const field = root.querySelector('.pp-field');
    const panel = root.querySelector('.pp-panel');
    const mainInput = root.querySelector('.pp-main-input');
    const search = root.querySelector('.pp-search-input');
    const allCheck = root.querySelector('.pp-all-check');
    const list = root.querySelector('.pp-list');
    const rows = Array.from(root.querySelectorAll('.pp-program-row'));
    const checks = rows.map(function (row) { return row.querySelector('.pp-program-check'); });
    const emptyNote = root.querySelector('.pp-empty');
    const countNote = root.querySelector('.pp-selected-count');
    const summaryText = root.querySelector('.pp-summary-text');
    const summarySr = root.querySelector('.pp-summary-sr');
    const morePill = root.querySelector('.pp-more-pill');
    const clearBtn = root.querySelector('.pp-clear');
    const doneBtn = root.querySelector('.pp-done');
    if (!field || !panel || !mainInput || !list || !checks.length) return;

    const total = checks.length;
    const usePopover = typeof panel.showPopover === 'function';
    let isOpen = false;
    // The side the panel opened on; it stays there while it still fits
    // (e.g. when a search makes the list shorter).
    let openSide = null;

    root.classList.add('pp-js');
    if (usePopover) panel.setAttribute('popover', 'manual');

    const nameOf = function (check) {
        const name = check.parentElement.querySelector('.pp-name');
        return name ? name.textContent : '';
    };

    // Programs ticked one by one, in the order they were ticked (these can
    // become the main program). Programs ticked by "All programs" are
    // ticked but not in this list.
    let order = [];
    const startMain = mainInput.value;
    if (startMain) {
        order.push(startMain);
        checks.forEach(function (check) {
            if (check.checked && check.value !== startMain) order.push(check.value);
        });
    }

    function tickedChecks() {
        return checks.filter(function (check) { return check.checked; });
    }

    function syncMain() {
        const ticked = new Set(tickedChecks().map(function (check) { return check.value; }));
        order = order.filter(function (id) { return ticked.has(id); });
        mainInput.value = order.length ? order[0] : '';
    }

    function render() {
        const ticked = tickedChecks();
        const count = ticked.length;
        const main = mainInput.value;

        rows.forEach(function (row, i) {
            const check = checks[i];
            row.classList.toggle('is-checked', check.checked);
            const pill = row.querySelector('.pp-main-pill');
            if (pill) pill.hidden = !(main && check.value === main);
        });

        if (allCheck) {
            allCheck.checked = count === total;
            allCheck.indeterminate = count > 0 && count < total;
        }
        if (countNote) countNote.textContent = count + ' selected';

        let text = 'Select programs';
        let more = 0;
        if (count > 0 && !main && count === total) {
            text = 'All programs';
        } else if (count > 0 && main) {
            const mainCheck = checks.find(function (check) { return check.value === main; });
            text = mainCheck ? nameOf(mainCheck) : '';
            more = count - 1;
        } else if (count > 0) {
            const names = ticked.map(nameOf).sort(function (a, b) {
                return a.localeCompare(b, undefined, { sensitivity: 'base', numeric: true });
            });
            text = names[0];
            more = count - 1;
        }
        field.classList.toggle('is-empty', count === 0);
        if (summaryText) summaryText.textContent = text;
        if (summarySr) summarySr.textContent = more > 0 ? ', plus ' + more + ' more' : '';
        if (morePill) {
            morePill.hidden = more <= 0;
            morePill.textContent = '+' + more;
        }
    }

    checks.forEach(function (check) {
        check.addEventListener('change', function () {
            if (check.checked) {
                if (order.indexOf(check.value) === -1) order.push(check.value);
            } else {
                order = order.filter(function (id) { return id !== check.value; });
            }
            syncMain();
            render();
        });
    });

    if (allCheck) {
        allCheck.addEventListener('change', function () {
            const everything = tickedChecks().length === total;
            checks.forEach(function (check) { check.checked = !everything; });
            if (everything) order = [];
            syncMain();
            render();
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            checks.forEach(function (check) { check.checked = false; });
            order = [];
            syncMain();
            render();
            if (search) search.focus();
        });
    }

    // ---- search ----
    function applyFilter() {
        const query = search ? search.value.trim().toLowerCase() : '';
        let shown = 0;
        rows.forEach(function (row) {
            const match = query === '' || (row.getAttribute('data-pp-name') || '').indexOf(query) !== -1;
            row.hidden = !match;
            if (match) shown += 1;
        });
        if (emptyNote) emptyNote.hidden = shown > 0;
        // A shorter list must stay attached to the field when it opened above.
        place();
    }
    if (search) search.addEventListener('input', applyFilter);

    // ---- placing the panel ----
    function place() {
        if (!isOpen) return;
        const rect = field.getBoundingClientRect();
        const viewW = document.documentElement.clientWidth;
        const viewH = window.innerHeight;
        const width = Math.min(332, viewW - 24);
        panel.style.width = width + 'px';
        list.style.maxHeight = '';
        let left = rect.right - width;
        left = Math.max(12, Math.min(left, viewW - 12 - width));
        panel.style.left = left + 'px';

        let height = panel.offsetHeight;
        const below = viewH - rect.bottom - 6 - 12;
        const above = rect.top - 6 - 12;
        let top;
        let flipped = false;
        if (openSide === 'above' && height <= above) {
            top = rect.top - 6 - height;
            flipped = true;
        } else if (height <= below) {
            top = rect.bottom + 6;
        } else if (height <= above) {
            top = rect.top - 6 - height;
            flipped = true;
        } else {
            // Neither side fits: use the taller side and shorten the list.
            const room = Math.max(below, above);
            const cut = height - room;
            list.style.maxHeight = Math.max(72, list.clientHeight - cut) + 'px';
            height = panel.offsetHeight;
            if (below >= above) {
                top = rect.bottom + 6;
            } else {
                top = rect.top - 6 - height;
                flipped = true;
            }
        }
        panel.style.top = Math.max(12, top) + 'px';
        root.classList.toggle('is-above', flipped);
        openSide = flipped ? 'above' : 'below';
    }

    function onViewportChange() {
        place();
    }

    function open() {
        if (isOpen) return;
        closeAllProgramPickers();
        isOpen = true;
        openSide = null;
        if (usePopover) {
            panel.showPopover();
        } else {
            panel.classList.add('is-open');
        }
        root.classList.add('is-open');
        field.setAttribute('aria-expanded', 'true');
        place();
        // Again once the window's opening animation (if still running) ends,
        // since it moves the field.
        window.requestAnimationFrame(place);
        window.addEventListener('resize', onViewportChange);
        window.addEventListener('scroll', onViewportChange, true);
        document.addEventListener('animationend', onViewportChange, true);
        if (search) search.focus({ preventScroll: true });
    }

    function close(returnFocus) {
        if (!isOpen) return;
        isOpen = false;
        if (usePopover) {
            try { panel.hidePopover(); } catch (error) { /* already closed */ }
        } else {
            panel.classList.remove('is-open');
        }
        root.classList.remove('is-open', 'is-above');
        field.setAttribute('aria-expanded', 'false');
        window.removeEventListener('resize', onViewportChange);
        window.removeEventListener('scroll', onViewportChange, true);
        document.removeEventListener('animationend', onViewportChange, true);
        if (search && search.value !== '') {
            search.value = '';
            applyFilter();
        }
        if (returnFocus) field.focus();
    }

    field.addEventListener('click', function () {
        if (isOpen) {
            close(true);
        } else {
            open();
        }
    });

    if (doneBtn) {
        doneBtn.addEventListener('click', function () { close(true); });
    }

    // Click outside: close; focus goes back to the field unless the click
    // landed on another control.
    document.addEventListener('pointerdown', function (event) {
        if (!isOpen || root.contains(event.target)) return;
        const onControl = event.target.closest && event.target.closest('input, select, textarea, button, a[href], [tabindex]');
        close(false);
        if (!onControl) {
            // After the browser's own focus change for this click.
            window.setTimeout(function () { field.focus(); }, 0);
        }
    }, true);

    // Focus leaving the picker (Tab past Done, Shift+Tab before the field)
    // closes the panel.
    root.addEventListener('focusout', function (event) {
        if (isOpen && event.relatedTarget && !root.contains(event.relatedTarget)) {
            close(false);
        }
    });

    function navItems() {
        const items = [];
        if (allCheck) items.push(allCheck);
        rows.forEach(function (row, i) {
            if (!row.hidden) items.push(checks[i]);
        });
        return items;
    }

    function moveTo(item) {
        if (!item) return;
        item.focus();
        const row = item.closest('.pp-row');
        if (row && row.scrollIntoView) row.scrollIntoView({ block: 'nearest' });
    }

    root.addEventListener('keydown', function (event) {
        const target = event.target;

        if (target === field) {
            if (!isOpen && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
                event.preventDefault();
                open();
            }
            if (isOpen && event.key === 'Escape') {
                // Escape closes the panel only, never the window behind it.
                event.preventDefault();
                event.stopPropagation();
                close(true);
            }
            return;
        }

        if (!isOpen) return;

        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            close(true);
            return;
        }

        const items = navItems();
        const index = items.indexOf(target);

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            moveTo(target === search ? items[0] : items[Math.min(items.length - 1, index + 1)]);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            if (index <= 0) {
                if (search) search.focus();
            } else {
                moveTo(items[index - 1]);
            }
        } else if ((event.key === 'Home' || event.key === 'End') && target !== search && index !== -1) {
            event.preventDefault();
            moveTo(event.key === 'Home' ? items[0] : items[items.length - 1]);
        } else if (event.key === 'Enter' && target.classList && target.classList.contains('pp-check')) {
            // Enter on a checkbox must not submit the book form.
            event.preventDefault();
            target.click();
        } else if (event.key === 'Enter' && target === search) {
            event.preventDefault();
            moveTo(items[0]);
        } else if (search && target !== search && event.key.length === 1 && event.key !== ' ' && !event.ctrlKey && !event.metaKey && !event.altKey) {
            // Typing a letter anywhere in the panel searches.
            search.focus();
        }
    });

    const picker = { close: close };
    programPickers.push(picker);
    render();
}

document.querySelectorAll('.pp-picker').forEach(initProgramPicker);

// Back on the list after Close or a saved edit (#book-row-<id>): put that
// row in the middle of the window and flash it for about 1.6 s. With
// prefers-reduced-motion the row is still scrolled to and marked, without
// the fade. Runs after load, once the browser's own jump to the #anchor
// (row at the very top) has happened.
(function highlightReturnedBookRow() {
    const match = /^#book-row-([1-9][0-9]*)$/.exec(window.location.hash || '');
    if (!match) return;
    const row = document.getElementById('book-row-' + match[1]);
    if (!row) return;
    const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const flashClass = reduceMotion ? 'mb-row-flash-still' : 'mb-row-flash';
    const show = function () {
        window.requestAnimationFrame(function () {
            row.scrollIntoView({ block: 'center', behavior: 'auto' });
            row.classList.add(flashClass);
            window.setTimeout(function () {
                row.classList.remove(flashClass);
            }, 1600);
        });
    };
    if (document.readyState === 'complete') {
        show();
    } else {
        window.addEventListener('load', show, { once: true });
    }
})();
