<?php
/**
 * Post Type pour Drinks - Structure mise à jour
 * 
 * @package Inline_Editor_CMS
 * @subpackage PostTypes
 */

if (!defined('ABSPATH')) exit;

class Drinks {
    
    public function __construct() {
        add_action('init', array($this, 'register_post_types'));
        add_action('rest_api_init', array($this, 'register_rest_fields'));
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_meta_boxes'));
    }
    
    /**
     * Enregistrer le post type
     */
    public function register_post_types() {
        $this->register_drink_post_type();
    }
    
    /**
     * Post type pour les drinks/boissons
     */
    private function register_drink_post_type() {
        $labels = array(
            'name'               => _x('Drinks', 'Post type general name', 'inline-editor-cms'),
            'singular_name'      => _x('Drink', 'Post type singular name', 'inline-editor-cms'),
            'menu_name'          => _x('Drinks', 'Admin Menu text', 'inline-editor-cms'),
            'add_new'            => __('Ajouter', 'inline-editor-cms'),
            'add_new_item'       => __('Ajouter un drink', 'inline-editor-cms'),
            'edit_item'          => __('Modifier le drink', 'inline-editor-cms'),
            'new_item'           => __('Nouveau drink', 'inline-editor-cms'),
            'view_item'          => __('Voir le drink', 'inline-editor-cms'),
            'search_items'       => __('Rechercher des drinks', 'inline-editor-cms'),
            'not_found'          => __('Aucun drink trouvé', 'inline-editor-cms'),
            'not_found_in_trash' => __('Aucun drink dans la corbeille', 'inline-editor-cms'),
        );

        $args = array(
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'rewrite'            => array('slug' => 'drinks'),
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => 25,
            'menu_icon'          => 'dashicons-drinks',
            'supports'           => array('title', 'editor', 'thumbnail', 'custom-fields', 'revisions'),
            'show_in_rest'       => true,
            'rest_base'          => 'drinks',
            'rest_controller_class' => 'WP_REST_Posts_Controller',
        );

        register_post_type('drink', $args);
    }
    
    /**
     * Ajouter les champs personnalisés à l'API REST
     */
    public function register_rest_fields() {
        register_rest_field('drink', 'drink_meta', array(
            'get_callback' => array($this, 'get_drink_meta'),
            'update_callback' => array($this, 'update_drink_meta'),
            'schema' => array(
                'description' => 'Métadonnées du drink',
                'type' => 'object',
                'properties' => array(
                    'tagline' => array('type' => 'string'),
                    'description_complete' => array('type' => 'string'),
                    'type' => array('type' => 'string'),
                    'volume_ml' => array('type' => 'integer'),
                    'tasting_notes' => array('type' => 'array'),
                    'characteristics' => array('type' => 'array'),
                    'note_speciale' => array('type' => 'string'),
                    'color' => array('type' => 'string'),
                    'image_square' => array('type' => 'string'),
                    'featured_cocktail_id' => array('type' => 'integer'),
                    'cocktails' => array('type' => 'array')
                )
            )
        ));
    }
    
    /**
     * Récupérer les métadonnées d'un drink
     */
    public function get_drink_meta($post) {
        return array(
            'tagline' => get_post_meta($post['id'], '_tagline', true),
            'description_complete' => get_post_meta($post['id'], '_description_complete', true),
            'type' => get_post_meta($post['id'], '_type', true),
            'volume_ml' => get_post_meta($post['id'], '_volume_ml', true),
            'tasting_notes' => $this->get_repeater_field($post['id'], '_tasting_notes'),
            'characteristics' => $this->get_repeater_field($post['id'], '_characteristics'),
            'note_speciale' => get_post_meta($post['id'], '_note_speciale', true),
            'color' => get_post_meta($post['id'], '_color', true) ?: '#ddd49a',
            'image_square' => get_post_meta($post['id'], '_image_square', true),
            'featured_cocktail_id' => get_post_meta($post['id'], '_featured_cocktail_id', true),
            'cocktails' => $this->get_repeater_field($post['id'], '_cocktails')
        );
    }
    
    /**
     * Mettre à jour les métadonnées d'un drink
     */
    public function update_drink_meta($value, $post) {
        if (isset($value['tagline'])) {
            update_post_meta($post->ID, '_tagline', sanitize_text_field($value['tagline']));
        }
        if (isset($value['description_complete'])) {
            update_post_meta($post->ID, '_description_complete', wp_kses_post($value['description_complete']));
        }
        if (isset($value['type'])) {
            update_post_meta($post->ID, '_type', sanitize_text_field($value['type']));
        }
        if (isset($value['volume_ml'])) {
            update_post_meta($post->ID, '_volume_ml', absint($value['volume_ml']));
        }
        if (isset($value['tasting_notes'])) {
            $this->update_repeater_field($post->ID, '_tasting_notes', $value['tasting_notes']);
        }
        if (isset($value['characteristics'])) {
            $this->update_repeater_field($post->ID, '_characteristics', $value['characteristics']);
        }
        if (isset($value['note_speciale'])) {
            update_post_meta($post->ID, '_note_speciale', wp_kses_post($value['note_speciale']));
        }
        if (isset($value['color'])) {
            update_post_meta($post->ID, '_color', sanitize_hex_color($value['color']));
        }
        if (isset($value['image_square'])) {
            update_post_meta($post->ID, '_image_square', esc_url_raw($value['image_square']));
        }
        if (isset($value['featured_cocktail_id'])) {
            update_post_meta($post->ID, '_featured_cocktail_id', absint($value['featured_cocktail_id']));
        }
        if (isset($value['cocktails'])) {
            $this->update_cocktails_relation($post->ID, $value['cocktails']);
        }
        
        return true;
    }
    
