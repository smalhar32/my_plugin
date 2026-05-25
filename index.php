<?php
/*
Plugin Name: My Plugin Self-Updater
Plugin URL: https://github.com/smalh/my_plugin
Description: A robust, self-updating RISE CRM (CodeIgniter 4) plugin that pulls releases from GitHub. 100% self-contained, daily cached checks, secure Zip Slip protection, and beautiful glassmorphic Admin UI.
Version: 1.0.8
Author: Antigravity
Author URL: https://google.com
Requires at least: 1.0.0
*/

defined('PLUGINPATH') or exit('No direct script access allowed');

// Constants
define('MY_PLUGIN_NAME', 'my_plugin');

// Set your GitHub repository details: replace these placeholders with your actual details
$repo_owner = "smalhar32"; // E.g., "mycompany" or "smalhar32"
$repo_name = "my_plugin";        // E.g., "rise-crm-webhook-plugin" or "my_plugin"

define('MY_PLUGIN_GITHUB_REPO', $repo_owner . '/' . $repo_name);

// Register Lifecycle Hooks
if (function_exists('register_installation_hook')) {
    register_installation_hook(MY_PLUGIN_NAME, "my_plugin_install");
}
if (function_exists('register_uninstallation_hook')) {
    register_uninstallation_hook(MY_PLUGIN_NAME, "my_plugin_uninstall");
}
if (function_exists('register_update_hook')) {
    register_update_hook(MY_PLUGIN_NAME, "my_plugin_update");
}

// Hook globally to inject CSS stylesheets into the head section
if (function_exists('app_hooks')) {
    app_hooks()->add_action("app_hook_head_extension", "my_plugin_inject_assets");
    // Hook globally to inject the Update Available banner in the main layout
    app_hooks()->add_action("app_hook_layout_main_view_extension", "my_plugin_inject_alert_banner");
}

/**
 * Installation hook: Initialize updater DB settings and run initial migration safely.
 */
if (!function_exists('my_plugin_install')) {
    function my_plugin_install() {
        $db = \Config\Database::connect();
        $prefix = $db->getPrefix();

        // 1. Create a safe migration table to track plugin updates
        $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}my_plugin_updates` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `version` VARCHAR(50) NOT NULL,
            `updated_at` DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;";
        $db->query($sql);

        // 2. Initialize default plugin settings safely
        $Settings_model = model("App\Models\Settings_model");
        $Settings_model->save_setting("my_plugin_last_check_date", "");
        $Settings_model->save_setting("my_plugin_update_available", "0");
        $Settings_model->save_setting("my_plugin_latest_version", "");
        $Settings_model->save_setting("my_plugin_download_url", "");
    }
}

/**
 * Uninstallation hook: Safely clean up custom tables and DB settings.
 */
if (!function_exists('my_plugin_uninstall')) {
    function my_plugin_uninstall() {
        $db = \Config\Database::connect();
        $prefix = $db->getPrefix();

        // Drop tracking tables safely
        $db->query("DROP TABLE IF EXISTS `{$prefix}my_plugin_updates`;");

        // Clean up settings from global settings table
        $db->query("DELETE FROM `{$prefix}settings` WHERE `setting_name` LIKE 'my_plugin_%';");
    }
}

/**
 * Update hook: Execute database migrations safely during updates (Zero Data Loss).
 */
if (!function_exists('my_plugin_update')) {
    function my_plugin_update() {
        $db = \Config\Database::connect();
        $prefix = $db->getPrefix();

        // Safe SQL migration: never drop or overwrite existing tables/data
        $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}my_plugin_updates` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `version` VARCHAR(50) NOT NULL,
            `updated_at` DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;";
        $db->query($sql);

        // Fetch and log current version update event
        $meta = get_plugin_meta_data(MY_PLUGIN_NAME);
        $version = isset($meta['version']) ? trim($meta['version']) : '1.0.0';
        $now = date('Y-m-d H:i:s');

        $db->query("INSERT INTO `{$prefix}my_plugin_updates` (`version`, `updated_at`) VALUES ('{$version}', '{$now}');");
    }
}

