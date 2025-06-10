<?php

/**
 * Plugin Name: Drinks & Cocktails - Import & CPT
 * Plugin URI: https://drinks_cocktails_importer.com
 * Description: Crée les CPT Drink/Cocktail, leurs metas, permet l’import JSON (relations par slugs), téléchargement différé des images, et expose tout dans l’API REST. Logging via error_log (WordPress).
 * Version: 1.0.0
 * Author: Tarek Bachir
 * Text Domain: drinks-cocktails-import-cpt
 * Domain Path: /languages
 */

// Sécurité : empêcher l'accès direct
if (!defined('ABSPATH')) {
    exit;
}

// Constantes du plugin
define('DCI_VERSION', '1.0.0');
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
        $this->cocktails = new Cocktails();
        $this->drinks = new Drinks();
        $this->editableContents = new Editable_Content();
    }

    private function load_dependencies() {
        $this->load_post_types();
    }

    /**
     * Initialiser les hooks WordPress
     */
    private function init_hooks()
    {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
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
     * Charger les styles admin
     */
    public function enqueue_admin_styles($hook)
    {

    }

    /**
     * Activation du plugin
     */
    public function activate()
    {
        // Charger les post types
        $this->load_post_types();

        // Rafraîchir les permaliens
        flush_rewrite_rules();

        // Version en base de données
        update_option('DCI_version', DCI_VERSION);
    }

    /**
     * Désactivation du plugin
     */
    public function deactivate()
    {
        // Nettoyer les permaliens
        flush_rewrite_rules();
    }
}

// Initialiser le plugin
new DCI_Plugin();
