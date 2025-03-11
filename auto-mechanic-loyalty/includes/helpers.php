<?php
/**
 * Helper functions for Auto Mechanic Loyalty Pro
 */

if (!defined('ABSPATH')) exit;

/**
 * Normalize phone numbers to international format
 */
function aml_normalize_phone_number($phone) {
    $phone = trim($phone);
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    // NL-logica (pas aan naar wens)
    if (substr($phone, 0, 2) === '06') {
        $phone = '+316' . substr($phone, 2);
    } elseif (substr($phone, 0, 3) === '316') {
        $phone = '+' . $phone;
    } elseif (!str_starts_with($phone, '+31')) {
        $phone = '+31' . ltrim($phone, '0');
    }
    return $phone;
}