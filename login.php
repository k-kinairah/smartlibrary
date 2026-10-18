<?php
// Deprecated: this standalone login page predates the in-page sign-in
// modal on index.php (it also posts mismatched field names to
// login_handler.php and its "Create Account" tab pointed at a
// register_handler.php that no longer exists). Kept as a redirect so an
// old bookmark/link doesn't dead-end.
header('Location: index.php');
exit;
