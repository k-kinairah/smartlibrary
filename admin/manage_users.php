<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
if (!isset($_SESSION['user_id']) || !in_array($currentRole, ['librarian', 'admin'], true)) {
    header('Location: ../index.php');
    exit;
}

require '../config/db_connect.php';
require_once '../config/session_guard.php';
if (!smartlib_active_staff_session($conn)) {
    header('Location: ' . smartlib_staff_signed_out_location());
    exit;
}
require_once '../config/admin_audit.php';
require_once '../config/csrf.php';
require_once '../config/library_card_helpers.php';
require_once '../config/auth_security.php'; // staff password rule (auth_staff_password_problem)
require_once __DIR__ . '/../config/request_input.php';
require_once __DIR__ . '/includes/list_pager.php';

// Shown (server and browser) when a Student/Faculty account is moved to a
// staff role without a new password in the same save.
function smartlib_staff_promotion_message(): string {
    return 'To make this account a Librarian, set a new password in the same save (at least '
        . AUTH_STAFF_PASSWORD_MIN . ' characters with a letter and a number).';
}

// List position: search, role and status filters and the page. Edit, a
// saved edit and Activate/Deactivate carry them so the librarian lands back
// where they were (the forms have no action attribute, so they post to the
// current URL). Same approach as Manage Books.
const MANAGE_USERS_PER_PAGE = 50;

/**
 * Every Manage Users list/edit URL. Known keys only, in a fixed order;
 * empty values and page 1 are left out; values are URL-encoded and the
 * result is always a relative manage_users.php URL (no open redirect).
 * $fragment is only ever 'user-row-<id>' built from an int.
 */
$buildManageUsersUrl = static function (array $params, string $fragment = ''): string {
    $clean = [];
    foreach (['edit_user', 'search', 'role', 'status', 'page'] as $key) {
        if (!array_key_exists($key, $params) || is_array($params[$key])) {
            continue;
        }
        $text = trim((string)$params[$key]);
        if ($key === 'edit_user' || $key === 'page') {
            $number = (int)$text;
            if ($number <= ($key === 'page' ? 1 : 0)) {
                continue;
            }
            $text = (string)$number;
        }
        if ($text !== '') {
            $clean[$key] = $text;
        }
    }

    return 'manage_users.php'
        . (!empty($clean) ? ('?' . http_build_query($clean, '', '&', PHP_QUERY_RFC3986)) : '')
        . (preg_match('/^user-row-[1-9][0-9]*$/', $fragment) ? ('#' . $fragment) : '');
};

$normalizeRoleFilter = static function (string $role): string {
    $role = strtolower(trim($role));
    if ($role === 'admin') {
        return 'librarian';
    }

    return in_array($role, ['', 'student', 'faculty', 'librarian'], true) ? $role : '';
};

// When librarians deactivate their own account, end the session on this same
// save -- same as ../logout.php -- rather than redirecting back into Manage
// Users only for the live check (config/session_guard.php) to bounce them.
$signOutIfSelfDeactivated = static function (int $editedUserId, string $newStatus): void {
    if ($newStatus !== 'inactive' || $editedUserId !== (int)($_SESSION['user_id'] ?? 0)) {
        return;
    }

    $_SESSION = [];
    session_destroy();
    header('Location: ../index.php');
    exit;
};

$allowedRoles = ['student', 'faculty', 'librarian', 'admin'];
$allowedStatusValues = ['active', 'inactive'];

$csrfToken = smartlib_csrf_token('manage_users_csrf');

$flash = ['type' => '', 'message' => ''];
$openEditUserModal = false;
$editPinPopupMessage = '';
$editUserData = [
    'user_id' => 0,
    'first_name' => '',
    'last_name' => '',
    'user_number' => '',
    'email' => '',
    'program_id' => null,
    'department_id' => null,
    'school_level' => 'college',
    'role' => 'student',
    'status' => 'active'
];

