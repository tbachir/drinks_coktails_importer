<?php
/**
 * Interface d'administration pour l'import
 * 
 * @package Drinks_Cocktails_Import
 * @subpackage Admin
 */

if (!defined('ABSPATH')) exit;

class DCI_Admin_Import {
    
    /**
     * Slug de la page admin
     */
    const MENU_SLUG = 'dci-import';
    
    /**
     * Capability requise
     */
    const CAPABILITY = 'manage_options';
    
    /**
     * Constructeur
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_dci_run_import', array($this, 'ajax_run_import'));
        add_action('wp_ajax_dci_check_status', array($this, 'ajax_check_status'));
    }
    
    /**
     * Ajouter le menu d'administration
     */
    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=drink',
            __('Import Drinks & Cocktails', 'drinks-cocktails-import'),
            __('Import', 'drinks-cocktails-import'),
            self::CAPABILITY,
            self::MENU_SLUG,
            array($this, 'render_import_page')
        );
    }
    
    /**
     * Enqueue scripts et styles admin
     */
    public function enqueue_scripts($hook) {
        if (strpos($hook, self::MENU_SLUG) === false) {
            return;
        }
        
        wp_enqueue_style(
            'dci-admin-import',
            DCI_PLUGIN_URL . 'assets/css/admin-import.css',
            array(),
            DCI_VERSION
        );
        
        wp_enqueue_script(
            'dci-admin-import',
            DCI_PLUGIN_URL . 'assets/js/admin-import.js',
            array('jquery'),
            DCI_VERSION,
            true
        );
        
        wp_localize_script('dci-admin-import', 'dci_import', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dci_import_nonce'),
            'strings' => array(
                'confirm_import' => __('Êtes-vous sûr de vouloir lancer l\'import ?', 'drinks-cocktails-import'),
                'import_running' => __('Import en cours...', 'drinks-cocktails-import'),
                'import_complete' => __('Import terminé !', 'drinks-cocktails-import'),
                'import_error' => __('Erreur lors de l\'import', 'drinks-cocktails-import')
            )
        ));
    }
    
    /**
     * Afficher la page d'import
     */
    public function render_import_page() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('Vous n\'avez pas les permissions nécessaires.', 'drinks-cocktails-import'));
        }
        
        // Vérifier l'existence des fichiers JSON
        $drinks_file = DCI_PLUGIN_DIR . 'post-types/data/drinks.json';
        $cocktails_file = DCI_PLUGIN_DIR . 'post-types/data/cocktails.json';
        
        $drinks_exists = file_exists($drinks_file);
        $cocktails_exists = file_exists($cocktails_file);
        
        // Compter les éléments existants
        $existing_drinks = wp_count_posts('drink');
        $existing_cocktails = wp_count_posts('cocktail');
        
        ?>
        <div class="wrap dci-import-wrap">
            <h1><?php _e('Import Drinks & Cocktails', 'drinks-cocktails-import'); ?></h1>
            
            <?php if (!$drinks_exists && !$cocktails_exists): ?>
                <div class="notice notice-error">
                    <p><?php _e('Aucun fichier JSON trouvé dans le répertoire post-types/data/', 'drinks-cocktails-import'); ?></p>
                </div>
            <?php else: ?>
                
                <!-- Statut des fichiers -->
                <div class="dci-status-section">
                    <h2><?php _e('Statut des fichiers', 'drinks-cocktails-import'); ?></h2>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th><?php _e('Fichier', 'drinks-cocktails-import'); ?></th>
                                <th><?php _e('Statut', 'drinks-cocktails-import'); ?></th>
                                <th><?php _e('Éléments', 'drinks-cocktails-import'); ?></th>
                                <th><?php _e('Existants', 'drinks-cocktails-import'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>drinks.json</td>
                                <td>
                                    <?php if ($drinks_exists): ?>
                                        <span class="dashicons dashicons-yes-alt" style="color: green;"></span>
                                        <?php _e('Trouvé', 'drinks-cocktails-import'); ?>
                                    <?php else: ?>
                                        <span class="dashicons dashicons-dismiss" style="color: red;"></span>
                                        <?php _e('Manquant', 'drinks-cocktails-import'); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    if ($drinks_exists) {
                                        $drinks_data = json_decode(file_get_contents($drinks_file), true);
                                        echo isset($drinks_data['drinks']) ? count($drinks_data['drinks']) : 0;
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td><?php echo $existing_drinks->publish; ?></td>
                            </tr>
                            <tr>
                                <td>cocktails.json</td>
                                <td>
                                    <?php if ($cocktails_exists): ?>
                                        <span class="dashicons dashicons-yes-alt" style="color: green;"></span>
                                        <?php _e('Trouvé', 'drinks-cocktails-import'); ?>
                                    <?php else: ?>
                                        <span class="dashicons dashicons-dismiss" style="color: red;"></span>
                                        <?php _e('Manquant', 'drinks-cocktails-import'); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    if ($cocktails_exists) {
                                        $cocktails_data = json_decode(file_get_contents($cocktails_file), true);
                                        echo isset($cocktails_data['cocktails']) ? count($cocktails_data['cocktails']) : 0;
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td><?php echo $existing_cocktails->publish; ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Options d'import -->
                <div class="dci-options-section">
                    <h2><?php _e('Options d\'import', 'drinks-cocktails-import'); ?></h2>
                    <form id="dci-import-form">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Éléments à importer', 'drinks-cocktails-import'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="import_drinks" value="1" checked="checked" <?php echo !$drinks_exists ? 'disabled' : ''; ?>>
                                        <?php _e('Importer les drinks', 'drinks-cocktails-import'); ?>
                                    </label><br>
                                    <label>
                                        <input type="checkbox" name="import_cocktails" value="1" checked="checked" <?php echo !$cocktails_exists ? 'disabled' : ''; ?>>
                                        <?php _e('Importer les cocktails', 'drinks-cocktails-import'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Gestion des doublons', 'drinks-cocktails-import'); ?></th>
                                <td>
                                    <label>
                                        <input type="radio" name="update_existing" value="1" checked="checked">
                                        <?php _e('Mettre à jour les éléments existants', 'drinks-cocktails-import'); ?>
                                    </label><br>
                                    <label>
                                        <input type="radio" name="update_existing" value="0">
                                        <?php _e('Ignorer les éléments existants', 'drinks-cocktails-import'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Images', 'drinks-cocktails-import'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="download_images" value="1" checked="checked">
                                        <?php _e('Télécharger les images (en arrière-plan)', 'drinks-cocktails-import'); ?>
                                    </label>
                                    <p class="description"><?php _e('Les images seront téléchargées progressivement après l\'import des données.', 'drinks-cocktails-import'); ?></p>
                                </td>
                            </tr>
                        </table>
                        
                        <p class="submit">
                            <button type="button" id="dci-start-import" class="button button-primary">
                                <?php _e('Lancer l\'import', 'drinks-cocktails-import'); ?>
                            </button>
                        </p>
                    </form>
                </div>
                
                <!-- Zone de progression -->
                <div id="dci-progress-section" style="display: none;">
                    <h2><?php _e('Progression', 'drinks-cocktails-import'); ?></h2>
                    <div class="dci-progress-bar">
                        <div class="dci-progress-fill" style="width: 0%;"></div>
                    </div>
                    <div class="dci-progress-text">
                        <span class="dci-progress-status"><?php _e('Préparation...', 'drinks-cocktails-import'); ?></span>
                        <span class="dci-progress-percent">0%</span>
                    </div>
                </div>
                
                <!-- Zone de résultats -->
                <div id="dci-results-section" style="display: none;">
                    <h2><?php _e('Résultats de l\'import', 'drinks-cocktails-import'); ?></h2>
                    <div class="dci-results-stats"></div>
                    <div class="dci-results-logs">
                        <h3><?php _e('Journal d\'import', 'drinks-cocktails-import'); ?></h3>
                        <div class="dci-logs-container"></div>
                    </div>
                </div>
                
            <?php endif; ?>
        </div>
        
        <style>
        .dci-import-wrap {
            max-width: 800px;
        }
        
        .dci-status-section, .dci-options-section {
            background: #fff;
            padding: 20px;
            margin: 20px 0;
            box-shadow: 0 1px 3px rgba(0,0,0,.1);
        }
        
        .dci-progress-bar {
            height: 30px;
            background: #f0f0f0;
            border-radius: 15px;
            overflow: hidden;
            margin: 20px 0;
        }
        
        .dci-progress-fill {
            height: 100%;
            background: #2271b1;
            transition: width 0.3s ease;
        }
        
        .dci-progress-text {
            display: flex;
            justify-content: space-between;
            margin-top: 10px;
        }
        
        #dci-progress-section, #dci-results-section {
            background: #fff;
            padding: 20px;
            margin: 20px 0;
            box-shadow: 0 1px 3px rgba(0,0,0,.1);
        }
        
        .dci-results-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        
        .dci-stat-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            text-align: center;
        }
        
        .dci-stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #2271b1;
        }
        
        .dci-stat-label {
            color: #666;
            margin-top: 5px;
        }
        
        .dci-logs-container {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #ddd;
            padding: 10px;
            background: #f8f9fa;
            font-family: monospace;
            font-size: 12px;
        }
        
        .dci-log-entry {
            margin: 5px 0;
            padding: 5px;
        }
        
        .dci-log-success {
            color: #008a00;
        }
        
        .dci-log-error {
            color: #d63638;
            font-weight: bold;
        }
        
        .dci-log-info {
            color: #0073aa;
        }
        
        .dci-log-warning {
            color: #996800;
        }
        </style>
        <?php
    }
    
    /**
     * AJAX handler pour lancer l'import
     */
    public function ajax_run_import() {
        // Vérifications de sécurité
        if (!check_ajax_referer('dci_import_nonce', 'nonce', false)) {
            wp_die('Nonce invalide');
        }
        
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Permissions insuffisantes');
        }
        
        // Récupérer les options
        $options = array(
            'update_existing' => isset($_POST['update_existing']) && $_POST['update_existing'] === '1',
            'download_images' => isset($_POST['download_images']) && $_POST['download_images'] === '1',
            'import_drinks' => isset($_POST['import_drinks']) && $_POST['import_drinks'] === '1',
            'import_cocktails' => isset($_POST['import_cocktails']) && $_POST['import_cocktails'] === '1'
        );
        
        // Créer l'importeur et lancer l'import
        require_once DCI_PLUGIN_DIR . 'includes/class-dci-importer.php';
        $importer = new DCI_Importer($options);
        
        // Sauvegarder le statut de l'import dans un transient pour le suivi
        set_transient('dci_import_status', array(
            'status' => 'running',
            'started' => time(),
            'progress' => 0
        ), HOUR_IN_SECONDS);
        
        // Lancer l'import
        $result = $importer->run_import();
        
        // Mettre à jour le statut
        set_transient('dci_import_status', array(
            'status' => 'completed',
            'started' => time(),
            'progress' => 100,
            'result' => $result
        ), HOUR_IN_SECONDS);
        
        // Retourner le résultat
        wp_send_json_success($result);
    }
    
    /**
     * AJAX handler pour vérifier le statut
     */
    public function ajax_check_status() {
        if (!check_ajax_referer('dci_import_nonce', 'nonce', false)) {
            wp_die('Nonce invalide');
        }
        
        $status = get_transient('dci_import_status');
        
        if ($status === false) {
            wp_send_json_success(array(
                'status' => 'idle',
                'progress' => 0
            ));
        }
        
        wp_send_json_success($status);
    }
}

new DCI_Admin_Import();