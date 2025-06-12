<?php

/**
 * Post Type pour contenu éditable - Version avec gestion des conflits et versions
 * 
 * @package Inline_Editor_CMS
 * @subpackage PostTypes
 */

if (!defined('ABSPATH')) exit;

class Editable_Content
{

    public function __construct()
    {
        add_action('init', array($this, 'register_post_type'));
        add_action('rest_api_init', array($this, 'register_rest_fields'));
        add_action('rest_api_init', array($this, 'register_custom_endpoints'));
        add_filter('jwt_auth_whitelist', array($this, 'whitelist_endpoints'));
       
    }

    public function whitelist_endpoints($endpoints)
    {
        $endpoints[] = '/wp-json/api/editable-content/(.*)';
        $endpoints[] = '/wp-json/api/editable-content/save';
        $endpoints[] = '/wp-json/api/editable-content/get';
        return $endpoints;
    }

    /**
     * Enregistrer le post type editable_content
     */
    public function register_post_type()
    {
        register_post_type('editable_content', array(
            'labels' => array(
                'name' => 'Contenu Éditable',
                'singular_name' => 'Contenu Éditable',
                'menu_name' => 'Contenu Éditable',
                'add_new' => 'Ajouter',
                'add_new_item' => 'Ajouter un contenu',
                'edit_item' => 'Modifier le contenu',
                'new_item' => 'Nouveau contenu',
                'view_item' => 'Voir le contenu',
                'search_items' => 'Rechercher',
                'not_found' => 'Aucun contenu trouvé',
                'not_found_in_trash' => 'Aucun contenu dans la corbeille'
            ),

            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => true,
            'rest_base' => 'editable-content',
            'rest_controller_class' => 'WP_REST_Posts_Controller',

            'supports' => array('title', 'editor', 'custom-fields', 'revisions'),
            'has_archive' => false,
            'hierarchical' => false,
            'menu_icon' => 'dashicons-edit-page',
            'menu_position' => 25,

            'capability_type' => 'post',
            'map_meta_cap' => true,
            'capabilities' => array(
                'read' => 'read',
                'create_posts' => 'edit_posts',
                'edit_posts' => 'edit_posts',
                'edit_others_posts' => 'edit_posts',
                'edit_published_posts' => 'edit_posts',
                'delete_posts' => 'edit_posts',
                'delete_others_posts' => 'edit_posts',
                'delete_published_posts' => 'edit_posts',
                'publish_posts' => 'edit_posts'
            )
        ));
    }

    /**
     * Ajouter des champs personnalisés à l'API REST
     */
    public function register_rest_fields()
    {
        register_rest_field('editable_content', 'editable_meta', array(
            'get_callback' => array($this, 'get_editable_meta'),
            'update_callback' => array($this, 'update_editable_meta'),
            'schema' => array(
                'description' => 'Métadonnées du contenu éditable',
                'type' => 'object'
            )
        ));

        register_rest_field('editable_content', 'context_info', array(
            'get_callback' => function ($post) {
                return array(
                    'editable_id' => get_post_meta($post['id'], '_editable_id', true),
                    'context' => get_post_meta($post['id'], '_context', true) ?: '/',
                    'context_id' => get_post_meta($post['id'], '_context_id', true) ?: 0,
                    'content_type' => get_post_meta($post['id'], '_content_type', true) ?: 'text',
                    'version' => intval(get_post_meta($post['id'], '_version', true)) ?: 1
                );
            }
        ));
    }

    public function register_custom_endpoints()
    {
        // GET all editable content
        register_rest_route('api', '/editable-content', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_all_editable_content'),
            'permission_callback' => '__return_true'
        ));

