<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * mounts the section editor on posts governed by a schema.
 *
 * unlike the schema builder, content is submitted with the post form rather
 * than over REST: the React app mirrors its state into a hidden input, so
 * Publish saves the post and its sections in one action and revisions stay
 * coherent. this works unchanged in both the classic and block editors.
 */
class ContentEditor
{
    const NONCE_ACTION = 'schemapress_save_content';
    const NONCE_NAME = 'schemapress_content_nonce';
    const INPUT_NAME = 'schemapress_content';

    /**
     * hooks the editor metabox, its assets and the save handler.
     */
    public function __construct()
    {
        add_action('add_meta_boxes', [$this, 'registerMetaBox'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('save_post', [$this, 'save'], 10, 2);

        add_filter('use_block_editor_for_post', [$this, 'suppressBlockEditor'], 10, 2);
        add_action('admin_head', [$this, 'suppressContentField']);
    }

    /**
     * whether the post content editor should be taken off a post's screen.
     *
     * on a bound page the content lives in sections, so the post_content field
     * is a second, unused writing surface — and one that quietly invites
     * authors to put content somewhere the front-end never reads.
     *
     * @param integer $post_id
     *
     * @return boolean
     */
    private function shouldSuppressEditor($post_id)
    {
        if (!$post_id || !Binding::isBound($post_id)) {
            return false;
        }

        /**
         * filters whether the post content editor is hidden on a bound post.
         *
         * @param boolean $suppress
         * @param integer $post_id
         */
        return (bool) apply_filters('schemapress/suppress_editor', true, $post_id);
    }

    /**
     * falls back to the classic screen on bound posts, where the section
     * metabox is a first-class citizen rather than a panel bolted under the
     * block canvas.
     *
     * @param boolean  $use
     * @param \WP_Post $post
     *
     * @return boolean
     */
    public function suppressBlockEditor($use, $post)
    {
        if ($post instanceof \WP_Post && $this->shouldSuppressEditor($post->ID)) {
            return false;
        }

        return $use;
    }

    /**
     * drops editor support on the bound post's edit screen.
     *
     * done this late, and only for the screen being rendered, so the post type
     * keeps its editor support everywhere else — including REST and any other
     * post of the same type that is not bound.
     *
     * @return void
     */
    public function suppressContentField()
    {
        $screen = get_current_screen();

        if (!$screen || $screen->base !== 'post') {
            return;
        }

        if ($this->shouldSuppressEditor(absint(get_the_ID()))) {
            remove_post_type_support($screen->post_type, 'editor');
        }
    }

    /**
     * registers the editor on any post whose template is bound to a schema.
     *
     * @param string   $post_type
     * @param \WP_Post $post
     *
     * @return void
     */
    public function registerMetaBox($post_type, $post)
    {
        if ($post_type === Schema::POST_TYPE || !$post instanceof \WP_Post) {
            return;
        }

        $schema_id = Binding::schemaId($post->ID);

        if (!$schema_id) {
            return;
        }

        add_meta_box(
            'schemapress-content',
            get_the_title($schema_id),
            [$this, 'render'],
            $post_type,
            'normal',
            'high'
        );
    }

    /**
     * renders the mount point, the hidden state mirror and the nonce.
     *
     * the textarea holds the last saved JSON so the form still round-trips
     * correctly if the bundle fails to load — content is never silently
     * dropped by a JavaScript error.
     *
     * @param \WP_Post $post
     *
     * @return void
     */
    public function render($post)
    {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);

        printf(
            '<div id="schemapress-content-root" data-post="%d"></div>',
            (int) $post->ID
        );

        printf(
            '<textarea id="%1$s" name="%1$s" hidden readonly>%2$s</textarea>',
            esc_attr(self::INPUT_NAME),
            esc_textarea(wp_json_encode(Content::get($post->ID)))
        );
    }

    /**
     * enqueues the editor bundle on bound post edit screens.
     *
     * @param string $hook
     *
     * @return void
     */
    public function enqueue($hook)
    {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        $post_id = absint(get_the_ID());
        $schema_id = $post_id ? Binding::schemaId($post_id) : 0;

        if (!$schema_id) {
            return;
        }

        Assets::enqueue('page-editor', [
            'rest' => Assets::restContext(),
            'postId' => $post_id,
            'schemaId' => $schema_id,
            'schemaTitle' => get_the_title($schema_id),
            'definition' => SchemaRepository::definition($schema_id),
            'layoutOptions' => Layout::forClient(),
            'content' => Content::get($post_id),
            'inputName' => self::INPUT_NAME,
            'appUrl' => esc_url_raw(admin_url('admin.php?page=' . Admin::PAGE_SLUG)),
        ]);
    }

    /**
     * persists submitted section content.
     *
     * @param integer  $post_id
     * @param \WP_Post $post
     *
     * @return void
     */
    public function save($post_id, $post)
    {
        if (!$this->shouldSave($post_id, $post)) {
            return;
        }

        // the payload is JSON in a single field; unslash before decoding or
        // every quote in the content arrives escaped
        $raw = wp_unslash($_POST[self::INPUT_NAME]);
        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return;
        }

        Content::save($post_id, $decoded);
    }

    /**
     * whether the current request is a genuine, authorised content save.
     *
     * @param integer  $post_id
     * @param \WP_Post $post
     *
     * @return boolean
     */
    private function shouldSave($post_id, $post)
    {
        if (!isset($_POST[self::INPUT_NAME], $_POST[self::NONCE_NAME])) {
            return false;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return false;
        }

        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return false;
        }

        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION)) {
            return false;
        }

        return current_user_can('edit_post', $post_id);
    }
}
