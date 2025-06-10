<?php
/**
 * Post Type pour contenu éditable - Version JWT
 * 
 * @package Inline_Editor_CMS
 * @subpackage PostTypes
 */

if (!defined('ABSPATH')) exit;

class Editable_Content {
    
    public function __construct() {
        add_action('init', array($this, 'register_post_type'));
        add_action('rest_api_init', array($this, 'register_rest_fields'));
        add_action('rest_api_init', array($this, 'register_custom_endpoints'));
        
        // Ajouter le support JWT aux endpoints custom
        add_filter('jwt_auth_whitelist', array($this, 'whitelist_endpoints'));
    }
    
    /**
     * Whitelist nos endpoints custom pour JWT
     */
    public function whitelist_endpoints($endpoints) {
        $endpoints[] = '/wp-json/api/editable-content/(.*)';
        $endpoints[] = '/wp-json/api/editable-content/save';
        $endpoints[] = '/wp-json/api/editable-content/batch';
        return $endpoints;
    }
    
    /**
     * Enregistrer le post type editable_content
     */
    public function register_post_type() {
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
    public function register_rest_fields() {
        register_rest_field('editable_content', 'editable_meta', array(
            'get_callback' => array($this, 'get_editable_meta'),
            'update_callback' => array($this, 'update_editable_meta'),
            'schema' => array(
                'description' => 'Métadonnées du contenu éditable',
                'type' => 'object'
            )
        ));
        
        register_rest_field('editable_content', 'context_info', array(
            'get_callback' => function($post) {
                return array(
                    'editable_id' => get_post_meta($post['id'], '_editable_id', true),
                    'context' => get_post_meta($post['id'], '_context', true) ?: '/',
                    'context_id' => get_post_meta($post['id'], '_context_id', true) ?: 0,
                    'content_type' => get_post_meta($post['id'], '_content_type', true) ?: 'text'
                );
            }
        ));
    }
    
    /**
     * Récupérer les métadonnées du contenu éditable
     */
    public function get_editable_meta($post) {
        return array(
            'editable_id' => get_post_meta($post['id'], '_editable_id', true),
            'context' => get_post_meta($post['id'], '_context', true) ?: '/',
            'context_id' => get_post_meta($post['id'], '_context_id', true) ?: 0,
            'content_type' => get_post_meta($post['id'], '_content_type', true) ?: 'text'
        );
    }
    
    /**
     * Mettre à jour les métadonnées du contenu éditable
     */
    public function update_editable_meta($value, $post) {
        if (isset($value['editable_id'])) {
            update_post_meta($post->ID, '_editable_id', sanitize_text_field($value['editable_id']));
        }
        if (isset($value['context'])) {
            update_post_meta($post->ID, '_context', sanitize_text_field($value['context']));
        }
        if (isset($value['context_id'])) {
            update_post_meta($post->ID, '_context_id', absint($value['context_id']));
        }
        if (isset($value['content_type'])) {
            update_post_meta($post->ID, '_content_type', sanitize_text_field($value['content_type']));
        }
        
        return true;
    }
    
    /**
     * Enregistrer des endpoints personnalisés
     */
    public function register_custom_endpoints() {
        // Endpoint pour rechercher par editable_id
        register_rest_route('api', '/editable-content/by-id/(?P<editable_id>[a-zA-Z0-9-_]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_content_by_editable_id'),
            'permission_callback' => '__return_true'
        ));
        
        // Endpoint pour créer/mettre à jour par editable_id
        register_rest_route('api', '/editable-content/save', array(
            'methods' => 'POST',
            'callback' => array($this, 'save_content_by_editable_id'),
            'permission_callback' => array($this, 'check_jwt_auth')
        ));
        
