<?php

namespace MailerPress\Core\Workflows\Repositories;

use MailerPress\Core\Enums\Tables;

class CartTrackingRepository
{
    private \wpdb $wpdb;
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . Tables::MAILERPRESS_TRACK_CART;
    }

    /**
     * Create or update a cart tracking entry
     * Updates existing active cart for user instead of creating new entries
     * 
     * @param string $cartHash
     * @param int $userId
     * @param string $customerEmail
     * @param array $cartData
     * @return array Returns ['success' => bool, 'is_new' => bool] - is_new indicates if this is a new cart entry
     */
    public function upsertCart(string $cartHash, int $userId, string $customerEmail, array $cartData): array
    {
        // Check if user already has an active cart (instead of checking by cart_hash)
        // This ensures we update the same cart entry when items are added/removed
        $existing = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT id, cart_hash FROM {$this->table} WHERE user_id = %d AND status = 'ACTIVE' ORDER BY updated_at DESC LIMIT 1",
                $userId
            ),
            ARRAY_A
        );

        $data = [
            'cart_hash' => $cartHash,
            'user_id' => $userId,
            'customer_email' => $customerEmail,
            'cart_data' => json_encode($cartData),
            'status' => 'ACTIVE',
            'updated_at' => current_time('mysql'),
        ];

        if ($existing) {
            // Update existing active cart with new cart_hash and data
            $result = $this->wpdb->update(
                $this->table,
                $data,
                ['id' => $existing['id']],
                ['%s', '%d', '%s', '%s', '%s', '%s'],
                ['%d']
            );
            return ['success' => $result !== false, 'is_new' => false];
        } else {
            // Insert new cart (first time for this user)
            $data['created_at'] = current_time('mysql');
            $result = $this->wpdb->insert(
                $this->table,
                $data,
                ['%s', '%d', '%s', '%s', '%s', '%s', '%s']
            );
            return ['success' => $result !== false, 'is_new' => true];
        }
    }

    /**
     * Mark cart as emptied
     * 
     * @param string $cartHash
     * @return bool
     */
    public function markCartEmptied(string $cartHash): bool
    {
        $result = $this->wpdb->update(
            $this->table,
            [
                'status' => 'EMPTIED',
                'emptied_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['cart_hash' => $cartHash],
            ['%s', '%s', '%s'],
            ['%s']
        );
        return $result !== false;
    }

    /**
     * Mark cart as completed (order created)
     * 
     * @param string $cartHash
     * @return bool
     */
    public function markCartCompleted(string $cartHash): bool
    {
        $result = $this->wpdb->update(
            $this->table,
            [
                'status' => 'COMPLETED',
                'updated_at' => current_time('mysql'),
            ],
            ['cart_hash' => $cartHash],
            ['%s', '%s'],
            ['%s']
        );
        return $result !== false;
    }

    /**
     * Check if cart is still active by cart_hash
     * 
     * @param string $cartHash
     * @return bool
     */
    public function isCartActive(string $cartHash): bool
    {
        $result = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT status FROM {$this->table} WHERE cart_hash = %s",
                $cartHash
            )
        );
        return $result === 'ACTIVE';
    }

    /**
     * Check if user has an active cart
     * 
     * @param int $userId
     * @return bool
     */
    public function hasActiveCart(int $userId): bool
    {
        $result = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE user_id = %d AND status = 'ACTIVE'",
                $userId
            )
        );
        return (int)$result > 0;
    }

    /**
     * Get cart by hash
     * 
     * @param string $cartHash
     * @return array|null
     */
    public function getCartByHash(string $cartHash): ?array
    {
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE cart_hash = %s",
                $cartHash
            ),
            ARRAY_A
        );
        return $result ?: null;
    }

    /**
     * Get active cart by user ID
     * 
     * @param int $userId
     * @return array|null
     */
    public function getActiveCartByUserId(int $userId): ?array
    {
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE user_id = %d AND status = 'ACTIVE' ORDER BY updated_at DESC LIMIT 1",
                $userId
            ),
            ARRAY_A
        );
        return $result ?: null;
    }

    /**
     * Delete cart by hash
     * 
     * @param string $cartHash
     * @return bool
     */
    public function deleteCartByHash(string $cartHash): bool
    {
        $result = $this->wpdb->delete(
            $this->table,
            ['cart_hash' => $cartHash],
            ['%s']
        );
        return $result !== false;
    }

    /**
     * Delete all carts for a user
     * 
     * @param int $userId
     * @return int Number of carts deleted
     */
    public function deleteCartsByUserId(int $userId): int
    {
        $result = $this->wpdb->delete(
            $this->table,
            ['user_id' => $userId],
            ['%d']
        );
        return $result !== false ? $result : 0;
    }

    /**
     * Clean up old emptied carts (older than 30 days)
     * 
     * @return int Number of carts deleted
     */
    public function cleanupOldEmptiedCarts(): int
    {
        $date = date('Y-m-d H:i:s', strtotime('-30 days'));
        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->table} WHERE status = 'EMPTIED' AND emptied_at < %s",
                $date
            )
        );
        return $result !== false ? $result : 0;
    }
}

