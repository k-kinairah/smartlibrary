/**
 * JS for admin/manage_users.php (create/edit user modals, PIN/password
 * hint text, optional-email validation). Moved out of an inline <script>
 * block.
 *
 * Note: `editPinPopupMessage` used to be filled in directly by PHP here.
 * It now reads `window.EDIT_PIN_POPUP_MESSAGE`, set by a small inline
 * <script> in manage_users.php right before this file is loaded.
 */

function openUserModal() {
    const modal = document.getElementById('userModal');
    if (!modal) return;
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
}

function closeUserModal() {
    const modal = document.getElementById('userModal');
    if (!modal) return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
}

function closeEditUserModal() {
    const modal = document.getElementById('editUserModal');
    if (!modal) return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');

    const url = new URL(window.location.href);
    if (url.searchParams.has('edit_user')) {
        url.searchParams.delete('edit_user');
        window.history.replaceState({}, document.title, url.toString());
    }
}

const createUserModal = document.getElementById('userModal');
const createUserForm = createUserModal ? createUserModal.querySelector('form[method="POST"]') : null;
const editUserForm = document.getElementById('editUserForm');

function roleNeedsNumericPin(roleValue) {
    const role = String(roleValue || '').toLowerCase();
    return role === 'student' || role === 'faculty';
}

function updatePinHint(hintEl, roleValue, isEditForm) {
    if (!hintEl) return;

    if (roleNeedsNumericPin(roleValue)) {
        hintEl.textContent = isEditForm
            ? 'Leave blank to keep current PIN. For Student/Faculty, new PIN must be at least 4 digits.'
            : 'For Student/Faculty accounts, PIN must be at least 4 digits.';
        hintEl.classList.add('is-strict');
    } else {
        hintEl.textContent = isEditForm
            ? 'Leave blank to keep current password. Librarian can use a secure password (letters, numbers, symbols).'
            : 'For Librarian accounts, use a secure password (letters, numbers, symbols).';
        hintEl.classList.remove('is-strict');
    }
}

function applyOptionalEmailValidation(formEl, selector) {
    if (!formEl) return;
    const emailInput = formEl.querySelector(selector);
    if (!emailInput) return;

    const updateMessage = function () {
        const value = emailInput.value.trim();
        emailInput.setCustomValidity('');
        if (value === '') {
            emailInput.setCustomValidity('');
            return '';
        }
        if (!value.includes('@')) {
            const msg = "Email must include '@' (example: name@domain.com).";
            emailInput.setCustomValidity(msg);
            return msg;
        }
        if (!emailInput.checkValidity()) {
            const msg = 'Use a valid email format like name@domain.com.';
            emailInput.setCustomValidity(msg);
            return msg;
        }
        emailInput.setCustomValidity('');
        return '';
    };

    emailInput.addEventListener('input', updateMessage);
    emailInput.addEventListener('blur', updateMessage);
    emailInput.addEventListener('invalid', function () {
        updateMessage();
    });
}

if (createUserForm) {
    const createRole = createUserForm.querySelector('select[name="role"]');
    const createPin = createUserForm.querySelector('input[name="pin"]');
    const createHint = document.getElementById('createPinHint');

    applyOptionalEmailValidation(createUserForm, 'input[name="email"]');

    if (createRole) {
        updatePinHint(createHint, createRole.value, false);
        createRole.addEventListener('change', function () {
            if (createPin) createPin.setCustomValidity('');
            updatePinHint(createHint, createRole.value, false);
        });
    }

    createUserForm.addEventListener('submit', function (e) {
        if (!createRole || !createPin) return;
        const pinValue = createPin.value.trim();
        createPin.setCustomValidity('');

        if (roleNeedsNumericPin(createRole.value) && !/^\d{4,}$/.test(pinValue)) {
            const msg = 'For Student/Faculty accounts, PIN must be at least 4 digits.';
            e.preventDefault();
            createPin.setCustomValidity(msg);
            createPin.reportValidity();
            window.alert(msg);
            createPin.focus();
            createPin.select();
        }
    });
}

if (editUserForm) {
    const editRole = editUserForm.querySelector('select[name="edit_role"]');
    const pinInput = editUserForm.querySelector('input[name="edit_pin"]');
    const editHint = document.getElementById('editPinHint');

    applyOptionalEmailValidation(editUserForm, 'input[name="edit_email"]');

    if (editRole) {
        updatePinHint(editHint, editRole.value, true);
        editRole.addEventListener('change', function () {
            if (pinInput) pinInput.setCustomValidity('');
            updatePinHint(editHint, editRole.value, true);
        });
    }

    editUserForm.addEventListener('submit', function (e) {
        if (!pinInput || !editRole) return;
        const newPin = pinInput.value.trim();
        pinInput.setCustomValidity('');

        if (newPin !== '' && roleNeedsNumericPin(editRole.value) && !/^\d{4,}$/.test(newPin)) {
            const msg = 'For Student/Faculty accounts, new PIN must be at least 4 digits.';
            e.preventDefault();
            pinInput.setCustomValidity(msg);
            pinInput.reportValidity();
            window.alert(msg);
            pinInput.focus();
            pinInput.select();
        }
    });
}

const editPinPopupMessage = window.EDIT_PIN_POPUP_MESSAGE;
if (editPinPopupMessage && document.getElementById('editUserModal')?.classList.contains('show')) {
    window.setTimeout(function () {
        window.alert(editPinPopupMessage);
        const pinInput = document.querySelector('#editUserModal input[name="edit_pin"]');
        if (pinInput) {
            pinInput.focus();
            pinInput.select();
        }
    }, 80);
}

window.addEventListener('click', function (e) {
    if (e.target && e.target.id === 'userModal') {
        closeUserModal();
    }
    if (e.target && e.target.id === 'editUserModal') {
        closeEditUserModal();
    }
});
