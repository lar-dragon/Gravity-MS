<?php

namespace Ecomkassa\Moysklad\Service;

use DateTimeImmutable;
use Ecomkassa\Moysklad\SDK\Ecomkassa\EcomApi;
use Ecomkassa\Moysklad\SDK\Ecomkassa\Check;
use Ecomkassa\Moysklad\SDK\Ecomkassa\Check\Operation;
use Ecomkassa\Moysklad\SDK\Ecomkassa\Check\Receipt;
use Ecomkassa\Moysklad\SDK\Ecomkassa\Check\Receipt\Position;
use Ecomkassa\Moysklad\SDK\Ecomkassa\Check\Receipt\MarkCode;
use Ecomkassa\Moysklad\SDK\Ecomkassa\Check\Receipt\Vat;
use Ecomkassa\Moysklad\SDK\Ecomkassa\Check\Receipt\Company;
use Ecomkassa\Moysklad\SDK\Ecomkassa\Check\Receipt\Payment;
use Ecomkassa\Moysklad\SDK\Ecomkassa\Response\MarkVerifyResponse;
use Ecomkassa\Moysklad\SDK\Ecomkassa\Response\MarkVerify\MarkVerifyItem;
use Ecomkassa\Moysklad\SDK\Moysklad\JsonApi;
use Ecomkassa\Moysklad\SDK\Moysklad\Helper;
use Ecomkassa\Moysklad\SDK\Moysklad\Document;
use Ecomkassa\Moysklad\SDK\Moysklad\Attribute;
use Ecomkassa\Moysklad\SDK\Moysklad\Entity\Webhook\Type;

class PopupService extends AbstractService
{
    public function saveProducts($extensionPoint, $objectId, $items): bool
    {
        $this->getLogger()->info('Сохранение товаров с кодами маркировки', [
            'extensionPoint' => $extensionPoint,
            'objectId' => $objectId,
            'items' => $items,
        ]);

        if (empty($extensionPoint)) {
            $this->getLogger()->warning('Сохранение товаров с кодами маркировки не удалось из-за пустого extensionPoint');

            return false;
        }

        if (empty($objectId)) {
            $this->getLogger()->warning('Сохранение товаров с кодами маркировки не удалось из-за пустого objectId');

            return false;
        }

        if (!is_array($items)) {
            $this->getLogger()->warning('Сохранение товаров с кодами маркировки не удалось из-за items, который не является массивом');

            return false;
        }

        $entity = $this->getEntityKeyByExtensionPoint($extensionPoint);

        return $this->updateTrackingCodes($entity, $objectId, $items);
    }

    public function updateTrackingCodes($entity, $objectId, $items): bool
    {
        $this->getLogger()->info('Обновление кодов маркировки', [
            'entity' => $entity,
            'objectId' => $objectId,
            'items' => $items,
        ]);

        if (empty($entity)) {
            $this->getLogger()->warning('Обновление кодов маркировки невозможно из-за пустого entity');

            return false;
        }

        if (empty($objectId)) {
            $this->getLogger()->warning('Обновление кодов маркировки невозможно из-за пустого objectId');

            return false;
        }

        if (!is_array($items)) {
            $this->getLogger()->warning('Обновление кодов маркировки невозможно из-за пустого items');

            return false;
        }

        foreach ($items as $item) {
            $this->savePosition($entity, $objectId, $item);
        }

        return true;
    }

    public function getEntityKeyByExtensionPoint(string $extensionPoint): ?string
    {
        $entity = null;

        if (Type::DEMAND == $extensionPoint) {
            return $extensionPoint;
        }

        if (Type::SALES_RETURN == $extensionPoint) {
            return $extensionPoint;
        }

        if (Type::CUSTOMER_ORDER == $extensionPoint) {
            return $extensionPoint;
        }

        if (Document::DOCUMENT_DEMAND_EDIT == $extensionPoint) {
            $entity = Type::DEMAND;
        }

        if (Document::DOCUMENT_SALESRETURN_EDIT == $extensionPoint) {
            $entity = Type::SALES_RETURN;
        }

        if (Document::DOCUMENT_CUSTOMERORDER_EDIT == $extensionPoint) {
            $entity = Type::CUSTOMER_ORDER;
        }

        return $entity;
    }

