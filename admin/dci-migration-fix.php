<?php
/**
 * Script de migration pour corriger les clés meta et les images
 * À exécuter une seule fois après la mise à jour du plugin
 * 
 * Placer ce fichier dans le dossier du plugin et l'exécuter via WP-CLI ou une page admin temporaire
 */

if (!defined('ABSPATH')) exit;

class DCI_Migration_Fix {
    
    private $logs = array();
    private $dry_run = false;
    
    public function __construct($dry_run = false) {
        $this->dry_run = $dry_run;
        
        // S'assurer que les constantes sont disponibles
        if (!class_exists('DCI_Meta_Keys')) {
            require_once DCI_PLUGIN_DIR . 'includes/class-dci-importer.php';
        }
    }
    
    /**
     * Lancer la migration complète
     */
    public function run() {
        $this->log('info', 'Début de la migration des données Drinks & Cocktails');
        $this->log('info', 'Mode: ' . ($this->dry_run ? 'DRY RUN (simulation)' : 'EXECUTION RÉELLE'));
        
        // Étape 1: Migrer les clés meta des drinks
        $this->migrate_drink_meta_keys();
        
        // Étape 2: Migrer les clés meta des cocktails
        $this->migrate_cocktail_meta_keys();
        
        // Étape 3: Vérifier et télécharger les images manquantes
        $this->fix_missing_images();
        
        // Étape 4: Nettoyer les meta temporaires résolues
        $this->cleanup_temp_metas();
        
        $this->log('info', 'Migration terminée');
        
        return $this->logs;
    }
    
    /**
     * Migrer les clés meta des drinks
     */
    private function migrate_drink_meta_keys() {
        $this->log('info', '=== Migration des clés meta des drinks ===');
        
        $drinks = get_posts(array(
            'post_type' => 'drink',
            'posts_per_page' => -1,
            'post_status' => 'any'
        ));
        
        $this->log('info', sprintf('Nombre de drinks à traiter: %d', count($drinks)));
        
        foreach ($drinks as $drink) {
            $this->log('info', sprintf('Traitement du drink "%s" (ID: %d)', $drink->post_title, $drink->ID));
            
            // Migration des anciennes clés vers les nouvelles
            $migrations = array(
                'drink_image' => DCI_Meta_Keys::DRINK_IMAGE,
                'drink_cutout_image' => DCI_Meta_Keys::DRINK_CUTOUT_IMAGE,
                '_cutout_image' => DCI_Meta_Keys::DRINK_CUTOUT_IMAGE_URL,
                '_image' => DCI_Meta_Keys::DRINK_IMAGE_URL
            );
            
            foreach ($migrations as $old_key => $new_key) {
                $this->migrate_single_meta($drink->ID, $old_key, $new_key);
            }
            
            // Vérifier l'image à la une
            $this->check_featured_image($drink->ID, 'drink');
        }
    }
    
    /**
     * Migrer les clés meta des cocktails
     */
    private function migrate_cocktail_meta_keys() {
        $this->log('info', '=== Migration des clés meta des cocktails ===');
        
        $cocktails = get_posts(array(
            'post_type' => 'cocktail',
            'posts_per_page' => -1,
            'post_status' => 'any'
        ));
        
        $this->log('info', sprintf('Nombre de cocktails à traiter: %d', count($cocktails)));
        
        foreach ($cocktails as $cocktail) {
            $this->log('info', sprintf('Traitement du cocktail "%s" (ID: %d)', $cocktail->post_title, $cocktail->ID));
            
            // Migration des anciennes clés vers les nouvelles
            $migrations = array(
                '_image' => DCI_Meta_Keys::COCKTAIL_IMAGE_URL
            );
            
            foreach ($migrations as $old_key => $new_key) {
                $this->migrate_single_meta($cocktail->ID, $old_key, $new_key);
            }
            
            // Vérifier l'image à la une
            $this->check_featured_image($cocktail->ID, 'cocktail');
        }
    }
    
