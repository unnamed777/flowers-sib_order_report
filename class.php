<?php
namespace Nav\Component;
use Esav\Floexp\PriceTable;

\CBitrixComponent::includeComponentClass("nav:component");

class ManagerOrderReportProfit extends \Nav\Component\Component
{
    protected $modules = ['sale'];

    protected string $groupBy;
    protected array $arFilter;
    protected array $arFilterCost;
    protected array $dateList;
    /** @var array<string,array> */
    protected array $adPricesGrouped;
    /** @var array<string,array> */
    protected array $ordersGrouped;
    /** @var array<string,array> */
    protected array $orderCostGrouped;
    /** @var array<string,array> */
    protected array $reportRows;
    protected \DateTimeZone $reportTimezone;
    protected \DateTimeZone $serverTimeZone;
    /** @var ?\DateTimeImmutable Uses server timezone */
    protected ?\DateTimeImmutable $filterStartDate = null;
    protected string $filterStartDateFormatted;
    /** @var ?\DateTimeImmutable Uses server timezone */
    protected ?\DateTimeImmutable $filterEndDate = null;
    protected string $filterEndDateFormatted;

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

    protected function initGroupMode(): void
    {
        $groupBy = $this->request->get('groupBy') ?? null;

        if (in_array($groupBy, [static::GROUP_BY_DAY, static::GROUP_BY_WEEK, static::GROUP_BY_MONTH])) {
            $this->groupBy = $groupBy;
        } else {
            $this->groupBy = static::GROUP_BY_DAY;
        }

        $this->arResult['GROUP_BY'] = $this->groupBy;
    }

    protected function savePrices(): void
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

    protected function loadLocations()
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

