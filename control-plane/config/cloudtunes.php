<?php

return [
    // Open account registration is intentionally disabled unless an operator
    // explicitly enables it for a one-time bootstrap workflow.
    'public_registration_enabled' => (bool) env('CLOUDTUNES_PUBLIC_REGISTRATION_ENABLED', false),
];
