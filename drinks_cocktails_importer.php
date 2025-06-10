<?php

/**
 * Plugin Name: Drinks & Cocktails - Import & CPT
 * Description: Crée les CPT Drink/Cocktail, leurs metas, permet l’import JSON (relations par slugs), téléchargement différé des images, log des erreurs, et expose tout dans l’API REST.
 * Version: 2.0
 * Author: OpenAI / Tarek Bachir
 */

if (! defined('ABSPATH')) exit;

class DCI_Plugin
{

    public function __construct()
    {
        add_action('init', [$this, 'register_post_types']);
        add_action('init', [$this, 'register_meta']);
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_post_dci_import_json', [$this, 'handle_import']);
        add_action('add_meta_boxes', [$this, 'add_image_downloader_metabox']);
        add_action('save_post', [$this, 'handle_image_download_on_save']);
        add_action('rest_api_init', function () {
            register_rest_field('drink', 'image_square_url', [
                'get_callback' => function ($object) {
                    $id = get_post_meta($object['id'], 'image_square_id', true);
                    return $id ? wp_get_attachment_url($id) : null;
                },
                'schema' => [
                    'description' => 'URL de l’image carrée',
                    'type'        => 'string',
                    'context'     => ['view', 'edit'],
                ],
            ]);
        });
        add_action('admin_post_dci_download_images_now', [$this, 'handle_direct_image_download']);
    }

    /**
     * Téléchargement direct des images pour un post donné
     * Utilisé pour le téléchargement différé des images depuis la méta box
     */
    public function handle_direct_image_download()
    {
        if (!current_user_can('edit_posts')) {
            wp_die('Non autorisé.');
        }
        $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;
        if (!$post_id || !wp_verify_nonce($_GET['_wpnonce'], 'dci_download_images_now_' . $post_id)) {
            wp_die('Requête non valide.');
        }

        $errors = [];
        $image_url = get_post_meta($post_id, 'image', true);
        if ($image_url && !has_post_thumbnail($post_id)) {
            $this->set_featured_image_from_url($image_url, $post_id);
            if (!has_post_thumbnail($post_id)) $errors[] = "Image principale : échec du téléchargement.";
        }
        $post_type = get_post_type($post_id);
        if ($post_type === 'drink') {
            $image_square_url = get_post_meta($post_id, 'image_square', true);
            $square_id = get_post_meta($post_id, 'image_square_id', true);
            if ($image_square_url && !$square_id) {
                $image_square_id = $this->sideload_media_and_attach($image_square_url, $post_id, 'image_square');
                if ($image_square_id) {
                    update_post_meta($post_id, 'image_square_id', $image_square_id);
                } else {
                    $errors[] = "Image carrée : échec du téléchargement.";
                }
            }
        }
        $redir = get_edit_post_link($post_id, 'redirect') . '&dci_img=' . (empty($errors) ? 'ok' : 'fail');
        wp_redirect($redir);
        exit;
    }


    /** Log des erreurs dans wp-content/uploads/dci_import_log.txt */
    private function log_error($message)
    {
        $upload_dir = wp_upload_dir();
        $log_file = trailingslashit($upload_dir['basedir']) . 'dci_import_log.txt';
        $date = date('Y-m-d H:i:s');
        $msg = "[$date] $message\n";
        @file_put_contents($log_file, $msg, FILE_APPEND | LOCK_EX);
    }

    public function register_post_types()
    {
        register_post_type('drink', [
            'label' => 'Drinks',
            'public' => true,
            'show_in_rest' => true,
            'show_in_menu' => true,
            'supports' => ['title', 'editor', 'thumbnail', 'custom-fields'],
            'has_archive' => true,
            'rewrite' => ['slug' => 'drinks'],
        ]);
        register_post_type('cocktail', [
            'label' => 'Cocktails',
            'public' => true,
            'show_in_rest' => true,
            'show_in_menu' => true,
            'supports' => ['title', 'editor', 'thumbnail', 'custom-fields'],
            'has_archive' => true,
            'rewrite' => ['slug' => 'cocktails'],
        ]);
    }

