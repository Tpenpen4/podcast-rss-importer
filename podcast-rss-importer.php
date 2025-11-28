<?php
/**
 * Plugin Name: Podcast Importer
 * Description: ポッドキャストをRSSで自動更新！
 * Version: 0.9.2
 * Author: よん。
 */
if (!defined('ABSPATH')) exit;

// Plugin update checker
require 'plugin-update-checker/plugin-update-checker.php';
$myUpdateChecker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/Tpenpen4/podcast-rss-importer/raw/main/update.json',
    __FILE__,
    'podcast-rss-importer',
    1 // Check for updates every hour.
);

register_activation_hook(__FILE__, 'pod_importer_activate');
register_deactivation_hook(__FILE__, 'pod_importer_deactivate');

function pod_importer_activate() {
    pod_importer_register_schedules();
}

function pod_importer_deactivate() {
    wp_unschedule_hook('pod_importer_cron_event');
}

add_filter('cron_schedules', function($schedules){
    $schedules['every15min'] = ['interval' => 900, 'display' => __('15分ごと', 'pod-importer')];
    $schedules['every6hours'] = ['interval' => 21600, 'display' => __('6時間ごと', 'pod-importer')];
    return $schedules;
});

function pod_importer_get_feeds() {
    $feeds = get_option('pod_importer_feeds', []);
    return is_array($feeds) ? $feeds : [];
}

function pod_importer_save_feeds($feeds) {
    update_option('pod_importer_feeds', $feeds);
    pod_importer_register_schedules();
}

function pod_importer_register_schedules() {
    // Clear all previously scheduled cron jobs for this hook.
    wp_unschedule_hook('pod_importer_cron_event');

    $feeds = pod_importer_get_feeds();
    $site_tz = new \DateTimeZone(wp_timezone_string());

    foreach ($feeds as $i => $feed) {
        if (empty($feed['schedule_type']) || $feed['schedule_type'] === 'none') {
            continue;
        }

        $hook_name = 'pod_importer_cron_event';

        if ($feed['schedule_type'] === 'interval' && !empty($feed['interval'])) {
            $args = ['feed_index' => $i, 'schedule_type' => 'interval'];
            if (!wp_next_scheduled($hook_name, $args)) {
                wp_schedule_event(time(), $feed['interval'], $hook_name, $args);
            }
        } elseif ($feed['schedule_type'] === 'time' && !empty($feed['time'])) {
            $args = ['feed_index' => $i, 'schedule_type' => 'daily'];
            $parts = explode(':', $feed['time']);
            $hour = intval($parts[0]);
            $min = intval($parts[1] ?? 0);

            $now = new \DateTime('now', $site_tz);
            $scheduled_time = (new \DateTime('now', $site_tz))->setTime($hour, $min, 0);
            if ($now > $scheduled_time) {
                $scheduled_time->modify('+1 day');
            }
            $timestamp = $scheduled_time->getTimestamp();

            if (!wp_next_scheduled($hook_name, $args)) {
                wp_schedule_event($timestamp, 'daily', $hook_name, $args);
            }
        } elseif ($feed['schedule_type'] === 'weekly' && !empty($feed['weekdays'])) {
            $weekdays_map = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            foreach ($feed['weekdays'] as $day_index => $dayData) {
                if (empty($dayData['enabled']) || !isset($weekdays_map[$day_index])) continue;
                
                $day_name = $weekdays_map[$day_index];
                foreach ($dayData['times'] as $time_str) {
                    $args = ['feed_index' => $i, 'day' => $day_index, 'time' => $time_str];
                    $parts = explode(':', $time_str);
                    $hour = intval($parts[0]);
                    $min = intval($parts[1] ?? 0);

                    $date_str_in_site_tz = "next $day_name $hour:$min";
                    $dt = new \DateTime($date_str_in_site_tz, $site_tz);
                    $timestamp = $dt->getTimestamp();
                    
                    if (!wp_next_scheduled($hook_name, $args)) {
                        wp_schedule_event($timestamp, 'weekly', $hook_name, $args);
                    }
                }
            }
        }
    }
}

