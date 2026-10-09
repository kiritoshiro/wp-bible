<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Bible_DB {

    /**
     * Create custom tables
     */
    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $sql_books = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bible_books (
            book_number INT NOT NULL,
            short_name VARCHAR(20) NOT NULL,
            long_name VARCHAR(100) NOT NULL,
            book_color VARCHAR(20) DEFAULT '',
            PRIMARY KEY (book_number)
        ) ENGINE=InnoDB $charset;";

        $sql_verses = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bible_verses (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            book_number INT NOT NULL,
            chapter INT NOT NULL,
            verse INT NOT NULL,
            text TEXT NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY book_chapter_verse (book_number, chapter, verse),
            KEY idx_book_chapter (book_number, chapter)
        ) ENGINE=InnoDB $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_books );
        dbDelta( $sql_verses );
    }

    public static function is_empty() {
        global $wpdb;
        $count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bible_verses" );
        return ( intval( $count ) === 0 );
    }

    /**
     * Import SQLite3 module into WP tables
     */
    public static function import_sqlite_module( $sqlite_path, $module_name = '' ) {
        global $wpdb;
        if ( ! class_exists( 'SQLite3' ) ) {
            return new WP_Error( 'no_sqlite', 'PHP SQLite3 extension is not available.' );
        }
        if ( ! is_file( $sqlite_path ) || filesize( $sqlite_path ) > 104857600 ||
            file_get_contents( $sqlite_path, false, null, 0, 16 ) !== "SQLite format 3\0" ) {
            return new WP_Error( 'invalid_module', 'Expected a SQLite3 database no larger than 100 MiB.' );
        }
        $db = null;
        $transaction = false;
        try {
            $db = new SQLite3( $sqlite_path, SQLITE3_OPEN_READONLY );
            $db->enableExceptions( true );
            $db->exec( 'PRAGMA query_only = ON' );
            $db->exec( 'PRAGMA trusted_schema = OFF' );
            foreach ( array( 'books', 'verses' ) as $table ) {
                $schema = $db->querySingle( "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = '$table'" );
                if ( ! is_string( $schema ) || ! preg_match( '/^CREATE\\s+TABLE\\s/i', $schema ) ) {
                    throw new RuntimeException( 'Missing module tables.' );
                }
                $columns = $db->query( "PRAGMA table_xinfo('$table')" );
                while ( $column = $columns->fetchArray( SQLITE3_ASSOC ) ) {
                    if ( ! empty( $column['hidden'] ) ) {
                        throw new RuntimeException( 'Generated or hidden module columns are not supported.' );
                    }
                }
            }
            // Validate the entire bounded input before touching the installed Bible.
            $books = array();
            $result = $db->query( 'SELECT book_number, short_name, long_name, book_color FROM books LIMIT 201' );
            while ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) {
                $number = filter_var( $row['book_number'], FILTER_VALIDATE_INT );
                if ( ! $number || $number < 1 || $number > 10000 || isset( $books[$number] ) ||
                    ! is_string( $row['short_name'] ) || ! is_string( $row['long_name'] ) ||
                    strlen( $row['short_name'] ) > 80 || strlen( $row['long_name'] ) > 400 ) {
                    throw new RuntimeException( 'Invalid book.' );
                }
                $books[$number] = array(
                    'book_number' => $number,
                    'short_name' => sanitize_text_field( $row['short_name'] ),
                    'long_name' => sanitize_text_field( $row['long_name'] ),
                    'book_color' => sanitize_hex_color( is_string( $row['book_color'] ) ? $row['book_color'] : '' ) ?: '',
                );
            }
            if ( ! $books || count( $books ) > 200 ) {
                throw new RuntimeException( 'Invalid book count.' );
            }
            $count = 0;
            $seen = array();
            $result = $db->query( 'SELECT book_number, chapter, verse, text FROM verses LIMIT 100001' );
            while ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) {
                foreach ( array( 'book_number', 'chapter', 'verse' ) as $key ) {
                    if ( false === filter_var( $row[$key], FILTER_VALIDATE_INT ) || $row[$key] < 1 || $row[$key] > 10000 ) {
                        throw new RuntimeException( 'Invalid verse reference.' );
                    }
                }
                $key = $row['book_number'] . ':' . $row['chapter'] . ':' . $row['verse'];
                if ( ! isset( $books[$row['book_number']] ) || isset( $seen[$key] ) ||
                    ! is_string( $row['text'] ) || strlen( $row['text'] ) > 16384 || ++$count > 100000 ) {
                    throw new RuntimeException( 'Invalid verse data.' );
                }
                $seen[$key] = true;
            }
            unset( $seen );
            if ( ! $count ) {
                throw new RuntimeException( 'Empty module.' );
            }
            // Rollback must be supported; never TRUNCATE the live tables.
            foreach ( array( 'bible_books', 'bible_verses' ) as $table ) {
                $engine = $wpdb->get_var( $wpdb->prepare(
                    'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                    $wpdb->prefix . $table
                ) );
                if ( strtoupper( (string) $engine ) !== 'INNODB' ) {
                    return new WP_Error( 'module_engine', 'Import requires InnoDB Bible tables. Existing data has been kept.' );
                }
            }
            if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
                throw new RuntimeException( 'Transaction failed.' );
            }
            $transaction = true;
            foreach ( array( 'bible_books', 'bible_verses' ) as $table ) {
                if ( false === $wpdb->query( $wpdb->prepare( "DELETE FROM %i", $wpdb->prefix . $table ) ) ) {
                    throw new RuntimeException( 'Database write failed.' );
                }
            }
            foreach ( $books as $book ) {
                if ( false === $wpdb->insert( $wpdb->prefix . 'bible_books', $book, array( '%d', '%s', '%s', '%s' ) ) ) {
                    throw new RuntimeException( 'Book write failed.' );
                }
            }
            $result = $db->query( 'SELECT book_number, chapter, verse, text FROM verses LIMIT 100001' );
            $batch = array();
            while ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) {
                $batch[] = $wpdb->prepare( '(%d,%d,%d,%s)', $row['book_number'], $row['chapter'], $row['verse'], $row['text'] );
                if ( count( $batch ) === 250 ) {
                    self::insert_verse_batch( $batch );
                    $batch = array();
                }
            }
            if ( $batch ) self::insert_verse_batch( $batch );
            if ( false === $wpdb->query( 'COMMIT' ) ) {
                throw new RuntimeException( 'Commit failed.' );
            }
            $transaction = false;
            // Optional metadata is not trusted HTML.
            $info = array();
            try {
                $info_schema = $db->querySingle( "SELECT sql FROM sqlite_master WHERE type='table' AND name='info'" );
                if ( is_string( $info_schema ) && preg_match( '/^CREATE\\s+TABLE\\s/i', $info_schema ) &&
                    stripos( $info_schema, 'GENERATED' ) === false && stripos( $info_schema, ' AS' ) === false ) {
                    $result = $db->query( 'SELECT name, value FROM info LIMIT 100' );
                    while ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) {
                        if ( is_string( $row['name'] ) && is_string( $row['value'] ) ) {
                            $info[ sanitize_key( substr( $row['name'], 0, 100 ) ) ] = sanitize_text_field( substr( $row['value'], 0, 4096 ) );
                        }
                    }
                }
            } catch ( Exception $e ) { /* Optional metadata may be absent. */ }
            update_option( 'bible_module_info', $info );
            update_option( 'bible_module_name', sanitize_text_field( $module_name ) );
            return $count;
        } catch ( Exception $e ) {
            if ( $transaction ) $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'module_import_failed', 'Invalid module or database write failure. Existing Bible data has been kept.' );
        } finally {
            if ( $db ) $db->close();
        }
    }

    private static function insert_verse_batch( $batch ) {
        global $wpdb;
        if ( false === $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Every tuple is prepared in import_sqlite_module; table prefix is trusted WP configuration.
            "INSERT INTO {$wpdb->prefix}bible_verses (book_number, chapter, verse, text) VALUES " . implode( ',', $batch )
        ) ) {
            throw new RuntimeException( 'Verse write failed.' );
        }
    }

    public static function get_books() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT book_number, short_name, long_name, book_color FROM {$wpdb->prefix}bible_books ORDER BY book_number",
            ARRAY_A
        );
    }

    public static function get_book( $book_number ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}bible_books WHERE book_number = %d",
            $book_number
        ), ARRAY_A );
    }

    /**
     * Get verses for a single chapter, optionally with verse range
     */
    public static function get_verses( $book_number, $chapter, $verse_start = null, $verse_end = null ) {
        global $wpdb;

        if ( $verse_start !== null && $verse_end !== null ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT chapter, verse, text FROM {$wpdb->prefix}bible_verses
                 WHERE book_number = %d AND chapter = %d AND verse >= %d AND verse <= %d
                 ORDER BY verse LIMIT 81",
                $book_number, $chapter, $verse_start, $verse_end
            ), ARRAY_A );
        } elseif ( $verse_start !== null ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT chapter, verse, text FROM {$wpdb->prefix}bible_verses
                 WHERE book_number = %d AND chapter = %d AND verse = %d",
                $book_number, $chapter, $verse_start
            ), ARRAY_A );
        } else {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT chapter, verse, text FROM {$wpdb->prefix}bible_verses
                 WHERE book_number = %d AND chapter = %d
                 ORDER BY verse LIMIT 81",
                $book_number, $chapter
            ), ARRAY_A );
        }
    }

    /**
     * Get verses across chapter range (e.g. chapters 4-6)
     */
    public static function get_chapter_range( $book_number, $chapter_start, $chapter_end ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT chapter, verse, text FROM {$wpdb->prefix}bible_verses
             WHERE book_number = %d AND chapter >= %d AND chapter <= %d
             ORDER BY chapter, verse LIMIT 81",
            $book_number, $chapter_start, $chapter_end
        ), ARRAY_A );
    }

    /**
     * Get verses for a cross-chapter verse range
     * e.g. 2 Kar 23,35 - 24,7 => chapter 23 v35+ then chapter 24 v1-7
     */
    public static function get_cross_chapter_verses( $book_number, $ch_from, $v_from, $ch_to, $v_to ) {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT chapter, verse, text FROM {$wpdb->prefix}bible_verses
             WHERE book_number = %d AND chapter >= %d AND chapter <= %d
             AND (chapter > %d OR verse >= %d) AND (chapter < %d OR verse <= %d)
             ORDER BY chapter, verse LIMIT 81",
            $book_number, $ch_from, $ch_to, $ch_from, $v_from, $ch_to, $v_to
        ), ARRAY_A );
    }

    /**
     * Revision of the default alias list. Activation seeds the alias option
     * only once, so aliases added later are merged in by upgrade_aliases().
     */
    const ALIAS_REVISION = 2;

    /**
     * Default Lithuanian aliases
     */
    public static function get_default_aliases() {
        return array_merge( self::get_base_aliases(), self::get_alias_additions() );
    }

    /**
     * Aliases added after the first release, by revision. Spellings and case
     * forms seen on adventistai.lt; phrase forms such as "Jono pirmas laiškas"
     * are recognised by bible-frontend.js instead.
     */
    public static function get_alias_additions( $since = 1 ) {
        $revisions = array(
            2 => array(
                array( 'alias' => 'Pakartoto įstatymo', 'book_number' => 50 ),
                array( 'alias' => '1 Metr', 'book_number' => 130 ),
                array( 'alias' => '2 Metr', 'book_number' => 140 ),
                array( 'alias' => 'Ezro', 'book_number' => 150 ),
                array( 'alias' => 'Ezra', 'book_number' => 150 ),
                array( 'alias' => 'Ezdros', 'book_number' => 150 ),
                array( 'alias' => 'Psalmyno', 'book_number' => 230 ),
                array( 'alias' => 'Psalmyne', 'book_number' => 230 ),
                array( 'alias' => 'Psalmėje', 'book_number' => 230 ),
                array( 'alias' => 'Koh', 'book_number' => 250 ),
                array( 'alias' => 'Ekl', 'book_number' => 250 ),
                array( 'alias' => 'Ezekielio', 'book_number' => 330 ),
                array( 'alias' => 'Ezekelio', 'book_number' => 330 ),
                array( 'alias' => 'Ezekielis', 'book_number' => 330 ),
                array( 'alias' => 'Ezek', 'book_number' => 330 ),
                array( 'alias' => 'Joėlio', 'book_number' => 360 ),
                array( 'alias' => 'Apaštalų', 'book_number' => 510 ),
                array( 'alias' => 'Apaštalų darbuose', 'book_number' => 510 ),
                array( 'alias' => 'Heb', 'book_number' => 650 ),
                array( 'alias' => '1 Pet', 'book_number' => 670 ),
                array( 'alias' => '2 Pet', 'book_number' => 680 ),
                array( 'alias' => 'Apreiškimas Jonui', 'book_number' => 730 ),
                array( 'alias' => 'Apreiškime Jonui', 'book_number' => 730 ),
                array( 'alias' => 'Apreiškimu Jonui', 'book_number' => 730 ),
                array( 'alias' => 'Apreiškime', 'book_number' => 730 ),
                array( 'alias' => 'Apreiškimų', 'book_number' => 730 ),
            ),
        );
        $aliases = array();
        foreach ( $revisions as $revision => $list ) {
            if ( $revision > $since ) $aliases = array_merge( $aliases, $list );
        }
        return $aliases;
    }

    /**
     * Adds the aliases of newer revisions to a site's alias list once. Aliases
     * the site already has, or removes afterwards, are left as they are.
     */
    public static function upgrade_aliases() {
        $revision = (int) get_option( 'bible_alias_revision', 1 );
        if ( $revision >= self::ALIAS_REVISION ) return;

        $aliases = get_option( 'bible_custom_aliases' );
        if ( ! is_array( $aliases ) ) $aliases = self::get_base_aliases();
        $known = array();
        foreach ( $aliases as $a ) {
            if ( is_array( $a ) && isset( $a['alias'] ) ) $known[ $a['alias'] ] = true;
        }
        foreach ( self::get_alias_additions( $revision ) as $a ) {
            if ( ! isset( $known[ $a['alias'] ] ) ) $aliases[] = $a;
        }
        update_option( 'bible_custom_aliases', $aliases );
        update_option( 'bible_alias_revision', self::ALIAS_REVISION );
    }

    /**
     * Aliases of the first release (revision 1)
     */
    private static function get_base_aliases() {
        return array(
            // Pr — Pradžios (10)
            array( 'alias' => 'Pradžios', 'book_number' => 10 ),
            array( 'alias' => 'Pradžios knyga', 'book_number' => 10 ),
            array( 'alias' => 'Pradžios knygos', 'book_number' => 10 ),
            array( 'alias' => 'Prad', 'book_number' => 10 ),
            array( 'alias' => 'Gen', 'book_number' => 10 ),
            array( 'alias' => 'Genezė', 'book_number' => 10 ),
            array( 'alias' => 'Genezės', 'book_number' => 10 ),
            // Iš — Išėjimo (20)
            array( 'alias' => 'Išėjimo', 'book_number' => 20 ),
            array( 'alias' => 'Išėjimo knyga', 'book_number' => 20 ),
            array( 'alias' => 'Išėjimo knygos', 'book_number' => 20 ),
            array( 'alias' => 'Išėj', 'book_number' => 20 ),
            array( 'alias' => 'Egz', 'book_number' => 20 ),
            array( 'alias' => 'Exodus', 'book_number' => 20 ),
            // Kun — Kunigų (30)
            array( 'alias' => 'Kunigų', 'book_number' => 30 ),
            array( 'alias' => 'Kunigų knyga', 'book_number' => 30 ),
            array( 'alias' => 'Kunigų knygos', 'book_number' => 30 ),
            array( 'alias' => 'Lev', 'book_number' => 30 ),
            array( 'alias' => 'Levitų', 'book_number' => 30 ),
            array( 'alias' => 'Kunig', 'book_number' => 30 ),
            // Sk — Skaičių (40)
            array( 'alias' => 'Skaičių', 'book_number' => 40 ),
            array( 'alias' => 'Skaičių knyga', 'book_number' => 40 ),
            array( 'alias' => 'Skaičių knygos', 'book_number' => 40 ),
            array( 'alias' => 'Skaič', 'book_number' => 40 ),
            array( 'alias' => 'Num', 'book_number' => 40 ),
            // Įst — Pakartoto Įstatymo (50)
            array( 'alias' => 'Pakartoto Įstatymo', 'book_number' => 50 ),
            array( 'alias' => 'Pakartoto Įstatymo knyga', 'book_number' => 50 ),
            array( 'alias' => 'Pakartoto Įstatymo knygos', 'book_number' => 50 ),
            array( 'alias' => 'Įstatymo', 'book_number' => 50 ),
            array( 'alias' => 'Įst', 'book_number' => 50 ),
            array( 'alias' => 'Pįst', 'book_number' => 50 ),
            array( 'alias' => 'Pak Įst', 'book_number' => 50 ),
            array( 'alias' => 'Deut', 'book_number' => 50 ),
            array( 'alias' => 'Deuteronomija', 'book_number' => 50 ),
            array( 'alias' => 'Deuteronomijos', 'book_number' => 50 ),
            // Joz — Jozuės (60)
            array( 'alias' => 'Jozuės', 'book_number' => 60 ),
            array( 'alias' => 'Jozuės knyga', 'book_number' => 60 ),
            array( 'alias' => 'Jozuės knygos', 'book_number' => 60 ),
            array( 'alias' => 'Jozuė', 'book_number' => 60 ),
            array( 'alias' => 'Joz', 'book_number' => 60 ),
            array( 'alias' => 'Josh', 'book_number' => 60 ),
            // Ts — Teisėjų (70)
            array( 'alias' => 'Teisėjų', 'book_number' => 70 ),
            array( 'alias' => 'Teisėjų knyga', 'book_number' => 70 ),
            array( 'alias' => 'Teisėjų knygos', 'book_number' => 70 ),
            array( 'alias' => 'Teis', 'book_number' => 70 ),
            array( 'alias' => 'Tsėj', 'book_number' => 70 ),
            // Rūt — Rūtos (80)
            array( 'alias' => 'Rūtos', 'book_number' => 80 ),
            array( 'alias' => 'Rūtos knyga', 'book_number' => 80 ),
            array( 'alias' => 'Rūtos knygos', 'book_number' => 80 ),
            array( 'alias' => 'Rūtis', 'book_number' => 80 ),
            array( 'alias' => 'Rut', 'book_number' => 80 ),
            // 1 Sam — 1 Samuelio (90)
            array( 'alias' => '1 Samuelio', 'book_number' => 90 ),
            array( 'alias' => '1 Samuelio knyga', 'book_number' => 90 ),
            array( 'alias' => '1 Samuelio knygos', 'book_number' => 90 ),
            array( 'alias' => 'I Samuelio', 'book_number' => 90 ),
            array( 'alias' => 'I Sam', 'book_number' => 90 ),
            array( 'alias' => 'Pirmoji Samuelio', 'book_number' => 90 ),
            array( 'alias' => 'Pirma Samuelio', 'book_number' => 90 ),
            // 2 Sam — 2 Samuelio (100)
            array( 'alias' => '2 Samuelio', 'book_number' => 100 ),
            array( 'alias' => '2 Samuelio knyga', 'book_number' => 100 ),
            array( 'alias' => '2 Samuelio knygos', 'book_number' => 100 ),
            array( 'alias' => 'II Samuelio', 'book_number' => 100 ),
            array( 'alias' => 'II Sam', 'book_number' => 100 ),
            array( 'alias' => 'Antroji Samuelio', 'book_number' => 100 ),
            array( 'alias' => 'Antra Samuelio', 'book_number' => 100 ),
            // 1 Kar — 1 Karalių (110)
            array( 'alias' => '1 Karalių', 'book_number' => 110 ),
            array( 'alias' => '1 Karalių knyga', 'book_number' => 110 ),
            array( 'alias' => '1 Karalių knygos', 'book_number' => 110 ),
            array( 'alias' => 'I Karalių', 'book_number' => 110 ),
            array( 'alias' => 'I Kar', 'book_number' => 110 ),
            array( 'alias' => 'Pirmoji Karalių', 'book_number' => 110 ),
            array( 'alias' => 'Pirma Karalių', 'book_number' => 110 ),
            // 2 Kar — 2 Karalių (120)
            array( 'alias' => '2 Karalių', 'book_number' => 120 ),
            array( 'alias' => '2 Karalių knyga', 'book_number' => 120 ),
            array( 'alias' => '2 Karalių knygos', 'book_number' => 120 ),
            array( 'alias' => 'II Karalių', 'book_number' => 120 ),
            array( 'alias' => 'II Kar', 'book_number' => 120 ),
            array( 'alias' => 'Antroji Karalių', 'book_number' => 120 ),
            array( 'alias' => 'Antra Karalių', 'book_number' => 120 ),
            // 1 Met — 1 Metraščių (130)
            array( 'alias' => '1 Metraščių', 'book_number' => 130 ),
            array( 'alias' => '1 Metraščių knyga', 'book_number' => 130 ),
            array( 'alias' => '1 Metraščių knygos', 'book_number' => 130 ),
            array( 'alias' => 'I Metraščių', 'book_number' => 130 ),
            array( 'alias' => 'I Met', 'book_number' => 130 ),
            array( 'alias' => 'Pirmoji Metraščių', 'book_number' => 130 ),
            array( 'alias' => 'Pirma Metraščių', 'book_number' => 130 ),
            array( 'alias' => '1 Kronikų', 'book_number' => 130 ),
            array( 'alias' => 'I Kronikų', 'book_number' => 130 ),
            // 2 Met — 2 Metraščių (140)
            array( 'alias' => '2 Metraščių', 'book_number' => 140 ),
            array( 'alias' => '2 Metraščių knyga', 'book_number' => 140 ),
            array( 'alias' => '2 Metraščių knygos', 'book_number' => 140 ),
            array( 'alias' => 'II Metraščių', 'book_number' => 140 ),
            array( 'alias' => 'II Met', 'book_number' => 140 ),
            array( 'alias' => 'Antroji Metraščių', 'book_number' => 140 ),
            array( 'alias' => 'Antra Metraščių', 'book_number' => 140 ),
            array( 'alias' => '2 Kronikų', 'book_number' => 140 ),
            array( 'alias' => 'II Kronikų', 'book_number' => 140 ),
            // Ezr — Ezros (150)
            array( 'alias' => 'Ezros', 'book_number' => 150 ),
            array( 'alias' => 'Ezros knyga', 'book_number' => 150 ),
            array( 'alias' => 'Ezros knygos', 'book_number' => 150 ),
            array( 'alias' => 'Ezdras', 'book_number' => 150 ),
            array( 'alias' => 'Ezdro', 'book_number' => 150 ),
            // Neh — Nehemijo (160)
            array( 'alias' => 'Nehemijo', 'book_number' => 160 ),
            array( 'alias' => 'Nehemijo knyga', 'book_number' => 160 ),
            array( 'alias' => 'Nehemijo knygos', 'book_number' => 160 ),
            array( 'alias' => 'Nehemijas', 'book_number' => 160 ),
            // Est — Esteros (190)
            array( 'alias' => 'Esteros', 'book_number' => 190 ),
            array( 'alias' => 'Esteros knyga', 'book_number' => 190 ),
            array( 'alias' => 'Esteros knygos', 'book_number' => 190 ),
            array( 'alias' => 'Estera', 'book_number' => 190 ),
            // Job — Jobo (220)
            array( 'alias' => 'Jobo', 'book_number' => 220 ),
            array( 'alias' => 'Jobo knyga', 'book_number' => 220 ),
            array( 'alias' => 'Jobo knygos', 'book_number' => 220 ),
            array( 'alias' => 'Jobas', 'book_number' => 220 ),
            // Ps — Psalmynas (230)
            array( 'alias' => 'Psalmynas', 'book_number' => 230 ),
            array( 'alias' => 'Psalmė', 'book_number' => 230 ),
            array( 'alias' => 'Psalmės', 'book_number' => 230 ),
            array( 'alias' => 'Psalmių', 'book_number' => 230 ),
            array( 'alias' => 'Psalm', 'book_number' => 230 ),
            array( 'alias' => 'Psal', 'book_number' => 230 ),
            // Pat — Patarlių (240)
            array( 'alias' => 'Patarlių', 'book_number' => 240 ),
            array( 'alias' => 'Patarlių knyga', 'book_number' => 240 ),
            array( 'alias' => 'Patarlių knygos', 'book_number' => 240 ),
            array( 'alias' => 'Patarlės', 'book_number' => 240 ),
            array( 'alias' => 'Patar', 'book_number' => 240 ),
            array( 'alias' => 'Salomono patarlės', 'book_number' => 240 ),
            array( 'alias' => 'Salomono patarlių', 'book_number' => 240 ),
            // Mok — Mokytojo (250)
            array( 'alias' => 'Mokytojo', 'book_number' => 250 ),
            array( 'alias' => 'Mokytojo knyga', 'book_number' => 250 ),
            array( 'alias' => 'Mokytojo knygos', 'book_number' => 250 ),
            array( 'alias' => 'Ekleziasto', 'book_number' => 250 ),
            array( 'alias' => 'Ekleziastas', 'book_number' => 250 ),
            array( 'alias' => 'Koheleto', 'book_number' => 250 ),
            array( 'alias' => 'Koheletas', 'book_number' => 250 ),
            // Gg — Giesmių giesmės (260)
            array( 'alias' => 'Giesmių giesmė', 'book_number' => 260 ),
            array( 'alias' => 'Giesmių giesmės', 'book_number' => 260 ),
            array( 'alias' => 'Giesmių giesmės knyga', 'book_number' => 260 ),
            array( 'alias' => 'Gg', 'book_number' => 260 ),
            array( 'alias' => 'Giesm', 'book_number' => 260 ),
            array( 'alias' => 'Salomono giesmė', 'book_number' => 260 ),
            array( 'alias' => 'Salomono giesmės', 'book_number' => 260 ),
            // Iz — Izaijo (290)
            array( 'alias' => 'Izaijo', 'book_number' => 290 ),
            array( 'alias' => 'Izaijo knyga', 'book_number' => 290 ),
            array( 'alias' => 'Izaijo knygos', 'book_number' => 290 ),
            array( 'alias' => 'Izaijas', 'book_number' => 290 ),
            array( 'alias' => 'Izai', 'book_number' => 290 ),
            array( 'alias' => 'Iz', 'book_number' => 290 ),
            array( 'alias' => 'Izaijas pranašas', 'book_number' => 290 ),
            // Jer — Jeremijo (300)
            array( 'alias' => 'Jeremijo', 'book_number' => 300 ),
            array( 'alias' => 'Jeremijo knyga', 'book_number' => 300 ),
            array( 'alias' => 'Jeremijo knygos', 'book_number' => 300 ),
            array( 'alias' => 'Jeremijas', 'book_number' => 300 ),
            array( 'alias' => 'Jerem', 'book_number' => 300 ),
            // Rd — Raudų (310)
            array( 'alias' => 'Raudų', 'book_number' => 310 ),
            array( 'alias' => 'Raudų knyga', 'book_number' => 310 ),
            array( 'alias' => 'Raudų knygos', 'book_number' => 310 ),
            array( 'alias' => 'Raudos', 'book_number' => 310 ),
            array( 'alias' => 'Jeremijo raudų', 'book_number' => 310 ),
            array( 'alias' => 'Jeremijo raudos', 'book_number' => 310 ),
            array( 'alias' => 'Rauda', 'book_number' => 310 ),
            // Ez — Ezechielio (330)
            array( 'alias' => 'Ezechielio', 'book_number' => 330 ),
            array( 'alias' => 'Ezechielio knyga', 'book_number' => 330 ),
            array( 'alias' => 'Ezechielio knygos', 'book_number' => 330 ),
            array( 'alias' => 'Ezechielis', 'book_number' => 330 ),
            array( 'alias' => 'Ezech', 'book_number' => 330 ),
            // Dan — Danieliaus (340)
            array( 'alias' => 'Danieliaus', 'book_number' => 340 ),
            array( 'alias' => 'Danieliaus knyga', 'book_number' => 340 ),
            array( 'alias' => 'Danieliaus knygos', 'book_number' => 340 ),
            array( 'alias' => 'Danielius', 'book_number' => 340 ),
            array( 'alias' => 'Daniel', 'book_number' => 340 ),
            // Oz — Ozėjo (350)
            array( 'alias' => 'Ozėjo', 'book_number' => 350 ),
            array( 'alias' => 'Ozėjo knyga', 'book_number' => 350 ),
            array( 'alias' => 'Ozėjo knygos', 'book_number' => 350 ),
            array( 'alias' => 'Ozėjas', 'book_number' => 350 ),
            array( 'alias' => 'Ozėj', 'book_number' => 350 ),
            array( 'alias' => 'Oze', 'book_number' => 350 ),
            // Jl — Joelio (360)
            array( 'alias' => 'Joelio', 'book_number' => 360 ),
            array( 'alias' => 'Joelio knyga', 'book_number' => 360 ),
            array( 'alias' => 'Joelio knygos', 'book_number' => 360 ),
            array( 'alias' => 'Joelis', 'book_number' => 360 ),
            // Am — Amoso (370)
            array( 'alias' => 'Amoso', 'book_number' => 370 ),
            array( 'alias' => 'Amoso knyga', 'book_number' => 370 ),
            array( 'alias' => 'Amoso knygos', 'book_number' => 370 ),
            array( 'alias' => 'Amosas', 'book_number' => 370 ),
            // Abd — Abdijo (380)
            array( 'alias' => 'Abdijo', 'book_number' => 380 ),
            array( 'alias' => 'Abdijo knyga', 'book_number' => 380 ),
            array( 'alias' => 'Abdijo knygos', 'book_number' => 380 ),
            array( 'alias' => 'Abdijas', 'book_number' => 380 ),
            array( 'alias' => 'Obadijo', 'book_number' => 380 ),
            array( 'alias' => 'Obadijas', 'book_number' => 380 ),
            // Jon — Jonos (390)
            array( 'alias' => 'Jonos', 'book_number' => 390 ),
            array( 'alias' => 'Jonos knyga', 'book_number' => 390 ),
            array( 'alias' => 'Jonos knygos', 'book_number' => 390 ),
            array( 'alias' => 'Jona', 'book_number' => 390 ),
            array( 'alias' => 'Pranašo Jonos', 'book_number' => 390 ),
            // Mch — Michėjo (400)
            array( 'alias' => 'Michėjo', 'book_number' => 400 ),
            array( 'alias' => 'Michėjo knyga', 'book_number' => 400 ),
            array( 'alias' => 'Michėjo knygos', 'book_number' => 400 ),
            array( 'alias' => 'Michėjas', 'book_number' => 400 ),
            array( 'alias' => 'Mich', 'book_number' => 400 ),
            // Nah — Nahumo (410)
            array( 'alias' => 'Nahumo', 'book_number' => 410 ),
            array( 'alias' => 'Nahumo knyga', 'book_number' => 410 ),
            array( 'alias' => 'Nahumo knygos', 'book_number' => 410 ),
            array( 'alias' => 'Nahumas', 'book_number' => 410 ),
            // Hab — Habakuko (420)
            array( 'alias' => 'Habakuko', 'book_number' => 420 ),
            array( 'alias' => 'Habakuko knyga', 'book_number' => 420 ),
            array( 'alias' => 'Habakuko knygos', 'book_number' => 420 ),
            array( 'alias' => 'Habakukas', 'book_number' => 420 ),
            // Sof — Sofonijo (430)
            array( 'alias' => 'Sofonijo', 'book_number' => 430 ),
            array( 'alias' => 'Sofonijo knyga', 'book_number' => 430 ),
            array( 'alias' => 'Sofonijo knygos', 'book_number' => 430 ),
            array( 'alias' => 'Sofonijas', 'book_number' => 430 ),
            // Ag — Agėjo (440)
            array( 'alias' => 'Agėjo', 'book_number' => 440 ),
            array( 'alias' => 'Agėjo knyga', 'book_number' => 440 ),
            array( 'alias' => 'Agėjo knygos', 'book_number' => 440 ),
            array( 'alias' => 'Agėjas', 'book_number' => 440 ),
            array( 'alias' => 'Hagajaus', 'book_number' => 440 ),
            array( 'alias' => 'Hagajus', 'book_number' => 440 ),
            // Zch — Zacharijo (450)
            array( 'alias' => 'Zacharijo', 'book_number' => 450 ),
            array( 'alias' => 'Zacharijo knyga', 'book_number' => 450 ),
            array( 'alias' => 'Zacharijo knygos', 'book_number' => 450 ),
            array( 'alias' => 'Zacharijas', 'book_number' => 450 ),
            array( 'alias' => 'Zach', 'book_number' => 450 ),
            // Mal — Malachijo (460)
            array( 'alias' => 'Malachijo', 'book_number' => 460 ),
            array( 'alias' => 'Malachijo knyga', 'book_number' => 460 ),
            array( 'alias' => 'Malachijo knygos', 'book_number' => 460 ),
            array( 'alias' => 'Malachijas', 'book_number' => 460 ),
            array( 'alias' => 'Malach', 'book_number' => 460 ),
            // Mt — Mato (470)
            array( 'alias' => 'Mato', 'book_number' => 470 ),
            array( 'alias' => 'Mato evangelija', 'book_number' => 470 ),
            array( 'alias' => 'Mato evangelijos', 'book_number' => 470 ),
            array( 'alias' => 'Evangelija pagal Matą', 'book_number' => 470 ),
            array( 'alias' => 'Evangelijos pagal Matą', 'book_number' => 470 ),
            array( 'alias' => 'Matas', 'book_number' => 470 ),
            array( 'alias' => 'Mat', 'book_number' => 470 ),
            // Mk — Morkaus (480)
            array( 'alias' => 'Morkaus', 'book_number' => 480 ),
            array( 'alias' => 'Morkaus evangelija', 'book_number' => 480 ),
            array( 'alias' => 'Morkaus evangelijos', 'book_number' => 480 ),
            array( 'alias' => 'Evangelija pagal Morkų', 'book_number' => 480 ),
            array( 'alias' => 'Evangelijos pagal Morkų', 'book_number' => 480 ),
            array( 'alias' => 'Morkus', 'book_number' => 480 ),
            array( 'alias' => 'Mork', 'book_number' => 480 ),
            // Lk — Luko (490)
            array( 'alias' => 'Luko', 'book_number' => 490 ),
            array( 'alias' => 'Luko evangelija', 'book_number' => 490 ),
            array( 'alias' => 'Luko evangelijos', 'book_number' => 490 ),
            array( 'alias' => 'Evangelija pagal Luką', 'book_number' => 490 ),
            array( 'alias' => 'Evangelijos pagal Luką', 'book_number' => 490 ),
            array( 'alias' => 'Lukas', 'book_number' => 490 ),
            array( 'alias' => 'Luk', 'book_number' => 490 ),
            // Jn — Jono (500)
            array( 'alias' => 'Jono', 'book_number' => 500 ),
            array( 'alias' => 'Jono evangelija', 'book_number' => 500 ),
            array( 'alias' => 'Jono evangelijos', 'book_number' => 500 ),
            array( 'alias' => 'Evangelija pagal Joną', 'book_number' => 500 ),
            array( 'alias' => 'Evangelijos pagal Joną', 'book_number' => 500 ),
            array( 'alias' => 'Jonas', 'book_number' => 500 ),
            // Apd — Apaštalų darbų (510)
            array( 'alias' => 'Apaštalų darbų', 'book_number' => 510 ),
            array( 'alias' => 'Apaštalų darbai', 'book_number' => 510 ),
            array( 'alias' => 'Apaštalų darbų knyga', 'book_number' => 510 ),
            array( 'alias' => 'Apaštalų darbų knygos', 'book_number' => 510 ),
            array( 'alias' => 'Ap darbų', 'book_number' => 510 ),
            array( 'alias' => 'Ap darb', 'book_number' => 510 ),
            array( 'alias' => 'Apd', 'book_number' => 510 ),
            // Rom — Romiečiams (520)
            array( 'alias' => 'Romiečiams', 'book_number' => 520 ),
            array( 'alias' => 'Laiškas romiečiams', 'book_number' => 520 ),
            array( 'alias' => 'Laiškas Romiečiams', 'book_number' => 520 ),
            array( 'alias' => 'Pauliaus laiškas romiečiams', 'book_number' => 520 ),
            array( 'alias' => 'Romiečių', 'book_number' => 520 ),
            // 1 Kor — 1 Korintiečiams (530)
            array( 'alias' => '1 Korintiečiams', 'book_number' => 530 ),
            array( 'alias' => 'I Korintiečiams', 'book_number' => 530 ),
            array( 'alias' => '1 Kor', 'book_number' => 530 ),
            array( 'alias' => 'I Kor', 'book_number' => 530 ),
            array( 'alias' => 'Pirmas laiškas korintiečiams', 'book_number' => 530 ),
            array( 'alias' => 'Pirmasis laiškas korintiečiams', 'book_number' => 530 ),
            array( 'alias' => 'Pirmoji Korintiečiams', 'book_number' => 530 ),
            // 2 Kor — 2 Korintiečiams (540)
            array( 'alias' => '2 Korintiečiams', 'book_number' => 540 ),
            array( 'alias' => 'II Korintiečiams', 'book_number' => 540 ),
            array( 'alias' => '2 Kor', 'book_number' => 540 ),
            array( 'alias' => 'II Kor', 'book_number' => 540 ),
            array( 'alias' => 'Antras laiškas korintiečiams', 'book_number' => 540 ),
            array( 'alias' => 'Antrasis laiškas korintiečiams', 'book_number' => 540 ),
            array( 'alias' => 'Antroji Korintiečiams', 'book_number' => 540 ),
            // Gal — Galatams (550)
            array( 'alias' => 'Galatams', 'book_number' => 550 ),
            array( 'alias' => 'Laiškas galatams', 'book_number' => 550 ),
            array( 'alias' => 'Laiškas Galatams', 'book_number' => 550 ),
            array( 'alias' => 'Galatų', 'book_number' => 550 ),
            // Ef — Efeziečiams (560)
            array( 'alias' => 'Efeziečiams', 'book_number' => 560 ),
            array( 'alias' => 'Laiškas efeziečiams', 'book_number' => 560 ),
            array( 'alias' => 'Laiškas Efeziečiams', 'book_number' => 560 ),
            array( 'alias' => 'Efez', 'book_number' => 560 ),
            array( 'alias' => 'Efeziečių', 'book_number' => 560 ),
            // Fil — Filipiečiams (570)
            array( 'alias' => 'Filipiečiams', 'book_number' => 570 ),
            array( 'alias' => 'Laiškas filipiečiams', 'book_number' => 570 ),
            array( 'alias' => 'Laiškas Filipiečiams', 'book_number' => 570 ),
            array( 'alias' => 'Filip', 'book_number' => 570 ),
            array( 'alias' => 'Filipiečių', 'book_number' => 570 ),
            // Kol — Kolosiečiams (580)
            array( 'alias' => 'Kolosiečiams', 'book_number' => 580 ),
            array( 'alias' => 'Laiškas kolosiečiams', 'book_number' => 580 ),
            array( 'alias' => 'Laiškas Kolosiečiams', 'book_number' => 580 ),
            array( 'alias' => 'Kolos', 'book_number' => 580 ),
            array( 'alias' => 'Kolosiečių', 'book_number' => 580 ),
            // 1 Tes — 1 Tesalonikiečiams (590)
            array( 'alias' => '1 Tesalonikiečiams', 'book_number' => 590 ),
            array( 'alias' => 'I Tesalonikiečiams', 'book_number' => 590 ),
            array( 'alias' => '1 Tes', 'book_number' => 590 ),
            array( 'alias' => 'I Tes', 'book_number' => 590 ),
            array( 'alias' => 'Pirmoji Tesalonikiečiams', 'book_number' => 590 ),
            array( 'alias' => '1 Tesalon', 'book_number' => 590 ),
            // 2 Tes — 2 Tesalonikiečiams (600)
            array( 'alias' => '2 Tesalonikiečiams', 'book_number' => 600 ),
            array( 'alias' => 'II Tesalonikiečiams', 'book_number' => 600 ),
            array( 'alias' => '2 Tes', 'book_number' => 600 ),
            array( 'alias' => 'II Tes', 'book_number' => 600 ),
            array( 'alias' => 'Antroji Tesalonikiečiams', 'book_number' => 600 ),
            array( 'alias' => '2 Tesalon', 'book_number' => 600 ),
            // 1 Tim — 1 Timotiejui (610)
            array( 'alias' => '1 Timotiejui', 'book_number' => 610 ),
            array( 'alias' => 'I Timotiejui', 'book_number' => 610 ),
            array( 'alias' => '1 Tim', 'book_number' => 610 ),
            array( 'alias' => 'I Tim', 'book_number' => 610 ),
            array( 'alias' => 'Pirmoji Timotiejui', 'book_number' => 610 ),
            // 2 Tim — 2 Timotiejui (620)
            array( 'alias' => '2 Timotiejui', 'book_number' => 620 ),
            array( 'alias' => 'II Timotiejui', 'book_number' => 620 ),
            array( 'alias' => '2 Tim', 'book_number' => 620 ),
            array( 'alias' => 'II Tim', 'book_number' => 620 ),
            array( 'alias' => 'Antroji Timotiejui', 'book_number' => 620 ),
            // Tit — Titui (630)
            array( 'alias' => 'Titui', 'book_number' => 630 ),
            array( 'alias' => 'Laiškas Titui', 'book_number' => 630 ),
            array( 'alias' => 'Titas', 'book_number' => 630 ),
            // Fm — Filemonui (640)
            array( 'alias' => 'Filemonui', 'book_number' => 640 ),
            array( 'alias' => 'Laiškas Filemonui', 'book_number' => 640 ),
            array( 'alias' => 'Filemonas', 'book_number' => 640 ),
            array( 'alias' => 'Filem', 'book_number' => 640 ),
            // Hbr — Hebrajams (650)
            array( 'alias' => 'Hebrajams', 'book_number' => 650 ),
            array( 'alias' => 'Laiškas hebrajams', 'book_number' => 650 ),
            array( 'alias' => 'Laiškas Hebrajams', 'book_number' => 650 ),
            array( 'alias' => 'Žydams', 'book_number' => 650 ),
            array( 'alias' => 'Žyd', 'book_number' => 650 ),
            array( 'alias' => 'Hebr', 'book_number' => 650 ),
            // Jok — Jokūbo (660)
            array( 'alias' => 'Jokūbo', 'book_number' => 660 ),
            array( 'alias' => 'Jokūbo laiškas', 'book_number' => 660 ),
            array( 'alias' => 'Jokūbas', 'book_number' => 660 ),
            // 1 Pt — 1 Petro (670)
            array( 'alias' => '1 Petro', 'book_number' => 670 ),
            array( 'alias' => 'I Petro', 'book_number' => 670 ),
            array( 'alias' => '1 Pt', 'book_number' => 670 ),
            array( 'alias' => 'I Pt', 'book_number' => 670 ),
            array( 'alias' => 'Pirmoji Petro', 'book_number' => 670 ),
            array( 'alias' => 'Pirmas Petro laiškas', 'book_number' => 670 ),
            // 2 Pt — 2 Petro (680)
            array( 'alias' => '2 Petro', 'book_number' => 680 ),
            array( 'alias' => 'II Petro', 'book_number' => 680 ),
            array( 'alias' => '2 Pt', 'book_number' => 680 ),
            array( 'alias' => 'II Pt', 'book_number' => 680 ),
            array( 'alias' => 'Antroji Petro', 'book_number' => 680 ),
            array( 'alias' => 'Antras Petro laiškas', 'book_number' => 680 ),
            // 1 Jn — 1 Jono (690)
            array( 'alias' => '1 Jono', 'book_number' => 690 ),
            array( 'alias' => 'I Jono', 'book_number' => 690 ),
            array( 'alias' => '1 Jn', 'book_number' => 690 ),
            array( 'alias' => 'I Jn', 'book_number' => 690 ),
            array( 'alias' => 'Pirmoji Jono', 'book_number' => 690 ),
            array( 'alias' => 'Pirmas Jono laiškas', 'book_number' => 690 ),
            // 2 Jn — 2 Jono (700)
            array( 'alias' => '2 Jono', 'book_number' => 700 ),
            array( 'alias' => 'II Jono', 'book_number' => 700 ),
            array( 'alias' => '2 Jn', 'book_number' => 700 ),
            array( 'alias' => 'II Jn', 'book_number' => 700 ),
            array( 'alias' => 'Antroji Jono', 'book_number' => 700 ),
            array( 'alias' => 'Antras Jono laiškas', 'book_number' => 700 ),
            // 3 Jn — 3 Jono (710)
            array( 'alias' => '3 Jono', 'book_number' => 710 ),
            array( 'alias' => 'III Jono', 'book_number' => 710 ),
            array( 'alias' => '3 Jn', 'book_number' => 710 ),
            array( 'alias' => 'III Jn', 'book_number' => 710 ),
            array( 'alias' => 'Trečioji Jono', 'book_number' => 710 ),
            array( 'alias' => 'Trečias Jono laiškas', 'book_number' => 710 ),
            // Jud — Judo (720)
            array( 'alias' => 'Judo', 'book_number' => 720 ),
            array( 'alias' => 'Judo laiškas', 'book_number' => 720 ),
            array( 'alias' => 'Judas', 'book_number' => 720 ),
            // Apr — Apreiškimo Jonui (730)
            array( 'alias' => 'Apreiškimo', 'book_number' => 730 ),
            array( 'alias' => 'Apreiškimo Jonui', 'book_number' => 730 ),
            array( 'alias' => 'Apreiškimo knyga', 'book_number' => 730 ),
            array( 'alias' => 'Apreiškimo knygos', 'book_number' => 730 ),
            array( 'alias' => 'Apreiškimas', 'book_number' => 730 ),
            array( 'alias' => 'Apokalipsė', 'book_number' => 730 ),
            array( 'alias' => 'Apokalipsės', 'book_number' => 730 ),
            array( 'alias' => 'Apr', 'book_number' => 730 ),
            array( 'alias' => 'Apre', 'book_number' => 730 ),
        );
    }
}