/**
 * Injects CSS premium glassmorphic styling into head section for administrator users.
 */
if (!function_exists('my_plugin_inject_assets')) {
    function my_plugin_inject_assets() {
        $session_user_id = \Config\Services::session()->get('user_id');
        $login_user = $session_user_id ? model('App\Models\Users_model')->get_one(clean_data($session_user_id)) : null;

        // Enforce only staff administrator users see visual update cues
        if (!$login_user || !isset($login_user->is_admin) || !$login_user->is_admin) {
            return;
        }

        ?>
        <style type="text/css">
            @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap');

            .my-plugin-update-alert-container {
                position: fixed;
                bottom: 30px;
                right: 30px;
                z-index: 999999;
                width: 380px;
                background: rgba(15, 23, 42, 0.85);
                backdrop-filter: blur(16px) saturate(180%);
                -webkit-backdrop-filter: blur(16px) saturate(180%);
                border: 1px solid rgba(139, 92, 246, 0.35);
                border-radius: 20px;
                padding: 24px;
                box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5), 0 0 30px rgba(99, 102, 241, 0.25), inset 0 0 0 1px rgba(255, 255, 255, 0.05);
                font-family: 'Outfit', 'Inter', sans-serif;
                color: #e2e8f0;
                transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
                animation: myPluginSlideIn 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            }

            @keyframes myPluginSlideIn {
                from { opacity: 0; transform: translateY(40px) scale(0.95); }
                to { opacity: 1; transform: translateY(0) scale(1); }
            }

            .my-plugin-update-alert-container.my-plugin-updating {
                border-color: rgba(99, 102, 241, 0.6);
                box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5), 0 0 40px rgba(99, 102, 241, 0.4);
            }

            .my-plugin-update-alert-container.my-plugin-success {
                border-color: rgba(34, 197, 94, 0.5);
                box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5), 0 0 40px rgba(34, 197, 94, 0.3);
            }

            .my-plugin-update-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 14px;
            }

            .my-plugin-update-title {
                font-size: 16px;
                font-weight: 700;
                color: #ffffff;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .my-plugin-update-badge {
                background: rgba(139, 92, 246, 0.15);
                border: 1px solid rgba(139, 92, 246, 0.3);
                color: #a78bfa;
                font-size: 11px;
                font-weight: 700;
                padding: 2px 8px;
                border-radius: 9999px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            .my-plugin-update-pulse {
                width: 10px;
                height: 10px;
                background-color: #8b5cf6;
                border-radius: 50%;
                box-shadow: 0 0 0 0 rgba(139, 92, 246, 0.7);
                animation: myPluginPulseGlow 2s infinite;
            }

            @keyframes myPluginPulseGlow {
                0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(139, 92, 246, 0.7); }
                70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(139, 92, 246, 0); }
                100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(139, 92, 246, 0); }
            }

            .my-plugin-update-close {
                background: transparent;
                border: none;
                color: #94a3b8;
                cursor: pointer;
                padding: 2px;
                transition: color 0.2s;
                font-size: 20px;
                line-height: 1;
            }

            .my-plugin-update-close:hover {
                color: #ffffff;
            }

            .my-plugin-update-body {
                font-size: 14px;
                line-height: 1.5;
                color: #cbd5e1;
                margin-bottom: 20px;
            }

            .my-plugin-update-version-tag {
                font-weight: 700;
                color: #818cf8;
            }

            .my-plugin-update-actions {
                display: flex;
                gap: 12px;
            }

            .my-plugin-update-btn-primary {
                flex: 1;
                background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);
                color: #ffffff !important;
                border: none;
                padding: 12px 18px;
                border-radius: 12px;
                font-weight: 600;
                font-size: 13px;
                cursor: pointer;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
                text-decoration: none !important;
                box-shadow: 0 4px 14px rgba(99, 102, 241, 0.35);
            }

            .my-plugin-update-btn-primary:hover {
                transform: translateY(-2px);
                box-shadow: 0 8px 20px rgba(99, 102, 241, 0.5);
                background: linear-gradient(135deg, #4f46e5 0%, #9333ea 100%);
            }

            .my-plugin-update-btn-primary:active {
                transform: translateY(0);
            }

            .my-plugin-update-btn-dismiss {
                background: rgba(255, 255, 255, 0.05);
                color: #94a3b8 !important;
                border: 1px solid rgba(255, 255, 255, 0.1);
                padding: 12px 16px;
                border-radius: 12px;
                font-weight: 600;
                font-size: 13px;
                cursor: pointer;
                transition: all 0.2s;
            }

            .my-plugin-update-btn-dismiss:hover {
                background: rgba(255, 255, 255, 0.1);
                color: #ffffff !important;
                border-color: rgba(255, 255, 255, 0.15);
            }

            .my-plugin-spinner {
                display: none;
                width: 16px;
                height: 16px;
                border: 2px solid rgba(255, 255, 255, 0.3);
                border-radius: 50%;
                border-top-color: #ffffff;
                animation: myPluginSpin 0.8s linear infinite;
            }

            @keyframes myPluginSpin {
                to { transform: rotate(360deg); }
            }
        </style>
        <?php
    }
}

