<?php

if (!defined('ABSPATH')) exit;

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
            __('Informations Drink', 'text-domain'),
            [$this, 'render'],
            'drink',
            'normal',
            'high'
        );
    }

    public function render($post)
    {
        wp_nonce_field('drink_meta_box_nonce', 'drink_meta_box_nonce_field');

        $drink_image = get_post_meta($post->ID, 'drink_image', true);
        $drink_cutout_image = get_post_meta($post->ID, 'drink_cutout_image', true);
        ?>

        <div class="drink-meta-box">
            <label for="drink_image">Image :</label>
            <input type="hidden" id="drink_image" name="drink_image" value="<?php echo esc_attr($drink_image); ?>">
            <button class="button upload-image-button" data-target="#drink_image">Sélectionner une image</button>
            <div class="image-preview" id="drink_image_preview">
                <?php if ($drink_image) : ?>
                    <img src="<?php echo wp_get_attachment_url($drink_image); ?>" style="max-width:100%; height:auto;">
                <?php endif; ?>
            </div>

            <label for="drink_cutout_image">Cutout Image :</label>
            <input type="hidden" id="drink_cutout_image" name="drink_cutout_image" value="<?php echo esc_attr($drink_cutout_image); ?>">
            <button class="button upload-image-button" data-target="#drink_cutout_image">Sélectionner une Cutout Image</button>
            <div class="image-preview" id="drink_cutout_image_preview">
                <?php if ($drink_cutout_image) : ?>
                    <img src="<?php echo wp_get_attachment_url($drink_cutout_image); ?>" style="max-width:100%; height:auto;">
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function save_meta_box_data($post_id)
    {
        if (!isset($_POST['drink_meta_box_nonce_field']) || !wp_verify_nonce($_POST['drink_meta_box_nonce_field'], 'drink_meta_box_nonce')) return;

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

        if ('drink' !== $_POST['post_type']) return;

        if (isset($_POST['drink_image'])) {
            update_post_meta($post_id, 'drink_image', intval($_POST['drink_image']));
        }

        if (isset($_POST['drink_cutout_image'])) {
            update_post_meta($post_id, 'drink_cutout_image', intval($_POST['drink_cutout_image']));
        }
    }

    public function enqueue_admin_assets($hook)
    {
        global $post;
        if ($hook === 'post-new.php' || $hook === 'post.php') {
            if ('drink' === $post->post_type) {
                wp_enqueue_media();
                wp_enqueue_script(
                    'drink-meta-box-script',
                    plugins_url('/assets/js/drink-meta-box.js', __FILE__),
                    ['jquery'],
                    '1.0',
                    true
                );

                wp_enqueue_style(
                    'drink-meta-box-style',
                    plugins_url('/assets/css/drink-meta-box.css', __FILE__),
                    [],
                    '1.0'
                );
            }
        }
    }
}