    public function register_meta()
    {
        $drink_meta = [
            'type'                => 'string',
            'volume_ml'           => 'number',
            'tagline'             => 'string',
            'description_complete' => 'string',
            'tasting_notes'       => 'array',
            'characteristics'     => 'array',
            'note_speciale'       => 'string',
            'color'               => 'string',
            'image'               => 'string',
            'image_square'        => 'string',
            'image_square_id'     => 'number',
            'featured_cocktail_id' => 'number',
            'featured_cocktail_slug' => 'string',
            'cocktails'           => 'array',
            'cocktail_slugs'      => 'array',
        ];
        $cocktail_meta = [
            'tagline'      => 'string',
            'color'        => 'string',
            'ingredients'  => 'array',
            'preparation'  => 'string',
            'image'        => 'string',
            'drinks'       => 'array',
            'drink_slugs'  => 'array',
            'variants'     => 'array',
        ];
        foreach ($drink_meta as $key => $type) {
            register_post_meta('drink', $key, [
                'show_in_rest' => [
                    'schema' => ['type' => $type]
                ],
                'single' => true,
                'type' => $type === 'array' ? 'string' : $type
            ]);
        }
        foreach ($cocktail_meta as $key => $type) {
            register_post_meta('cocktail', $key, [
                'show_in_rest' => [
                    'schema' => ['type' => $type]
                ],
                'single' => true,
                'type' => $type === 'array' ? 'string' : $type
            ]);
        }
    }

    public function register_admin_page()
    {
        add_management_page(
            'Import Drinks & Cocktails',
            'Import Drinks & Cocktails',
            'manage_options',
            'dci-importer',
            [$this, 'import_page_html']
        );
    }

    public function import_page_html()
    {
?>
        <div class="wrap">
            <h1>Importer Drinks & Cocktails (JSON)</h1>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('dci_import_json'); ?>
                <input type="hidden" name="action" value="dci_import_json" />
                <p>
                    <label for="drinks_json">Fichier drinks.json :</label>
                    <input type="file" name="drinks_json" id="drinks_json" accept=".json" required />
                </p>
                <p>
                    <label for="cocktails_json">Fichier cocktails.json :</label>
                    <input type="file" name="cocktails_json" id="cocktails_json" accept=".json" required />
                </p>
                <p><button type="submit" class="button button-primary">Importer</button></p>
            </form>
            <?php if (isset($_GET['imported'])): ?>
                <div class="notice notice-success">
                    <p>Import réussi.</p>
                </div>
            <?php elseif (isset($_GET['error'])): ?>
                <div class="notice notice-error">
                    <p>Erreur à l'import : consulte le log pour plus de détails.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_import()
    {
        if (! current_user_can('manage_options')) wp_die('Non autorisé.');
        check_admin_referer('dci_import_json');

        $uploads = [];
        foreach (['drinks_json', 'cocktails_json'] as $input) {
            if (! isset($_FILES[$input]) || $_FILES[$input]['error'] !== UPLOAD_ERR_OK) {
                $this->log_error("Fichier manquant ou corrompu : $input");
                wp_redirect(admin_url('tools.php?page=dci-importer&error=1'));
                exit;
            }
            $content = file_get_contents($_FILES[$input]['tmp_name']);
            $data = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->log_error("Erreur JSON dans $input : " . json_last_error_msg());
                wp_redirect(admin_url('tools.php?page=dci-importer&error=2'));
                exit;
            }
            $uploads[$input] = $data;
        }

        $drinks = $uploads['drinks_json']['drinks'] ?? [];
        $cocktails = $uploads['cocktails_json']['cocktails'] ?? [];

        // 1. Import posts et mapping slug=>ID
        $drink_ids = [];
        foreach ($drinks as $drink) {
            $post_id = $this->insert_post('drink', $drink['name'], $drink['slug'], $drink['description'], $drink, true);
            $drink_ids[$drink['slug']] = $post_id;
        }
        $cocktail_ids = [];
        foreach ($cocktails as $cocktail) {
            $post_id = $this->insert_post('cocktail', $cocktail['name'], $cocktail['slug'], $cocktail['description'], $cocktail, false);
            $cocktail_ids[$cocktail['slug']] = $post_id;
        }

        // 2. Mapping slug => ID pour les relations
        $drink_slugs = $drink_ids;
        $cocktail_slugs = $cocktail_ids;

        // 3. Stockage des relations via slugs (et ID pour WP)
        foreach ($drinks as $drink) {
            $post_id = $drink_ids[$drink['slug']];

            // featured_cocktail_slug
            if (! empty($drink['featured_cocktail_slug']) && isset($cocktail_slugs[$drink['featured_cocktail_slug']])) {
                update_post_meta($post_id, 'featured_cocktail_id', $cocktail_slugs[$drink['featured_cocktail_slug']]);
                update_post_meta($post_id, 'featured_cocktail_slug', $drink['featured_cocktail_slug']);
            }

            // cocktails: tableau de slugs
            if (! empty($drink['cocktails']) && is_array($drink['cocktails'])) {
                $cocktail_posts = [];
                foreach ($drink['cocktails'] as $c_slug) {
                    if (isset($cocktail_slugs[$c_slug])) $cocktail_posts[] = $cocktail_slugs[$c_slug];
                }
                update_post_meta($post_id, 'cocktails', $cocktail_posts);
                update_post_meta($post_id, 'cocktail_slugs', $drink['cocktails']);
            }
        }
        foreach ($cocktails as $cocktail) {
            $post_id = $cocktail_ids[$cocktail['slug']];
            if (! empty($cocktail['drinks']) && is_array($cocktail['drinks'])) {
                $drink_posts = [];
                foreach ($cocktail['drinks'] as $d_slug) {
                    if (isset($drink_slugs[$d_slug])) $drink_posts[] = $drink_slugs[$d_slug];
                }
                update_post_meta($post_id, 'drinks', $drink_posts);
                update_post_meta($post_id, 'drink_slugs', $cocktail['drinks']);
            }
        }
        wp_redirect(admin_url('tools.php?page=dci-importer&imported=1'));
        exit;
    }

