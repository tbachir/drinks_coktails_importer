<?php
/**
 * Classe d'import pour Drinks & Cocktails
 * 
 * @package Drinks_Cocktails_Import
 * @subpackage Importer
 */

if (!defined('ABSPATH')) exit;
ini_set('max_execution_time', 900);
class DCI_Importer
{

    /**
     * Messages de log
     */
    private $logs = array();

    /**
     * Mapping slug -> ID pour résolution des relations
     */
    private $drink_mapping = array();
    private $cocktail_mapping = array();

    /**
     * Statistiques d'import
     */
    private $stats = array(
        'drinks_imported' => 0,
        'drinks_updated' => 0,
        'drinks_skipped' => 0,
        'cocktails_imported' => 0,
        'cocktails_updated' => 0,
        'cocktails_skipped' => 0,
        'images_queued' => 0,
        'errors' => 0
    );

    /**
     * Options d'import
     */
    private $options = array(
        'update_existing' => true,
        'download_images' => true,
        'import_drinks' => true,
        'import_cocktails' => true
    );

    /**
     * Constructeur
     */
    public function __construct($options = array())
    {
        $this->options = wp_parse_args($options, $this->options);

        add_action('dci_download_image', array($this, 'download_single_image'), 10, 3);
    }

    /**
     * Lancer l'import complet
     */
    public function run_import()
    {
        $this->log('info', 'Début de l\'import Drinks & Cocktails');

        // Phase 1 : Import des drinks
        if ($this->options['import_drinks']) {
            $this->import_drinks();
        }

        // Phase 2 : Import des cocktails
        if ($this->options['import_cocktails']) {
            $this->import_cocktails();
        }

        // Phase 3 : Résolution des relations
        $this->resolve_all_relations();

        $this->log('success', 'Import terminé', $this->stats);

        return array(
            'success' => ($this->stats['errors'] === 0),
            'stats' => $this->stats,
            'logs' => $this->logs
        );
    }

    /**
     * Importer les drinks depuis le fichier JSON
     */
    public function import_drinks()
    {
        $json_file = DCI_PLUGIN_DIR . 'post-types/data/drinks.json';

        if (!file_exists($json_file)) {
            $this->log('error', 'Fichier drinks.json introuvable');
            $this->stats['errors']++;
            return false;
        }

        $json_content = file_get_contents($json_file);
        $data = json_decode($json_content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log('error', 'Erreur parsing JSON drinks: ' . json_last_error_msg());
            $this->stats['errors']++;
            return false;
        }

        if (!isset($data['drinks']) || !is_array($data['drinks'])) {
            $this->log('error', 'Format JSON drinks invalide');
            $this->stats['errors']++;
            return false;
        }

        $this->log('info', sprintf('Import de %d drinks', count($data['drinks'])));

        foreach ($data['drinks'] as $drink_data) {
            $this->import_single_drink($drink_data);
        }

        return true;
    }

    /**
     * Importer un drink individuel
     */
    private function import_single_drink($data)
    {
        // Validation des données requises
        if (empty($data['slug']) || empty($data['name'])) {
            $this->log('error', 'Drink sans slug ou nom', $data);
            $this->stats['errors']++;
            return false;
        }

        $slug = sanitize_title($data['slug']);

        // Vérifier si le drink existe déjà
        $existing = get_posts(array(
            'post_type' => 'drink',
            'name' => $slug,
            'posts_per_page' => 1
        ));

        if (!empty($existing)) {
            $post_data['ID'] = $existing[0]->ID;
            $post_id = wp_update_post($post_data);
            $action = 'updated';
            $this->stats['drinks_updated']++;
        } else {
            $post_id = wp_insert_post($post_data);
            $action = 'imported';
            $this->stats['drinks_imported']++;
        }

        if (is_wp_error($post_id)) {
            $this->log('error', sprintf('Erreur %s drink "%s": %s', $action, $data['name'], $post_id->get_error_message()));
            $this->stats['errors']++;
            return false;
        }

        $this->drink_mapping[$slug] = $post_id;

        // Métadonnées
        Drinks::persist_drink_meta($post_id, $data);

        $image_url = $data['image'] ?? null;
        $cutout_image_url = $data['cutout_image'] ?? null;

        if ($image_url) {
            $attachment_id = $this->import_external_image($image_url, $post_id, 'Image Boisson');
            if (!is_wp_error($attachment_id)) {
                update_post_meta($post_id, '_image_id', $attachment_id);
                set_post_thumbnail($post_id, $attachment_id);
            }
        }

        if ($cutout_image_url) {
            $attachment_id = $this->import_external_image($cutout_image_url, $post_id, 'Image Bouteille');
            if (!is_wp_error($attachment_id)) {
                update_post_meta($post_id, '_cutout_image_id', $attachment_id);
            }
        }

        $this->log('success', sprintf('Drink "%s" %s avec succès (ID: %d)', $data['name'], $action, $post_id));

        return $post_id;
    }

