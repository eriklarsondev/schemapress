<?php

namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Boots the plugin's services, in dependency order.
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
     * Repeat calls return the existing instance.
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
     * A booted service, by its short class name.
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
     * Instantiates each service and keeps a reference, so callers can reach them
     * without re-instantiating hooks.
     *
     * @return void
     */
    private function registerServices()
    {
        $services = [
            // before anything asks what the current user may do, which every
            // route and every screen does
            'Capabilities' => Capabilities::class,

            // the model: field types must be registered before any definition
            // is parsed, and the schema post type before content types query it
            'FieldTypes' => FieldTypes::class,
            'Schema' => Schema::class,
            'Component' => Component::class,
            'ContentType' => ContentType::class,

            // the queue has to be listening before anything can queue onto it,
            // and the upgrade below is one of the things that does
            'Batch' => Batch::class,

            // after the post types exist to be queried, and before anything
            // reads an entry: it is what stops a public GET minting identifiers
            'Upgrade' => Upgrade::class,

            // reading: Twig functions for themes that use Timber, and the
            // content API for everyone else. both read the same Collection, so
            // a filter means the same thing over HTTP as it does in a template
            'Timber' => Timber::class,
            'Api' => Api::class,

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
