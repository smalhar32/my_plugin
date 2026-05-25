<?php

namespace my_plugin\Config;

$routes = \Config\Services::routes();

$routes->post("my_plugin/updater/check_for_updates", "\my_plugin\Controllers\Updater::check_for_updates");
$routes->post("my_plugin/updater/update", "\my_plugin\Controllers\Updater::update");
