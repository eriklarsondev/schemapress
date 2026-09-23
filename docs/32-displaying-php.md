<!-- group: Displaying content -->
<!-- description: Reading values out of an entry in a theme file — properties, defaults, dot paths, repeater rows and escaping. -->

## Values in PHP

How to get the entries is **The query object**. This is what to do with one once you have it.

### Field keys are properties

```php
$person->full_name;                    // a text field
$person->get('full_name');             // the same
$person->get('nickname', 'Anonymous'); // with a fallback
$person->get('address.city');          // into a group
$person->has('bio');                   // non-empty?
```

An undeclared key returns the default rather than raising a notice, so markup never needs
`isset()` around content.

The entry's own identity is read as **methods**, with the parentheses:

```php
$person->id();                      // the entry's uuid
$person->title();                   // the entry's title
$person->slug();
$person->state();                   // published, modified or draft
$person->modified();
$person->isPublished();
$person->hasUnpublishedChanges();
```

:::note In PHP the parentheses are the whole distinction
A method and a property are separate things in PHP, so a field called `title` never
collides with the entry's own title — `$person->title` is **your field** and
`$person->title()` is the entry's. Nothing is shadowed and nothing needs `get()` to reach
it.

This is the one rule that differs between the two surfaces. Twig has no parentheses to tell
them apart, so there the entry's name wins and the field is reached explicitly — see
**Values in Twig**.
:::

### Images and links

Values arrive **resolved** — an image is its attachment, not an id. **Displaying values**
has the type-by-type table; these two are the ones worth knowing at the keyboard:

```php
$person->avatar['url'];                        // full size
$person->avatar['sizes']['large']['url'];      // any registered size
$person->avatar['srcset'];                     // ready for an <img>
$person->avatar['alt'];

$person->website['url'];                       // a link field
$person->website['label'];
$person->website['target'];
```

Both are `null` when empty, so guard with `has()` before reaching in:

```php
<?php if ($person->has('avatar')) : ?>
  <img src="<?php echo esc_url($person->avatar['sizes']['large']['url']); ?>"
       srcset="<?php echo esc_attr($person->avatar['srcset']); ?>"
       alt="<?php echo esc_attr($person->avatar['alt']); ?>">
<?php endif; ?>
```

### Repeaters

`rows()` returns each row as its own accessor:

```php
<?php foreach ($person->rows('links') as $link) : ?>
  <a href="<?php echo esc_url($link->get('url.url')); ?>">
    <?php echo esc_html($link->get('label')); ?>
  </a>
<?php endforeach; ?>
```

Rows nest. A repeater inside a repeater is `rows()` again on the inner accessor.

### Nothing is escaped for you

Values come back as data. It is the template's job to decide whether a given spot needs
`esc_html`, `esc_attr` or `esc_url` — which is WordPress's own convention, and the reason
the values are not pre-escaped is that a URL escaped for HTML is not a usable URL.

### A whole archive template

`archive-team.php`, complete:

```php
<?php get_header(); ?>

<main class="team">
  <h1><?php esc_html_e('The team', 'my-theme'); ?></h1>

  <?php
  $people = SchemaPress::collection('team-members')
      ->where('role', 'Engineer')
      ->sort('full_name')
      ->limit(50);

  if ($people->isEmpty()) : ?>
    <p><?php esc_html_e('Nobody here yet.', 'my-theme'); ?></p>
  <?php else : ?>
    <ul>
      <?php foreach ($people as $person) : ?>
        <li>
          <?php if ($person->has('avatar')) : ?>
            <img
              src="<?php echo esc_url($person->avatar['url']); ?>"
              alt="<?php echo esc_attr($person->avatar['alt']); ?>"
              width="<?php echo esc_attr($person->avatar['width']); ?>"
              height="<?php echo esc_attr($person->avatar['height']); ?>">
          <?php endif; ?>

          <h2><?php echo esc_html($person->full_name); ?></h2>
          <p><?php echo esc_html($person->role); ?></p>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</main>

<?php get_footer(); ?>
```