add_action('pod_importer_cron_event', 'pod_importer_execute_scheduled_import', 10, 1);
/**
 * Executes the feed import for a scheduled event.
 *
 * @param array $args Arguments passed from the cron event, must contain 'feed_index'.
 */
function pod_importer_execute_scheduled_import($args) {
    error_log('Podcast Importer Cron: Task started with args: ' . print_r($args, true));
    if (!isset($args['feed_index'])) {
        error_log('Podcast Importer Cron Error: feed_index not provided in cron arguments.');
        return;
    }

    $feeds = pod_importer_get_feeds();
    $feed_index = $args['feed_index'];

    if (isset($feeds[$feed_index])) {
        // To avoid race conditions or long-running processes,
        // check if this specific event is already running.
        $lock_transient_key = 'pod_importer_lock_' . md5(serialize($args));
        if (get_transient($lock_transient_key)) {
            error_log('Podcast Importer Cron: Import for feed ' . $feed_index . ' is already running. Skipping.');
            return; // Already running.
        }
        
        set_transient($lock_transient_key, true, 60 * 15); // Lock for 15 minutes.
        
        error_log('Podcast Importer Cron: Processing feed index ' . $feed_index . '. Data: ' . print_r($feeds[$feed_index], true));
        pod_importer_import_feed($feeds[$feed_index]);

        delete_transient($lock_transient_key); // Unlock.
    } else {
        error_log('Podcast Importer Cron Error: Invalid feed_index ' . $feed_index . ' provided in cron arguments. Feeds array: ' . print_r($feeds, true));
    }
}

function pod_importer_get_attachment_by_source_url($source_url) {
    $query_args = [
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => 1,
        'meta_query'     => [
            [
                'key'     => '_pod_importer_source_url',
                'value'   => $source_url,
                'compare' => '=',
            ],
        ],
        'fields'         => 'ids',
    ];
    $attachments = get_posts($query_args);
    if ($attachments) {
        return $attachments[0];
    }
    return null;
}

