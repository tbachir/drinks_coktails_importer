<?php
/**
 * Post Type pour Cocktails - Structure mise à jour
 * 
 * @package Inline_Editor_CMS
 * @subpackage PostTypes
 */

if (!defined('ABSPATH')) exit;

class Cocktails
{
    public function __construct()
    {
        add_action('init', array($this, 'register_post_type'));
        add_action('rest_api_init', array($this, 'register_rest_fields'));
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_meta_boxes'));
    }

    /**
     * Enregistrer le post type cocktail
     */
    public function register_post_type()
    {
        $labels = array(
            'name'               => _x('Cocktails', 'Post type general name', 'inline-editor-cms'),
            'singular_name'      => _x('Cocktail', 'Post type singular name', 'inline-editor-cms'),
            'menu_name'          => _x('Cocktails', 'Admin Menu text', 'inline-editor-cms'),
            'add_new'            => __('Ajouter', 'inline-editor-cms'),
            'add_new_item'       => __('Ajouter un cocktail', 'inline-editor-cms'),
            'edit_item'          => __('Modifier le cocktail', 'inline-editor-cms'),
            'new_item'           => __('Nouveau cocktail', 'inline-editor-cms'),
            'view_item'          => __('Voir le cocktail', 'inline-editor-cms'),
            'search_items'       => __('Rechercher des cocktails', 'inline-editor-cms'),
            'not_found'          => __('Aucun cocktail trouvé', 'inline-editor-cms'),
            'not_found_in_trash' => __('Aucun cocktail dans la corbeille', 'inline-editor-cms'),
        );

        $args = array(
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'rewrite'            => array('slug' => 'cocktails'),
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => 26,
            'menu_icon'          => 'dashicons-food',
            'supports'           => array('title', 'editor', 'thumbnail', 'custom-fields', 'revisions'),
            'show_in_rest'       => true,
            'rest_base'          => 'cocktails',
            'rest_controller_class' => 'WP_REST_Posts_Controller',
        );

        register_post_type('cocktail', $args);
    }

    /**
     * Ajouter les champs personnalisés à l'API REST
     */
    public function register_rest_fields()
    {
        register_rest_field('cocktail', 'cocktail_meta', array(
            'get_callback' => array($this, 'get_cocktail_meta'),
            'update_callback' => array($this, 'update_cocktail_meta'),
            'schema' => array(
                'description' => 'Métadonnées du cocktail',
                'type' => 'object',
                'properties' => array(
                    'tagline' => array('type' => 'string'),
                    'drinks' => array('type' => 'array'),
                    'ingredients' => array('type' => 'array'),
                    'preparation' => array('type' => 'string'),
                    'variants' => array('type' => 'array'),
                    'color' => array('type' => 'string')
                )
            )
        ));
    }

    /**
     * Récupérer les métadonnées d'un cocktail
     */
    public function get_cocktail_meta($post)
    {
        return array(
            'tagline' => get_post_meta($post['id'], '_tagline', true),
            'drinks' => $this->get_repeater_field($post['id'], '_drinks'),
            'ingredients' => $this->get_repeater_field($post['id'], '_ingredients'),
            'preparation' => get_post_meta($post['id'], '_preparation', true),
            'variants' => $this->get_repeater_field($post['id'], '_variants'),
            'color' => get_post_meta($post['id'], '_color', true) ?: '#C1D4D3'
        );
    }

    /**
     * Mettre à jour les métadonnées d'un cocktail
     */
    public function update_cocktail_meta($value, $post)
    {
        if (isset($value['tagline'])) {
            update_post_meta($post->ID, '_tagline', sanitize_text_field($value['tagline']));
        }
        if (isset($value['drinks'])) {
            $this->update_drinks_relation($post->ID, $value['drinks']);
        }
        if (isset($value['ingredients'])) {
            $this->update_repeater_field($post->ID, '_ingredients', $value['ingredients']);
        }
        if (isset($value['preparation'])) {
            update_post_meta($post->ID, '_preparation', wp_kses_post($value['preparation']));
        }
        if (isset($value['variants'])) {
            $this->update_repeater_field($post->ID, '_variants', $value['variants']);
        }
        if (isset($value['color'])) {
            update_post_meta($post->ID, '_color', sanitize_hex_color($value['color']));
        }

        return true;
    }

    /**
     * Ajouter les meta boxes
     */
    public function add_meta_boxes()
    {
        add_meta_box(
            'cocktail_meta',
            'Informations Cocktail',
            array($this, 'render_cocktail_meta_box'),
            'cocktail',
            'normal',
            'high'
        );
    }