    public function getProductsByEntity(array $options = []): ?array
    {
        $appId = $this->getAppId();
        $accountId = JsonApi::getAccountIdByContextKey($this->getContextKey());
        $jsonApi = $this->getJsonApi($accountId);
        $objectId = $options['objectId'] ?? null;
        $extensionPoint = $options['extensionPoint'] ?? null;
        $entity = $this->getEntityKeyByExtensionPoint($extensionPoint);

        if ($entity !== null) {

            $objects = $jsonApi->getObjects($entity . '/' . $objectId . '/positions');

            if ($objects) {

                $rows = $objects->rows ?? null;

                if (is_array($rows)) {

                    $a = [];

                    foreach ($rows as $row) {

                        $name = '';
                        $object = $jsonApi->getByHref($row->assortment->meta->href);

                        if ($object) {
                            $name = $object->name ?? '';
                        }

                        $positions = $this->getPositions($entity, $objectId, $row->id);

                        if (is_array($positions) && !empty($positions)) {
                            foreach ($positions as $position) {
                                $a[] = [
                                    'id' => $row->id,
                                    'product_id' => $object->id,
                                    'name' => $name,
                                    'quantity' => $row->quantity,
                                    'mark' => $position['code'] ?? null,
                                    'price' => $this->fetchSellPrice($row),
                                    'mark_status' => $position['checked'] ?? null,
                                ];
                            }
                        } else {
                            $a[] = [
                                'id' => $row->id,
                                'product_id' => $object->id,
                                'name' => $name,
                                'quantity' => $row->quantity,
                                'mark' => '',
                                'price' => $this->fetchSellPrice($row),
                                'mark_status' => null,
                            ];
                        }
                    }

                    return $a;
                }
            }
        }

        return null;
    }

    /**
     * Возвращает массив позиций кодов для указанной сущности, объекта и идентификатора позиции.
     *
     * @param string $entity      Имя сущности (ms_entity)
     * @param string $objectId    Идентификатор объекта (ms_object_id)
     * @param string $positionId  Идентификатор позиции (ms_position_id)
     *
     * @return ?array Массив ассоциативных массивов с полями 'code', `position_id` либо null при отсутствии данных
     */
    public function getPositions(string $entity, string $objectId, string $positionId): ?array
    {
        $connectionService = new ConnectionService($this->getLogger());
        $connection = $connectionService->getConnection();
        $queryBuilder = $connection->createQueryBuilder();

        $result = $queryBuilder
            ->select('c.code', 'c.checked', 'pc.position_id as position_id', 'c.id')
            ->from('codes', 'c')
            ->innerJoin('c', 'position_codes', 'pc', 'c.id = pc.code_id')
            ->innerJoin('pc', 'positions', 'p', 'p.id = pc.position_id')
            ->where('p.ms_entity = :entity')
            ->andWhere('p.ms_object_id = :object_id')
            ->andWhere('p.ms_position_id = :position_id')
            ->setParameter('entity', $entity)
            ->setParameter('object_id', $objectId)
            ->setParameter('position_id', $positionId)
            ->executeQuery()
            ->fetchAllAssociative();

        if ($result) {

            return $result;
        }

        return null;
    }

    public function getCodeByPosition(string $entity, string $objectId, string $positionId): ?array
    {
        $connectionService = new ConnectionService($this->getLogger());
        $connection = $connectionService->getConnection();
        $queryBuilder = $connection->createQueryBuilder();

        $result = $queryBuilder
            ->select('c.code', 'pc.position_id as position_id', 'c.id')
            ->from('codes', 'c')
            ->innerJoin('c', 'position_codes', 'pc', 'c.id = pc.code_id')
            ->innerJoin('pc', 'positions', 'p', 'p.id = pc.position_id')
            ->where('p.ms_entity = :entity')
            ->andWhere('p.ms_object_id = :object_id')
            ->andWhere('p.ms_position_id = :position_id')
            ->setParameter('entity', $entity)
            ->setParameter('object_id', $objectId)
            ->setParameter('position_id', $positionId)
            ->executeQuery()
            ->fetchAssociative();

        if ($result) {

            return $result;
        }

        return null;
    }

