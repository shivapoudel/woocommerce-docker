#!/bin/bash
# Script to check MySQL replication status

echo "=== MySQL Replication Status Check ==="
echo ""

# Check replica status
echo "1. Replica Status:"
echo "-------------------"
docker exec woocommerce-mysql-replica mysql -uroot -pSecure@RootPass2026 -e "
SHOW REPLICA STATUS\G
" 2>/dev/null | grep -E "Replica_IO_Running|Replica_SQL_Running|Seconds_Behind_Source|Last_IO_Error|Last_SQL_Error|Source_Host|Source_User" || \
docker exec woocommerce-mysql-replica mysql -uroot -pSecure@RootPass2026 -e "
SHOW SLAVE STATUS\G
" 2>/dev/null | grep -E "Slave_IO_Running|Slave_SQL_Running|Seconds_Behind_Master|Last_IO_Error|Last_SQL_Error|Master_Host|Master_User"

echo ""
echo "2. Testing Replication:"
echo "-------------------"
echo "Creating test table on master..."

# Create a test table on master
docker exec woocommerce-mysql mysql -uroot -pSecure@RootPass2026 wordpress_db -e "
CREATE TABLE IF NOT EXISTS replication_test (
    id INT AUTO_INCREMENT PRIMARY KEY,
    test_data VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
INSERT INTO replication_test (test_data) VALUES ('Replication test at $(date)');
" 2>/dev/null

sleep 2

echo "Checking if test table exists on replica..."
REPLICA_HAS_TABLE=$(docker exec woocommerce-mysql-replica mysql -uroot -pSecure@RootPass2026 wordpress_db -e "SHOW TABLES LIKE 'replication_test';" 2>/dev/null | grep -c replication_test || echo "0")

if [ "$REPLICA_HAS_TABLE" -gt 0 ]; then
    echo "✓ SUCCESS: Test table found on replica - replication is working!"
    REPLICA_COUNT=$(docker exec woocommerce-mysql-replica mysql -uroot -pSecure@RootPass2026 wordpress_db -e "SELECT COUNT(*) FROM replication_test;" 2>/dev/null | tail -n 1)
    echo "  Replica has $REPLICA_COUNT row(s) in test table"
else
    echo "✗ WARNING: Test table not found on replica - replication may not be active"
fi

echo ""
echo "3. Master Binary Log Status:"
echo "-------------------"
docker exec woocommerce-mysql mysql -uroot -pSecure@RootPass2026 -e "SHOW MASTER STATUS\G" 2>/dev/null

echo ""
echo "=== End of Status Check ==="
