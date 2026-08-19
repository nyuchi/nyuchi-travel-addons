<?php
/**
 * Plugin Name: WT Outline printf Guard
 * Description: Stops WT Widgets for Elementor 1.4.7 from passing itinerary day descriptions into printf() as the format string. Remove once the vendor ships a fix.
 * Version:     1.0.0
 * Author:      Nyuchi Web Services
 * License:     GPL v2 or later
 *
 * THE DEFECT
 * wt-widgets-elementor/inc/widgets/trip-outline-widget.php line 1741 places the
 * itinerary day description in the FORMAT STRING argument of printf() instead of
 * passing it as a value. Any '%' an editor types is then parsed as a format
 * specifier. Observed on PHP 8.3:
 *
 *   "incline of about 20%!"                -> ValueError: Unknown format specifier "!"   (HTTP 500)
 *   "30% of the reserve ... 70% is within" -> ArgumentCountError: 3 arguments required   (HTTP 500)
 *   "a 98% guarantee of viewing"           -> renders "a 987350uarantee"                 (silent, HTTP 200)
 *
 * The same file gets it right 144 lines later at line 1885, which is what the
 * upstream fix should look like.
 *
 * THE GUARD
 * The broken call routes the description through the filter tag below. The
 * genuine title call on line 1726 passes the literal string '%s' through that
 * SAME tag, so this cannot blindly escape everything: '%s' must survive intact
 * or the day title stops rendering. Everything else is content sitting in a
 * format slot, and gets its percent signs doubled so printf prints them
 * literally.
 *
 * The tag name contains the vendor's own typo ("ititneraries"). It is reproduced
 * verbatim because that is the string add_filter has to match.
 *
 * @package WPTravelAddons
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'wp_travel_ititneraries_trip_outline_title_tab',
	function ( $text ) {
		if ( ! is_string( $text ) ) {
			return $text;
		}

		// The real title call. Leave the placeholder alone.
		if ( '%s' === $text ) {
			return $text;
		}

		return str_replace( '%', '%%', $text );
	},
	9
);
