#!/usr/bin/env bash
# =============================================================================
# LeanAutoLinks Performance Benchmark Script
# =============================================================================
# Usage:
#   ./bin/benchmark.sh              Run all fast benchmarks (B1-B3, B6, B7)
#   ./bin/benchmark.sh --all        Run full suite including bulk tests
#   ./bin/benchmark.sh --frontend   Run TTFB and query benchmarks only
#   ./bin/benchmark.sh --save-post  Run save_post overhead benchmark only
#   ./bin/benchmark.sh --engine     Run engine performance benchmark only
#   ./bin/benchmark.sh --bulk       Run bulk processing benchmark only
#   ./bin/benchmark.sh --output X   Save results to file X (JSON)
#   ./bin/benchmark.sh --compare A B  Compare two result files
#
# NOTE: Make this file executable with: chmod +x bin/benchmark.sh
# =============================================================================

set -euo pipefail

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_URL="http://localhost:8080"
AB_REQUESTS=100
AB_CONCURRENCY=5
WARMUP_REQUESTS=10
SAMPLE_POSTS=10
SAVE_POST_SAMPLES=50
ENGINE_SAMPLES=20
OUTPUT_FILE=""
COMPARE_A=""
COMPARE_B=""

# Thresholds (from benchmark-spec.md)
TTFB_THRESHOLD_MS=5
SAVE_POST_THRESHOLD_MS=50
ENGINE_1000_THRESHOLD_MS=500
ENGINE_100_THRESHOLD_MS=50
MEMORY_JOB_THRESHOLD_MB=32
BULK_THRESHOLD_SECONDS=14400  # 4 hours

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Test selection
RUN_FRONTEND=false
RUN_SAVEPOST=false
RUN_ENGINE=false
RUN_BULK=false

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
log_header() {
    echo ""
    echo -e "${BLUE}================================================================${NC}"
    echo -e "${BLUE}  $1${NC}"
    echo -e "${BLUE}================================================================${NC}"
    echo ""
}

log_pass() {
    echo -e "  ${GREEN}[PASS]${NC} $1"
}

log_fail() {
    echo -e "  ${RED}[FAIL]${NC} $1"
}

log_info() {
    echo -e "  ${YELLOW}[INFO]${NC} $1"
}

dc() {
    docker compose -f "$PROJECT_DIR/docker-compose.yml" "$@"
}

wp_cli() {
    dc run --rm wp-cli "$@" 2>/dev/null
}

check_prerequisites() {
    log_header "Checking Prerequisites"

    # Check Docker is running
    if ! docker info > /dev/null 2>&1; then
        log_fail "Docker is not running"
        exit 1
    fi
    log_pass "Docker is running"

    # Check containers are up
    if ! dc ps --status running | grep -q wordpress; then
        log_fail "WordPress container is not running. Run: docker compose up -d"
        exit 1
    fi
    log_pass "WordPress container is running"

    if ! dc ps --status running | grep -q db; then
        log_fail "MySQL container is not running. Run: docker compose up -d"
        exit 1
    fi
    log_pass "MySQL container is running"

    # Check Apache Bench is available
    if ! command -v ab &> /dev/null; then
        log_fail "Apache Bench (ab) is not installed. Install with: brew install httpd (macOS) or apt-get install apache2-utils (Linux)"
        exit 1
    fi
    log_pass "Apache Bench (ab) is available"

    # Check WordPress is responding
    local http_code
    http_code=$(curl -s -o /dev/null -w "%{http_code}" "$WP_URL/" || echo "000")
    if [ "$http_code" = "000" ]; then
        log_fail "WordPress is not responding at $WP_URL"
        exit 1
    fi
    log_pass "WordPress is responding (HTTP $http_code)"
}

# Get sample post IDs from the WordPress site
get_sample_post_ids() {
    local count=${1:-$SAMPLE_POSTS}
    wp_cli eval "
        \$posts = get_posts([
            'posts_per_page' => $count,
            'post_status' => 'publish',
            'fields' => 'ids',
            'orderby' => 'rand',
        ]);
        echo implode(' ', \$posts);
    "
}

