<?php

if (!defined('ABSPATH')) exit;

// Inclure les constantes des clés meta
require_once DCI_PLUGIN_DIR . 'includes/class-dci-importer.php';

class Drink_Meta_Box
{
    public function __construct()
    {
        add_action('add_meta_boxes', [$this, 'register_meta_box']);
        add_action('save_post_drink', [$this, 'save_meta_box_data']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    public function register_meta_box()
    {
        add_meta_box(
            'drink_meta_box',
            __('Images du Drink', 'drinks-cocktails-import-cpt'),
            [$this, 'render'],
            'drink',
            'normal',
            'high'
        );
    }

    public function render($post)
    {
        wp_nonce_field('drink_meta_box_nonce', 'drink_meta_box_nonce_field');

        // Utiliser les constantes unifiées pour les clés meta
        $drink_image_id = get_post_meta($post->ID, DCI_Meta_Keys::DRINK_IMAGE, true);
        $drink_image_url = get_post_meta($post->ID, DCI_Meta_Keys::DRINK_IMAGE_URL, true);
        $drink_cutout_image_id = get_post_meta($post->ID, DCI_Meta_Keys::DRINK_CUTOUT_IMAGE, true);
        $drink_cutout_image_url = get_post_meta($post->ID, DCI_Meta_Keys::DRINK_CUTOUT_IMAGE_URL, true);
        ?>

        <div class="drink-meta-box">
            <!-- Image principale -->
            <div class="image-field">
                <h4><?php _e('Image principale', 'drinks-cocktails-import-cpt'); ?></h4>
                <label for="drink_image"><?php _e('Image :', 'drinks-cocktails-import-cpt'); ?></label>
                <input type="hidden" id="drink_image" name="drink_image" value="<?php echo esc_attr($drink_image_id); ?>">
                <button class="button upload-image-button" data-target="#drink_image">
                    <?php _e('Sélectionner une image', 'drinks-cocktails-import-cpt'); ?>
                </button>
                
                <div class="image-preview" id="drink_image_preview">
                    <?php if ($drink_image_id && wp_attachment_is_image($drink_image_id)) : ?>
                        <img src="<?php echo wp_get_attachment_url($drink_image_id); ?>" style="max-width:100%; height:auto;">
                        <button class="button remove-image-button" data-target="#drink_image">
                            <?php _e('Supprimer', 'drinks-cocktails-import-cpt'); ?>
                        </button>
                    <?php elseif ($drink_image_url) : ?>
                        <div class="notice notice-warning inline">
                            <p><?php _e('Image non téléchargée. URL source :', 'drinks-cocktails-import-cpt'); ?></p>
                            <p><code><?php echo esc_url($drink_image_url); ?></code></p>
                            <button class="button download-image-button" 
                                    data-url="<?php echo esc_attr($drink_image_url); ?>"
                                    data-post-id="<?php echo esc_attr($post->ID); ?>"
                                    data-meta-key="<?php echo esc_attr(DCI_Meta_Keys::DRINK_IMAGE); ?>">
                                <?php _e('Télécharger maintenant', 'drinks-cocktails-import-cpt'); ?>
                            </button>
                        </div>
                    <?php else : ?>
                        <p class="description"><?php _e('Aucune image sélectionnée', 'drinks-cocktails-import-cpt'); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <hr style="margin: 30px 0;">

            <!-- Image détourée -->
            <div class="image-field">
                <h4><?php _e('Image détourée (Cutout)', 'drinks-cocktails-import-cpt'); ?></h4>
                <label for="drink_cutout_image"><?php _e('Image détourée :', 'drinks-cocktails-import-cpt'); ?></label>
                <input type="hidden" id="drink_cutout_image" name="drink_cutout_image" value="<?php echo esc_attr($drink_cutout_image_id); ?>">
                <button class="button upload-image-button" data-target="#drink_cutout_image">
                    <?php _e('Sélectionner une image détourée', 'drinks-cocktails-import-cpt'); ?>
                </button>
                
                <div class="image-preview" id="drink_cutout_image_preview">
                    <?php if ($drink_cutout_image_id && wp_attachment_is_image($drink_cutout_image_id)) : ?>
                        <img src="<?php echo wp_get_attachment_url($drink_cutout_image_id); ?>" style="max-width:100%; height:auto;">
                        <button class="button remove-image-button" data-target="#drink_cutout_image">
                            <?php _e('Supprimer', 'drinks-cocktails-import-cpt'); ?>
                        </button>
                    <?php elseif ($drink_cutout_image_url) : ?>
                        <div class="notice notice-warning inline">
                            <p><?php _e('Image non téléchargée. URL source :', 'drinks-cocktails-import-cpt'); ?></p>
                            <p><code><?php echo esc_url($drink_cutout_image_url); ?></code></p>
                            <button class="button download-image-button" 
                                    data-url="<?php echo esc_attr($drink_cutout_image_url); ?>"
                                    data-post-id="<?php echo esc_attr($post->ID); ?>"
                                    data-meta-key="<?php echo esc_attr(DCI_Meta_Keys::DRINK_CUTOUT_IMAGE); ?>">
                                <?php _e('Télécharger maintenant', 'drinks-cocktails-import-cpt'); ?>
                            </button>
                        </div>
                    <?php else : ?>
                        <p class="description"><?php _e('Aucune image détourée sélectionnée', 'drinks-cocktails-import-cpt'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    public function save_meta_box_data($post_id)
    {
        // Vérifications de sécurité
        if (!isset($_POST['drink_meta_box_nonce_field']) || 
            !wp_verify_nonce($_POST['drink_meta_box_nonce_field'], 'drink_meta_box_nonce')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Sauvegarder l'image principale
        if (isset($_POST['drink_image'])) {
            $image_id = intval($_POST['drink_image']);
            if ($image_id) {
                update_post_meta($post_id, DCI_Meta_Keys::DRINK_IMAGE, $image_id);
                // Supprimer l'URL si on a un ID
                delete_post_meta($post_id, DCI_Meta_Keys::DRINK_IMAGE_URL);
                // Définir comme image à la une
                set_post_thumbnail($post_id, $image_id);
            } else {
                delete_post_meta($post_id, DCI_Meta_Keys::DRINK_IMAGE);
                delete_post_thumbnail($post_id);
            }
        }

        // Sauvegarder l'image détourée
        if (isset($_POST['drink_cutout_image'])) {
            $cutout_id = intval($_POST['drink_cutout_image']);
            if ($cutout_id) {
                update_post_meta($post_id, DCI_Meta_Keys::DRINK_CUTOUT_IMAGE, $cutout_id);
                // Supprimer l'URL si on a un ID
                delete_post_meta($post_id, DCI_Meta_Keys::DRINK_CUTOUT_IMAGE_URL);
            } else {
                delete_post_meta($post_id, DCI_Meta_Keys::DRINK_CUTOUT_IMAGE);
            }
        }
    }

    public function enqueue_admin_assets($hook)
    {
        global $post;
        
        if (($hook === 'post-new.php' || $hook === 'post.php') && 
            isset($post) && $post->post_type === 'drink') {
            
            wp_enqueue_media();
            
            // Script amélioré avec gestion du téléchargement AJAX
            wp_enqueue_script(
                'drink-meta-box-script',
                DCI_PLUGIN_URL . 'includes/admin/assets/js/drink-meta-box.js',
                ['jquery', 'media-upload'],
                DCI_VERSION,
                true
            );

            // Localiser le script pour AJAX
            wp_localize_script('drink-meta-box-script', 'dci_meta_box', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('dci_download_image'),
                'downloading' => __('Téléchargement en cours...', 'drinks-cocktails-import-cpt'),
                'download_success' => __('Image téléchargée avec succès', 'drinks-cocktails-import-cpt'),
                'download_error' => __('Erreur lors du téléchargement', 'drinks-cocktails-import-cpt')
            ));

            // CSS amélioré
            wp_enqueue_style(
                'drink-meta-box-style',
                DCI_PLUGIN_URL . 'includes/admin/assets/css/drink-meta-box.css',
                [],
                DCI_VERSION
            );
        }
    }
}