<?php

return [

    // Segregation of duties: a submitter may not approve their own document.
    'allow_self_approval' => (bool) env('APPROVALS_ALLOW_SELF_APPROVAL', false),

];