        // Endpoint pour sauvegarde batch
        register_rest_route('api', '/editable-content/batch', array(
            'methods' => 'POST',
            'callback' => array($this, 'batch_save_content'),
            'permission_callback' => array($this, 'check_jwt_auth')
        ));
    }
    
    /**
     * Vérifier l'authentification JWT
     */
    public function check_jwt_auth($request) {
        // Le plugin JWT définit automatiquement l'utilisateur courant si le token est valide
        return is_user_logged_in();
    }
    
    /**
     * Récupérer un contenu par son editable_id
     */
    public function get_content_by_editable_id($request) {
        $editable_id = $request['editable_id'];
        $context = $request->get_param('context') ?: '/';
        $context_id = $request->get_param('context_id') ?: 0;
        
        $posts = get_posts(array(
            'post_type' => 'editable_content',
            'meta_query' => array(
                array('key' => '_editable_id', 'value' => $editable_id),
                array('key' => '_context', 'value' => $context),
                array('key' => '_context_id', 'value' => $context_id)
            ),
            'posts_per_page' => 1
        ));
        
        if (empty($posts)) {
            return rest_ensure_response(array(
                'editable_id' => $editable_id,
                'content' => '',
                'context' => $context,
                'context_id' => $context_id,
                'exists' => false
            ));
        }
        
        $post = $posts[0];
        return rest_ensure_response(array(
            'id' => $post->ID,
            'editable_id' => $editable_id,
            'content' => $post->post_content,
            'context' => $context,
            'context_id' => $context_id,
            'exists' => true,
            'updated_at' => $post->post_modified
        ));
    }
    
    /**
     * Sauvegarder un contenu par son editable_id
     */
    public function save_content_by_editable_id($request) {
        $params = $request->get_json_params();
        
        if (!is_array($params)) {
            return new WP_Error('invalid_data', 'Données JSON invalides', array('status' => 400));
        }
        
        $editable_id = sanitize_text_field($params['editable_id'] ?? '');
        $content = $params['content'] ?? null;
        $context = sanitize_text_field($params['context'] ?? '/');
        $context_id = absint($params['context_id'] ?? 0);
        
        if (empty($editable_id) || $content === null) {
            return new WP_Error('missing_data', 'editable_id et content requis', array('status' => 400));
        }
        
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $editable_id)) {
            return new WP_Error('invalid_editable_id', 'editable_id contient des caractères invalides', array('status' => 400));
        }
        
        $content_size = is_string($content) ? strlen($content) : strlen(json_encode($content));
        if ($content_size > 1048576) {
            return new WP_Error('content_too_large', 'Contenu trop volumineux (max 1MB)', array('status' => 413));
        }
        
        // JWT garantit que l'utilisateur est connecté
        if (!current_user_can('edit_posts')) {
            return new WP_Error('insufficient_permissions', 'Permissions insuffisantes', array('status' => 403));
        }
        
        // Chercher un post existant
        $existing_posts = get_posts(array(
            'post_type' => 'editable_content',
            'meta_query' => array(
                array('key' => '_editable_id', 'value' => $editable_id),
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
                'post_title' => "Editable: {$editable_id}",
                'post_content' => is_string($content) ? $content : json_encode($content),
                'post_status' => 'publish',
                'post_type' => 'editable_content'
            ));
            
            if (!is_wp_error($post_id)) {
                update_post_meta($post_id, '_editable_id', $editable_id);
                update_post_meta($post_id, '_context', $context);
                update_post_meta($post_id, '_context_id', $context_id);
                update_post_meta($post_id, '_content_type', is_string($content) ? 'text' : 'json');
            }
        }
        
        if (is_wp_error($post_id)) {
            return new WP_Error('save_failed', $post_id->get_error_message(), array('status' => 500));
        }
        
        error_log(sprintf('[Inline-Editor-CMS] Content saved: %s by user %d', $editable_id, get_current_user_id()));
        
        return rest_ensure_response(array(
            'success' => true,
            'post_id' => $post_id,
            'editable_id' => $editable_id,
            'message' => 'Contenu sauvegardé avec succès'
        ));
    }
    
    /**
     * Sauvegarde batch de plusieurs contenus
     */
    public function batch_save_content($request) {
        $params = $request->get_json_params();
        
        $changes = $params['changes'] ?? array();
        $context = sanitize_text_field($params['context'] ?? '/');
        $context_id = absint($params['context_id'] ?? 0);
        
        if (empty($changes)) {
            return new WP_Error('no_changes', 'Aucune modification fournie', array('status' => 400));
        }
        
        $saved = 0;
        $errors = array();
        
        foreach ($changes as $editable_id => $content) {
            $fake_request = new WP_REST_Request();
            $fake_request->set_body(json_encode(array(
                'editable_id' => $editable_id,
                'content' => $content,
                'context' => $context,
                'context_id' => $context_id
            )));
            
            $result = $this->save_content_by_editable_id($fake_request);
            
            if (is_wp_error($result)) {
                $errors[$editable_id] = $result->get_error_message();
            } else {
                $saved++;
            }
        }
        
        error_log(sprintf('[Inline-Editor-CMS] Batch save: %d/%d items saved by user %d', 
            $saved, count($changes), get_current_user_id()));
        
        $response = array(
            'success' => true,
            'saved' => $saved,
            'total' => count($changes),
            'message' => sprintf('%d élément(s) sauvegardé(s)', $saved)
        );
        
        if (!empty($errors)) {
            $response['errors'] = $errors;
        }
        
        return rest_ensure_response($response);
    }
}

