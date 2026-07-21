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
        // CSV export streams a file and exits, so it runs on admin-post rather
        // than inside a settings-page render.
        add_action('admin_post_hrefl_csv_export', [$this, 'csv_export']);
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
            $this->pages[] = add_submenu_page('hrefl', __('CSV review', 'hrefl'), __('CSV review', 'hrefl'), 'manage_options', 'hrefl-csv', [$this, 'render_csv']);
            $this->pages[] = add_submenu_page('hrefl', __('Health', 'hrefl'), __('Health', 'hrefl'), 'manage_options', 'hrefl-health', [$this, 'render_health']);
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
                    <tr><th colspan="2"><h2 style="margin:0"><?php esc_html_e('AI matching (optional)', 'hrefl'); ?></h2>
                        <p class="description"><?php esc_html_e('All AI output is a proposal - a human still confirms every match. Metadata only (title/URL), never full page bodies.', 'hrefl'); ?></p></th></tr>
                    <tr><th><?php esc_html_e('AI provider (Tier C)', 'hrefl'); ?></th><td>
                        <select name="ai_provider">
                            <?php foreach (['' => 'None', 'copilot' => 'Microsoft Copilot', 'anthropic' => 'Anthropic'] as $k => $label): ?>
                                <option value="<?php echo esc_attr($k); ?>" <?php selected($s['ai_provider'], $k); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select></td></tr>
                    <tr><th><?php esc_html_e('AI endpoint', 'hrefl'); ?></th>
                        <td><input type="url" name="ai_endpoint" value="<?php echo esc_attr($s['ai_endpoint']); ?>" class="regular-text"></td></tr>
                    <tr><th><?php esc_html_e('AI model', 'hrefl'); ?></th>
                        <td><input type="text" name="ai_model" value="<?php echo esc_attr($s['ai_model']); ?>" class="regular-text"></td></tr>
                    <tr><th><?php esc_html_e('AI API key', 'hrefl'); ?></th>
                        <td><input type="password" name="ai_key" value="<?php echo esc_attr($s['ai_key']); ?>" class="regular-text" autocomplete="off">
                        <p class="description"><?php esc_html_e('Prefer HREFL_ANTHROPIC_KEY / HREFL_COPILOT_KEY in wp-config.php.', 'hrefl'); ?></p></td></tr>
                    <tr><th><?php esc_html_e('Embedding endpoint (Tier B)', 'hrefl'); ?></th>
                        <td><input type="url" name="embedding_endpoint" value="<?php echo esc_attr($s['embedding_endpoint']); ?>" class="regular-text" placeholder="self-hosted preferred"></td></tr>
                    <tr><th><?php esc_html_e('Embedding model', 'hrefl'); ?></th>
                        <td><input type="text" name="embedding_model" value="<?php echo esc_attr($s['embedding_model']); ?>" class="regular-text"></td></tr>
                    <tr><th><?php esc_html_e('Embedding API key', 'hrefl'); ?></th>
                        <td><input type="password" name="embedding_key" value="<?php echo esc_attr($s['embedding_key']); ?>" class="regular-text" autocomplete="off">
                        <p class="description"><?php esc_html_e('Optional; or HREFL_EMBEDDING_KEY in wp-config.php.', 'hrefl'); ?></p></td></tr>
                    <tr><th colspan="2"><h2 style="margin:0"><?php esc_html_e('Human review', 'hrefl'); ?></h2></th></tr>
                    <tr><th><?php esc_html_e('Auto-confirm mappings', 'hrefl'); ?></th>
                        <td><label><input type="checkbox" name="auto_confirm" value="1" <?php checked($s['auto_confirm']); ?>>
                        <?php esc_html_e('Skip manual review - publish matched mappings automatically', 'hrefl'); ?></label>
                        <p class="description"><strong><?php esc_html_e('Off by default (recommended): a human confirms every match before it goes live.', 'hrefl'); ?></strong>
                        <?php esc_html_e('When on, the hub auto-confirms each matched mapping on the next cron run. The correctness guard still applies - a mapping is only published once its target has validated (HTTP 200, indexable) and no other confirmed member in its group already uses that hreflang code - so a broken mapping can never go live. This removes the human-in-the-loop; enable it only once you trust the matching on your content.', 'hrefl'); ?></p></td></tr>
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
            'ai_provider'       => in_array($post['ai_provider'] ?? '', ['', 'anthropic', 'copilot'], true) ? (string) $post['ai_provider'] : '',
            'ai_endpoint'       => esc_url_raw($post['ai_endpoint'] ?? ''),
            'ai_model'          => sanitize_text_field($post['ai_model'] ?? ''),
            'ai_key'            => sanitize_text_field($post['ai_key'] ?? ''),
            'embedding_endpoint' => esc_url_raw($post['embedding_endpoint'] ?? ''),
            'embedding_model'    => sanitize_text_field($post['embedding_model'] ?? ''),
            'embedding_key'      => sanitize_text_field($post['embedding_key'] ?? ''),
            'auto_confirm'       => empty($post['auto_confirm']) ? 0 : 1,
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
        $actions = new Hrefl_Review_Actions($this->registry);
        if ($op === 'reject') {
            return $actions->reject($id)
                ? __('Mapping rejected.', 'hrefl')
                : __('Member not found.', 'hrefl');
        }
        if ($op === 'confirm') {
            $violations = $actions->confirm($id);
            return $violations
                ? sprintf(__('Cannot confirm: %s', 'hrefl'), implode(' ', $violations))
                : __('Mapping confirmed; it will publish on the next client sync.', 'hrefl');
        }
        return __('Unknown action.', 'hrefl');
    }

    /* ------------------------------------------------------------------ */
    /* CSV review round-trip                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Stream the review CSV as a download (admin-post handler).
     */
    public function csv_export(): void {
        if (!current_user_can('manage_options')
            || !isset($_GET['_wpnonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'hrefl_csv')) {
            wp_die(esc_html__('Unauthorized.', 'hrefl'));
        }
        $csv = (new Hrefl_Csv($this->registry, new Hrefl_Review_Actions($this->registry)))->export();
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="hrefl-mapping.csv"');
        header('Content-Length: ' . strlen($csv));
        echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput -- raw CSV body.
        exit;
    }

    /**
     * The CSV review page: download link + upload-to-apply form.
     */
    public function render_csv(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        $result = null;
        if (isset($_POST['hrefl_csv_nonce'])
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hrefl_csv_nonce'])), 'hrefl_csv_import')
            && isset($_FILES['hrefl_csv']) && is_array($_FILES['hrefl_csv'])) {
            $result = $this->handle_csv_upload($_FILES['hrefl_csv']);
        }
        $export_url = wp_nonce_url(admin_url('admin-post.php?action=hrefl_csv_export'), 'hrefl_csv');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Hreflang CSV review', 'hrefl'); ?></h1>
            <p><?php esc_html_e('Download every mapping, set the "decision" column to confirm / reject / leave, then upload the file back. Only confirmed rows go live, and each confirm passes the same correctness guard as the on-screen queue.', 'hrefl'); ?></p>

            <h2><?php esc_html_e('1. Download', 'hrefl'); ?></h2>
            <p><a class="button button-primary" href="<?php echo esc_url($export_url); ?>"><?php esc_html_e('Export mapping CSV', 'hrefl'); ?></a></p>

            <h2><?php esc_html_e('2. Review &amp; upload', 'hrefl'); ?></h2>
            <?php if ($result !== null) : ?>
                <div class="notice notice-info"><p>
                    <?php echo esc_html(sprintf(
                        /* translators: 1: applied count, 2: skipped count, 3: blocked count. */
                        __('Applied %1$d, left unchanged %2$d, blocked %3$d.', 'hrefl'),
                        $result['applied'],
                        $result['skipped'],
                        count($result['blocked'])
                    )); ?>
                </p>
                <?php if (!empty($result['blocked'])) : ?>
                    <ul style="list-style:disc;margin-left:2em;">
                    <?php foreach ($result['blocked'] as $url => $why) : ?>
                        <li><code><?php echo esc_html((string) $url); ?></code> - <?php echo esc_html(implode(' ', (array) $why)); ?></li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                </div>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('hrefl_csv_import', 'hrefl_csv_nonce'); ?>
                <input type="file" name="hrefl_csv" accept=".csv,text/csv" required />
                <?php submit_button(__('Upload &amp; apply decisions', 'hrefl')); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Validate an uploaded CSV and apply it.
     *
     * @param array<string,mixed> $file
     *   A single $_FILES entry.
     *
     * @return array{applied:int,skipped:int,blocked:array<string,string[]>}
     */
    private function handle_csv_upload(array $file): array {
        $empty = ['applied' => 0, 'skipped' => 0, 'blocked' => []];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $empty;
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        // Only accept a genuine PHP upload, and cap the size to a sane ceiling.
        if ($tmp === '' || !is_uploaded_file($tmp) || (int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
            return $empty;
        }
        $csv = (string) file_get_contents($tmp);
        if (trim($csv) === '') {
            return $empty;
        }
        return (new Hrefl_Csv($this->registry, new Hrefl_Review_Actions($this->registry)))->import($csv);
    }

    /* ------------------------------------------------------------------ */
    /* Health dashboard                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Render the translation-graph health report.
     */
    public function render_health(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        $r = (new Hrefl_Monitor($this->registry))->report();
        $t = $r['totals'];
        $i = $r['issues'];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Hreflang health', 'hrefl'); ?></h1>
            <p>
                <?php if ($r['healthy']) : ?>
                    <span style="color:#227122;font-weight:600;">&#10003; <?php esc_html_e('Healthy - no structural issues found.', 'hrefl'); ?></span>
                <?php else : ?>
                    <span style="color:#a00;font-weight:600;">&#9888; <?php esc_html_e('Issues found - see below. Fix them in the review queue or CSV.', 'hrefl'); ?></span>
                <?php endif; ?>
            </p>

            <h2><?php esc_html_e('Coverage', 'hrefl'); ?></h2>
            <p><strong><?php echo esc_html(number_format((float) $r['coverage'] * 100, 1)); ?>%</strong>
               <?php esc_html_e('of eligible members are confirmed with a valid target.', 'hrefl'); ?></p>

            <h2><?php esc_html_e('Totals', 'hrefl'); ?></h2>
            <table class="widefat striped" style="max-width:32em;">
                <tbody>
                    <tr><td><?php esc_html_e('Groups', 'hrefl'); ?></td><td><?php echo (int) $t['groups']; ?></td></tr>
                    <tr><td><?php esc_html_e('Members', 'hrefl'); ?></td><td><?php echo (int) $t['members']; ?></td></tr>
                    <tr><td><?php esc_html_e('Confirmed', 'hrefl'); ?></td><td><?php echo (int) $t['confirmed']; ?></td></tr>
                    <tr><td><?php esc_html_e('Proposed', 'hrefl'); ?></td><td><?php echo (int) $t['proposed']; ?></td></tr>
                    <tr><td><?php esc_html_e('Rejected', 'hrefl'); ?></td><td><?php echo (int) $t['rejected']; ?></td></tr>
                </tbody>
            </table>

            <h2><?php esc_html_e('Issues', 'hrefl'); ?></h2>
            <table class="widefat striped" style="max-width:40em;">
                <tbody>
                    <tr><td><?php esc_html_e('Confirmed targets that failed validation', 'hrefl'); ?></td><td><?php echo count($i['invalid_targets']); ?></td></tr>
                    <tr><td><?php esc_html_e('hreflang code collisions in a group', 'hrefl'); ?></td><td><?php echo count($i['code_collisions']); ?></td></tr>
                    <tr><td><?php esc_html_e('Groups with no x-default (no Global member)', 'hrefl'); ?></td><td><?php echo count($i['missing_x_default']); ?></td></tr>
                    <tr><td><?php esc_html_e('Confirmed members with nothing to link to', 'hrefl'); ?></td><td><?php echo count($i['lonely_confirmed']); ?></td></tr>
                </tbody>
            </table>

            <?php if (!empty($i['invalid_targets'])) : ?>
                <h3><?php esc_html_e('Invalid targets', 'hrefl'); ?></h3>
                <ul style="list-style:disc;margin-left:2em;">
                    <?php foreach (array_slice($i['invalid_targets'], 0, 50) as $bad) : ?>
                        <li><code><?php echo esc_html($bad['hreflang']); ?></code> - <?php echo esc_html($bad['url']); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if (!empty($i['code_collisions'])) : ?>
                <h3><?php esc_html_e('Code collisions', 'hrefl'); ?></h3>
                <ul style="list-style:disc;margin-left:2em;">
                    <?php foreach (array_slice($i['code_collisions'], 0, 50) as $c) : ?>
                        <li><code><?php echo esc_html((string) $c['hreflang']); ?></code>: <?php echo esc_html(implode(', ', (array) $c['urls'])); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
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
