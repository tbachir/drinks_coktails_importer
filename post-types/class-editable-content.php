<?php

/**
 * Post Type pour contenu éditable - Version JWT
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
                    'content_type' => get_post_meta($post['id'], '_content_type', true) ?: 'text'
                );
            }
        ));
    }


    public function register_custom_endpoints()
    {
        // GET via context/context_id
        register_rest_route('api', '/editable-content/get', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_content_by_context'),
            'permission_callback' => '__return_true'
        ));

        // POST persist (create/update) via context/context_id
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
        $last_revision_id = count($revisions) ? array_key_first($revisions) : 0;

        return array(
            'editable_id' => get_post_meta($post['id'], '_editable_id', true),
            'context' => get_post_meta($post['id'], '_context', true) ?: '/',
            'context_id' => get_post_meta($post['id'], '_context_id', true) ?: 0,
            'content_type' => get_post_meta($post['id'], '_content_type', true) ?: 'text',
            'version' => $last_revision_id
        );
    }
    /**
     * GET : Récupérer un contenu selon context/context_id
     */
    public function get_content_by_context($request)
    {
        $context = sanitize_text_field($request->get_param('context') ?? '/');
        $context_id = sanitize_text_field($params['context_id'] ?? '');


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
                'exists' => false
            ));
        }

        $post = $posts[0];
        return rest_ensure_response(array(
            'id' => $post->ID,
            'content' => $post->post_content,
            'context' => $context,
            'context_id' => $context_id,
            'exists' => true,
            'updated_at' => $post->post_modified
        ));
    }

    /**
     * POST : Persiste le contenu (update ou create) selon context/context_id
     */
    public function save_content_by_context($request)
    {
        $params = $request->get_json_params();
        $content = $params['content'] ?? null;
        $context = sanitize_text_field($params['context'] ?? '/');
        $context_id = sanitize_text_field($params['context_id'] ?? '');

        if ($content === null) {
            return new WP_Error('missing_data', 'content requis', array('status' => 400));
        }

        if (!current_user_can('edit_posts')) {
            return new WP_Error('insufficient_permissions', 'Permissions insuffisantes', array('status' => 403));
        }

        // Recherche sur context/context_id
        $existing_posts = get_posts(array(
            'post_type' => 'editable_content',
            'meta_query' => array(
                array('key' => '_context', 'value' => $context),
                array('key' => '_context_id', 'value' => $context_id)
            ),
            'posts_per_page' => 1
        ));

        if (!empty($existing_posts)) {
            $post_id = wp_update_post(array(
                'ID' => $existing_posts[0]->ID,
                'post_content' => is_string($content) ? $content : json_encode($content)
            ));
        } else {
            $post_id = wp_insert_post(array(
                'post_title' => "Editable: {$context}/{$context_id}",
                'post_content' => is_string($content) ? $content : json_encode($content),
                'post_status' => 'publish',
                'post_type' => 'editable_content'
            ));

            if (!is_wp_error($post_id)) {
                update_post_meta($post_id, '_context', $context);
                update_post_meta($post_id, '_context_id', $context_id);
                update_post_meta($post_id, '_content_type', is_string($content) ? 'text' : 'json');
            }
        }

        if (is_wp_error($post_id)) {
            return new WP_Error('save_failed', $post_id->get_error_message(), array('status' => 500));
        }

        error_log(sprintf('[Inline-Editor-CMS] Content saved: %s/%s by user %d', $context, $context_id, get_current_user_id()));

        $revisions = wp_get_post_revisions($post['id']);
        $last_revision_id = count($revisions) ? array_key_first($revisions) : 0;
        return rest_ensure_response(array(
            'success' => true,
            'post_id' => $post_id,
            'context' => $context,
            'context_id' => $context_id,
            'message' => 'Contenu sauvegardé avec succès',
            'version' => $last_revision_id
        ));
    }
}
