<?php
/*
Plugin Name: RSS AI Hybrid SEO Auto Poster
Description: RSS + AI rewrite + SEO optimization auto post
Version: 3.0
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
    if (!wp_next_scheduled('rss_ai_cron')) {
        wp_schedule_event(time(), 'fifteen_min', 'rss_ai_cron');
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('rss_ai_cron');
});

/* =========================
   ADMIN MENU
========================= */

add_action('admin_menu', function () {
    add_menu_page(
        'RSS AI SEO',
        'RSS AI SEO',
        'manage_options',
        'rss-ai-seo',
        'rss_ai_settings_page'
    );
});

function rss_ai_settings_page() {
    ?>
    <div class="wrap">
        <h1>RSS AI Hybrid SEO</h1>

        <form method="post" action="options.php">
            <?php
            settings_fields('rss_ai_group');
            ?>

            <table class="form-table">

                <tr>
                    <th>OpenAI API Key</th>
                    <td>
                        <input type="text" name="rss_ai_key" value="<?php echo esc_attr(get_option('rss_ai_key')); ?>" size="60">
                    </td>
                </tr>

                <tr>
                    <th>RSS Feed</th>
                    <td>
                        <textarea name="rss_feeds" rows="6" cols="70"><?php echo esc_textarea(get_option('rss_feeds')); ?></textarea>
                    </td>
                </tr>

                <tr>
                    <th>Include Keyword</th>
                    <td>
                        <input type="text" name="rss_include" value="<?php echo esc_attr(get_option('rss_include')); ?>">
                    </td>
                </tr>

                <tr>
                    <th>Exclude Keyword</th>
                    <td>
                        <input type="text" name="rss_exclude" value="<?php echo esc_attr(get_option('rss_exclude')); ?>">
                    </td>
                </tr>

                <tr>
                    <th>Status</th>
                    <td>
                        <select name="rss_status">
                            <option value="publish" <?php selected(get_option('rss_status'), 'publish'); ?>>Publish</option>
                            <option value="draft" <?php selected(get_option('rss_status'), 'draft'); ?>>Draft</option>
                        </select>
                    </td>
                </tr>

            </table>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

/* =========================
   REGISTER SETTINGS
========================= */

add_action('admin_init', function () {
    register_setting('rss_ai_group', 'rss_ai_key');
    register_setting('rss_ai_group', 'rss_feeds');
    register_setting('rss_ai_group', 'rss_include');
    register_setting('rss_ai_group', 'rss_exclude');
    register_setting('rss_ai_group', 'rss_status');
});

/* =========================
   AI REWRITE FUNCTION
========================= */

function rss_ai_rewrite($title, $content) {

    $api_key = get_option('rss_ai_key');
    if (!$api_key) return $content;

    $prompt = "
Rewrite artikel berikut menjadi artikel SEO-friendly bahasa Indonesia.

Judul: $title

Isi:
$content

Rules:
- Buat 600–1200 kata
- Gunakan heading H2 H3
- SEO friendly
- Jangan copy 100%
- Tambahkan penjelasan tambahan
- Natural bahasa Indonesia
";

    $response = wp_remote_post("https://api.openai.com/v1/chat/completions", [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json'
        ],
        'body' => json_encode([
            'model' => 'gpt-5-mini',
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ]
        ]),
        'timeout' => 60
    ]);

    if (is_wp_error($response)) return $content;

    $body = json_decode(wp_remote_retrieve_body($response), true);

    return $body['choices'][0]['message']['content'] ?? $content;
}

/* =========================
   MAIN ENGINE
========================= */

add_action('rss_ai_cron', function () {

    include_once ABSPATH . WPINC . '/feed.php';

    $feeds   = explode("\n", get_option('rss_feeds'));
    $include = strtolower(get_option('rss_include'));
    $exclude = strtolower(get_option('rss_exclude'));
    $status  = get_option('rss_status', 'publish');

    foreach ($feeds as $feed_url) {

        $feed_url = trim($feed_url);
        if (!$feed_url) continue;

        $rss = fetch_feed($feed_url);
        if (is_wp_error($rss)) continue;

        $items = $rss->get_items(0, 3);

        foreach ($items as $item) {

            $title = wp_strip_all_tags($item->get_title());
            $lower = strtolower($title);

            // DUPLIKAT
            if (get_page_by_title($title, OBJECT, 'post')) continue;

            // FILTER
            if ($include && strpos($lower, $include) === false) continue;
            if ($exclude && strpos($lower, $exclude) !== false) continue;

            $content = $item->get_description();
            $link = $item->get_permalink();

            // AI REWRITE
            $content = rss_ai_rewrite($title, $content);

            // SEO TITLE
            $seo_title = $title . " - Update Terbaru & Analisis Lengkap";

            // INTERNAL LINK SIMPLE
            $content .= "<p><a href='/artikel-terkait'>Artikel terkait</a></p>";
            $content .= "<p>Sumber: <a href='$link' target='_blank'>Klik di sini</a></p>";

            // FEATURED IMAGE
            $image = '';
            if ($enclosure = $item->get_enclosure()) {
                $image = $enclosure->get_link();
            }

            if ($image) {
                $content = "<img src='$image' style='max-width:100%;height:auto;' /><br>" . $content;
            }

            wp_insert_post([
                'post_title'   => $seo_title,
                'post_content' => $content,
                'post_status'  => $status,
                'post_author'  => 1
            ]);
        }
    }
});