    /**
     * Ajouter les meta boxes
     */
    public function add_meta_boxes() {
        add_meta_box(
            'drink_meta',
            'Informations Drink',
            array($this, 'render_drink_meta_box'),
            'drink',
            'normal',
            'high'
        );
    }
    
    /**
     * Afficher la meta box pour les drinks
     */
    public function render_drink_meta_box($post) {
        wp_nonce_field('drink_meta', 'drink_meta_nonce');
        
        $tagline = get_post_meta($post->ID, '_tagline', true);
        $description_complete = get_post_meta($post->ID, '_description_complete', true);
        $type = get_post_meta($post->ID, '_type', true);
        $volume_ml = get_post_meta($post->ID, '_volume_ml', true);
        $tasting_notes = $this->get_repeater_field($post->ID, '_tasting_notes');
        $characteristics = $this->get_repeater_field($post->ID, '_characteristics');
        $note_speciale = get_post_meta($post->ID, '_note_speciale', true);
        $color = get_post_meta($post->ID, '_color', true) ?: '#ddd49a';
        $image_square = get_post_meta($post->ID, '_image_square', true);
        $featured_cocktail_id = get_post_meta($post->ID, '_featured_cocktail_id', true);
        $cocktails = $this->get_repeater_field($post->ID, '_cocktails');
        
        ?>
        <table class="form-table">
            <tr>
                <th><label for="tagline">Tagline</label></th>
                <td><input type="text" id="tagline" name="tagline" value="<?php echo esc_attr($tagline); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="description_complete">Description complète</label></th>
                <td>
                    <?php 
                    wp_editor($description_complete, 'description_complete', array(
                        'textarea_name' => 'description_complete',
                        'media_buttons' => false,
                        'textarea_rows' => 8,
                        'teeny' => true
                    )); 
                    ?>
                </td>
            </tr>
            <tr>
                <th><label for="type">Type</label></th>
                <td>
                    <select id="type" name="type">
                        <option value="">Sélectionner un type</option>
                        <option value="Tonic" <?php selected($type, 'Tonic'); ?>>Tonic</option>
                        <option value="Soda" <?php selected($type, 'Soda'); ?>>Soda</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="volume_ml">Volume (ml)</label></th>
                <td><input type="number" id="volume_ml" name="volume_ml" value="<?php echo esc_attr($volume_ml); ?>" class="small-text" /></td>
            </tr>
            <tr>
                <th><label>Notes de dégustation</label></th>
                <td>
                    <div id="tasting-notes-container">
                        <?php foreach ($tasting_notes as $i => $note): ?>
                            <div class="repeater-item">
                                <input type="text" name="tasting_notes[]" value="<?php echo esc_attr($note); ?>" class="regular-text" />
                                <button type="button" class="button remove-item">Supprimer</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button add-tasting-note">Ajouter une note</button>
                </td>
            </tr>
            <tr>
                <th><label>Caractéristiques</label></th>
                <td>
                    <div id="characteristics-container">
                        <?php foreach ($characteristics as $i => $char): ?>
                            <div class="repeater-item">
                                <input type="text" name="characteristics[]" value="<?php echo esc_attr($char); ?>" class="regular-text" />
                                <button type="button" class="button remove-item">Supprimer</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button add-characteristic">Ajouter une caractéristique</button>
                </td>
            </tr>
            <tr>
                <th><label for="note_speciale">Note spéciale</label></th>
                <td>
                    <?php 
                    wp_editor($note_speciale, 'note_speciale', array(
                        'textarea_name' => 'note_speciale',
                        'media_buttons' => false,
                        'textarea_rows' => 4,
                        'teeny' => true
                    )); 
                    ?>
                </td>
            </tr>
            <tr>
                <th><label for="color">Couleur</label></th>
                <td><input type="color" id="color" name="color" value="<?php echo esc_attr($color); ?>" /></td>
            </tr>
            <tr>
                <th><label for="image_square">Image carrée (URL)</label></th>
                <td><input type="url" id="image_square" name="image_square" value="<?php echo esc_attr($image_square); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="featured_cocktail_id">Cocktail en vedette</label></th>
                <td>
                    <?php
                    wp_dropdown_pages(array(
                        'post_type' => 'cocktail',
                        'selected' => $featured_cocktail_id,
                        'name' => 'featured_cocktail_id',
                        'show_option_none' => 'Aucun cocktail sélectionné',
                        'option_none_value' => ''
                    ));
                    ?>
                </td>
            </tr>
            <tr>
                <th><label>Cocktails liés (IDs)</label></th>
                <td>
                    <div id="cocktails-container">
                        <?php foreach ($cocktails as $i => $cocktail_id): ?>
                            <div class="repeater-item">
                                <input type="number" name="cocktails[]" value="<?php echo esc_attr($cocktail_id); ?>" class="small-text" min="1" />
                                <button type="button" class="button remove-item">Supprimer</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button add-cocktail">Ajouter un cocktail</button>
                </td>
            </tr>
        </table>
        
        <script>
        jQuery(document).ready(function($) {
            $('.add-tasting-note').click(function() {
                $('#tasting-notes-container').append('<div class="repeater-item"><input type="text" name="tasting_notes[]" value="" class="regular-text" /><button type="button" class="button remove-item">Supprimer</button></div>');
            });
            
            $('.add-characteristic').click(function() {
                $('#characteristics-container').append('<div class="repeater-item"><input type="text" name="characteristics[]" value="" class="regular-text" /><button type="button" class="button remove-item">Supprimer</button></div>');
            });
            
            $('.add-cocktail').click(function() {
                $('#cocktails-container').append('<div class="repeater-item"><input type="number" name="cocktails[]" value="" class="small-text" min="1" /><button type="button" class="button remove-item">Supprimer</button></div>');
            });
            
            $(document).on('click', '.remove-item', function() {
                $(this).parent().remove();
            });
        });
        </script>
        
        <style>
        .repeater-item { margin-bottom: 10px; }
        .repeater-item input { margin-right: 10px; }
        </style>
        <?php
    }
    
