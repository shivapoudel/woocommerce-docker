#!/bin/bash
# Manual replication setup script
# Use this if you have an existing database and need to set up replication manually

set -e

MASTER_HOST="${MYSQL_MASTER_HOST:-mysql}"
MASTER_USER="${MYSQL_MASTER_USER:-replicator}"
MASTER_PASSWORD="${MYSQL_MASTER_PASSWORD:-Replicator@Pass2026}"
REPLICA_ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-Secure@RootPass2026}"

echo "Setting up MySQL replication manually..."
echo "Master: $MASTER_HOST"
echo "Replica: localhost"

# Wait for master to be ready
echo "Waiting for MySQL master to be ready..."
until mysqladmin ping -h "$MASTER_HOST" -uroot -p"$REPLICA_ROOT_PASSWORD" --silent 2>/dev/null; do
  echo "Waiting for master..."
  sleep 2
done

echo "Master is ready. Configuring replica..."

# Configure replica using GTID auto-positioning
mysql -uroot -p"$REPLICA_ROOT_PASSWORD" <<EOF
STOP REPLICA IF EXISTS;
STOP SLAVE IF EXISTS;

CHANGE REPLICATION SOURCE TO
  SOURCE_HOST='$MASTER_HOST',
  SOURCE_USER='$MASTER_USER',
  SOURCE_PASSWORD='$MASTER_PASSWORD',
  SOURCE_AUTO_POSITION=1;

START REPLICA;
EOF

# Check replication status
echo ""
echo "Replication status:"
mysql -uroot -p"$REPLICA_ROOT_PASSWORD" -e "SHOW REPLICA STATUS\G" | grep -E "Replica_IO_Running|Replica_SQL_Running|Seconds_Behind_Source|Last_IO_Error|Last_SQL_Error" || \
mysql -uroot -p"$REPLICA_ROOT_PASSWORD" -e "SHOW SLAVE STATUS\G" | grep -E "Slave_IO_Running|Slave_SQL_Running|Seconds_Behind_Master|Last_IO_Error|Last_SQL_Error"

echo ""
echo "Replication setup complete!"
