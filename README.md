# dx-metrics

[![codecov](https://codecov.io/gh/pfazzi/dx-metrics/branch/main/graph/badge.svg)](https://codecov.io/gh/pfazzi/dx-metrics)

CLI tool for **temporal coupling analysis** of Git repositories — identifies which files are frequently changed together in the same commits.

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