    /**
     * Migrer une meta individuelle
     */
    private function migrate_single_meta($post_id, $old_key, $new_key) {
        $old_value = get_post_meta($post_id, $old_key, true);
        
        if (!empty($old_value)) {
            $new_value = get_post_meta($post_id, $new_key, true);
            
            if (empty($new_value)) {
                $this->log('info', sprintf('  Migration: %s -> %s (valeur: %s)', $old_key, $new_key, 
                    is_numeric($old_value) ? "ID $old_value" : substr($old_value, 0, 50) . '...'));
                
                if (!$this->dry_run) {
                    update_post_meta($post_id, $new_key, $old_value);
                    delete_post_meta($post_id, $old_key);
                }
            } else {
                $this->log('info', sprintf('  %s déjà migré, suppression de l\'ancienne clé %s', $new_key, $old_key));
                
                if (!$this->dry_run) {
                    delete_post_meta($post_id, $old_key);
                }
            }
        }
    }
    
    /**
     * Vérifier et corriger l'image à la une
     */
    private function check_featured_image($post_id, $post_type) {
        $thumbnail_id = get_post_thumbnail_id($post_id);
        
        if ($post_type === 'drink') {
            $image_id = get_post_meta($post_id, DCI_Meta_Keys::DRINK_IMAGE, true);
            
            if ($image_id && !$thumbnail_id) {
                $this->log('info', sprintf('  Définition de l\'image à la une depuis %s', DCI_Meta_Keys::DRINK_IMAGE));
                
                if (!$this->dry_run) {
                    set_post_thumbnail($post_id, $image_id);
                }
            }
        } elseif ($post_type === 'cocktail') {
            $image_id = get_post_meta($post_id, DCI_Meta_Keys::COCKTAIL_IMAGE, true);
            
            if ($image_id && !$thumbnail_id) {
                $this->log('info', sprintf('  Définition de l\'image à la une depuis %s', DCI_Meta_Keys::COCKTAIL_IMAGE));
                
                if (!$this->dry_run) {
                    set_post_thumbnail($post_id, $image_id);
                }
            }
        }
    }
    
    /**
     * Corriger les images manquantes
     */
    private function fix_missing_images() {
        $this->log('info', '=== Vérification et téléchargement des images manquantes ===');
        
        // Créer une instance de l'importeur
        require_once DCI_PLUGIN_DIR . 'includes/class-dci-importer.php';
        $importer = new DCI_Importer();
        
        // Vérifier les drinks
        $drinks = get_posts(array(
            'post_type' => 'drink',
            'posts_per_page' => -1,
            'post_status' => 'publish'
        ));
        
        foreach ($drinks as $drink) {
            // Image principale
            $this->fix_single_image(
                $drink->ID,
                DCI_Meta_Keys::DRINK_IMAGE,
                DCI_Meta_Keys::DRINK_IMAGE_URL,
                $importer,
                'Image principale du drink ' . $drink->post_title,
                true
            );
            
            // Image détourée
            $this->fix_single_image(
                $drink->ID,
                DCI_Meta_Keys::DRINK_CUTOUT_IMAGE,
                DCI_Meta_Keys::DRINK_CUTOUT_IMAGE_URL,
                $importer,
                'Image détourée du drink ' . $drink->post_title,
                false
            );
        }
        
        // Vérifier les cocktails
        $cocktails = get_posts(array(
            'post_type' => 'cocktail',
            'posts_per_page' => -1,
            'post_status' => 'publish'
        ));
        
        foreach ($cocktails as $cocktail) {
            $this->fix_single_image(
                $cocktail->ID,
                DCI_Meta_Keys::COCKTAIL_IMAGE,
                DCI_Meta_Keys::COCKTAIL_IMAGE_URL,
                $importer,
                'Image du cocktail ' . $cocktail->post_title,
                true
            );
        }
    }
    
