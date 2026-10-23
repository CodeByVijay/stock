<?php
/**
 * SMTP settings for password recovery (used from Milestone 6 onward).
 * Leave host empty if SMTP is not configured.
 */
return [
    'enabled' => false,
    'host' => '',
    'port' => 587,
    'username' => '',
    'password' => '',
    'encryption' => 'tls',
    'from_email' => '',
    'from_name' => 'Stock Research Portal',
    'reply_to' => '',
    'meta' => [],
];
