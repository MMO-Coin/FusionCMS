# Store, history and guild pages

Store orders, GM history and guild rosters now load 25 rows per page. Page links work with browser back and forward, and keep the selected filters in the URL. Direct model callers can request up to 100 rows. Invalid bounds return no rows.

Store orders can be filtered by status and account name. The failed-order view covers the last seven days; successful orders remain available through pagination. Item details use an expandable list, including a fallback for deleted items or characters. Account, item and character names are loaded in batches, with character lookups grouped by realm.

Refunds require a POST request, a valid CSRF token and refund permission. The failed-order claim and VP/DP credit share one website-database transaction on the CMS InnoDB tables. Refunded orders retain their original details with `completed = -1`; they leave the failed-order list. Repeating a refund cannot credit the same order twice. A failed request leaves the row visible so the administrator can check its status. Checkout completion uses the order ID recorded for that checkout.

Guild rosters keep the emulator's existing joins and rank data. Pages use rank rights, rank number and character ID for stable ordering. Faction comes from the race already selected with each member. Counts cover the same visible roster query, including its treatment of missing character or rank records. Each cached page includes its own offset and title, with a five-minute lifetime.

## Website database update

Fresh installations include the new indexes. Existing installations can apply `application/modules/install/SQL/updates/2026_11_02_03_list_query_indexes.sql` to the website database using their normal SQL client or update process. Back up that database before a production schema update.

The update adds two order indexes and two GM history indexes. It preserves records, can be rerun, and reuses equivalent custom indexes. Queries continue to work before the indexes are installed. No realm, character or world database schema changes are required.

The indexes are checked with seeded MySQL `EXPLAIN` plans. GM log types remain text; this update does not shorten that column or add a full-column index to it.

## Public theme assets

The bundled Default and Heaven themes load DataTables for pages that use tables, carousel code for rendered carousels, and slider code when the theme renders a slider. Inactive sidebox scripts and styles are omitted from the shared bundle. Shared navigation, alerts and form controls stay available.

Custom modules with dependencies used only in external scripts can declare them before rendering:

```php
$this->template->requireAssets(['datatables']);
$this->template->view($content, false, 'modules/example/js/page.js');
```

Supported public groups are `datatables`, `carousel` and `slider`. Themes with their own asset configuration keep their existing loading behavior. The bundled themes give each dependency combination a separate minified filename, including the theme and local file metadata in its fingerprint. This prevents one page's reduced bundle from replacing another page's dependencies and refreshes the filename when local assets change.

The optional shared AJAX router follows full-page responses with a normal navigation so dependencies are loaded before page scripts execute. Clear any proxy or CDN cache serving old HTML after updating.
