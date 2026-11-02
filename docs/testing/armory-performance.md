# Armory database profiling

`tests/armory_profile.php` measures uncached Armory queries, repeated pages with a primed count cache, and an experimental item-page query, using synthetic data and the Trinity and VMaNGOS column mappings. Search still matches text anywhere in the name. No production data is used.

## Run a profile

Run from the checkout root with Composer dependencies installed and PHP's `mysqli` and `mbstring` extensions enabled. Use a disposable MySQL or MariaDB server you own. The tool connects as `root`, creates a randomly named schema, and removes it after checking its ownership marker. It requires an explicit literal loopback host and does not load the site's database configuration.

Set the port and password to those of your disposable server. These examples create a new report directory. The output directory must already exist, and the output file must be new.

Bash:

```bash
export FUSION_TEST_MYSQL_HOST=127.0.0.1
export FUSION_TEST_MYSQL_PORT=3306
export FUSION_TEST_MYSQL_PASSWORD='your-disposable-server-root-password'
profile_dir="$(mktemp -d)"
php tests/armory_profile.php --rows=10000 --iterations=9 --output="$profile_dir/armory-10000.json"
php tests/armory_profile.php --rows=100000 --iterations=9 --output="$profile_dir/armory-100000.json"
```

PowerShell:

```powershell
$env:FUSION_TEST_MYSQL_HOST = '127.0.0.1'
$env:FUSION_TEST_MYSQL_PORT = '3306'
$env:FUSION_TEST_MYSQL_PASSWORD = 'your-disposable-server-root-password'
$profileDir = Join-Path ([IO.Path]::GetTempPath()) ('fusion-armory-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $profileDir | Out-Null
php tests/armory_profile.php --rows=10000 --iterations=9 --output="$profileDir/armory-10000.json"
php tests/armory_profile.php --rows=100000 --iterations=9 --output="$profileDir/armory-100000.json"
```

Use `--help` for accepted limits. The JSON includes the generated table definitions, indexes, SQL, `EXPLAIN` estimates, session handler-counter deltas, individual samples and cleanup confirmation.

## What it measures

The main workloads follow the controller's database path: count matching records first, then call the actual model page method only when the offset is below that count. Pages contain at most ten rows. Every sample checks the count, result IDs, ordering and guild member totals. Workloads cover selective, common, interior and missing matches, with first and deeper pages where applicable. The separate item-query comparison measures page queries without a count.

By default, the tables contain only the columns the queries need. Searchable tables have a primary key and a name index; guild members have a composite guild/member primary key. Names and item levels follow a deterministic distribution. Optional item padding and name-index settings test other table shapes. These fixtures do not reproduce a particular server's data, indexes or statistics.

Each workload has one excluded warm-up, followed by the requested number of single-client samples against warm buffers. The local runs below used nine samples, MariaDB 11.4.13, PHP 8.5.12 and a 256 MiB InnoDB buffer pool. MariaDB's query cache was disabled for the profiling session. Timings include PHP model and database work, and exclude HTTP handling, result formatting, icons, browser rendering and concurrent traffic. The report's p95 is the highest of nine samples at this sample size; it is not a reliable production tail-latency estimate.

## Local results

Recorded on November 2, 2026. These medians are milliseconds for the first `Hero` search page, including its count. Each searchable table contained either 10,000 or 100,000 rows; the search matched 200 or 2,000 records respectively.

| Mapping | Result type | 10,000 rows | 100,000 rows |
| --- | --- | ---: | ---: |
| Trinity | Characters | 1.417 | 11.629 |
| Trinity | Items | 2.873 | 26.059 |
| Trinity | Guilds | 1.608 | 12.091 |
| VMaNGOS | Characters | 1.447 | 12.399 |
| VMaNGOS | Items | 2.917 | 26.408 |
| VMaNGOS | Guilds | 1.625 | 12.114 |

Character and item pages execute two queries each. Guild pages execute three, with member aggregation restricted to the guild IDs on that page. The measured member query used the composite primary key with range access.

The count remains the main character-search cost. In the 100,000-row Trinity run, the count alone took 11.377 ms compared with 11.629 ms for count plus the first page. Its plan scanned the name index, and each count sample added exactly 100,000 `Handler_read_next` operations. Item pages also used an `ALL` scan with `Using filesort` for item-level ordering, so their page query remains a separate cost.

