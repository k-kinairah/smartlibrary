/**
 * JS for admin/manage_books.php (add/edit book modals, accession field
 * generation, delete confirmation). Moved out of an inline <script> block
 * into its own file so it can be cached by the browser and edited without
 * touching the PHP.
 */

function openBookModal() {
    const modal = document.getElementById('bookModal');
    if (!modal) return;
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
}

function closeBookModal() {
    const modal = document.getElementById('bookModal');
    if (!modal) return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
}

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
        input.name = 'accession_numbers[]';
        input.placeholder = 'Accession #' + (i + 1);
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

    if (desiredCount === null) {
        setEditCopyHint('Enter a valid Number of Copies (1-200).');
        return;
    }

    const additionalCount = Math.max(0, desiredCount - currentCount);

    if (additionalCount === 0) {
        if (desiredCount < currentCount) {
            setEditCopyHint('Lower copy count will remove available copies first (borrowed/overdue/missing stay protected).');
        } else {
            setEditCopyHint('Copy count matches current inventory. No new accession fields needed.');
        }
        return;
    }

    setEditCopyHint('Provide accession numbers for ' + additionalCount + ' new ' + (additionalCount === 1 ? 'copy.' : 'copies.'));

    for (let i = 0; i < additionalCount; i += 1) {
        const input = document.createElement('input');
        input.type = 'text';
        input.name = 'new_accession_numbers[]';
        input.placeholder = 'New Accession #' + (i + 1);
        input.value = existingValues[i] || '';
        editNewAccessionInputsWrap.appendChild(input);
    }
}

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

document.querySelectorAll('.book-copy-toggle-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const rowId = btn.getAttribute('data-target');
        if (!rowId) return;

        const detailRow = document.getElementById(rowId);
        if (!detailRow) return;

        const willShow = detailRow.hasAttribute('hidden');
        if (willShow) {
            detailRow.removeAttribute('hidden');
            btn.setAttribute('aria-expanded', 'true');
            btn.textContent = 'Hide';
        } else {
            detailRow.setAttribute('hidden', '');
            btn.setAttribute('aria-expanded', 'false');
            btn.textContent = 'View';
        }
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
