<?php
namespace Nav\Component;
use Esav\Floexp\PriceTable;

\CBitrixComponent::includeComponentClass("nav:component");

/**
 * @psalm-type ManagerOrderReportProfitRow = array{
 *     TOTAL_SUM: float,
 *     PAYED_COUNT: int,
 *     SUM_AVG: float,
 *     SUPPLIER: float,
 *     CLEAN_TOTAL: float,
 *     TOTAL_COUNT: int,
 *     ORDER_PRICE: float,
 *     GRAND: float,
 *     MARGIN: float,
 * }
 */
class ManagerOrderReportProfit extends \Nav\Component\Component
{
    protected $modules = ['sale'];

    protected string $groupBy;

    protected array $arFilter;

    protected array $arFilterCost;

    /** @var string[] */
    protected array $dateList;

    /** @var array<string,float> */
    protected array $adPricesGrouped;

    /** @var array<string,array> */
    protected array $ordersGrouped;

    /** @var array<string,array> */
    protected array $orderCostGrouped;

    /** @var array<string,ManagerOrderReportProfitRow> */
    protected array $reportRows;

    protected \DateTimeZone $reportTimezone;

    protected \DateTimeZone $serverTimeZone;

    /** @var ?\DateTimeImmutable Uses server timezone */
    protected ?\DateTimeImmutable $filterStartDate = null;

    protected string $filterStartDateFormatted;

    /** @var ?\DateTimeImmutable Uses server timezone */
    protected ?\DateTimeImmutable $filterEndDate = null;

    protected string $filterEndDateFormatted;

    /** @var \Bitrix\Main\Error[] */
    protected array $errors = [];

    protected float $grandCoefficient = 0.93;

    const GROUP_BY_DAY = 'day';
    const GROUP_BY_WEEK = 'week';
    const GROUP_BY_MONTH = 'month';

    public function prepareData()
    {
        $this->reportTimezone = new \DateTimeZone('Europe/Moscow');
        //date_default_timezone_set('Asia/Novosibirsk');
        $this->serverTimeZone = new \DateTimeZone(date_default_timezone_get());

        $this->savePrices();

        $this->initGroupMode();
        $this->prepareFilter();

        $this->loadOrders();
        $this->loadOrderCosts();
        $this->loadAdPrices();

        $this->buildReport();
    }

    private function initGroupMode(): void
    {
        $groupBy = $this->request->get('groupBy') ?? null;

        if (in_array($groupBy, [static::GROUP_BY_DAY, static::GROUP_BY_WEEK, static::GROUP_BY_MONTH])) {
            $this->groupBy = $groupBy;
        } else {
            $this->groupBy = static::GROUP_BY_DAY;
        }

        $this->arResult['GROUP_BY'] = $this->groupBy;
    }

    private function savePrices(): void
    {
        if (!isset($_POST['submit-prices'])) {
            return;
        }

        foreach ($_POST['price'] as $date => $price) {
            if ($price == '') {
                continue;
            }

            $priceRow = PriceTable::getByPrimary($date)->fetch();
            $price = (int) $price;

            if ($priceRow) {
                if ($price === 0) {
                    PriceTable::delete($date);
                } else {
                    PriceTable::update($date, ['UF_PRICE' => $price]);
                }
            } elseif ($price !== 0) {
                PriceTable::add([
                    'UF_DATE' => $date,
                    'UF_PRICE' => $price
                ]);
            }
        }
    }

    private function loadLocations()
    {
        $locations = [];

        $iterator = \Bitrix\Sale\Location\LocationTable::getList([
            'order' => ['LEFT_MARGIN' => 'ASC'],
            'select' => ['*', 'NAME_LANG' => 'NAME.NAME'],
            'filter' => [
                '=NAME.LANGUAGE_ID' => LANGUAGE_ID,
            ],
        ]);

        while ($location = $iterator->Fetch()) {
            if ($location['DEPTH_LEVEL'] == 1) {
                $locations[$location['ID']] = $location;
            } elseif ($location['DEPTH_LEVEL'] == 2) {
                $locations[$location['PARENT_ID']]['CHILDREN'][$location['ID']] = $location;
            }
        }

        foreach ($locations as &$city) {
            if (is_array($city['CHILDREN'])) {
                \Bitrix\Main\Type\Collection::sortByColumn($city['CHILDREN'], 'NAME_LANG');
            }
        }
        $this->arResult['LOCS'] = $locations;
    }

