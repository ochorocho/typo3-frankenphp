<?php

declare(strict_types=1);

return [
    'pin' => ['\\Vendor\\Ext\\PinnedRegistry'],
    'keep' => ['Vendor\\Ext\\Stateless'],
    'discard' => ['Vendor\\Ext\\PerRequest', 'vendor.ext.legacy'],
    'discardPatterns' => ['/^Vendor\\\\Ext\\\\Controller\\\\/'],
];