    public function savePosition(string $entity, string $objectId, array $item): void
    {
        $positionId = $item['id'] ?? null;

        if ($positionId === null) {
            return ;
        }

        $item['mark'] = trim($item['mark']);

        $connectionService = new ConnectionService($this->getLogger());
        $connection = $connectionService->getConnection();
        $queryBuilder = $connection->createQueryBuilder();
        $position = $queryBuilder
            ->select('p.*')
            ->from('positions', 'p')
            ->where('p.ms_entity = :entity')
            ->andWhere('p.ms_object_id = :object_id')
            ->andWhere('p.ms_position_id = :position_id')
            ->setParameter('entity', $entity)
            ->setParameter('object_id', $objectId)
            ->setParameter('position_id', $positionId)
            ->executeQuery()
            ->fetchAssociative();

        $code = null;

        if ($position) {
            $code = $this->getCodeByPosition($entity, $objectId, $positionId);
        }

        if ($position && $code) {
            $a = [
                'code' => $item['mark'] ?? null,
                'updated_at' => (new DateTimeImmutable())->format('c'),
            ];

            if ($code['code'] != $item['mark'] ?? null) {
                $a['checked'] = null;
            }

            $connection->update('codes', $a, [
                'id' => $code['id'],
            ]);
        } else {

            $connection->insert('positions', [
                'ms_entity' => $entity,
                'ms_object_id' => $objectId,
                'ms_position_id' => $positionId,
                'created_at' => (new DateTimeImmutable())->format('c'),
                'updated_at' => (new DateTimeImmutable())->format('c'),
            ]);

            $positionRecordId = $connection->lastInsertId();

            $connection->insert('codes', [
                'code' => $item['mark'] ?? null,
                'created_at' => (new DateTimeImmutable())->format('c'),
                'updated_at' => (new DateTimeImmutable())->format('c'),
            ]);

            $codeRecordId = $connection->lastInsertId();

            $connection->insert('position_codes', [
                'position_id' => $positionRecordId,
                'code_id' => $codeRecordId,
            ]);
        }
    }

    public function fetchTrackingCode($tracking)
    {
        if ($tracking) {
            $rows = $tracking->rows ?? null;

            if (is_array($rows)) {
                foreach ($rows as $row) {
                    return $row;
                }
            }
        }

        return null;
    }

    public function fetchSellPrice($product): ?float
    {
        if ($product) {

            $price = $product->price ?? null;

            if ($price !== null) {

                return (float)$price;
            }

            $salePrices = $product->salePrices ?? null;

            if (is_array($salePrices)) {
                $salePrice = array_shift($salePrices);

                if ($salePrice) {
                    $value = $salePrice->value ?? null;

                    if ($value !== null) {

                        return (float)$value;
                    }

                }
            }
        }

        return null;
    }