    private function insert_post($type, $title, $slug, $description, $data, $is_drink = false)
    {
        $exists = get_page_by_path($slug, OBJECT, $type);
        if ($exists) {
            $post_id = $exists->ID;
            wp_update_post([
                'ID'           => $post_id,
                'post_title'   => $title,
                'post_content' => $description,
            ]);
        } else {
            $post_id = wp_insert_post([
                'post_type'    => $type,
                'post_title'   => $title,
                'post_name'    => $slug,
                'post_status'  => 'publish',
                'post_content' => $description,
            ]);
        }

        $exclude = ['name', 'slug', 'description'];
        foreach ($data as $key => $value) {
            if (in_array($key, $exclude)) continue;
            if (is_array($value)) {
                update_post_meta($post_id, $key, wp_json_encode($value));
            } else {
                update_post_meta($post_id, $key, $value);
            }
        }

        // (AUCUN téléchargement d'image à l'import : seules les URLs sont stockées.)

        return $post_id;
    }

    // Méta box pour télécharger les images à la demande sur chaque post
    public function add_image_downloader_metabox() {
    foreach (['drink', 'cocktail'] as $type) {
        add_meta_box('dci_image_downloader', 'Télécharger les images', function($post) use ($type) {
            $image_url = get_post_meta($post->ID, 'image', true);
            $image_square_url = ($type === 'drink') ? get_post_meta($post->ID, 'image_square', true) : null;
            $has_thumb = has_post_thumbnail($post->ID);
            $square_id = get_post_meta($post->ID, 'image_square_id', true);
            // Feedback post-action
            if (isset($_GET['dci_img']) && $_GET['dci_img'] === 'ok') {
                echo '<div class="notice notice-success"><p>Images importées !</p></div>';
            } elseif (isset($_GET['dci_img']) && $_GET['dci_img'] === 'fail') {
                echo '<div class="notice notice-error"><p>Erreur lors du téléchargement d\'au moins une image. Consulte le log.</p></div>';
            }
            ?>
            <p>
                <strong>Image principale :</strong><br>
                <?php echo $image_url ? esc_html($image_url) : '<em>aucune</em>'; ?><br>
                <?php if ($has_thumb): ?>
                    <span style="color:green">✔ Image à la une déjà présente</span>
                <?php endif; ?>
            </p>
            <?php if ($type === 'drink'): ?>
            <p>
                <strong>Image carrée :</strong><br>
                <?php echo $image_square_url ? esc_html($image_square_url) : '<em>aucune</em>'; ?><br>
                <?php if ($square_id): ?>
                    <span style="color:green">✔ Image carrée déjà téléchargée</span>
                <?php endif; ?>
            </p>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('dci_download_images_now_' . $post->ID); ?>
                <input type="hidden" name="action" value="dci_download_images_now" />
                <input type="hidden" name="post_id" value="<?php echo esc_attr($post->ID); ?>" />
                <input type="submit" class="button" value="Télécharger les images maintenant" <?php if ($has_thumb && ($type !== 'drink' || $square_id)) echo 'disabled style="opacity:0.5;"'; ?> />
            </form>
            <?php
        }, $type);
    }
}


