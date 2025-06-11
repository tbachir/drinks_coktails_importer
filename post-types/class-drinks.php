<?php

/**
 * Post Type pour Drinks - Version fusionnée & statique
 * 
 * @package Inline_Editor_CMS
 * @subpackage PostTypes
 */

if (!defined('ABSPATH')) exit;

class Drinks
{
    public function __construct()
    {
        add_action('init', array($this, 'register_post_types'));
        add_action('rest_api_init', array($this, 'register_rest_fields'));
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_meta_boxes'));
    }

    /**
     * Enregistrer le post type
     */
    public function register_post_types()
    {
        self::register_drink_post_type();
    }

    /**
     * Post type pour les drinks/boissons
     */
    private static function register_drink_post_type()
    {
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
    public function register_rest_fields()
    {
        register_rest_field('drink', 'drink_meta', array(
            'get_callback' => array($this, 'get_drink_meta'),
            'update_callback' => array(__CLASS__, 'update_drink_meta'),
            'schema' => array(
                'description' => 'Métadonnées du drink',
                'type' => 'object',
                'properties' => array(
                    'tagline' => array('type' => 'string'),
                    'description_short' => array('type' => 'string'),
                    'type' => array('type' => 'string'),
                    'volume_ml' => array('type' => 'integer'),
                    'tasting_notes' => array('type' => 'array'),
                    'characteristics' => array('type' => 'array'),
                    'note_speciale' => array('type' => 'string'),
                    'color' => array('type' => 'string'),
                    'cutout_image' => array('type' => 'string'),
                    'featured_cocktail_id' => array('type' => 'integer'),
                    'cocktails' => array('type' => 'array'),
                    '_temp_featured_cocktail_slug' => array('type' => 'string'),
                    '_temp_cocktail_slugs' => array('type' => 'array'),
                )
            )
        ));
    }

    /**
     * Récupérer les métadonnées d'un drink
     */
    public static function get_drink_meta($post)
    {
        return array(
            'tagline' => get_post_meta($post['id'], '_tagline', true),
            'description_short' => get_post_meta($post['id'], '_description_short', true),
            'type' => get_post_meta($post['id'], '_type', true),
            'volume_ml' => get_post_meta($post['id'], '_volume_ml', true),
            'tasting_notes' => self::get_repeater_field($post['id'], '_tasting_notes'),
            'characteristics' => self::get_repeater_field($post['id'], '_characteristics'),
            'note_speciale' => get_post_meta($post['id'], '_note_speciale', true),
            'color' => get_post_meta($post['id'], '_color', true) ?: '#ddd49a',
            'image' => DCI_API::get_image_url($post['id'], '_image_id', '_image'),
            'cutout_image' => DCI_API::get_image_url($post['id'], '_cutout_image_id', '_cutout_image'),
            'featured_cocktail_id' => get_post_meta($post['id'], '_featured_cocktail_id', true),
            'cocktails' => self::get_repeater_field($post['id'], '_cocktails')
        );
    }

    /**
     * Callback REST pour mettre à jour les métadonnées d'un drink
     */
    public static function update_drink_meta($value, $post)
    {
        self::persist_drink_meta($post->ID, $value);
        return true;
    }

    /**
     * Persistance unique des metas (fusion admin & REST) - STATIC
     */
    public static function persist_drink_meta($post_id, $data)
    {
        if (isset($data['tagline'])) {
            update_post_meta($post_id, '_tagline', sanitize_text_field($data['tagline']));
        }
        if (isset($data['description_short'])) {
            update_post_meta($post_id, '_description_short', wp_kses_post($data['description_short']));
        }
        if (isset($data['type'])) {
            update_post_meta($post_id, '_type', sanitize_text_field($data['type']));
        }
        if (isset($data['volume_ml'])) {
            update_post_meta($post_id, '_volume_ml', absint($data['volume_ml']));
        }
        if (isset($data['tasting_notes'])) {
            self::update_repeater_field($post_id, '_tasting_notes', $data['tasting_notes']);
        }
        if (isset($data['characteristics'])) {
            self::update_repeater_field($post_id, '_characteristics', $data['characteristics']);
        }
        if (isset($data['note_speciale'])) {
            update_post_meta($post_id, '_note_speciale', wp_kses_post($data['note_speciale']));
        }
        if (isset($data['color'])) {
            update_post_meta($post_id, '_color', sanitize_hex_color($data['color']));
        }
        if (isset($data['cutout_image'])) {
            update_post_meta($post_id, '_cutout_image', esc_url_raw($data['cutout_image']));
        }
        if (isset($data['featured_cocktail_id'])) {
            update_post_meta($post_id, '_featured_cocktail_id', absint($data['featured_cocktail_id']));
        }
        if (isset($data['_temp_featured_cocktail_slug'])) {
            update_post_meta($post_id, '_temp_featured_cocktail_slug', sanitize_text_field($data['_temp_featured_cocktail_slug']));
        }
        if (isset($data['cocktails']) && is_array($data['cocktails'])) {
            self::update_cocktails_relation($post_id, $data['cocktails']);
        }
        if (isset($data['_temp_cocktail_slugs']) && is_array($data['_temp_cocktail_slugs'])) {
            update_post_meta($post_id, '_temp_cocktail_slugs', $data['_temp_cocktail_slugs']);
        }
    }

    /**
     * Sauvegarder les meta boxes (admin)
     */
    public function save_meta_boxes($post_id)
    {
        if (get_post_type($post_id) === 'drink') {
            if (!isset($_POST['drink_meta_nonce']) || !wp_verify_nonce($_POST['drink_meta_nonce'], 'drink_meta')) {
                return;
            }
            $data = array(
                'tagline'     => $_POST['tagline'] ?? null,
                'description_short' => $_POST['description_short'] ?? null,
                'type'        => $_POST['type'] ?? null,
                'volume_ml'   => $_POST['volume_ml'] ?? null,
                'tasting_notes' => $_POST['tasting_notes'] ?? null,
                'characteristics' => $_POST['characteristics'] ?? null,
                'note_speciale' => $_POST['note_speciale'] ?? null,
                'color'       => $_POST['color'] ?? null,
                'cutout_image' => $_POST['cutout_image'] ?? null,
                'featured_cocktail_id' => $_POST['featured_cocktail_id'] ?? null,
                '_temp_featured_cocktail_slug' => $_POST['_temp_featured_cocktail_slug'] ?? null,
                'cocktails' => $_POST['cocktails'] ?? null,
                '_temp_cocktail_slugs' => $_POST['_temp_cocktail_slugs'] ?? null,
            );
            self::persist_drink_meta($post_id, $data);
        }
    }

    /**
     * Ajouter les meta boxes
     */
    public function add_meta_boxes()
    {
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
    public function render_drink_meta_box($post)
    {
        wp_nonce_field('drink_meta', 'drink_meta_nonce');

        $tagline = get_post_meta($post->ID, '_tagline', true);
        $description_short = get_post_meta($post->ID, '_description_short', true);
        $type = get_post_meta($post->ID, '_type', true);
        $volume_ml = get_post_meta($post->ID, '_volume_ml', true);
        $tasting_notes = self::get_repeater_field($post->ID, '_tasting_notes');
        $characteristics = self::get_repeater_field($post->ID, '_characteristics');
        $note_speciale = get_post_meta($post->ID, '_note_speciale', true);
        $color = get_post_meta($post->ID, '_color', true) ?: '#ddd49a';
        $cutout_image = get_post_meta($post->ID, '_cutout_image', true);
        $featured_cocktail_id = get_post_meta($post->ID, '_featured_cocktail_id', true);
        $cocktails = self::get_repeater_field($post->ID, '_cocktails');
        $_temp_featured_cocktail_slug = get_post_meta($post->ID, '_temp_featured_cocktail_slug', true);
        $_temp_cocktail_slugs = self::get_repeater_field($post->ID, '_temp_cocktail_slugs');

?>
        <table class="form-table">
            <tr>
                <th><label for="tagline">Tagline</label></th>
                <td><input type="text" id="tagline" name="tagline" value="<?php echo esc_attr($tagline); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="description_short">Description complète</label></th>
                <td>
                    <?php
                    wp_editor($description_short, 'description_short', array(
                        'textarea_name' => 'description_short',
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
                <th><label for="cutout_image">Image détourée (URL ou ID)</label></th>
                <td><input type="text" id="cutout_image" name="cutout_image" value="<?php echo esc_attr($cutout_image); ?>" class="regular-text" /></td>
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
                <th><label for="_temp_featured_cocktail_slug">Slug cocktail vedette (temp)</label></th>
                <td><input type="text" id="_temp_featured_cocktail_slug" name="_temp_featured_cocktail_slug" value="<?php echo esc_attr($_temp_featured_cocktail_slug); ?>" class="regular-text" /></td>
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
            <tr>
                <th><label>Slugs cocktails liés (temp)</label></th>
                <td>
                    <div id="temp-cocktail-slugs-container">
                        <?php foreach ($_temp_cocktail_slugs as $i => $slug): ?>
                            <div class="repeater-item">
                                <input type="text" name="_temp_cocktail_slugs[]" value="<?php echo esc_attr($slug); ?>" class="regular-text" />
                                <button type="button" class="button remove-item">Supprimer</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button add-temp-cocktail-slug">Ajouter un slug</button>
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
                $('.add-temp-cocktail-slug').click(function() {
                    $('#temp-cocktail-slugs-container').append('<div class="repeater-item"><input type="text" name="_temp_cocktail_slugs[]" value="" class="regular-text" /><button type="button" class="button remove-item">Supprimer</button></div>');
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
     * Récupérer un champ répéteur - STATIC
     */
    private static function get_repeater_field($post_id, $field_name)
    {
        $values = get_post_meta($post_id, $field_name, true);
        return is_array($values) ? $values : array();
    }

    /**
     * Mettre à jour un champ répéteur - STATIC
     */
    private static function update_repeater_field($post_id, $field_name, $values)
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
     * Mettre à jour les relations avec les cocktails - STATIC
     */
    private static function update_cocktails_relation($drink_id, $cocktail_ids)
    {
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