/**
 * Injects the update banner and background checking JS scripts (Non-blocking design).
 */
if (!function_exists('my_plugin_inject_alert_banner')) {
    function my_plugin_inject_alert_banner() {
        $session_user_id = \Config\Services::session()->get('user_id');
        $login_user = $session_user_id ? model('App\Models\Users_model')->get_one(clean_data($session_user_id)) : null;

        if (!$login_user || !isset($login_user->is_admin) || !$login_user->is_admin) {
            return;
        }

        $last_checked = get_setting("my_plugin_last_check_date");
        $today = date("Y-m-d");

        // JS markup and trigger logic
        ?>
        <script type="text/javascript">
            $(document).ready(function() {
                // Listen for One-Click Update trigger clicks (on delegated events)
                $(document).on("click", "#myPluginTriggerUpdate", function(e) {
                    e.preventDefault();
                    var $container = $(".my-plugin-update-alert-container");
                    var $btn = $(this);
                    var $dismiss = $("#myPluginDismissUpdate");
                    var $spinner = $btn.find(".my-plugin-spinner");

                    // De-activate and lock layout
                    $btn.prop("disabled", true);
                    $dismiss.prop("disabled", true);
                    $spinner.show();
                    $btn.contents().filter(function() {
                        return this.nodeType === 3; // Text nodes
                    }).first().replaceWith(" Updating Plugin...");
                    $container.addClass("my-plugin-updating");

                    // Execute the secure extraction script
                    $.ajax({
                        url: "<?php echo get_uri('my_plugin/updater/update'); ?>",
                        type: 'POST',
                        dataType: 'json',
                        success: function(res) {
                            if (res.success) {
                                $container.removeClass("my-plugin-updating").addClass("my-plugin-success");
                                $spinner.hide();
                                $btn.hide();
                                $dismiss.hide();
                                $(".my-plugin-update-body").html("<i data-feather='check-circle' style='color: #22c55e; width: 16px; height: 16px; margin-right: 6px; vertical-align: middle;'></i> " + res.message);
                                if (window.feather) feather.replace();

                                // Smooth reload after 2 seconds to reload application with updated files
                                setTimeout(function() {
                                    window.location.reload();
                                }, 2000);
                            } else {
                                // Recover on error
                                $btn.prop("disabled", false);
                                $dismiss.prop("disabled", false);
                                $spinner.hide();
                                $btn.contents().filter(function() {
                                    return this.nodeType === 3;
                                }).first().replaceWith(" Retry Update");
                                $container.removeClass("my-plugin-updating");
                                
                                if (window.appAlert) {
                                    appAlert.error(res.message || "Failed to complete updater installation.");
                                } else {
                                    alert(res.message || "Failed to complete updater installation.");
                                }
                            }
                        },
                        error: function(xhr, status, error) {
                            $btn.prop("disabled", false);
                            $dismiss.prop("disabled", false);
                            $spinner.hide();
                            $btn.contents().filter(function() {
                                return this.nodeType === 3;
                            }).first().replaceWith(" Retry Update");
                            $container.removeClass("my-plugin-updating");

                            if (window.appAlert) {
                                appAlert.error("An error occurred during extraction pipeline: " + error);
                            } else {
                                alert("An error occurred during extraction pipeline: " + error);
                            }
                        }
                    });
                });

                // Handle dismissing alert
                $(document).on("click", "#myPluginDismissUpdate, .my-plugin-update-close", function(e) {
                    e.preventDefault();
                    var latestVersion = $(".my-plugin-update-version-tag").text().trim();
                    if (latestVersion) {
                        localStorage.setItem("my_plugin_dismissed_version", latestVersion);
                    }
                    $(".my-plugin-update-alert-container").fadeOut(300, function() {
                        $(this).remove();
                    });
                });
            });

            // Appends the premium glassmorphic alert dynamically
            function showMyPluginUpdateBanner(latestVersion) {
                // If the user has explicitly dismissed/skipped this version, do not show the sliding banner again
                if (localStorage.getItem("my_plugin_dismissed_version") === latestVersion.trim()) {
                    return;
                }

                if ($(".my-plugin-update-alert-container").length > 0) return;

                var bannerHtml = 
                    '<div class="my-plugin-update-alert-container">' +
                    '  <div class="my-plugin-update-header">' +
                    '    <div class="my-plugin-update-title">' +
                    '      <span class="my-plugin-update-pulse"></span>' +
                    '      Update Available' +
                    '      <span class="my-plugin-update-badge">New</span>' +
                    '    </div>' +
                    '    <button class="my-plugin-update-close">&times;</button>' +
                    '  </div>' +
                    '  <div class="my-plugin-update-body">' +
                    '    A new version of My Plugin is available: <span class="my-plugin-update-version-tag">' + latestVersion + '</span>. Build is fully validated and ready for installation.' +
                    '  </div>' +
                    '  <div class="my-plugin-update-actions">' +
                    '    <button id="myPluginTriggerUpdate" class="my-plugin-update-btn-primary">' +
                    '      <span class="my-plugin-spinner"></span>' +
                    '      <i data-feather="cloud-lightning" style="width: 14px; height: 14px;"></i>' +
                    '      One-Click Update' +
                    '    </button>' +
                    '    <button id="myPluginDismissUpdate" class="my-plugin-update-btn-dismiss">Skip</button>' +
                    '  </div>' +
                    '</div>';

                $("body").append(bannerHtml);
                if (window.feather) {
                    feather.replace();
                }
            }
        </script>
        <?php

        // Determine if daily check is due, or if update was previously found
        if ($last_checked !== $today) {
            // Daily check is due. Trigger background non-blocking poll
            $poll_url = get_uri("my_plugin/updater/check_for_updates");
            ?>
            <script type="text/javascript">
                $(document).ready(function() {
                    $.ajax({
                        url: "<?php echo $poll_url; ?>",
                        type: 'POST',
                        dataType: 'json',
                        success: function(res) {
                            if (res.success && res.update_available) {
                                showMyPluginUpdateBanner(res.latest_version);
                            }
                        },
                        error: function() {
                            // Suppress polling network issues silently
                        }
                    });
                });
            </script>
            <?php
        } else if (get_setting("my_plugin_update_available") === "1") {
            // Cached state proves an update is available. Display banner directly!
            $latest_ver = get_setting("my_plugin_latest_version");
            ?>
            <script type="text/javascript">
                $(document).ready(function() {
                    showMyPluginUpdateBanner("<?php echo esc($latest_ver); ?>");
                });
            </script>
            <?php
        }
    }
}
