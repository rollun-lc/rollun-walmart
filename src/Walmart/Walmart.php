<?php

declare(strict_types=1);

namespace rollun\Walmart;

use rollun\dic\InsideConstruct;
use rollun\Walmart\Sdk;

/**
 * Class Walmart
 *
 * @property Sdk\Feed $feed
 * @property Sdk\Inventory $inventory
 * @property Sdk\Item $item
 * @property Sdk\Orders $orders
 * @property Sdk\Price $price
 * @property Sdk\Reports $reports
 * @property Sdk\Utilities $utilities
 * @property Sdk\Insights $insights
 *
 * @method Sdk\Feed getApiFeed()
 * @method Sdk\Inventory getApiInventory()
 * @method Sdk\Item getApiItem()
 * @method Sdk\Orders getApiOrders()
 * @method Sdk\Price getApiPrice()
 * @method Sdk\Reports getApiReports()
 * @method Sdk\Utilities getApiUtilities()
 * @method Sdk\Insights getApiInsights()
 */
class Walmart
{
    protected $apis = [];

    /**
     * Walmart constructor.
     */
    public function __construct($apis) {
        $this->apis = $apis;
    }

    public function getApi($api)
    {
        if (array_key_exists($api, $this->apis)) {
            return $this->apis[$api];
        }

        throw new \Exception('Api not exist');
    }

    public function __call($name, $arguments)
    {
        if (strpos($name, 'getApi') === 0) {
            $apiName = strtolower(substr($name, 6));
            return $this->getApi($apiName);
        }

        throw new \Exception('Unknown method');
    }

    public function __get($name)
    {
        if ($api = $this->getApi($name)) {
            return $api;
        }

        throw new \Exception('Unknown property');
    }

    public function __isset($name)
    {
        return !empty($this->getApi($name));
    }

    /**
     * @throws \ReflectionException
     */
    public function __wakeup()
    {
        InsideConstruct::initWakeup([
            'feed' => Sdk\Feed::class,
            'item' => Sdk\Item::class,
            'inventory' => Sdk\Inventory::class,
            'orders' => Sdk\Orders::class,
            'price' => Sdk\Price::class,
            'reports' => Sdk\Reports::class,
        ]);
    }

    /**
     * @param string $feedId
     *
     * @return array
     */
    public function getFeedStatus(string $feedId): array
    {
        $statuses = [];
        $limit = 1000;
        $offset = 0;

        do {
            $response = $this->feed->getFeedStatus($feedId, true, $limit, $offset);
            $total = (int)$response['itemsReceived'];
            $statuses[] = $response['itemDetails']['itemIngestionStatus'];
            $offset += $limit;
            usleep(500000);
        } while ($offset < $total);

        // TODO
        $response['offset'] = 0;
        $response['limit'] = $total;

        $response['itemDetails']['itemIngestionStatus'] = array_merge(...$statuses);

        return $response;

        //return $this->feed->getFeedStatus($feedId, true, 1000, 0);
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function getActiveItems()
    {
        // prepare result
        $result = [];

        // get items
        do {
            $data = $this->item->getItems(
                200,
                $nextCursor ?? null,
                Sdk\Item::LIFECYCLE_STATUS_ACTIVE,
                Sdk\Item::PUBLISHED_STATUS_PUBLISHED
            );
            $result[] = $data['ItemResponse'];
            $nextCursor = $data['nextCursor'] ?? null;
            usleep(250000);
        } while ($nextCursor);

        $result = array_merge(...$result);

        return $this->setDataFromReport($result);
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function getAllItems()
    {
        // prepare result
        $result = [];

        // get items
        do {
            $data = $this->item->getItems(
                200,
                $nextCursor ?? null
            );
            $result[] = $data['ItemResponse'];
            $nextCursor = $data['nextCursor'] ?? null;
            usleep(250000);
        } while (isset($data['nextCursor']));

        $result = array_merge(...$result);

        return $this->setDataFromReport($result);
    }

    /**
     * @return array[]
     *
     * @todo
     */
    protected function getInventoryFromReport()
    {
        $keys = [
            "mart",
            "sku",
            "wpid",
            "upc",
            "gtin",
            "productName",
            "shelf",
            "productType",
            "price",
            "publishedStatus",
            "unpublishedReasons",
            "lifecycleStatus"
        ];
        $report = $this->reports->getItemReport();
        $report = array_column($report, null, 'sku');
        $results = array_map(function ($item) use ($keys) {
            foreach ($keys as $key) {
                if ($key === 'publishedStatus') {
                    $value = $item['publish_status'];
                } elseif ($key === 'price') {
                    $value = ['amount' => $item['price']];
                } elseif (array_key_exists($key, $item)) {
                    $value = $item[$key];
                } else {
                    $temp = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $key));
                    $value = $item[$temp] ?? null;
                }
                $result[$key] = $value;
            }
            $result['report'] = $item;
            return $result;
        }, $report);

        return $results;
    }

    /**
     * @param $ids
     *
     * @todo
     */
    public function deleteItems($ids)
    {
        foreach ($ids as $id) {
            try {
                $this->item->deleteItem($id);
            } catch (\Throwable $exception) {

            }
        }
    }


