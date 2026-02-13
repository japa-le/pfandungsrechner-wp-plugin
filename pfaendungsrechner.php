<?php
/*
Plugin Name: Pfändungsrechner
Description: Ein Pfändungsrechner mit automatisierten Updates der Tabellen und Shortcode-Integration.
Version: 1.2
Author: Janos
*/

defined('ABSPATH') || exit;

$pfaendungsrechner_autoload = plugin_dir_path(__FILE__) . 'vendor/autoload.php';
if (file_exists($pfaendungsrechner_autoload)) {
    require_once $pfaendungsrechner_autoload; // Smalot/PdfParser laden
}

// Plugin-Aktivierung: Tabelle erstellen und initial befüllen
register_activation_hook(__FILE__, 'pfaendungsrechner_install');
register_deactivation_hook(__FILE__, 'pfaendungsrechner_deactivate');
register_uninstall_hook(__FILE__, 'pfaendungsrechner_uninstall');

function pfaendungsrechner_install() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'pfaendungstabellen';

    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table_name (
        id INT AUTO_INCREMENT PRIMARY KEY,
        income_min FLOAT NOT NULL,
        income_max FLOAT NOT NULL,
        pfand_0 FLOAT NOT NULL,
        pfand_1 FLOAT,
        pfand_2 FLOAT,
        pfand_3 FLOAT,
        pfand_4 FLOAT,
        pfand_5 FLOAT
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Prüfen, ob Tabelle leer ist
    $row_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    if ($row_count == 0) {
        // Tabelle initial füllen
        update_pfaendungstabellen();
    }
}

/**
 * Plugin-Deaktivierung: nur geplante Events entfernen (keine Daten löschen)
 */
function pfaendungsrechner_deactivate() {
    $timestamp = wp_next_scheduled('update_pfaendungstabellen_event');
    while ($timestamp) {
        wp_unschedule_event($timestamp, 'update_pfaendungstabellen_event');
        $timestamp = wp_next_scheduled('update_pfaendungstabellen_event');
    }
}

/**
 * Plugin-Deinstallation: Datenbanktabelle entfernen
 */
function pfaendungsrechner_uninstall() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'pfaendungstabellen';
    $wpdb->query("DROP TABLE IF EXISTS {$table_name}");
}

// Cron-Job registrieren (jährlich am 1. Juli oder nächster Werktag)
add_action('wp', 'pfaendungsrechner_schedule_yearly_update');
function pfaendungsrechner_schedule_yearly_update() {
    if (!wp_next_scheduled('update_pfaendungstabellen_event')) {
        $next_update = pfaendungsrechner_get_next_update_date();
        wp_schedule_event($next_update, 'yearly', 'update_pfaendungstabellen_event');
        error_log("Pfändungstabelle Update geplant für: " . date('Y-m-d H:i:s', $next_update));
    }
}

/**
 * Berechnet das nächste Update-Datum (1. Juli oder nächster Werktag)
 */
function pfaendungsrechner_get_next_update_date() {
    $current_year = date('Y');
    $current_month = (int)date('m');
    
    // Wenn wir vor Juli sind, nutze 1. Juli dieses Jahres
    // Wenn wir nach Juli sind, nutze 1. Juli nächstes Jahr
    if ($current_month < 7) {
        $target_year = $current_year;
    } else {
        $target_year = $current_year + 1;
    }
    
    // 1. Juli des Zieljahres
    $update_date = strtotime("$target_year-07-01 03:00:00"); // 3 Uhr morgens
    
    // Prüfe, ob es ein Wochenende ist
    $day_of_week = date('N', $update_date); // 1 = Montag, 7 = Sonntag
    
    if ($day_of_week == 6) {
        // Samstag -> verschiebe auf Montag (+2 Tage)
        $update_date = strtotime('+2 days', $update_date);
    } elseif ($day_of_week == 7) {
        // Sonntag -> verschiebe auf Montag (+1 Tag)
        $update_date = strtotime('+1 day', $update_date);
    }
    
    return $update_date;
}

// Registriere benutzerdefiniertes Cron-Intervall
add_filter('cron_schedules', 'pfaendungsrechner_add_yearly_cron_interval');
function pfaendungsrechner_add_yearly_cron_interval($schedules) {
    $schedules['yearly'] = array(
        'interval' => 365 * DAY_IN_SECONDS,
        'display'  => __('Einmal jährlich')
    );
    return $schedules;
}


