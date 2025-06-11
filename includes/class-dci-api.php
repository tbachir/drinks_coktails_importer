<?php
ini_set('max_execution_time', 300);
/**
 * Classe d'import pour Drinks & Cocktails
 * 
 * @package Drinks_Cocktails_Import
 * @subpackage Importer
 */

if (!defined('ABSPATH')) exit;

class DCI_API
{
    /**
     * Récupère l'URL d'une image associée à un post
     * Donne la priorité à l'ID WordPress (_cutout_image_id), sinon l'URL brute (_cutout_image)
     * @param int $post_id
     * @param string $meta_id_key  (ex: '_cutout_image_id')
     * @param string $meta_url_key (ex: '_cutout_image')
     * @return string|null
     */
    public static function get_image_url($post_id, $meta_id_key, $meta_url_key)
    {
        $image_id = get_post_meta($post_id, $meta_id_key, true);
        if ($image_id) {
            $url = wp_get_attachment_url($image_id);
            if ($url) return $url;
        }
        $url = get_post_meta($post_id, $meta_url_key, true);
        return $url ?: null;
    }

}

add_action('rest_api_init', function () {
    register_rest_route('api/v1', '/drinks', [
        'methods'  => 'GET',
        'callback' => 'cryptonic_api_get_drinks',
        'permission_callback' => '__return_true' // ou sécurité si nécessaire
    ]);
});

/**
 * Endpoint REST "light" pour la liste des drinks
 */
add_action('rest_api_init', function () {
    register_rest_route('api/v1', '/drinks', [
        'methods'  => 'GET',
        'callback' => 'cryptonic_api_get_drinks',
        'permission_callback' => '__return_true'
    ]);
});

function cryptonic_api_get_drinks($request)
{
    $posts = get_posts([
        'post_type'      => 'drink',
        'post_status'    => 'publish',
        'posts_per_page' => -1
    ]);

    $data = [];
    foreach ($posts as $post) {
        $meta = Drinks::get_drink_meta(['id' => $post->ID]);
        $data[] = array_merge([
            'id'    => $post->ID,
            'slug'  => $post->post_name,
            'title' => get_the_title($post),
            'description' => apply_filters('the_content', $post->post_content),
            'image' => get_the_post_thumbnail_url($post, 'full')
        ], $meta);
    }
    return rest_ensure_response($data);
}

add_action('rest_api_init', function () {
    register_rest_route('api/v1', '/cocktails', [
        'methods'  => 'GET',
        'callback' => 'cryptonic_api_get_cocktails',
        'permission_callback' => '__return_true' // Adapter si besoin sécurité
    ]);
});

/**
 * Endpoint REST "light" pour la liste des cocktails
 */
function cryptonic_api_get_cocktails($request)
{
    $posts = get_posts([
        'post_type'      => 'cocktail',
        'post_status'    => 'publish',
        'posts_per_page' => -1
    ]);
    $cocktails_instance = new Cocktails();
    $data = [];
    foreach ($posts as $post) {
        $meta = Cocktails::get_cocktail_meta(['id' => $post->ID]);
        $data[] = array_merge([
            'id'    => $post->ID,
            'slug'  => $post->post_name,
            'title' => get_the_title($post),
            'description' => apply_filters('the_content', $post->post_content),
            'image' => get_the_post_thumbnail_url($post, 'full')
        ], $meta);
    }
    return rest_ensure_response($data);
}