    private function prepareFilter(): void
    {
        if ($_REQUEST['filter']) {
            foreach ($_REQUEST['filter'] as $field => $val) {
                if (is_array($val)) {
                    foreach ($val as $k => $v) {
                        $this->arResult['FILTER'][$field][$k] = htmlspecialchars($v);
                    }
                } else {
                    $this->arResult['FILTER'][$field] = htmlspecialchars($val);
                }
            }
        }

        $arFilter = array();
        $magicStartDate = '20.07.2017';

        $arDefaultFilter = [
            '>=DATE_INSERT' => $magicStartDate,
            '@PROPERTY_VAL_BY_CODE_RETURN_CANCEL_STATUS' => [
                O_STATUS_NORMAL,
                O_STATUS_CANCELED,
                O_STATUS_RETURNED,
            ]
        ];

        if (!empty($this->arResult['FILTER']['PROP'])) {
            foreach ($this->arResult['FILTER']['PROP'] as $code => $val) {
                if ($val) {
                    $arFilter['PROPERTY_VAL_BY_CODE_' . $code] = $val;
                }
            }
        }

        $this->arFilterCost = [
            '>=DATE_INSERT' => $magicStartDate,
            '!@PROPERTY_VAL_BY_CODE_DEALER_ID' => [false, 0, "0", "Выберите дилера"],
            '!=PROPERTY_VAL_BY_CODE_DEALER_DATE' => false,
            '@PROPERTY_VAL_BY_CODE_RETURN_CANCEL_STATUS' => [O_STATUS_NORMAL]
        ];

        if (empty($this->arResult['FILTER']['DATE_FROM']) && empty($this->arResult['FILTER']['DATE_TO'])) {
            // Don't use timezone here intentionally, because it will be set below
            $sevenDaysAgo = (new \DateTime)->setTimezone($this->reportTimezone)->modify('-7 days');
            $today = (new \DateTime)->setTimezone($this->reportTimezone)->format('d.m.Y');
            $this->arResult['FILTER']['DATE_FROM'] = $sevenDaysAgo->format('d.m.Y');//$sevenDaysAgo;
            $this->arResult['FILTER']['DATE_TO'] = $today;
        }

        if ($this->arResult['FILTER']['DATE_FROM']) {
            // Treat date as report timezone
            $date = new \DateTime(substr($this->arResult['FILTER']['DATE_FROM'], 0, 10) . ' 00:00:00', $this->reportTimezone);
            $date->modify('midnight');
            // Convert to server timezone
            $date->setTimezone($this->serverTimeZone);
            $this->filterStartDate = \DateTimeImmutable::createFromInterface($date);
            $this->filterStartDateFormatted = ConvertTimeStamp($this->filterStartDate->getTimestamp(), 'FULL');

            $arFilter['>=DATE_INSERT'] = $this->filterStartDateFormatted;
            $this->arFilterCost['>=PROPERTY_VAL_BY_CODE_DEALER_DATE'] = $this->filterStartDate->getTimestamp();
        }

        if ($this->arResult['FILTER']['DATE_TO']) {
            // Treat date as report timezone
            $date = new \DateTime(substr($this->arResult['FILTER']['DATE_TO'], 0, 10) . ' 23:59:59', $this->reportTimezone);
            // Convert to server timezone
            $date->setTimezone($this->serverTimeZone);
            $this->filterEndDate = \DateTimeImmutable::createFromInterface($date);
            $this->filterEndDateFormatted = ConvertTimeStamp($this->filterEndDate->getTimestamp(), 'FULL');

            $arFilter['<=DATE_INSERT'] = $this->filterEndDateFormatted;
            $this->arFilterCost['<=PROPERTY_VAL_BY_CODE_DEALER_DATE'] = $this->filterEndDate->getTimestamp();
        }

        if (isset($this->filterStartDate) && $this->filterStartDate->getTimestamp() > strtotime($arDefaultFilter['>=DATE_INSERT'])) {
            $arDefaultFilter['>=DATE_INSERT'] = $arFilter['>=DATE_INSERT'];
        } else {
            $this->filterStartDate = new \DateTimeImmutable($magicStartDate, $this->serverTimeZone);
            $this->filterStartDateFormatted = ConvertTimeStamp($this->filterStartDate->getTimestamp(), 'FULL');
        }

        $this->arFilter = array_merge($arFilter, $arDefaultFilter);
    }

