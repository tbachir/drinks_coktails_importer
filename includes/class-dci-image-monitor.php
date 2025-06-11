<?php
/**
 * Classe de monitoring des images
 */

class DCI_Image_Monitor {
    
    /**
     * Obtenir toutes les tâches d'images en attente
     */
    public static function get_pending_images() {
        $crons = _get_cron_array();
        $pending_images = array();
        
        foreach ($crons as $timestamp => $cron) {
            if (isset($cron['dci_download_image'])) {
                foreach ($cron['dci_download_image'] as $hook) {
                    $pending_images[] = array(
                        'timestamp' => $timestamp,
                        'scheduled_time' => date('Y-m-d H:i:s', $timestamp),
                        'time_remaining' => human_time_diff(time(), $timestamp),
                        'post_id' => $hook['args'][0],
                        'image_url' => $hook['args'][1],
                        'type' => $hook['args'][2]
                    );
                }
            }
        }
        
        return $pending_images;
    }
    
    /**
     * Forcer le traitement immédiat de toutes les images
     */
    public static function process_all_pending_images() {
        $pending = self::get_pending_images();
        $processed = 0;
        
        foreach ($pending as $image) {
            // Déclencher immédiatement
            do_action('dci_download_image', 
                $image['post_id'], 
                $image['image_url'], 
                $image['type']
            );
            
            // Supprimer de la queue
            wp_unschedule_event(
                $image['timestamp'], 
                'dci_download_image', 
                array($image['post_id'], $image['image_url'], $image['type'])
            );
            
            $processed++;
            
            // Pause pour éviter la surcharge
            if ($processed % 1 === 0) {
                sleep(10000);
            }
        }
        
        return $processed;
    }
    
    /**
     * Ajouter une page de monitoring dans l'admin
     */
    public static function add_monitor_page() {
        add_submenu_page(
            'edit.php?post_type=drink',
            'Images en attente',
            'Images en attente',
            'manage_options',
            'dci-image-monitor',
            array(__CLASS__, 'render_monitor_page')
        );
    }
    
