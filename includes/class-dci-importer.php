<?php
/**
 * Classe d'import pour Drinks & Cocktails - Version corrigée
 * 
 * @package Drinks_Cocktails_Import
 * @subpackage Importer
 */

if (!defined('ABSPATH')) exit;
ini_set('max_execution_time', 900);

/**
 * Constantes pour les clés meta standardisées
 */
class DCI_Meta_Keys {
    const DRINK_IMAGE = '_image_id';
    const DRINK_IMAGE_URL = '_image_url';
    const DRINK_CUTOUT_IMAGE = '_cutout_image_id';
    const DRINK_CUTOUT_IMAGE_URL = '_cutout_image_url';
    const COCKTAIL_IMAGE = '_image_id';
    const COCKTAIL_IMAGE_URL = '_image_url';
}

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
        'images_downloaded' => 0,
        'images_failed' => 0,
        'errors' => 0
    );

    /**
     * Options d'import
     */
    private $options = array(
        'update_existing' => true,
        'download_images' => true,
        'import_drinks' => true,
        'import_cocktails' => true,
        'force_image_download' => false // Nouvelle option pour forcer le re-téléchargement
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

        // Phase 4 : Vérification de l'intégrité des images
        if ($this->options['download_images']) {
            $this->verify_images_integrity();
        }

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
     * Importer un drink individuel - VERSION CORRIGÉE
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

        // Préparer les données du post
        $post_data = array(
            'post_title' => sanitize_text_field($data['name']),
            'post_name' => $slug,
            'post_content' => isset($data['description']) ? wp_kses_post($data['description']) : '',
            'post_status' => 'publish',
            'post_type' => 'drink'
        );

        // Vérifier si le drink existe déjà - CORRECTION DE LA TYPO
        $existing = get_posts(array(
            'post_type' => 'drink',
            'name' => $slug,
            'posts_per_page' => 1  // Corrigé de 'potss_per_page'
        ));

        if (!empty($existing) && !$this->options['update_existing']) {
            $this->log('info', sprintf('Drink "%s" déjà existant - ignoré', $data['name']));
            $this->stats['drinks_skipped']++;
            $this->drink_mapping[$slug] = $existing[0]->ID;
            return $existing[0]->ID;
        }

        // Créer ou mettre à jour
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

        // Métadonnées - utilise la méthode statique de la classe Drinks
        Drinks::persist_drink_meta($post_id, $data);

        // Gestion des images avec le nouveau système unifié
        if ($this->options['download_images']) {
            // Image principale
            if (!empty($data['image'])) {
                $this->handle_image_import(
                    $data['image'], 
                    $post_id, 
                    DCI_Meta_Keys::DRINK_IMAGE,
                    DCI_Meta_Keys::DRINK_IMAGE_URL,
                    'Image Boisson',
                    true // Set as featured
                );
            }

            // Image détourée
            if (!empty($data['cutout_image'])) {
                $this->handle_image_import(
                    $data['cutout_image'], 
                    $post_id, 
                    DCI_Meta_Keys::DRINK_CUTOUT_IMAGE,
                    DCI_Meta_Keys::DRINK_CUTOUT_IMAGE_URL,
                    'Image Bouteille',
                    false
                );
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

        // Préparer les données du post
        $post_data = array(
            'post_title' => sanitize_text_field($data['name']),
            'post_name' => $slug,
            'post_content' => isset($data['description']) ? wp_kses_post($data['description']) : '',
            'post_status' => 'publish',
            'post_type' => 'cocktail'
        );

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

        // Gestion de l'image
        if ($this->options['download_images'] && !empty($data['image'])) {
            $this->handle_image_import(
                $data['image'], 
                $post_id, 
                DCI_Meta_Keys::COCKTAIL_IMAGE,
                DCI_Meta_Keys::COCKTAIL_IMAGE_URL,
                'Image cocktail',
                true // Set as featured
            );
        }

        $this->log('success', sprintf('Cocktail "%s" %s avec succès (ID: %d)', $data['name'], $action, $post_id));

        return $post_id;
    }

    /**
     * Gestion unifiée de l'import d'images
     */
    private function handle_image_import($url, $post_id, $meta_key_id, $meta_key_url, $description = '', $set_featured = false) 
    {
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            $this->log('warning', "URL d'image invalide: $url");
            return false;
        }

        // Vérifier si l'image a déjà été téléchargée
        $existing_attachment_id = get_post_meta($post_id, $meta_key_id, true);
        if ($existing_attachment_id && !$this->options['force_image_download']) {
            // Vérifier que l'attachment existe toujours
            if (wp_attachment_is_image($existing_attachment_id)) {
                $this->log('info', "Image déjà téléchargée (ID: $existing_attachment_id)");
                return $existing_attachment_id;
            }
        }

        // Sauvegarder l'URL temporairement
        update_post_meta($post_id, $meta_key_url, esc_url_raw($url));

        // Télécharger l'image
        $attachment_id = $this->import_external_image($url, $post_id, $description);

        if (!is_wp_error($attachment_id)) {
            // Sauvegarder l'ID de l'attachment
            update_post_meta($post_id, $meta_key_id, $attachment_id);
            
            // Définir comme image à la une si demandé
            if ($set_featured) {
                set_post_thumbnail($post_id, $attachment_id);
            }
            
            // Supprimer l'URL temporaire après téléchargement réussi
            delete_post_meta($post_id, $meta_key_url);
            
            $this->stats['images_downloaded']++;
            $this->log('success', sprintf('Image téléchargée avec succès (ID: %d) depuis %s', $attachment_id, $url));
            
            return $attachment_id;
        } else {
            $this->stats['images_failed']++;
            $this->log('error', sprintf('Échec téléchargement image depuis %s: %s', $url, $attachment_id->get_error_message()));
            return false;
        }
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
            $this->log('info', sprintf('Relation featured cocktail résolue pour drink %d', $drink_id));
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
                $this->log('info', sprintf('Relations cocktails résolues pour drink %d (%d cocktails)', $drink_id, count($cocktail_ids)));
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
                $this->log('info', sprintf('Relations drinks résolues pour cocktail %d (%d drinks)', $cocktail_id, count($drink_ids)));
            }
            delete_post_meta($cocktail_id, '_temp_drink_slugs');
        }
    }

    /**
     * Vérifier l'intégrité des images après import
     */
    public function verify_images_integrity() 
    {
        $this->log('info', 'Vérification de l\'intégrité des images');
        
        // Vérifier les drinks
        $drinks = get_posts(array(
            'post_type' => 'drink',
            'posts_per_page' => -1
        ));
        
        foreach ($drinks as $drink) {
            // Vérifier image principale
            $this->verify_single_image(
                $drink->ID, 
                DCI_Meta_Keys::DRINK_IMAGE, 
                DCI_Meta_Keys::DRINK_IMAGE_URL,
                "Image principale du drink {$drink->post_title}"
            );
            
            // Vérifier image détourée
            $this->verify_single_image(
                $drink->ID, 
                DCI_Meta_Keys::DRINK_CUTOUT_IMAGE, 
                DCI_Meta_Keys::DRINK_CUTOUT_IMAGE_URL,
                "Image détourée du drink {$drink->post_title}"
            );
        }
        
        // Vérifier les cocktails
        $cocktails = get_posts(array(
            'post_type' => 'cocktail',
            'posts_per_page' => -1
        ));
        
        foreach ($cocktails as $cocktail) {
            $this->verify_single_image(
                $cocktail->ID, 
                DCI_Meta_Keys::COCKTAIL_IMAGE, 
                DCI_Meta_Keys::COCKTAIL_IMAGE_URL,
                "Image du cocktail {$cocktail->post_title}"
            );
        }
    }

    /**
     * Vérifier une image spécifique
     */
    private function verify_single_image($post_id, $meta_key_id, $meta_key_url, $description)
    {
        $image_id = get_post_meta($post_id, $meta_key_id, true);
        $image_url = get_post_meta($post_id, $meta_key_url, true);
        
        // Si on a une URL mais pas d'ID, ou si l'attachment n'existe plus
        if ($image_url && (!$image_id || !wp_attachment_is_image($image_id))) {
            $this->log('warning', "Image manquante pour $description - Re-tentative de téléchargement");
            
            // Re-télécharger l'image
            $attachment_id = $this->import_external_image($image_url, $post_id, $description);
            
            if (!is_wp_error($attachment_id)) {
                update_post_meta($post_id, $meta_key_id, $attachment_id);
                delete_post_meta($post_id, $meta_key_url);
                $this->stats['images_downloaded']++;
                $this->log('success', "Image re-téléchargée avec succès pour $description");
            } else {
                $this->stats['images_failed']++;
                $this->log('error', "Échec du re-téléchargement pour $description");
            }
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

        // Vérifier si l'image existe déjà dans la médiathèque
        $attachment_id = $this->get_attachment_id_by_url($url);
        if ($attachment_id) {
            $this->log('info', "Image déjà présente dans la médiathèque (ID: $attachment_id)");
            return $attachment_id;
        }

        // Télécharge l'image sur le serveur temporairement
        $tmp = download_url($url, 300); // Timeout de 5 minutes
        if (is_wp_error($tmp)) return $tmp;

        // Récupère le nom de fichier d'origine
        $file_array = [];
        $file_array['name'] = basename(parse_url($url, PHP_URL_PATH));
        
        // S'assurer que le nom de fichier a une extension
        if (!pathinfo($file_array['name'], PATHINFO_EXTENSION)) {
            $file_array['name'] .= '.jpg'; // Extension par défaut
        }
        
        $file_array['tmp_name'] = $tmp;

        // Charge l'image dans la médiathèque
        $attachment_id = media_handle_sideload($file_array, $post_id, $desc);

        // Efface le fichier temporaire en cas d'erreur
        if (is_wp_error($attachment_id)) {
            @unlink($file_array['tmp_name']);
            return $attachment_id;
        }

        // Sauvegarder l'URL d'origine comme meta
        update_post_meta($attachment_id, '_source_url', $url);

        return $attachment_id;
    }

    /**
     * Retrouver un attachment par son URL
     */
    private function get_attachment_id_by_url($url) 
    {
        global $wpdb;
        
        // D'abord chercher par meta _source_url
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_source_url' AND meta_value = %s LIMIT 1",
            $url
        ));
        
        if ($attachment_id) {
            return $attachment_id;
        }
        
        // Sinon chercher par guid
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE guid = %s AND post_type = 'attachment' LIMIT 1",
            $url
        ));
        
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