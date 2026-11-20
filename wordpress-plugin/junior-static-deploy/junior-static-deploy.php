<?php
/**
 * Plugin Name: Junior Static Deploy
 * Description: Publie le site statique du dépôt GitHub JuniorUTBM/Website à la racine du site, à chaque push sur main. WordPress reste installé en arrière-plan (administration uniquement).
 * Version:     1.0.0
 * Requires PHP: 8.0
 * Author:      Junior UTBM
 */

// Pull-based deploy: GitHub pings /index.php?rest_route=/junior-deploy/v1/sync, the plugin asks GitHub
// for the head of main and mirrors that commit into ABSPATH. Only the head of main of a fixed repo can
// ever be deployed, so the ping endpoint needs no secret.

defined('ABSPATH') || exit;

final class Junior_Static_Deploy
{
    const REPO = 'JuniorUTBM/Website';
    const BRANCH = 'main';
    const MARKER = 'Junior Static';
    const OPT_STATE = 'jsd_state';       // deployed sha, time, manifest, last log
    const OPT_LOCK = 'jsd_lock';
    const OPT_LAST_PING = 'jsd_last_ping';
    const PING_INTERVAL = 20;            // seconds between accepted public pings
    const LOCK_TTL = 600;

    // Repository files that are tooling, not website.
    const REPO_EXCLUDES = ['.github/', 'wordpress-plugin/', 'README.md', '.nojekyll', '.gitignore', 'CNAME', 'LICENSE', '.htaccess'];

    // WordPress paths the deploy must never write or delete.
    const PROTECTED_DIRS = ['wp-admin/', 'wp-includes/', 'wp-content/', '.well-known/'];
    const PROTECTED_ROOT_FILE = '/^(wp-[^\/]*\.php|index\.php|xmlrpc\.php|\.htaccess|\.user\.ini|php\.ini|web\.config|license\.txt|readme\.html)$/i';

