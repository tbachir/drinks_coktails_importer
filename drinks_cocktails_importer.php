<?php

/**
 * Plugin Name: Drinks & Cocktails - Import & CPT
 * Plugin URI: https://drinks_cocktails_importer.com
 * Description: Crée les CPT Drink/Cocktail, leurs metas, permet l'import JSON (relations par slugs), téléchargement différé des images, et expose tout dans l'API REST. Logging via error_log (WordPress).
 * Version: 1.1.0
 * Author: Tarek Bachir
 * Text Domain: drinks-cocktails-import-cpt
 * Domain Path: /languages
 */

// Sécurité : empêcher l'accès direct
if (!defined('ABSPATH')) {
    exit;
}

// Constantes du plugin
define('DCI_VERSION', '1.1.0');
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
    private $cocktails = null;
    private $drinks = null;
    private $editableContents = null;
    private $adminImport = null;
    private $imageMonitor = null;

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
        $this->init_components();
    }

    /**
     * Charger les dépendances
     */
    private function load_dependencies()
    {
        // Charger les post types
        $this->load_post_types();


        // Charger la classe d'import API
        $api_file = DCI_PLUGIN_DIR . 'includes/class-dci-api.php';
        if (file_exists($api_file)) {
            require_once $api_file;
        }
        // Charger les classes d'administration

        $this->load_admin_classes();
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

        // Hook pour créer les dossiers nécessaires
        add_action('init', [$this, 'create_plugin_directories']);
    }

    /**
     * Initialiser les composants
     */
    private function init_components()
    {
        // Post types
        $this->cocktails = new Cocktails();
        $this->drinks = new Drinks();
        $this->editableContents = new Editable_Content();

        // Admin
        if (is_admin()) {
            $this->adminImport = new DCI_Admin_Import();
        }
    }

    /**
     * Charger les post types
     */
    public function load_post_types()
    {
        // Charger automatiquement tous les fichiers dans post-types/
        $post_types_dir = DCI_PLUGIN_DIR . 'post-types/';

        if (is_dir($post_types_dir)) {
            foreach (glob($post_types_dir . '*.php') as $file) {
                require_once $file;
            }
        }
    }

    /**
     * Charger les classes d'administration
     */
    public function load_admin_classes()
    {
        if (is_admin()) {
            // Charger la classe d'import admin
            $admin_file = DCI_PLUGIN_DIR . 'admin/class-dci-admin-import.php';
            if (file_exists($admin_file)) {
                require_once $admin_file;
            }

            // Charger la classe d'importeur si nécessaire
            $importer_file = DCI_PLUGIN_DIR . 'includes/class-dci-importer.php';
            if (file_exists($importer_file)) {
                require_once $importer_file;
            }
        }
    }

    /**
     * Créer les répertoires nécessaires
     */
    public function create_plugin_directories()
    {
        $directories = array(
            DCI_PLUGIN_DIR . 'admin',
            DCI_PLUGIN_DIR . 'includes',
            DCI_PLUGIN_DIR . 'assets',
            DCI_PLUGIN_DIR . 'assets/css',
            DCI_PLUGIN_DIR . 'assets/js',
            DCI_PLUGIN_DIR . 'assets/images',
            DCI_PLUGIN_DIR . 'languages',
            DCI_PLUGIN_DIR . 'templates'
        );

        foreach ($directories as $dir) {
            if (!file_exists($dir)) {
                wp_mkdir_p($dir);
            }
        }
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
        // Créer les répertoires
        $this->create_plugin_directories();

        // Charger les post types
        $this->load_post_types();

        // Rafraîchir les permaliens
        flush_rewrite_rules();

        // Version en base de données
        update_option('DCI_version', DCI_VERSION);

        // Créer les tables si nécessaire
        $this->create_database_tables();

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
     * Créer les tables de base de données
     */
    private function create_database_tables()
    {
        // Pour l'instant, nous utilisons les post meta
        // Cette méthode est prête pour une future évolution
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