`EXPLAIN` row figures are optimizer estimates. The handler counters describe storage-engine read activity, rather than exact rows examined; see the [MariaDB status-variable reference](https://mariadb.com/docs/server/server-management/variables-and-modes/server-status-variables). These local measurements do not establish production capacity.

## Count cache comparison

Armory now keeps up to 128 search counts for 30 seconds when site caching is enabled. Pagination can reuse a count while fetching current page rows. Submitting Search again refreshes the count. See the [Armory guide](../admin-assets.md#armory-search) for the cache policy.

A second local run used the same server, PHP version, buffer-pool size, 100,000-row fixture and nine-sample method. The profiler primed the filesystem count cache before measuring the same first `Hero` page. These figures compare uncached count plus page against cached count plus a fresh page within that run:

| Mapping | Result type | Uncached median ms | Cache-hit median ms | SQL queries before / after |
| --- | --- | ---: | ---: | --- |
| Trinity | Characters | 11.596 | 0.402 | 2 / 1 |
| Trinity | Items | 26.350 | 14.860 | 2 / 1 |
| Trinity | Guilds | 11.950 | 0.696 | 3 / 2 |
| VMaNGOS | Characters | 11.650 | 0.492 | 2 / 1 |
| VMaNGOS | Items | 26.193 | 14.963 | 2 / 1 |
| VMaNGOS | Guilds | 11.932 | 0.641 | 3 / 2 |

The cache-assisted measurements use the count-cache helper with the model's count and page methods. Initial cache filling and explicit Search refreshes still run the count query. These are single-client, warm-buffer database-path timings, excluding HTTP and browser work. The profiler checks that each primed page removes exactly one SQL query and still returns the expected current rows; CI has no timing threshold.

## Remaining search cost

The tool also compares contains and prefix counts as an analytical experiment. At 100,000 Trinity character rows, `Hero%` took 0.266 ms and matched 1,000 names, while `%Hero%` took 11.377 ms and matched 2,000. Prefix matching lost 1,000 interior matches such as `AncientHero...`, so it cannot replace contains matching without changing the feature. A constant `LIKE` pattern without a leading wildcard can support a B-tree range; the [MySQL range-optimization reference](https://dev.mysql.com/doc/refman/8.0/en/range-optimization.html) explains that distinction.

Item pages still scan and sort in this fixture after the count is cached. The query experiment below did not produce a consistent improvement, so the application keeps its current item query. Further query or index work needs measurements from representative realm data. If substring search remains expensive, an opt-in prefix or exact-search mode needs clear UI wording.

## Item-page query experiment

The `item_query_comparison` report compares the current query with a candidate that selects a limited page of item IDs and item levels, then joins those IDs to their details in the same SQL statement. Both queries use escaped contains matching and `ItemLevel DESC, entry ASC` ordering. The candidate also orders the outer query explicitly. Every sample checks all eight returned fields and the expected page IDs.

Each query has one excluded warm-up. Measured samples alternate which query runs first. The report includes execution order, SQL, `EXPLAIN` and handler-counter changes. Selective, common and interior searches have first and deep pages; a missing term checks empty results. The application skips the page query when its count is zero, so the missing-term page measurement is only an experimental comparison.

Two optional flags control the synthetic item table. `--item-padding=2048` adds 2,048 bytes of unselected data to each item row. `--item-name-index=0` omits its name index. Other tables keep their existing indexes. These flags affect only the owned temporary schema.

For example, after setting the connection variables and creating a report directory as above:

```bash
php tests/armory_profile.php --rows=100000 --iterations=9 --item-padding=2048 --item-name-index=0 --output="$profile_dir/items-wide-no-index.json"
```

PowerShell:

```powershell
php tests/armory_profile.php --rows=100000 --iterations=9 --item-padding=2048 --item-name-index=0 --output="$profileDir/items-wide-no-index.json"
```

Local comparisons used 100,000 rows per searchable table, nine measured samples, MariaDB 11.4.13, PHP 8.5.12 and a 256 MiB InnoDB buffer pool. Four runs covered compact and padded item rows, each with and without a name index. The values below are median milliseconds for `Hero` pages, excluding the count and HTTP work. The deep page starts at offset 1,990.

| Item table | Mapping | Current first | Candidate first | Current deep | Candidate deep |
| --- | --- | ---: | ---: | ---: | ---: |
| Compact, name index | Trinity | 14.62 | 14.91 | 18.98 | 14.64 |
| Compact, name index | VMaNGOS | 14.58 | 14.90 | 19.20 | 14.67 |
| Compact, no name index | Trinity | 14.32 | 14.49 | 18.56 | 14.47 |
| Compact, no name index | VMaNGOS | 14.63 | 14.59 | 18.94 | 14.62 |
| Padded, name index | Trinity | 25.02 | 25.23 | 26.37 | 27.07 |
| Padded, name index | VMaNGOS | 21.91 | 22.13 | 23.12 | 22.99 |
| Padded, no name index | Trinity | 24.44 | 24.37 | 26.46 | 26.85 |
| Padded, no name index | VMaNGOS | 26.77 | 26.75 | 27.29 | 28.84 |

The candidate helped deep pages on compact tables, including the common `Player` search at offset 5,000. That benefit did not carry over to the padded cases. First pages showed no consistent gain. In the padded Trinity first-page plan, both queries scanned the item table and used a filesort; the candidate added ten primary-key lookups and an outer sort. The rewrite therefore leaves the main scan cost in place.

These results do not justify switching the production query or choosing an offset threshold from this dataset. Armory keeps its existing search behavior and query. The benchmark remains available for testing against other synthetic table shapes. MySQL CI checks correctness and cleanup with small fixtures; it does not establish equivalent performance on MySQL or a live realm. Wider tables can put more pressure on the buffer pool, so the padding runs should not be treated as an isolated CPU comparison.

## CI coverage

GitHub CI runs two smaller smoke profiles on MySQL 8: 1,000 rows per searchable table and three measured samples on each supported PHP version. The first uses the default compact tables and name indexes. The second adds 2,048 bytes of item padding per row and omits the item name index. Correct results, captured queries and owned-schema cleanup are checked without timing pass/fail thresholds. Both JSON reports are included with the browser diagnostics and retained for seven days.