    public function getProductsByTerm(string $term, int $limit): array
    {
        $accountId = JsonApi::getAccountIdByContextKey($this->getContextKey());
        $jsonApi = $this->getJsonApi($accountId);
        $objects = $jsonApi->getObjects('product', ['search' => $term]);

        $items = [];

        $rows = $objects->rows ?? null;

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $items[] = [
                    'id' => $row->id,
                    'product_id' => $row->id,
                    'name' => $row->name,
                    'mark' => null,
                    'price' => $this->fetchSellPrice($row),
                ];
            }
        }

        return $items;
    }

    public function isTrackingCode($term): bool
    {
        return false;
    }

    public function getProductsByTrackingCode($trackingCode, $limit): array
    {
        $accountId = JsonApi::getAccountIdByContextKey($this->getContextKey());
        $jsonApi = $this->getJsonApi($accountId);
        $objects = $jsonApi->getObjects('product', ['filter' => 'trackingCodes.cis='. $trackingCode]);

        $items = [];

        $rows = $objects->rows ?? null;

        if (is_array($rows)) {
            foreach ($rows as $row) {

                $mark = null;

                $items[] = [
                    'id' => $row->id,
                    'product_id' => $row->id,
                    'name' => $row->name,
                    'mark' => $mark,
                    'price' => $this->fetchSellPrice($row),
                ];
            }
        }

        return $items;
    }

    public function getTrackingCodeByPosition($extensionPoint, $objectId, $productId)
    {
        try {
            $accountId = JsonApi::getAccountIdByContextKey($this->getContextKey());
            $jsonApi = $this->getJsonApi($accountId);
            $entity = $this->getEntityKeyByExtensionPoint($extensionPoint);

            $positions = $jsonApi->getObjects($entity . '/' . $objectId . '/positions');

            if (is_array($positions?->rows)) {
                foreach ($positions->rows as $position) {
                    if (preg_match('/' . $productId . '/', $position->assortment->meta->href)) {
                        $trackingCodes = $position->trackingCodes ?? [];

                        if (is_array($trackingCodes)) {
                            $c = array_shift($trackingCodes);

                            return [$c, $position->id];
                        }
                    }
                }
            }

        } catch (\Exception $exception) {
            return $exception->getMessage();
        }
    }

    public function verifyCode(?string $code): ?bool
    {
        // Поле code может содержать \n по краям, которые попадают в БД через refreshStatuses
        $code = trim($code);

        if (empty($code)) {

            return null;
        }

        $ecomApi = new EcomApi();
        $ecomApi->setLogger($this->getLogger());

        $accountId = JsonApi::getAccountIdByContextKey($this->getContextKey());
        $app = Helper::getAppByAccountId($accountId);

        $jsonApi = $this->getJsonApi($accountId);

        // Данные для отправки
        $groupCode = $app->shopId;
        $operation = Operation::SELL;
        $login = $app->login;
        $password = $app->password;

        $items = [];

        $position = new Position();
        $markCode = new MarkCode();
        $markCode->setGs1m($code);
        $position->setMarkCode($markCode);

        $vat = new Vat();
        $vat->setType(Vat::VAT_NONE);
        $position->setVat($vat);
        $position->setName($code);
        $position->setQuantity(1);
        $position->setPaymentMethod(Payment::PAYMENT_METHOD_FULL_PAYMENT);
        $position->setPaymentObject(Payment::PAYMENT_OBJECT_COMMODITY);

        $items[] = $position->toArray();

        $receipt = new Receipt();


        $payments = [];
        $payment = new Payment();
        $payment->setType(Payment::TYPE_CASHLESS);
        $payment->setSum(100);
        $payments[] = $payment;

        $receipt->setPayments($payments);

        $company = new Company();
        $company->setSno(Company::SNO_OSN)->setEmail('dummy@example.com')->setInn('111111111111')->setPaymentAddress('test address');
        $receipt->setCompany($company);

        $receipt->setItems($items);
        $check = new Check();
        $check->setReceipt($receipt);

        $markVerifyResponse = $ecomApi->markVerify($check, $groupCode, $operation, $login, $password);
        if ($markVerifyResponse instanceof MarkVerifyResponse) {

            $items = $markVerifyResponse->getItems();

            if (is_array($items)) {
                foreach ($items as $item) {
                    if ($item->getName() == $position->getName()) {
                        if ($item->getStatus() == MarkVerifyItem::MARK_SUCCESS) {
                            return true;
                        } else {
                            return false;
                        }
                    }
                }
            }
        }
        return false;
    }

    public function refreshStatuses($extensionPoint, $objectId): ?array
    {
        $entity = $this->getEntityKeyByExtensionPoint($extensionPoint);

        $connectionService = new ConnectionService($this->getLogger());
        $connection = $connectionService->getConnection();
        $queryBuilder = $connection->createQueryBuilder();

        $positions = $queryBuilder
            ->select('c.code', 'p.ms_position_id as position_id', 'c.id', 'c.checked')
            ->from('codes', 'c')
            ->innerJoin('c', 'position_codes', 'pc', 'c.id = pc.code_id')
            ->innerJoin('pc', 'positions', 'p', 'p.id = pc.position_id')
            ->where('p.ms_entity = :entity')
            ->andWhere('p.ms_object_id = :object_id')
            ->setParameter('entity', $entity)
            ->setParameter('object_id', $objectId)
            ->executeQuery()
            ->fetchAllAssociative();

        if (is_array($positions)) {
            $result = [];

            foreach ($positions as $position) {
                $checked = $position['checked'];

                if ($checked !== true) {
                    $checked = $this->verifyCode($position['code']);

                    $checkedValue = $checked;
                    if ($checked === false) {
                        $checkedValue = 'false';
                    }

                    $connection->update('codes', [
                        'checked' => $checkedValue,
                    ], [
                        'id' => $position['id'],
                    ]);
                }

                $result[] = [
                    'position_id' => $position['position_id'],
                    'checked' => $checked,
                ];
            }

            return $result;
        }

        return null;
    }
}
