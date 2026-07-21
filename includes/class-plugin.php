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

namespace OLDEV\FcDedup;

defined('ABSPATH') || exit;

use OLDEV\FcDedup\Admin\AdminMenu;
use OLDEV\FcDedup\Admin\RestController;

/**
 * Orchestrator: guards against FluentCRM being inactive, then wires the
 * admin menu (Vue SPA host, Task 9) and REST routes (Task 10) that the
 * SPA talks to.
 */
final class Plugin
{
    public function __construct(private string $file)
    {
    }

    public function boot(): void
    {
        // Load translations on init (not earlier: WP 6.7+ warns when a text domain is
        // loaded before init). Registered before the dependency check so the missing-
        // FluentCRM notice is itself translatable. For a Freemius-distributed plugin the
        // .mo files live in the bundled /languages folder rather than wp.org's language pack.
        $file = $this->file;
        add_action('init', static function () use ($file): void {
            load_plugin_textdomain(
                'fluentcrm-dedup-merge',
                false,
                dirname(plugin_basename($file)) . '/languages'
            );
        });

        if (!$this->fluentCrmActive()) {
            add_action('admin_notices', [$this, 'renderMissingDependencyNotice']);
            return;
        }

        (new AdminMenu($this->file))->register();
        (new RestController())->register();
    }

    private function fluentCrmActive(): bool
    {
        return defined('FLUENTCRM') || class_exists('FluentCrm\App\Models\Subscriber');
    }

    public function renderMissingDependencyNotice(): void
    {
        echo '<div class="notice notice-warning"><p>'
            . esc_html__('FluentCRM Dedup & Merge requires FluentCRM to be active.', 'fluentcrm-dedup-merge')
            . '</p></div>';
    }
}
