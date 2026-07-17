import React from 'react';
import EntityIndexPage from '../../components/EntityBrowser/EntityIndexPage';

/**
 * 社會機構實體列表：通用 EntityIndexPage 的薄殼（§6.5）。
 * 全欄位／排序／逐欄＋布林篩選等 parity 機制在共用組件與
 * EntityTableBrowser（後端）內，此處只注入實體差異。
 */
export default function SocialInstitutionIndex() {
    return (
        <EntityIndexPage
            config={{
                i18nGroup: 'social_institution',
                resource: 'social-institution',
                pkField: 'c_inst_code',
                dynastyColumns: ['c_inst_begin_dy', 'c_inst_floruit_dy', 'c_inst_end_dy'],
            }}
        />
    );
}
