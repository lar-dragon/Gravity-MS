<?php

namespace Ecomkassa\Moysklad\Handler;

use Throwable;
use Monolog\Logger;
use Ecomkassa\Moysklad\SDK\Moysklad\Entity\Webhook\Event;
use Ecomkassa\Moysklad\SDK\Moysklad\JsonApi;
use Ecomkassa\Moysklad\SDK\Moysklad\Helper;
use Ecomkassa\Moysklad\SDK\Moysklad\Service\DocumentService;
use Ecomkassa\Moysklad\SDK\Moysklad\Entity\Webhook\Type;
use Ecomkassa\Moysklad\SDK\Moysklad\Attribute;
use Ecomkassa\Moysklad\SDK\Ecomkassa\Check\Operation;
use Ecomkassa\Moysklad\SDK\Ecomkassa\MarkCodeDetector;
use Ecomkassa\Moysklad\SDK\Ecomkassa\Response\MarkVerifyResponse;
use Ecomkassa\Moysklad\SDK\Ecomkassa\Response\MarkVerify\MarkVerifyItem;
use Ecomkassa\Moysklad\SDK\Ecomkassa\Check\Receipt\SectoralItemProps;
use Ecomkassa\Moysklad\SDK\Ecomkassa\Response\MarkVerify\RequestInfo;
use Ecomkassa\Moysklad\SDK\Ecomkassa\EcomApi;
use Ecomkassa\Moysklad\SDK\Ecomkassa\Check;
use Ecomkassa\Moysklad\SDK\Ecomkassa\Check\Receipt;
use Ecomkassa\Moysklad\SDK\Ecomkassa\Check\Receipt\MarkCode;
use Ecomkassa\Moysklad\SDK\Ecomkassa\Check\Receipt\Client;
use Ecomkassa\Moysklad\SDK\Ecomkassa\Check\Receipt\Company;
use Ecomkassa\Moysklad\SDK\Ecomkassa\Check\Receipt\Payment;
use Ecomkassa\Moysklad\SDK\Ecomkassa\Check\Receipt\Position;
use Ecomkassa\Moysklad\SDK\Ecomkassa\Check\Receipt\Vat;
use Ecomkassa\Moysklad\Service\StatusService;
use Ecomkassa\Moysklad\SDK\Moysklad\Entity\Webhook\Action;
use Ecomkassa\Moysklad\Service\PopupService;

/**
 * Абстрактный обработчик webhook событий от МойСклад
 *
 * @package Ecomkassa\Moysklad\Handler
 */
abstract class AbstractHandler
{
    /**
     * Тип webhook события
     *
     * @var string
     */
    public string $type;

    /**
     * Действие webhook события
     *
     * @var string
     */
    public string $action;

    /**
     * Объект контекста
     *
     * @var object|null
     */
    private $contextObject = null;

    /**
     * Индекс документа
     *
     * @var int|null
     */
    private ?int $documentIndex = null;

    /**
     * Операция для чека
     *
     * @var string
     */
    public string $operation = Operation::SELL;

    /**
     * Логгер
     *
     * @var Logger|null
     */
    protected ?Logger $logger;

    /**
     * Установка логгера
     *
     * @param Logger|null $logger Логгер
     * @return static
     */
    public function setLogger(?Logger $logger): static
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * Получение логгера
     *
     * @return Logger|null Логгер
     */
    public function getLogger(): ?Logger
    {
        return $this->logger;
    }

