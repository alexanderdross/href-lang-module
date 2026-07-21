<?php
/**
 * Admin UI: settings page + review queue (confirm/reject).
 *
 * Mirrors the Drupal client/hub settings forms and the review queue, condensed
 * into WordPress admin pages.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Hrefl_Admin {

    /**
     * Screen hook suffixes of this plugin's own admin pages.
     *
     * @var string[]
     */
    private array $pages = [];

    public function __construct(private Hrefl_Registry $registry) {}

    public function register(): void {
        add_action('admin_menu', [$this, 'menu']);
        add_filter('admin_footer_text', [$this, 'footer_text']);
    }

    public function menu(): void {
        $this->pages[] = add_menu_page(
            __('Hreflang', 'hrefl'),
            __('Hreflang', 'hrefl'),
            'manage_options',
            'hrefl',
            [$this, 'render_settings'],
            'dashicons-translation'
        );
        if (Hrefl_Settings::is_hub()) {
            $this->pages[] = add_submenu_page('hrefl', __('Review queue', 'hrefl'), __('Review queue', 'hrefl'), 'manage_options', 'hrefl-review', [$this, 'render_review']);
        }
    }

    /**
     * Replaces the admin footer text with a Dross:Media credit - only on this
     * plugin's own pages, so the rest of wp-admin is untouched.
     */
    public function footer_text($text) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && in_array($screen->id, $this->pages, true)) {
            return 'Made with &hearts; by <a href="https://dross.net/media/?hreflang" target="_blank" rel="noopener">Dross:Media</a>';
        }
        return $text;
    }

    /* ------------------------------------------------------------------ */
    /* Settings                                                            */
    /* ------------------------------------------------------------------ */

    public function render_settings(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (isset($_POST['hrefl_settings_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hrefl_settings_nonce'])), 'hrefl_settings')) {
            $this->save_settings();
            echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved.', 'hrefl') . '</p></div>';
        }
        $s = Hrefl_Settings::all();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Hreflang Cross-Site', 'hrefl'); ?></h1>
            <form method="post">
                <?php wp_nonce_field('hrefl_settings', 'hrefl_settings_nonce'); ?>
                <table class="form-table" role="presentation">
                    <tr><th><?php esc_html_e('Role', 'hrefl'); ?></th><td>
                        <select name="role">
                            <?php foreach (['both' => 'Client + Hub (main site)', 'client' => 'Client only', 'hub' => 'Hub only'] as $k => $label): ?>
                                <option value="<?php echo esc_attr($k); ?>" <?php selected($s['role'], $k); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>
                    <tr><th><?php esc_html_e('This market key', 'hrefl'); ?></th>
                        <td><input type="text" name="market" value="<?php echo esc_attr($s['market']); ?>" class="regular-text" placeholder="global, de, us…"></td></tr>
                    <tr><th><?php esc_html_e('Hub REST URL', 'hrefl'); ?></th>
                        <td><input type="url" name="hub_url" value="<?php echo esc_attr($s['hub_url']); ?>" class="regular-text" placeholder="https://main-site/wp-json/hrefl/v1"></td></tr>
                    <tr><th><?php esc_html_e('Shared secret', 'hrefl'); ?></th>
                        <td><input type="password" name="secret" value="<?php echo esc_attr($s['secret']); ?>" class="regular-text" autocomplete="off">
                        <p class="description"><?php esc_html_e('Prefer defining HREFL_HUB_SECRET in wp-config.php. Same value on hub and every client.', 'hrefl'); ?></p></td></tr>
                    <tr><th><?php esc_html_e('Emit hreflang head tags', 'hrefl'); ?></th>
                        <td><label><input type="checkbox" name="emit_head" value="1" <?php checked($s['emit_head']); ?>> <?php esc_html_e('on', 'hrefl'); ?></label></td></tr>
                    <tr><th><?php esc_html_e('Serve sitemap at /hrefl-sitemap.xml', 'hrefl'); ?></th>
                        <td><label><input type="checkbox" name="sitemap_enabled" value="1" <?php checked($s['sitemap_enabled']); ?>> <?php esc_html_e('on', 'hrefl'); ?></label></td></tr>
                    <tr><th><?php esc_html_e('Language map (langcode|hreflang)', 'hrefl'); ?></th>
                        <td><textarea name="lang_map" rows="3" class="large-text code"><?php echo esc_textarea($this->encode_map($s['lang_map'])); ?></textarea>
                        <p class="description">e.g. <code>en|en-US</code></p></td></tr>
                    <?php if (Hrefl_Settings::is_hub()): ?>
                    <tr><th><?php esc_html_e('Markets (market|prefix)', 'hrefl'); ?></th>
                        <td><textarea name="markets" rows="5" class="large-text code"><?php echo esc_textarea($this->encode_markets($s['markets'])); ?></textarea>
                        <p class="description">One per line. A path or a whole domain, e.g. <code>de|https://host/de/</code> or <code>es|https://host.es/</code>.</p></td></tr>
                    <?php endif; ?>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    private function save_settings(): void {
        $post = wp_unslash($_POST);
        Hrefl_Settings::save([
            'role'            => in_array($post['role'] ?? 'both', ['both', 'client', 'hub'], true) ? $post['role'] : 'both',
            'market'          => sanitize_text_field($post['market'] ?? 'global'),
            'hub_url'         => esc_url_raw($post['hub_url'] ?? ''),
            'secret'          => sanitize_text_field($post['secret'] ?? ''),
            'emit_head'       => empty($post['emit_head']) ? 0 : 1,
            'sitemap_enabled' => empty($post['sitemap_enabled']) ? 0 : 1,
            'lang_map'        => $this->decode_map((string) ($post['lang_map'] ?? '')),
            'markets'         => $this->decode_markets((string) ($post['markets'] ?? '')),
        ]);
        flush_rewrite_rules(false);
    }

    /* ------------------------------------------------------------------ */
    /* Review queue                                                        */
    /* ------------------------------------------------------------------ */

    public function render_review(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (isset($_GET['hrefl_action'], $_GET['member'], $_GET['_wpnonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'hrefl_review')) {
            $id = (int) $_GET['member'];
            $op = sanitize_text_field(wp_unslash($_GET['hrefl_action']));
            $msg = $this->act($id, $op);
            echo '<div class="notice notice-info"><p>' . esc_html($msg) . '</p></div>';
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Hreflang review queue', 'hrefl'); ?></h1>
            <p><?php esc_html_e('Proposed cross-market mappings. Confirm publishes on the next client sync; only valid, non-conflicting members can be confirmed.', 'hrefl'); ?></p>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e('Market', 'hrefl'); ?></th>
                    <th>hreflang</th>
                    <th><?php esc_html_e('URL', 'hrefl'); ?></th>
                    <th><?php esc_html_e('Title', 'hrefl'); ?></th>
                    <th><?php esc_html_e('Valid', 'hrefl'); ?></th>
                    <th><?php esc_html_e('Actions', 'hrefl'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($this->registry->all_needing_review() as $m):
                    $id = (int) $m['id'];
                    $confirm = wp_nonce_url(admin_url('admin.php?page=hrefl-review&hrefl_action=confirm&member=' . $id), 'hrefl_review');
                    $reject  = wp_nonce_url(admin_url('admin.php?page=hrefl-review&hrefl_action=reject&member=' . $id), 'hrefl_review'); ?>
                    <tr>
                        <td><?php echo esc_html($m['market']); ?></td>
                        <td><?php echo esc_html($m['hreflang']); ?></td>
                        <td><a href="<?php echo esc_url($m['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($m['url']); ?></a></td>
                        <td><?php echo esc_html((string) $m['title']); ?></td>
                        <td><?php echo ((int) $m['valid'] === 1) ? '✔' : '-'; ?></td>
                        <td>
                            <a class="button button-primary" href="<?php echo esc_url($confirm); ?>"><?php esc_html_e('Confirm', 'hrefl'); ?></a>
                            <a class="button" href="<?php echo esc_url($reject); ?>"><?php esc_html_e('Reject', 'hrefl'); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Apply a confirm/reject with the correctness guard.
     */
    private function act(int $id, string $op): string {
        $member = $this->registry->load_member($id);
        if (!$member) {
            return __('Member not found.', 'hrefl');
        }
        if ($op === 'reject') {
            $this->registry->set_status($id, 'rejected');
            return __('Mapping rejected.', 'hrefl');
        }
        if ($op === 'confirm') {
            if ((int) $member['valid'] !== 1) {
                return __('Cannot confirm: target is not validated.', 'hrefl');
            }
            foreach ($this->registry->members_of_group((string) $member['group_id']) as $sib) {
                if ((int) $sib['id'] !== $id && $sib['status'] === 'confirmed' && $sib['hreflang'] === $member['hreflang']) {
                    return __('Cannot confirm: another confirmed member already uses this hreflang code.', 'hrefl');
                }
            }
            $this->registry->set_status($id, 'confirmed');
            return __('Mapping confirmed; it will publish on the next client sync.', 'hrefl');
        }
        return __('Unknown action.', 'hrefl');
    }

    /* ------------------------------------------------------------------ */
    /* Encoders                                                            */
    /* ------------------------------------------------------------------ */

    private function encode_map(array $map): string {
        $lines = [];
        foreach ($map as $k => $v) {
            if ($k !== '') {
                $lines[] = $k . '|' . $v;
            }
        }
        return implode("\n", $lines);
    }

    private function decode_map(string $text): array {
        $map = [];
        foreach (preg_split('/\r?\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '|')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode('|', $line, 2));
            if ($k !== '') {
                $map[sanitize_key($k)] = sanitize_text_field($v);
            }
        }
        return $map;
    }

    private function encode_markets(array $markets): string {
        $lines = [];
        foreach ($markets as $key => $def) {
            $lines[] = $key . '|' . ($def['prefix'] ?? '');
        }
        return implode("\n", $lines);
    }

    private function decode_markets(string $text): array {
        $markets = [];
        foreach (preg_split('/\r?\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '|')) {
                continue;
            }
            [$key, $prefix] = array_map('trim', explode('|', $line, 2));
            if ($key !== '') {
                $markets[sanitize_key($key)] = ['prefix' => esc_url_raw($prefix)];
            }
        }
        return $markets;
    }
}