function pod_importer_import_feed($feed) {
    if (!is_array($feed) || !isset($feed['url']) || empty(trim($feed['url']))) {
        error_log('Podcast Importer Import Error: Feed data is invalid or URL is empty. Data received: ' . print_r($feed, true));
        return;
    }

    error_log('Podcast Importer: Starting import for ' . $feed['url']);
    include_once(ABSPATH . WPINC . '/feed.php');
    $feed_url = $feed['url'];
    $cat_id = $feed['cat'];
    $tags = $feed['tags'];

    $rss = fetch_feed($feed_url);

    if (is_wp_error($rss)) {
        error_log('Podcast Importer Error: Failed to fetch feed ' . $feed_url . '. Error: ' . $rss->get_error_message());
        error_log('Podcast Importer Error Details: ' . print_r($rss, true));
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Podcast Importer Error: Failed to fetch feed ' . $feed_url . '. Error: ' . $rss->get_error_message());
        }
        return;
    }

    $maxitems = $rss->get_item_quantity(10);
    $items = $rss->get_items(0, $maxitems);
    error_log('Podcast Importer: Found ' . count($items) . ' items in feed ' . $feed['url']);

    foreach ($items as $item) {
        $guid = $item->get_id();
        if (!$guid) {
            $guid = sha1($item->get_title() . $item->get_date('U'));
        }

        $exist = get_posts([
            'post_type' => 'post',
            'meta_key' => 'pod_import_guid',
            'meta_value'=> $guid,
            'fields' => 'ids',
            'posts_per_page' => 1,
        ]);

        if ($exist) {
            continue;
        }

        $title = $item->get_title();
        $content = $item->get_content() ?: $item->get_description();
        
        $timestamp = $item->get_date('U');
        $date_gmt = gmdate('Y-m-d H:i:s', $timestamp);
        $date = get_date_from_gmt($date_gmt);

        $enclosure_url = '';
        if ($item->get_enclosure()) {
            $enc = $item->get_enclosure();
            $enclosure_url = $enc->get_link();
        }
        $player = $enclosure_url ? '[audio src="' . esc_url($enclosure_url) . '" preload="metadata"]' : '';

        $image_url = '';
        $itunes_image = $item->get_item_tags('http://www.itunes.com/dtds/podcast-1.0.dtd', 'image');
        if (!empty($itunes_image[0]['attribs']['']['href'])) {
            $image_url = $itunes_image[0]['attribs']['']['href'];
        }
        if (!$image_url) {
            $media_thumb = $item->get_item_tags('http://search.yahoo.com/mrss/', 'thumbnail');
            if (!empty($media_thumb[0]['attribs']['']['url'])) {
                $image_url = $media_thumb[0]['attribs']['']['url'];
            }
        }
        if (!$image_url && $rss->get_image_url()) {
            $image_url = $rss->get_image_url();
        }

        $post_id = wp_insert_post([
            'post_title' => $title,
            'post_content' => $player . "\n\n" . $content,
            'post_status' => 'publish',
            'post_date' => $date,
            'post_date_gmt' => $date_gmt,
            'post_category'=> $cat_id ? [$cat_id] : [],
            'tags_input' => $tags,
        ]);

        if ($post_id) {
            update_post_meta($post_id, 'pod_import_guid', $guid);
            if ($enclosure_url) {
                update_post_meta($post_id, 'pod_import_enclosure', $enclosure_url);
            }

            if ($image_url) {
                $existing_attach_id = pod_importer_get_attachment_by_source_url($image_url);
                if ($existing_attach_id) {
                    set_post_thumbnail($post_id, $existing_attach_id);
                } else {
                    require_once(ABSPATH . 'wp-admin/includes/image.php');
                    require_once(ABSPATH . 'wp-admin/includes/file.php');
                    require_once(ABSPATH . 'wp-admin/includes/media.php');
                    
                    $attach_id = media_sideload_image($image_url, $post_id, $title, 'id');
                    if (!is_wp_error($attach_id)) {
                        set_post_thumbnail($post_id, $attach_id);
                        // Store the source URL for future checks
                        update_post_meta($attach_id, '_pod_importer_source_url', $image_url);
                    }
                }
            }
        }
    }
}

add_action('admin_menu', function(){
    add_menu_page(
        'Podcast Importer', 'Podcast Importer',
        'manage_options', 'pod-importer',
        'pod_importer_admin_page',
        'dashicons-microphone'
    );
});

add_action('admin_enqueue_scripts', function($hook){
    if ($hook === 'toplevel_page_pod-importer') {
        wp_enqueue_script('jquery-ui-dialog');
        wp_enqueue_style('wp-jquery-ui-dialog');

        $script_path = plugin_dir_path(__FILE__) . 'admin.js';
        $script_url = plugin_dir_url(__FILE__) . 'admin.js';
        
        wp_enqueue_script('pod-importer-admin', $script_url, ['jquery', 'jquery-ui-dialog'], filemtime($script_path), true);
        wp_localize_script('pod-importer-admin', 'podImporter', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pod_importer_ajax_nonce'),
            'spinner' => '<span class="spinner is-active" style="float:none;margin:0 0 0 4px;"></span>',
            'text' => [
                'edit_feed' => __('フィードを編集', 'pod-importer'),
                'add_feed' => __('新しいフィードを追加', 'pod-importer'),
                'save' => __('保存', 'pod-importer'),
                'close' => __('閉じる', 'pod-importer'),
                'error' => __('エラーが発生しました。', 'pod-importer'),
                'confirm_delete' => __('このフィードを本当に削除しますか？この操作は取り消せません。', 'pod-importer'),
                'deleting' => __('削除中...', 'pod-importer'),
                'fetching' => __('取得中...', 'pod-importer'),
            ]
        ]);
    }
});

