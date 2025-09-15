<?php
// includes/utils/functions.php
namespace Calendly_Bookings\Utils;

if (!defined('ABSPATH')) exit;

function cb_resolve_timezone(): ?string {
    $tz = wp_timezone_string();
    return $tz ?: null;
}
