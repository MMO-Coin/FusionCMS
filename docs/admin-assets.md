# Admin page assets

The admin theme loads shared navigation, Bootstrap and alerts on every page. Larger plugins load on the pages that use them. For example, account search gets DataTables, the dashboard gets ApexCharts, and the email template editor gets CodeMirror and its HTML modes.

The asset resolver checks rendered page controls and inline plugin calls. Existing built-in pages need no configuration. Plugins load before page scripts, and each dependency is included once.

## Custom modules

Declare a dependency before calling `Administrator::view()` when your plugin is used only in an external script or added after the page loads:

```php
$this->administrator->requireAssets(['datatables', 'datepicker']);
$this->administrator->view($content, false, 'modules/example/js/admin.js');
```

Available groups are `datatables`, `charts`, `codemirror`, `imagesloader`, `dropzone`, `carousel`, `datepicker`, `select2`, `multiselect`, `spinner`, `popup`, `jqueryui`, `sparkline`, `flot`, `morris`, `gauge`, `liquidmeter`, `iconpicker`, `appear` and `switchery`. Unknown declarations raise an error so a typo does not silently break a control. Switchery retains its existing pinned CDN URLs; the other plugins use local files and respect the configured asset CDN.

Normal admin links load a full page. JSON admin responses include an `admin_assets` object with `css`, `js` and `groups`. The shared optional AJAX router follows admin responses with a full navigation so plugins are available before inline scripts run. A custom client that inserts admin fragments must load the declared dependencies first.

After an update, clear any reverse-proxy or asset CDN cache that serves the changed template or scripts. There is no database migration for this change.

## Armory search

Armory keeps one table per result type and requests ten rows at a time. The server accepts pages up to 100 rows and rejects invalid bounds. Results use a stable ID tie breaker so records with the same name or item level do not jump between pages.

Search accepts 3 to 100 characters. `%` and `_` are treated as text. Case matching follows the realm database's existing collation, and counts use the same filter as the results. Character faction comes from the selected race without another query per row. Guild member counts cover only the guilds on the requested page, including guilds with no members or a missing leader.

Search still matches text anywhere in the name. Ordinary name indexes do not remove the cost of a leading wildcard. This update does not add indexes or alter game databases. Item search remains available through expansion ID 4, matching the existing supported schemas.