    private function loadOrderCosts(): void
    {
        $arSelectCost = ['ID', 'ACCOUNT_NUMBER', 'DATE_INSERT', 'DATE_UPDATE', 'COMMENTS', 'PRICE'];
        $dbOrderCost = \CSaleOrder::GetList(['PROPERTY_VAL_BY_CODE_DEALER_DATE' => 'ASC'], $this->arFilterCost, false, false, $arSelectCost);

        $ordersCost = [];

        while ($arOrderCost = $dbOrderCost->Fetch()) {
            $ordersCost[$arOrderCost['ID']] = $arOrderCost;
        }

        $obPropsCost = \Bitrix\Sale\Internals\OrderPropsValueTable::getList([
            'filter' => [
                'ORDER_ID' => array_keys($ordersCost),
                'CODE' => [
                    'DEALER_DATE',
                    'COST_PRICE'
                ]
            ]
        ]);

        while ($arPropCost = $obPropsCost->fetch()) {
            $ordersCost[$arPropCost['ORDER_ID']]['ID'] = $arPropCost['ORDER_ID'];
            $ordersCost[$arPropCost['ORDER_ID']]['PROPS'][$arPropCost['CODE']] = $arPropCost;
        }

        $minimalDate = new \DateTime($this->arResult['FILTER']['DATE_FROM']);
        $this->orderCostGrouped = [];

        foreach ($ordersCost as $orderCost) {
            $dateToGroup = $this->getReportDateForOrderCost($orderCost);

            if ($dateToGroup === null) {
                $this->errors[] = new \Bitrix\Main\Error('Ошибка получения отчётной даты для `loadOrderCosts()` по заказу ' . (int) $orderCost['ID']);
                continue;
            }

            //$dealerDate = (\DateTime::createFromFormat('U', $orderCost['PROPS']['DEALER_DATE']['VALUE']))->setTimezone($this->reportTimezone);
            $interval = $this->getGroupInterval($this->groupBy, $dateToGroup);
            $this->orderCostGrouped[$interval][] = $orderCost;
        }
    }

    /**
     * Supposed to be overridden in local component
     */
    protected function getReportDateForOrderCost(array $order): ?\DateTime
    {
        if (empty($order['PROPS']['DEALER_DATE']['VALUE'])) {
            $this->errors[] = new \Bitrix\Main\Error('Пустая дата DEALER_DATE для заказа ' . (int) $order['ID']);
            return null;
        }

        try {
            $date = (\DateTime::createFromFormat('U', $order['PROPS']['DEALER_DATE']['VALUE']))->setTimezone($this->reportTimezone);
        } catch (\Exception $e) {
            $this->errors[] = new \Bitrix\Main\Error('Ошибка получения даты DEALER_DATE для заказа ' . (int) $order['ID']);
            return null;
        }

        return $date;
    }

    private function loadOrders(): void
    {
        $this->ordersGrouped = [];
        $orders = [];

        $arSelect = ['ID', 'ACCOUNT_NUMBER', 'DATE_INSERT', 'DATE_UPDATE', 'COMMENTS', 'PRICE', 'CURRENCY', 'EXTERNAL_ORDER'];
        $dbOrder = \CSaleOrder::GetList(['DATE_INSERT' => 'ASC'], $this->arFilter, false, false, $arSelect);

        while ($arOrder = $dbOrder->Fetch()) {
            $orders[$arOrder['ID']] = $arOrder;
        }

        $obProps = \Bitrix\Sale\Internals\OrderPropsValueTable::getList([
            'filter' => [
                'ORDER_ID' => array_keys($orders),
                'CODE' => $this->getPropertyCodesForOrderLoading(),
            ]
        ]);

        while ($arProp = $obProps->fetch()) {
            $orders[$arProp['ORDER_ID']]['PROPS'][$arProp['CODE']] = $arProp;
        }

        $minimalDate = $this->filterStartDate; //new \DateTime($this->arResult['FILTER']['DATE_FROM']);
        $this->dateList = [];

        foreach ($orders as $order) {
            $dateToGroup = $this->getReportDateForOrder($order);

            if (empty($dateToGroup)) {
                $this->errors[] = new \Bitrix\Main\Error('Ошибка получения отчётной даты для `loadOrders()` по заказу ' . (int) $order['ID']);
                continue;
            }

            $dateInsertFormatted = \DateTime::createFromInterface($dateToGroup)->setTimezone($this->reportTimezone)->format('d.m.Y');
            $this->dateList[] = $dateInsertFormatted;

            $interval = $this->getGroupInterval($this->groupBy, $dateToGroup, $minimalDate);
            $this->ordersGrouped[$interval][] = $order;
        }

        $this->dateList = array_unique($this->dateList);
    }

