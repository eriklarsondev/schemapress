## What this is

SchemaPress models structured content in WordPress. You define a **collection type** —
a named shape, like Team Member or News Article — and it gives you an admin for adding
entries and an API for reading them.

There is one concept, deliberately:

| | |
| --- | --- |
| **Collection type** | A shape of content you have many of. Team Members, News Articles, Events. |
| **Field** | One piece of an entry. Name, Bio, Photo, Author. |
| **Entry** | One of the things. Ada Lovelace. |

You configure two things about a collection: its **fields** (what an entry is made of)
and its **form** (what order and width those fields sit at on the entry screen). The
first shapes the data; the second only shapes the admin screen.

Nothing here describes how content looks on the site. No widths, columns, colours or CSS
classes. A collection says what it is made of; your theme's templates say what that looks
like.

%%timber_status%%

Timber is optional. Without it the PHP API works exactly the same — only the Twig
functions are missing, and a theme not using Twig would not have called them.
