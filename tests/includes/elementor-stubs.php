<?php
/**
 * Just enough of Elementor to load the trip card widget class.
 *
 * Elementor is not installed in the test site. The card's label, footnote and
 * markup logic does not touch Elementor, only the class hierarchy does, so an
 * empty base class is enough. Skipped when the real one is present.
 *
 * @package WPTravelAddons\Tests
 */

namespace Elementor {
    if (!class_exists('Elementor\Widget_Base', false)) {
        abstract class Widget_Base {
        }
    }
}