function pod_importer_render_row($id, $feed, $is_placeholder = false) {
    ob_start();
    $cat_name = $feed['cat'] ? esc_html(get_cat_name($feed['cat'])) : __('未指定', 'pod-importer');
    $schedule_text = '';
    switch ($feed['schedule_type'] ?? 'none') {
        case 'interval':
            $schedule_text = __('間隔:', 'pod-importer') . ' ' . esc_html($feed['interval']);
            break;
        case 'time':
            $schedule_text = __('毎日', 'pod-importer') . ' ' . esc_html($feed['time']);
            break;
        case 'weekly':
            $schedule_parts = [];
            $days = [__('日'), __('月'), __('火'), __('水'), __('木'), __('金'), __('土')];
            if(is_array($feed['weekdays'])){
                foreach ($feed['weekdays'] as $day_index => $dayData) {
                    if (!empty($dayData['enabled'])) {
                        $schedule_parts[] = esc_html($days[$day_index] . ':' . implode(',', $dayData['times']));
                    }
                }
            }
            $schedule_text = __('曜日指定:', 'pod-importer') . ' ' . implode(' ', $schedule_parts);
            break;
        default:
            $schedule_text = __('自動更新しない', 'pod-importer');
            break;
    }
    ?>
    <tr data-id="<?php echo esc_attr($id); ?>" <?php if($is_placeholder) echo 'style="display:none;"'; ?> >
        <td class="feed-url"><div><?php echo esc_html($feed['url']); ?></div></td>
        <td class="feed-cat"><?php echo $cat_name; ?></td>
        <td class="feed-tags"><?php echo esc_html($feed['tags']); ?></td>
        <td class="feed-schedule"><?php echo $schedule_text; ?></td>
        <td class="feed-actions">
            <div class="dropdown">
                <button type="button" class="button dropdown-toggle" aria-haspopup="true" aria-expanded="false">
                    <?php _e('操作', 'pod-importer'); ?> <span class="dashicons dashicons-arrow-down-alt2"></span>
                </button>
                <div class="dropdown-menu">
                    <button type="button" class="dropdown-item edit-feed-btn"><?php _e('編集', 'pod-importer'); ?></button>
                    <button type="button" class="dropdown-item fetch-now-btn"><?php _e('今すぐ取得', 'pod-importer'); ?></button>
                    <button type="button" class="dropdown-item remove-feed-btn is-destructive"><?php _e('削除', 'pod-importer'); ?></button>
                </div>
            </div>
        </td>
    </tr>
    <?php
    return ob_get_clean();
}

function pod_importer_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('このページにアクセスする権限がありません。'));
    }
    ?>
    <div class="wrap pod-importer-wrap">
        <h1>
            <?php _e('Podcast RSS Importer', 'pod-importer'); ?>
            <button id="add-new-feed" class="page-title-action"><?php _e('新しいフィードを追加', 'pod-importer'); ?></button>
        </h1>
        
        <table class="widefat fixed striped" id="feed-list-table">
            <thead>
                <tr>
                    <th style="width:30%"><?php _e('RSS URL', 'pod-importer'); ?></th>
                    <th style="width:10%"><?php _e('カテゴリー', 'pod-importer'); ?></th>
                    <th style="width:15%"><?php _e('タグ', 'pod-importer'); ?></th>
                    <th style="width:25%"><?php _e('スケジュール', 'pod-importer'); ?></th>
                    <th style="width:20%"><?php _e('操作', 'pod-importer'); ?></th>
                </tr>
            </thead>
            <tbody id="feed-list">
                <?php
                $feeds = pod_importer_get_feeds();
                if (!empty($feeds)) {
                    foreach($feeds as $i => $feed) {
                        echo pod_importer_render_row($i, $feed);
                    }
                } 
                ?>
            </tbody>
        </table>
        <div id="no-feeds-row" <?php if (!empty($feeds)) echo 'style="display:none;"'; ?> >
            <p><?php _e('まだフィードが登録されていません。', 'pod-importer'); ?></p>
        </div>
    </div>
    <div id="feed-form-dialog" title="" style="display:none;"></div>
    <div id="pod-importer-notifier-container"></div>
    <?php
}