    protected function prepareFilter(): void
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
            $sevenDaysAgo = (new \DateTime)->modify('-7 days');
            $today = (new \DateTime)->format('d.m.Y');
            $this->arResult['FILTER']['DATE_FROM'] = $sevenDaysAgo->format('d.m.Y');//$sevenDaysAgo;
            $this->arResult['FILTER']['DATE_TO'] = $today;
        }

        if ($this->arResult['FILTER']['DATE_FROM']) {
            // Treat date as report timezone
            $date = new \DateTime(substr($this->arResult['FILTER']['DATE_FROM'], 0, 10) . ' 00:00:00', $this->reportTimezone);
            $date->modify('midnight');
            // Convert to server timezone
            $date->setTimezone($this->serverTimeZone);
            $this->filterStartDate = \DateTimeImmutable::createFromMutable($date);
            $this->filterStartDateFormatted = ConvertTimeStamp($this->filterStartDate->getTimestamp(), 'FULL');

            $arFilter['>=DATE_INSERT'] = $this->filterStartDateFormatted;
            $this->arFilterCost['>=PROPERTY_VAL_BY_CODE_DEALER_DATE'] = $this->filterStartDate->getTimestamp();
        }

        if ($this->arResult['FILTER']['DATE_TO']) {
            // Treat date as report timezone
            $date = new \DateTime(substr($this->arResult['FILTER']['DATE_TO'], 0, 10) . ' 23:59:59', $this->reportTimezone);
            // Convert to server timezone
            $date->setTimezone($this->serverTimeZone);
            $this->filterEndDate = \DateTimeImmutable::createFromMutable($date);
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

    protected function loadOrderCosts(): void
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
            $ordersCost[$arPropCost['ORDER_ID']]['PROPS'][$arPropCost['CODE']] = $arPropCost;
        }

        $minimalDate = new \DateTime($this->arResult['FILTER']['DATE_FROM']);
        $this->orderCostGrouped = [];

        foreach ($ordersCost as $orderCost) {
            $dealerDate = (\DateTime::createFromFormat('U', $orderCost['PROPS']['DEALER_DATE']['VALUE']))->setTimezone($this->reportTimezone);
            $interval = $this->getGroupInterval($this->groupBy, $dealerDate, $minimalDate);
            $this->orderCostGrouped[$interval][] = $orderCost;
        }
    }

    protected function loadOrders(): void
    {
        $this->ordersGrouped = [];
        $orders = [];

        $arSelect = ['ID', 'ACCOUNT_NUMBER', 'DATE_INSERT', 'DATE_UPDATE', 'COMMENTS', 'PRICE', 'CURRENCY', 'EXTERNAL_ORDER'];
        $dbOrder = \CSaleOrder::GetList(['DATE_INSERT' => 'ASC'], $this->arFilter, false, false, $arSelect);

        while ($arOrder = $dbOrder->Fetch()) {
            $arOrder['DATE_INSERT_ORIGINAL'] = $arOrder['DATE_INSERT'];
            $arOrder['DATE_INSERT'] = new \DateTimeImmutable($arOrder['DATE_INSERT_ORIGINAL']);
            $orders[$arOrder['ID']] = $arOrder;
        }

        $obProps = \Bitrix\Sale\Internals\OrderPropsValueTable::getList([
            'filter' => [
                'ORDER_ID' => array_keys($orders),
                'CODE' => [
                    'PAID_BY_CLIENT',
                    'RETURN_CANCEL_STATUS',
                ]
            ]
        ]);

        while ($arProp = $obProps->fetch()) {
            $orders[$arProp['ORDER_ID']]['PROPS'][$arProp['CODE']] = $arProp;
        }

        $minimalDate = new \DateTime($this->arResult['FILTER']['DATE_FROM']);
        $this->dateList = [];

        foreach ($orders as $order) {
            /** @var \DateTimeImmutable $dateInsert */
            $dateInsert = $order['DATE_INSERT'];
            $dateInsertFormatted = $dateInsert->setTimezone($this->reportTimezone)->format('d.m.Y');
            $this->dateList[] = $dateInsertFormatted;

            $interval = $this->getGroupInterval($this->groupBy, $dateInsert, $minimalDate);
            $this->ordersGrouped[$interval][] = $order;
        }

        $this->dateList = array_unique($this->dateList);
    }

    protected function loadAdPrices(): void
    {
        $iterator = PriceTable::getList([
            'filter' => [
                'UF_DATE' => $this->dateList,
            ]
        ]);

        $minimalDate = new \DateTime($this->arResult['FILTER']['DATE_FROM']);
        $this->adPricesGrouped = [];

        while ($item = $iterator->fetch()) {
            $date = (new \DateTime($item['UF_DATE']))->setTimezone($this->reportTimezone);
            $interval = $this->getGroupInterval($this->groupBy, $date, $minimalDate);

            if (!isset($this->adPricesGrouped[$interval])) {
                $this->adPricesGrouped[$interval] = 0;
            }

            $this->adPricesGrouped[$interval] += (float) $item['UF_PRICE'];
        }
    }

    protected function buildReport(): void
    {
        $this->reportRows = [];

        $this->processOrders();
        $this->processOrderCosts();
        $this->processAdCosts();
        $this->processFinal();
        $this->calcSummary();
    }

    protected function processOrders(): void
    {
        foreach ($this->ordersGrouped as $day => $orders) {
            $ordersCount = count($orders);
            $this->reportRows[$day] = [];
            $reportRow = &$this->reportRows[$day];

            $reportRow['TOTAL'] = $ordersCount;
            $reportRow['PAYED'] = 0;
            $reportRow['CANCELED'] = 0;
            $reportRow['RETURNED'] = 0;
            $reportRow['TOTAL_SUM'] = 0;

            foreach ($orders as $order) {
                if ($order['PROPS']['PAID_BY_CLIENT']['VALUE'] == 'Y') {
                    $reportRow['PAYED']++;
                    $reportRow['TOTAL_SUM'] += (float) $order['PRICE'];
                }
            }

            $reportRow['SUM_AVG'] = $reportRow['PAYED'] > 0 ? round($reportRow['TOTAL_SUM'] / $reportRow['PAYED'], 2) : null;
            $reportRow['PERCENTS'] = $reportRow['TOTAL'] > 0 ? round($reportRow['PAYED'] / $reportRow['TOTAL'] * 100, 2) : null;
            $reportRow['AD_PRICE'] = null;
            $reportRow['CLEAN_TOTAL'] = $reportRow['TOTAL_SUM'];
            $reportRow['ORDER_PRICE'] = 0;
            $reportRow['COST_PRICE'] = 0;
        }

        unset($reportRow);
        $this->ordersGrouped = [];
    }

    protected function processOrderCosts(): void
    {
        foreach ($this->orderCostGrouped as $date => $orders) {
            if (!isset($this->reportRows[$date])) {
                $this->reportRows[$date] = [
                    'COST_PRICE' => 0,
                ];
            }

            $reportRow = &$this->reportRows[$date];

            foreach ($orders as $order) {
                $reportRow['COST_PRICE'] += (float) $order['PROPS']['COST_PRICE']['VALUE'];
            }

            unset($reportRow);
        }

        $this->orderCostGrouped = [];
    }

    protected function processAdCosts(): void
    {
        foreach ($this->adPricesGrouped as $interval => $price) {
            if (!isset($this->reportRows[$interval])) {
                continue;
            }

            $reportRow = &$this->reportRows[$interval];

            $reportRow['AD_PRICE'] = $price;
            $reportRow['CLEAN_TOTAL'] = $reportRow['TOTAL_SUM'] - $price;
            $reportRow['ORDER_PRICE'] = round($price / $reportRow['TOTAL'], 2);
        }

        unset($reportRow);
    }

    protected function processFinal(): void
    {
        foreach ($this->reportRows as $interval => &$reportRow) {
            $reportRow['CLEAN_TOTAL'] -= $reportRow['COST_PRICE'] ?? 0;
            $reportRow['GRAND'] = $reportRow['TOTAL_SUM'] * 0.93 - $reportRow['COST_PRICE'] - $reportRow['AD_PRICE'];
            $reportRow['MARGIN'] = $reportRow['TOTAL_SUM'] > 0 ? ceil((($reportRow['TOTAL_SUM'] - $reportRow['COST_PRICE']) / $reportRow['TOTAL_SUM']) * 100) : 0;
        }

        unset($reportRow);
    }

    protected function getGroupInterval(string $period, \DateTimeInterface $itemDate, \DateTimeInterface $minimalDate): string
    {
        //$minimalDate = $this->filterStartDate;
        $startDate = clone $itemDate;//new \DateTime();

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
                $startDate = $startDate->modify('first day of this month');

                $endDate = (clone $itemDate)
                    ->modify('last day of this month');
                break;

            default:
                throw new \LogicException('Unknown period');
                break;
        }

        $start = max($startDate, $minimalDate);

        return $start->format('d.m.Y') . (isset($endDate) ? ' - ' . $endDate->format('d.m.Y') : '');
    }

    protected function calcSummary()
    {
        $totalStats = [
            'TOTAL_SUM' => 0,
            'PAYED' => 0,
            'COST_PRICE' => 0,
            'CLEAN_TOTAL' => 0,
            'TOTAL' => 0,
            'AD_PRICE' => 0,
        ];

        foreach ($this->reportRows as $date => $reportRow) {
            $totalStats['TOTAL_SUM'] += (float) $reportRow['TOTAL_SUM'];
            $totalStats['PAYED'] += (float) $reportRow['PAYED'];
            $totalStats['COST_PRICE'] += (float) $reportRow['COST_PRICE'];
            $totalStats['CLEAN_TOTAL'] += (float) $reportRow['CLEAN_TOTAL'];
            $totalStats['TOTAL'] += (float) $reportRow['TOTAL'];
            $totalStats['AD_PRICE'] += (float) $reportRow['AD_PRICE'];
        }

        $totalStats['SUM_AVG'] = $totalStats['PAYED'] > 0 ? round($totalStats['TOTAL_SUM'] / $totalStats['PAYED'], 2) : 0;
        $totalStats['ORDER_PRICE'] = $totalStats['TOTAL'] > 0 ? round($totalStats['AD_PRICE'] / $totalStats['TOTAL'], 2) : 0;

        // TOTAL_SUM - Сумма итог
        // COST_PRICE - Расход постав.
        $totalStats['GRAND'] = $totalStats['TOTAL_SUM'] * 0.93 - $totalStats['COST_PRICE'] - $totalStats['AD_PRICE'];
        $totalStats['MARGIN'] = $totalStats['TOTAL_SUM'] > 0 ? ceil((($totalStats['TOTAL_SUM'] - $totalStats['COST_PRICE']) / $totalStats['TOTAL_SUM']) * 100) : 0;

        $this->arResult['TOTAL_STATS'] = $totalStats;
    }

    protected function formatResult()
    {
        $this->arResult['STATS'] = $this->reportRows;
        $this->loadLocations();
    }
}
