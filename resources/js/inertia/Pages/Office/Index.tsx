import React from 'react';
import EntityIndexPage from '../../components/EntityBrowser/EntityIndexPage';

/**
 * 官職實體列表：通用 EntityIndexPage 的薄殼（§6.5）。
 * 全欄位／排序／逐欄＋布林篩選／全表匯出等 parity 機制在共用組件與
 * EntityTableBrowser（後端）內，此處只注入實體差異。
 */
export default function OfficeIndex() {
    return (
        <EntityIndexPage
            config={{
                i18nGroup: 'office',
                resource: 'office',
                pkField: 'c_office_id',
                dynastyColumns: ['c_dy'],
            }}
        />
    );
}
