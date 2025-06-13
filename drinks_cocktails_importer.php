<?php

/**
 * Plugin Name: Drinks & Cocktails - Import & CPT
 * Plugin URI: https://drinks_cocktails_importer.com
 * Description: Crée les CPT Drink/Cocktail, leurs metas, permet l'import JSON (relations par slugs), téléchargement différé des images, et expose tout dans l'API REST. Logging via error_log (WordPress).
 * Version: 1.4.0
 * Author: Tarek Bachir
 */

// Sécurité : empêcher l'accès direct
if (!defined('ABSPATH')) {
    exit;
}

// Constantes du plugin
define('DCI_VERSION', '1.4.0');
define('DCI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DCI_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Classe principale du plugin
 */
class DCI_Plugin
{
    /**
     * Instance unique du plugin
     */
    private static $instance = null;

    /**
     * Obtenir l'instance unique
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructeur
     */
    public function __construct()
    {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Charger les dépendances
     */
    private function load_dependencies()
    {
        // Charger les classes principales d'abord
        require_once DCI_PLUGIN_DIR . 'includes/class-dci-importer.php';
        require_once DCI_PLUGIN_DIR . 'includes/class-dci-api.php';
        
        // Charger les post types
        $this->load_post_types();
        
        // Charger les classes d'administration
        if (is_admin()) {
            $this->load_admin_classes();
        }
    }

    /**
     * Charger les post types
     */
    private function load_post_types()
    {
        $post_types_files = array(
            'class-drinks.php',
            'class-cocktails.php',
            'class-editable-content.php'
        );
        
        foreach ($post_types_files as $file) {
            $file_path = DCI_PLUGIN_DIR . 'post-types/' . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
            }
        }
    }

    /**
     * Charger les classes d'administration
     */
    private function load_admin_classes()
    {
        // Charger l'interface d'import
        require_once DCI_PLUGIN_DIR . 'admin/class-dci-admin-import.php';
        
        // Charger la page de migration
        $migration_file = DCI_PLUGIN_DIR . 'admin/class-dci-admin-migration.php';
        if (file_exists($migration_file)) {
            require_once $migration_file;
        }
        
        // Charger la meta box des drinks
        $meta_box_file = DCI_PLUGIN_DIR . 'includes/admin/class-drink-meta-box.php';
        if (file_exists($meta_box_file)) {
            require_once $meta_box_file;
        }
    }

    /**
     * Initialiser les hooks WordPress
     */
    private function init_hooks()
    {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        // Hook pour les styles/scripts admin
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

    }


    /**
     * Charger les styles et scripts admin
     */
    public function enqueue_admin_assets($hook)
    {
        // CSS global admin
        wp_enqueue_style(
            'dci-admin-global',
            DCI_PLUGIN_URL . 'assets/css/admin-global.css',
            array(),
            DCI_VERSION
        );
    }

    /**
     * Activation du plugin
     */
    public function activate()
    {
        // Charger les dépendances pour l'activation
        $this->load_dependencies();

        // Rafraîchir les permaliens
        flush_rewrite_rules();

        // Version en base de données
        update_option('DCI_version', DCI_VERSION);

        // Planifier les tâches cron
        $this->schedule_cron_events();

        // Log d'activation
        error_log('[DCI] Plugin activé - Version ' . DCI_VERSION);
    }

    /**
     * Désactivation du plugin
     */
    public function deactivate()
    {
        // Nettoyer les permaliens
        flush_rewrite_rules();

        // Supprimer les tâches cron
        $this->unschedule_cron_events();

        // Log de désactivation
        error_log('[DCI] Plugin désactivé');
    }

    /**
     * Planifier les événements cron
     */
    private function schedule_cron_events()
    {
        // Planifier le nettoyage des logs
        if (!wp_next_scheduled('dci_cleanup_logs')) {
            wp_schedule_event(time(), 'daily', 'dci_cleanup_logs');
        }

        // Planifier la vérification des images
        if (!wp_next_scheduled('dci_check_pending_images')) {
            wp_schedule_event(time(), 'hourly', 'dci_check_pending_images');
        }
    }

    /**
     * Supprimer les événements cron
     */
    private function unschedule_cron_events()
    {
        wp_clear_scheduled_hook('dci_cleanup_logs');
        wp_clear_scheduled_hook('dci_check_pending_images');
    }
}

// Initialiser le plugin
DCI_Plugin::get_instance();