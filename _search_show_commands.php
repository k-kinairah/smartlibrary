<?php
// Temporary emergency-recovery diagnostic script, neutralized after use.
// Originally ran unauthenticated DB/filesystem commands - safe to delete
// this file entirely whenever convenient.
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo "Gone. This diagnostic script has been neutralized and no longer does anything.\n";
