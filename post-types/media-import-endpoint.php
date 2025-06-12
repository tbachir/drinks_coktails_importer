<?php
/**
 * Endpoint WordPress pour importer des images depuis des URLs externes
 * À ajouter dans functions.php du thème ou dans un plugin personnalisé
 *
 * @package InlineEditor
 * @subpackage MediaImport
 */

// Empêcher l'accès direct
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enregistrer l'endpoint API REST pour l'import d'images
 */
add_action('rest_api_init', function () {
    register_rest_route('api/media', '/import-url', array(
        'methods' => 'POST',
        'callback' => 'inline_editor_handle_media_import_from_url',
        'permission_callback' => 'inline_editor_check_upload_permissions',
        'args' => array(
            'url' => array(
                'required' => true,
                'type' => 'string',
                'validate_callback' => function($param, $request, $key) {
                    return filter_var($param, FILTER_VALIDATE_URL) !== false;
                },
                'sanitize_callback' => 'esc_url_raw'
            ),
            'filename' => array(
                'required' => false,
                'type' => 'string',
                'default' => '',
                'sanitize_callback' => 'sanitize_file_name'
            ),
            'title' => array(
                'required' => false,
                'type' => 'string',
                'default' => '',
                'sanitize_callback' => 'sanitize_text_field'
            ),
            'alt' => array(
                'required' => false,
                'type' => 'string',
                'default' => '',
                'sanitize_callback' => 'sanitize_text_field'
            )
        )
    ));
});

/**
 * Vérifier les permissions pour l'upload
 * 
 * @return bool|WP_Error
 */
function inline_editor_check_upload_permissions() {
    // Vérifier que l'utilisateur est connecté
    if (!is_user_logged_in()) {
        return new WP_Error(
            'rest_forbidden',
            'Vous devez être connecté pour uploader des images.',
            array('status' => 401)
        );
    }
    
    // Vérifier les capacités
    if (!current_user_can('upload_files')) {
        return new WP_Error(
            'rest_forbidden',
            'Vous n\'avez pas la permission d\'uploader des fichiers.',
            array('status' => 403)
        );
    }
    
    return true;
}

/**
 * Handler principal pour l'import d'images depuis une URL
 * 
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function inline_editor_handle_media_import_from_url(WP_REST_Request $request) {
    $url = $request->get_param('url');
    $filename = $request->get_param('filename');
    $title = $request->get_param('title');
    $alt = $request->get_param('alt');
    
    // Log pour debug
    error_log('[Media Import] Starting import from URL: ' . $url);
    
    // Validation supplémentaire de l'URL
    if (!wp_http_validate_url($url)) {
        return new WP_Error(
            'invalid_url',
            'URL invalide ou non autorisée.',
            array('status' => 400)
        );
    }
    
    // Vérifier que l'URL est accessible
    $response = wp_remote_head($url, array(
        'timeout' => 10,
        'redirection' => 5,
        'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url')
    ));
    
    if (is_wp_error($response)) {
        return new WP_Error(
            'url_not_accessible',
            'Impossible d\'accéder à l\'URL: ' . $response->get_error_message(),
            array('status' => 400)
        );
    }
    
    // Vérifier le Content-Type
    $headers = wp_remote_retrieve_headers($response);
    $content_type = wp_remote_retrieve_header($response, 'content-type');
    
    if (!inline_editor_is_valid_image_content_type($content_type)) {
        return new WP_Error(
            'invalid_content_type',
            'Le fichier n\'est pas une image valide. Type détecté: ' . $content_type,
            array('status' => 400)
        );
    }
    
    // Générer un nom de fichier si non fourni
    if (empty($filename)) {
        $filename = inline_editor_generate_filename_from_url($url, $content_type);
    }
    
    // S'assurer que le nom de fichier est unique
    $upload_dir = wp_upload_dir();
    $filename = wp_unique_filename($upload_dir['path'], $filename);
    
    // Télécharger l'image
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    $tmp = download_url($url, 300); // Timeout de 5 minutes
    
    if (is_wp_error($tmp)) {
        error_log('[Media Import] Download failed: ' . $tmp->get_error_message());
        return new WP_Error(
            'download_failed',
            'Impossible de télécharger l\'image: ' . $tmp->get_error_message(),
            array('status' => 500)
        );
    }
    
    // Vérifier la taille du fichier
    $file_size = filesize($tmp);
    $max_size = inline_editor_get_max_upload_size();
    
    if ($file_size > $max_size) {
        @unlink($tmp);
        return new WP_Error(
            'file_too_large',
            sprintf('Le fichier est trop volumineux. Taille maximale autorisée: %s', size_format($max_size)),
            array('status' => 413)
        );
    }
    
    // Préparer le fichier pour l'upload
    $file_array = array(
        'name' => $filename,
        'tmp_name' => $tmp,
        'size' => $file_size
    );
    
    // Vérifier le type MIME réel du fichier téléchargé
    $file_type = wp_check_filetype_and_ext($tmp, $filename);
    if (!$file_type['type']) {
        @unlink($tmp);
        return new WP_Error(
            'invalid_file_type',
            'Type de fichier non autorisé.',
            array('status' => 400)
        );
    }
    
    // Gérer l'upload via WordPress
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    
    $attachment_id = media_handle_sideload($file_array, 0, $title);
    
    // Nettoyer le fichier temporaire (normalement fait par media_handle_sideload, mais au cas où)
    if (file_exists($tmp)) {
        @unlink($tmp);
    }
    
    if (is_wp_error($attachment_id)) {
        error_log('[Media Import] Upload failed: ' . $attachment_id->get_error_message());
        return new WP_Error(
            'upload_failed',
            'Échec de l\'upload: ' . $attachment_id->get_error_message(),
            array('status' => 500)
        );
    }
    
    // Mettre à jour les métadonnées
    if (!empty($title)) {
        wp_update_post(array(
            'ID' => $attachment_id,
            'post_title' => $title
        ));
    }
    
    if (!empty($alt)) {
        update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
    }
    
    // Ajouter des métadonnées supplémentaires
    update_post_meta($attachment_id, '_imported_from_url', $url);
    update_post_meta($attachment_id, '_import_date', current_time('mysql'));
    
    // Générer les métadonnées de l'image
    $attach_data = wp_generate_attachment_metadata($attachment_id, get_attached_file($attachment_id));
    wp_update_attachment_metadata($attachment_id, $attach_data);
    
    // Construire la réponse
    $response = inline_editor_format_media_response($attachment_id);
    
    error_log('[Media Import] Success! Attachment ID: ' . $attachment_id);
    
    return rest_ensure_response($response);
}

/**
 * Vérifier si le Content-Type correspond à une image
 * 
 * @param string $content_type
 * @return bool
 */