    public static function boot(): void
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('admin_menu', [self::class, 'admin_menu']);
        add_action('admin_post_jsd_deploy', [self::class, 'handle_admin_deploy']);
        add_action('admin_post_jsd_remove', [self::class, 'handle_admin_remove']);
        add_action('jsd_hourly', [self::class, 'cron_sync']);
        register_activation_hook(__FILE__, [self::class, 'activate']);
        register_deactivation_hook(__FILE__, [self::class, 'deactivate']);
    }

    public static function activate(): void
    {
        if (!wp_next_scheduled('jsd_hourly')) {
            wp_schedule_event(time() + 300, 'hourly', 'jsd_hourly');
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('jsd_hourly');
    }

    // ---------------------------------------------------------------- REST

    public static function register_routes(): void
    {
        register_rest_route('junior-deploy/v1', '/sync', [
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => [self::class, 'rest_sync'],
        ]);
        register_rest_route('junior-deploy/v1', '/status', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => fn () => ['sha' => self::state()['sha'] ?? null, 'deployed_at' => self::state()['time'] ?? null],
        ]);
    }

    public static function rest_sync(WP_REST_Request $request)
    {
        $last = (int) get_option(self::OPT_LAST_PING, 0);
        if (time() - $last < self::PING_INTERVAL) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Trop de requêtes, réessayez dans quelques secondes.'], 429);
        }
        update_option(self::OPT_LAST_PING, time(), false);

        // The caller may say which commit it expects; it is only used to wait for GitHub's API to catch up.
        $expected = (string) $request->get_param('sha');
        $expected = preg_match('/^[0-9a-f]{40}$/', $expected) ? $expected : null;

        $result = self::sync($expected, false);
        return new WP_REST_Response($result, $result['ok'] ? 200 : 500);
    }

    public static function cron_sync(): void
    {
        self::sync(null, false);
    }

    // -------------------------------------------------------------- Deploy

    /** @return array{ok: bool, ...} */
    public static function sync(?string $expected_sha, bool $force): array
    {
        if (!add_option(self::OPT_LOCK, time(), '', false)) {
            if (time() - (int) get_option(self::OPT_LOCK) < self::LOCK_TTL) {
                return ['ok' => false, 'error' => 'Un déploiement est déjà en cours.'];
            }
            update_option(self::OPT_LOCK, time(), false); // stale lock from a crashed run
        }

        try {
            @set_time_limit(300);
            $sha = self::head_sha($expected_sha);
            $state = self::state();
            if (!$force && ($state['sha'] ?? null) === $sha) {
                return ['ok' => true, 'sha' => $sha, 'unchanged' => true];
            }
            $result = self::deploy($sha);
        } catch (Throwable $e) {
            $result = ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            delete_option(self::OPT_LOCK);
        }

        $state = self::state();
        $state['log'] = ['time' => time()] + $result;
        update_option(self::OPT_STATE, $state, false);
        return $result;
    }

    private static function head_sha(?string $expected): string
    {
        // GitHub's API can lag a few seconds behind a push: wait for the expected commit if one was given.
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $response = wp_remote_get('https://api.github.com/repos/' . self::REPO . '/commits/' . self::BRANCH, [
                'timeout' => 20,
                'headers' => ['Accept' => 'application/vnd.github.sha', 'User-Agent' => 'junior-static-deploy'],
            ]);
            if (is_wp_error($response)) {
                throw new RuntimeException('GitHub injoignable depuis le serveur : ' . $response->get_error_message());
            }
            $sha = trim((string) wp_remote_retrieve_body($response));
            if (!preg_match('/^[0-9a-f]{40}$/', $sha)) {
                throw new RuntimeException('Réponse inattendue de GitHub (HTTP ' . wp_remote_retrieve_response_code($response) . ').');
            }
            if ($expected === null || $sha === $expected) {
                return $sha;
            }
            sleep(5);
        }
        return $sha; // main moved on past $expected: deploy the newer head
    }

    private static function deploy(string $sha): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException("L'extension PHP zip est absente du serveur.");
        }
        if (!wp_is_writable(ABSPATH)) {
            throw new RuntimeException("Le dossier racine du site n'est pas accessible en écriture.");
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $zip_path = download_url('https://codeload.github.com/' . self::REPO . '/zip/' . $sha, 120);
        if (is_wp_error($zip_path)) {
            throw new RuntimeException('Téléchargement impossible : ' . $zip_path->get_error_message());
        }

        try {
            $files = self::read_zip($zip_path);
        } finally {
            @unlink($zip_path);
        }
        if (!isset($files['index.html'])) {
            throw new RuntimeException("L'archive ne contient pas d'index.html à la racine : déploiement annulé.");
        }

        $previous_htaccess = self::read_htaccess();

        foreach ($files as $relative => $contents) {
            self::write_file($relative, $contents);
        }

        $old_manifest = self::state()['manifest'] ?? [];
        $removed = 0;
        foreach (array_diff($old_manifest, array_keys($files)) as $stale) {
            if (self::is_deployable($stale) && is_file(ABSPATH . $stale) && @unlink(ABSPATH . $stale)) {
                $removed++;
                self::remove_empty_parents(dirname($stale));
            }
        }

        self::write_htaccess_block(true);

        $state = self::state();
        $state['sha'] = $sha;
        $state['time'] = time();
        $state['manifest'] = array_keys($files);
        update_option(self::OPT_STATE, $state, false);

        $check = self::health_check();
        if ($check['broken']) {
            self::write_raw_htaccess($previous_htaccess);
            return ['ok' => false, 'sha' => $sha, 'error' => 'Le site répondait en erreur après le déploiement ; la configuration Apache précédente a été restaurée.', 'checks' => $check['results']];
        }

        return ['ok' => true, 'sha' => $sha, 'files' => count($files), 'removed' => $removed, 'checks' => $check['results']];
    }

    /** @return array<string, string> relative path => contents */
    private static function read_zip(string $zip_path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zip_path) !== true) {
            throw new RuntimeException('Archive ZIP illisible.');
        }
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_ends_with($name, '/')) {
                continue;
            }
            // GitHub zips wrap everything in "<repo>-<sha>/": drop that first segment.
            $relative = substr($name, (int) strpos($name, '/') + 1);
            if (!self::is_safe_path($relative) || self::is_repo_tooling($relative) || !self::is_deployable($relative)) {
                continue;
            }
            $files[$relative] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        return $files;
    }

    private static function is_safe_path(string $relative): bool
    {
        return $relative !== ''
            && !str_starts_with($relative, '/')
            && !str_contains($relative, '\\')
            && !str_contains($relative, "\0")
            && !in_array('..', explode('/', $relative), true);
    }

    private static function is_repo_tooling(string $relative): bool
    {
        foreach (self::REPO_EXCLUDES as $prefix) {
            if ($relative === $prefix || (str_ends_with($prefix, '/') && str_starts_with($relative, $prefix))) {
                return true;
            }
        }
        return false;
    }

    private static function is_deployable(string $relative): bool
    {
        foreach (self::PROTECTED_DIRS as $dir) {
            if (str_starts_with($relative, $dir)) {
                return false;
            }
        }
        return str_contains($relative, '/') || !preg_match(self::PROTECTED_ROOT_FILE, $relative);
    }

    private static function write_file(string $relative, string $contents): void
    {
        $target = ABSPATH . $relative;
        if (!wp_mkdir_p(dirname($target))) {
            throw new RuntimeException('Impossible de créer le dossier de ' . $relative);
        }
        // Write then rename so visitors never get a half-written file.
        $tmp = $target . '.jsd-tmp';
        if (file_put_contents($tmp, $contents) === false || !rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException("Impossible d'écrire " . $relative);
        }
    }

    private static function remove_empty_parents(string $relative_dir): void
    {
        while ($relative_dir !== '.' && $relative_dir !== '' && self::is_deployable($relative_dir . '/')) {
            if (!@rmdir(ABSPATH . $relative_dir)) {
                return; // not empty
            }
            $relative_dir = dirname($relative_dir);
        }
    }

    // ------------------------------------------------------------ .htaccess

    private static function htaccess_path(): string
    {
        return ABSPATH . '.htaccess';
    }

    private static function read_htaccess(): string
    {
        return is_file(self::htaccess_path()) ? (string) file_get_contents(self::htaccess_path()) : '';
    }

    private static function write_raw_htaccess(string $contents): void
    {
        if (file_put_contents(self::htaccess_path(), $contents) === false) {
            throw new RuntimeException("Impossible d'écrire le fichier .htaccess.");
        }
    }

    /**
     * Puts our rules ABOVE WordPress's block: Apache applies them first, so existing static pages win
     * and WordPress only handles its admin and API. WordPress rewrites only its own block, so ours survives.
     */
    private static function write_htaccess_block(bool $enabled): void
    {
        $pattern = '/# BEGIN ' . self::MARKER . '.*?# END ' . self::MARKER . "\n?/s";
        $current = preg_replace($pattern, '', self::read_htaccess());
        $block = $enabled ? self::htaccess_rules() : '';
        self::write_raw_htaccess($block . ltrim((string) $current));
    }

    private static function htaccess_rules(): string
    {
        $root = rtrim(ABSPATH, '/');
        $wordpress = 'wp-admin|wp-json|wp-content|wp-includes|wp-login\.php|wp-cron\.php|index\.php|xmlrpc\.php';
        return implode("\n", [
            '# BEGIN ' . self::MARKER,
            '# Managed by the Junior Static Deploy plugin: static pages first, WordPress only for its admin.',
            '<IfModule mod_rewrite.c>',
            'RewriteEngine On',
            'RewriteBase /',
            '# Home page',
            'RewriteCond %{QUERY_STRING} !(^|&)rest_route=',
            'RewriteCond ' . $root . '/index.html -f',
            'RewriteRule ^$ index.html [L]',
            '# /section -> /section/ (relative links need the trailing slash)',
            'RewriteCond %{REQUEST_FILENAME} -d',
            'RewriteCond %{REQUEST_FILENAME}/index.html -f',
            'RewriteRule ^(.*[^/])$ https://%{HTTP_HOST}/$1/ [R=301,L]',
            '# /section/ -> /section/index.html',
            'RewriteCond %{REQUEST_FILENAME} -d',
            'RewriteCond %{REQUEST_FILENAME}/index.html -f',
            'RewriteRule ^(.+/)$ $1index.html [L]',
            '# Anything else that does not exist: the static 404 page, not WordPress',
            'RewriteCond ' . $root . '/404.html -f',
            'RewriteCond %{REQUEST_FILENAME} !-f',
            'RewriteCond %{REQUEST_FILENAME} !-d',
            'RewriteCond %{REQUEST_URI} !^/(' . $wordpress . ')',
            'RewriteCond %{QUERY_STRING} !(^|&)rest_route=',
            'RewriteRule ^ - [R=404,L]',
            '</IfModule>',
            'ErrorDocument 404 /404.html',
            '# END ' . self::MARKER,
            '',
            '',
        ]);
    }

    /** @return array{broken: bool, results: array<string, int|string>} */
    private static function health_check(): array
    {
        $urls = [
            '/' => home_url('/'),
            '/etudiants/' => home_url('/etudiants/'),
            'wordpress' => home_url('/index.php?rest_route=/junior-deploy/v1/status'),
        ];
        $results = [];
        $broken = false;
        foreach ($urls as $label => $url) {
            $response = wp_remote_get($url, ['timeout' => 15, 'redirection' => 0, 'sslverify' => false]);
            if (is_wp_error($response)) {
                // Some servers cannot call themselves (loopback blocked): not proof the site is down.
                $results[$label] = 'non vérifié : ' . $response->get_error_message();
                continue;
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            $results[$label] = $code;
            $broken = $broken || $code >= 500;
        }
        return ['broken' => $broken, 'results' => $results];
    }

    // --------------------------------------------------------------- Admin

    private static function state(): array
    {
        return (array) get_option(self::OPT_STATE, []);
    }

    public static function admin_menu(): void
    {
        add_options_page('Site statique', 'Site statique', 'manage_options', 'junior-static-deploy', [self::class, 'render_admin']);
    }

    public static function handle_admin_deploy(): void
    {
        self::guard_admin_action('jsd_deploy');
        self::sync(null, true);
        wp_safe_redirect(admin_url('options-general.php?page=junior-static-deploy'));
        exit;
    }

    public static function handle_admin_remove(): void
    {
        self::guard_admin_action('jsd_remove');
        $state = self::state();
        foreach ($state['manifest'] ?? [] as $relative) {
            if (self::is_deployable($relative) && is_file(ABSPATH . $relative) && @unlink(ABSPATH . $relative)) {
                self::remove_empty_parents(dirname($relative));
            }
        }
        self::write_htaccess_block(false);
        update_option(self::OPT_STATE, ['log' => ['time' => time(), 'ok' => true, 'removed_site' => true]], false);
        wp_safe_redirect(admin_url('options-general.php?page=junior-static-deploy'));
        exit;
    }

    private static function guard_admin_action(string $action): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé.', 403);
        }
        check_admin_referer($action);
    }

    public static function render_admin(): void
    {
        $state = self::state();
        $log = $state['log'] ?? null;
        $sha = $state['sha'] ?? null;
        echo '<div class="wrap"><h1>Site statique</h1>';
        echo '<p>Dépôt : <a href="https://github.com/' . esc_attr(self::REPO) . '" target="_blank" rel="noopener">' . esc_html(self::REPO) . '</a> (branche ' . esc_html(self::BRANCH) . ')</p>';
        echo '<p>Version en ligne : ' . ($sha ? '<code>' . esc_html(substr($sha, 0, 7)) . '</code> publiée le ' . esc_html(wp_date('d/m/Y H:i', (int) $state['time'])) : '<em>aucune</em>') . '</p>';
        echo '<p>URL appelée par GitHub après chaque push : <code>' . esc_html(home_url('/index.php?rest_route=/junior-deploy/v1/sync')) . '</code></p>';
        if ($log) {
            echo '<h2>Dernière opération (' . esc_html(wp_date('d/m/Y H:i', (int) $log['time'])) . ')</h2>';
            echo '<pre style="background:#fff;padding:1em;max-width:60em;white-space:pre-wrap">' . esc_html(wp_json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:1em">';
        wp_nonce_field('jsd_deploy');
        echo '<input type="hidden" name="action" value="jsd_deploy">';
        submit_button('Déployer maintenant', 'primary', 'submit', false);
        echo '</form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block" onsubmit="return confirm(\'Supprimer le site statique et revenir aux pages WordPress ?\')">';
        wp_nonce_field('jsd_remove');
        echo '<input type="hidden" name="action" value="jsd_remove">';
        submit_button('Retirer le site statique', 'secondary', 'submit', false);
        echo '</form></div>';
    }
}

Junior_Static_Deploy::boot();