    // Lors du submit de la méta box, télécharger les images si demandé
    public function handle_image_download_on_save($post_id)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['dci_download_images'])) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (!wp_verify_nonce($_POST['_wpnonce'], 'dci_download_image_' . $post_id)) return;

        $image_url = get_post_meta($post_id, 'image', true);
        if ($image_url && !has_post_thumbnail($post_id)) {
            $this->set_featured_image_from_url($image_url, $post_id);
        }

        $post_type = get_post_type($post_id);
        if ($post_type === 'drink') {
            $image_square_url = get_post_meta($post_id, 'image_square', true);
            $square_id = get_post_meta($post_id, 'image_square_id', true);
            if ($image_square_url && !$square_id) {
                $image_square_id = $this->sideload_media_and_attach($image_square_url, $post_id, 'image_square');
                if ($image_square_id) {
                    update_post_meta($post_id, 'image_square_id', $image_square_id);
                }
            }
        }
    }

    // Télécharge une image et l'affecte comme featured image si succès
    private function set_featured_image_from_url($image_url, $post_id)
    {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $existing_thumb = get_post_thumbnail_id($post_id);
        if ($existing_thumb) return;
        $tmp = download_url($image_url);
        if (is_wp_error($tmp)) {
            $this->log_error("Echec du téléchargement de l'image $image_url pour post $post_id : " . $tmp->get_error_message());
            return;
        }
        $file_array = [
            'name'     => basename($image_url),
            'tmp_name' => $tmp
        ];
        $id = media_handle_sideload($file_array, $post_id);
        if (is_wp_error($id)) {
            $this->log_error("Echec media_handle_sideload pour $image_url (post $post_id): " . $id->get_error_message());
            @unlink($tmp);
            return;
        }
        set_post_thumbnail($post_id, $id);
        @unlink($tmp);
    }

    // Télécharge une image (image_square), l’attache au post, retourne son ID
    private function sideload_media_and_attach($image_url, $post_id, $desc = '')
    {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $tmp = download_url($image_url);
        if (is_wp_error($tmp)) {
            $this->log_error("Echec du téléchargement de l'image_square $image_url pour post $post_id : " . $tmp->get_error_message());
            return false;
        }
        $file_array = [
            'name'     => basename($image_url),
            'tmp_name' => $tmp
        ];
        $id = media_handle_sideload($file_array, $post_id, $desc);
        if (is_wp_error($id)) {
            $this->log_error("Echec media_handle_sideload (image_square) pour $image_url (post $post_id): " . $id->get_error_message());
            @unlink($tmp);
            return false;
        }
        @unlink($tmp);
        return $id;
    }
}

new DCI_Plugin();
