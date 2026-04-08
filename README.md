# dx-metrics

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

### Example

```bash
./dx-metrics analyze /path/to/repo --since=2024-01-01 --threshold=3 --filter=src/
```

Outputs a table of file pairs sorted by co-change count, and generates `coupling.dot` and `coupling.png` (requires [Graphviz](https://graphviz.org/)) in the current directory.