    /**
     * Supposed to be overridden in local component
     */
    protected function getPropertyCodesForOrderLoading(): array
    {
        return [
            'PAID_BY_CLIENT',
            'RETURN_CANCEL_STATUS',
        ];
    }

    /**
     * Supposed to be overridden in local component
     */
    protected function getReportDateForOrder(array $order): ?\DateTime
    {
        return new \DateTime($order['DATE_INSERT']);
    }

    private function loadAdPrices(): void
    {
        $iterator = PriceTable::getList([
            'filter' => [
                'UF_DATE' => $this->dateList,
            ]
        ]);

        $minimalDate = new \DateTime($this->arResult['FILTER']['DATE_FROM']);
        $this->adPricesGrouped = [];

        while ($item = $iterator->fetch()) {
            // Treat ad price date as report timezone
            $date = (new \DateTime($item['UF_DATE'], $this->reportTimezone));
            $interval = $this->getGroupInterval($this->groupBy, $date, $minimalDate);

            if (!isset($this->adPricesGrouped[$interval])) {
                $this->adPricesGrouped[$interval] = 0;
            }

            $this->adPricesGrouped[$interval] += (float) $item['UF_PRICE'];
        }
    }

    private function buildReport(): void
    {
        $this->reportRows = [];

        $this->processOrders();
        $this->processOrderCosts();
        $this->processAdCosts();
        $this->processFinal();
        $this->calcSummary();
    }

    private function processOrders(): void
    {
        foreach ($this->ordersGrouped as $day => $orders) {
            $ordersCount = count($orders);
            $this->reportRows[$day] = [];
            $reportRow = &$this->reportRows[$day];

            $reportRow['TOTAL_COUNT'] = $ordersCount;
            $reportRow['PAYED_COUNT'] = 0;
            $reportRow['CANCELED'] = 0;
            $reportRow['RETURNED'] = 0;
            $reportRow['TOTAL_SUM'] = 0;

            foreach ($orders as $order) {
                if ($order['PROPS']['PAID_BY_CLIENT']['VALUE'] == 'Y') {
                    $reportRow['PAYED_COUNT']++;
                    $reportRow['TOTAL_SUM'] += (float) $order['PRICE'];
                }
            }

            $reportRow['SUM_AVG'] = $reportRow['PAYED_COUNT'] > 0 ? round($reportRow['TOTAL_SUM'] / $reportRow['PAYED_COUNT'], 2) : null;
            $reportRow['AD'] = null;
            $reportRow['CLEAN_TOTAL'] = $reportRow['TOTAL_SUM'];
            $reportRow['ORDER_PRICE'] = 0;
            $reportRow['SUPPLIER'] = 0;
        }

        unset($reportRow);
        $this->ordersGrouped = [];
    }

    private function processOrderCosts(): void
    {
        foreach ($this->orderCostGrouped as $date => $orders) {
            if (!isset($this->reportRows[$date])) {
                $this->reportRows[$date] = [
                    'SUPPLIER' => 0,
                ];
            }

            $reportRow = &$this->reportRows[$date];

            foreach ($orders as $order) {
                $reportRow['SUPPLIER'] += (float) $order['PROPS']['COST_PRICE']['VALUE'];
            }

            unset($reportRow);
        }

        $this->orderCostGrouped = [];
    }

    private function processAdCosts(): void
    {
        foreach ($this->adPricesGrouped as $interval => $price) {
            if (!isset($this->reportRows[$interval])) {
                continue;
            }

            $reportRow = &$this->reportRows[$interval];
            $reportRow['AD'] = $price;
        }

        unset($reportRow);
    }

    protected function processFinal(): void
    {
        $this->sortReportRows();

        foreach ($this->reportRows as $interval => &$reportRow) {
            $reportRow['ORDER_PRICE'] = $this->calcOrderPrice($reportRow);
            $reportRow['CLEAN_TOTAL'] = $this->calcCleanTotal($reportRow);
            $reportRow['GRAND'] = $this->calcGrand($reportRow);
            $reportRow['MARGIN'] = $this->calcMargin($reportRow);
        }

        unset($reportRow);
    }

    protected function sortReportRows()
    {
        uksort($this->reportRows, function ($a, $b) {
            $a = explode('.', substr($a, 0, 10));
            $b = explode('.', substr($b, 0, 10));
            $newA = $a[2] . $a[1] . $a[0];
            $newB = $b[2] . $b[1] . $b[0];

            return $newA < $newB ? -1 : 1;
        });
    }