    /**
     * Importer les cocktails depuis le fichier JSON
     */
    public function import_cocktails()
    {
        $json_file = DCI_PLUGIN_DIR . 'post-types/data/cocktails.json';

        if (!file_exists($json_file)) {
            $this->log('error', 'Fichier cocktails.json introuvable');
            $this->stats['errors']++;
            return false;
        }

        $json_content = file_get_contents($json_file);
        $data = json_decode($json_content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log('error', 'Erreur parsing JSON cocktails: ' . json_last_error_msg());
            $this->stats['errors']++;
            return false;
        }

        if (!isset($data['cocktails']) || !is_array($data['cocktails'])) {
            $this->log('error', 'Format JSON cocktails invalide');
            $this->stats['errors']++;
            return false;
        }

        $this->log('info', sprintf('Import de %d cocktails', count($data['cocktails'])));

        foreach ($data['cocktails'] as $cocktail_data) {
            $this->import_single_cocktail($cocktail_data);
        }

        return true;
    }

    /**
     * Importer un cocktail individuel
     */
    private function import_single_cocktail($data)
    {
        // Validation des données requises
        if (empty($data['slug']) || empty($data['name'])) {
            $this->log('error', 'Cocktail sans slug ou nom', $data);
            $this->stats['errors']++;
            return false;
        }

        $slug = sanitize_title($data['slug']);

        // Vérifier si le cocktail existe déjà
        $existing = get_posts(array(
            'post_type' => 'cocktail',
            'name' => $slug,
            'posts_per_page' => 1
        ));

        if (!empty($existing) && !$this->options['update_existing']) {
            $this->log('info', sprintf('Cocktail "%s" déjà existant - ignoré', $data['name']));
            $this->stats['cocktails_skipped']++;
            $this->cocktail_mapping[$slug] = $existing[0]->ID;
            return $existing[0]->ID;
        }

        // Préparer les données du post
        $post_data = array(
            'post_title' => sanitize_text_field($data['name']),
            'post_name' => $slug,
            'post_content' => isset($data['description']) ? wp_kses_post($data['description']) : '',
            'post_status' => 'publish',
            'post_type' => 'cocktail'
        );

        // Créer ou mettre à jour
        if (!empty($existing)) {
            $post_data['ID'] = $existing[0]->ID;
            $post_id = wp_update_post($post_data);
            $action = 'updated';
            $this->stats['cocktails_updated']++;
        } else {
            $post_id = wp_insert_post($post_data);
            $action = 'imported';
            $this->stats['cocktails_imported']++;
        }

        if (is_wp_error($post_id)) {
            $this->log('error', sprintf('Erreur %s cocktail "%s": %s', $action, $data['name'], $post_id->get_error_message()));
            $this->stats['errors']++;
            return false;
        }

        // Sauvegarder le mapping slug -> ID
        $this->cocktail_mapping[$slug] = $post_id;

        // Mettre à jour les métadonnées
        Cocktails::persist_cocktail_meta($post_id, $data);

        $image_url = $data['image'];

        if ($image_url && filter_var($image_url, FILTER_VALIDATE_URL)) {
            $attachment_id = $this->import_external_image($image_url, $post_id, 'Image cocktail');
            if (!is_wp_error($attachment_id)) {
                // Stocke l'ID du média dans une meta, ou comme thumbnail
                update_post_meta($post_id, '_image_id', $attachment_id);
                // Pour mettre comme image à la une :
                set_post_thumbnail($post_id, $attachment_id);
            }
        }
        $this->log('success', sprintf('Cocktail "%s" %s avec succès (ID: %d)', $data['name'], $action, $post_id));

        return $post_id;
    }

