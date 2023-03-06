<?php
use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

global $USER;

$i18n = fn ($code) => Loc::getMessage('NAV_ORDER_REPORT_PROFIT_' . $code);
?>
<div class="admin-filter">
    <form>
        <div class="admin-filter__inner-wrap">
            <div class="admin-filter__column">
                <div class="admin-filter__input-wrap">
                    <div class="admin-filter__label"><?=$i18n('DELIVERY_DATE')?></div>
                    <span class="admin-filter__ico-wrap admin-filter__ico-wrap--first calendar-wrap">
                        <input
                            type="text"
                            class="admin-filter__date-input datepicker"
                            name="filter[DATE_FROM]"
                           value="<?=$arResult['FILTER']['DATE_FROM']?>"
                        />
                        <span class="admin-filter__calendar-icon"></span>
                    </span>
                    <span class="admin-filter__ico-wrap admin-filter__ico-wrap--second calendar-wrap">
                        <input
                            type="text"
                            class="admin-filter__date-input datepicker"
                            name="filter[DATE_TO]"
                            value="<?= $arResult['FILTER']['DATE_TO'] ?>"
                        />
                        <span class="admin-filter__calendar-icon"></span>
                    </span>
                </div>
                <div class="admin-filter__input-wrap">
                    <div class="admin-filter__label">&nbsp;</div>
                    <?
                    $allowedGroups = [
                        'day' => $i18n('GROUP_BY_DAY'),
                        'week'=> $i18n('GROUP_BY_WEEK'),
                        'month' => $i18n('GROUP_BY_MONTH'),
                    ];

                    if (array_key_exists($arResult['GROUP_BY'], $allowedGroups)) {
                        unset($allowedGroups[$arResult['GROUP_BY']]);
                    }
                    ?>
                    <? foreach ($allowedGroups as $key => $name): ?>
                        <a style="width: 40%; margin-right: 10px;" class="admin-filter__apply-btn" href="<?=$APPLICATION->GetCurPageParam('groupBy=' . $key, ['groupBy'])?>"><?=$name?></a>
                    <? endforeach; ?>
                </div>
            </div>
            <div class="admin-filter__column">
            </div>
            <div class="admin-filter__column">
                <div class="admin-filter__input-wrap">
                    <div class="admin-filter__label"><?=$i18n('CITY')?></div>

                    <select class="admin-filter__select admin-filter__select--location-js" name="filter[PROP][CITY]"
                            style="width: 100%;">
                        <option value=""><?=$i18n('CITY_ALL')?></option>
                        <?php foreach ($arResult['LOCS'] as $country): ?>
                            <optgroup label="<?= $country['NAME_LANG'] ?>">
                                <?php foreach ($country['CHILDREN'] as $city): ?>
                                    <option value="<?= $city['CODE'] ?>"
                                        <? if ($city['CODE'] === $arResult['FILTER']['PROP']['CITY']): ?>selected<? endif ?> ><?=$city['NAME_LANG']?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="admin-filter__input-wrap">
                    <div class="admin-filter__label">&nbsp;</div>
                    <button class="admin-filter__apply-btn" type="submit"><?=$i18n('FILTER_APPLY')?></button>
                    <button class="admin-filter__clear-btn"
                            onclick="location.href='<?= $APPLICATION->GetCurDir() ?>'; return false;">
                        <?=$i18n('FILTER_RESET')?>
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<div class="admin-page__inner">


    <div class="admin-table js-clone-th fixed">

    </div>
    <form action="" method="post">
        <table class="report-table">
            <thead class="table-head table-head-to-be-fixed">
                <tr>
                    <th><?=$i18n('COLUMN_DATE')?></th>
                    <th><?=$i18n('COLUMN_TOTAL_SUM')?></th>
                    <th><?=$i18n('COLUMN_PAID_ORDERS_COUNT')?></th>
                    <th><?=$i18n('COLUMN_AVG_SUM')?></th>
                    <th><?=$i18n('COLUMN_AD')?></th>
                    <th><?=$i18n('COLUMN_SUPPLIER')?></th>
                    <th><?=$i18n('COLUMN_CLEAN_TOTAL')?></th>
                    <th><?=$i18n('COLUMN_TOTAL_COUNT')?></th>
                    <th><?=$i18n('COLUMN_ORDER_PRICE')?></th>
                    <th><?=$i18n('COLUMN_GRAND')?></th>
                    <th><?=$i18n('COLUMN_MARGIN')?></th>
                </tr>
            </thead>
            <tbody>
                <? foreach ($arResult['STATS'] as $date => $stat): ?>
                    <?
                    $star = '';

                    if (date('N', strtotime($date)) >= 6) {
                        $star = '*';
                    }
                    ?>
                    <tr>
                        <td><?=$date?><?=$star?></td>
                        <td><?=$stat['TOTAL_SUM']?></td>
                        <td><?=$stat['PAYED_COUNT']?></td>
                        <td><?=$stat['SUM_AVG']?></td>
                        <td>
                            <? if ($arResult['GROUP_BY'] === 'day'): ?>
                                <input type="text" name="price[<?= $date ?>]" value="<?=$stat['AD'] ?? ''?>">
                            <? else: ?>
                                <?=$stat['AD'] ?? ''?>
                            <? endif ?>
                        </td>
                        <td><?=$stat['SUPPLIER']?></td>
                        <td><?=$stat['CLEAN_TOTAL'] ?? ''?></td>
                        <td><?=$stat['TOTAL_COUNT'] ?></td>
                        <td><?=$stat['ORDER_PRICE'] ?? ''?></td>
                        <td><?=$stat['GRAND'] ?? ''?></td>
                        <td><?=$stat['MARGIN'] ?? ''?></td>
                    </tr>
                <? endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td><?=$i18n('SUMMARY')?></td>
                    <td><?=$arResult['TOTAL_STATS']['TOTAL_SUM']?></td>
                    <td><?=$arResult['TOTAL_STATS']['PAYED_COUNT']?></td>
                    <td><?=$arResult['TOTAL_STATS']['SUM_AVG']?></td>
                    <td><?=$arResult['TOTAL_STATS']['AD']?></td>
                    <td><?=$arResult['TOTAL_STATS']['SUPPLIER']?></td>
                    <td><?=$arResult['TOTAL_STATS']['CLEAN_TOTAL']?></td>
                    <td><?=$arResult['TOTAL_STATS']['TOTAL_COUNT'] ?></td>
                    <td><?=$arResult['TOTAL_STATS']['ORDER_PRICE']?></td>
                    <td><?=$arResult['TOTAL_STATS']['GRAND']?></td>
                    <td><?=$arResult['TOTAL_STATS']['MARGIN']?></td>
                </tr>
            </tfoot>
        </table>
        <? if ($arResult['GROUP_BY'] === 'day'): ?>
            <div style="margin-top: 20px;">
                <input type="submit" name="submit-prices" value="<?=$i18n('AD_SAVE')?>"/>
            </div>
        <? endif ?>

    </form>
    <?= $arResult["NAV_STRING"] ?>
</div>

<a href="" class="page-reload"></a>

<script>
    new Clipboard('.btn-clipboard');
    window.managers = <?=CUtil::PhpToJSObject($arResult['MANAGERS_FOR_JS']);?>;
    window.dealers = <?=CUtil::PhpToJSObject($arResult['DEALERS']);?>;
    jQuery('.admin-filter__select--location-js').select2();
    jQuery('.js-list-dealer').select2();
</script>