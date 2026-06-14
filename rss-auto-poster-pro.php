<?php
/*
Plugin Name: RSS Auto Poster PRO
Description: Auto posting RSS feed advanced tanpa AI
Version: 2.0
Author: Jemma Build
*/

if (!defined('ABSPATH')) exit;

/* =========================
   CRON 15 MENIT
========================= */

add_filter('cron_schedules', function ($schedules) {
    $schedules['fifteen_min'] = [
        'interval' => 900,
        'display'  => 'Every 15 Minutes'
    ];
    return $schedules;
});

register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('rap_pro_cron')) {
        wp_schedule_event(time(), 'fifteen_min', 'rap_pro_cron');
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('rap_pro_cron');
});

/* =========================
   ADMIN MENU
========================= */

add_action('admin_menu', function () {
    add_menu_page(
        'RSS Poster PRO',
        'RSS PRO',
        'manage_options',
        'rss-pro',
        'rap_pro_settings'
    );
});

function rap_pro_settings() {
    ?>
    <div class="wrap">
        <h1>RSS Auto Poster PRO</h1>

        <form method="post" action="options.php">
            <?php
            settings_fields('rap_pro_group');
            ?>

            <table class="form-table">

                <tr>
                    <th>RSS Feed List</th>
                    <td>
                        <textarea name="rap_feeds" rows="8" cols="70"><?php echo esc_textarea(get_option('rap_feeds')); ?></textarea>
                        <p>1 URL per baris</p>
                    </td>
                </tr>

                <tr>
                    <th>Include Keyword</th>
                    <td>
                        <input type="text" name="rap_include" value="<?php echo esc_attr(get_option('rap_include')); ?>">
                        <p>Contoh: crypto,tech,news</p>
                    </td>
                </tr>

                <tr>
                    <th>Exclude Keyword</th>
                    <td>
                        <input type="text" name="rap_exclude" value="<?php echo esc_attr(get_option('rap_exclude')); ?>">
                        <p>Contoh: sport,politics</p>
                    </td>
                </tr>

                <tr>
                    <th>Status Post</th>
                    <td>
                        <select name="rap_status">
                            <option value="publish" <?php selected(get_option('rap_status'), 'publish'); ?>>Publish</option>
                            <option value="draft" <?php selected(get_option('rap_status'), 'draft'); ?>>Draft</option>
                        </select>
                    </td>
                </tr>

                <tr>
                    <th>Max Post per Feed</th>
                    <td>
                        <input type="number" name="rap_limit" value="<?php echo esc_attr(get_option('rap_limit', 3)); ?>">
                    </td>
                </tr>

            </table>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

/* =========================
   SAVE SETTINGS
========================= */

add_action('admin_init', function () {
    register_setting('rap_pro_group', 'rap_feeds');
    register_setting('rap_pro_group', 'rap_include');
    register_setting('rap_pro_group', 'rap_exclude');
    register_setting('rap_pro_group', 'rap_status');
    register_setting('rap_pro_group', 'rap_limit');
});

/* =========================
   MAIN ENGINE
========================= */

add_action('rap_pro_cron', function () {

    include_once ABSPATH . WPINC . '/feed.php';

    $feeds   = explode("\n", get_option('rap_feeds'));
    $include = explode(",", strtolower(get_option('rap_include')));
    $exclude = explode(",", strtolower(get_option('rap_exclude')));
    $status  = get_option('rap_status', 'publish');
    $limit   = intval(get_option('rap_limit', 3));

    foreach ($feeds as $feed_url) {

        $feed_url = trim($feed_url);
        if (!$feed_url) continue;

        $rss = fetch_feed($feed_url);
        if (is_wp_error($rss)) continue;

        $items = $rss->get_items(0, $limit);

        foreach ($items as $item) {

            $title = wp_strip_all_tags($item->get_title());
            $title_lower = strtolower($title);

            $guid = $item->get_id();

            // DUPLIKAT CHECK
            if (get_page_by_title($title, OBJECT, 'post')) continue;

            // FILTER EXCLUDE
            foreach ($exclude as $ex) {
                if ($ex && strpos($title_lower, trim($ex)) !== false) {
                    continue 2;
                }
            }

            // FILTER INCLUDE (jika diisi)
            if (!empty(trim(get_option('rap_include')))) {
                $ok = false;
                foreach ($include as $in) {
                    if ($in && strpos($title_lower, trim($in)) !== false) {
                        $ok = true;
                        break;
                    }
                }
                if (!$ok) continue;
            }

            $content = $item->get_description();
            $link = $item->get_permalink();

            // AUTO TAG dari judul
            $tags = explode(" ", $title);
            $tag_ids = [];

            foreach ($tags as $t) {
                if (strlen($t) > 4) {
                    $tag = wp_insert_term($t, 'post_tag');
                    if (!is_wp_error($tag)) {
                        $tag_ids[] = $tag['term_id'];
                    }
                }
            }

            // FEATURED IMAGE (jika ada di RSS)
            $image = '';
            if ($enclosure = $item->get_enclosure()) {
                $image = $enclosure->get_link();
            }

            if ($image) {
                $content = "<img src='$image' style='max-width:100%;height:auto;' /><br>" . $content;
            }

            $content .= "<p><a href='$link' target='_blank'>Sumber asli</a></p>";

            $post_id = wp_insert_post([
                'post_title'   => $title,
                'post_content' => $content,
                'post_status'  => $status,
                'post_author'  => 1,
            ]);

            if (!is_wp_error($post_id) && $tag_ids) {
                wp_set_post_terms($post_id, $tag_ids, 'post_tag');
            }
        }
    }
});