    /**
     * Выполнение обработки события
     *
     * @param Event $event Событие webhook
     * @return void
     */
    public function run(Event $event): void
    {
        $accountId = $event->getAccountId();

        $app = Helper::getAppByAccountId($accountId);

        // Данные для отправки
        $groupCode = $app->shopId;
        $operation = $this->getOperation();
        $login = $app->login;
        $password = $app->password;

        // Данные чека
        $storeEmail = $app->email;
        $inn = $app->inn;
        $address = $app->address;
        $sno = $app->sno;

        $ecomApi = new EcomApi();
        $ecomApi->setLogger($this->getLogger());
        $check = new Check();

        $href = $event->getMeta()->getHref();

        $jsonApi = $this->getJsonApi($accountId);

        $entity = $this->getContextObject();

        $callbackUrl = $this->fetchCallbackUrl();
        $check->setCallbackUrl($callbackUrl);

        $receipt = new Receipt();
        $client = new Client();

        $agent = $jsonApi->getByHref($entity?->agent?->meta->href);

        if ($agent) {
            if (!empty($agent?->email)) {
                $client->setEmail($agent?->email);
            }

            if (!empty($agent?->phone)) {
                $client->setPhone($agent?->phone);
            }
        }

        $receipt->setClient($client);

        $company = new Company();

        $company->setSno($sno)->setEmail($storeEmail)->setInn($inn)->setPaymentAddress($address);
        $receipt->setCompany($company);

        $total = $this->getSum($entity);
        $receipt->setTotal($total);
        $documentIndex = $this->getDocumentIndex();
        $type = $app->type[$documentIndex] ?? null;
        $method = $app->method[$documentIndex] ?? null;

        $payments = [];
        $payment = new Payment();
        $typeStr = Payment::$typeMapping[$type] ?? null;

        if (!is_null($typeStr)) {
            $payment->setType($typeStr);
        }

        $payment->setSum($total);

        $payments[] = $payment;

        $receipt->setPayments($payments);

        $items = [];
        $itemsSums = 0;

        $positions = $jsonApi->getByHref($entity->positions->meta->href);
        if ($positions) {
            if (is_array($positions->rows)) {
                foreach ($positions->rows as $row) {
                    $position = new Position();

                    $this->applyMarkCode($position, $event, $entity, $row, $jsonApi);

                    $product = $jsonApi->getByHref($row->assortment->meta->href);

                    $this->getLogger()->info('URL товара: ' . json_encode($row->assortment->meta->href, JSON_PRETTY_PRINT || JSON_UNESCAPED_UNICODE));
                    $this->getLogger()->info('Товар: ' . json_encode($product, JSON_PRETTY_PRINT || JSON_UNESCAPED_UNICODE));

                    if ($row->discount) {
                        $row->price = $row->price - ($row->price * ($row->discount / 100));
                    }

                    $position->setName($product->name);
                    $position->setPrice($row->price / 100);

                    // Дя весовых товаров точность 3 знака после запятой
                    $total = floor($row->quantity * 1000) / 1000;
                    // Считаем сумму по позиции с округлением вверх по копейкам
                    $sum = ceil($total * $row->price);
                    $itemsSums += $sum;

                    $position->setSum($sum / 100);
                    $position->setQuantity($total);

                    $vat = new Vat();
                    $vat->setType(Vat::VAT_NONE);

                    if ($row->vatEnabled == 1) {
                       $value = $vat->getByValue($row->vat);

                       if ($value) {
                            $vat->setType($value);
                       }
                    }

                    $position->setVat($vat);
                    $this->getLogger()->info('НДС: ' . json_encode($position->getVat()?->toArray(), JSON_PRETTY_PRINT || JSON_UNESCAPED_UNICODE));

                    if ($app->obj[$documentIndex] ?? null) {
                        $position->setPaymentObject($app->obj[$documentIndex]);
                    } else {
                        $position->setPaymentObject(Payment::PAYMENT_OBJECT_COMMODITY);
                    }

                    if ($method) {
                        $position->setPaymentMethod($method);
                    }

                    $items[] = $position->toArray();
                }
            }
        }

        // Заменим $total если сумма позиций отличается от суммы по документу от МС
        $itemsTotal = $itemsSums / 100;
        if ($total !== $itemsTotal) {
            $receipt->setTotal($itemsTotal);
            $payment->setSum($itemsTotal);
        }

        $receipt->setItems($items);
        $check->setReceipt($receipt);

        if ($method !== Payment::PAYMENT_METHOD_FULL_PAYMENT) {
            $this->getLogger()->info('Предварительная проверка маркировки "Честный знак" пропущена так как выполняется только полного расчета');
        } elseif ($operation == Operation::SELL_REFUND) {
            $this->getLogger()->info('Предварительная проверка маркировки "Честный знак" пропущена так как не выполняется для возвратов');
        } else {
            $markVerifyResponse = $ecomApi->markVerify($check, $groupCode, $operation, $login, $password);
            if ($markVerifyResponse instanceof MarkVerifyResponse) {
                $this->getLogger()->info('Результат предварительной проверки маркировки "Честный знак" получен: orderId=' . $markVerifyResponse->getOrderId());

                $this->addMark($markVerifyResponse, $check);
            }
        }

        $statusService = new StatusService($this->getLogger());

        if (($this->getAction() != Action::CREATE) && $statusService->alreadyStored($entity)) {
            $this->getLogger()->warning('Чек уже создавался и не будет создан повторно', ['entity' => $entity]);
        } else {
            $response = $ecomApi->send($check, $groupCode, $operation, $login, $password);

            $this->getLogger()->info('Ответ сервиса: ' . json_encode($response));

            if ($response) {
                $force = ($this->getAction() === Action::CREATE);
                $statusService->store($entity, $response, $force);
            }
        }
    }

