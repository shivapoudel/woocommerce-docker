# MySQL Replication and Atomic Locks - Critical Considerations

## The Problem

When using MySQL replication with HyperDB, there's a critical issue with atomic locks:

1. **GET_LOCK() is connection-specific**: Locks only exist on the server where they're acquired
2. **Replication lag**: Even with microsecond-level replication, there's still a delay
3. **Read-after-write consistency**: If you write to master and immediately read from replica, you might not see the write

## How HyperDB Handles This

HyperDB has built-in **read-after-write consistency**:

- HyperDB tracks which tables have been written to during the request
- After a write, subsequent reads to those tables are automatically routed to the **master** (not replica)
- This ensures you always read the latest data after writing

## Your Current Code Flow

In `prevent-duplicate-order.php`:

1. `acquire_lock()` - Uses `GET_LOCK()` → Goes to **master** (write query)
2. `check_duplicate_before_save()` - SELECT query → **Could go to replica** ❌
3. Order is saved → Goes to **master** (write query)

## The Issue

If step 2 reads from replica:
- The lock on master doesn't protect the read on replica
- Replication lag means you might not see an order that was just created
- Race condition: Two requests could both pass the duplicate check

## The Solution

HyperDB's read-after-write consistency should handle this, BUT there's a timing issue:

- `GET_LOCK()` is a SELECT query, not a write
- HyperDB might not track it as a "write" to the orders table
- The duplicate check might still go to replica

### Option 1: Trust HyperDB (Current Setup)

HyperDB should route the duplicate check to master because:
- The lock acquisition happens on master
- HyperDB may track this connection and route subsequent reads to master

**Risk**: If HyperDB doesn't track `GET_LOCK()` as a write, the duplicate check could still go to replica.

### Option 2: Force Read to Master (Recommended)

Modify `find_recent_order()` to force the read to master:

```php
private static function find_recent_order( $cart_hash, $email, $lockout_duration ) {
    global $wpdb;

    // Force read to master by using a write connection
    // HyperDB will route this to master
    $wpdb->last_error = ''; // Clear any previous errors

    // Use a dummy write to force master connection
    // This ensures the subsequent read goes to master
    $wpdb->query( "SELECT 1" ); // This will use the master connection from GET_LOCK

    $time_threshold = gmdate( 'Y-m-d H:i:s', time() - $lockout_duration );
    // ... rest of the query
}
```

### Option 3: Use Same Connection (Best)

Since `GET_LOCK()` is already on master, ensure the duplicate check uses the same connection:

```php
private static function find_recent_order( $cart_hash, $email, $lockout_duration ) {
    global $wpdb;

    // HyperDB should automatically route this to master after GET_LOCK()
    // But we can be explicit by ensuring we're using the write connection
    $time_threshold = gmdate( 'Y-m-d H:i:s', time() - $lockout_duration );

    // The query will use the same connection as GET_LOCK() if HyperDB is configured correctly
    $order_id = $wpdb->get_var( /* ... */ );

    return $order_id;
}
```

## Verification

To verify replication and lock behavior:

1. **Check replication lag**:
   ```bash
   docker exec woocommerce-mysql-replica mysql -uroot -pSecure@RootPass2026 -e "SHOW REPLICA STATUS\G" | grep Seconds_Behind_Source
   ```

2. **Test atomic locks**:
   - Run concurrent checkout requests
   - Check logs to see if duplicate orders are prevented
   - Monitor which server handles the duplicate check query

3. **Monitor query routing**:
   - Enable HyperDB query logging
   - Check which server handles GET_LOCK() vs SELECT queries

## Configuration

The `db-config.php` is now configured with:
- Master: `write=1, read=2` (lower priority for reads)
- Replica: `write=0, read=1` (higher priority for reads)

This means:
- All writes go to master
- Reads prefer replica, but fallback to master
- After a write, subsequent reads go to master (HyperDB behavior)

## Recommendation

**For maximum safety**, modify `prevent-duplicate-order.php` to ensure the duplicate check happens on master:

1. After `GET_LOCK()`, the connection is already on master
2. HyperDB should route the duplicate check to master automatically
3. But to be 100% sure, you could add a comment or log which server handled the query

The current setup should work, but monitor it closely in production.
