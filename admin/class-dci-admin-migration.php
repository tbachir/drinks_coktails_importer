<?php
/**
 * Page d'administration pour lancer la migration
 * À ajouter dans admin/class-dci-admin-migration.php
 */

if (!defined('ABSPATH')) exit;

class DCI_Admin_Migration {
    
    /**
     * Constructeur
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_migration_page'));
        add_action('wp_ajax_dci_run_migration', array($this, 'ajax_run_migration'));
    }
    
    /**
     * Ajouter la page de migration au menu
     */
    public function add_migration_page() {
        add_submenu_page(
            'edit.php?post_type=drink',
            __('Migration des données', 'drinks-cocktails-import-cpt'),
            __('Migration', 'drinks-cocktails-import-cpt'),
            'manage_options',
            'dci-migration',
            array($this, 'render_migration_page')
        );
    }
    
    /**
     * Afficher la page de migration
     */
    public function render_migration_page() {
        // Charger le script de migration
        require_once DCI_PLUGIN_DIR . 'admin/dci-migration-fix.php';
        
        ?>
        <div class="wrap">
            <h1><?php _e('Migration des données Drinks & Cocktails', 'drinks-cocktails-import-cpt'); ?></h1>
            
            <div class="notice notice-info">
                <p><?php _e('Cette migration va corriger les clés meta et télécharger les images manquantes.', 'drinks-cocktails-import-cpt'); ?></p>
                <p><strong><?php _e('Il est recommandé de faire une sauvegarde de votre base de données avant de continuer.', 'drinks-cocktails-import-cpt'); ?></strong></p>
            </div>
            
            <div class="migration-actions">
                <p>
                    <button type="button" id="run-migration-dry" class="button button-secondary">
                        <?php _e('Simuler la migration (dry run)', 'drinks-cocktails-import-cpt'); ?>
                    </button>
                    <button type="button" id="run-migration-real" class="button button-primary">
                        <?php _e('Exécuter la migration', 'drinks-cocktails-import-cpt'); ?>
                    </button>
                </p>
            </div>
            
            <div id="migration-progress" style="display: none;">
                <h2><?php _e('Progression', 'drinks-cocktails-import-cpt'); ?></h2>
                <div class="progress-bar" style="width: 100%; height: 30px; background: #f0f0f0; border-radius: 15px; overflow: hidden;">
                    <div class="progress-fill" style="width: 0%; height: 100%; background: #2271b1; transition: width 0.3s;"></div>
                </div>
                <p class="progress-status"></p>
            </div>
            
            <div id="migration-results" style="display: none;">
                <h2><?php _e('Résultats', 'drinks-cocktails-import-cpt'); ?></h2>
                <div class="logs-container" style="max-height: 400px; overflow-y: auto; background: #f8f9fa; padding: 10px; border: 1px solid #ddd; font-family: monospace; font-size: 12px;"></div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            function runMigration(dryRun) {
                var button = dryRun ? $('#run-migration-dry') : $('#run-migration-real');
                var originalText = button.text();
                
                // Désactiver les boutons
                $('.migration-actions button').prop('disabled', true);
                button.text('En cours...');
                
                // Afficher la progression
                $('#migration-progress').show();
                $('#migration-results').hide();
                $('.progress-fill').css('width', '50%');
                $('.progress-status').text('Migration en cours...');
                
                // Lancer la requête AJAX
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'dci_run_migration',
                        nonce: '<?php echo wp_create_nonce('dci_migration'); ?>',
                        dry_run: dryRun ? 1 : 0
                    },
                    success: function(response) {
                        $('.progress-fill').css('width', '100%');
                        $('.progress-status').text('Migration terminée!');
                        
                        if (response.success && response.data.logs) {
                            displayLogs(response.data.logs);
                        }
                    },
                    error: function() {
                        alert('Erreur lors de la migration');
                    },
                    complete: function() {
                        $('.migration-actions button').prop('disabled', false);
                        button.text(originalText);
                    }
                });
            }
            
            function displayLogs(logs) {
                $('#migration-results').show();
                var container = $('.logs-container').empty();
                
                logs.forEach(function(log) {
                    var color = 'inherit';
                    switch(log.type) {
                        case 'error': color = '#d63638'; break;
                        case 'warning': color = '#996800'; break;
                        case 'success': color = '#008a00'; break;
                        case 'info': color = '#0073aa'; break;
                    }
                    
                    $('<div>')
                        .css('color', color)
                        .text('[' + log.time + '] [' + log.type.toUpperCase() + '] ' + log.message)
                        .appendTo(container);
                });
                
                container.scrollTop(container[0].scrollHeight);
            }
            
            $('#run-migration-dry').on('click', function() {
                if (confirm('Lancer une simulation de la migration ?')) {
                    runMigration(true);
                }
            });
            
            $('#run-migration-real').on('click', function() {
                if (confirm('Êtes-vous sûr de vouloir exécuter la migration ?\n\nAssurez-vous d\'avoir fait une sauvegarde de votre base de données.')) {
                    runMigration(false);
                }
            });
        });
        </script>
        
        <style>
        .migration-actions {
            margin: 30px 0;
        }
        
        .migration-actions button {
            margin-right: 10px;
        }
        
        #migration-progress, #migration-results {
            background: #fff;
            padding: 20px;
            margin: 20px 0;
            box-shadow: 0 1px 3px rgba(0,0,0,.1);
        }
        
        .logs-container {
            line-height: 1.6;
        }
        </style>
        <?php
    }
    
    /**
     * Handler AJAX pour la migration
     */
    public function ajax_run_migration() {
        // Vérifier le nonce
        if (!check_ajax_referer('dci_migration', 'nonce', false)) {
            wp_send_json_error('Nonce invalide');
        }
        
        // Vérifier les permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissions insuffisantes');
        }
        
        $dry_run = isset($_POST['dry_run']) && $_POST['dry_run'] === '1';
        
        // Charger le script de migration
        require_once DCI_PLUGIN_DIR . 'admin/dci-migration-fix.php';
        
        // Lancer la migration
        $logs = dci_run_migration($dry_run);
        
        wp_send_json_success(array(
            'logs' => $logs,
            'dry_run' => $dry_run
        ));
    }
}

// Initialiser la page de migration
new DCI_Admin_Migration();