    public function applyLocalMarkCode(Position $position, Event $event, object $entity, object $row): void
    {
        $type = $event->getMeta()->getType();
        $entityId = $entity->id;
        $positionId = $row->id;

        $this->getLogger()->info('Добавление локального код маркировки', [
                'type' => $type,
                'entity_id' => $entityId,
                'position_id' => $positionId,
        ]);

        try {
            $popupService = new PopupService($this->getLogger());
            $code = $popupService->getCodeByPosition($type, $entityId, $positionId);

            if ($code) {
                $markCodeDetector = new MarkCodeDetector();
                $markCode = $markCodeDetector->retrieveMarkCodeByStr($code['code']);
                $position->setMarkCode($markCode);

                $this->getLogger()->info('Код найден',[
                    'type' => $type,
                    'entity_id' => $entityId,
                    'position_id' => $positionId,
                    'code' => $code,
                ]);
            } else {
                $this->getLogger()->warning('Код не найден',[
                    'type' => $type,
                    'entity_id' => $entityId,
                    'position_id' => $positionId,
                ]);
            }
        } catch (Throwable $exception) {
            $this->getLogger()->error('Ошибка при определение кода маркировки', [
                'error' => $exception->getMessage(),
                'type' => $type,
                'entity_id' => $entityId,
                'position_id' => $positionId,
            ]);
        }
    }