    /**
     * @psalm-param ManagerOrderReportProfitRow $reportRow
     */
    protected function calcOrderPrice(array $reportRow): float
    {
        return round($reportRow['AD'] / $reportRow['TOTAL_COUNT'], 2);
    }

    /**
     * @psalm-param ManagerOrderReportProfitRow $reportRow
     */
    protected function calcCleanTotal(array $reportRow): float
    {
        return $reportRow['TOTAL_SUM'] - ($reportRow['SUPPLIER'] ?? 0) - ($reportRow['AD'] ?? 0);
    }

    /**
     * @psalm-param ManagerOrderReportProfitRow $reportRow
     */
    protected function calcGrand(array $reportRow): float
    {
        return $reportRow['TOTAL_SUM'] * $this->grandCoefficient - $reportRow['SUPPLIER'] - $reportRow['AD'];
    }

    /**
     * @psalm-param ManagerOrderReportProfitRow $reportRow
     */
    protected function calcMargin(array $reportRow): float
    {
        return $reportRow['TOTAL_SUM'] > 0 ? ceil((($reportRow['TOTAL_SUM'] - $reportRow['SUPPLIER']) / $reportRow['TOTAL_SUM']) * 100) : 0;
    }

    private function getGroupInterval(string $period, \DateTimeInterface $itemDate): string
    {
        $minimalDate = $this->filterStartDate;
        $startDate = \DateTimeImmutable::createFromInterface($itemDate)->setTimeZone($this->reportTimezone);

        switch ($period) {
            case static::GROUP_BY_DAY:
                // Do nothing
                break;

            case static::GROUP_BY_WEEK:
                $startDate = $startDate
                    ->modify('last sunday')
                    ->modify('+1 day');

                $endDate = (clone $startDate)
                    ->modify('+6 days');
                break;

            case static::GROUP_BY_MONTH:
                $endDate = $startDate
                    ->modify('last day of this month');

                $startDate = $startDate->modify('first day of this month');
                break;

            default:
                throw new \LogicException('Unknown period');
                break;
        }

        $start = max($startDate, $minimalDate);

        return $start->format('d.m.Y') . (isset($endDate) ? ' - ' . $endDate->format('d.m.Y') : '');
    }

    private function calcSummary()
    {
        $totalStats = [
            'TOTAL_SUM' => 0,
            'PAYED_COUNT' => 0,
            'SUPPLIER' => 0,
            'CLEAN_TOTAL' => 0,
            'TOTAL_COUNT' => 0,
            'AD' => 0,
        ];

        foreach ($this->reportRows as $date => $reportRow) {
            $totalStats['TOTAL_SUM'] += (float) $reportRow['TOTAL_SUM'];
            $totalStats['PAYED_COUNT'] += (float) $reportRow['PAYED_COUNT'];
            $totalStats['SUPPLIER'] += (float) $reportRow['SUPPLIER'];
            $totalStats['CLEAN_TOTAL'] += (float) $reportRow['CLEAN_TOTAL'];
            $totalStats['TOTAL_COUNT'] += (float) $reportRow['TOTAL_COUNT'];
            $totalStats['AD'] += (float) $reportRow['AD'];
        }

        $totalStats['SUM_AVG'] = $totalStats['PAYED_COUNT'] > 0 ? round($totalStats['TOTAL_SUM'] / $totalStats['PAYED_COUNT'], 2) : 0;
        // Possible mistake in original code - AD
        $totalStats['ORDER_PRICE'] = $totalStats['TOTAL_COUNT'] > 0 ? round($totalStats['AD'] / $totalStats['TOTAL_COUNT'], 2) : 0;

        // TOTAL_SUM - Сумма итог
        // SUPPLIER - Расход постав.
        $totalStats['GRAND'] = $totalStats['TOTAL_SUM'] * 0.93 - $totalStats['SUPPLIER'] - $totalStats['AD'];
        $totalStats['MARGIN'] = $totalStats['TOTAL_SUM'] > 0 ? ceil((($totalStats['TOTAL_SUM'] - $totalStats['SUPPLIER']) / $totalStats['TOTAL_SUM']) * 100) : 0;

        $this->arResult['TOTAL_STATS'] = $totalStats;
    }

    protected function formatResult()
    {
        $this->arResult['STATS'] = $this->reportRows;
        $this->loadLocations();
    }
}
