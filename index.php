<?php
/*
Plugin Name: My Plugin Self-Updater
Plugin URL: https://github.com/smalh/my_plugin
Description: A robust, self-updating RISE CRM (CodeIgniter 4) plugin that pulls releases from GitHub. 100% self-contained, daily cached checks, secure Zip Slip protection, and beautiful glassmorphic Admin UI.
Version: 1.0.7
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
    // Hook to inject action links on Plugins native page
    app_hooks()->add_filter("app_filter_action_links_of_my_plugin", "my_plugin_action_links");
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

        // Dynamically clear out the local `.git` directory if it exists,
        // clearing any Windows read-only flags to prevent rmdir() "Directory not empty" crashes.
        $git_dir = PLUGINPATH . MY_PLUGIN_NAME . '/.git';
        if (is_dir($git_dir)) {
            my_plugin_delete_git_folder($git_dir);
        }
    }
}

if (!function_exists('my_plugin_delete_git_folder')) {
    function my_plugin_delete_git_folder($dir) {
        if (!is_dir($dir)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $path = $fileinfo->getRealPath();
            if ($fileinfo->isDir()) {
                @rmdir($path);
            } else {
                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                    @chmod($path, 0777);
                }
                @unlink($path);
            }
        }
        @rmdir($dir);
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

        // If this is a GET request (triggered when the user clicks "Updates" under the plugin's wrench dropdown menu)
        // then render our beautiful, premium glassmorphic modal update management panel.
        $request = \Config\Services::request();
        if ($request->getMethod() === 'get' || $request->getMethod() === 'GET') {
            $update_available = get_setting("my_plugin_update_available") === "1";
            $latest_ver = get_setting("my_plugin_latest_version");
            $local_ver = $version;

            ?>
            <div class="modal-body clearfix my-plugin-modal-container">
                <div class="my-plugin-modal-card">
                    <div class="my-plugin-modal-header">
                        <div class="my-plugin-modal-logo">
                            <i data-feather="cloud-lightning" style="width: 24px; height: 24px;"></i>
                        </div>
                        <div class="my-plugin-modal-title">
                            <h3>My Plugin Self-Updater</h3>
                            <p>Robust, self-contained GitHub release integration</p>
                        </div>
                    </div>

                    <div class="my-plugin-info-grid">
                        <div class="my-plugin-info-item">
                            <div class="my-plugin-info-label">Installed Version</div>
                            <div class="my-plugin-info-value">v<?php echo esc($local_ver); ?></div>
                        </div>
                        <div class="my-plugin-info-item">
                            <div class="my-plugin-info-label">Status</div>
                            <div class="my-plugin-info-value" style="margin-top: 4px;">
                                <?php if ($update_available): ?>
                                    <span class="my-plugin-status-badge update-available">Update Available</span>
                                <?php else: ?>
                                    <span class="my-plugin-status-badge up-to-date">Up to Date</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if ($update_available): ?>
                        <div class="my-plugin-desc-box">
                            <strong>Release v<?php echo esc($latest_ver); ?> is ready!</strong><br />
                            This update has been fully compiled and validated for your system. Click the button below to perform a safe, one-click update.
                        </div>
                        <button type="button" id="myPluginModalUpdateBtn" class="my-plugin-btn-primary">
                            <span class="my-plugin-spinner"></span>
                            <i data-feather="cloud-lightning" style="width: 16px; height: 16px;"></i>
                            One-Click Update to v<?php echo esc($latest_ver); ?>
                        </button>
                    <?php else: ?>
                        <div class="my-plugin-desc-box" style="background: rgba(34, 197, 94, 0.02); border-color: rgba(34, 197, 94, 0.08); color: #1e293b;">
                            <div style="display: flex; align-items: flex-start; gap: 10px;">
                                <i data-feather="check-circle" style="color: #22c55e; width: 20px; height: 20px; flex-shrink: 0; margin-top: 2px;"></i>
                                <div>
                                    <strong>You are all set!</strong><br />
                                    Your plugin is running the latest available release. No action is required at this time.
                                </div>
                            </div>
                        </div>
                        <button type="button" id="myPluginModalCheckBtn" class="my-plugin-btn-secondary">
                            <i data-feather="refresh-cw" style="width: 16px; height: 16px;"></i>
                            Check for Updates
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-bs-dismiss="modal"><i data-feather="x" class="icon-16"></i> Close</button>
            </div>

            <!-- Styles & Scripts -->
            <style type="text/css">
                @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap');
                
                .my-plugin-modal-container {
                    font-family: 'Outfit', 'Inter', sans-serif !important;
                    background: #f8fafc;
                    padding: 24px !important;
                }
                
                .my-plugin-modal-card {
                    background: #ffffff;
                    border: 1px solid #e2e8f0;
                    border-radius: 16px;
                    padding: 24px;
                    box-shadow: 0 10px 25px rgba(99, 102, 241, 0.05);
                    position: relative;
                    overflow: hidden;
                }
                
                .my-plugin-modal-card::before {
                    content: '';
                    position: absolute;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 6px;
                    background: linear-gradient(90deg, #6366f1 0%, #a855f7 100%);
                }

                .my-plugin-modal-header {
                    display: flex;
                    align-items: center;
                    gap: 16px;
                    margin-bottom: 20px;
                }

                .my-plugin-modal-logo {
                    width: 48px;
                    height: 48px;
                    border-radius: 12px;
                    background: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(168, 85, 247, 0.1) 100%);
                    border: 1px solid rgba(139, 92, 246, 0.2);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    color: #6366f1;
                }

                .my-plugin-modal-title h3 {
                    font-size: 18px;
                    font-weight: 700;
                    margin: 0;
                    color: #0f172a;
                }

                .my-plugin-modal-title p {
                    font-size: 13px;
                    color: #64748b;
                    margin: 4px 0 0 0;
                }

                .my-plugin-status-badge {
                    font-size: 11px;
                    font-weight: 700;
                    padding: 4px 10px;
                    border-radius: 9999px;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                    display: inline-block;
                }

                .my-plugin-status-badge.up-to-date {
                    background: rgba(34, 197, 94, 0.1);
                    border: 1px solid rgba(34, 197, 94, 0.2);
                    color: #166534;
                }

                .my-plugin-status-badge.update-available {
                    background: rgba(245, 158, 11, 0.1);
                    border: 1px solid rgba(245, 158, 11, 0.2);
                    color: #92400e;
                }

                .my-plugin-info-grid {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 16px;
                    margin-bottom: 20px;
                }

                .my-plugin-info-item {
                    background: #f8fafc;
                    border: 1px solid #f1f5f9;
                    border-radius: 12px;
                    padding: 14px;
                }

                .my-plugin-info-label {
                    font-size: 12px;
                    color: #64748b;
                    margin-bottom: 4px;
                    font-weight: 500;
                }

                .my-plugin-info-value {
                    font-size: 15px;
                    font-weight: 600;
                    color: #0f172a;
                }

                .my-plugin-desc-box {
                    background: rgba(99, 102, 241, 0.02);
                    border: 1px solid rgba(99, 102, 241, 0.08);
                    border-radius: 12px;
                    padding: 16px;
                    margin-bottom: 20px;
                    font-size: 13px;
                    line-height: 1.6;
                    color: #475569;
                }

                .my-plugin-btn-primary {
                    background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);
                    color: #ffffff !important;
                    border: none;
                    padding: 12px 24px;
                    border-radius: 12px;
                    font-weight: 600;
                    font-size: 14px;
                    cursor: pointer;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    gap: 8px;
                    transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
                    box-shadow: 0 4px 14px rgba(99, 102, 241, 0.35);
                    width: 100%;
                    text-align: center;
                }

                .my-plugin-btn-primary:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 8px 20px rgba(99, 102, 241, 0.5);
                    background: linear-gradient(135deg, #4f46e5 0%, #9333ea 100%);
                }

                .my-plugin-btn-primary:disabled {
                    background: #cbd5e1;
                    box-shadow: none;
                    cursor: not-allowed;
                    transform: none;
                }

                .my-plugin-btn-secondary {
                    background: #ffffff;
                    color: #475569 !important;
                    border: 1px solid #e2e8f0;
                    padding: 12px 24px;
                    border-radius: 12px;
                    font-weight: 600;
                    font-size: 14px;
                    cursor: pointer;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    gap: 8px;
                    transition: all 0.2s;
                    width: 100%;
                    text-align: center;
                }

                .my-plugin-btn-secondary:hover {
                    background: #f8fafc;
                    color: #0f172a !important;
                    border-color: #cbd5e1;
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
            </style>

            <script type="text/javascript">
                $(document).ready(function() {
                    if (window.feather) feather.replace();

                    // One-Click Update inside the modal
                    $("#myPluginModalUpdateBtn").click(function(e) {
                        e.preventDefault();
                        var $btn = $(this);
                        var $spinner = $btn.find(".my-plugin-spinner");

                        $btn.prop("disabled", true);
                        $spinner.show();
                        $btn.contents().filter(function() {
                            return this.nodeType === 3;
                        }).first().replaceWith(" Updating Plugin...");

                        $.ajax({
                            url: "<?php echo get_uri('my_plugin/updater/update'); ?>",
                            type: 'POST',
                            dataType: 'json',
                            success: function(res) {
                                $spinner.hide();
                                if (res.success) {
                                    $btn.css("background", "#22c55e").html("<i data-feather='check-circle' style='width: 16px; height: 16px;'></i> Updated Successfully!");
                                    if (window.feather) feather.replace();
                                    
                                    if (window.appAlert) {
                                        appAlert.success(res.message);
                                    }
                                    
                                    setTimeout(function() {
                                        window.location.reload();
                                    }, 2000);
                                } else {
                                    $btn.prop("disabled", false);
                                    $btn.contents().filter(function() {
                                        return this.nodeType === 3;
                                    }).first().replaceWith(" Retry Update");
                                    
                                    if (window.appAlert) {
                                        appAlert.error(res.message || "Failed to update.");
                                    } else {
                                        alert(res.message || "Failed to update.");
                                    }
                                }
                            },
                            error: function(xhr, status, error) {
                                $spinner.hide();
                                $btn.prop("disabled", false);
                                $btn.contents().filter(function() {
                                    return this.nodeType === 3;
                                }).first().replaceWith(" Retry Update");
                                
                                if (window.appAlert) {
                                    appAlert.error("An error occurred: " + error);
                                } else {
                                    alert("An error occurred: " + error);
                                }
                            }
                        });
                    });

                    // Manual Check inside the modal
                    $("#myPluginModalCheckBtn").click(function(e) {
                        e.preventDefault();
                        var $btn = $(this);
                        var originalHtml = $btn.html();

                        $btn.prop("disabled", true);
                        $btn.html("<i data-feather='refresh-cw' class='icon-14' style='animation: myPluginSpin 1s linear infinite;'></i> Checking...");
                        if (window.feather) feather.replace();

                        $.ajax({
                            url: "<?php echo get_uri('my_plugin/updater/check_for_updates'); ?>",
                            type: 'POST',
                            dataType: 'json',
                            success: function(res) {
                                if (res.success && res.update_available) {
                                    // Reload page to reflect changes
                                    if (window.appAlert) {
                                        appAlert.success("Update found! Reloading updates view...");
                                    }
                                    setTimeout(function() {
                                        window.location.reload();
                                    }, 1000);
                                } else {
                                    $btn.html("<i data-feather='check-circle' style='color: #22c55e; width: 16px; height: 16px;'></i> Up to date!");
                                    if (window.feather) feather.replace();
                                    setTimeout(function() {
                                        $btn.prop("disabled", false);
                                        $btn.html(originalHtml);
                                        if (window.feather) feather.replace();
                                    }, 2000);
                                }
                            },
                            error: function() {
                                $btn.html("<i data-feather='alert-circle' style='color: #ef4444; width: 16px; height: 16px;'></i> Check failed");
                                if (window.feather) feather.replace();
                                setTimeout(function() {
                                    $btn.prop("disabled", false);
                                    $btn.html(originalHtml);
                                    if (window.feather) feather.replace();
                                }, 2000);
                            }
                        });
                    });
                });
            </script>
            <?php
        }
    }
}

/**
 * Injects update action link on native RISE CRM Plugins page when an update is available.
 */