    /**
     * Afficher la page de monitoring
     */
    public static function render_monitor_page() {
        $pending = self::get_pending_images();
        
        // Traitement forcé si demandé
        if (isset($_POST['process_all']) && check_admin_referer('dci_process_images')) {
            $processed = self::process_all_pending_images();
            echo '<div class="notice notice-success"><p>';
            printf(__('%d images traitées avec succès', 'drinks-cocktails-import'), $processed);
            echo '</p></div>';
            
            // Recharger la liste
            $pending = self::get_pending_images();
        }
        
        ?>
        <div class="wrap">
            <h1><?php _e('Images en attente de téléchargement', 'drinks-cocktails-import'); ?></h1>
            
            <?php if (empty($pending)): ?>
                <div class="notice notice-info">
                    <p><?php _e('Aucune image en attente de téléchargement.', 'drinks-cocktails-import'); ?></p>
                </div>
            <?php else: ?>
                <p><?php printf(__('%d images en attente', 'drinks-cocktails-import'), count($pending)); ?></p>
                
                <form method="post" style="margin-bottom: 20px;">
                    <?php wp_nonce_field('dci_process_images'); ?>
                    <button type="submit" name="process_all" class="button button-primary">
                        <?php _e('Traiter toutes les images maintenant', 'drinks-cocktails-import'); ?>
                    </button>
                </form>
                
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php _e('Post', 'drinks-cocktails-import'); ?></th>
                            <th><?php _e('URL de l\'image', 'drinks-cocktails-import'); ?></th>
                            <th><?php _e('Type', 'drinks-cocktails-import'); ?></th>
                            <th><?php _e('Planifié pour', 'drinks-cocktails-import'); ?></th>
                            <th><?php _e('Actions', 'drinks-cocktails-import'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending as $image): ?>
                            <tr>
                                <td>
                                    <?php 
                                    $post = get_post($image['post_id']);
                                    if ($post) {
                                        echo '<a href="' . get_edit_post_link($image['post_id']) . '">';
                                        echo esc_html($post->post_title);
                                        echo '</a>';
                                    } else {
                                        echo 'Post #' . $image['post_id'];
                                    }
                                    ?>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url($image['image_url']); ?>" target="_blank">
                                        <?php echo esc_html(substr($image['image_url'], 0, 50)) . '...'; ?>
                                    </a>
                                </td>
                                <td><?php echo esc_html($image['type']); ?></td>
                                <td>
                                    <?php echo esc_html($image['scheduled_time']); ?>
                                    <br><small><?php echo esc_html($image['time_remaining']); ?></small>
                                </td>
                                <td>
                                    <button class="button button-small process-single" 
                                            data-post-id="<?php echo $image['post_id']; ?>"
                                            data-url="<?php echo esc_attr($image['image_url']); ?>"
                                            data-type="<?php echo esc_attr($image['type']); ?>">
                                        <?php _e('Traiter', 'drinks-cocktails-import'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <script>
                jQuery(document).ready(function($) {
                    $('.process-single').on('click', function() {
                        var button = $(this);
                        var row = button.closest('tr');
                        
                        button.prop('disabled', true).text('Traitement...');
                        
                        $.post(ajaxurl, {
                            action: 'dci_process_single_image',
                            post_id: button.data('post-id'),
                            url: button.data('url'),
                            type: button.data('type'),
                            nonce: '<?php echo wp_create_nonce('dci_process_image'); ?>'
                        }, function(response) {
                            if (response.success) {
                                row.fadeOut();
                            } else {
                                alert('Erreur lors du traitement');
                                button.prop('disabled', false).text('Traiter');
                            }
                        });
                    });
                });
                </script>
            <?php endif; ?>
            
            <h2><?php _e('Informations sur WP-Cron', 'drinks-cocktails-import'); ?></h2>
            <div class="dci-cron-info">
                <?php if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON): ?>
                    <div class="notice notice-warning">
                        <p><?php _e('WP-Cron est désactivé. Les images ne seront pas téléchargées automatiquement.', 'drinks-cocktails-import'); ?></p>
                        <p><?php _e('Configurez un cron système ou traitez les images manuellement.', 'drinks-cocktails-import'); ?></p>
                    </div>
                <?php else: ?>
                    <div class="notice notice-success">
                        <p><?php _e('WP-Cron est actif. Les images seront téléchargées automatiquement.', 'drinks-cocktails-import'); ?></p>
                    </div>
                <?php endif; ?>
                
                <h3><?php _e('Dernière exécution du cron', 'drinks-cocktails-import'); ?></h3>
                <?php
                $last_run = get_option('dci_last_cron_run', 0);
                if ($last_run) {
                    echo '<p>' . sprintf(__('Dernière exécution : %s', 'drinks-cocktails-import'), 
                        date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_run)
                    ) . '</p>';
                } else {
                    echo '<p>' . __('Aucune exécution enregistrée', 'drinks-cocktails-import') . '</p>';
                }
                ?>
            </div>
        </div>
        <?php
    }
}

// Ajouter les hooks
add_action('admin_menu', array('DCI_Image_Monitor', 'add_monitor_page'));

// AJAX pour traiter une image unique
add_action('wp_ajax_dci_process_single_image', function() {
    if (!check_ajax_referer('dci_process_image', 'nonce', false)) {
        wp_die('Nonce invalide');
    }
    
    $post_id = intval($_POST['post_id']);
    $url = esc_url_raw($_POST['url']);
    $type = sanitize_text_field($_POST['type']);
    
    // Déclencher le téléchargement
    do_action('dci_download_image', $post_id, $url, $type);
    
    wp_send_json_success();
});

// Enregistrer la dernière exécution
add_action('dci_download_image', function() {
    update_option('dci_last_cron_run', time());
}, 1);