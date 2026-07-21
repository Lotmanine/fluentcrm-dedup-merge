<?php

/**
 * FluentCRM Dedup & Merge
 *
 * @package   OLDEV\FcDedup
 * @author    Lotfi O. (OLDEV) <https://oldev.site>
 * @copyright 2026 Lotfi O. / OLDEV
 * @license   GPL-2.0-or-later
 * @link      https://oldev.site
 */

namespace OLDEV\FcDedup\Admin;

defined('ABSPATH') || exit;

/**
 * Registers the plugin's admin page as a submenu of FluentCRM and enqueues the
 * Vue 3 SPA (built by Vite into assets/app.js + assets/app.css) that drives the
 * scan / preview / merge / undo flow against the REST routes.
 */
final class AdminMenu
{
    /**
     * The page hook suffix returned by add_submenu_page(), used to scope the
     * asset enqueue. Captured rather than hardcoded so we do not depend on
     * FluentCRM's exact parent-slug hook naming.
     *
     * @var string|false
     */
    private $hookSuffix = false;

    public function __construct(private string $pluginFile)
    {
    }

    public function register(): void
    {
        // Priority 100: FluentCRM registers its parent menu on admin_menu at the
        // default priority (AdminMenu::init), so running later guarantees the parent
        // exists and our page appends at the bottom of the FluentCRM submenu.
        add_action('admin_menu', [$this, 'addPage'], 100);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function addPage(): void
    {
        // Nest under FluentCRM's top-level menu. Its slug is 'fluentcrm-admin' (see
        // FluentCRM AdminMenu::addMenu -> add_menu_page), NOT 'fluentcrm'. Plugin::boot
        // only registers this page when FluentCRM is active, so the parent is present.
        $this->hookSuffix = add_submenu_page(
            'fluentcrm-admin',
            __('Dedup & Merge', 'dedup-and-merge-for-fluentcrm'),
            __('Dedup & Merge', 'dedup-and-merge-for-fluentcrm'),
            OLDEV_FCDEDUP_CAP,
            'fcdm-dedup',
            [$this, 'renderRoot']
        );
    }

    public function renderRoot(): void
    {
        echo '<div class="wrap"><div id="fcdm-app"></div></div>';
    }

    public function enqueue(string $hook): void
    {
        if ($this->hookSuffix === false || $hook !== $this->hookSuffix) {
            return;
        }

        $base = plugin_dir_url($this->pluginFile);
        wp_enqueue_script('fcdm-app', $base . 'assets/app.js', [], OLDEV_FCDEDUP_VERSION, true);
        wp_enqueue_style('fcdm-app', $base . 'assets/app.css', [], OLDEV_FCDEDUP_VERSION);
        wp_localize_script('fcdm-app', 'FCDM', [
            'restUrl' => esc_url_raw(rest_url(OLDEV_FCDEDUP_REST_NS)),
            'nonce'   => wp_create_nonce('wp_rest'),
        ]);
    }
}