if (!function_exists('my_plugin_action_links')) {
    function my_plugin_action_links($action_links) {
        if (get_setting("my_plugin_update_available") === "1") {
            $latest = get_setting("my_plugin_latest_version");
            $action_links[] = '<a href="#" id="myPluginTriggerUpdate" class="text-warning" style="font-weight: bold;"><i data-feather="cloud-lightning" class="icon-14" style="vertical-align: middle; margin-right: 2px;"></i> Update Available: ' . $latest . ' (Click to Update)</a>';
        } else {
            $action_links[] = '<a href="#" id="myPluginTriggerCheck" class="text-primary"><i data-feather="refresh-cw" class="icon-14" style="vertical-align: middle; margin-right: 2px;"></i> Check for Updates</a>';
        }
        return $action_links;
    }
}

/**
 * Injects CSS premium glassmorphic styling into head section for administrator users.
 */
if (!function_exists('my_plugin_inject_assets')) {
    function my_plugin_inject_assets() {
        $session_user_id = \Config\Services::session()->get('user_id');
        // Check if the user is logged in
        if (!$session_user_id) {
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
        // Check if the user is logged in
        if (!$session_user_id) {
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
                    $(".my-plugin-update-alert-container").fadeOut(300, function() {
                        $(this).remove();
                    });
                });

                // Handle manual check for updates from other CRM users
                $(document).on("click", "#myPluginTriggerCheck", function(e) {
                    e.preventDefault();
                    var $btn = $(this);
                    $btn.html("<i data-feather='refresh-cw' class='icon-14' style='vertical-align: middle; margin-right: 2px; animation: myPluginSpin 1s linear infinite;'></i> Checking...");
                    if (window.feather) feather.replace();

                    $.ajax({
                        url: "<?php echo get_uri('my_plugin/updater/check_for_updates'); ?>",
                        type: 'POST',
                        dataType: 'json',
                        success: function(res) {
                            if (res.success && res.update_available) {
                                showMyPluginUpdateBanner(res.latest_version);
                                window.location.reload();
                            } else {
                                $btn.html("<i data-feather='check-circle' class='icon-14' style='color: #22c55e; vertical-align: middle; margin-right: 2px;'></i> Up to date!");
                                if (window.feather) feather.replace();
                                setTimeout(function() {
                                    $btn.html("<i data-feather='refresh-cw' class='icon-14' style='vertical-align: middle; margin-right: 2px;'></i> Check for Updates");
                                    if (window.feather) feather.replace();
                                }, 2000);
                            }
                        },
                        error: function() {
                            $btn.html("<i data-feather='alert-circle' class='icon-14' style='color: #ef4444; vertical-align: middle; margin-right: 2px;'></i> Check failed");
                            if (window.feather) feather.replace();
                            setTimeout(function() {
                                $btn.html("<i data-feather='refresh-cw' class='icon-14' style='vertical-align: middle; margin-right: 2px;'></i> Check for Updates");
                                if (window.feather) feather.replace();
                            }, 2000);
                        }
                    });
                });
            });

            // Appends the premium glassmorphic alert dynamically
            function showMyPluginUpdateBanner(latestVersion) {
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
