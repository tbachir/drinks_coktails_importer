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
