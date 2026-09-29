<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Bible_Admin {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_init', array( $this, 'handle_actions' ) );
        add_action( 'admin_init', array( $this, 'handle_export' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
    }

    public function add_menu() {
        add_menu_page(
            'Bible',
            'Bible',
            'manage_options',
            'bible-settings',
            array( $this, 'render_settings_page' ),
            'dashicons-book-alt',
            80
        );
        add_submenu_page( 'bible-settings', 'Nustatymai', 'Nustatymai', 'manage_options', 'bible-settings', array( $this, 'render_settings_page' ) );
        add_submenu_page( 'bible-settings', 'Modulis', 'Modulis', 'manage_options', 'bible-module', array( $this, 'render_module_page' ) );
        add_submenu_page( 'bible-settings', 'Šablonai', 'Šablonai', 'manage_options', 'bible-patterns', array( $this, 'render_patterns_page' ) );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( strpos( $hook, 'bible' ) === false ) return;
        wp_enqueue_style( 'bible-admin', BIBLE_PLUGIN_URL . 'assets/css/bible-admin.css', array(), BIBLE_PLUGIN_VERSION );
    }

    /**
     * Handle export (runs early before headers are sent)
     */
    public function handle_export() {
        if ( ! isset( $_GET['bible_export'] ) || ! current_user_can( 'manage_options' ) ) return;

        check_admin_referer( 'bible_export_nonce' );

        $type = in_array( $_GET['bible_export'], array( 'aliases', 'all' ), true ) ? $_GET['bible_export'] : 'aliases';

        $export = array(
            'plugin'     => 'bible',
            'version'    => BIBLE_PLUGIN_VERSION,
            'exported'   => current_time( 'mysql' ),
        );

        if ( $type === 'aliases' || $type === 'all' ) {
            $export['aliases'] = get_option( 'bible_custom_aliases', array() );
        }
        if ( $type === 'all' ) {
            $export['settings']    = get_option( 'bible_settings', array() );
            $export['module_name'] = get_option( 'bible_module_name', '' );
        }

        $filename = 'bible-' . $type . '-' . date( 'Y-m-d' ) . '.json';

        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        echo wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
        exit;
    }

    /**
     * Handle form submissions
     */
    private static function uploaded_file( $key, $extension, $limit ) {
        $file = $_FILES[$key] ?? null;
        if ( ! is_array( $file ) || ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ||
            ! is_string( $file['tmp_name'] ?? null ) || ! is_string( $file['name'] ?? null ) ||
            ! is_uploaded_file( $file['tmp_name'] ) ||
            strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) !== $extension ||
            filesize( $file['tmp_name'] ) > $limit || filesize( $file['tmp_name'] ) < 1 ) {
            return new WP_Error( 'invalid_upload', 'Invalid upload, extension, or file size.' );
        }
        return $file;
    }

    private static function normalize_settings( $settings ) {
        $settings = is_array( $settings ) ? $settings : array();
        return array(
            'enabled' => ( $settings['enabled'] ?? '0' ) === '1' ? '1' : '0',
            'popup_trigger' => ( $settings['popup_trigger'] ?? '' ) === 'click' ? 'click' : 'hover',
            'popup_maxwidth' => is_scalar( $settings['popup_maxwidth'] ?? null ) ?
                max( 200, min( 800, intval( $settings['popup_maxwidth'] ) ) ) : 450,
        );
    }

    private static function normalize_aliases( $aliases ) {
        $clean = array();
        if ( ! is_array( $aliases ) || count( $aliases ) > 2000 ) {
            return new WP_Error( 'invalid_aliases', 'Expected at most 2000 aliases.' );
        }
        $books = array_column( Bible_DB::get_books(), 'book_number' );
        foreach ( $aliases as $alias ) {
            if ( ! is_array( $alias ) || ! is_string( $alias['alias'] ?? null ) ||
                strlen( $alias['alias'] ) > 200 || ! is_scalar( $alias['book_number'] ?? null ) ||
                false === filter_var( $alias['book_number'], FILTER_VALIDATE_INT ) ||
                ! in_array( intval( $alias['book_number'] ), $books ) ) {
                return new WP_Error( 'invalid_aliases', 'Invalid alias or unknown book.' );
            }
            $text = trim( sanitize_text_field( $alias['alias'] ) );
            $number = intval( $alias['book_number'] );
            if ( $text !== '' ) $clean[$number . ':' . $text] = array( 'alias' => $text, 'book_number' => $number );
        }
        $clean = array_values( $clean );
        usort( $clean, function( $a, $b ) {
            return ( $a['book_number'] <=> $b['book_number'] ) ?: strcmp( $a['alias'], $b['alias'] );
        } );
        return $clean;
    }

    public function handle_actions() {
        // A valid nonce is not permission to manage site settings.
        if ( ! current_user_can( 'manage_options' ) || ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) return;

        if ( isset( $_POST['bible_save_settings'] ) ) {
            check_admin_referer( 'bible_settings_nonce' );
            update_option( 'bible_settings', self::normalize_settings( wp_unslash( $_POST ) ) );
            add_settings_error( 'bible_messages', 'bible_updated', 'Settings saved.', 'updated' );
        }
        if ( isset( $_POST['bible_upload_module'] ) ) {
            check_admin_referer( 'bible_module_nonce' );
            $file = self::uploaded_file( 'bible_module_file', 'sqlite3', 104857600 );
            $result = is_wp_error( $file ) ? $file : Bible_DB::import_sqlite_module(
                $file['tmp_name'], pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_FILENAME )
            );
            // Import directly from PHP's temporary upload. Never publish or overwrite module files.
            add_settings_error( 'bible_messages', 'bible_import',
                is_wp_error( $result ) ? $result->get_error_message() : 'Imported verses: ' . $result,
                is_wp_error( $result ) ? 'error' : 'updated' );
        }
        if ( isset( $_POST['bible_import_aliases'] ) ) {
            check_admin_referer( 'bible_import_nonce' );
            $file = self::uploaded_file( 'bible_import_file', 'json', 1048576 );
            $data = is_wp_error( $file ) ? null : json_decode( file_get_contents( $file['tmp_name'] ), true, 16 );
            if ( ! is_array( $data ) || ( $data['plugin'] ?? '' ) !== 'bible' ) {
                add_settings_error( 'bible_messages', 'bible_error', 'Invalid Bible JSON export.', 'error' );
                return;
            }
            $aliases = self::normalize_aliases( $data['aliases'] ?? array() );
            if ( ! is_wp_error( $aliases ) && ( $_POST['import_mode'] ?? '' ) !== 'replace' ) {
                $existing = get_option( 'bible_custom_aliases', array() );
                $aliases = self::normalize_aliases( array_merge( is_array( $existing ) ? $existing : array(), $aliases ) );
            }
            if ( is_wp_error( $aliases ) ) {
                add_settings_error( 'bible_messages', 'bible_error', $aliases->get_error_message(), 'error' );
                return;
            }
            if ( array_key_exists( 'aliases', $data ) ) update_option( 'bible_custom_aliases', $aliases );
            if ( isset( $data['settings'] ) ) update_option( 'bible_settings', self::normalize_settings( $data['settings'] ) );
            add_settings_error( 'bible_messages', 'bible_updated', 'Bible settings imported.', 'updated' );
        }
        if ( isset( $_POST['bible_save_aliases'] ) ) {
            check_admin_referer( 'bible_aliases_nonce' );
            $aliases = array();
            $texts = wp_unslash( $_POST['alias_text'] ?? array() );
            $numbers = $_POST['alias_book'] ?? array();
            if ( ! is_array( $texts ) || ! is_array( $numbers ) || count( $texts ) > 2000 ) {
                add_settings_error( 'bible_messages', 'bible_error', 'Invalid aliases.', 'error' );
                return;
            }
            foreach ( $texts as $i => $text ) {
                $aliases[] = array( 'alias' => $text, 'book_number' => $numbers[$i] ?? 0 );
            }
            $aliases = self::normalize_aliases( $aliases );
            if ( is_wp_error( $aliases ) ) {
                add_settings_error( 'bible_messages', 'bible_error', $aliases->get_error_message(), 'error' );
                return;
            }
            update_option( 'bible_custom_aliases', $aliases );
            add_settings_error( 'bible_messages', 'bible_updated', 'Aliases saved.', 'updated' );
        }
    }

    /* ──────────────────────────────────────────────
       Settings page
       ────────────────────────────────────────────── */
    public function render_settings_page() {
        $settings    = get_option( 'bible_settings', array() );
        $module_name = get_option( 'bible_module_name', '' );
        $module_info = get_option( 'bible_module_info', array() );
        $books       = Bible_DB::get_books();
        ?>
        <div class="wrap bible-admin-wrap">
            <h1><span class="dashicons dashicons-book-alt"></span> Bible – Nustatymai</h1>
            <?php settings_errors( 'bible_messages' ); ?>

            <div class="bible-admin-card">
                <h2>Dabartinis modulis</h2>
                <table class="form-table">
                    <tr><th>Modulis:</th><td><strong><?php echo esc_html( $module_name ?: '—' ); ?></strong></td></tr>
                    <?php if ( ! empty( $module_info['description'] ) ) : ?>
                        <tr><th>Aprašymas:</th><td><?php echo esc_html( $module_info['description'] ); ?></td></tr>
                    <?php endif; ?>
                    <tr><th>Knygų:</th><td><?php echo count( $books ); ?></td></tr>
                </table>
            </div>

            <div class="bible-admin-card">
                <h2>Nustatymai</h2>
                <form method="post">
                    <?php wp_nonce_field( 'bible_settings_nonce' ); ?>
                    <table class="form-table">
                        <tr>
                            <th>Įjungta</th>
                            <td><label><input type="checkbox" name="enabled" value="1" <?php checked( $settings['enabled'] ?? '1', '1' ); ?>> Aktyvuoti Biblijos nuorodų atpažinimą</label></td>
                        </tr>
                        <tr>
                            <th>Popup paleidimas</th>
                            <td>
                                <select name="popup_trigger">
                                    <option value="hover" <?php selected( $settings['popup_trigger'] ?? 'hover', 'hover' ); ?>>Užvedus pelę (hover)</option>
                                    <option value="click" <?php selected( $settings['popup_trigger'] ?? 'hover', 'click' ); ?>>Paspaudus (click)</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Popup max plotis (px)</th>
                            <td><input type="number" name="popup_maxwidth" value="<?php echo intval( $settings['popup_maxwidth'] ?? 450 ); ?>" min="200" max="800" class="small-text"></td>
                        </tr>
                    </table>
                    <p class="submit"><input type="submit" name="bible_save_settings" class="button-primary" value="Išsaugoti nustatymus"></p>
                </form>
            </div>

            <div class="bible-admin-card">
                <h2>Knygos duomenų bazėje</h2>
                <?php if ( ! empty( $books ) ) : ?>
                    <table class="widefat striped">
                        <thead><tr><th>Numeris</th><th>Trumpinys</th><th>Pilnas pavadinimas</th></tr></thead>
                        <tbody>
                            <?php foreach ( $books as $b ) : ?>
                                <tr>
                                    <td><?php echo intval( $b['book_number'] ); ?></td>
                                    <td><strong><?php echo esc_html( $b['short_name'] ); ?></strong></td>
                                    <td><?php echo esc_html( $b['long_name'] ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p>Nėra knygų. Įkelkite modulį.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /* ──────────────────────────────────────────────
       Module upload page
       ────────────────────────────────────────────── */
    public function render_module_page() {
        ?>
        <div class="wrap bible-admin-wrap">
            <h1><span class="dashicons dashicons-upload"></span> Bible – Modulio įkėlimas</h1>
            <?php settings_errors( 'bible_messages' ); ?>
            <div class="bible-admin-card">
                <h2>Įkelti naują modulį</h2>
                <p>Įkelkite MyBible formato <code>.SQLite3</code> failą. Esami duomenys bus pakeisti.</p>
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field( 'bible_module_nonce' ); ?>
                    <table class="form-table">
                        <tr>
                            <th>SQLite3 failas</th>
                            <td>
                                <input type="file" name="bible_module_file" accept=".sqlite3,.SQLite3">
                                <p class="description">MyBible formato SQLite3 failas su „books" ir „verses" lentelėmis.</p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <input type="submit" name="bible_upload_module" class="button-primary" value="Įkelti modulį" onclick="return confirm('Ar tikrai norite pakeisti esamą modulį?');">
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    /* ──────────────────────────────────────────────
       Patterns / Aliases page (grouped by book)
       ────────────────────────────────────────────── */
    public function render_patterns_page() {
        $aliases = get_option( 'bible_custom_aliases', array() );
        $books   = Bible_DB::get_books();

        // Build book lookup: number => { short_name, long_name }
        $book_lookup = array();
        foreach ( $books as $b ) {
            $book_lookup[ intval( $b['book_number'] ) ] = $b;
        }

        // Group aliases by book_number
        $grouped = array();
        foreach ( $aliases as $a ) {
            $bn = intval( $a['book_number'] );
            if ( ! isset( $grouped[ $bn ] ) ) {
                $grouped[ $bn ] = array();
            }
            $grouped[ $bn ][] = $a['alias'];
        }

        // Build select options HTML
        $opts_html = '<option value="">— Pasirinkite —</option>';
        foreach ( $books as $b ) {
            $opts_html .= '<option value="' . intval( $b['book_number'] ) . '">'
                . esc_html( $b['short_name'] . ' — ' . $b['long_name'] . ' (' . $b['book_number'] . ')' )
                . '</option>';
        }

        ?>
        <div class="wrap bible-admin-wrap">
            <h1><span class="dashicons dashicons-editor-code"></span> Bible – Šablonai / Aliases</h1>
            <?php settings_errors( 'bible_messages' ); ?>

            <div class="bible-admin-card">
                <p>Trumpiniai iš duomenų bazės (pvz. <strong>Pr</strong>, <strong>1 Sam</strong>, <strong>Ts</strong>) ir ilgi pavadinimai (pvz. <strong>Pradžios</strong>, <strong>1 Samuelio</strong>) atpažįstami <strong>automatiškai</strong> — jų čia pridėti nereikia.</p>
                <p>Čia pridėkite <strong>papildomus</strong> pavadinimus ar formas, pvz. <em>Teisėjų knyga</em>, <em>Pradžios knygos</em>, <em>Psalmė</em> ir pan.</p>
                <p><em>Išsaugojus, įrašai automatiškai surūšiuojami pagal knygas.</em></p>
            </div>

            <!-- Export / Import section -->
            <div class="bible-admin-card bible-export-import-card">
                <h2>Eksportas / Importas</h2>
                <div class="bible-export-import-grid">
                    <div class="bible-export-section">
                        <h3>📤 Eksportuoti</h3>
                        <p>Atsisiųskite .json failą, kurį galėsite importuoti kitoje svetainėje.</p>
                        <div class="bible-export-buttons">
                            <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=bible-patterns&bible_export=aliases' ), 'bible_export_nonce' ); ?>" class="button">
                                Eksportuoti šablonus
                            </a>
                            <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=bible-patterns&bible_export=all' ), 'bible_export_nonce' ); ?>" class="button">
                                Eksportuoti viską (šablonai + nustatymai)
                            </a>
                        </div>
                        <p class="description">Šablonų: <strong><?php echo count( $aliases ); ?></strong></p>
                    </div>
                    <div class="bible-import-section">
                        <h3>📥 Importuoti</h3>
                        <p>Įkelkite anksčiau eksportuotą .json failą.</p>
                        <form method="post" enctype="multipart/form-data">
                            <?php wp_nonce_field( 'bible_import_nonce' ); ?>
                            <div class="bible-import-fields">
                                <input type="file" name="bible_import_file" accept=".json">
                                <div class="bible-import-mode">
                                    <label><input type="radio" name="import_mode" value="merge" checked> <strong>Sujungti</strong> — pridėti naujus, esamus palikti</label>
                                    <label><input type="radio" name="import_mode" value="replace"> <strong>Pakeisti</strong> — pakeisti visus esamus</label>
                                </div>
                                <button type="submit" name="bible_import_aliases" class="button button-primary">Importuoti</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <form method="post" id="bible-aliases-form">
                <?php wp_nonce_field( 'bible_aliases_nonce' ); ?>

                <?php foreach ( $books as $b ) :
                    $bn = intval( $b['book_number'] );
                    $book_aliases = isset( $grouped[ $bn ] ) ? $grouped[ $bn ] : array();
                ?>
                <div class="bible-book-group" data-book="<?php echo $bn; ?>">
                    <div class="bible-book-header">
                        <span class="bible-book-badge" style="background-color: <?php echo esc_attr( sanitize_hex_color( $b['book_color'] ) ?: '' ); ?>">
                            <?php echo esc_html( $b['short_name'] ); ?>
                        </span>
                        <strong><?php echo esc_html( $b['long_name'] ); ?></strong>
                        <span class="bible-book-num">(#<?php echo $bn; ?>)</span>
                        <span class="bible-alias-count"><?php echo count( $book_aliases ); ?> alias<?php echo count( $book_aliases ) !== 1 ? 'ų' : ''; ?></span>
                        <button type="button" class="button button-small bible-add-alias-btn" data-book="<?php echo $bn; ?>" title="Pridėti alias šiai knygai">+ Pridėti</button>
                    </div>
                    <div class="bible-alias-rows">
                        <?php foreach ( $book_aliases as $alias_text ) : ?>
                            <div class="bible-alias-row">
                                <input type="hidden" name="alias_book[]" value="<?php echo $bn; ?>">
                                <input type="text" name="alias_text[]" value="<?php echo esc_attr( $alias_text ); ?>" class="regular-text bible-alias-input" placeholder="Alias...">
                                <button type="button" class="button button-small bible-remove-row" title="Šalinti">✕</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- Section for aliases with unrecognized book numbers -->
                <?php
                $orphan_aliases = array();
                foreach ( $aliases as $a ) {
                    if ( ! isset( $book_lookup[ intval( $a['book_number'] ) ] ) ) {
                        $orphan_aliases[] = $a;
                    }
                }
                if ( ! empty( $orphan_aliases ) ) : ?>
                <div class="bible-book-group bible-orphans">
                    <div class="bible-book-header">
                        <span class="bible-book-badge" style="background:#e74c3c;color:#fff">?</span>
                        <strong>Nerastos knygos</strong>
                    </div>
                    <div class="bible-alias-rows">
                        <?php foreach ( $orphan_aliases as $a ) : ?>
                            <div class="bible-alias-row">
                                <input type="hidden" name="alias_book[]" value="<?php echo intval( $a['book_number'] ); ?>">
                                <input type="text" name="alias_text[]" value="<?php echo esc_attr( $a['alias'] ); ?>" class="regular-text bible-alias-input">
                                <span class="bible-orphan-note">knyga #<?php echo intval( $a['book_number'] ); ?> nerasta DB</span>
                                <button type="button" class="button button-small bible-remove-row">✕</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Section for adding new alias to any book -->
                <div class="bible-admin-card bible-add-new-section">
                    <h3>Pridėti naują alias bet kuriai knygai</h3>
                    <div class="bible-new-alias-row">
                        <input type="text" id="bible-new-alias-text" class="regular-text" placeholder="Alias tekstas, pvz. Pradžios knyga">
                        <select id="bible-new-alias-book"><?php echo $opts_html; ?></select>
                        <button type="button" class="button" id="bible-add-new-alias">+ Pridėti</button>
                    </div>
                </div>

                <p class="submit" style="position:sticky;bottom:0;background:#f0f0f1;padding:16px 0;margin:0;border-top:2px solid #c3c4c7;z-index:10;">
                    <input type="submit" name="bible_save_aliases" class="button-primary button-hero" value="💾 Išsaugoti visus šablonus">
                </p>
            </form>
        </div>

        <script>
        (function(){
            // Remove alias row
            document.addEventListener('click', function(e){
                if(e.target.classList.contains('bible-remove-row')){
                    e.target.closest('.bible-alias-row').remove();
                    updateCounts();
                }
            });

            // Add alias to specific book group
            document.addEventListener('click', function(e){
                var btn = e.target.closest('.bible-add-alias-btn');
                if(!btn) return;
                var bn = btn.getAttribute('data-book');
                var group = document.querySelector('.bible-book-group[data-book="'+bn+'"]');
                if(!group) return;
                var rows = group.querySelector('.bible-alias-rows');
                var row = document.createElement('div');
                row.className = 'bible-alias-row bible-alias-new';
                row.innerHTML = '<input type="hidden" name="alias_book[]" value="'+bn+'">'
                    + '<input type="text" name="alias_text[]" class="regular-text bible-alias-input" placeholder="Naujas alias..." autofocus>'
                    + '<button type="button" class="button button-small bible-remove-row" title="Šalinti">✕</button>';
                rows.appendChild(row);
                row.querySelector('input[type=text]').focus();
                updateCounts();
            });

            // Add alias via the bottom "any book" section
            document.getElementById('bible-add-new-alias').addEventListener('click', function(){
                var aliasText = document.getElementById('bible-new-alias-text');
                var aliasBook = document.getElementById('bible-new-alias-book');
                var text = aliasText.value.trim();
                var bn   = aliasBook.value;
                if(!text || !bn) {
                    alert('Įveskite alias tekstą ir pasirinkite knygą.');
                    return;
                }
                // Find the book group and add there
                var group = document.querySelector('.bible-book-group[data-book="'+bn+'"]');
                if(group){
                    var rows = group.querySelector('.bible-alias-rows');
                    var row = document.createElement('div');
                    row.className = 'bible-alias-row bible-alias-new';
                    row.innerHTML = '<input type="hidden" name="alias_book[]" value="'+bn+'">'
                        + '<input type="text" name="alias_text[]" value="'+text.replace(/"/g, '&quot;')+'" class="regular-text bible-alias-input">'
                        + '<button type="button" class="button button-small bible-remove-row" title="Šalinti">✕</button>';
                    rows.appendChild(row);
                    // Scroll to it
                    group.scrollIntoView({behavior:'smooth', block:'center'});
                    row.querySelector('input[type=text]').style.backgroundColor = '#e8f5e9';
                }
                aliasText.value = '';
                aliasBook.selectedIndex = 0;
                updateCounts();
            });

            function updateCounts(){
                document.querySelectorAll('.bible-book-group').forEach(function(g){
                    var count = g.querySelectorAll('.bible-alias-row').length;
                    var badge = g.querySelector('.bible-alias-count');
                    if(badge) badge.textContent = count + ' alias' + (count !== 1 ? 'ų' : '');
                });
            }
        })();
        </script>
        <?php
    }
}
