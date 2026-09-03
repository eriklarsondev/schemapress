<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * boots the plugin's services.
 *
 * services are grouped by domain and instantiated in dependency order: the
 * field type registry must exist before any schema is parsed, and the schema
 * post type must be registered before bindings query it.
 */
class Plugin
{
    /**
     * @var Plugin|null
     */
    private static $instance = null;

    /**
     * @var array<string, object>
     */
    private $services = [];

    /**
     * boots the plugin once. repeat calls return the existing instance.
     *
     * @return Plugin
     */
    public static function boot()
    {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->registerServices();
        }

        return self::$instance;
    }

    /**
     * retrieves a booted service by its short class name.
     *
     * @param string $name
     *
     * @return object|null
     */
    public static function get($name)
    {
        $instance = self::boot();

        return isset($instance->services[$name]) ? $instance->services[$name] : null;
    }

    /**
     * instantiates each service in dependency order and keeps a reference so
     * callers can reach them without re-instantiating hooks.
     *
     * @return void
     */
    private function registerServices()
    {
        $services = [
            // the model: field types must be registered before any definition
            // is parsed, and the schema post type before content types query it
            'FieldTypes' => FieldTypes::class,
            'Schema' => Schema::class,
            'Component' => Component::class,
            'ContentType' => ContentType::class,

            // reading: Twig functions for themes that use Timber
            'Timber' => Timber::class,

            // admin: screens and transport
            'Rest' => Rest::class,
            'Admin' => Admin::class,
            'Docs' => Docs::class,
        ];

        foreach ($services as $name => $class) {
            $this->services[$name] = new $class();
        }
    }
}
