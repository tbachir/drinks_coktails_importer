<?php

/**
 * Post Type pour Cocktails - Version corrigée avec clés unifiées
 * 
 * @package Inline_Editor_CMS
 * @subpackage PostTypes
 */

if (!defined('ABSPATH')) exit;

// S'assurer que les constantes sont disponibles
if (!class_exists('DCI_Meta_Keys')) {
    require_once DCI_PLUGIN_DIR . 'includes/class-dci-importer.php';
}

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
            'update_callback' => array(__CLASS__, 'update_cocktail_meta'),
            'schema' => array(
                'description' => 'Métadonnées du cocktail',
                'type' => 'object',
                'properties' => array(
                    'tagline' => array('type' => 'string'),
                    'drinks' => array('type' => 'array'),
                    'ingredients' => array('type' => 'array'),
                    'preparation' => array('type' => 'string'),
                    'variants' => array('type' => 'array'),
                    'color' => array('type' => 'string'),
                    'image' => array('type' => 'string'),
                )
            )
        ));
    }

    /**
     * Récupérer les métadonnées d'un cocktail - VERSION CORRIGÉE
     */
    public static function get_cocktail_meta($post)
    {
        // Utiliser la méthode de DCI_API pour obtenir l'URL de l'image
        $image_url = null;
        if (class_exists('DCI_API')) {
            $image_url = DCI_API::get_image_url($post['id'], DCI_Meta_Keys::COCKTAIL_IMAGE, DCI_Meta_Keys::COCKTAIL_IMAGE_URL);
        }
        
        return array(
            'tagline'     => get_post_meta($post['id'], '_tagline', true),
            'drinks'      => self::get_repeater_field($post['id'], '_drinks'),
            'ingredients' => self::get_repeater_field($post['id'], '_ingredients'),
            'preparation' => get_post_meta($post['id'], '_preparation', true),
            'variants'    => self::get_repeater_field($post['id'], '_variants'),
            'image'       => $image_url,
            'color'       => get_post_meta($post['id'], '_color', true) ?: '#C1D4D3',
        );
    }

    /**
     * Callback REST pour mettre à jour les métadonnées d'un cocktail
     */
    public static function update_cocktail_meta($value, $post)
    {
        self::persist_cocktail_meta($post->ID, $value);
        return true;
    }

    /**
     * Persistance unique des metas - VERSION CORRIGÉE
     */
    public static function persist_cocktail_meta($post_id, $data)
    {
        if (isset($data['tagline'])) {
            update_post_meta($post_id, '_tagline', sanitize_text_field($data['tagline']));
        }
        if (isset($data['drinks'])) {
            self::update_drinks_relation($post_id, $data['drinks']);
        }
        if (isset($data['ingredients'])) {
            self::update_repeater_field($post_id, '_ingredients', $data['ingredients']);
        }
        if (isset($data['preparation'])) {
            update_post_meta($post_id, '_preparation', wp_kses_post($data['preparation']));
        }
        if (isset($data['variants'])) {
            self::update_repeater_field($post_id, '_variants', $data['variants']);
        }
        if (isset($data['color'])) {
            update_post_meta($post_id, '_color', sanitize_hex_color($data['color']));
        }
        
        // Gestion de l'image - Sauvegarder temporairement l'URL si présente
        if (isset($data['image']) && filter_var($data['image'], FILTER_VALIDATE_URL)) {
            update_post_meta($post_id, DCI_Meta_Keys::COCKTAIL_IMAGE_URL, esc_url_raw($data['image']));
        }
        
        if (isset($data['_temp_drink_slugs']) && is_array($data['_temp_drink_slugs'])) {
            update_post_meta($post_id, '_temp_drink_slugs', $data['_temp_drink_slugs']);
        }
    }

    /**
     * Sauvegarder les meta boxes (admin)
     */
    public function save_meta_boxes($post_id)
    {
        if (get_post_type($post_id) === 'cocktail') {
            if (!isset($_POST['cocktail_meta_nonce']) || !wp_verify_nonce($_POST['cocktail_meta_nonce'], 'cocktail_meta')) {
                return;
            }
            $data = array(
                'tagline'     => $_POST['tagline'] ?? null,
                'drinks'      => $_POST['drinks'] ?? null,
                'ingredients' => $_POST['ingredients'] ?? null,
                'preparation' => $_POST['preparation'] ?? null,
                'variants'    => $_POST['variants'] ?? null,
                'color'       => $_POST['color'] ?? null,
                '_temp_drink_slugs' => isset($_POST['_temp_drink_slugs']) ? $_POST['_temp_drink_slugs'] : null,
            );
            self::persist_cocktail_meta($post_id, $data);
        }
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
        
        // Ajouter une meta box pour l'image
        add_meta_box(
            'cocktail_image_meta',
            'Image du Cocktail',
            array($this, 'render_cocktail_image_meta_box'),
            'cocktail',
            'side',
            'default'
        );
    }

    /**
     * Afficher la meta box pour l'image du cocktail
     */
    public function render_cocktail_image_meta_box($post)
    {
        $image_id = get_post_meta($post->ID, DCI_Meta_Keys::COCKTAIL_IMAGE, true);
        $image_url = get_post_meta($post->ID, DCI_Meta_Keys::COCKTAIL_IMAGE_URL, true);
        ?>
        <div class="cocktail-image-meta-box">
            <?php if ($image_id && wp_attachment_is_image($image_id)) : ?>
                <img src="<?php echo wp_get_attachment_url($image_id); ?>" style="max-width:100%; height:auto;">
                <p class="description"><?php _e('Image définie via l\'image à la une', 'inline-editor-cms'); ?></p>
            <?php elseif ($image_url) : ?>
                <div class="notice notice-warning inline">
                    <p><?php _e('Image non téléchargée. URL source :', 'inline-editor-cms'); ?></p>
                    <p><code><?php echo esc_url($image_url); ?></code></p>
                    <button class="button download-cocktail-image" 
                            data-url="<?php echo esc_attr($image_url); ?>"
                            data-post-id="<?php echo esc_attr($post->ID); ?>">
                        <?php _e('Télécharger maintenant', 'inline-editor-cms'); ?>
                    </button>
                </div>
            <?php else : ?>
                <p class="description"><?php _e('Utilisez l\'image à la une pour définir l\'image du cocktail', 'inline-editor-cms'); ?></p>
            <?php endif; ?>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('.download-cocktail-image').on('click', function(e) {
                e.preventDefault();
                var button = $(this);
                var originalText = button.text();
                
                button.prop('disabled', true).text('Téléchargement...');
                
                jQuery.post(ajaxurl, {
                    action: 'dci_download_single_image',
                    nonce: '<?php echo wp_create_nonce('dci_download_image'); ?>',
                    image_url: button.data('url'),
                    post_id: button.data('post-id'),
                    meta_key: '<?php echo DCI_Meta_Keys::COCKTAIL_IMAGE; ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Erreur: ' + response.data.message);
                        button.prop('disabled', false).text(originalText);
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Afficher la meta box pour les cocktails
     */
    public function render_cocktail_meta_box($post)
    {
        wp_nonce_field('cocktail_meta', 'cocktail_meta_nonce');

        $tagline     = get_post_meta($post->ID, '_tagline', true);
        $drinks      = self::get_repeater_field($post->ID, '_drinks');
        $ingredients = self::get_repeater_field($post->ID, '_ingredients');
        $preparation = get_post_meta($post->ID, '_preparation', true);
        $variants    = self::get_repeater_field($post->ID, '_variants');
        $color       = get_post_meta($post->ID, '_color', true) ?: '#C1D4D3';
        $temp_drink_slugs = self::get_repeater_field($post->ID, '_temp_drink_slugs');

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
                                <?php 
                                $drink_title = get_the_title($drink_id);
                                if ($drink_title) {
                                    echo '<span class="description">' . esc_html($drink_title) . '</span>';
                                }
                                ?>
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
            <?php if (!empty($temp_drink_slugs)) : ?>
            <tr>
                <th><label for="_temp_drink_slugs">Slugs Drinks liés (temp)</label></th>
                <td>
                    <div id="temp-drink-slugs-container">
                        <?php foreach ($temp_drink_slugs as $i => $slug): ?>
                            <div class="repeater-item">
                                <input type="text" name="_temp_drink_slugs[]" value="<?php echo esc_attr($slug); ?>" class="regular-text" />
                                <button type="button" class="button remove-item">Supprimer</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="description notice notice-warning inline">Ces slugs temporaires doivent être résolus via l'import.</p>
                </td>
            </tr>
            <?php endif; ?>
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
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .repeater-item input {
                margin-right: 10px;
            }
            
            .repeater-item .description {
                color: #666;
                font-style: italic;
            }
            
            .cocktail-image-meta-box img {
                border-radius: 4px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
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
     * Mettre à jour les relations avec les drinks - STATIC
     */
    private static function update_drinks_relation($cocktail_id, $drink_ids)
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