add_action('wp_ajax_pod_importer_render_form', function(){
    if (!current_user_can('manage_options')) wp_send_json_error('permission_denied', 403);
    check_ajax_referer('pod_importer_ajax_nonce', 'nonce');

    $id = isset($_POST['id']) ? intval($_POST['id']) : null;
    $feed = null;
    if ($id !== null) {
        $feeds = pod_importer_get_feeds();
        if(isset($feeds[$id])) {
            $feed = $feeds[$id];
        }
    }

    ob_start();
    pod_importer_feed_form($id, $feed);
    wp_send_json_success(['html' => ob_get_clean()]);
});

add_action('wp_ajax_pod_importer_save_feed', function(){
    if (!current_user_can('manage_options')) wp_send_json_error('permission_denied', 403);
    check_ajax_referer('pod_importer_ajax_nonce', 'nonce');

    $feeds = pod_importer_get_feeds();
    $id = isset($_POST['id']) && $_POST['id'] !== '' ? intval($_POST['id']) : null;

    $feed_data = [];
    $feed_data['url'] = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
    $feed_data['cat'] = isset($_POST['cat']) ? intval($_POST['cat']) : 0;
    $feed_data['tags'] = isset($_POST['tags']) ? sanitize_text_field($_POST['tags']) : '';
    $feed_data['schedule_type'] = isset($_POST['schedule_type']) ? sanitize_text_field($_POST['schedule_type']) : 'none';
    $feed_data['interval'] = isset($_POST['interval']) ? sanitize_text_field($_POST['interval']) : 'hourly';
    $feed_data['time'] = isset($_POST['time']) ? sanitize_text_field($_POST['time']) : '07:00';
    
    $weekdays = [];
    if (isset($_POST['weekdays']) && is_array($_POST['weekdays'])) {
        foreach ($_POST['weekdays'] as $day_index => $day_data) {
            $index = intval($day_index);
            $weekdays[$index]['enabled'] = !empty($day_data['enabled']) ? '1' : '0';
            $weekdays[$index]['times'] = [];
            if (!empty($day_data['times']) && is_array($day_data['times'])) {
                foreach($day_data['times'] as $time) {
                    if (!empty($time)) {
                       $weekdays[$index]['times'][] = sanitize_text_field($time);
                    }
                }
            }
            if (empty($weekdays[$index]['times'])) {
                $weekdays[$index]['times'][] = '07:00';
            }
        }
    }
    $feed_data['weekdays'] = $weekdays;

    if ($id !== null && isset($feeds[$id])) {
        $feeds[$id] = $feed_data;
        $new_id = $id;
    } else {
        $feeds[] = $feed_data;
        $new_id = count($feeds) - 1;
    }

    pod_importer_save_feeds($feeds);
    $row_html = pod_importer_render_row($new_id, $feed_data);
    wp_send_json_success(['message' => __('フィードを保存しました。', 'pod-importer'), 'id' => $new_id, 'rowHtml' => $row_html]);
});

add_action('wp_ajax_pod_importer_delete_feed', function(){
    if (!current_user_can('manage_options')) wp_send_json_error('permission_denied', 403);
    check_ajax_referer('pod_importer_ajax_nonce', 'nonce');

    $id = isset($_POST['id']) ? intval($_POST['id']) : null;
    if ($id === null) wp_send_json_error('invalid_id');

    $feeds = pod_importer_get_feeds();
    if (!isset($feeds[$id])) wp_send_json_error('not_found');

    unset($feeds[$id]);
    $feeds = array_values($feeds);
    pod_importer_save_feeds($feeds);

    wp_send_json_success(['message' => __('フィードを削除しました。', 'pod-importer'), 'id' => $id]);
});

