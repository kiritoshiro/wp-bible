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

        $type = sanitize_text_field( $_GET['bible_export'] );

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
    public function handle_actions() {
        // Save settings
        if ( isset( $_POST['bible_save_settings'] ) && check_admin_referer( 'bible_settings_nonce' ) ) {
            $settings = array(
                'popup_trigger'  => sanitize_text_field( $_POST['popup_trigger'] ?? 'hover' ),
                'enabled'        => isset( $_POST['enabled'] ) ? '1' : '0',
                'popup_maxwidth' => intval( $_POST['popup_maxwidth'] ?? 450 ),
            );
            update_option( 'bible_settings', $settings );
            add_settings_error( 'bible_messages', 'bible_updated', 'Nustatymai išsaugoti.', 'updated' );
        }

        // Upload module
        if ( isset( $_POST['bible_upload_module'] ) && check_admin_referer( 'bible_module_nonce' ) ) {
            if ( ! empty( $_FILES['bible_module_file']['tmp_name'] ) ) {
                $filename = sanitize_file_name( $_FILES['bible_module_file']['name'] );
                $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

                if ( $ext === 'sqlite3' ) {
                    if ( ! file_exists( BIBLE_MODULES_DIR ) ) wp_mkdir_p( BIBLE_MODULES_DIR );

                    $dest = BIBLE_MODULES_DIR . $filename;
                    if ( move_uploaded_file( $_FILES['bible_module_file']['tmp_name'], $dest ) ) {
                        $result = Bible_DB::import_sqlite_module( $dest, pathinfo( $filename, PATHINFO_FILENAME ) );
                        if ( is_wp_error( $result ) ) {
                            add_settings_error( 'bible_messages', 'bible_error', 'Klaida: ' . $result->get_error_message(), 'error' );
                        } else {
                            add_settings_error( 'bible_messages', 'bible_updated', "Modulis įkeltas! Importuota eilučių: {$result}", 'updated' );
                        }
                    } else {
                        add_settings_error( 'bible_messages', 'bible_error', 'Nepavyko išsaugoti failo.', 'error' );
                    }
                } else {
                    add_settings_error( 'bible_messages', 'bible_error', 'Netinkamas formatas. Įkelkite .SQLite3 failą.', 'error' );
                }
            }
        }

        // Import aliases from JSON
        if ( isset( $_POST['bible_import_aliases'] ) && check_admin_referer( 'bible_import_nonce' ) ) {
            if ( ! empty( $_FILES['bible_import_file']['tmp_name'] ) ) {
                $json = file_get_contents( $_FILES['bible_import_file']['tmp_name'] );
                $data = json_decode( $json, true );

                if ( ! $data || ! isset( $data['plugin'] ) || $data['plugin'] !== 'bible' ) {
                    add_settings_error( 'bible_messages', 'bible_error', 'Netinkamas failas. Tai nėra Bible plugino eksportas.', 'error' );
                } else {
                    $imported = 0;
                    $mode = sanitize_text_field( $_POST['import_mode'] ?? 'merge' );

                    if ( ! empty( $data['aliases'] ) && is_array( $data['aliases'] ) ) {
                        if ( $mode === 'replace' ) {
                            // Replace all aliases
                            $aliases = $data['aliases'];
                        } else {
                            // Merge: add imported aliases, skip duplicates
                            $existing = get_option( 'bible_custom_aliases', array() );
                            $existing_keys = array();
                            foreach ( $existing as $a ) {
                                $existing_keys[ $a['alias'] . '|' . $a['book_number'] ] = true;
                            }
                            $aliases = $existing;
                            foreach ( $data['aliases'] as $a ) {
                                $key = $a['alias'] . '|' . $a['book_number'];
                                if ( ! isset( $existing_keys[ $key ] ) ) {
                                    $aliases[] = $a;
                                    $imported++;
                                }
                            }
                        }

                        // Sort
                        usort( $aliases, function( $a, $b ) {
                            if ( $a['book_number'] !== $b['book_number'] ) {
                                return $a['book_number'] - $b['book_number'];
                            }
                            return strcmp( $a['alias'], $b['alias'] );
                        });

                        update_option( 'bible_custom_aliases', $aliases );

                        if ( $mode === 'replace' ) {
                            add_settings_error( 'bible_messages', 'bible_updated',
                                'Šablonai pakeisti! Importuota: ' . count( $data['aliases'] ) . ' alias(ų).', 'updated' );
                        } else {
                            add_settings_error( 'bible_messages', 'bible_updated',
                                'Šablonai sujungti! Naujų pridėta: ' . $imported . '. Iš viso dabar: ' . count( $aliases ) . '.', 'updated' );
                        }
                    }

                    // Also import settings if present and "all" export
                    if ( ! empty( $data['settings'] ) && is_array( $data['settings'] ) ) {
                        update_option( 'bible_settings', $data['settings'] );
                        add_settings_error( 'bible_messages', 'bible_updated', 'Nustatymai taip pat importuoti.', 'updated' );
                    }
                }
            } else {
                add_settings_error( 'bible_messages', 'bible_error', 'Pasirinkite .json failą importui.', 'error' );
            }
        }

        // Save aliases – collect, sort by book_number, then save
        if ( isset( $_POST['bible_save_aliases'] ) && check_admin_referer( 'bible_aliases_nonce' ) ) {
            $aliases = array();
            if ( isset( $_POST['alias_text'] ) && is_array( $_POST['alias_text'] ) ) {
                foreach ( $_POST['alias_text'] as $i => $alias ) {
                    $alias = trim( sanitize_text_field( $alias ) );
                    $bn    = intval( $_POST['alias_book'][ $i ] ?? 0 );
                    if ( $alias !== '' && $bn > 0 ) {
                        $aliases[] = array( 'alias' => $alias, 'book_number' => $bn );
                    }
                }
            }
            // Sort by book_number, then alphabetically within each book
            usort( $aliases, function( $a, $b ) {
                if ( $a['book_number'] !== $b['book_number'] ) {
                    return $a['book_number'] - $b['book_number'];
                }
                return strcmp( $a['alias'], $b['alias'] );
            });
            update_option( 'bible_custom_aliases', $aliases );
            add_settings_error( 'bible_messages', 'bible_updated', 'Šablonai išsaugoti ir surūšiuoti pagal knygas.', 'updated' );
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
                        <span class="bible-book-badge" style="background-color: <?php echo esc_attr( $b['book_color'] ); ?>">
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
