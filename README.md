# FiQueLa Benchmarks

A standalone suite of reproducible end-to-end benchmarks and profiles for [FiQueLa](https://github.com/1biot/FiQueLa), a PHP library for querying files.

## Quick start

Use Composer 2 and PHP 8.2, 8.3, or 8.4 with the locked dependencies. Enable `dom`, `fileinfo`, `iconv`, `libxml`, `mbstring`, `simplexml`, `xmlreader`, and `zip`. Xdebug 3 is required only for function profiling. Use fixture paths without spaces or parentheses because the FQL file-source syntax in these scenarios uses unquoted paths.

```bash
composer install
composer bench:test
composer bench:list
composer bench:run -- --sizes=10000 --scenarios=csv_scan,csv_where,xml_where --repeat=3
```

Pass benchmark options after `--`. The runner generates missing fixtures automatically. To generate them separately, run `composer bench:generate -- --sizes=10000,100000,1000000`.

Each run writes three files under `reports/<timestamp>-<pid>/`. Set `--out` to choose the directory.

- `results.json` contains raw measurements, scenario and environment metadata, statistics, and sampled result fingerprints.
- `summary.csv` contains the results in tabular form.
- `summary.md` contains the human-readable report.

## Composer commands

| Command | Purpose | Options |
|---|---|---|
| `composer bench:list` | List scenarios and their groups | |
| `composer bench:generate` | Generate deterministic fixtures | `--sizes`, `--fixtures`, `--force` |
| `composer bench:run` | Run benchmarks | `--sizes`, `--scenarios`, `--repeat`, `--warmup`, `--out`, `--fixtures`, `--no-generate`, `--memory`, `--timeout`, `--jit` |
| `composer bench:profile` | Profile one scenario | `--type`, `--scenario`, `--size`, `--out`, `--fixtures`, `--no-generate`, `--memory`, `--timeout` |
| `composer bench:compare` | Compare two reports | `--baseline`, `--candidate`, `--metric`, `--threshold`, `--fail-on-regression` |
| `composer bench:smoke` | Test the harness using the native PHP scenario | |
| `composer bench:smoke:fql` | Test FQL queries, providers, and EXPLAIN ANALYZE | |
| `composer bench:test` | Run both smoke suites | |

The default benchmark uses 10,000 and 100,000 rows, five measured iterations, and one warm-up per scenario and size. The default worker memory limit is `1024M` and its timeout is 3,600 seconds. The runner writes failed measurements to the report and exits with status 2 if any measurement failed.

## GitHub Actions

**Benchmark harness smoke** runs both smoke suites on pushes and pull requests using PHP 8.2 and 8.4.

Once the workflow is on the default branch, open **Actions → FiQueLa benchmarks → Run workflow** to run the full pipeline on a selected branch. It runs on PHP 8.4 and accepts fixture sizes, scenario names, the number of measured iterations and warm-ups, and an optional profile mode, scenario, and size.

- Read the Markdown report in the workflow run summary or download the JSON, CSV, and Markdown files from the **benchmark-reports** artifact. The workflow publishes reports when available even if a measurement failed.
- Select `phase`, `explain`, or `xdebug` to download the optional **benchmark-profile** artifact. Xdebug mode includes the Cachegrind snapshot.

Use hosted-runner measurements to inspect a run and track broad trends. For regression thresholds, compare repeated runs on the same machine with the same configuration and fixtures.

## Scenarios and fixtures

Use `--scenarios=default` for the default group, `--scenarios=all` to include advanced and heavy scenarios, or pass a comma-separated list. Select `native_csv_scan` explicitly for the native PHP reference.

| Scenario | Workload |
|---|---|
| `csv_scan` | Sequential CSV reading and row creation |
| `csv_projection` | Column selection |
| `csv_where` | Filtering, numeric conversion, and field access |
| `csv_functions` | Expression evaluation, including `UPPER` and `ROUND` |
| `csv_group` | `GROUP BY`, `COUNT`, and `SUM` |
| `csv_distinct` | Deduplication |
| `csv_topk` | `ORDER BY ... LIMIT 100` |
| `csv_join` | Hash join with 50 categories |
| `xml_scan`, `xml_where` | `XMLReader`, structure conversion, and filtering |
| `ndjson_where` | NDJSON streaming and filtering |
| `jsonstream_where` | Streaming JSON through `jsonFile(...)` |
| `ndjson_nested` | Nested field access (advanced) |
| `csv_union_all` | Reading an additional source and applying `UNION ALL` (advanced) |
| `csv_full_sort` | Full sort with potentially high memory use (heavy) |
| `native_csv_scan` | Baseline for CSV reading with PHP's `fgetcsv()` |

The generator streams the same logical data to CSV, XML, NDJSON, and JSON. JSON and NDJSON also contain nested `meta` objects. Each dataset has a `manifest.json` with the generator version and SHA-256 checksums. Existing valid datasets are reused. Use `--force` to regenerate them. The native CSV baseline measures raw reading with `fgetcsv()`; FQL scenarios include query processing.

## Measurement methodology

Each measured iteration starts a fresh PHP process with Xdebug disabled. The worker records:

1. `prepare_ms` for bootstrap, autoloading, and `Provider::fql($sql)`, including FQL parsing.
2. `execute_ms` for `$query->execute()`. Execution can be lazy, and some operations can materialize data in this phase.
3. `consume_ms` for iterating over the full result with `foreach`.
4. `total_ms` for the sum of those phases, excluding OS process startup.

The reports include PHP allocator peak memory, peak process RSS from Linux `/proc/self/status` where available, and nominal input rows per second based on fixture size. With early termination or pushdown, the nominal rate can differ from the rate of rows actually read.

Warm-ups are excluded from the statistics. The worker checks the expected result count and hashes the first, every 1,024th, and last result row. Run FiQueLa's own tests to verify query semantics when changing the library. For comparable timings, keep the fixtures, hardware, PHP version, extensions, and settings consistent. Use repeated measurements and avoid concurrent workloads.

## Profiling

```bash
composer bench:profile -- --type=phase --scenario=csv_where --size=100000
composer bench:profile -- --type=explain --scenario=csv_where --size=100000
composer bench:profile -- --type=xdebug --scenario=csv_where --size=100000
```

- **Phase** saves timings, memory, and the result fingerprint in `profile.json`.
- **EXPLAIN ANALYZE** runs the query with that prefix and reports FiQueLa's internal phases, row counts, and timings in the console and in `profile.json`.
- **Xdebug** saves `profile.json` and a `cachegrind.out.<pid>` snapshot, optionally compressed as `.gz`. Check `php -v` to confirm that the CLI interpreter used by Composer has Xdebug 3 loaded.

In PhpStorm, choose **Tools → Analyze Xdebug Profiler Snapshot…** and open the Cachegrind snapshot. Use **Execution Statistics** for call counts and timings, and **Call Tree** for the execution paths. KCachegrind and QCachegrind can also open these profiles. Decompress `.gz` snapshots before opening them in QCachegrind.

**Own time** measures time spent directly in a function. **Inclusive time** includes the work of functions it calls. **Calls** also counts resumptions of PHP generators. A single `applyBaseStream()` generator that yields 60,972 rows can therefore appear 60,973 times, including the final resumption to finish iteration. Use the regular benchmark for latency comparisons because instrumentation adds overhead.

## Benchmark a local FiQueLa checkout

Install dependencies in the FiQueLa checkout and point `FIQUELA_AUTOLOAD` at its Composer autoloader. This takes precedence over the benchmark project's installed dependency.

```bash
export FIQUELA_AUTOLOAD="$(realpath ../FiQueLa/vendor/autoload.php)"
composer bench:smoke:fql
composer bench:run -- --sizes=10000,100000 --out=reports/before --repeat=7
# Change the FiQueLa checkout before starting the next run.
composer bench:run -- --sizes=10000,100000 --out=reports/after --repeat=7
composer bench:compare -- --baseline=reports/before --candidate=reports/after --threshold=10
```

Unset `FIQUELA_AUTOLOAD` to use the dependency installed in this project. The comparison matches entries by scenario and fixture size. It uses median elapsed time by default (`--metric=time`). With `--fail-on-regression`, an increase in the selected metric beyond `--threshold` exits with status 2. The comparison flags environment differences and reports observed changes without testing statistical significance.

### Measure memory for `ORDER BY … LIMIT` across two versions

`csv_topk` runs `SELECT id, price … ORDER BY price DESC LIMIT 100`. Use a FiQueLa checkout from before the optimization and one containing it. Install dependencies in both checkouts, then run these commands from the benchmark project. Replace the two checkout paths with your own.

```bash
FIQUELA_AUTOLOAD="$(realpath ../FiQueLa-before/vendor/autoload.php)" \
  composer bench:run -- --scenarios=csv_topk --sizes=100000 --repeat=5 --out=reports/topk-before

FIQUELA_AUTOLOAD="$(realpath ../FiQueLa-after/vendor/autoload.php)" \
  composer bench:run -- --scenarios=csv_topk --sizes=100000 --repeat=5 --out=reports/topk-after

composer bench:compare -- --baseline=reports/topk-before --candidate=reports/topk-after --metric=php-peak
```

`php-peak` compares the median per-run peak of PHP-managed memory in MiB. A negative delta means the candidate used less memory. On Linux, use `--metric=rss-peak` for peak memory of the whole process. To measure how memory scales with input size, pass `--sizes=100000,1000000` to both runs. Check the result count and sampled fingerprint in both reports to verify that the runs produced the same sampled output.

### Compare JIT settings

```bash
composer bench:run -- --sizes=100000 --scenarios=csv_where,csv_functions,csv_group --jit=off --out=reports/jit-off
composer bench:run -- --sizes=100000 --scenarios=csv_where,csv_functions,csv_group --jit=on --out=reports/jit-on
composer bench:compare -- --baseline=reports/jit-off --candidate=reports/jit-on
```

The default `--jit=default` respects the CLI `php.ini`. With Zend OPcache available, `--jit=on` enables CLI OPcache and sets a 64 MB JIT buffer in tracing mode. `--jit=off` disables the JIT buffer.

## Project layout

- `bin/` contains the entry points invoked by Composer and the isolated benchmark worker.
- `src/` contains the fixtures, scenario definitions, worker support, and reporting code.
- `tests/` contains the harness and FiQueLa smoke suites.
- `.github/workflows/` contains the smoke and benchmark pipelines.
- `fixtures/`, `profiles/`, and `reports/` contain generated files excluded from Git.

To add a scenario, add an entry to `src/Scenarios.php` and extend `expectedCount()` if the new scenario needs a new result-count rule. For XML and JSON, match the query path to the generated structure.

## References

- [FQL syntax](https://docs.fiquela.io/querying/fql-syntax)
- [EXPLAIN ANALYZE](https://docs.fiquela.io/advanced/explain-analyze)
- [Streaming and in-memory results](https://docs.fiquela.io/concepts/stream-vs-memory)
- [Xdebug profiling](https://xdebug.org/docs/profiler)
- [Viewing Xdebug snapshots in PhpStorm](https://www.jetbrains.com/help/phpstorm/profiling-with-xdebug.html#analyze-xdebug-profiling-data)