add_action('wp_ajax_pod_importer_fetch_now', function(){
    if (!current_user_can('manage_options')) wp_send_json_error('permission_denied', 403);
    check_ajax_referer('pod_importer_ajax_nonce', 'nonce');

    $id = isset($_POST['id']) ? intval($_POST['id']) : null;
    if ($id === null) wp_send_json_error('invalid_id');

    $feeds = pod_importer_get_feeds();
    if (!isset($feeds[$id])) wp_send_json_error('not_found');

    pod_importer_import_feed($feeds[$id]);

    wp_send_json_success(['message' => __('フィードの取得処理を完了しました。', 'pod-importer')]);
});

function pod_importer_feed_form($id, $feed) {
    $prefix = 'pod_form';
    ?>
    <form id="pod-importer-form">
        <input type="hidden" name="id" value="<?php echo esc_attr($id); ?>">
        <table class="form-table">
            <tbody>
                <tr>
                    <th><label for="<?php echo $prefix; ?>_url"><?php _e('RSS URL', 'pod-importer'); ?></label></th>
                    <td><input type="url" id="<?php echo $prefix; ?>_url" name="url" class="regular-text" placeholder="https://example.com/podcast/feed.xml" required value="<?php echo esc_attr($feed['url'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th><label for="<?php echo $prefix; ?>_cat"><?php _e('カテゴリー', 'pod-importer'); ?></label></th>
                    <td><?php wp_dropdown_categories([
                        'show_option_none' => __('未指定', 'pod-importer'),
                        'name' => 'cat',
                        'id' => $prefix . '_cat',
                        'hide_empty' => 0,
                        'selected' => $feed['cat'] ?? 0
                    ]); ?></td>
                </tr>
                <tr>
                    <th><label for="<?php echo $prefix; ?>_tags"><?php _e('タグ (カンマ区切り)', 'pod-importer'); ?></label></th>
                    <td><input type="text" id="<?php echo $prefix; ?>_tags" name="tags" class="regular-text" value="<?php echo esc_attr($feed['tags'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th><label for="<?php echo $prefix; ?>_schedule_type"><?php _e('更新方法', 'pod-importer'); ?></label></th>
                    <td>
                        <select name="schedule_type" id="<?php echo $prefix; ?>-schedule-type">
                            <option value="none" <?php selected($feed['schedule_type'] ?? 'none', 'none'); ?>><?php _e('自動更新しない', 'pod-importer'); ?></option>
                            <option value="interval" <?php selected($feed['schedule_type'] ?? '', 'interval'); ?>><?php _e('間隔指定', 'pod-importer'); ?></option>
                            <option value="time" <?php selected($feed['schedule_type'] ?? '', 'time'); ?>><?php _e('毎日特定時刻', 'pod-importer'); ?></option>
                            <option value="weekly" <?php selected($feed['schedule_type'] ?? '', 'weekly'); ?>><?php _e('曜日＋時刻', 'pod-importer'); ?></option>
                        </select>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <div id="<?php echo $prefix; ?>-interval-box" class="schedule-box" style="display:none;">
            <table class="form-table">
                <tbody>
                    <tr>
                        <th><label for="<?php echo $prefix; ?>_interval"><?php _e('間隔', 'pod-importer'); ?></label></th>
                        <td>
                            <select id="<?php echo $prefix; ?>_interval" name="interval">
                                <option value="every15min" <?php selected($feed['interval'] ?? '', 'every15min'); ?>><?php _e('15分ごと', 'pod-importer'); ?></option>
                                <option value="hourly" <?php selected($feed['interval'] ?? '', 'hourly'); ?>><?php _e('1時間ごと', 'pod-importer'); ?></option>
                                <option value="every6hours" <?php selected($feed['interval'] ?? '', 'every6hours'); ?>><?php _e('6時間ごと', 'pod-importer'); ?></option>
                                <option value="twicedaily" <?php selected($feed['interval'] ?? '', 'twicedaily'); ?>><?php _e('1日2回', 'pod-importer'); ?></option>
                                <option value="daily" <?php selected($feed['interval'] ?? '', 'daily'); ?>><?php _e('1日1回', 'pod-importer'); ?></option>
                            </select>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div id="<?php echo $prefix; ?>-time-box" class="schedule-box" style="display:none;">
            <table class="form-table">
                <tbody>
                    <tr>
                        <th><label for="<?php echo $prefix; ?>_time"><?php _e('毎日特定時刻', 'pod-importer'); ?></label></th>
                        <td><input type="time" id="<?php echo $prefix; ?>_time" name="time" value="<?php echo esc_attr($feed['time'] ?? '07:00'); ?>"></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div id="<?php echo $prefix; ?>-weekly-box" class="schedule-box" style="display:none;">
            <table class="form-table">
                <tbody>
                    <tr>
                        <th><?php _e('曜日＋時刻', 'pod-importer'); ?></th>
                        <td>
                            <?php 
                            $days = [__('日'), __('月'), __('火'), __('水'), __('木'), __('金'), __('土')];
                            foreach ($days as $num => $label): 
                                $dayData = $feed['weekdays'][$num] ?? ['enabled' => 0, 'times' => ['07:00']];
                                ?>
                                <div class="weekday-block">
                                    <label><input type="checkbox" name="weekdays[<?php echo $num; ?>][enabled]" value="1" <?php checked(!empty($dayData['enabled'])); ?>> <?php echo $label; ?></label>
                                    <div class="time-list">
                                        <?php foreach ($dayData['times'] as $t): ?>
                                            <div class="time-item">
                                                <input type="time" name="weekdays[<?php echo $num; ?>][times][]" value="<?php echo esc_attr($t); ?>">
                                                <button type="button" class="button button-small remove-time">&times;</button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="button" class="button button-secondary add-time">+ <?php _e('時刻を追加', 'pod-importer'); ?></button>
                                </div>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </form>
    <?php
}