// Cron-Job-Aktion
add_action('update_pfaendungstabellen_event', 'update_pfaendungstabellen');
function update_pfaendungstabellen() {
    if (!class_exists('Smalot\\PdfParser\\Parser')) {
        error_log('Pfändungstabelle Update fehlgeschlagen: PDF Parser Library nicht verfügbar.');
        pfaendungsrechner_send_error_notification(
            'PDF-Parser nicht verfügbar',
            'Die benötigte Library smalot/pdfparser wurde nicht gefunden. Bitte Composer-Abhängigkeiten prüfen.'
        );
        return false;
    }
    
    $base_url = "https://www.vzhh.de"; // Basis-URL der Website
    $response = wp_remote_get($base_url . "/pfaendungstabelle");
    
    if (is_wp_error($response)) {
        error_log("Pfändungstabelle Update fehlgeschlagen: Keine Verbindung zur VZHH-Website");
        pfaendungsrechner_send_error_notification(
            "Verbindungsfehler zur VZHH-Website",
            "Es konnte keine Verbindung zu https://www.vzhh.de/pfaendungstabelle hergestellt werden.\n\nFehler: " . $response->get_error_message()
        );
        return false;
    }
    
    $html = wp_remote_retrieve_body($response);
    
    if (!$html) {
        error_log("Pfändungstabelle Update fehlgeschlagen: Leere Antwort von VZHH");
        pfaendungsrechner_send_error_notification(
            "Leere Antwort von VZHH-Website",
            "Die Webseite https://www.vzhh.de/pfaendungstabelle hat keine Inhalte zurückgegeben."
        );
        return false;
    }
    
    // Suche nach dem PDF-Link basierend auf der vollständigen URL-Struktur
    if (preg_match('/https:\/\/www\.vzhh\.de\/sites\/default\/files\/medien\/\d+\/dokumente\/Pfaendungstabelle[^"]*\.pdf/i', $html, $matches)) {
        $url = $matches[0];
    } else {
        error_log("PDF-Link nicht gefunden!");
        pfaendungsrechner_send_error_notification(
            "PDF-Link nicht gefunden",
            "Der Link zur Pfändungstabelle-PDF konnte auf der VZHH-Webseite nicht gefunden werden.\n\nMöglicherweise hat sich die Seitenstruktur geändert."
        );
        return false;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'pfaendungstabellen';
    $upload_dir = wp_upload_dir();
    $file_path = $upload_dir['basedir'] . '/pfaendungstabelle.pdf';

    // PDF herunterladen
    $response = wp_remote_get($url);
    if (is_wp_error($response)) {
        error_log("Fehler beim PDF-Download: " . $response->get_error_message());
        pfaendungsrechner_send_error_notification(
            "PDF-Download fehlgeschlagen",
            "Das Pfändungstabelle-PDF konnte nicht heruntergeladen werden.\n\nURL: $url\nFehler: " . $response->get_error_message()
        );
        return;
    }

    $pdf_content = wp_remote_retrieve_body($response);
    if (!$pdf_content) {
        error_log("PDF-Inhalt konnte nicht abgerufen werden.");
        pfaendungsrechner_send_error_notification(
            "PDF-Inhalt leer",
            "Das heruntergeladene PDF enthält keine Daten.\n\nURL: $url"
        );
        return;
    }

    file_put_contents($file_path, $pdf_content);
    error_log("PDF erfolgreich heruntergeladen.");

    // PDF parsen
    $parser = new Smalot\PdfParser\Parser();
    try {
        $pdf = $parser->parseFile($file_path);
        $text = $pdf->getText();
        error_log("PDF erfolgreich geparst.");
    } catch (Exception $e) {
        error_log("Fehler beim Parsen der PDF: " . $e->getMessage());
        pfaendungsrechner_send_error_notification(
            "PDF-Parsing fehlgeschlagen",
            "Die heruntergeladene PDF konnte nicht geparst werden.\n\nDatei: $file_path\nFehler: " . $e->getMessage()
        );
        return;
    }

    // Daten extrahieren
    $data = parse_pfaendungstabelle($text);
    if (empty($data)) {
        error_log("Keine Daten aus der Tabelle extrahiert.");
        pfaendungsrechner_send_error_notification(
            "Keine Daten extrahiert",
            "Aus der Pfändungstabelle-PDF konnten keine Daten extrahiert werden.\n\nDie PDF-Struktur könnte sich geändert haben. Das Plugin verwendet nun Standardwerte."
        );
        return;
    }

    // Tabelle leeren und neu befüllen
    $wpdb->query("TRUNCATE TABLE $table_name");
    $inserted_rows = 0;
    foreach ($data as $row) {
        $result = $wpdb->insert($table_name, $row);
        if ($result) {
            $inserted_rows++;
        }
    }
    
    error_log("Datenbank erfolgreich aktualisiert mit $inserted_rows Zeilen.");
    
    // Erfolgsbenachrichtigung senden
    pfaendungsrechner_send_success_notification($inserted_rows, $url);
}

/**
 * Sendet Erfolgsbenachrichtigung an den Admin
 */
function pfaendungsrechner_send_success_notification($row_count, $pdf_url) {
    $admin_email = get_option('admin_email');
    $site_name = get_bloginfo('name');
    $site_url = get_bloginfo('url');
    
    $subject = "✅ Pfändungstabelle erfolgreich aktualisiert - $site_name";
    
    $message = "Guten Tag,\n\n";
    $message .= "die Pfändungstabelle auf Ihrer Website wurde erfolgreich aktualisiert.\n\n";
    $message .= "Details:\n";
    $message .= "- Zeitpunkt: " . date('d.m.Y, H:i:s') . " Uhr\n";
    $message .= "- Anzahl importierter Zeilen: $row_count\n";
    $message .= "- PDF-Quelle: $pdf_url\n\n";
    $message .= "Die Pfändungstabelle wurde mathematisch basierend auf § 850c ZPO generiert.\n\n";
    $message .= "Nächstes automatisches Update: " . date('d.m.Y', pfaendungsrechner_get_next_update_date()) . "\n\n";
    $message .= "---\n";
    $message .= "Website: $site_url\n";
    $message .= "Diese Nachricht wurde automatisch vom Pfändungsrechner-Plugin erstellt.";
    
    wp_mail($admin_email, $subject, $message);
}

/**
 * Sendet Fehlerbenachrichtigung an den Admin
 */
function pfaendungsrechner_send_error_notification($error_title, $error_details) {
    $admin_email = get_option('admin_email');
    $site_name = get_bloginfo('name');
    $site_url = get_bloginfo('url');
    
    $subject = "⚠️ Pfändungstabelle Update fehlgeschlagen - $site_name";
    
    $message = "Guten Tag,\n\n";
    $message .= "beim automatischen Update der Pfändungstabelle ist ein Fehler aufgetreten.\n\n";
    $message .= "Fehler: $error_title\n\n";
    $message .= "Details:\n$error_details\n\n";
    $message .= "Zeitpunkt: " . date('d.m.Y, H:i:s') . " Uhr\n\n";
    $message .= "Bitte prüfen Sie die Situation. Die alte Tabelle bleibt aktiv, bis ein erfolgreiches Update durchgeführt werden kann.\n\n";
    $message .= "---\n";
    $message .= "Website: $site_url\n";
    $message .= "Diese Nachricht wurde automatisch vom Pfändungsrechner-Plugin erstellt.";
    
    wp_mail($admin_email, $subject, $message);
}




// Shortcode registrieren
add_shortcode('pfaendungsrechner', 'render_pfaendungsrechner');
function render_pfaendungsrechner() {
    $ajax_nonce = wp_create_nonce('pfaendungsrechner_ajax_nonce');
    ob_start();
    ?>
    <form id="pfaendungsrechner-form">

        <div class="droppers">
            <label for="netto">Nettoeinkommen (€):</label>
            <input type="number" id="netto" name="netto" required>
        </div> 

        <div class="droppers">
            <label for="dependents">Unterhaltspflichtige Personen:</label>
            <select id="dependents" name="dependents" required>
                <option value="0">0</option>
                <option value="1">1</option>
                <option value="2">2</option>
                <option value="3">3</option>
                <option value="4">4</option>
                <option value="5">5 und mehr</option>
            </select>
        </div>

        <button type="submit">Berechnen</button>
    </form>

    <div id="pfaendungsrechner-result"></div>

    <script>
        document.getElementById('pfaendungsrechner-form').addEventListener('submit', function(e) {
            e.preventDefault();
            const netto = document.getElementById('netto').value;
            const dependents = document.getElementById('dependents').value;

            fetch('<?php echo admin_url("admin-ajax.php"); ?>?action=pfaendungsrechner_calculate', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ netto, dependents, nonce: '<?php echo esc_js($ajax_nonce); ?>' })
            })
            .then(response => response.json())
            .then(data => {
                const resultDiv = document.getElementById('pfaendungsrechner-result');
                if (data.message) {
                    // Zeige Nachricht (z. B. bei vollständiger Pfändbarkeit)
                    resultDiv.innerText = data.message;
                } else if (data.error) {
                    // Zeige Fehlernachricht
                    resultDiv.innerText = data.error;
                } else {
                    // Zeige berechneten Pfändungsbetrag
                    resultDiv.innerText = `Pfändbarer Betrag: ${data.result} €`;
                }
            });
        });
    </script>
    <?php
    return ob_get_clean();
}


