<?php

namespace my_plugin\Controllers;

use App\Controllers\Security_Controller;

/**
 * Updater Controller for My Plugin.
 * Extends RISE CRM's built-in Security_Controller to automatically restrict access 
 * to logged-in administrator users only.
 */
class Updater extends Security_Controller {

    function __construct() {
        parent::__construct();
        // Force strict administrator-only authorization check
        $this->access_only_admin();
    }

    /**
     * Polls the GitHub Repository Releases API to check for updates (Non-blocking daily routine).
     */
    public function check_for_updates() {
        // Enforce POST requests to block hotlinking
        if ($this->request->getMethod() !== 'post') {
            return $this->response->setStatusCode(405)->setJSON(array("success" => false, "message" => "Method Not Allowed"));
        }

        // Verify that cURL extension is active on the host environment
        if (!function_exists('curl_init')) {
            return $this->response->setJSON(array("success" => false, "message" => "cURL PHP extension is required but not installed on this server."));
        }

        $repo = MY_PLUGIN_GITHUB_REPO;
        $url = "https://api.github.com/repos/{$repo}/releases/latest";

        // Query the GitHub API securely using standard cURL parameters
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        // GitHub API strictly requires a valid User-Agent string
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'User-Agent: rise-crm-my-plugin-self-updater',
            'Accept: application/vnd.github.v3+json'
        ));

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $Settings_model = model("App\Models\Settings_model");
        $today = date("Y-m-d");

        // Parse and validate the response
        if ($http_code !== 200 || empty($response)) {
            // Silently cache check for today anyway to prevent flooding GitHub API if their rate limits are hit
            $Settings_model->save_setting("my_plugin_last_check_date", $today);
            return $this->response->setJSON(array(
                "success" => false, 
                "message" => "Failed to reach GitHub Releases API. HTTP Code: " . $http_code
            ));
        }

        $release = json_decode($response, true);
        if (empty($release) || !isset($release['tag_name'])) {
            $Settings_model->save_setting("my_plugin_last_check_date", $today);
            return $this->response->setJSON(array("success" => false, "message" => "Invalid response format from GitHub."));
        }

        $latest_version = $release['tag_name'];

        // Retrieve local plugin metadata version dynamically
        $meta = get_plugin_meta_data(MY_PLUGIN_NAME);
        $local_version = isset($meta['version']) ? trim($meta['version']) : '1.0.0';

        // Normalize version strings (removing leading 'v' prefix if present)
        $clean_latest = ltrim($latest_version, 'vV');
        $clean_local = ltrim($local_version, 'vV');

        // Evaluate if a newer update build exists
        if (version_compare($clean_latest, $clean_local, '>')) {
            // Find ZIP asset download url, default to source zipball
            $download_url = '';
            if (isset($release['assets']) && is_array($release['assets'])) {
                foreach ($release['assets'] as $asset) {
                    if (isset($asset['name']) && substr($asset['name'], -4) === '.zip') {
                        $download_url = $asset['browser_download_url'];
                        break;
                    }
                }
            }

            if (empty($download_url)) {
                $download_url = isset($release['zipball_url']) ? $release['zipball_url'] : '';
            }

            if (empty($download_url)) {
                $Settings_model->save_setting("my_plugin_last_check_date", $today);
                return $this->response->setJSON(array("success" => false, "message" => "No ZIP download target located in GitHub Release."));
            }

            // Cache discovery findings safely inside settings database
            $Settings_model->save_setting("my_plugin_last_check_date", $today);
            $Settings_model->save_setting("my_plugin_update_available", "1");
            $Settings_model->save_setting("my_plugin_latest_version", $latest_version);
            $Settings_model->save_setting("my_plugin_download_url", $download_url);

            return $this->response->setJSON(array(
                "success" => true,
                "update_available" => true,
                "latest_version" => $latest_version,
                "download_url" => $download_url
            ));
        }

        // Cache negative result to bypass repeated checking today
        $Settings_model->save_setting("my_plugin_last_check_date", $today);
        $Settings_model->save_setting("my_plugin_update_available", "0");
        $Settings_model->save_setting("my_plugin_latest_version", $latest_version);

        return $this->response->setJSON(array(
            "success" => true,
            "update_available" => false,
            "message" => "Plugin is already up to date."
        ));
    }

    /**
     * Executes the secure download, Zip Slip directory traversal checking, extraction, and update migrations.
     */
    public function update() {
        // Enforce POST requests to prevent malicious trigger execution
        if ($this->request->getMethod() !== 'post') {
            return $this->response->setStatusCode(405)->setJSON(array("success" => false, "message" => "Method Not Allowed"));
        }

        // Verify standard requirements
        if (!class_exists('\ZipArchive')) {
            return $this->response->setJSON(array("success" => false, "message" => "ZipArchive PHP class is required but not enabled on this server."));
        }

        // Retrieve cached update variables
        $download_url = get_setting("my_plugin_download_url");
        $latest_version = get_setting("my_plugin_latest_version");

        if (empty($download_url)) {
            return $this->response->setJSON(array("success" => false, "message" => "No update package URL found. Please perform an update check first."));
        }

        // Establish safe temporary download file inside RISE CRM writable upload folder
        $temp_dir = WRITEPATH . 'uploads';
        if (!is_dir($temp_dir)) {
            mkdir($temp_dir, 0755, true);
        }
        $zip_filepath = $temp_dir . DIRECTORY_SEPARATOR . 'my_plugin_update_temp.zip';

        // Open handle to write download
        $fp = fopen($zip_filepath, 'w+');
        if (!$fp) {
            return $this->response->setJSON(array("success" => false, "message" => "Failed to initialize writable storage for update download. Check server permissions on writable/ directory."));
        }

        // Initialize secure download cURL pipeline
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $download_url);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Direct redirects safely to AWS S3 / CDN
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('User-Agent: rise-crm-my-plugin-self-updater'));

        $success = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$success || $http_code !== 200) {
            if (file_exists($zip_filepath)) {
                unlink($zip_filepath);
            }
            return $this->response->setJSON(array("success" => false, "message" => "Failed to download update package. HTTP Code: " . $http_code));
        }

        // Open ZIP and perform extensive validation
        $zip = new \ZipArchive();
        if ($zip->open($zip_filepath) !== true) {
            unlink($zip_filepath);
            return $this->response->setJSON(array("success" => false, "message" => "Unable to read the downloaded ZIP update package. File may be corrupted."));
        }

        // 1. Core Zip Slip directory traversal vulnerability scanner
        // Validates all paths inside ZIP to ensure no malicious breakout targets exist
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry_name = $zip->getNameIndex($i);

            // Absolute path or directory traversal detection
            if (strpos($entry_name, '..') !== false || strpos($entry_name, '/') === 0 || strpos($entry_name, '\\') === 0) {
                $zip->close();
                unlink($zip_filepath);
                return $this->response->setJSON(array(
                    "success" => false, 
                    "message" => "Security Alert: Malicious directory traversal (Zip Slip) attempt detected inside update ZIP. Operation aborted."
                ));
            }
        }

        // 2. Identify top-level repository parent wrapping folders (e.g. from GitHub auto-archive zips)
        // If present, it strips the wrapper so files resolve directly in plugins/my_plugin/
        $top_folder_prefix = '';
        $first_entry = $zip->getNameIndex(0);
        $first_slash = strpos($first_entry, '/');
        
        if ($first_slash !== false) {
            $candidate_prefix = substr($first_entry, 0, $first_slash + 1);
            $has_uniform_prefix = true;
            
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (strpos($name, $candidate_prefix) !== 0) {
                    $has_uniform_prefix = false;
                    break;
                }
            }
            
            if ($has_uniform_prefix) {
                $top_folder_prefix = $candidate_prefix;
            }
        }

        // 3. Extract safe files and folders to destination
        $destination_root = PLUGINPATH . MY_PLUGIN_NAME . '/';

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            // Strip prefix if wrapping folder detected
            $relative_path = $name;
            if (!empty($top_folder_prefix) && strpos($name, $top_folder_prefix) === 0) {
                $relative_path = substr($name, strlen($top_folder_prefix));
            }

            // Skip top-level empty root index references
            if ($relative_path === '' || $relative_path === '/') {
                continue;
            }

            $destination_path = $destination_root . $relative_path;

            // Handle directory creation
            if (substr($name, -1) === '/' || substr($name, -1) === '\\') {
                if (!is_dir($destination_path)) {
                    mkdir($destination_path, 0755, true);
                }
            } else {
                // Ensure parent directory exists for nested files
                $parent_directory = dirname($destination_path);
                if (!is_dir($parent_directory)) {
                    mkdir($parent_directory, 0755, true);
                }

                // Extract and write file
                $file_contents = $zip->getFromIndex($i);
                if ($file_contents === false) {
                    $zip->close();
                    unlink($zip_filepath);
                    return $this->response->setJSON(array("success" => false, "message" => "Extraction failure: Failed to read file data from ZIP entry: " . $name));
                }

                if (file_put_contents($destination_path, $file_contents) === false) {
                    $zip->close();
                    unlink($zip_filepath);
                    return $this->response->setJSON(array("success" => false, "message" => "Extraction failure: Failed to write file to disk: " . $destination_path));
                }
            }
        }

        $zip->close();
        unlink($zip_filepath); // Clean up temporary zip safely

        // 4. Trigger safe SQL database migrations in index.php (Zero Data Loss design)
        app_hooks()->do_action("app_hook_update_plugin_" . MY_PLUGIN_NAME);

        // Reset settings caches
        $Settings_model = model("App\Models\Settings_model");
        $Settings_model->save_setting("my_plugin_update_available", "0");
        $Settings_model->save_setting("my_plugin_last_check_date", date("Y-m-d"));

        return $this->response->setJSON(array(
            "success" => true,
            "message" => "Plugin successfully updated to v" . $latest_version . "! Reloading..."
        ));
    }
}
