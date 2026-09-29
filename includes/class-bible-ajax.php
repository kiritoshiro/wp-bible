<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Bible_Ajax {

    public function __construct() {
        add_action( 'wp_ajax_bible_get_verse', array( $this, 'get_verse' ) );
        add_action( 'wp_ajax_nopriv_bible_get_verse', array( $this, 'get_verse' ) );
    }

    /**
     * AJAX endpoint: get verse text
     *
     * Params (via GET):
     *   book_number  (required)
     *   chapter      (required) – chapter start
     *   verse_start  (optional)
     *   verse_end    (optional)
     *   chapter_end  (optional) – for chapter ranges (ch4-6) OR cross-chapter verse ranges
     *   verse_end_ch (optional) – verse in the end chapter for cross-chapter ranges (e.g. 24,7)
     *   mode         (optional) – "cross_chapter" for cross-chapter verse ranges
     */
    public function get_verse() {
        foreach ( array( 'book_number', 'chapter', 'verse_start', 'verse_end', 'chapter_end', 'verse_end_ch' ) as $key ) {
            if ( isset( $_GET[$key] ) && $_GET[$key] !== '' &&
                ( ! is_scalar( $_GET[$key] ) || ! preg_match( '/^[0-9]{1,5}$/D', (string) $_GET[$key] ) ||
                  intval( $_GET[$key] ) < 1 || intval( $_GET[$key] ) > 10000 ) ) {
                wp_send_json_error( array( 'message' => 'Invalid reference' ), 400 );
            }
        }
        if ( isset( $_GET['mode'] ) && ! in_array( $_GET['mode'], array( '', 'cross_chapter' ), true ) ) {
            wp_send_json_error( array( 'message' => 'Invalid reference mode' ), 400 );
        }
        $book_number  = isset( $_GET['book_number'] ) ? intval( $_GET['book_number'] ) : 0;
        $chapter      = isset( $_GET['chapter'] ) ? intval( $_GET['chapter'] ) : 0;
        $verse_start  = isset( $_GET['verse_start'] ) && $_GET['verse_start'] !== '' ? intval( $_GET['verse_start'] ) : null;
        $verse_end    = isset( $_GET['verse_end'] ) && $_GET['verse_end'] !== '' ? intval( $_GET['verse_end'] ) : null;
        $chapter_end  = isset( $_GET['chapter_end'] ) && $_GET['chapter_end'] !== '' ? intval( $_GET['chapter_end'] ) : null;
        $verse_end_ch = isset( $_GET['verse_end_ch'] ) && $_GET['verse_end_ch'] !== '' ? intval( $_GET['verse_end_ch'] ) : null;
        $mode         = isset( $_GET['mode'] ) ? sanitize_text_field( $_GET['mode'] ) : '';

        if ( ! $book_number || ! $chapter ) {
            wp_send_json_error( array( 'message' => 'Netinkama nuoroda / Invalid reference' ) );
        }

        $book = Bible_DB::get_book( $book_number );
        if ( ! $book ) {
            wp_send_json_error( array( 'message' => 'Knyga nerasta / Book not found' ) );
        }

        // ── Cross-chapter verse range: e.g. 23,35 - 24,7 ──
        if ( $mode === 'cross_chapter' && $chapter_end !== null && $verse_start !== null && $verse_end_ch !== null ) {
            $verses = Bible_DB::get_cross_chapter_verses(
                $book_number, $chapter, $verse_start, $chapter_end, $verse_end_ch
            );

            if ( empty( $verses ) ) {
                wp_send_json_error( array( 'message' => 'Eilutės nerastos' ) );
            }

            $total = count( $verses );
            $truncated = $total > 80;
            if ( $truncated ) $verses = array_slice( $verses, 0, 80 );

            $html = self::format_multi_chapter_verses( $verses, $book );
            if ( $truncated ) {
                $html .= '<p class="bible-truncated"><em>… (rodoma tik dalis eilučių)</em></p>';
            }

            $title = $book['long_name'] . ' ' . $chapter . ',' . $verse_start . '–' . $chapter_end . ',' . $verse_end_ch;

            wp_send_json_success( array( 'html' => $html, 'title' => $title ) );
        }

        // ── Chapter range: e.g. chapters 4-6 ──
        if ( $chapter_end !== null && $chapter_end > $chapter && $verse_start === null ) {
            $verses = Bible_DB::get_chapter_range( $book_number, $chapter, $chapter_end );

            if ( empty( $verses ) ) {
                wp_send_json_error( array( 'message' => 'Eilutės nerastos' ) );
            }

            $total = count( $verses );
            $truncated = $total > 80;
            if ( $truncated ) $verses = array_slice( $verses, 0, 80 );

            $html = self::format_multi_chapter_verses( $verses, $book );
            if ( $truncated ) {
                $html .= '<p class="bible-truncated"><em>… (rodoma tik dalis eilučių)</em></p>';
            }

            $title = $book['long_name'] . ' ' . $chapter . '–' . $chapter_end;

            wp_send_json_success( array( 'html' => $html, 'title' => $title ) );
        }

        // ── Single chapter / verse range ──
        if ( $verse_end !== null && $verse_end > 0 ) {
            $verses = Bible_DB::get_verses( $book_number, $chapter, $verse_start, $verse_end );
        } elseif ( $verse_start !== null ) {
            $verses = Bible_DB::get_verses( $book_number, $chapter, $verse_start );
        } else {
            $verses = Bible_DB::get_verses( $book_number, $chapter );
        }

        if ( empty( $verses ) ) {
            wp_send_json_error( array( 'message' => 'Eilutės nerastos' ) );
        }

        $total = count( $verses );
        $truncated = $total > 50;
        if ( $truncated ) $verses = array_slice( $verses, 0, 50 );

        $html = self::format_verses( $verses );
        if ( $truncated ) {
            $html .= '<p class="bible-truncated"><em>… (rodoma tik dalis eilučių)</em></p>';
        }

        // Build title
        $title = $book['long_name'] . ' ' . $chapter;
        if ( $verse_start && $verse_end && $verse_end > $verse_start ) {
            $title .= ',' . $verse_start . '-' . $verse_end;
        } elseif ( $verse_start ) {
            $title .= ',' . $verse_start;
        }

        wp_send_json_success( array( 'html' => $html, 'title' => $title ) );
    }

    /**
     * Format single-chapter verses
     */
    private static function format_verses( $verses ) {
        $html = '';
        foreach ( $verses as $v ) {
            $text = self::clean_verse_text( $v['text'] );
            $html .= '<span class="bible-verse-num">' . intval( $v['verse'] ) . '</span> ' . $text . ' ';
        }
        return '<div class="bible-verse-text">' . trim( $html ) . '</div>';
    }

    /**
     * Format multi-chapter verses (groups by chapter)
     */
    private static function format_multi_chapter_verses( $verses, $book ) {
        $html = '';
        $current_chapter = 0;
        foreach ( $verses as $v ) {
            $ch = intval( $v['chapter'] );
            if ( $ch !== $current_chapter ) {
                if ( $current_chapter !== 0 ) {
                    $html .= '</div>';
                }
                $html .= '<div class="bible-chapter-section"><strong class="bible-chapter-label">' . esc_html( $book['short_name'] ) . ' ' . $ch . '</strong><br>';
                $current_chapter = $ch;
            }
            $text = self::clean_verse_text( $v['text'] );
            $html .= '<span class="bible-verse-num">' . intval( $v['verse'] ) . '</span> ' . $text . ' ';
        }
        if ( $current_chapter !== 0 ) {
            $html .= '</div>';
        }
        return '<div class="bible-verse-text">' . $html . '</div>';
    }

    /**
     * Clean SQLite markup from verse text
     */
    private static function clean_verse_text( $text ) {
        $text = preg_replace( '/<pb\s*\/?>/', '', $text );
        $text = preg_replace( '/<f>.*?<\/f>/', '', $text );
        $text = preg_replace( '/<t>|<\/t>/', '', $text );
        $text = preg_replace( '/<br\s*\/?>/', ' ', $text );
        $text = preg_replace( '/<S>.*?<\/S>/', '', $text );
        $text = preg_replace( '/<RF>.*?<Rf>/', '', $text );
        $text = wp_kses( $text, array(
            'i' => array(), 'em' => array(), 'b' => array(),
            'strong' => array(), 'span' => array(),
        ) );
        return trim( $text );
    }
}
