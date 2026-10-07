<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Bible_Frontend {

    public function __construct() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function enqueue_assets() {
        $settings = get_option( 'bible_settings', array() );

        if ( empty( $settings['enabled'] ) || $settings['enabled'] !== '1' ) {
            return;
        }

        wp_enqueue_style(
            'bible-frontend',
            BIBLE_PLUGIN_URL . 'assets/css/bible-frontend.css',
            array(),
            BIBLE_PLUGIN_VERSION
        );

        wp_enqueue_script(
            'bible-frontend',
            BIBLE_PLUGIN_URL . 'assets/js/bible-frontend.js',
            array(),
            BIBLE_PLUGIN_VERSION,
            true
        );

        // WordPress 6.3+ can defer this DOM-ready script; older versions keep
        // the existing footer loading. BibleData is still printed before it.
        wp_script_add_data( 'bible-frontend', 'strategy', 'defer' );

        // Build book map: alias => book_number
        $books   = Bible_DB::get_books();
        $aliases = get_option( 'bible_custom_aliases', array() );

        $book_map = array();
        foreach ( $books as $b ) {
            $book_map[ $b['short_name'] ] = intval( $b['book_number'] );
            $book_map[ $b['long_name'] ]  = intval( $b['book_number'] );
        }
        foreach ( $aliases as $a ) {
            if ( ! empty( $a['alias'] ) && ! empty( $a['book_number'] ) ) {
                $book_map[ $a['alias'] ] = intval( $a['book_number'] );
            }
        }

        wp_localize_script( 'bible-frontend', 'BibleData', array(
            'ajaxurl'      => admin_url( 'admin-ajax.php' ),
            'bookMap'      => $book_map,
            'popupTrigger' => $settings['popup_trigger'] ?? 'hover',
            'popupMaxWidth'=> intval( $settings['popup_maxwidth'] ?? 450 ),
        ) );
    }
}