    /**
     * Afficher la meta box pour les cocktails
     */
    public function render_cocktail_meta_box($post)
    {
        wp_nonce_field('cocktail_meta', 'cocktail_meta_nonce');

        $tagline = get_post_meta($post->ID, '_tagline', true);
        $drinks = $this->get_repeater_field($post->ID, '_drinks');
        $ingredients = $this->get_repeater_field($post->ID, '_ingredients');
        $preparation = get_post_meta($post->ID, '_preparation', true);
        $variants = $this->get_repeater_field($post->ID, '_variants');
        $color = get_post_meta($post->ID, '_color', true) ?: '#C1D4D3';

        ?>
        <table class="form-table">
            <tr>
                <th><label for="tagline">Tagline</label></th>
                <td><input type="text" id="tagline" name="tagline" value="<?php echo esc_attr($tagline); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label>Drinks liés (IDs)</label></th>
                <td>
                    <div id="drinks-container">
                        <?php foreach ($drinks as $i => $drink_id): ?>
                            <div class="repeater-item">
                                <input type="number" name="drinks[]" value="<?php echo esc_attr($drink_id); ?>" class="small-text" min="1" />
                                <button type="button" class="button remove-item">Supprimer</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button add-drink">Ajouter un drink</button>
                </td>
            </tr>
            <tr>
                <th><label>Ingrédients</label></th>
                <td>
                    <div id="ingredients-container">
                        <?php foreach ($ingredients as $i => $ingredient): ?>
                            <div class="repeater-item">
                                <input type="text" name="ingredients[]" value="<?php echo esc_attr($ingredient); ?>" class="large-text" />
                                <button type="button" class="button remove-item">Supprimer</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button add-ingredient">Ajouter un ingrédient</button>
                </td>
            </tr>
            <tr>
                <th><label for="preparation">Préparation</label></th>
                <td>
                    <?php 
                    wp_editor($preparation, 'preparation', array(
                        'textarea_name' => 'preparation',
                        'media_buttons' => false,
                        'textarea_rows' => 6,
                        'teeny' => true
                    )); 
                    ?>
                </td>
            </tr>
            <tr>
                <th><label>Variantes</label></th>
                <td>
                    <div id="variants-container">
                        <?php foreach ($variants as $i => $variant): ?>
                            <div class="repeater-item">
                                <input type="text" name="variants[]" value="<?php echo esc_attr($variant); ?>" class="large-text" />
                                <button type="button" class="button remove-item">Supprimer</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button add-variant">Ajouter une variante</button>
                    <p class="description">Ex: London Mule = Gin, Moscow Mule = Vodka</p>
                </td>
            </tr>
            <tr>
                <th><label for="color">Couleur</label></th>
                <td><input type="color" id="color" name="color" value="<?php echo esc_attr($color); ?>" /></td>
            </tr>
        </table>

        <script>
            jQuery(document).ready(function($) {
                $('.add-drink').click(function() {
                    $('#drinks-container').append('<div class="repeater-item"><input type="number" name="drinks[]" value="" class="small-text" min="1" /><button type="button" class="button remove-item">Supprimer</button></div>');
                });

                $('.add-ingredient').click(function() {
                    $('#ingredients-container').append('<div class="repeater-item"><input type="text" name="ingredients[]" value="" class="large-text" /><button type="button" class="button remove-item">Supprimer</button></div>');
                });

                $('.add-variant').click(function() {
                    $('#variants-container').append('<div class="repeater-item"><input type="text" name="variants[]" value="" class="large-text" /><button type="button" class="button remove-item">Supprimer</button></div>');
                });

                $(document).on('click', '.remove-item', function() {
                    $(this).parent().remove();
                });
            });
        </script>

        <style>
            .repeater-item {
                margin-bottom: 10px;
            }

            .repeater-item input {
                margin-right: 10px;
            }
        </style>
        <?php
    }

    /**
     * Sauvegarder les meta boxes
     */
    public function save_meta_boxes($post_id)
    {
        if (get_post_type($post_id) === 'cocktail') {
            if (!isset($_POST['cocktail_meta_nonce']) || !wp_verify_nonce($_POST['cocktail_meta_nonce'], 'cocktail_meta')) {
                return;
            }
            $this->save_cocktail_meta($post_id);
        }
    }

    /**
     * Sauvegarder les métadonnées d'un cocktail
     */
    private function save_cocktail_meta($post_id)
    {
        if (isset($_POST['tagline'])) {
            update_post_meta($post_id, '_tagline', sanitize_text_field($_POST['tagline']));
        }

        if (isset($_POST['drinks'])) {
            $this->update_drinks_relation($post_id, $_POST['drinks']);
        }

        if (isset($_POST['ingredients'])) {
            $this->update_repeater_field($post_id, '_ingredients', $_POST['ingredients']);
        }

        if (isset($_POST['preparation'])) {
            update_post_meta($post_id, '_preparation', wp_kses_post($_POST['preparation']));
        }

        if (isset($_POST['variants'])) {
            $this->update_repeater_field($post_id, '_variants', $_POST['variants']);
        }

        if (isset($_POST['color'])) {
            update_post_meta($post_id, '_color', sanitize_hex_color($_POST['color']));
        }
    }

    /**
     * Récupérer un champ répéteur
     */
    private function get_repeater_field($post_id, $field_name)
    {
        $values = get_post_meta($post_id, $field_name, true);
        return is_array($values) ? $values : array();
    }

    /**
     * Mettre à jour un champ répéteur
     */
    private function update_repeater_field($post_id, $field_name, $values)
    {
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
     * Mettre à jour les relations avec les drinks
     */
    private function update_drinks_relation($cocktail_id, $drink_ids)
    {
        $clean_ids = array();
        if (is_array($drink_ids)) {
            foreach ($drink_ids as $id) {
                $clean_id = absint($id);
                if ($clean_id > 0) {
                    $clean_ids[] = $clean_id;
                }
            }
        }
        update_post_meta($cocktail_id, '_drinks', $clean_ids);
    }
}

new Cocktails();