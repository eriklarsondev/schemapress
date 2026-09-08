<!-- group: Content API -->
<!-- description: Ordering by any field, paging by page or by offset, and what the totals actually count. -->

## Sorting & pagination

### Sorting

:::tabs
```http REST
?sort=full_name
?sort=-full_name
?sort=role,-full_name
?sort[0]=role:asc&sort[1]=full_name:desc
```
```php PHP
->sort('full_name')
->sort('full_name', 'desc')
```
```twig Twig
.sort('full_name')
.sort('full_name', 'desc')
```
:::

Direction defaults to `asc`. A leading `-` means descending, and `field:desc` says the
same thing — use whichever reads better. Alongside your own fields, five names sort on the
entry itself: `title`, `slug`, `createdAt`, `updatedAt`, `publishedAt`.

:::note One field sort per request
WordPress orders by a single custom field at a time, so a second field-based sort is
ignored rather than quietly reordering the first. The entry-level names above —
`title`, `slug`, `createdAt`, `updatedAt`, `publishedAt` — can be combined freely.
:::

### Pagination

Either of Strapi's two styles. By page:

```http
?page=2&limit=50
```

```json
"meta": {
  "pagination": {
    "page": 2,
    "pageSize": 50,
    "pageCount": 4,
    "total": 173
  }
}
```

Or by offset:

```http
?start=25&limit=25
```

```json
"meta": {
  "pagination": {
    "start": 25,
    "limit": 25,
    "total": 173
  }
}
```

```php
->limit(50)->page(2)
```

| | REST | PHP and Twig |
| --- | --- | --- |
| Default size | 25 | 10 |
| Maximum size | 100 | 100 |

The defaults differ on purpose. A template that loops a collection without asking for a
size should not render two thousand rows; a client that asked for a page is usually
drawing a page.

`total` is the count of what **matched the filters**, not the size of the collection, so
paging through a filtered set works correctly. In PHP that count is `->total()`, and
`->count()` is how many the current page returned.