// Faculty/staff departments (database/faculty_departments_setup.sql), in the
// school's order. Faculty get a department instead of a program. Add User
// offers only active departments; Edit User also keeps a retired one the
// person already has (database/departments_update_nov_2026.sql).
$departmentsList = library_card_get_departments($conn);
$allDepartmentsList = library_card_get_departments($conn, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    if (!smartlib_csrf_verify('manage_users_csrf', $_POST['csrf_token'] ?? '')) {
        $flash = ['type' => 'error', 'message' => 'Invalid request token. Refresh and try again.'];
    } else {
        $firstName = trim((string)($_POST['first_name'] ?? ''));
    $lastName = trim((string)($_POST['last_name'] ?? ''));
    // Stored without dashes/spaces, like every other path that writes or looks
    // up an ID (signup, login, the door scanner) -- see library_card_normalize_id().
    $userNumber = library_card_normalize_id((string)($_POST['user_number'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $pin = trim((string)($_POST['pin'] ?? ''));
    $role = strtolower(trim((string)($_POST['role'] ?? 'student')));
    $programIdRaw = trim((string)($_POST['program_id'] ?? ''));
    $programId = ctype_digit($programIdRaw) ? (int)$programIdRaw : null;
    $departmentIdRaw = trim((string)($_POST['department_id'] ?? ''));
    $departmentId = ctype_digit($departmentIdRaw) && isset($departmentsList[(int)$departmentIdRaw]) ? (int)$departmentIdRaw : null;
    $schoolLevelRaw = trim((string)($_POST['school_level'] ?? ''));
    $schoolLevel = isset(LIBRARY_CARD_SCHOOL_LEVELS[$schoolLevelRaw]) ? $schoolLevelRaw : null;

    if (!in_array($role, $allowedRoles, true)) {
        $role = 'student';
    }
    // Faculty/staff: department (optional, like Program) and no program.
    // Every other role keeps its program and has no department.
    if ($role === 'faculty') {
        $programId = null;
    } else {
        $departmentId = null;
    }
    // School level is students only (same rule as sign-up): Junior/Senior
    // High have no program (and no year level, which a new account never
    // has anyway); every other role stores NULL.
    if ($role !== 'student') {
        $schoolLevel = null;
    } elseif ($schoolLevel !== null && $schoolLevel !== 'college') {
        $programId = null;
    }

    if ($firstName === '' || $lastName === '' || $userNumber === '' || $email === '' || $pin === '') {
        $flash = ['type' => 'error', 'message' => 'First name, last name, ID number, email, and PIN are required.'];
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $flash = ['type' => 'error', 'message' => 'Please enter a valid email address.'];
    } elseif ($role === 'faculty' && $departmentIdRaw !== '' && $departmentId === null) {
        $flash = ['type' => 'error', 'message' => 'Please choose a department from the list.'];
    } elseif ($role === 'student' && $schoolLevel === null) {
        $flash = ['type' => 'error', 'message' => 'Please choose a school level.'];
    } elseif (in_array($role, ['student', 'faculty'], true) && !auth_kiosk_pin_valid((string)($_POST['pin'] ?? ''))) {
        // Exactly 4 digits, checked untrimmed (a valid PIN equals $pin).
        $flash = ['type' => 'error', 'message' => AUTH_KIOSK_PIN_MESSAGE];
    } elseif (in_array($role, ['librarian', 'admin'], true) && ($staffProblem = auth_staff_password_problem($pin, $userNumber)) !== '') {
        // Same rule as a staff reset link: staff sign in with a password + 2FA.
        $flash = ['type' => 'error', 'message' => 'For Librarian/Admin accounts: ' . $staffProblem];
    } else {
        $dupStmt = $conn->prepare("SELECT user_id FROM library_users WHERE user_number = ? LIMIT 1");
        if ($dupStmt) {
            $dupStmt->bind_param('s', $userNumber);
            $dupStmt->execute();
            $dupRes = $dupStmt->get_result();
            $exists = $dupRes && $dupRes->num_rows > 0;
            $dupStmt->close();

            if ($exists) {
                $flash = ['type' => 'error', 'message' => 'That ID number already exists.'];
            }
        }

        if ($flash['message'] === '') {
            $dupEmailStmt = $conn->prepare("SELECT user_id FROM library_users WHERE email = ? LIMIT 1");
            if ($dupEmailStmt) {
                $dupEmailStmt->bind_param('s', $email);
                $dupEmailStmt->execute();
                $dupEmailRes = $dupEmailStmt->get_result();
                $emailExists = $dupEmailRes && $dupEmailRes->num_rows > 0;
                $dupEmailStmt->close();

                if ($emailExists) {
                    $flash = ['type' => 'error', 'message' => 'That email is already used by another account.'];
                }
            }
        }

        if ($flash['message'] === '') {
            $pinHash = password_hash($pin, PASSWORD_DEFAULT);

            $insert = $conn->prepare(
                "INSERT INTO library_users (user_number, first_name, last_name, email, password, program_id, department_id, school_level, role, status, created_at)
                 VALUES (?, ?, ?, ?, ?, NULLIF(?, 0), ?, ?, ?, 'active', NOW())"
            );

            if ($insert) {
                $insert->bind_param('sssssiiss', $userNumber, $firstName, $lastName, $email, $pinHash, $programId, $departmentId, $schoolLevel, $role);
                $ok = $insert->execute();
                $createdUserId = $ok ? (int)$conn->insert_id : 0;
                $insert->close();

                if ($ok) {
                    smartlib_admin_audit_log($conn, 'added', 'user', $createdUserId, trim($firstName . ' ' . $lastName), [
                        'user_number' => $userNumber,
                        'email' => $email,
                        'role' => $role,
                        'status' => 'active',
                        'program_id' => $programId,
                        'department_id' => $departmentId,
                        'school_level' => $schoolLevel
                    ]);
                    $flash = ['type' => 'success', 'message' => 'User account created successfully.'];
                } else {
                    $flash = ['type' => 'error', 'message' => 'Could not create user account. Please try again.'];
                }
            } else {
                $flash = ['type' => 'error', 'message' => 'User insert failed. Check your database columns.'];
            }
        }
    }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    if (!smartlib_csrf_verify('manage_users_csrf', $_POST['csrf_token'] ?? '')) {
        $flash = ['type' => 'error', 'message' => 'Invalid request token. Refresh and try again.'];
    } else {
        $editUserId = (int)($_POST['edit_user_id'] ?? 0);
    $firstName = trim((string)($_POST['edit_first_name'] ?? ''));
    $lastName = trim((string)($_POST['edit_last_name'] ?? ''));
    $userNumber = library_card_normalize_id((string)($_POST['edit_user_number'] ?? ''));
    $email = trim((string)($_POST['edit_email'] ?? ''));
    $programIdRaw = trim((string)($_POST['edit_program_id'] ?? ''));
    $programId = ctype_digit($programIdRaw) ? (int)$programIdRaw : null;
    $departmentIdRaw = trim((string)($_POST['edit_department_id'] ?? ''));
    // Active departments, plus a retired one only if this person already has it
    // (so saving the form never blanks or changes it).
    $currentDepartmentId = null;
    $editUserIdForDept = (int)($_POST['edit_user_id'] ?? 0);
    if ($editUserIdForDept > 0) {
        $curDeptStmt = $conn->prepare("SELECT department_id FROM library_users WHERE user_id = ? LIMIT 1");
        if ($curDeptStmt) {
            $curDeptStmt->bind_param('i', $editUserIdForDept);
            $curDeptStmt->execute();
            $curDeptRow = $curDeptStmt->get_result()->fetch_assoc();
            $curDeptStmt->close();
            $currentDepartmentId = isset($curDeptRow['department_id']) ? (int)$curDeptRow['department_id'] : null;
        }
    }
    $departmentId = null;
    if (ctype_digit($departmentIdRaw)) {
        $postedDepartmentId = (int)$departmentIdRaw;
        if (isset($departmentsList[$postedDepartmentId])
            || ($postedDepartmentId === $currentDepartmentId && isset($allDepartmentsList[$postedDepartmentId]))) {
            $departmentId = $postedDepartmentId;
        }
    }
    $schoolLevelRaw = trim((string)($_POST['edit_school_level'] ?? ''));
    $schoolLevel = isset(LIBRARY_CARD_SCHOOL_LEVELS[$schoolLevelRaw]) ? $schoolLevelRaw : null;
    $role = strtolower(trim((string)($_POST['edit_role'] ?? 'student')));
    $status = strtolower(trim((string)($_POST['edit_status'] ?? 'active')));
    $newPin = trim((string)($_POST['edit_pin'] ?? ''));

    $returnSearch = trim((string)($_POST['return_search'] ?? ''));
    $returnRole = $normalizeRoleFilter((string)($_POST['return_role'] ?? ''));
    $returnStatus = strtolower(trim((string)($_POST['return_status'] ?? '')));
    $returnPage = max(1, smartlib_request_int($_POST, 'return_page', 1));

    if ($editUserId <= 0) {
        $flash = ['type' => 'error', 'message' => 'Invalid user selected for editing.'];
    } elseif ($firstName === '' || $lastName === '' || $userNumber === '') {
        $flash = ['type' => 'error', 'message' => 'First name, last name, and ID number are required.'];
    } else {
        if (!in_array($role, $allowedRoles, true)) {
            $role = 'student';
        }
        if (!in_array($status, $allowedStatusValues, true)) {
            $status = 'active';
        }
        // Faculty + department chosen: department, program cleared. Faculty
        // with no department yet: keep the posted program (an older faculty
        // account keeps its course until a department is picked). Any other
        // role: program from the form, no department.
        if ($role === 'faculty' && $departmentIdRaw !== '' && $departmentId === null) {
            $flash = ['type' => 'error', 'message' => 'Please choose a department from the list.'];
        } elseif ($role === 'faculty' && $departmentId !== null) {
            $programId = null;
        } elseif ($role !== 'faculty') {
            $departmentId = null;
        }
        // School level (students only, same rule as sign-up): Junior/Senior
        // High clear program and year level; College keeps both; every other
        // role stores NULL.
        if ($role !== 'student') {
            $schoolLevel = null;
        } elseif ($schoolLevel === null) {
            if ($flash['message'] === '') {
                $flash = ['type' => 'error', 'message' => 'Please choose a school level.'];
            }
        } elseif ($schoolLevel !== 'college') {
            $programId = null;
        }
        $clearYearLevel = ($schoolLevel === 'jhs' || $schoolLevel === 'shs') ? 1 : 0;

        $dupStmt = $conn->prepare("SELECT user_id FROM library_users WHERE user_number = ? AND user_id <> ? LIMIT 1");
        if ($dupStmt) {
            $dupStmt->bind_param('si', $userNumber, $editUserId);
            $dupStmt->execute();
            $dupRes = $dupStmt->get_result();
            $exists = $dupRes && $dupRes->num_rows > 0;
            $dupStmt->close();

            if ($exists) {
                $flash = ['type' => 'error', 'message' => 'That ID number already exists.'];
            }
        }

        if ($flash['message'] === '' && $email !== '') {
            $dupEmailStmt = $conn->prepare("SELECT user_id FROM library_users WHERE email = ? AND user_id <> ? LIMIT 1");
            if ($dupEmailStmt) {
                $dupEmailStmt->bind_param('si', $email, $editUserId);
                $dupEmailStmt->execute();
                $dupEmailRes = $dupEmailStmt->get_result();
                $emailExists = $dupEmailRes && $dupEmailRes->num_rows > 0;
                $dupEmailStmt->close();

                if ($emailExists) {
                    $flash = ['type' => 'error', 'message' => 'That email is already used by another account.'];
                }
            }
        }

        if (
            $flash['message'] === ''
            && (string)($_POST['edit_pin'] ?? '') !== ''
            && in_array($role, ['student', 'faculty'], true)
            && !auth_kiosk_pin_valid((string)($_POST['edit_pin'] ?? ''))
        ) {
            // Exactly 4 digits, checked untrimmed (blank keeps the current PIN).
            $editPinPopupMessage = AUTH_KIOSK_PIN_MESSAGE;
            $flash = ['type' => 'error', 'message' => $editPinPopupMessage];
        }

        // Staff (librarian/admin) passwords follow the staff reset rule. An
        // account being moved into a staff role from student/faculty must get
        // a new password here, or its numeric PIN would become a staff password.
        if ($flash['message'] === '' && in_array($role, ['librarian', 'admin'], true)) {
            if ($newPin !== '') {
                $staffProblem = auth_staff_password_problem($newPin, $userNumber);
                if ($staffProblem !== '') {
                    $editPinPopupMessage = 'For Librarian/Admin accounts: ' . $staffProblem;
                    $flash = ['type' => 'error', 'message' => $editPinPopupMessage];
                }
            } else {
                $oldRole = '';
                $oldRoleStmt = $conn->prepare('SELECT role FROM library_users WHERE user_id = ? LIMIT 1');
                if ($oldRoleStmt) {
                    $oldRoleStmt->bind_param('i', $editUserId);
                    $oldRoleStmt->execute();
                    $oldRoleRow = $oldRoleStmt->get_result()->fetch_assoc();
                    $oldRoleStmt->close();
                    $oldRole = strtolower(trim((string)($oldRoleRow['role'] ?? '')));
                }
                if (!in_array($oldRole, ['librarian', 'admin'], true)) {
                    $editPinPopupMessage = smartlib_staff_promotion_message();
                    $flash = ['type' => 'error', 'message' => $editPinPopupMessage];
                }
            }
        }

        if ($flash['message'] === '') {
            $ok = false;
            if ($newPin !== '') {
                $pinHash = password_hash($newPin, PASSWORD_DEFAULT);
                $update = $conn->prepare(
                    "UPDATE library_users
                     SET first_name = ?, last_name = ?, user_number = ?, email = NULLIF(?, ''),
                         program_id = NULLIF(?, 0), department_id = ?, school_level = ?,
                         year_level = IF(? = 1, NULL, year_level), role = ?, status = ?, password = ?
                     WHERE user_id = ? LIMIT 1"
                );
                if ($update) {
                    $update->bind_param('ssssiisisssi', $firstName, $lastName, $userNumber, $email, $programId, $departmentId, $schoolLevel, $clearYearLevel, $role, $status, $pinHash, $editUserId);
                    $ok = $update->execute();
                    $update->close();
                }
            } else {
                $update = $conn->prepare(
                    "UPDATE library_users
                     SET first_name = ?, last_name = ?, user_number = ?, email = NULLIF(?, ''),
                         program_id = NULLIF(?, 0), department_id = ?, school_level = ?,
                         year_level = IF(? = 1, NULL, year_level), role = ?, status = ?
                     WHERE user_id = ? LIMIT 1"
                );
                if ($update) {
                    $update->bind_param('ssssiisissi', $firstName, $lastName, $userNumber, $email, $programId, $departmentId, $schoolLevel, $clearYearLevel, $role, $status, $editUserId);
                    $ok = $update->execute();
                    $update->close();
                }
            }

            if ($ok) {
                // When librarians edit their own account, refresh the session copies
                // now so this request's audit entry and the redirect already use the
                // new values. Other accounts pick up the change on their own next
                // request (config/session_guard.php), via the same helper.
                if ($editUserId === (int)($_SESSION['user_id'] ?? 0)) {
                    smartlib_session_apply_account([
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'user_number' => $userNumber,
                        'role' => $role,
                    ]);
                }

                smartlib_admin_audit_log($conn, 'updated', 'user', $editUserId, trim($firstName . ' ' . $lastName), [
                    'user_number' => $userNumber,
                    'email' => $email,
                    'role' => $role,
                    'status' => $status,
                    'program_id' => $programId,
                    'department_id' => $departmentId,
                    'school_level' => $schoolLevel,
                    'pin_changed' => $newPin !== ''
                ]);

                $signOutIfSelfDeactivated($editUserId, $status);

                $redirectParams = [
                    'search' => $returnSearch,
                    'role' => $returnRole,
                    'status' => in_array($returnStatus, $allowedStatusValues, true) ? $returnStatus : '',
                    'page' => $returnPage
                ];
                header('Location: ' . $buildManageUsersUrl($redirectParams, 'user-row-' . (int)$editUserId));
                exit;
            }

            $flash = ['type' => 'error', 'message' => 'Could not update user. Please try again.'];
        }
    }

    $openEditUserModal = true;
    $editUserData = [
        'user_id' => $editUserId,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'user_number' => $userNumber,
        'email' => $email,
        'program_id' => $programId,
        'department_id' => $departmentId,
        'school_level' => isset(LIBRARY_CARD_SCHOOL_LEVELS[$schoolLevelRaw]) ? $schoolLevelRaw : '',
        'role' => in_array($role, ['student', 'faculty', 'librarian'], true) ? $role : 'student',
        'status' => in_array($status, $allowedStatusValues, true) ? $status : 'active'
    ];
    }
}

$search = trim(smartlib_request_string($_GET, 'search'));
$roleFilter = $normalizeRoleFilter(smartlib_request_string($_GET, 'role'));
$statusFilter = strtolower(trim(smartlib_request_string($_GET, 'status')));
// int only: missing, non-numeric, arrays, 0 or negative = page 1 (the upper
// end is clamped to the last page once the filtered total is known).
$usersPage = max(1, smartlib_request_int($_GET, 'page', 1));

$allowedStatusFilters = array_merge([''], $allowedStatusValues);
if (!in_array($statusFilter, $allowedStatusFilters, true)) {
    $statusFilter = '';
}

$baseFilterParams = [
    'search' => $search,
    'role' => $roleFilter,
    'status' => $statusFilter,
    'page' => $usersPage
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_user_status'])) {
    if (smartlib_csrf_verify('manage_users_csrf', $_POST['csrf_token'] ?? '')) {
        $userId = (int)($_POST['toggle_user_status'] ?? 0);
        if ($userId > 0) {
            $beforeUser = null;
            $beforeStmt = $conn->prepare('SELECT user_id, user_number, first_name, last_name, role, status FROM library_users WHERE user_id = ? LIMIT 1');
            if ($beforeStmt) {
                $beforeStmt->bind_param('i', $userId);
                $beforeStmt->execute();
                $beforeRes = $beforeStmt->get_result();
                $beforeUser = $beforeRes ? $beforeRes->fetch_assoc() : null;
                $beforeStmt->close();
            }

            $toggleStmt = $conn->prepare("UPDATE library_users SET status = IF(status='active','inactive','active') WHERE user_id = ? LIMIT 1");
            if ($toggleStmt) {
                $toggleStmt->bind_param('i', $userId);
                $toggleStmt->execute();
                $didToggle = $toggleStmt->affected_rows > 0;
                $toggleStmt->close();

                if ($didToggle && is_array($beforeUser)) {
                    $oldStatus = strtolower((string)($beforeUser['status'] ?? ''));
                    $newStatus = $oldStatus === 'active' ? 'inactive' : 'active';
                    $displayName = trim((string)($beforeUser['first_name'] ?? '') . ' ' . (string)($beforeUser['last_name'] ?? ''));
                    smartlib_admin_audit_log($conn, 'status_changed', 'user', $userId, $displayName, [
                        'user_number' => (string)($beforeUser['user_number'] ?? ''),
                        'role' => (string)($beforeUser['role'] ?? ''),
                        'previous_status' => $oldStatus,
                        'new_status' => $newStatus
                    ]);

                    $signOutIfSelfDeactivated($userId, $newStatus);
                }
            }
        }
    }

    header('Location: ' . $buildManageUsersUrl($baseFilterParams, 'user-row-' . smartlib_request_int($_POST, 'toggle_user_status', 0)));
    exit;
}

if (!$openEditUserModal && isset($_GET['edit_user'])) {
    $editId = smartlib_request_int($_GET, 'edit_user', 0);
    if ($editId > 0) {
        $editRow = null;
        $editStmt = $conn->prepare("SELECT user_id, first_name, last_name, user_number, email, program_id, department_id, school_level, role, status FROM library_users WHERE user_id = ? LIMIT 1");
        if ($editStmt) {
            $editStmt->bind_param('i', $editId);
            $editStmt->execute();
            $editRes = $editStmt->get_result();
            $editRow = $editRes ? $editRes->fetch_assoc() : null;
            $editStmt->close();
        }
        if ($editRow) {
            $dbRole = strtolower((string)($editRow['role'] ?? ''));
            if ($dbRole === 'admin') {
                $dbRole = 'librarian';
            }

            $openEditUserModal = true;
            $editUserData = [
                'user_id' => (int)($editRow['user_id'] ?? 0),
                'first_name' => (string)($editRow['first_name'] ?? ''),
                'last_name' => (string)($editRow['last_name'] ?? ''),
                'user_number' => (string)($editRow['user_number'] ?? ''),
                'email' => (string)($editRow['email'] ?? ''),
                'program_id' => isset($editRow['program_id']) ? (int)$editRow['program_id'] : null,
                'department_id' => isset($editRow['department_id']) ? (int)$editRow['department_id'] : null,
                // NULL (older student rows) is College.
                'school_level' => isset(LIBRARY_CARD_SCHOOL_LEVELS[(string)($editRow['school_level'] ?? '')]) ? (string)$editRow['school_level'] : 'college',
                'role' => in_array($dbRole, ['student', 'faculty', 'librarian'], true) ? $dbRole : 'student',
                'status' => in_array((string)($editRow['status'] ?? ''), $allowedStatusValues, true) ? (string)$editRow['status'] : 'active'
            ];
        }
    }
}

$whereParts = [];
$whereTypes = '';
$whereParams = [];
if ($search !== '') {
    $like = '%' . $search . '%';
    $whereParts[] = "(first_name LIKE ? OR last_name LIKE ? OR user_number LIKE ? OR email LIKE ?)";
    $whereTypes .= 'ssss';
    array_push($whereParams, $like, $like, $like, $like);
}
if ($roleFilter !== '') {
    if ($roleFilter === 'librarian') {
        $whereParts[] = "role IN ('librarian', 'admin')";
    } else {
        $whereParts[] = "role = ?";
        $whereTypes .= 's';
        $whereParams[] = $roleFilter;
    }
}
if ($statusFilter !== '') {
    $whereParts[] = "status = ?";
    $whereTypes .= 's';
    $whereParams[] = $statusFilter;
}
$whereSql = !empty($whereParts) ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

// Filtered total: the same WHERE as the list, counted.
$filteredUsersTotal = 0;
$usersCountStmt = $conn->prepare("SELECT COUNT(*) AS filtered_total FROM library_users {$whereSql}");
if ($usersCountStmt) {
    if ($whereTypes !== '') {
        $usersCountStmt->bind_param($whereTypes, ...$whereParams);
    }
    $usersCountStmt->execute();
    $usersCountRes = $usersCountStmt->get_result();
    $usersCountRow = $usersCountRes ? $usersCountRes->fetch_assoc() : null;
    $filteredUsersTotal = (int)($usersCountRow['filtered_total'] ?? 0);
    $usersCountStmt->close();
}
$usersTotalPages = max(1, (int)ceil($filteredUsersTotal / MANAGE_USERS_PER_PAGE));
$usersPage = min($usersPage, $usersTotalPages);
$baseFilterParams['page'] = $usersPage;
$usersPageOffset = ($usersPage - 1) * MANAGE_USERS_PER_PAGE;

// Same order as before (newest first); user_id breaks created_at ties so a
// row never repeats or goes missing between pages.
$users = false;
$usersStmt = $conn->prepare(
    "SELECT * FROM library_users {$whereSql} ORDER BY created_at DESC, user_id DESC LIMIT "
    . (int)$usersPageOffset . ', ' . (int)MANAGE_USERS_PER_PAGE
);
if ($usersStmt) {
    if ($whereTypes !== '') {
        $usersStmt->bind_param($whereTypes, ...$whereParams);
    }
    $usersStmt->execute();
    $users = $usersStmt->get_result();
    $usersStmt->close();
}

$totalUsers = 0;
$activeUsers = 0;
$inactiveUsers = 0;
$studentUsers = 0;
$facultyUsers = 0;
$librarianUsers = 0;

$userCounts = $conn->query(
    "
    SELECT
        COUNT(*) AS total_users,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_users,
        SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive_users,
        SUM(CASE WHEN role = 'student' THEN 1 ELSE 0 END) AS student_users,
        SUM(CASE WHEN role = 'faculty' THEN 1 ELSE 0 END) AS faculty_users,
        SUM(CASE WHEN role IN ('librarian', 'admin') THEN 1 ELSE 0 END) AS librarian_users
    FROM library_users
    "
);

if ($userCounts && ($countRow = $userCounts->fetch_assoc())) {
    $totalUsers = (int)($countRow['total_users'] ?? 0);
    $activeUsers = (int)($countRow['active_users'] ?? 0);
    $inactiveUsers = (int)($countRow['inactive_users'] ?? 0);
    $studentUsers = (int)($countRow['student_users'] ?? 0);
    $facultyUsers = (int)($countRow['faculty_users'] ?? 0);
    $librarianUsers = (int)($countRow['librarian_users'] ?? 0);
}

$statusChipMetrics = [
    ['status' => '', 'title' => 'Total Users', 'count' => $totalUsers],
    ['status' => 'active', 'title' => 'Active', 'count' => $activeUsers],
    ['status' => 'inactive', 'title' => 'Inactive', 'count' => $inactiveUsers]
];

$roleChipMetrics = [
    ['role' => 'student', 'title' => 'Students', 'count' => $studentUsers],
    ['role' => 'faculty', 'title' => 'Faculty', 'count' => $facultyUsers],
    ['role' => 'librarian', 'title' => 'Librarians', 'count' => $librarianUsers]
];

$allChipMetrics = [];
$chipBaseParams = ['search' => $search];

foreach ($statusChipMetrics as $chip) {
    $chipStatus = (string)($chip['status'] ?? '');
    $isActive = ($chipStatus === '') ? ($statusFilter === '') : ($statusFilter === $chipStatus);

    $targetParams = $chipBaseParams;
    if ($roleFilter !== '') {
        $targetParams['role'] = $roleFilter;
    }
    if ($chipStatus !== '' && !$isActive) {
        $targetParams['status'] = $chipStatus;
    }

    $allChipMetrics[] = [
        'title' => (string)($chip['title'] ?? ''),
        'count' => (int)($chip['count'] ?? 0),
        'href' => $buildManageUsersUrl($targetParams),
        'is_active' => $isActive
    ];
}

foreach ($roleChipMetrics as $chip) {
    $chipRole = (string)($chip['role'] ?? '');
    $isActive = ($roleFilter === $chipRole);

    $targetParams = $chipBaseParams;
    if ($statusFilter !== '') {
        $targetParams['status'] = $statusFilter;
    }
    if (!$isActive) {
        $targetParams['role'] = $chipRole;
    }

    $allChipMetrics[] = [
        'title' => (string)($chip['title'] ?? ''),
        'count' => (int)($chip['count'] ?? 0),
        'href' => $buildManageUsersUrl($targetParams),
        'is_active' => $isActive
    ];
}

$programs = [];
$programNamesById = [];
$programRes = $conn->query("SELECT program_id, program_name FROM programs ORDER BY program_name ASC");
if ($programRes) {
    while ($programRow = $programRes->fetch_assoc()) {
        $programs[] = $programRow;
        $programNamesById[(int)$programRow['program_id']] = (string)$programRow['program_name'];
    }
}

$openCreateUserModal = $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['create_user'])
    && ($flash['type'] ?? '') !== 'success';

if ($openEditUserModal) {
    $openCreateUserModal = false;
}

require 'layout_top.php';
?>

<div class="page-top">
    <h1 class="page-heading"><span class="page-icon" aria-hidden="true"><?= admin_icon('users') ?></span>Manage Users</h1>
    <button class="btn-primary" onclick="openUserModal()">+ Add User</button>
</div>

<?php if (!empty($flash['message'])): ?>
    <section class="panel glass-card borrow-flash borrow-flash-<?= htmlspecialchars($flash['type'] === 'success' ? 'success' : 'error') ?>">
        <?= htmlspecialchars($flash['message']) ?>
    </section>
<?php endif; ?>

<section class="stats-grid borrow-status-chips manage-users-chip-row">
    <?php foreach ($allChipMetrics as $chip): ?>
        <?php $chipMeta = stat_chip_meta((string)($chip['title'] ?? '')); ?>
        <a href="<?= htmlspecialchars((string)($chip['href'] ?? '')) ?>" class="stat-card glass-card borrow-chip<?= !empty($chip['is_active']) ? ' is-active' : '' ?>">
            <div class="metric-head-row">
                <span class="metric-icon-badge metric-icon-badge--<?= htmlspecialchars($chipMeta['accent']) ?>" aria-hidden="true"><?= admin_icon($chipMeta['icon']) ?></span>
                <h3><?= htmlspecialchars((string)($chip['title'] ?? '')) ?></h3>
            </div>
            <p class="value"><?= number_format((int)($chip['count'] ?? 0)) ?></p>
        </a>
    <?php endforeach; ?>
</section>

<section class="filters-bar-panel">
    <form method="GET" class="filters-inline borrow-filters">
        <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
        <input type="hidden" name="role" value="<?= htmlspecialchars($roleFilter) ?>">
        <div class="search-field-wrap">
            <span class="filters-search-icon" aria-hidden="true"><?= admin_icon('search') ?></span>
            <input type="text" name="search" placeholder="Search name, ID, email" value="<?= htmlspecialchars($search) ?>">
        </div>
        <button type="submit" class="btn-primary">Apply</button>
        <a href="manage_users.php" class="filter-reset-btn">Reset</a>
    </form>
</section>

<section class="panel glass-card data-table-panel">
    <div class="table-wrap">
        <table class="data-table manage-users-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>User Number</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($users && $users->num_rows > 0): ?>
                    <?php while ($u = $users->fetch_assoc()): ?>
                        <?php

                            $editParams = $baseFilterParams;
                            $editParams['edit_user'] = (string)intval($u['user_id']);
                            $editHref = $buildManageUsersUrl($editParams);
                            $historyHref = 'borrower_history.php?user_id=' . intval($u['user_id']);

                            $rowRole = strtolower((string)($u['role'] ?? ''));
                            $displayRole = $rowRole === 'admin' ? 'Librarian' : ucfirst((string)$rowRole);
                            $roleClassKey = $rowRole === 'admin' ? 'librarian' : $rowRole;
                            $roleTextClass = 'user-role-text';
                            if ($roleClassKey === 'student') {
                                $roleTextClass .= ' user-role-student';
                            } elseif ($roleClassKey === 'faculty') {
                                $roleTextClass .= ' user-role-faculty';
                            } elseif ($roleClassKey === 'librarian') {
                                $roleTextClass .= ' user-role-librarian';
                            }
                            $rowStatus = strtolower(trim((string)($u['status'] ?? 'inactive')));
                            $statusClass = $rowStatus === 'active'
                                ? 'user-status-text user-status-active'
                                : 'user-status-text user-status-inactive';
                            $statusLabel = ucfirst($rowStatus === '' ? 'inactive' : $rowStatus);
                            // Students: Junior/Senior High show their level,
                            // College (or NULL, which is College) their program.
                            $rowAffiliation = '';
                            if ($rowRole === 'student') {
                                $rowLevel = (string)($u['school_level'] ?? '');
                                $rowAffiliation = ($rowLevel === 'jhs' || $rowLevel === 'shs')
                                    ? library_card_school_level_label($rowLevel)
                                    : ($programNamesById[(int)($u['program_id'] ?? 0)] ?? '');
                            }
                            $userDisplayName = trim((string)$u['first_name'] . ' ' . (string)$u['last_name']);
                            if ($userDisplayName === '') {
                                $userDisplayName = (string)($u['user_number'] ?? 'this user');
                            }
                        ?>
                        <tr id="user-row-<?= intval($u['user_id']) ?>">
                            <td><?= intval($u['user_id']) ?></td>
                            <td><?= htmlspecialchars((string)$u['first_name'] . ' ' . (string)$u['last_name']) ?></td>
                            <td><?= htmlspecialchars((string)$u['user_number']) ?></td>
                            <td class="user-meta-text"><?= htmlspecialchars((string)($u['email'] ?? '') !== '' ? (string)$u['email'] : '-') ?></td>
                            <td class="<?= htmlspecialchars($roleTextClass) ?>"><?= htmlspecialchars($displayRole) ?><?php if ($rowAffiliation !== ''): ?><br><small><?= htmlspecialchars($rowAffiliation) ?></small><?php endif; ?></td>
                            <td><span class="<?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars($statusLabel) ?></span></td>
                            <td class="user-actions-cell">
                                <a class="btn-status user-pin-btn user-action-icon-btn borrower-history-icon-btn" href="<?= htmlspecialchars($historyHref) ?>" aria-label="View borrower history">
                                    <?= admin_icon('history') ?>
                                </a>
                                <a class="btn-status user-pin-btn user-action-icon-btn" href="<?= htmlspecialchars($editHref) ?>" aria-label="Edit user">
                                    <?= admin_icon('square-pen') ?>
                                </a>
                                <form method="POST" class="row-action-form user-status-confirm-form" data-user-action="<?= $u['status'] === 'active' ? 'deactivate' : 'activate' ?>" data-user-name="<?= htmlspecialchars($userDisplayName) ?>">
                                    <input type="hidden" name="toggle_user_status" value="<?= intval($u['user_id']) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <button type="submit" class="btn-status user-toggle-btn user-action-icon-btn <?= $u['status'] === 'active' ? 'deactivate' : 'activate' ?>" aria-label="<?= $u['status'] === 'active' ? 'Deactivate user' : 'Activate user' ?>">
                                        <?= admin_icon($u['status'] === 'active' ? 'user-x' : 'user-check') ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="7">No users found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php smartlib_list_pager_render($usersPage, $usersTotalPages, $filteredUsersTotal, MANAGE_USERS_PER_PAGE, static function (int $pagerPage) use ($buildManageUsersUrl, $baseFilterParams): string { return $buildManageUsersUrl(['page' => $pagerPage] + $baseFilterParams); }, 'users'); ?>
</section>

<div class="overlay-modal<?= $openCreateUserModal ? ' show' : '' ?>" id="userModal" aria-hidden="<?= $openCreateUserModal ? 'false' : 'true' ?>">
    <div class="overlay-card glass-card user-modal-card">
        <div class="panel-head">
            <h2><span class="modal-icon" aria-hidden="true"><?= admin_icon('users') ?></span>Add User</h2>
            <button class="icon-close" type="button" onclick="closeUserModal()">&times;</button>
        </div>

        <form method="POST" class="form-grid user-create-grid user-create-portrait">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="text" name="first_name" placeholder="First Name" required>
            <input type="text" name="last_name" placeholder="Last Name" required>
            <input type="text" name="user_number" placeholder="Student/Faculty/Librarian ID" required>
            <input type="email" name="email" placeholder="Email" required>
            <input type="password" name="pin" placeholder="PIN / Password" required>
            <small class="pin-rule-note" id="createPinHint">Student/Faculty: exactly 4 digits. Librarian/Admin: at least <?= (int)AUTH_STAFF_PASSWORD_MIN ?> characters with a letter and a number.</small>

            <select name="school_level" aria-label="School level">
                <?php foreach (LIBRARY_CARD_SCHOOL_LEVELS as $levelValue => $levelLabel): ?>
                    <option value="<?= htmlspecialchars($levelValue) ?>" <?= $levelValue === 'college' ? 'selected' : '' ?>><?= htmlspecialchars($levelLabel) ?></option>
                <?php endforeach; ?>
            </select>

            <select name="program_id">
                <option value="">No Program</option>
                <?php foreach ($programs as $p): ?>
                    <option value="<?= intval($p['program_id']) ?>"><?= htmlspecialchars((string)$p['program_name']) ?></option>
                <?php endforeach; ?>
            </select>

            <select name="department_id" hidden>
                <option value="">No Department</option>
                <?php foreach ($departmentsList as $did => $dname): ?>
                    <option value="<?= (int)$did ?>"><?= htmlspecialchars((string)$dname) ?></option>
                <?php endforeach; ?>
            </select>

            <select name="role" required>
                <option value="student">Student</option>
                <option value="faculty">Faculty</option>
                <option value="librarian">Librarian</option>
            </select>

            <button type="submit" name="create_user" class="btn-primary form-submit modal-submit-btn">
                <span class="modal-submit-icon" aria-hidden="true"><?= admin_icon('plus-circle') ?></span>
                Create User
            </button>
        </form>
    </div>
</div>

<div class="overlay-modal<?= $openEditUserModal ? ' show' : '' ?>" id="editUserModal" aria-hidden="<?= $openEditUserModal ? 'false' : 'true' ?>">
    <div class="overlay-card glass-card user-modal-card">
        <div class="panel-head">
            <h2>Edit User</h2>
            <button class="icon-close" type="button" onclick="closeEditUserModal()">&times;</button>
        </div>

        <form method="POST" id="editUserForm" class="form-grid user-create-grid user-create-portrait">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="edit_user_id" value="<?= intval($editUserData['user_id'] ?? 0) ?>">
            <input type="hidden" name="return_search" value="<?= htmlspecialchars($search) ?>">
            <input type="hidden" name="return_role" value="<?= htmlspecialchars($roleFilter) ?>">
            <input type="hidden" name="return_status" value="<?= htmlspecialchars($statusFilter) ?>">
            <input type="hidden" name="return_page" value="<?= intval($usersPage) ?>">

            <input type="text" name="edit_first_name" placeholder="First Name" value="<?= htmlspecialchars((string)($editUserData['first_name'] ?? '')) ?>" required>
            <input type="text" name="edit_last_name" placeholder="Last Name" value="<?= htmlspecialchars((string)($editUserData['last_name'] ?? '')) ?>" required>
            <input type="text" name="edit_user_number" placeholder="Student/Faculty/Librarian ID" value="<?= htmlspecialchars((string)($editUserData['user_number'] ?? '')) ?>" required>
            <input type="email" name="edit_email" placeholder="Email (optional)" value="<?= htmlspecialchars((string)($editUserData['email'] ?? '')) ?>">
            <input type="password" name="edit_pin" placeholder="New PIN / Password (optional)" autocomplete="new-password">
            <small class="pin-rule-note" id="editPinHint">Leave blank to keep current PIN/password. Student/Faculty: exactly 4 digits. Librarian/Admin: at least <?= (int)AUTH_STAFF_PASSWORD_MIN ?> characters with a letter and a number.</small>

            <?php
            $editRoleNow = (string)($editUserData['role'] ?? '');
            $editLevelNow = (string)($editUserData['school_level'] ?? '');
            $editHideProgram = $editRoleNow === 'faculty' || ($editRoleNow === 'student' && $editLevelNow !== 'college');
            ?>
            <select name="edit_school_level" aria-label="School level"<?= $editRoleNow === 'student' ? '' : ' hidden' ?>>
                <?php if ($editLevelNow === ''): ?>
                    <option value="" selected>Select a school level</option>
                <?php endif; ?>
                <?php foreach (LIBRARY_CARD_SCHOOL_LEVELS as $levelValue => $levelLabel): ?>
                    <option value="<?= htmlspecialchars($levelValue) ?>" <?= $editLevelNow === $levelValue ? 'selected' : '' ?>><?= htmlspecialchars($levelLabel) ?></option>
                <?php endforeach; ?>
            </select>

            <select name="edit_program_id"<?= $editHideProgram ? ' hidden' : '' ?>>
                <option value="">No Program</option>
                <?php foreach ($programs as $p): ?>
                    <?php $pid = (int)($p['program_id'] ?? 0); ?>
                    <option value="<?= $pid ?>" <?= (int)($editUserData['program_id'] ?? 0) === $pid ? 'selected' : '' ?>><?= htmlspecialchars((string)$p['program_name']) ?></option>
                <?php endforeach; ?>
            </select>

            <?php
            $editIsFaculty = ($editUserData['role'] ?? '') === 'faculty';
            // Active departments, plus the person's own retired one (selected).
            $editCurrentDepartmentId = (int)($editUserData['department_id'] ?? 0);
            $editDepartmentOptions = array_filter(
                $allDepartmentsList,
                static fn($did) => isset($departmentsList[$did]) || (int)$did === $editCurrentDepartmentId,
                ARRAY_FILTER_USE_KEY
            );
            ?>
            <select name="edit_department_id"<?= $editIsFaculty ? '' : ' hidden' ?>>
                <option value="">No Department</option>
                <?php foreach ($editDepartmentOptions as $did => $dname): ?>
                    <option value="<?= (int)$did ?>" <?= (int)($editUserData['department_id'] ?? 0) === (int)$did ? 'selected' : '' ?>><?= htmlspecialchars((string)$dname) ?></option>
                <?php endforeach; ?>
            </select>

            <?php
            // Role as stored right now (not what a failed save posted), so the
            // form knows whether choosing Librarian is a promotion.
            $editOriginalRole = '';
            if ((int)($editUserData['user_id'] ?? 0) > 0) {
                $origStmt = $conn->prepare('SELECT role FROM library_users WHERE user_id = ? LIMIT 1');
                if ($origStmt) {
                    $origId = (int)$editUserData['user_id'];
                    $origStmt->bind_param('i', $origId);
                    $origStmt->execute();
                    $origRow = $origStmt->get_result()->fetch_assoc();
                    $origStmt->close();
                    $editOriginalRole = strtolower(trim((string)($origRow['role'] ?? '')));
                }
            }
            $editWasStaff = in_array($editOriginalRole, ['librarian', 'admin'], true);
            ?>
            <select name="edit_role" required data-was-staff="<?= $editWasStaff ? '1' : '0' ?>">
                <option value="student" <?= (($editUserData['role'] ?? '') === 'student') ? 'selected' : '' ?>>Student</option>
                <option value="faculty" <?= (($editUserData['role'] ?? '') === 'faculty') ? 'selected' : '' ?>>Faculty</option>
                <option value="librarian" <?= (($editUserData['role'] ?? '') === 'librarian') ? 'selected' : '' ?>>Librarian</option>
            </select>

            <select name="edit_status" required>
                <option value="active" <?= (($editUserData['status'] ?? '') === 'active') ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= (($editUserData['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>Inactive</option>
            </select>

            <button type="submit" name="update_user" class="btn-primary form-submit">Save Changes</button>
        </form>
    </div>
</div>

<script>
function openUserModal() {
    const modal = document.getElementById('userModal');
    if (!modal) return;
    // Program or Department by the role already chosen in the form.
    const form = modal.querySelector('form');
    if (form) {
        syncAffiliationFields(form.querySelector('select[name="role"]'), form.querySelector('select[name="program_id"]'), form.querySelector('select[name="department_id"]'), form.querySelector('select[name="school_level"]'));
    }
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

// Student/Faculty: the PIN box takes digits only, at most 4 (typed or
// pasted). Librarian/Admin: the same box is a free-text password again.
function applyPinInputMode(input, roleValue) {
    if (!input) return;
    if (roleNeedsNumericPin(roleValue)) {
        input.setAttribute('maxlength', '4');
        input.setAttribute('inputmode', 'numeric');
        input.setAttribute('pattern', '[0-9]{4}');
        const clean = input.value.replace(/[^0-9]/g, '').slice(0, 4);
        if (clean !== input.value) input.value = clean;
    } else {
        input.removeAttribute('maxlength');
        input.removeAttribute('inputmode');
        input.removeAttribute('pattern');
    }
    if (!input.dataset.pinModeBound) {
        input.dataset.pinModeBound = '1';
        input.addEventListener('input', function () {
            if (input.getAttribute('inputmode') !== 'numeric') return;
            const clean = input.value.replace(/[^0-9]/g, '').slice(0, 4);
            if (clean !== input.value) input.value = clean;
        });
        // A paste keeps its digits first and then the first 4 (PIN mode only).
        input.addEventListener('paste', function (e) {
            if (input.getAttribute('inputmode') !== 'numeric') return;
            const text = (e.clipboardData || window.clipboardData)?.getData('text') || '';
            e.preventDefault();
            const v = input.value;
            const s = input.selectionStart ?? v.length;
            const en = input.selectionEnd ?? v.length;
            input.value = (v.slice(0, s) + text + v.slice(en)).replace(/[^0-9]/g, '').slice(0, 4);
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
    }
}

const STAFF_PASSWORD_MIN = <?= (int)AUTH_STAFF_PASSWORD_MIN ?>;

// Mirrors auth_staff_password_problem() so staff see the rule before
// submitting; the server still checks everything.
function staffPasswordProblem(value, userNumber) {
    if (new TextEncoder().encode(value).length > <?= (int)AUTH_STAFF_PASSWORD_MAX_BYTES ?>) {
        return 'Password must be at most <?= (int)AUTH_STAFF_PASSWORD_MAX_BYTES ?> characters.';
    }
    if ([...value].length < STAFF_PASSWORD_MIN || !/\p{L}/u.test(value) || !/\d/.test(value)) {
        return 'Password must be at least ' + STAFF_PASSWORD_MIN + ' characters with a letter and a number.';
    }
    if (userNumber && value.toLowerCase() === String(userNumber).trim().toLowerCase()) {
        return 'Password must not be the same as your ID.';
    }
    return '';
}

const STAFF_PROMOTION_MESSAGE = <?= json_encode(smartlib_staff_promotion_message()) ?>;

// wasStaff: edit form only -- whether the account is a librarian/admin now.
function updatePinHint(hintEl, roleValue, isEditForm, wasStaff) {
    if (!hintEl) return;

    if (isEditForm && !roleNeedsNumericPin(roleValue) && wasStaff === false) {
        hintEl.textContent = 'Required: ' + STAFF_PROMOTION_MESSAGE.charAt(0).toLowerCase() + STAFF_PROMOTION_MESSAGE.slice(1);
        hintEl.classList.add('is-strict');
        return;
    }

    if (roleNeedsNumericPin(roleValue)) {
        hintEl.textContent = isEditForm
            ? 'Leave blank to keep current PIN. For Student/Faculty, the PIN must be exactly 4 digits.'
            : 'For Student/Faculty accounts, the PIN must be exactly 4 digits.';
        hintEl.classList.add('is-strict');
    } else {
        hintEl.textContent = isEditForm
            ? 'Leave blank to keep current password. New Librarian/Admin password: at least ' + STAFF_PASSWORD_MIN + ' characters with a letter and a number.'
            : 'For Librarian/Admin accounts: at least ' + STAFF_PASSWORD_MIN + ' characters with a letter and a number.';
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

// Faculty/staff pick a Department; every other role a Program. Students
// also pick a School level, and Junior/Senior High have no Program (cleared
// here; the server clears it too). Hidden selects still post: the server
// applies the role rule.
function syncAffiliationFields(roleSelect, programSelect, departmentSelect, levelSelect) {
    if (!roleSelect) return;
    const isFaculty = roleSelect.value === 'faculty';
    const isStudent = roleSelect.value === 'student';
    const isBasicEd = isStudent && !!levelSelect && (levelSelect.value === 'jhs' || levelSelect.value === 'shs');
    if (levelSelect) levelSelect.hidden = !isStudent;
    if (programSelect) {
        programSelect.hidden = isFaculty || isBasicEd;
        if (isBasicEd) programSelect.value = '';
    }
    if (departmentSelect) departmentSelect.hidden = !isFaculty;
}

if (createUserForm) {
    const createRole = createUserForm.querySelector('select[name="role"]');
    const createPin = createUserForm.querySelector('input[name="pin"]');
    const createHint = document.getElementById('createPinHint');

    applyOptionalEmailValidation(createUserForm, 'input[name="email"]');

    if (createRole) {
        const createProgram = createUserForm.querySelector('select[name="program_id"]');
        const createDepartment = createUserForm.querySelector('select[name="department_id"]');
        const createLevel = createUserForm.querySelector('select[name="school_level"]');
        updatePinHint(createHint, createRole.value, false);
        applyPinInputMode(createPin, createRole.value);
        syncAffiliationFields(createRole, createProgram, createDepartment, createLevel);
        createRole.addEventListener('change', function () {
            if (createPin) createPin.setCustomValidity('');
            updatePinHint(createHint, createRole.value, false);
        applyPinInputMode(createPin, createRole.value);
            syncAffiliationFields(createRole, createProgram, createDepartment, createLevel);
        });
        if (createLevel) {
            createLevel.addEventListener('change', function () {
                syncAffiliationFields(createRole, createProgram, createDepartment, createLevel);
            });
        }
    }

    createUserForm.addEventListener('submit', function (e) {
        if (!createRole || !createPin) return;
        const pinValue = createPin.value.trim();
        createPin.setCustomValidity('');

        const staffMsg = roleNeedsNumericPin(createRole.value)
            ? ''
            : staffPasswordProblem(pinValue, createUserForm.querySelector('input[name="user_number"]')?.value || '');
        if (staffMsg) {
            const msg = 'For Librarian/Admin accounts: ' + staffMsg;
            e.preventDefault();
            createPin.setCustomValidity(msg);
            createPin.reportValidity();
            window.alert(msg);
            createPin.focus();
            createPin.select();
            return;
        }

        if (roleNeedsNumericPin(createRole.value) && !/^[0-9]{4}$/.test(createPin.value)) {
            const msg = 'Your PIN must be exactly 4 digits.';
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
    const editWasStaff = editRole ? editRole.dataset.wasStaff === '1' : true;

    applyOptionalEmailValidation(editUserForm, 'input[name="edit_email"]');

    if (editRole) {
        const editProgram = editUserForm.querySelector('select[name="edit_program_id"]');
        const editDepartment = editUserForm.querySelector('select[name="edit_department_id"]');
        const editLevel = editUserForm.querySelector('select[name="edit_school_level"]');
        updatePinHint(editHint, editRole.value, true, editWasStaff);
        applyPinInputMode(pinInput, editRole.value);
        syncAffiliationFields(editRole, editProgram, editDepartment, editLevel);
        editRole.addEventListener('change', function () {
            if (pinInput) pinInput.setCustomValidity('');
            updatePinHint(editHint, editRole.value, true, editWasStaff);
        applyPinInputMode(pinInput, editRole.value);
            syncAffiliationFields(editRole, editProgram, editDepartment, editLevel);
        });
        if (editLevel) {
            editLevel.addEventListener('change', function () {
                syncAffiliationFields(editRole, editProgram, editDepartment, editLevel);
            });
        }
    }

    editUserForm.addEventListener('submit', function (e) {
        if (!pinInput || !editRole) return;
        const newPin = pinInput.value.trim();
        pinInput.setCustomValidity('');

        let staffMsg = '';
        if (!roleNeedsNumericPin(editRole.value)) {
            if (newPin !== '') {
                const problem = staffPasswordProblem(newPin, editUserForm.querySelector('input[name="edit_user_number"]')?.value || '');
                if (problem) staffMsg = 'For Librarian/Admin accounts: ' + problem;
            } else if (!editWasStaff) {
                staffMsg = STAFF_PROMOTION_MESSAGE;
            }
        }
        if (staffMsg) {
            const msg = staffMsg;
            e.preventDefault();
            pinInput.setCustomValidity(msg);
            pinInput.reportValidity();
            window.alert(msg);
            pinInput.focus();
            pinInput.select();
            return;
        }

        if (pinInput.value !== '' && roleNeedsNumericPin(editRole.value) && !/^[0-9]{4}$/.test(pinInput.value)) {
            const msg = 'Your PIN must be exactly 4 digits.';
            e.preventDefault();
            pinInput.setCustomValidity(msg);
            pinInput.reportValidity();
            window.alert(msg);
            pinInput.focus();
            pinInput.select();
        }
    });
}

const editPinPopupMessage = <?= json_encode($editPinPopupMessage, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
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
</script>

<div class="overlay-modal" id="userStatusConfirmModal" aria-hidden="true">
    <div class="overlay-card glass-card confirm-modal-card" role="dialog" aria-modal="true" aria-labelledby="userStatusConfirmTitle" aria-describedby="userStatusConfirmText">
        <button type="button" class="icon-close confirm-modal-close" id="userStatusConfirmCancel" aria-label="Cancel">&times;</button>
        <div class="confirm-modal-header">
            <span class="confirm-modal-icon" id="userStatusConfirmIcon" aria-hidden="true">!</span>
            <div>
                <h2 id="userStatusConfirmTitle">Confirm</h2>
                <p id="userStatusConfirmText">Apply this change?</p>
            </div>
        </div>
        <p class="confirm-modal-note" id="userStatusConfirmNote"></p>
        <div class="confirm-modal-actions">
            <button type="button" class="confirm-modal-confirm" id="userStatusConfirmSubmit">Confirm</button>
        </div>
    </div>
</div>

<script>
(function () {
    const modal = document.getElementById('userStatusConfirmModal');
    const iconEl = document.getElementById('userStatusConfirmIcon');
    const titleEl = document.getElementById('userStatusConfirmTitle');
    const textEl = document.getElementById('userStatusConfirmText');
    const noteEl = document.getElementById('userStatusConfirmNote');
    const cancelBtn = document.getElementById('userStatusConfirmCancel');
    const submitBtn = document.getElementById('userStatusConfirmSubmit');
    if (!modal || !iconEl || !titleEl || !textEl || !noteEl || !cancelBtn || !submitBtn) {
        return;
    }

    const TONES = ['is-positive', 'is-warning', 'is-danger'];
    const COPY = {
        activate: {
            title: 'Activate User',
            icon: String.fromCharCode(0x2713),
            note: 'The account regains sign-in and borrowing access.',
            confirmLabel: 'Activate',
            tone: 'is-positive'
        },
        deactivate: {
            title: 'Deactivate User',
            icon: '!',
            note: 'The account loses sign-in and borrowing access until reactivated.',
            confirmLabel: 'Deactivate',
            tone: 'is-danger'
        }
    };

    let pendingForm = null;

    function setTone(el, tone) {
        TONES.forEach(function (t) { el.classList.remove(t); });
        el.classList.add(tone);
    }

    function closeModal() {
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        pendingForm = null;
    }

    document.querySelectorAll('.user-status-confirm-form').forEach(function (formEl) {
        formEl.addEventListener('submit', function (event) {
            event.preventDefault();

            const action = formEl.dataset.userAction === 'deactivate' ? 'deactivate' : 'activate';
            const copy = COPY[action];
            const rawName = (formEl.dataset.userName || '').trim();
            const label = rawName !== '' ? '"' + rawName + '"' : 'this user';

            pendingForm = formEl;
            titleEl.textContent = copy.title;
            textEl.textContent = copy.title.replace('User', '') + label + '?';
            noteEl.textContent = copy.note;
            iconEl.textContent = copy.icon;
            setTone(iconEl, copy.tone);
            submitBtn.textContent = copy.confirmLabel;
            setTone(submitBtn, copy.tone);

            modal.classList.add('show');
            modal.setAttribute('aria-hidden', 'false');
            submitBtn.focus();
        });
    });

    cancelBtn.addEventListener('click', closeModal);

    submitBtn.addEventListener('click', function () {
        if (!pendingForm) {
            closeModal();
            return;
        }

        const formToSubmit = pendingForm;
        pendingForm = null;
        closeModal();
        formToSubmit.submit();
    });

    window.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });

    window.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('show')) {
            closeModal();
        }
    });
}());

// Back on the list after a saved edit or Activate/Deactivate
// (#user-row-<id>): put that row in the middle of the window instead of
// under the top edge. Runs after load, once the browser's own jump to the
// #anchor has happened.
(function centerReturnedUserRow() {
    const match = /^#user-row-([1-9][0-9]*)$/.exec(window.location.hash || '');
    if (!match) return;
    const row = document.getElementById('user-row-' + match[1]);
    if (!row) return;
    const show = function () {
        window.requestAnimationFrame(function () {
            row.scrollIntoView({ block: 'center', behavior: 'auto' });
        });
    };
    if (document.readyState === 'complete') {
        show();
    } else {
        window.addEventListener('load', show, { once: true });
    }
}());
</script>

<?php require 'layout_bottom.php'; ?>
