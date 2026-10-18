<?php
// Deprecated and disabled: this endpoint let anyone POST here and create
// a live, active "student" account with no verification. It isn't called
// by the current UI (accounts are created by librarians in
// admin/manage_users.php), so it stayed live purely as an unauthenticated
// account-creation hole. Disabled rather than left reachable.
http_response_code(404);
exit;
