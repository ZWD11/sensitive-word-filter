<?php
/*
Plugin Name: Sensitive Word Filter
Description: 检测和替换敏感词或违禁词。
Version: 1.0
Author: WenSong
*/

if (!defined('ABSPATH')) {
    exit; // 防止直接访问
}

// 注册激活钩子
register_activation_hook(__FILE__, 'swf_activate');
function swf_activate() {
    // 插件激活时执行的代码
}

// 注册停用钩子
register_deactivation_hook(__FILE__, 'swf_deactivate');
function swf_deactivate() {
    // 插件停用时执行的代码
}

// 添加设置页面
add_action('admin_menu', 'swf_add_admin_menu');
function swf_add_admin_menu() {
    add_options_page('Sensitive Word Filter', 'Sensitive Word Filter', 'manage_options', 'sensitive-word-filter', 'swf_options_page');
}

function swf_options_page() {
    ?>
    <div class="wrap">
        <h1>Sensitive Word Filter</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('swf_settings_group');
            do_settings_sections('sensitive-word-filter');
            submit_button();
            ?>
        </form>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="swf_scan_posts">
            <?php submit_button('Scan Existing Posts for Sensitive Words'); ?>
        </form>
    </div>
    <?php
}

// 注册设置
add_action('admin_init', 'swf_settings_init');
function swf_settings_init() {
    register_setting('swf_settings_group', 'swf_settings');

    add_settings_section(
        'swf_settings_section',
        __('Sensitive Word Settings', 'wordpress'),
        null,
        'sensitive-word-filter'
    );

    add_settings_field(
        'swf_sensitive_words',
        __('Sensitive Words', 'wordpress'),
        'swf_sensitive_words_render',
        'sensitive-word-filter',
        'swf_settings_section'
    );
}

function swf_sensitive_words_render() {
    $options = get_option('swf_settings');
    ?>
    <textarea cols='60' rows='15' name='swf_settings[swf_sensitive_words]'><?php echo isset($options['swf_sensitive_words']) ? esc_textarea($options['swf_sensitive_words']) : ''; ?></textarea>
    <p>输入敏感词，多个词请用逗号分隔。</p>
    <?php
}

// 过滤内容并处理敏感词
function swf_filter_sensitive_words($content) {
    $options = get_option('swf_settings');
    if (isset($options['swf_sensitive_words'])) {
        $sensitive_words = explode(',', $options['swf_sensitive_words']);
        foreach ($sensitive_words as $word) {
            $word = trim($word);
            if (!empty($word)) {
                $content = str_ireplace($word, '***', $content);
            }
        }
    }
    return $content;
}

// 针对发布内容的钩子
add_action('save_post', 'swf_check_post_content');
function swf_check_post_content($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $post = get_post($post_id);
    if ($post->post_type == 'revision') return;

    $content = $post->post_content;

    $filtered_content = swf_filter_sensitive_words($content);

    // 更新内容
    if ($filtered_content != $content) {
        remove_action('save_post', 'swf_check_post_content');
        wp_update_post(array('ID' => $post_id, 'post_content' => $filtered_content));
        add_action('save_post', 'swf_check_post_content');
    }
}

// 过滤搜索查询
add_filter('get_search_query', 'swf_filter_search_query');
function swf_filter_search_query($query) {
    return swf_filter_sensitive_words($query);
}

// 处理搜索请求
add_action('pre_get_posts', 'swf_handle_search_query');
function swf_handle_search_query($query) {
    if ($query->is_search && !is_admin()) {
        $query->set('s', swf_filter_sensitive_words($query->get('s')));
    }
}

// 处理扫描现有文章的请求
add_action('admin_post_swf_scan_posts', 'swf_scan_existing_posts');
function swf_scan_existing_posts() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $args = array(
        'posts_per_page' => -1,
        'post_type' => 'post',
        'post_status' => 'any'
    );

    $posts = get_posts($args);
    $found_sensitive_words = false;

    foreach ($posts as $post) {
        $content = $post->post_content;
        $filtered_content = swf_filter_sensitive_words($content);

        if ($filtered_content != $content) {
            $found_sensitive_words = true;
            wp_update_post(array('ID' => $post->ID, 'post_content' => $filtered_content));
        }
    }

    if ($found_sensitive_words) {
        wp_redirect(admin_url('options-general.php?page=sensitive-word-filter&scanned=1&found=1'));
    } else {
        wp_redirect(admin_url('options-general.php?page=sensitive-word-filter&scanned=1&found=0'));
    }
    exit;
}

// 添加扫描完成的通知
add_action('admin_notices', 'swf_scan_notice');
function swf_scan_notice() {
    if (isset($_GET['scanned']) && $_GET['scanned'] == 1) {
        if (isset($_GET['found']) && $_GET['found'] == 1) {
            echo '<div class="notice notice-success is-dismissible"><p>扫描完成，发现敏感词，敏感词已被替换。</p></div>';
        } else {
            echo '<div class="notice notice-success is-dismissible"><p>扫描完成，暂未发现敏感词。</p></div>';
        }
    }
}
?>