add_action('admin_head', function(){
    if (get_current_screen()->id !== 'toplevel_page_pod-importer') return;
    ?>
    <style>
        .pod-importer-wrap .feed-url div { max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pod-importer-wrap .dropdown { position: relative; display: inline-block; }
        .pod-importer-wrap .dropdown-menu { display: none; position: absolute; z-index: 100; background-color: #fff; border: 1px solid #ccc; border-radius: 4px; box-shadow: 0 2px 5px rgba(0,0,0,0.15); min-width: 120px; }
        .pod-importer-wrap .dropdown-menu.show { display: block; }
        .pod-importer-wrap .dropdown-item { display: block; width: 100%; padding: 8px 12px; text-align: left; border: none; background: none; cursor: pointer; }
        .pod-importer-wrap .dropdown-item:hover { background-color: #f0f0f0; }
        .pod-importer-wrap .dropdown-item.is-destructive { color: #d63638; }
        .pod-importer-wrap .dropdown-toggle .dashicons { vertical-align: middle; }
        #pod-importer-notifier-container { position: fixed; top: 40px; right: 20px; z-index: 9999; }
        .pod-importer-notice { background: #fff; border-left: 4px solid #46b450; box-shadow: 0 1px 1px 0 rgba(0,0,0,.1); padding: 10px 20px; margin-bottom: 10px; opacity: 0; transition: opacity 0.3s, transform 0.3s; transform: translateX(100%); }
        .pod-importer-notice.show { opacity: 1; transform: translateX(0); }
        .pod-importer-notice.error { border-left-color: #dc3232; }
        .weekday-block { border-left: 3px solid #eee; padding-left: 10px; margin-bottom: 10px; }
        .time-item { display: flex; align-items: center; margin-bottom: 5px; }
        .time-item .remove-time { margin-left: 5px; }
        .ui-dialog-titlebar-close { float: right; }
        .ui-dialog .ui-dialog-buttonpane button .spinner { float: left; margin-right: 5px; }
    </style>
    <?php
});