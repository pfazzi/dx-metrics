# dx-metrics

[![codecov](https://codecov.io/gh/pfazzi/dx-metrics/branch/main/graph/badge.svg)](https://codecov.io/gh/pfazzi/dx-metrics)

CLI tool for **volatility coupling and shared ownership analysis** of Git repositories — surfaces hidden dependencies and organisational friction from commit history.

## Installation

```bash
composer install
```

## Usage

```bash
./dx-metrics analyze <path-to-repo> [options]
```

| Option | Short | Description |
|---|---|---|
| `--since` | `-s` | Include commits after this date (e.g. `2024-01-01`) |
| `--until` | `-u` | Include commits before this date (e.g. `2024-12-31`) |
| `--threshold` | `-t` | Minimum number of co-changes to include (default: 0) |
| `--filter` | `-f` | Only show pairs where both files match this path prefix |
| `--exclude` | | Exclude files matching this glob pattern (repeatable, e.g. `--exclude=Cargo.lock --exclude='.sqlx/*'`) |
| `--output-dir` | `-o` | Directory where `coupling.dot` and `coupling.png` are written (default: current directory) |

### Example

```bash
./dx-metrics analyze /path/to/repo --since=2024-01-01 --threshold=3 --filter=src/
```

```bash
./dx-metrics analyze /path/to/repo --since=2024-01-01 --threshold=3 --output-dir=/tmp/output
```

Outputs a table of file pairs sorted by co-change count, and generates `coupling.dot` and `coupling.png` (requires [Graphviz](https://graphviz.org/)) in the output directory (current directory by default).

---

## Shared Ownership

Identifies files touched by multiple teams, highlighting potential hotspots where organizational boundaries are not reflected in the codebase (Conway's Law violations).

### Team config

Create a JSON file mapping author emails to teams:

```json
{
  "teams": {
    "platform":  ["alice@example.com", "bob@example.com"],
    "payments":  ["charlie@example.com"],
    "identity":  ["dave@example.com", "eve@example.com"]
  }
}
```

### Usage

```bash
./dx-metrics shared-ownership <path-to-repo> --teams=teams.json [options]
```

| Option | Short | Description |
|---|---|---|
| `--teams` | `-T` | Path to the teams JSON config file (required) |
| `--since` | `-s` | Include commits after this date (e.g. `2024-01-01`) |
| `--until` | `-u` | Include commits before this date (e.g. `2024-12-31`) |
| `--filter` | `-f` | Only show files matching this path prefix |
| `--exclude` | | Exclude files matching this glob pattern (repeatable, e.g. `--exclude=Cargo.lock --exclude='.sqlx/*'`) |
| `--min-teams` | `-m` | Minimum number of teams to show a file (default: 2) |

### Example

```bash
./dx-metrics shared-ownership /path/to/repo --teams=teams.json --since=2024-01-01 --filter=src/
```

Outputs a table sorted by **ownership entropy** (0 = single owner, 1 = perfectly shared):

```
+------------------+-------+---------------------+---------+
| File             | Teams | Dominant Team       | Entropy |
+------------------+-------+---------------------+---------+
| src/Payment.php  | 3     | payments (60%)      | 0.92    |
| src/User.php     | 2     | identity (75%)      | 0.56    |
+------------------+-------+---------------------+---------+
```

Authors not listed in the teams config are grouped under `unknown`.

---

## Ownership Hotspots

Identifies files with **ambiguous ownership**, ranked by urgency. The risk score combines ownership entropy with commit frequency — a file touched often by multiple teams is more urgent than a rarely-changed one with the same entropy.

> `risk score = ownership entropy × total commits`

### Usage

```bash
./dx-metrics ownership-hotspots <path-to-repo> --teams=teams.json [options]
```

| Option | Short | Description |
|---|---|---|
| `--teams` | `-T` | Path to the teams JSON config file (required) |
| `--since` | `-s` | Include commits after this date (e.g. `2024-01-01`) |
| `--until` | `-u` | Include commits before this date (e.g. `2024-12-31`) |
| `--filter` | `-f` | Only show files matching this path prefix |
| `--exclude` | | Exclude files matching this glob pattern (repeatable, e.g. `--exclude=Cargo.lock --exclude='.sqlx/*'`) |
| `--min-teams` | `-m` | Minimum number of teams to show a file (default: 2) |

### Example

```bash
./dx-metrics ownership-hotspots /path/to/repo --teams=teams.json --since=2024-01-01
```

Output sorted by risk score descending:

```
+------------------+--------+-------+----------------------+---------+------------+
| File             | Commits| Teams | Dominant Team        | Entropy | Risk Score |
+------------------+--------+-------+----------------------+---------+------------+
| src/Order.php    | 87     | 3     | platform (40%)       | 0.91    | 79.2       |
| src/Invoice.php  | 34     | 2     | payments (55%)       | 0.79    | 26.9       |
+------------------+--------+-------+----------------------+---------+------------+
```

Files with a high risk score are the best candidates for an ownership clarification conversation between teams.

---

## Territory Map

Generates a **visual map of team territories and their coupling**, answering: *who owns what, and where are teams implicitly forced to coordinate?*

The graph has **one node per team**. Edges represent **cross-team volatility coupling** — the total number of commits that touched code from both teams at the same time. A thick edge is a Conway's Law violation: two teams sharing code changes without a shared owner.

Files are first grouped into modules by path depth (`--depth`), then modules are aggregated per dominant team into a single node. The module-level detail is printed as a table in the terminal for drill-down.

### How modules are defined

The `--depth` option controls how many path segments make up a module:

| `--depth` | File | Module |
|---|---|---|
| `1` | `src/Domain/Order/OrderService.php` | `src` |
| `2` | `src/Domain/Order/OrderService.php` | `src/Domain` |
| `3` | `src/Domain/Order/OrderService.php` | `src/Domain/Order` |

Start with `--depth=2` and adjust until the granularity matches your architectural boundaries.

### Usage

```bash
./dx-metrics territory-map <path-to-repo> --teams=teams.json [options]
```

| Option | Short | Description |
|---|---|---|
| `--teams` | `-T` | Path to the teams JSON config file (required) |
| `--depth` | `-d` | Number of path segments that define a module (default: 2) |
| `--filter` | `-f` | Only include files matching this path prefix (e.g. `src/`) |
| `--min-coupling` | `-c` | Hide cross-team edges below this co-change count (default: 0) |
| `--since` | `-s` | Include commits after this date (e.g. `2024-01-01`) |
| `--until` | `-u` | Include commits before this date (e.g. `2024-12-31`) |
| `--exclude` | | Exclude files matching this glob pattern (repeatable, e.g. `--exclude='.sqlx/*'`) |
| `--output-dir` | `-o` | Directory where `territory.dot` and `territory.png` are written (default: current directory) |

### Example

```bash
./dx-metrics territory-map /path/to/repo --teams=teams.json --depth=2 --since=2024-01-01 --exclude='.sqlx/*'
```

This prints a summary table to stdout and writes `territory.dot` and `territory.png` (requires [Graphviz](https://graphviz.org/)) to the output directory:

```
+---------------------+------------------+---------+---------+-------+
| Module              | Dominant Team    | Entropy | Commits | Teams |
+---------------------+------------------+---------+---------+-------+
| src/Domain          | payments (72%)   | 0.42    | 341     | 2     |
| src/Application     | payments (68%)   | 0.51    | 198     | 3     |
| src/Infrastructure  | platform (85%)   | 0.21    | 127     | 2     |
| src/UI              | frontend (91%)   | 0.14    | 89      | 2     |
+---------------------+------------------+---------+---------+-------+
```

### Reading the graph

- **Node** → one team. Colour is unique per team. Label shows number of modules owned, total commits, and average ownership entropy across those modules.
- **Edge thickness and label** → total co-change count summed across all module pairs between the two teams. Thicker = stronger implicit coordination cost between the teams.
- **No edge** → those two teams' code never (or rarely) changed together. Clear boundary.

Use `--min-coupling=N` to suppress weak edges and focus on the most significant Conway violations.

The module-level table printed in the terminal lets you drill into which specific modules are responsible for each edge.