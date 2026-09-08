<!-- group: Content API -->
<!-- description: The three refusals, what each one means, and why a collection that is switched off answers 403. -->

## Errors

WordPress's standard error shape, with the status in the body as well as in the response:

```json
{
  "code": "schemapress_api_disabled",
  "message": "This collection is not published to the API. Turn on Public API in its Settings.",
  "data": {
    "status": 403
  }
}
```

| Status | Code | Means |
| --- | --- | --- |
| `404` | `schemapress_unknown_collection` | No collection with that name |
| `403` | `schemapress_api_disabled` | It exists, but its Public API switch is off |
| `404` | `schemapress_not_found` | No **published** entry with that id |

`403` rather than `404` for a disabled collection is deliberate. You are building against a
collection you can see in the admin, and "not found" would send you hunting for a typo that
is not there.