    /**
     * Corriger une image manquante
     */
    private function fix_single_image($post_id, $meta_key_id, $meta_key_url, $importer, $description, $set_featured = false) {
        $image_id = get_post_meta($post_id, $meta_key_id, true);
        $image_url = get_post_meta($post_id, $meta_key_url, true);
        
        // Si on a une URL mais pas d'ID valide
        if ($image_url && (!$image_id || !wp_attachment_is_image($image_id))) {
            $this->log('warning', sprintf('  Image manquante pour %s - URL: %s', $description, $image_url));
            
            if (!$this->dry_run) {
                $attachment_id = $importer->import_external_image($image_url, $post_id, $description);
                
                if (!is_wp_error($attachment_id)) {
                    update_post_meta($post_id, $meta_key_id, $attachment_id);
                    delete_post_meta($post_id, $meta_key_url);
                    
                    if ($set_featured) {
                        set_post_thumbnail($post_id, $attachment_id);
                    }
                    
                    $this->log('success', sprintf('  Image téléchargée avec succès (ID: %d)', $attachment_id));
                } else {
                    $this->log('error', sprintf('  Échec du téléchargement: %s', $attachment_id->get_error_message()));
                }
            }
        }
    }
    
    /**
     * Nettoyer les meta temporaires résolues
     */
    private function cleanup_temp_metas() {
        $this->log('info', '=== Nettoyage des meta temporaires ===');
        
        // Nettoyer les drinks
        $drinks = get_posts(array(
            'post_type' => 'drink',
            'posts_per_page' => -1,
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_featured_cocktail_id',
                    'compare' => 'EXISTS'
                ),
                array(
                    'key' => '_temp_featured_cocktail_slug',
                    'compare' => 'EXISTS'
                )
            )
        ));
        
        foreach ($drinks as $drink) {
            $this->log('info', sprintf('  Nettoyage des meta temporaires du drink "%s"', $drink->post_title));
            
            if (!$this->dry_run) {
                delete_post_meta($drink->ID, '_temp_featured_cocktail_slug');
                delete_post_meta($drink->ID, '_temp_cocktail_slugs');
            }
        }
        
        // Nettoyer les cocktails
        $cocktails = get_posts(array(
            'post_type' => 'cocktail',
            'posts_per_page' => -1,
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_drinks',
                    'compare' => 'EXISTS'
                ),
                array(
                    'key' => '_temp_drink_slugs',
                    'compare' => 'EXISTS'
                )
            )
        ));
        
        foreach ($cocktails as $cocktail) {
            $this->log('info', sprintf('  Nettoyage des meta temporaires du cocktail "%s"', $cocktail->post_title));
            
            if (!$this->dry_run) {
                delete_post_meta($cocktail->ID, '_temp_drink_slugs');
            }
        }
    }
    
    /**
     * Logger un message
     */
    private function log($type, $message) {
        $this->logs[] = array(
            'type' => $type,
            'message' => $message,
            'time' => current_time('mysql')
        );
        
        // Afficher dans error_log aussi
        error_log(sprintf('[DCI Migration] [%s] %s', strtoupper($type), $message));
    }
}

/**
 * Fonction helper pour lancer la migration
 */
function dci_run_migration($dry_run = false) {
    $migration = new DCI_Migration_Fix($dry_run);
    return $migration->run();
}

// Si exécuté via WP-CLI
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('dci migrate', function($args, $assoc_args) {
        $dry_run = isset($assoc_args['dry-run']);
        
        WP_CLI::log('Lancement de la migration DCI...');
        
        $logs = dci_run_migration($dry_run);
        
        foreach ($logs as $log) {
            switch ($log['type']) {
                case 'error':
                    WP_CLI::error($log['message'], false);
                    break;
                case 'warning':
                    WP_CLI::warning($log['message']);
                    break;
                case 'success':
                    WP_CLI::success($log['message']);
                    break;
                default:
                    WP_CLI::log($log['message']);
            }
        }
        
        WP_CLI::success('Migration terminée!');
    });
}