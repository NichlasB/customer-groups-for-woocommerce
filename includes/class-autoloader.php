<?php
/**
 * Autoloader for plugin classes
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCCG_Autoloader {
    /**
     * Class constructor
     */
    public function __construct() {
        spl_autoload_register(array($this, 'autoload'));
    }

    /**
     * Autoload callback
     *
     * @param string $class_name The name of the class to load
     */
    public function autoload($class_name) {
        // Only handle our plugin's classes
        if (strpos($class_name, 'WCCG_') !== 0) {
            return;
        }

        // Convert class name to filename
        $file_name = $this->get_file_name_from_class($class_name);

        // Get the file path
        $file = $this->get_file_path_from_class($class_name);

        // Load the file if it exists
        if (file_exists($file)) {
            require_once $file;
        }
    }

    /**
     * Convert class name to file name
     *
     * @param string $class_name The name of the class
     * @return string The name of the file
     */
    private function get_file_name_from_class($class_name) {
        return 'class-' . str_replace('_', '-', 
            strtolower(
                substr($class_name, 5) // Remove 'WCCG_' prefix
            )
        ) . '.php';
    }

    /**
     * Get the file path for a class
     *
     * @param string $class_name The name of the class
     * @return string The file path
     */
    private function get_file_path_from_class($class_name) {
        $file_name = $this->get_file_name_from_class($class_name);

        // Define directory mapping
        $directories = array(
            'admin' => WCCG_PATH . 'admin/',
            'public' => WCCG_PATH . 'public/',
            'includes' => WCCG_PATH . 'includes/'
        );

        // Handle admin classes
        if (strpos($class_name, 'WCCG_Admin_') === 0 || $class_name === 'WCCG_Admin') {
            return $directories['admin'] . $file_name;
        }

        // Handle public classes
        if (strpos($class_name, 'WCCG_Public') === 0) {
            return $directories['public'] . $file_name;
        }

        // Default to includes directory
        return $directories['includes'] . $file_name;
    }
}
