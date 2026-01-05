#!/bin/bash
#
# Simple horizontal autoscaler for the WordPress service.
# Prereqs: Docker Compose v2, this script run from repo root, docker daemon available.
# Usage: ./autoscale.sh
# Scales between MIN_REPLICAS and MAX_REPLICAS based on average container CPU%.

set -euo pipefail

# Configuration
SERVICE_NAME="wordpress"
MIN_REPLICAS=3
MAX_REPLICAS=8
CPU_THRESHOLD_UP=50  # Scale up if average CPU > 50%
CPU_THRESHOLD_DOWN=10 # Scale down if average CPU < 10%
CHECK_INTERVAL=10    # Seconds between checks

# Function to get current replica count
get_replica_count() {
    docker ps --filter "name=woocommerce-docker-${SERVICE_NAME}-" --format "{{.Names}}" | wc -l | xargs
}

# Function to get average CPU usage
get_avg_cpu() {
    local ids
    ids=$(docker ps -q --filter "name=woocommerce-docker-${SERVICE_NAME}-")
    if [ -z "$ids" ]; then
        echo 0
        return
    fi

    # Get CPU percentages, strip '%', sum them up, divide by count
    docker stats --no-stream --format "{{.CPUPerc}}" $ids | \
      sed 's/%//' | \
      awk '{sum+=$1} END {if (NR>0) print sum/NR; else print 0}'
}

echo "Starting autoscaler for $SERVICE_NAME..."
echo "Range: $MIN_REPLICAS - $MAX_REPLICAS replicas"
echo "Thresholds: Up > $CPU_THRESHOLD_UP%, Down < $CPU_THRESHOLD_DOWN%"

while true; do
    CURRENT_COUNT=$(get_replica_count)
    AVG_CPU=$(get_avg_cpu)

    # Use integer comparison for simple floating point (awk returns float)
    AVG_CPU_INT=$(printf "%.0f" "$AVG_CPU")

    echo "[$(date '+%H:%M:%S')] Replicas: $CURRENT_COUNT | Avg CPU: $AVG_CPU%"

    if [ "$AVG_CPU_INT" -gt "$CPU_THRESHOLD_UP" ]; then
        if [ "$CURRENT_COUNT" -lt "$MAX_REPLICAS" ]; then
            NEW_COUNT=$((CURRENT_COUNT + 1))
            echo "🔥 High Load detected ($AVG_CPU%). Scaling UP to $NEW_COUNT..."
            docker compose up -d --scale $SERVICE_NAME=$NEW_COUNT --no-recreate
        else
            echo "⚠️  Max replicas reached ($MAX_REPLICAS). Cannot scale up."
        fi

    elif [ "$AVG_CPU_INT" -lt "$CPU_THRESHOLD_DOWN" ]; then
        if [ "$CURRENT_COUNT" -gt "$MIN_REPLICAS" ]; then
            NEW_COUNT=$((CURRENT_COUNT - 1))
            echo "❄️  Low Load detected ($AVG_CPU%). Scaling DOWN to $NEW_COUNT..."
            docker compose up -d --scale $SERVICE_NAME=$NEW_COUNT --no-recreate
        else
             # echo "Min replicas maintained ($MIN_REPLICAS)."
             :
        fi
    fi

    sleep $CHECK_INTERVAL
done
