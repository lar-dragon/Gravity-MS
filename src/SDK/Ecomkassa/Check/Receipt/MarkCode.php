<?php

namespace Ecomkassa\Moysklad\SDK\Ecomkassa\Check\Receipt;

/**
 * Класс для работы с кодами маркировки товаров.
 * 
 * Предназначен для формирования и хранения различных форматов кодов маркировки,
 * которые используются при формировании чеков в соответствии с требованиями
 * приказа ФНС России от 14.09.2020 № ЕД-7-20/662@ (Таблица 118).
 */
class MarkCode
{
    /**
     * Код товара, формат которого не идентифицирован, как один из реквизитов.
     * Максимум 32 символа. Значения реквизита должно формироваться в соответствии
     * с правилами, указанными в Приложении № 2 к приказу ФНС России от 14.09.2020г.
     * № ЕД-7-20/662@ (Таблица 118)
     *
     * @var string|null
     */
    protected ?string $unknown = null;

    /**
     * Код товара в формате EAN-8.
     * Ровно 8 цифр.
     * Значения реквизита должно формироваться в соответствии с правилами,
     * указанными в Приложении № 2 к приказу ФНС России от 14.09.2020г.
     * № ЕД-7-20/662@ (Таблица 118)
     *
     * @var string|null
     */
    protected ?string $ean8 = null;

    /**
     * Код товара в формате EAN-13.
     * Ровно 13 цифр. Значения реквизита должно формироваться в соответствии
     * с правилами, указанными в Приложении № 2 к приказу ФНС России
     * от 14.09.2020г. № ЕД-7-20/662@ (Таблица 118)
     *
     * @var string|null
     */
    protected ?string $ean13 = null;

    /**
     * Код товара в формате ITF-14.
     * Ровно 14 цифр. Значения реквизита должно формироваться в соответствии
     * с правилами, указанными в Приложении № 2 к приказу ФНС России
     * от 14.09.2020г. № ЕД-7-20/662@ (Таблица 118)
     *
     * @var string|null
     */
    protected ?string $itf14 = null;

    /**
     * Код товара в формате GS1, нанесенный на товар, не подлежащий маркировке
     * средствами идентификации. Максимум 38 символов.
     * Значения реквизита должно формироваться в соответствии с правилами,
     * указанными в Приложении № 2 к приказу ФНС России от 14.09.2020г.
     * № ЕД-7-20/662@ (Таблица 118)
     *
     * @var string|null
     */
    protected ?string $gs10 = null;

    /**
     * Код товара в формате GS1, нанесенный на товар, подлежащий маркировке
     * средствами идентификации.
     * Максимум 200 символов.
     * Значения реквизита должно формироваться в соответствии с правилами,
     * указанными в Приложении № 2 к приказу ФНС России от 14.09.2020г.
     * № ЕД-7-20/662@ (Таблица 118)
     * Примечание: Код товара необходимо передавать целиком. В связи с тем,
     * что в коде товара могут быть непечатные символы, необходимо перед
     * отправкой кодировать строку с кодом товара в Base64.
     *
     * @var string|null
     */
    protected ?string $gs1m = null;

    /**
     * Код товара в формате короткого кода маркировки, нанесенный на товар,
     * подлежащий маркировке средствами идентификации.
     * Максимум 38 символов.
     * Значения реквизита должно формироваться в соответствии с правилами,
     * указанными в Приложении № 2 к приказу ФНС России от 14.09.2020г.
     * № ЕД-7-20/662@ (Таблица 118)
     *
     * @var string|null
     */
    protected ?string $short = null;

    /**
     * Контрольно-идентификационный знак мехового изделия.
     * Ровно 20 символов, должно соответствовать маске СС-ЦЦЦЦЦЦСССССССССС
     * Значения реквизита должно формироваться в соответствии с правилами,
     * указанными в Приложении № 2 к приказу ФНС России от 14.09.2020г.
     * № ЕД-7-20/662@ (Таблица 118)
     *
     * @var string|null
     */
    protected ?string $fur = null;

    /**
     * Код товара в формате ЕГАИС-2.0.
     * Ровно 23 символа. Значения реквизита должно формироваться в соответствии
     * с правилами, указанными в Приложении № 2 к приказу ФНС России
     * от 14.09.2020г. № ЕД-7-20/662@ (Таблица 118)
     *
     * @var string|null
     */
    protected ?string $egais20 = null;

    /**
     * Код товара в формате ЕГАИС-3.0.
     * Ровно 14 символов.
     * Значения реквизита должно формироваться в соответствии с правилами,
     * указанными в Приложении № 2 к приказу ФНС России от 14.09.2020г.
     * № ЕД-7-20/662@ (Таблица 118)
     *
     * @var string|null
     */
    protected ?string $egais30 = null;

    public function load(array $data): static
    {
        if (isset($data['unknown'])) {
            $this->setUnknown($data['unknown']);
        }

        if (isset($data['ean8'])) {
            $this->setEan8($data['ean8']);
        }

        if (isset($data['ean13'])) {
            $this->setEan13($data['ean13']);
        }

        if (isset($data['itf14'])) {
            $this->setItf14($data['itf14']);
        }

        if (isset($data['gs10'])) {
            $this->setGs10($data['gs10']);
        }

        if (isset($data['gs1m'])) {
            $s = base64_decode($data['gs1m']);
            $s = str_replace(chr(29), '\\u001D', $s);
            $this->setGs1m($s);
        }

        if (isset($data['short'])) {
            $this->setShort($data['short']);
        }

        if (isset($data['fur'])) {
            $this->setFur($data['fur']);
        }

        if (isset($data['egais20'])) {
            $this->setEgais20($data['egais20']);
        }

        if (isset($data['egais30'])) {
            $this->setEgais30($data['egais30']);
        }

        return $this;
    }

