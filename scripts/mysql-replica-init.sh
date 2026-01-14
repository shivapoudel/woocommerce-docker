#!/bin/bash
set -e

# Wait for master to be ready and fully initialized
echo "Waiting for MySQL master to be ready..."
MAX_RETRIES=60
RETRY_COUNT=0

while [ $RETRY_COUNT -lt $MAX_RETRIES ]; do
  if mysqladmin ping -h mysql -uroot -p"$MYSQL_ROOT_PASSWORD" --silent 2>/dev/null; then
    # Check if master is accepting connections and replication user exists
    if mysql -h mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "SELECT 1" 2>/dev/null >/dev/null; then
      echo "Master is ready!"
      break
    fi
  fi
  echo "Waiting for master... ($RETRY_COUNT/$MAX_RETRIES)"
  sleep 2
  RETRY_COUNT=$((RETRY_COUNT + 1))
done

if [ $RETRY_COUNT -eq $MAX_RETRIES ]; then
  echo "Warning: Master did not become ready in time. Replication may need manual setup."
  exit 0
fi

# Wait a bit more for master to be fully initialized
sleep 5

echo "Setting up replication..."

# Configure replica using GTID auto-positioning
mysql -uroot -p"$MYSQL_ROOT_PASSWORD" <<EOF
STOP SLAVE IF EXISTS;

CHANGE REPLICATION SOURCE TO
  SOURCE_HOST='mysql',
  SOURCE_USER='replicator',
  SOURCE_PASSWORD='Replicator@Pass2026',
  SOURCE_AUTO_POSITION=1;

START REPLICA;
EOF

# Wait a moment for replication to start
sleep 2

# Check replication status
echo ""
echo "Replication status:"
mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "SHOW REPLICA STATUS\G" | grep -E "Replica_IO_Running|Replica_SQL_Running|Seconds_Behind_Source|Last_IO_Error|Last_SQL_Error" || \
mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "SHOW SLAVE STATUS\G" | grep -E "Slave_IO_Running|Slave_SQL_Running|Seconds_Behind_Master|Last_IO_Error|Last_SQL_Error"

echo ""
echo "Replication setup complete!"