    /**
     * Sauvegarder les meta boxes
     */
    public function save_meta_boxes($post_id) {
        if (get_post_type($post_id) === 'drink') {
            if (!isset($_POST['drink_meta_nonce']) || !wp_verify_nonce($_POST['drink_meta_nonce'], 'drink_meta')) {
                return;
            }
            $this->save_drink_meta($post_id);
        }
    }
    
    /**
     * Sauvegarder les métadonnées d'un drink
     */
    private function save_drink_meta($post_id) {
        if (isset($_POST['tagline'])) {
            update_post_meta($post_id, '_tagline', sanitize_text_field($_POST['tagline']));
        }
        
        if (isset($_POST['description_complete'])) {
            update_post_meta($post_id, '_description_complete', wp_kses_post($_POST['description_complete']));
        }
        
        if (isset($_POST['type'])) {
            update_post_meta($post_id, '_type', sanitize_text_field($_POST['type']));
        }
        
        if (isset($_POST['volume_ml'])) {
            update_post_meta($post_id, '_volume_ml', absint($_POST['volume_ml']));
        }
        
        if (isset($_POST['tasting_notes'])) {
            $this->update_repeater_field($post_id, '_tasting_notes', $_POST['tasting_notes']);
        }
        
        if (isset($_POST['characteristics'])) {
            $this->update_repeater_field($post_id, '_characteristics', $_POST['characteristics']);
        }
        
        if (isset($_POST['note_speciale'])) {
            update_post_meta($post_id, '_note_speciale', wp_kses_post($_POST['note_speciale']));
        }
        
        if (isset($_POST['color'])) {
            update_post_meta($post_id, '_color', sanitize_hex_color($_POST['color']));
        }
        
        if (isset($_POST['image_square'])) {
            update_post_meta($post_id, '_image_square', esc_url_raw($_POST['image_square']));
        }
        
        if (isset($_POST['featured_cocktail_id'])) {
            update_post_meta($post_id, '_featured_cocktail_id', absint($_POST['featured_cocktail_id']));
        }
        
        if (isset($_POST['cocktails'])) {
            $this->update_cocktails_relation($post_id, $_POST['cocktails']);
        }
    }
    
    /**
     * Récupérer un champ répéteur
     */
    private function get_repeater_field($post_id, $field_name) {
        $values = get_post_meta($post_id, $field_name, true);
        return is_array($values) ? $values : array();
    }
    
    /**
     * Mettre à jour un champ répéteur
     */
    private function update_repeater_field($post_id, $field_name, $values) {
        $clean_values = array();
        if (is_array($values)) {
            foreach ($values as $value) {
                $clean_value = sanitize_text_field($value);
                if (!empty($clean_value)) {
                    $clean_values[] = $clean_value;
                }
            }
        }
        update_post_meta($post_id, $field_name, $clean_values);
    }
    
    /**
     * Mettre à jour les relations avec les cocktails
     */
    private function update_cocktails_relation($drink_id, $cocktail_ids) {
        $clean_ids = array();
        if (is_array($cocktail_ids)) {
            foreach ($cocktail_ids as $id) {
                $clean_id = absint($id);
                if ($clean_id > 0) {
                    $clean_ids[] = $clean_id;
                }
            }
        }
        update_post_meta($drink_id, '_cocktails', $clean_ids);
    }
}
new Drinks();