function inline_editor_is_valid_image_content_type($content_type) {
    if (empty($content_type)) {
        return false;
    }
    
    // Extraire le type principal (avant le ;)
    $content_type = explode(';', $content_type)[0];
    $content_type = strtolower(trim($content_type));
    
    $valid_types = array(
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        'image/bmp',
        'image/tiff'
    );
    
    return in_array($content_type, $valid_types);
}

/**
 * Générer un nom de fichier à partir de l'URL et du Content-Type
 * 
 * @param string $url
 * @param string $content_type
 * @return string
 */
function inline_editor_generate_filename_from_url($url, $content_type) {
    // Essayer d'extraire le nom depuis l'URL
    $path_parts = pathinfo(parse_url($url, PHP_URL_PATH));
    
    if (!empty($path_parts['basename']) && !empty($path_parts['extension'])) {
        return $path_parts['basename'];
    }
    
    // Générer un nom basé sur le timestamp
    $filename = 'image-' . time();
    
    // Déterminer l'extension depuis le Content-Type
    $extension = '';
    switch ($content_type) {
        case 'image/jpeg':
        case 'image/jpg':
            $extension = '.jpg';
            break;
        case 'image/png':
            $extension = '.png';
            break;
        case 'image/gif':
            $extension = '.gif';
            break;
        case 'image/webp':
            $extension = '.webp';
            break;
        case 'image/svg+xml':
            $extension = '.svg';
            break;
        default:
            $extension = '.jpg'; // Par défaut
    }
    
    return $filename . $extension;
}

/**
 * Obtenir la taille maximale d'upload autorisée
 * 
 * @return int
 */
function inline_editor_get_max_upload_size() {
    $max_upload = wp_max_upload_size();
    $custom_max = apply_filters('inline_editor_max_import_size', 10 * MB_IN_BYTES);
    
    return min($max_upload, $custom_max);
}

/**
 * Formater la réponse pour un média
 * 
 * @param int $attachment_id
 * @return array
 */
function inline_editor_format_media_response($attachment_id) {
    $attachment = get_post($attachment_id);
    $metadata = wp_get_attachment_metadata($attachment_id);
    $url = wp_get_attachment_url($attachment_id);
    
    $response = array(
        'id' => $attachment_id,
        'ID' => $attachment_id, // Pour compatibilité
        'source_url' => $url,
        'url' => $url, // Pour compatibilité
        'title' => array(
            'rendered' => $attachment->post_title
        ),
        'alt_text' => get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
        'caption' => array(
            'rendered' => $attachment->post_excerpt
        ),
        'description' => array(
            'rendered' => $attachment->post_content
        ),
        'media_type' => 'image',
        'mime_type' => $attachment->post_mime_type,
        'media_details' => array()
    );
    
    // Ajouter les détails du média si disponibles
    if ($metadata) {
        $response['media_details'] = array(
            'width' => isset($metadata['width']) ? $metadata['width'] : 0,
            'height' => isset($metadata['height']) ? $metadata['height'] : 0,
            'file' => isset($metadata['file']) ? $metadata['file'] : '',
            'sizes' => array()
        );
        
        // Ajouter les différentes tailles
        if (!empty($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size_name => $size_data) {
                $size_url = wp_get_attachment_image_src($attachment_id, $size_name);
                if ($size_url) {
                    $response['media_details']['sizes'][$size_name] = array(
                        'source_url' => $size_url[0],
                        'width' => $size_data['width'],
                        'height' => $size_data['height'],
                        'mime_type' => $size_data['mime-type'] ?? $attachment->post_mime_type
                    );
                }
            }
            
            // Ajouter la taille 'full'
            $response['media_details']['sizes']['full'] = array(
                'source_url' => $url,
                'width' => $metadata['width'],
                'height' => $metadata['height'],
                'mime_type' => $attachment->post_mime_type
            );
        }
    }
    
    return $response;
}

/**
 * Ajouter les types MIME supportés si nécessaire
 */
add_filter('upload_mimes', function($mimes) {
    // S'assurer que WebP est autorisé
    if (!isset($mimes['webp'])) {
        $mimes['webp'] = 'image/webp';
    }
    
    return $mimes;
}, 10, 1);

/**
 * Logger les erreurs d'import pour le debug
 */
add_action('rest_api_init', function() {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        add_filter('rest_request_after_callbacks', function($response, $handler, $request) {
            if ($request->get_route() === '/api/media/import-url' && is_wp_error($response)) {
                error_log('[Media Import] API Error: ' . print_r($response, true));
            }
            return $response;
        }, 10, 3);
    }
});