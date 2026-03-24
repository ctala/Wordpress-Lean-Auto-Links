# Benchmark Results Archive

All benchmark results are stored here as permanent records.
These feed directly into documentation, wordpress.org listing, and marketing.

## Directory Structure

```
results/
├── leanweave/              # LeanWeave benchmark runs
│   ├── YYYY-MM-DD-milestone.json    # Raw JSON from benchmark.sh --output
│   └── YYYY-MM-DD-milestone.md      # Human-readable report
│
├── competitors/            # Individual plugin benchmarks
│   ├── ilj-free-YYYY-MM-DD.json
│   ├── ilj-free-YYYY-MM-DD.md
│   ├── internal-links-manager-YYYY-MM-DD.json
│   ├── link-whisper-YYYY-MM-DD.json
│   └── ...
│
├── comparative/            # Head-to-head comparisons
│   ├── YYYY-MM-DD-full-comparison.md    # All plugins side by side
│   └── YYYY-MM-DD-full-comparison.json  # Raw data
│
└── README.md               # This file
```

## Naming Convention

- Date prefix: `YYYY-MM-DD`
- Milestone suffix for LeanWeave: `-phase2`, `-phase3`, `-pre-release`, `-v0.1.0`
- Plugin slug for competitors: `ilj-free`, `ilj-pro`, `internal-links-manager`, `link-whisper`

## What Gets Stored

### Per benchmark run (JSON)
- Timestamp
- Environment (Docker config, PHP version, MySQL version, post count, rule count)
- All B1-B10 metrics with raw measurements
- Pass/fail per metric
- p50, p95, p99 percentiles where applicable

### Per benchmark run (Markdown report)
- Summary table: metric | threshold | measured | pass/fail
- Notable findings
- Comparison with previous run (if applicable)
- Screenshots of Query Monitor output (if relevant)

### Comparative reports (Markdown)
- Side-by-side table: metric | LeanWeave | ILJ | ILM | Link Whisper
- Winner per metric highlighted
- Failure documentation with evidence
- Edge cases: accent handling, overlapping keywords, self-linking
- Conclusion: where LeanWeave wins and by how much

## Usage in Documentation

These results will be referenced in:
1. `plugin/README.md` - Performance claims with data
2. `plugin/readme.txt` - WordPress.org listing
3. Blog post / case study for launch
4. Comparison screenshots for wordpress.org assets