    /**
     * Résoudre toutes les relations slug -> ID
     */
    private function resolve_all_relations()
    {
        $this->log('info', 'Résolution des relations slug -> ID');

        // Résoudre les relations pour les drinks
        $drinks = get_posts(array(
            'post_type' => 'drink',
            'posts_per_page' => -1,
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => '_temp_featured_cocktail_slug',
                    'compare' => 'EXISTS'
                ),
                array(
                    'key' => '_temp_cocktail_slugs',
                    'compare' => 'EXISTS'
                )
            )
        ));

        foreach ($drinks as $drink) {
            $this->resolve_drink_relations($drink->ID);
        }

        // Résoudre les relations pour les cocktails
        $cocktails = get_posts(array(
            'post_type' => 'cocktail',
            'posts_per_page' => -1,
            'meta_key' => '_temp_drink_slugs',
            'meta_compare' => 'EXISTS'
        ));

        foreach ($cocktails as $cocktail) {
            $this->resolve_cocktail_relations($cocktail->ID);
        }

        $this->log('success', 'Relations résolues avec succès');
    }

    /**
     * Résoudre les relations d'un drink
     */
    private function resolve_drink_relations($drink_id)
    {
        // Featured cocktail
        $featured_slug = get_post_meta($drink_id, '_temp_featured_cocktail_slug', true);
        if ($featured_slug && isset($this->cocktail_mapping[$featured_slug])) {
            update_post_meta($drink_id, '_featured_cocktail_id', $this->cocktail_mapping[$featured_slug]);
            delete_post_meta($drink_id, '_temp_featured_cocktail_slug');
        }

        // Cocktails liés
        $cocktail_slugs = get_post_meta($drink_id, '_temp_cocktail_slugs', true);
        if (is_array($cocktail_slugs)) {
            $cocktail_ids = array();
            foreach ($cocktail_slugs as $slug) {
                if (isset($this->cocktail_mapping[$slug])) {
                    $cocktail_ids[] = $this->cocktail_mapping[$slug];
                }
            }
            if (!empty($cocktail_ids)) {
                update_post_meta($drink_id, '_cocktails', $cocktail_ids);
            }
            delete_post_meta($drink_id, '_temp_cocktail_slugs');
        }
    }

    /**
     * Résoudre les relations d'un cocktail
     */
    private function resolve_cocktail_relations($cocktail_id)
    {
        $drink_slugs = get_post_meta($cocktail_id, '_temp_drink_slugs', true);
        if (is_array($drink_slugs)) {
            $drink_ids = array();
            foreach ($drink_slugs as $slug) {
                if (isset($this->drink_mapping[$slug])) {
                    $drink_ids[] = $this->drink_mapping[$slug];
                }
            }
            if (!empty($drink_ids)) {
                update_post_meta($cocktail_id, '_drinks', $drink_ids);
            }
            delete_post_meta($cocktail_id, '_temp_drink_slugs');
        }
    }

    /**
     * Télécharge une image distante et l'ajoute à la médiathèque WP.
     *
     * @param string $url    URL de l'image distante.
     * @param int    $post_id ID du post parent (pour lier l'image au post).
     * @param string $desc   Description (optionnel).
     * @return int|WP_Error  Attachment ID ou WP_Error si échec.
     */
    function import_external_image($url, $post_id = 0, $desc = '')
    {
        if (empty($url)) return new WP_Error('empty_url', 'URL vide');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        // Télécharge l'image sur le serveur temporairement
        $tmp = download_url($url);
        if (is_wp_error($tmp)) return $tmp;

        // Récupère le nom de fichier d'origine
        $file_array = [];
        $file_array['name'] = basename(parse_url($url, PHP_URL_PATH));
        $file_array['tmp_name'] = $tmp;

        // Charge l'image dans la médiathèque
        $attachment_id = media_handle_sideload($file_array, $post_id, $desc);

        // Efface le fichier temporaire en cas d'erreur
        if (is_wp_error($attachment_id)) {
            @unlink($file_array['tmp_name']);
            return $attachment_id;
        }

        return $attachment_id;
    }

    /**
     * Ajouter un message au log
     */
    private function log($type, $message, $data = null)
    {
        $log_entry = array(
            'type' => $type,
            'message' => $message,
            'time' => current_time('mysql'),
            'data' => $data
        );

        $this->logs[] = $log_entry;

        // Log aussi dans error_log pour debug
        error_log(sprintf('[DCI Import] [%s] %s', strtoupper($type), $message));
        if ($data) {
            error_log('[DCI Import] Data: ' . print_r($data, true));
        }
    }

    /**
     * Obtenir les logs
     */
    public function get_logs()
    {
        return $this->logs;
    }

    /**
     * Obtenir les statistiques
     */
    public function get_stats()
    {
        return $this->stats;
    }
}