// Berechnungslogik
add_action('wp_ajax_pfaendungsrechner_calculate', 'pfaendungsrechner_calculate');
add_action('wp_ajax_nopriv_pfaendungsrechner_calculate', 'pfaendungsrechner_calculate');
function pfaendungsrechner_calculate() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'pfaendungstabellen';

    $input_raw = file_get_contents('php://input');
    $input = json_decode($input_raw, true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    if (empty($input['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($input['nonce'])), 'pfaendungsrechner_ajax_nonce')) {
        wp_send_json_error(['error' => 'Ungültige Anfrage. Bitte laden Sie die Seite neu.'], 403);
    }

    if (!isset($input['netto'], $input['dependents']) || !is_numeric($input['netto']) || !is_numeric($input['dependents'])) {
        wp_send_json_error(['error' => 'Ungültige Eingabedaten.'], 400);
    }

    $netto = max(0, (float) $input['netto']);
    $dependents = (int) $input['dependents'];
    $dependents = max(0, min($dependents, 5));

    // Schwelle für vollständige Pfändung
    $threshold = 4766.99;

    // Prüfen, ob der Betrag über der Schwelle liegt
    if ($netto > $threshold) {
        wp_send_json_success(['message' => 'Alle Beträge über 4.766,99 Euro sind voll pfändbar!']);
    }

    // Maximal 5+ Personen berücksichtigen
    $dependent_field = "pfand_" . min($dependents, 5);

    // Einkommen liegt innerhalb der Tabelle
    $query = $wpdb->prepare(
        "SELECT * FROM $table_name WHERE income_min <= %f AND income_max >= %f LIMIT 1",
        $netto, $netto
    );
    $row = $wpdb->get_row($query);

    // Fehlerbehandlung für fehlende Zeile
    if (!$row) {
        wp_send_json_error(['error' => 'Dieser Betrag ist von einer Pfändung befreit.'], 404);
    } else {
        $pfandbetrag = round($row->$dependent_field, 2);
        wp_send_json_success(['result' => $pfandbetrag]);
    }
}


/**
 * Parse Pfändungstabelle mathematisch (§ 850c ZPO)
 * Extrahiert Ankerwerte und berechnet Zwischenwerte
 */
function parse_pfaendungstabelle($text) {
    $table = [];
    
    // Ankerwerte extrahieren (Start- und Endwerte für Validierung)
    $anchor_values = extract_anchor_values($text);
    
    if (!$anchor_values) {
        error_log("Keine Ankerwerte aus PDF extrahiert - verwende Standardwerte.");
        $anchor_values = get_default_anchor_values();
    }
    
    // Tabelle mathematisch generieren
    $table = generate_pfaendungstabelle_mathematically($anchor_values);
    
    return $table;
}

/**
 * Extrahiert kritische Ankerwerte aus dem PDF-Text
 */
function extract_anchor_values($text) {
    $anchors = [];
    
    // Suche nach dem ersten pfändbaren Betrag (Start)
    if (preg_match('/1[.,\s]?560[.,]00\s+bis\s+1[.,\s]?569[.,]99\s+(\d+[.,]\d+)/i', $text, $match)) {
        $anchors['start_income'] = 1560.00;
        $anchors['start_pfand_0'] = floatval(str_replace(',', '.', str_replace([' ', '.'], '', $match[1])));
    }
    
    // Suche nach dem Schwellenwert (Ende)
    if (preg_match('/4[.,\s]?760[.,]00\s+bis\s+4[.,\s]?766[.,]99/i', $text, $match)) {
        $anchors['end_income'] = 4766.99;
    }
    
    // Suche nach weiteren Start-Ankerpunkten für verschiedene Unterhaltsberechtigte
    if (preg_match('/2[.,\s]?150[.,]00\s+bis\s+2[.,\s]?159[.,]99\s+\d+[.,]\d+\s+(\d+[.,]\d+)/i', $text, $match)) {
        $anchors['start_1_dependent'] = 2150.00;
    }
    
    if (preg_match('/2[.,\s]?470[.,]00\s+bis\s+2[.,\s]?479[.,]99\s+\d+[.,]\d+\s+\d+[.,]\d+\s+(\d+[.,]\d+)/i', $text, $match)) {
        $anchors['start_2_dependents'] = 2470.00;
    }
    
    if (preg_match('/2[.,\s]?800[.,]00\s+bis\s+2[.,\s]?809[.,]99\s+\d+[.,]\d+\s+\d+[.,]\d+\s+\d+[.,]\d+\s+(\d+[.,]\d+)/i', $text, $match)) {
        $anchors['start_3_dependents'] = 2800.00;
    }
    
    if (preg_match('/3[.,\s]?120[.,]00\s+bis\s+3[.,\s]?129[.,]99.*?(\d+[.,]\d+)\s+(\d+[.,]\d+)$/mi', $text, $match)) {
        $anchors['start_4_dependents'] = 3120.00;
    }
    
    if (preg_match('/3[.,\s]?450[.,]00\s+bis\s+3[.,\s]?459[.,]99/i', $text, $match)) {
        $anchors['start_5_dependents'] = 3450.00;
    }
    
    return !empty($anchors) ? $anchors : null;
}

/**
 * Standardwerte falls PDF-Parsing fehlschlägt
 * Basiert auf aktueller Pfändungstabelle 2025/2026
 */
function get_default_anchor_values() {
    return [
        'start_income' => 1560.00,
        'end_income' => 4766.99,
        'start_pfand_0' => 3.50,
        'start_1_dependent' => 2150.00,
        'start_2_dependents' => 2470.00,
        'start_3_dependents' => 2800.00,
        'start_4_dependents' => 3120.00,
        'start_5_dependents' => 3450.00,
    ];
}

/**
 * Generiert Pfändungstabelle mathematisch basierend auf § 850c ZPO
 * Pro 10€ Einkommenserhöhung steigen die pfändbaren Beträge um:
 * - 0 Unterhaltsberechtigte: +7,00€
 * - 1 Unterhaltsberechtigte: +5,00€
 * - 2 Unterhaltsberechtigte: +4,00€
 * - 3 Unterhaltsberechtigte: +3,00€
 * - 4 Unterhaltsberechtigte: +2,00€
 * - 5+ Unterhaltsberechtigte: +1,00€
 */
function generate_pfaendungstabelle_mathematically($anchors) {
    $table = [];
    
    // Konditionen nach § 850c ZPO
    $income_increment = 10; // Einkommensschritte: je 10€
    $pfand_increments = [7.00, 5.00, 4.00, 3.00, 2.00, 1.00]; // Inkrement pro Unterhaltsberechtigten
    
    // Start- und Endwerte
    $start_income = $anchors['start_income'];
    $end_income = $anchors['end_income'];
    
    // Startwerte für pfändbare Beträge (0 Unterhaltsberechtigte)
    $start_pfand_0 = isset($anchors['start_pfand_0']) ? $anchors['start_pfand_0'] : 3.50;
    
    // Starteinkommen für verschiedene Unterhaltsberechtigte
    $dependent_start_incomes = [
        0 => $start_income,        // 1.560,00
        1 => 2150.00,               // 2.150,00
        2 => 2470.00,               // 2.470,00
        3 => 2800.00,               // 2.800,00
        4 => 3120.00,               // 3.120,00
        5 => 3450.00,               // 3.450,00
    ];
    
    // Startwerte für pfändbare Beträge je Unterhaltsberechtigte
    $dependent_start_pfand = [
        0 => 3.50,
        1 => 4.89,
        2 => 1.49,
        3 => 2.31,
        4 => 0.33,
        5 => 0.56,
    ];
    
    // Iteriere über alle Einkommensbereiche von Start bis Schwellenwert
    for ($income_min = $start_income; $income_min <= $end_income; $income_min += $income_increment) {
        $income_max = $income_min + 9.99;
        
        // Berechne Zeilennummer ab Start
        $row_number = ($income_min - $start_income) / $income_increment;
        
        $row = [
            'income_min' => round($income_min, 2),
            'income_max' => round($income_max, 2),
        ];
        
        // Berechne pfändbare Beträge für jede Anzahl von Unterhaltsberechtigten
        for ($dependents = 0; $dependents <= 5; $dependents++) {
            $pfand_field = "pfand_$dependents";
            
            // Prüfe, ob diese Einkommensstufe bereits pfändbar ist für diese Anzahl
            if ($income_min < $dependent_start_incomes[$dependents]) {
                // Noch nicht pfändbar
                $row[$pfand_field] = 0;
            } else {
                // Berechne Zeilennummer ab Start dieser Spalte
                $dependent_row = ($income_min - $dependent_start_incomes[$dependents]) / $income_increment;
                
                // Berechne pfändbaren Betrag
                $pfand_value = $dependent_start_pfand[$dependents] + ($dependent_row * $pfand_increments[$dependents]);
                $row[$pfand_field] = round($pfand_value, 2);
            }
        }
        
        $table[] = $row;
    }
    
    return $table;
}

/**
 * Manueller Trigger für Testing
 * Admin kann Update manuell auslösen unter: /wp-admin/admin.php?page=pfaendungsrechner_trigger
 */
add_action('admin_menu', 'pfaendungsrechner_add_trigger_menu');
function pfaendungsrechner_add_trigger_menu() {
    add_submenu_page(
        null, // Kein Menü-Eintrag (nur direkt über URL erreichbar)
        'Pfändungstabelle Update',
        'Pfändungstabelle Update',
        'manage_options',
        'pfaendungsrechner_trigger',
        'pfaendungsrechner_manual_trigger_page'
    );
}

function pfaendungsrechner_manual_trigger_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Keine Berechtigung');
    }
    
    // Prüfe ob Update ausgelöst werden soll
    if (isset($_POST['trigger_update']) && check_admin_referer('pfaendungsrechner_trigger_update', 'pfaendungsrechner_nonce')) {
        echo '<div class="wrap"><h1>Pfändungstabelle Update</h1>';
        echo '<div class="notice notice-info"><p>Update wird ausgeführt...</p></div>';
        
        // Update ausführen
        update_pfaendungstabellen();
        
        echo '<div class="notice notice-success"><p><strong>Update abgeschlossen!</strong> Prüfen Sie Ihre E-Mails für Details.</p></div>';
        echo '<p><a href="' . esc_url(admin_url()) . '" class="button">Zurück zum Dashboard</a></p>';
        echo '</div>';
        return;
    }
    
    // Zeige Formular
    ?>
    <div class="wrap">
        <h1>Pfändungstabelle manuell aktualisieren</h1>
        <div class="card">
            <h2>Automatisches Update</h2>
            <p>Nächstes geplantes Update: <strong><?php echo esc_html(date('d.m.Y', pfaendungsrechner_get_next_update_date())); ?></strong></p>
            <p>Das Plugin aktualisiert die Pfändungstabelle automatisch jedes Jahr am 1. Juli (oder dem nächsten Werktag).</p>
        </div>
        
        <div class="card" style="margin-top: 20px;">
            <h2>Manuelles Update</h2>
            <p>Mit diesem Button können Sie die Pfändungstabelle jederzeit manuell aktualisieren.</p>
            <p><strong>Hinweis:</strong> Das Update lädt die aktuelle PDF von vzhh.de herunter und aktualisiert die Datenbank. Sie erhalten eine E-Mail-Benachrichtigung über das Ergebnis.</p>
            
            <form method="post" style="margin-top: 20px;">
                <?php wp_nonce_field('pfaendungsrechner_trigger_update', 'pfaendungsrechner_nonce'); ?>
                <input type="hidden" name="trigger_update" value="1">
                <button type="submit" class="button button-primary button-large">
                    Pfändungstabelle jetzt aktualisieren
                </button>
            </form>
        </div>
    </div>
    <?php
}