    /**
     * Преобразует объект в массив для использования в API.
     *
     * @return array
     */
    public function toArray(array $replace = []): array
    {
        $result = [];

        if ($this->getUnknown() !== null) {
            $result['unknown'] = $this->getUnknown();
        }

        if ($this->getEan8() !== null) {
            $result['ean8'] = $this->getEan8();
        }

        if ($this->getEan13() !== null) {
            $result['ean13'] = $this->getEan13();
        }

        if ($this->getItf14() !== null) {
            $result['itf14'] = $this->getItf14();
        }

        if ($this->getGs10() !== null) {
            $result['gs10'] = $this->getGs10();
        }

        if ($this->getGs1m() !== null) {
            $result['gs1m'] = $this->getGs1mEncoded($this->getGs1m());
        }

        if ($this->getShort() !== null) {
            $result['short'] = $this->getShort();
        }

        if ($this->getFur() !== null) {
            $result['fur'] = $this->getFur();
        }

        if ($this->getEgais20() !== null) {
            $result['egais20'] = $this->getEgais20();
        }

        if ($this->getEgais30() !== null) {
            $result['egais30'] = $this->getEgais30();
        }

        return $result;
    }

    /**
     * Возвращает кодированный base64 gs1m
     *
     * @param string $gs1m
     * @return string
     */
    public function getGs1mEncoded(string $gs1m): string
    {
        return base64_encode(str_replace('\\u001D', chr(29), $gs1m));
    }

    /**
     * Устанавливает код товара в формате "неизвестный" (unknown).
     *
     * @param string|null $unknown
     * @return static
     */
    public function setUnknown(?string $unknown): static
    {
        $this->unknown = $unknown;
        return $this;
    }

    /**
     * Получает код товара в формате "неизвестный" (unknown).
     *
     * @return string|null
     */
    public function getUnknown(): ?string
    {
        return $this->unknown;
    }

    /**
     * Устанавливает код товара в формате EAN-8.
     *
     * @param string|null $ean8
     * @return static
     */
    public function setEan8(?string $ean8): static
    {
        $this->ean8 = $ean8;
        return $this;
    }

    /**
     * Получает код товара в формате EAN-8.
     *
     * @return string|null
     */
    public function getEan8(): ?string
    {
        return $this->ean8;
    }

    /**
     * Устанавливает код товара в формате EAN-13.
     *
     * @param string|null $ean13
     * @return static
     */
    public function setEan13(?string $ean13): static
    {
        $this->ean13 = $ean13;
        return $this;
    }

    /**
     * Получает код товара в формате EAN-13.
     *
     * @return string|null
     */
    public function getEan13(): ?string
    {
        return $this->ean13;
    }

    /**
     * Устанавливает код товара в формате ITF-14.
     *
     * @param string|null $itf14
     * @return static
     */
    public function setItf14(?string $itf14): static
    {
        $this->itf14 = $itf14;
        return $this;
    }

    /**
     * Получает код товара в формате ITF-14.
     *
     * @return string|null
     */
    public function getItf14(): ?string
    {
        return $this->itf14;
    }

    /**
     * Устанавливает код товара в формате GS1 (не маркируемый).
     *
     * @param string|null $gs10
     * @return static
     */
    public function setGs10(?string $gs10): static
    {
        $this->gs10 = $gs10;
        return $this;
    }

    /**
     * Получает код товара в формате GS1 (не маркируемый).
     *
     * @return string|null
     */
    public function getGs10(): ?string
    {
        return $this->gs10;
    }

    /**
     * Устанавливает код товара в формате GS1 (маркируемый).
     *
     * @param string|null $gs1m
     * @return static
     */
    public function setGs1m(?string $gs1m): static
    {
        $this->gs1m = $gs1m;
        return $this;
    }

    /**
     * Получает код товара в формате GS1 (маркируемый).
     *
     * @return string|null
     */
    public function getGs1m(): ?string
    {
        return $this->gs1m;
    }

    /**
     * Устанавливает код товара в формате короткого кода маркировки.
     *
     * @param string|null $short
     * @return static
     */
    public function setShort(?string $short): static
    {
        $this->short = $short;
        return $this;
    }

    /**
     * Получает код товара в формате короткого кода маркировки.
     *
     * @return string|null
     */
    public function getShort(): ?string
    {
        return $this->short;
    }

    /**
     * Устанавливает контрольно-идентификационный знак мехового изделия.
     *
     * @param string|null $fur
     * @return static
     */
    public function setFur(?string $fur): static
    {
        $this->fur = $fur;
        return $this;
    }

    /**
     * Получает контрольно-идентификационный знак мехового изделия.
     *
     * @return string|null
     */
    public function getFur(): ?string
    {
        return $this->fur;
    }

    /**
     * Устанавливает код товара в формате ЕГАИС-2.0.
     *
     * @param string|null $egais20
     * @return static
     */
    public function setEgais20(?string $egais20): static
    {
        $this->egais20 = $egais20;
        return $this;
    }

    /**
     * Получает код товара в формате ЕГАИС-2.0.
     *
     * @return string|null
     */
    public function getEgais20(): ?string
    {
        return $this->egais20;
    }

    /**
     * Устанавливает код товара в формате ЕГАИС-3.0.
     *
     * @param string|null $egais30
     * @return static
     */
    public function setEgais30(?string $egais30): static
    {
        $this->egais30 = $egais30;
        return $this;
    }

    /**
     * Получает код товара в формате ЕГАИС-3.0.
     *
     * @return string|null
     */
    public function getEgais30(): ?string
    {
        return $this->egais30;
    }
}