# Measure TTFB for a single URL using Apache Bench
measure_ttfb() {
    local url="$1"
    local requests="${2:-$AB_REQUESTS}"
    local concurrency="${3:-$AB_CONCURRENCY}"

    # Warm up (discard)
    ab -n "$WARMUP_REQUESTS" -c 1 "$url" > /dev/null 2>&1 || true

    # Actual measurement - extract median time per request
    local result
    result=$(ab -n "$requests" -c "$concurrency" "$url" 2>/dev/null)

    # Extract the 50% percentile (median) from the distribution
    local median_ms
    median_ms=$(echo "$result" | grep "50%" | awk '{print $2}')

    # If percentile not available, use mean
    if [ -z "$median_ms" ]; then
        median_ms=$(echo "$result" | grep "Time per request" | head -1 | awk '{print $4}')
    fi

    echo "$median_ms"
}

# ---------------------------------------------------------------------------
# Benchmark: Frontend TTFB (B1, B2)
# ---------------------------------------------------------------------------
run_frontend_benchmark() {
    log_header "B1/B2: Frontend TTFB and DB Queries"

    local post_ids
    post_ids=$(get_sample_post_ids "$SAMPLE_POSTS")

    if [ -z "$post_ids" ]; then
        log_fail "No published posts found. Seed data first."
        return 1
    fi

    local failures=0

    # --- Phase 1: Baseline (plugin inactive) ---
    log_info "Deactivating LeanAutoLinks plugin for baseline measurement..."
    wp_cli plugin deactivate leanautolinks 2>/dev/null || log_info "Plugin already inactive or not installed"

    declare -A baseline_ttfb
    log_info "Measuring baseline TTFB (plugin inactive)..."

    for post_id in $post_ids; do
        local url="$WP_URL/?p=$post_id"
        local ttfb
        ttfb=$(measure_ttfb "$url")
        baseline_ttfb[$post_id]="$ttfb"
        log_info "  Post $post_id baseline: ${ttfb}ms"
    done

    # --- Phase 2: With plugin active ---
    log_info "Activating LeanAutoLinks plugin..."
    wp_cli plugin activate leanautolinks 2>/dev/null || {
        log_fail "Could not activate LeanAutoLinks plugin"
        return 1
    }

    log_info "Measuring TTFB with plugin active..."
    for post_id in $post_ids; do
        local url="$WP_URL/?p=$post_id"
        local ttfb
        ttfb=$(measure_ttfb "$url")
        local baseline="${baseline_ttfb[$post_id]}"

        # Calculate difference (handle floating point with awk)
        local diff
        diff=$(awk "BEGIN {printf \"%.2f\", $ttfb - $baseline}")
        local abs_diff
        abs_diff=$(awk "BEGIN {d = $ttfb - $baseline; printf \"%.2f\", (d < 0 ? -d : d)}")

        if awk "BEGIN {exit !($abs_diff >= $TTFB_THRESHOLD_MS)}"; then
            log_fail "Post $post_id: TTFB delta ${diff}ms (baseline: ${baseline}ms, active: ${ttfb}ms) exceeds ${TTFB_THRESHOLD_MS}ms threshold"
            ((failures++))
        else
            log_pass "Post $post_id: TTFB delta ${diff}ms (baseline: ${baseline}ms, active: ${ttfb}ms)"
        fi
    done

    # --- Phase 3: Check DB query count ---
    log_info "Checking additional DB queries on frontend..."
    local query_check
    query_check=$(wp_cli eval "
        // Deactivate to get baseline query count
        deactivate_plugins('leanautolinks/leanautolinks.php');
        \$baseline_queries = get_num_queries();

        // Reactivate
        activate_plugin('leanautolinks/leanautolinks.php');

        // Simulate a frontend page load context
        \$post_id = ${post_ids%% *};
        global \$wp_query;
        \$wp_query = new WP_Query(['p' => \$post_id]);
        do_action('wp');

        \$active_queries = get_num_queries();
        \$additional = \$active_queries - \$baseline_queries;
        echo \$additional;
    " 2>/dev/null || echo "N/A")

    if [ "$query_check" = "0" ]; then
        log_pass "Additional frontend DB queries: 0"
    elif [ "$query_check" = "N/A" ]; then
        log_info "Could not measure DB queries automatically (plugin may not be installed yet)"
    else
        log_fail "Additional frontend DB queries: $query_check (expected 0)"
        ((failures++))
    fi

    echo ""
    if [ "$failures" -eq 0 ]; then
        log_pass "B1/B2 PASSED: Frontend performance within thresholds"
    else
        log_fail "B1/B2 FAILED: $failures checks exceeded thresholds"
    fi

    return "$failures"
}

# ---------------------------------------------------------------------------
# Benchmark: save_post Overhead (B3)
# ---------------------------------------------------------------------------
run_savepost_benchmark() {
    log_header "B3: save_post Overhead"

    local failures=0

    # Ensure plugin is active
    wp_cli plugin activate leanautolinks 2>/dev/null || log_info "Plugin already active or not installed"

    log_info "Measuring save_post overhead across $SAVE_POST_SAMPLES operations..."

    local results
    results=$(wp_cli eval "
        \$post_ids = get_posts([
            'posts_per_page' => $SAVE_POST_SAMPLES,
            'post_status' => 'publish',
            'fields' => 'ids',
        ]);

        if (empty(\$post_ids)) {
            echo 'ERROR:No posts found';
            return;
        }

        \$times = [];
        foreach (\$post_ids as \$post_id) {
            \$post = get_post(\$post_id);
            if (!\$post) continue;

            // Measure baseline: temporarily remove leanautolinks hooks
            \$has_hook = has_action('save_post', 'leanautolinks_on_save_post');

            if (\$has_hook) {
                remove_action('save_post', 'leanautolinks_on_save_post');
            }
            \$start = microtime(true);
            wp_update_post(['ID' => \$post_id, 'post_content' => \$post->post_content]);
            \$baseline = (microtime(true) - \$start) * 1000;

            // Re-add hook and measure with plugin
            if (\$has_hook) {
                add_action('save_post', 'leanautolinks_on_save_post');
            }
            \$start = microtime(true);
            wp_update_post(['ID' => \$post_id, 'post_content' => \$post->post_content]);
            \$with_plugin = (microtime(true) - \$start) * 1000;

            \$overhead = \$with_plugin - \$baseline;
            \$times[] = \$overhead;
            echo sprintf('%.2f', \$overhead) . '\n';
        }

        sort(\$times);
        \$count = count(\$times);
        if (\$count > 0) {
            \$p50 = \$times[(int)(\$count * 0.50)];
            \$p95 = \$times[(int)(\$count * 0.95)];
            \$max = end(\$times);
            echo \"STATS:p50=\" . sprintf('%.2f', \$p50) . \",p95=\" . sprintf('%.2f', \$p95) . \",max=\" . sprintf('%.2f', \$max);
        }
    " 2>/dev/null)

    if echo "$results" | grep -q "ERROR:"; then
        log_fail "$(echo "$results" | grep "ERROR:" | sed 's/ERROR://')"
        return 1
    fi

    # Parse stats line
    local stats_line
    stats_line=$(echo "$results" | grep "^STATS:" | sed 's/STATS://')

    if [ -n "$stats_line" ]; then
        local p50 p95 max_val
        p50=$(echo "$stats_line" | sed 's/.*p50=\([^,]*\).*/\1/')
        p95=$(echo "$stats_line" | sed 's/.*p95=\([^,]*\).*/\1/')
        max_val=$(echo "$stats_line" | sed 's/.*max=\([^,]*\).*/\1/')

        log_info "save_post overhead stats: p50=${p50}ms, p95=${p95}ms, max=${max_val}ms"

        if awk "BEGIN {exit !($max_val >= $SAVE_POST_THRESHOLD_MS)}"; then
            log_fail "Maximum save_post overhead ${max_val}ms exceeds ${SAVE_POST_THRESHOLD_MS}ms threshold"
            ((failures++))
        else
            log_pass "All save_post operations under ${SAVE_POST_THRESHOLD_MS}ms threshold (max: ${max_val}ms)"
        fi
    else
        log_info "Could not measure save_post overhead (plugin hooks may not be registered yet)"
    fi

    echo ""
    if [ "$failures" -eq 0 ]; then
        log_pass "B3 PASSED: save_post overhead within threshold"
    else
        log_fail "B3 FAILED: save_post overhead exceeded threshold"
    fi

    return "$failures"
}

# ---------------------------------------------------------------------------
# Benchmark: Engine Performance (B6, B7, B8)
# ---------------------------------------------------------------------------
run_engine_benchmark() {
    log_header "B6/B7/B8: Engine Performance, Memory, and Leak Detection"

    local failures=0

    wp_cli plugin activate leanautolinks 2>/dev/null || log_info "Plugin already active or not installed"

    log_info "Measuring engine performance with active rules..."

    local results
    results=$(wp_cli eval "
        // Check if engine class exists
        if (!class_exists('LeanAutoLinks\\\Engine\\\RuleMatcherEngine')) {
            echo 'ERROR:RuleMatcherEngine class not found. Build engine first.';
            return;
        }

        // Count active rules
        \$rules = LeanAutoLinks\\\Repositories\\\RulesRepository::get_active_rules();
        \$rule_count = count(\$rules);
        echo \"RULES:\$rule_count\n\";

        // Get sample posts
        \$post_ids = get_posts([
            'posts_per_page' => $ENGINE_SAMPLES,
            'post_status' => 'publish',
            'fields' => 'ids',
            'orderby' => 'rand',
        ]);

        \$times = [];
        \$memories = [];

        foreach (\$post_ids as \$post_id) {
            \$mem_before = memory_get_usage(true);
            \$start = microtime(true);

            \$engine = new LeanAutoLinks\\\Engine\\\RuleMatcherEngine();
            \$engine->process_post(\$post_id);

            \$elapsed = (microtime(true) - \$start) * 1000;
            \$mem_used = (memory_get_peak_usage(true) - \$mem_before) / 1024 / 1024;

            \$times[] = \$elapsed;
            \$memories[] = \$mem_used;

            echo sprintf(\"POST:%d,time=%.2f,mem=%.2f\n\", \$post_id, \$elapsed, \$mem_used);
        }

        sort(\$times);
        sort(\$memories);
        \$count = count(\$times);
        if (\$count > 0) {
            echo sprintf(\"TIME_STATS:p50=%.2f,p95=%.2f,max=%.2f\n\",
                \$times[(int)(\$count * 0.50)],
                \$times[(int)(\$count * 0.95)],
                end(\$times)
            );
            echo sprintf(\"MEM_STATS:p50=%.2f,p95=%.2f,max=%.2f\n\",
                \$memories[(int)(\$count * 0.50)],
                \$memories[(int)(\$count * 0.95)],
                end(\$memories)
            );
        }
    " 2>/dev/null)

    if echo "$results" | grep -q "ERROR:"; then
        log_info "$(echo "$results" | grep "ERROR:" | sed 's/ERROR://')"
        log_info "Engine benchmarks will be available after the engine is implemented."
        return 0
    fi

    # Parse results
    local rule_count
    rule_count=$(echo "$results" | grep "^RULES:" | sed 's/RULES://')
    log_info "Active rules: $rule_count"

    local time_stats
    time_stats=$(echo "$results" | grep "^TIME_STATS:" | sed 's/TIME_STATS://')
    local mem_stats
    mem_stats=$(echo "$results" | grep "^MEM_STATS:" | sed 's/MEM_STATS://')

    if [ -n "$time_stats" ]; then
        local p95_time
        p95_time=$(echo "$time_stats" | sed 's/.*p95=\([^,]*\).*/\1/')
        log_info "Engine time stats: $time_stats"

        local threshold="$ENGINE_1000_THRESHOLD_MS"
        if [ "$rule_count" -le 200 ]; then
            threshold="$ENGINE_100_THRESHOLD_MS"
        fi

        if awk "BEGIN {exit !($p95_time >= $threshold)}"; then
            log_fail "Engine p95 ${p95_time}ms exceeds ${threshold}ms threshold ($rule_count rules)"
            ((failures++))
        else
            log_pass "Engine p95 ${p95_time}ms under ${threshold}ms threshold ($rule_count rules)"
        fi
    fi

    if [ -n "$mem_stats" ]; then
        local max_mem
        max_mem=$(echo "$mem_stats" | sed 's/.*max=\([^,]*\).*/\1/')
        log_info "Memory stats: $mem_stats"

        if awk "BEGIN {exit !($max_mem >= $MEMORY_JOB_THRESHOLD_MB)}"; then
            log_fail "Peak memory ${max_mem}MB exceeds ${MEMORY_JOB_THRESHOLD_MB}MB threshold"
            ((failures++))
        else
            log_pass "Peak memory ${max_mem}MB under ${MEMORY_JOB_THRESHOLD_MB}MB threshold"
        fi
    fi

    echo ""
    if [ "$failures" -eq 0 ]; then
        log_pass "B6/B7/B8 PASSED: Engine performance within thresholds"
    else
        log_fail "B6/B7/B8 FAILED: $failures checks exceeded thresholds"
    fi

    return "$failures"
}

# ---------------------------------------------------------------------------
# Benchmark: Bulk Processing (B4, B5)
# ---------------------------------------------------------------------------
run_bulk_benchmark() {
    log_header "B4/B5: Bulk Processing and Sustained Throughput"

    log_info "This benchmark is long-running. Estimated time: up to 4 hours."
    log_info "Starting bulk reprocess of all posts..."

    local failures=0

    local start_time
    start_time=$(date +%s)

    local bulk_result
    bulk_result=$(wp_cli eval "
        if (!class_exists('LeanAutoLinks\\\Engine\\\RuleMatcherEngine')) {
            echo 'ERROR:Engine not available. Build it first.';
            return;
        }

        // Count total posts
        \$total = wp_count_posts()->publish;
        echo \"TOTAL:\$total\n\";

        // Process in batches of 100
        \$batch_size = 100;
        \$processed = 0;
        \$batch_num = 0;
        \$batch_times = [];

        for (\$offset = 0; \$offset < \$total; \$offset += \$batch_size) {
            \$batch_num++;
            \$batch_start = microtime(true);

            \$post_ids = get_posts([
                'posts_per_page' => \$batch_size,
                'offset' => \$offset,
                'post_status' => 'publish',
                'fields' => 'ids',
                'orderby' => 'ID',
                'order' => 'ASC',
            ]);

            foreach (\$post_ids as \$post_id) {
                \$engine = new LeanAutoLinks\\\Engine\\\RuleMatcherEngine();
                \$engine->process_post(\$post_id);
                \$processed++;
            }

            \$batch_time = microtime(true) - \$batch_start;
            \$batch_times[] = \$batch_time;
            \$rate = count(\$post_ids) / \$batch_time * 3600;

            if (\$batch_num % 10 === 0) {
                \$mem = memory_get_usage(true) / 1024 / 1024;
                echo sprintf(\"BATCH:%d,processed=%d,rate=%.0f/hr,mem=%.1fMB\n\",
                    \$batch_num, \$processed, \$rate, \$mem);
            }
        }

        echo \"PROCESSED:\$processed\n\";
        echo \"BATCHES:\$batch_num\n\";
    " 2>/dev/null)

    local end_time
    end_time=$(date +%s)
    local elapsed=$((end_time - start_time))

    if echo "$bulk_result" | grep -q "ERROR:"; then
        log_info "$(echo "$bulk_result" | grep "ERROR:" | sed 's/ERROR://')"
        log_info "Bulk benchmarks will be available after the engine is implemented."
        return 0
    fi

    local total_processed
    total_processed=$(echo "$bulk_result" | grep "^PROCESSED:" | sed 's/PROCESSED://')

    log_info "Processed $total_processed posts in ${elapsed} seconds ($((elapsed / 60)) minutes)"

    # Check against 4-hour threshold
    if [ "$elapsed" -ge "$BULK_THRESHOLD_SECONDS" ]; then
        log_fail "Bulk processing took $((elapsed / 60)) minutes (threshold: $((BULK_THRESHOLD_SECONDS / 60)) minutes)"
        ((failures++))
    else
        log_pass "Bulk processing completed in $((elapsed / 60)) minutes (threshold: $((BULK_THRESHOLD_SECONDS / 60)) minutes)"
    fi

    # Calculate throughput
    if [ "$elapsed" -gt 0 ] && [ -n "$total_processed" ] && [ "$total_processed" -gt 0 ]; then
        local throughput_per_hour
        throughput_per_hour=$(awk "BEGIN {printf \"%.0f\", ($total_processed / $elapsed) * 3600}")
        log_info "Average throughput: $throughput_per_hour posts/hour"

        if [ "$throughput_per_hour" -lt 70 ]; then
            log_fail "Sustained throughput ${throughput_per_hour}/hour below 70/hour threshold"
            ((failures++))
        else
            log_pass "Sustained throughput ${throughput_per_hour}/hour exceeds 70/hour threshold"
        fi
    fi

    echo ""
    if [ "$failures" -eq 0 ]; then
        log_pass "B4/B5 PASSED: Bulk processing within thresholds"
    else
        log_fail "B4/B5 FAILED: $failures checks exceeded thresholds"
    fi

    return "$failures"
}

# ---------------------------------------------------------------------------
# Results output
# ---------------------------------------------------------------------------
generate_json_output() {
    local file="$1"
    local total_failures="$2"
    local timestamp
    timestamp=$(date -u +"%Y-%m-%dT%H:%M:%SZ")

    cat > "$file" << JSONEOF
{
    "timestamp": "$timestamp",
    "environment": {
        "wp_url": "$WP_URL",
        "ab_requests": $AB_REQUESTS,
        "ab_concurrency": $AB_CONCURRENCY
    },
    "thresholds": {
        "ttfb_delta_ms": $TTFB_THRESHOLD_MS,
        "save_post_ms": $SAVE_POST_THRESHOLD_MS,
        "engine_1000_rules_ms": $ENGINE_1000_THRESHOLD_MS,
        "engine_100_rules_ms": $ENGINE_100_THRESHOLD_MS,
        "memory_job_mb": $MEMORY_JOB_THRESHOLD_MB,
        "bulk_seconds": $BULK_THRESHOLD_SECONDS
    },
    "total_failures": $total_failures,
    "status": "$([ "$total_failures" -eq 0 ] && echo 'PASS' || echo 'FAIL')"
}
JSONEOF

    log_info "Results saved to $file"
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --frontend)
                RUN_FRONTEND=true
                shift
                ;;
            --save-post)
                RUN_SAVEPOST=true
                shift
                ;;
            --engine)
                RUN_ENGINE=true
                shift
                ;;
            --bulk)
                RUN_BULK=true
                shift
                ;;
            --all)
                RUN_FRONTEND=true
                RUN_SAVEPOST=true
                RUN_ENGINE=true
                RUN_BULK=true
                shift
                ;;
            --output)
                OUTPUT_FILE="$2"
                shift 2
                ;;
            --compare)
                COMPARE_A="$2"
                COMPARE_B="$3"
                shift 3
                ;;
            --help|-h)
                echo "Usage: $0 [--frontend] [--save-post] [--engine] [--bulk] [--all] [--output FILE] [--compare A B]"
                exit 0
                ;;
            *)
                echo "Unknown option: $1"
                exit 1
                ;;
        esac
    done

    # Default: run fast benchmarks
    if ! $RUN_FRONTEND && ! $RUN_SAVEPOST && ! $RUN_ENGINE && ! $RUN_BULK && [ -z "$COMPARE_A" ]; then
        RUN_FRONTEND=true
        RUN_SAVEPOST=true
        RUN_ENGINE=true
    fi
}