        // GET specific content by context
        register_rest_route('api', '/editable-content/get', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_content_by_context'),
            'permission_callback' => '__return_true'
        ));

        // POST save content
        register_rest_route('api', '/editable-content/save', array(
            'methods' => 'POST',
            'callback' => array($this, 'save_content_by_context'),
            'permission_callback' => array($this, 'check_jwt_auth')
        ));
    }

    public function check_jwt_auth($request)
    {
        return is_user_logged_in();
    }

    /**
     * Récupérer les métadonnées du contenu éditable
     */
    public function get_editable_meta($post)
    {
        $revisions = wp_get_post_revisions($post['id']);
        $last_revision_id = count($revisions) ? array_key_last($revisions) : 0;

        return array(
            'context' => get_post_meta($post['id'], '_context', true) ?: '/',
            'context_id' => get_post_meta($post['id'], '_context_id', true) ?: 0,
            'content_type' => get_post_meta($post['id'], '_content_type', true) ?: 'text',
            'version' => intval(get_post_meta($post['id'], '_version', true)) ?: 1,
            'last_revision_id' => $last_revision_id
        );
    }

    /**
     * GET all editable content
     */
    public function get_all_editable_content($request)
    {
        $args = array(
            'post_type' => 'editable_content',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'fields' => 'ids'
        );
        $post_ids = get_posts($args);

        $result = array();
        foreach ($post_ids as $post_id) {
            $editable_id = get_post_meta($post_id, '_editable_id', true);
            if (!$editable_id) {
                $editable_id = $this->generate_editable_id($post_id);
                update_post_meta($post_id, '_editable_id', $editable_id);
            }

            $context = get_post_meta($post_id, '_context', true) ?: '/';
            $context_id = get_post_meta($post_id, '_context_id', true) ?: '';
            $content = get_post_field('post_content', $post_id);
            $version = intval(get_post_meta($post_id, '_version', true)) ?: 1;
            $content_type = get_post_meta($post_id, '_content_type', true) ?: 'text';

            $result[] = array(
                'editable_id' => $editable_id,
                'context' => $context,
                'context_id' => $context_id,
                'version' => $version,
                'content' => $content,
                'content_type' => $content_type
            );
        }

        return rest_ensure_response($result);
    }

    /**
     * GET : Récupérer un contenu selon context/context_id
     */
    public function get_content_by_context($request)
    {
        $context = sanitize_text_field($request->get_param('context') ?? '/');
        $context_id = sanitize_text_field($request->get_param('context_id') ?? '');

        $posts = get_posts(array(
            'post_type' => 'editable_content',
            'meta_query' => array(
                array('key' => '_context', 'value' => $context),
                array('key' => '_context_id', 'value' => $context_id)
            ),
            'posts_per_page' => 1
        ));

        if (empty($posts)) {
            return rest_ensure_response(array(
                'content' => '',
                'context' => $context,
                'context_id' => $context_id,
                'exists' => false,
                'version' => 0
            ));
        }

        $post = $posts[0];
        $editable_id = get_post_meta($post->ID, '_editable_id', true);
        if (!$editable_id) {
            $editable_id = $this->generate_editable_id($post->ID);
            update_post_meta($post->ID, '_editable_id', $editable_id);
        }

        $version = intval(get_post_meta($post->ID, '_version', true)) ?: 1;
        $content_type = get_post_meta($post->ID, '_content_type', true) ?: 'text';

        return rest_ensure_response(array(
            'editable_id' => $editable_id,
            'content' => $post->post_content,
            'context' => $context,
            'context_id' => $context_id,
            'content_type' => $content_type,
            'exists' => true,
            'updated_at' => $post->post_modified,
            'version' => $version
        ));
    }

    /**
     * POST : Sauvegarder le contenu avec gestion des conflits et versions
     */
    public function save_content_by_context($request)
    {
        $params = $request->get_json_params();
        $content = $params['content'] ?? null;
        $context = sanitize_text_field($params['context'] ?? '/');
        $context_id = sanitize_text_field($params['context_id'] ?? '');
        $client_version = isset($params['version']) ? intval($params['version']) : null;
        $is_default_content = $params['isDefaultContent'] ?? false;
        $content_type = sanitize_text_field($params['content_type'] ?? 'text');

        // Validation des paramètres requis
        if ($content === null) {
            return rest_ensure_response(array(
                'status' => 'error',
                'message' => 'Le contenu est requis',
                'data' => null
            ));
        }

        if (!current_user_can('edit_posts')) {
            return rest_ensure_response(array(
                'status' => 'error',
                'message' => 'Permissions insuffisantes',
                'data' => null
            ));
        }

        // Recherche du contenu existant
        $existing_posts = get_posts(array(
            'post_type' => 'editable_content',
            'meta_query' => array(
                array('key' => '_context', 'value' => $context),
                array('key' => '_context_id', 'value' => $context_id)
            ),
            'posts_per_page' => 1
        ));

        // Si c'est un contenu par défaut et qu'il existe déjà, ne rien faire
        if ($is_default_content && !empty($existing_posts)) {
            $post = $existing_posts[0];
            $editable_id = get_post_meta($post->ID, '_editable_id', true);
            if (!$editable_id) {
                $editable_id = $this->generate_editable_id($post->ID);
                update_post_meta($post->ID, '_editable_id', $editable_id);
            }

            return rest_ensure_response(array(
                'status' => 'no_action',
                'message' => 'Le contenu existe déjà',
                'data' => array(
                    'editable_id' => $editable_id,
                    'content' => $post->post_content,
                    'context' => $context,
                    'context_id' => $context_id,
                    'version' => intval(get_post_meta($post->ID, '_version', true)) ?: 1,
                    'content_type' => get_post_meta($post->ID, '_content_type', true) ?: 'text'
                )
            ));
        }

        // Mise à jour d'un contenu existant
        if (!empty($existing_posts)) {
            $post_id = $existing_posts[0]->ID;
            $current_version = intval(get_post_meta($post_id, '_version', true)) ?: 1;
            $current_content = get_post_field('post_content', $post_id);

            // Vérification de conflit de version
            if ($client_version !== null && $client_version !== $current_version) {
                $editable_id = get_post_meta($post_id, '_editable_id', true);
                if (!$editable_id) {
                    $editable_id = $this->generate_editable_id($post_id);
                    update_post_meta($post_id, '_editable_id', $editable_id);
                }

                return rest_ensure_response(array(
                    'status' => 'conflict',
                    'message' => 'Conflit de version détecté',
                    'data' => null,
                    'conflict' => array(
                        'client_version' => $client_version,
                        'server_version' => $current_version,
                        'server_content' => $current_content,
                        'editable_id' => $editable_id
                    )
                ));
            }

            // Vérifier si le contenu a changé
            if ($current_content === $content) {
                $editable_id = get_post_meta($post_id, '_editable_id', true);
                if (!$editable_id) {
                    $editable_id = $this->generate_editable_id($post_id);
                    update_post_meta($post_id, '_editable_id', $editable_id);
                }

                return rest_ensure_response(array(
                    'status' => 'no_change',
                    'message' => 'Aucune modification apportée',
                    'data' => array(
                        'editable_id' => $editable_id,
                        'content' => $content,
                        'context' => $context,
                        'context_id' => $context_id,
                        'version' => $current_version,
                        'content_type' => get_post_meta($post_id, '_content_type', true) ?: 'text'
                    )
                ));
            }

            // Mise à jour avec incrémentation de version
            $new_version = $current_version + 1;
            wp_update_post(array(
                'ID' => $post_id,
                'post_content' => $content
            ));
            update_post_meta($post_id, '_version', $new_version);
            update_post_meta($post_id, '_content_type', $content_type);

            $editable_id = get_post_meta($post_id, '_editable_id', true);
            if (!$editable_id) {
                $editable_id = $this->generate_editable_id($post_id);
                update_post_meta($post_id, '_editable_id', $editable_id);
            }

            error_log(sprintf(
                '[Inline-Editor-CMS] Content updated: %s/%s (v%d->v%d) by user %d',
                $context,
                $context_id,
                $current_version,
                $new_version,
                get_current_user_id()
            ));

            return rest_ensure_response(array(
                'status' => 'success',
                'message' => 'Contenu mis à jour avec succès',
                'data' => array(
                    'editable_id' => $editable_id,
                    'content' => $content,
                    'context' => $context,
                    'context_id' => $context_id,
                    'version' => $new_version,
                    'content_type' => $content_type
                )
            ));
        }

        // Création d'un nouveau contenu
        $post_id = wp_insert_post(array(
            'post_title' => "Editable: {$context}/{$context_id}",
            'post_content' => $content,
            'post_status' => 'publish',
            'post_type' => 'editable_content'
        ));

        if (is_wp_error($post_id)) {
            return rest_ensure_response(array(
                'status' => 'error',
                'message' => 'Erreur lors de la création : ' . $post_id->get_error_message(),
                'data' => null
            ));
        }

        // Générer un editable_id unique
        $editable_id = $this->generate_editable_id($post_id);

        // Sauvegarder les métadonnées
        update_post_meta($post_id, '_editable_id', $editable_id);
        update_post_meta($post_id, '_context', $context);
        update_post_meta($post_id, '_context_id', $context_id);
        update_post_meta($post_id, '_content_type', $content_type);
        update_post_meta($post_id, '_version', 1);

        error_log(sprintf(
            '[Inline-Editor-CMS] Content created: %s/%s by user %d',
            $context,
            $context_id,
            get_current_user_id()
        ));

        return rest_ensure_response(array(
            'status' => 'success',
            'message' => 'Contenu créé avec succès',
            'data' => array(
                'editable_id' => $editable_id,
                'content' => $content,
                'context' => $context,
                'context_id' => $context_id,
                'version' => 1,
                'content_type' => $content_type
            )
        ));
    }

    /**
     * Générer un identifiant unique pour un contenu éditable
     */
    private function generate_editable_id($post_id)
    {
        return 'editable_' . $post_id . '_' . uniqid();
    }
}
