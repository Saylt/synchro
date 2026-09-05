<?php

namespace Tygh\Addons\Synchro\Importers;

use Throwable;
use Tygh\Addons\Warehouses\Manager;
use Tygh\Database\Connection;

/**
 * Replaces product warehouse stocks in batches.
 */
class ProductStockUpdater
{
    /** @var \Tygh\Database\Connection */
    private $database;

    /** @var \Tygh\Addons\Warehouses\Manager */
    private $warehouses_manager;

    /**
     * @param \Tygh\Database\Connection       $database           Database connection
     * @param \Tygh\Addons\Warehouses\Manager $warehouses_manager Warehouses manager
     */
    public function __construct(Connection $database, Manager $warehouses_manager)
    {
        $this->database = $database;
        $this->warehouses_manager = $warehouses_manager;
    }

    /**
     * @param array<int, array{amount: int, warehouses: array<int, int>}> $product_stocks Product stocks
     *
     * @return void
     *
     * @throws \Throwable When stock data cannot be saved.
     */
    public function update(array $product_stocks)
    {
        if (!$product_stocks) {
            return;
        }

        $product_ids = array_map('intval', array_keys($product_stocks));
        $stock_rows = [];
        $amount_cases = [];

        foreach ($product_stocks as $product_id => $product_stock) {
            $amount_cases[] = sprintf('WHEN %d THEN %d', $product_id, $product_stock['amount']);

            foreach ($product_stock['warehouses'] as $warehouse_id => $amount) {
                $stock_rows[] = [
                    'product_id'   => $product_id,
                    'warehouse_id' => $warehouse_id,
                    'amount'       => $amount,
                ];
            }
        }

        $this->database->beginTransaction();

        try {
            $this->database->query(
                'DELETE FROM ?:warehouses_products_amount WHERE product_id IN (?n)',
                $product_ids
            );

            if ($stock_rows) {
                $this->database->query('INSERT INTO ?:warehouses_products_amount ?m', $stock_rows);
            }

            $this->database->query(
                'UPDATE ?:products SET amount = CASE product_id ?p END WHERE product_id IN (?n)',
                implode(' ', $amount_cases),
                $product_ids
            );
            $this->warehouses_manager->recalculateDestinationProductsStocksByProductIds($product_ids);
            $this->database->commit();
        } catch (Throwable $exception) {
            $this->database->rollback();

            throw $exception;
        }
    }
}