    /**
     * Get all orders
     *
     * @return array
     */
    public function getAllOrders(): array
    {
        // prepare result
        $result = [];

        $nextCursor = null;

        do {
            $data = $this->orders->getAll($nextCursor);
            $nextCursor = $data['list']['meta']['nextCursor'];
            //$result = array_merge($result, $data['list']['elements']['order']);
            $result[] = $data['list']['elements']['order'];
        } while ($nextCursor);

        $result = array_merge(...$result);

        return $result;
    }

    /**
     * Get orders by number of days passed
     *
     * @param int $days
     *
     * @return array
     */
    public function getOrdersByDaysPassed(int $days): array
    {
        if ($days < 1) {
            throw new \InvalidArgumentException('The number of days passed should be more the 0');
        }

        // prepare date
        $date = (new \DateTime())->setTime(0, 0, 0);
        $date->modify("-$days days");

        return $this->orders->getByCreatedStartDate($date);
    }

    /**
     * Get order
     *
     * @param string $orderId
     *
     * @return array
     */
    public function getOrder(string $orderId): array
    {
        return $this->orders->getOrder($orderId);
    }

    /**
     * @param string $orderId
     * @param string $carrier
     * @param string $methodCode
     * @param string $trackNumber
     * @param \DateTime $shippingDate
     * @param array $lines
     *
     * @return array
     */
    public function updateOrderTrackNumber(
        string $orderId,
        string $carrier,
        string $methodCode,
        string $trackNumber,
        \DateTime $shippingDate,
        array $lines = [1]
    ): array {
        $carriers = [
            'UPS', 'USPS', 'FedEx', 'Airborne', 'OnTrac', 'DHL', 'LS', 'UDS', 'UPSMI', 'FDX', 'PILOT', 'ESTES', 'SAIA'
        ];
        if (!in_array($carrier, $carriers)) {
            throw new \InvalidArgumentException('Unknown carrier. Please choose some of:' . implode(', ', $carriers));
        }

        $methodCodes = ['Standard', 'Express', 'Oneday', 'Freight', 'WhiteGlove', 'Value'];
        if (!in_array($methodCode, $methodCodes)) {
            throw new \InvalidArgumentException(
                'Unknown method code. Please choose some of:' . implode(', ', $methodCodes)
            );
        }

        if (empty($lines)) {
            throw new \InvalidArgumentException('Lines is required');
        }

        $data = [
            'orderShipment' => [
                'processMode' => 'PARTIAL_UPDATE',
                'orderLines' => [
                    'orderLine' => [],
                ]
            ]
        ];

        foreach ($lines as $lineNumber) {
            $trackingUrl = "https://www.walmart.com/tracking?tracking_id=$trackNumber&order_id=$orderId";
            $data['orderShipment']['orderLines']['orderLine'][] = [
                'lineNumber' => $lineNumber,
                'orderLineStatuses' => [
                    'orderLineStatus' => [
                        [
                            'status' => 'Shipped',
                            'statusQuantity' => [
                                'unitOfMeasurement' => 'EACH',
                                'amount' => 1
                            ],
                            'trackingInfo' => [
                                'shipDateTime' => $shippingDate->getTimestamp() * 1000,
                                'carrierName' => [
                                    'otherCarrier' => null,
                                    'carrier' => $carrier
                                ],
                                'methodCode' => $methodCode,
                                'trackingNumber' => $trackNumber,
                                'trackingURL' => $trackingUrl,
                            ]
                        ]
                    ]
                ]
            ];
        }

        return $this->orders->shippingUpdate($orderId, $data);
    }

    /**
     * @param array $data
     *
     * @return string
     */
    public function updateQuantity(array $data): string
    {
        // prepare request data
        $requestData = [
            'InventoryHeader' => [
                'version' => '1.4'
            ],
            'Inventory' => []
        ];
        foreach ($data as $sku => $quantity) {
            $requestData['Inventory'][] = [
                'sku' => (string) $sku,
                'quantity' => [
                    'unit' => 'EACH',
                    'amount' => $quantity
                ]
            ];
        }

        $response = $this->inventory->bulkUpdateInventory($requestData);
        if (empty($response['feedId'])) {
            throw new \Exception('No feedId in the response of bulkUpdateInventory');
        }

        return $response['feedId'];
    }

    /**
     * @param array $data
     *
     * @return string
     */
    public function updatePrice(array $data): string
    {
        // prepare request data
        $requestData = [
            'PriceHeader' => [
                'version' => '1.7'
            ],
            'Price' => []
        ];
        foreach ($data as $sku => $price) {
            $requestData['Price'][] = [
                'sku' =>  (string) $sku,
                'pricing' => [
                    [
                        'currentPriceType' => 'BASE',
                        'currentPrice' => [
                            'currency' => 'USD',
                            'amount' => $price
                        ]
                    ]
                ]
            ];
        }

        $response = $this->price->bulkUpdatePrice($requestData);
        if (empty($response['feedId'])) {
            throw new \Exception('No feedId in the response of bulkUpdatePrice');
        }

        return $response['feedId'];
    }

    /**
     * @param array $result
     *
     * @return array
     * @throws \Exception
     */
    protected function setDataFromReport(array $result): array
    {
        if (!empty($result)) {
            $report = $this->reports->getItemReport();
            if (!empty($report)) {
                $report = array_column($report, null, 'sku');
                foreach ($result as $k => $row) {
                    if (array_key_exists($row['sku'], $report)) {
                        $result[$k]['report'] = $report[$row['sku']];
                    }
                }
            }
        }

        return $result;
    }
}