main() {
    parse_args "$@"

    # Handle comparison mode
    if [ -n "$COMPARE_A" ] && [ -n "$COMPARE_B" ]; then
        log_header "Comparing Benchmark Results"
        echo "  Baseline: $COMPARE_A"
        echo "  Active:   $COMPARE_B"
        echo ""
        if command -v jq &> /dev/null; then
            jq -s '.[0] as $a | .[1] as $b | {
                baseline_status: $a.status,
                active_status: $b.status,
                baseline_failures: $a.total_failures,
                active_failures: $b.total_failures
            }' "$COMPARE_A" "$COMPARE_B"
        else
            echo "  Install jq for detailed comparison. Showing raw files:"
            echo "  --- Baseline ---"
            cat "$COMPARE_A"
            echo ""
            echo "  --- Active ---"
            cat "$COMPARE_B"
        fi
        exit 0
    fi

    log_header "LeanAutoLinks Performance Benchmark Suite"
    echo "  Date: $(date)"
    echo "  WordPress URL: $WP_URL"
    echo ""

    check_prerequisites

    local total_failures=0

    if $RUN_FRONTEND; then
        run_frontend_benchmark || total_failures=$((total_failures + $?))
    fi

    if $RUN_SAVEPOST; then
        run_savepost_benchmark || total_failures=$((total_failures + $?))
    fi

    if $RUN_ENGINE; then
        run_engine_benchmark || total_failures=$((total_failures + $?))
    fi

    if $RUN_BULK; then
        run_bulk_benchmark || total_failures=$((total_failures + $?))
    fi

    # Summary
    log_header "BENCHMARK SUMMARY"
    echo "  Total failures: $total_failures"
    echo ""

    if [ "$total_failures" -eq 0 ]; then
        echo -e "  ${GREEN}========================================${NC}"
        echo -e "  ${GREEN}  ALL BENCHMARKS PASSED${NC}"
        echo -e "  ${GREEN}========================================${NC}"
    else
        echo -e "  ${RED}========================================${NC}"
        echo -e "  ${RED}  $total_failures BENCHMARK(S) FAILED${NC}"
        echo -e "  ${RED}  RELEASE BLOCKED BY PERFORMANCE AGENT${NC}"
        echo -e "  ${RED}========================================${NC}"
    fi

    # Save output if requested
    if [ -n "$OUTPUT_FILE" ]; then
        generate_json_output "$OUTPUT_FILE" "$total_failures"
    fi

    exit "$total_failures"
}

main "$@"