    /**
     * Применение кода маркировки к позиции
     *
     * @param Position $position Позиция чека
     * @param Event $event Событие webhook
     * @param object $entity Сущность документа
     * @param object $row Строка документа
     * @param JsonApi $jsonApi API для работы с МойСклад
     * @return void
     */
    public function applyMarkCode(Position $position, Event $event, object $entity, object $row, JsonApi $jsonApi): void
    {
        // Маркировка применяется к заказу, отгрузке и возврату
        if (!in_array($event->getMeta()->getType(), [Type::DEMAND, Type::CUSTOMER_ORDER, Type::SALES_RETURN])) {

            return ;
        }

        $this->applyLocalMarkCode($position, $event, $entity, $row);

        return ;

        $markCodeDetector = new MarkCodeDetector();

        $url = $jsonApi->fetchTrackingCodesUrl($event->getMeta()->getType(), $entity->id, $row->id);

        $this->getLogger()->info('URL маркировки: ' . json_encode($url, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $hasMarkCode = false;

        $object = $jsonApi->getByHref($row?->assortment?->meta?->href);

        if ($object) {

            $this->getLogger()->info('Объект маркировки: ' . json_encode($entity, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->getLogger()->info('Товар: ' . json_encode($object, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            $objectAttributes = $object->attributes ?? [];

            $this->getLogger()->info('Атрибуты товара: ' . json_encode($objectAttributes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            if (is_array($objectAttributes)) {
                foreach ($objectAttributes as $objectAttribute) {
                    if ($objectAttribute->name == Attribute::ATTRIBUTE_ID_TRACKING_CODE) {

                        $this->getLogger()->info('Найден атрибут маркировки с криптохвостом: ' . json_encode($objectAttribute, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                        $code = $objectAttribute->value;

                        $this->getLogger()->info('Найден код маркировки с криптохвостом: ' . json_encode($code, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                        $markCode = $markCodeDetector->retrieveMarkCodeByStr($code);
                        $position->setMarkCode($markCode);

                        $this->getLogger()->info('Определена маркировка с криптохвостом: ' . json_encode($markCode, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                        $hasMarkCode = true;
                    }
                }
            }
        }

        if ($hasMarkCode === false) {
            $tracking = $jsonApi->getByHref($url);

            $this->getLogger()->info('Маркировка: ' . json_encode($tracking, JSON_PRETTY_PRINT || JSON_UNESCAPED_UNICODE));

            $trackingRows = $tracking?->rows;

            if (is_array($trackingRows)) {
                foreach ($trackingRows as $trackingRow) {
                    $cis = $trackingRow?->cis;

                    if (!empty($cis)) {
                        $this->getLogger()->info('Определена маркировка: ' . json_encode($cis, JSON_PRETTY_PRINT || JSON_UNESCAPED_UNICODE));

                        $markCode = $markCodeDetector->retrieveMarkCodeByStr($cis);

                        if ($markCode) {
                            $this->getLogger()->info('Определен код маркировки: ' . json_encode($markCode->toArray(), JSON_PRETTY_PRINT || JSON_UNESCAPED_UNICODE));

                            $position->setMarkCode($markCode);
                        }
                    }
                }
            }
        }
    }

    /**
     * Добавление информации о маркировке "Честный знак" в чек
     *
     * @param MarkVerifyResponse $markVerifyResponse Ответ на проверку маркировки
     * @param Check $check Чек для отправки
     * @return void
     */
    public function addMark(MarkVerifyResponse $markVerifyResponse, Check $check): void
    {
        $items = $markVerifyResponse->getItems();

        if (is_array($items)) {
            foreach ($items as $item) {
                $index = $item->getIndex();

                $this->getLogger()->info('Обнаружена маркировка "Честный знак": ' . $item->getName() . ' (' . $index . ') ' . $item->getStatus());

                if ($item->getStatus() == MarkVerifyItem::MARK_SUCCESS) {

                    $product = $check->getReceipt()->getItemByIndex($index);
                    $this->getLogger()->info('Товар ' . json_encode($product->toArray()));

                    if (!is_null($product)) {
                        $requestInfo = $item->getRequestInfo();

                        if ($requestInfo instanceof RequestInfo) {
                            $sectoralItemProps = new SectoralItemProps();

                            $requestId = $requestInfo->getRequestId();
                            $timestamp = $requestInfo->getTimestamp();

                            $value = $sectoralItemProps->retrieveValue($requestId, $timestamp);

                            $sectoralItemProps->setValue($value);

                            $product->setSectoralItemProps($sectoralItemProps);
                            $check->getReceipt()->setItemByIndex($index, $product);

                            $this->getLogger()->info('Добавлены сведения о маркировке "Честный знак"', $sectoralItemProps->toArray());
                            $this->getLogger()->info('Измененный товар', $product->toArray());
                        }
                    }
                }
            }
        }
    }

    /**
     * Получение объекта контекста
     *
     * @return object|null Объект контекста
     */
    public function getContextObject()
    {
        return $this->contextObject;
    }

    /**
     * Установка объекта контекста
     *
     * @param object|null $contextObject Объект контекста
     * @return static
     */
    public function setContextObject($contextObject): static
    {
        $this->contextObject = $contextObject;

        return $this;
    }

    /**
     * Получение экземпляра JsonApi
     *
     * @param string $accountId Идентификатор аккаунта
     * @return JsonApi Экземпляр JsonApi
     */
    public function getJsonApi(string $accountId)
    {
        $accessToken = Helper::getAccessTokenByAccountId($accountId);
        $jsonApi = new JsonApi($accessToken);

        return $jsonApi;
    }

    /**
     * Получение экземпляра JsonApi по событию
     *
     * @param Event $event Событие webhook
     * @return JsonApi Экземпляр JsonApi
     */
    public function getJsonApiByEvent(Event $event)
    {
        return $this->getJsonApi($event->getMeta()->getAccountId());
    }

    /**
     * Проверка поддержки события данным обработчиком
     *
     * @param Event $event Событие webhook
     * @return bool true если поддерживается, false в противном случае
     */
    public function supports(Event $event): bool
    {
        $documentService = new DocumentService();
        $documentService->setLogger($this->getLogger());

        $documentIndex = $documentService->getDocumentIndex($event, $this->getContextObject());

        if (is_null($documentIndex)) {

            return false;
        }

        $this->setDocumentIndex($documentIndex);

        return ($event->getAction() === $this->getAction())
                && ($event->getMeta()?->getType() === $this->getType());
    }

    /**
     * Установка типа события
     *
     * @param string $type Тип события
     * @return static
     */
    public function setType(string $type): static 
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Получение типа события
     *
     * @return string Тип события
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Установка действия события
     *
     * @param string $action Действие события
     * @return static
     */
    public function setAction(string $action): static
    {
        $this->action = $action;

        return $this;
    }

    /**
     * Получение действия события
     *
     * @return string Действие события
     */
    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * Получение суммы документа
     *
     * @param object $object Объект документа
     * @return float|null Сумма документа
     */
    public function getSum($object)
    {
        $sum = $object->sum;

        if (!is_null($sum)) {

            return (float)round($object->sum / 100, 2);
        }

        return null;
    }

    /**
     * Получение URL обратного вызова
     *
     * @return string URL обратного вызова
     */
    function fetchCallbackUrl(): string
    {
        return Helper::getCallbackUrl();
    }

    /**
     * Получение индекса документа
     *
     * @return int|null Индекс документа
     */
    public function getDocumentIndex(): ?int
    {
        return $this->documentIndex;
    }

    /**
     * Установка индекса документа
     *
     * @param int|null $documentIndex Индекс документа
     * @return static
     */
    public function setDocumentIndex(?int $documentIndex): static
    {
        $this->documentIndex = $documentIndex;

        return $this;
    }

    /**
     * Установка операции для чека
     *
     * @param string $operation Операция для чека
     * @return static
     */
    public function setOperation(string $operation): static
    {
        $this->operation = $operation;

        return $this;
    }

    /**
     * Получение операции для чека
     *
     * @return string Операция для чека
     */
    public function getOperation(): string
    {
        return $this->operation;
    }
}