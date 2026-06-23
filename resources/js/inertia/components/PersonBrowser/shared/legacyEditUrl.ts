const TAB_SEGMENTS: Record<string, string> = {
    alt_names: 'altnames',
    addresses: 'addresses',
    texts: 'texts',
    sources: 'sources',
    entries: 'entries',
    events: 'events',
    statuses: 'statuses',
    associations: 'assoc',
    kinship: 'kinship',
    possessions: 'possession',
    social_institutions: 'socialinst',
    postings: 'offices',
};

export type LegacyPk = Record<string, string | number | boolean | null | undefined>;

export function buildLegacyEditUrl(tabKey: string, pk: LegacyPk, fallbackPersonId?: number | null): string | null {
    const segment = TAB_SEGMENTS[tabKey];
    if (!segment) {
        return null;
    }

    const personId = normalizePersonId(pk.c_personid, fallbackPersonId ?? getCurrentPersonIdFromLocation());
    if (personId === null) {
        return null;
    }

    const params = new URLSearchParams();
    Object.entries(pk).forEach(([key, value]) => {
        if (value === undefined) {
            return;
        }

        params.set(key, value === null ? 'NULL' : String(value));
    });

    const query = params.toString();
    const path = `/basicinformation/${personId}/${segment}/edit`;

    return query ? `${path}?${query}` : path;
}

export function buildLegacyDeleteUrl(tabKey: string, pk: LegacyPk, fallbackPersonId?: number | null): string | null {
    const segment = TAB_SEGMENTS[tabKey];
    if (!segment) {
        return null;
    }

    const personId = normalizePersonId(pk.c_personid, fallbackPersonId ?? getCurrentPersonIdFromLocation());
    if (personId === null) {
        return null;
    }

    const params = new URLSearchParams();
    Object.entries(pk).forEach(([key, value]) => {
        if (value === undefined) {
            return;
        }

        params.set(key, value === null ? 'NULL' : String(value));
    });

    const query = params.toString();
    const path = `/basicinformation/${personId}/${segment}/delete`;

    return query ? `${path}?${query}` : path;
}

export function buildLegacyCreateUrl(tabKey: string, fallbackPersonId?: number | null): string | null {
    const segment = TAB_SEGMENTS[tabKey];
    if (!segment) {
        return null;
    }

    const personId = normalizePersonId(fallbackPersonId ?? getCurrentPersonIdFromLocation());
    if (personId === null) {
        return null;
    }

    return `/basicinformation/${personId}/${segment}/create`;
}

/**
 * 新版 React/Inertia 編輯器（edit-v2）URL（#34 詳情中樞接線）。
 * 路由段沿用 TAB_SEGMENTS（與 legacy 相同：alt_names→altnames、postings→offices…），
 * 但路徑為 /app/basicinformation/{id}/{segment}/edit-v2；編輯模式以 PK query 帶入
 * （editv2 controller 以 PK 欄位是否存在判斷 create/edit）。
 */
export function buildEditV2CreateUrl(tabKey: string, fallbackPersonId?: number | null): string | null {
    const segment = TAB_SEGMENTS[tabKey];
    if (!segment) {
        return null;
    }

    const personId = normalizePersonId(fallbackPersonId ?? getCurrentPersonIdFromLocation());
    if (personId === null) {
        return null;
    }

    return `/app/basicinformation/${personId}/${segment}/edit-v2`;
}

export function buildEditV2EditUrl(tabKey: string, pk: LegacyPk, fallbackPersonId?: number | null): string | null {
    const segment = TAB_SEGMENTS[tabKey];
    if (!segment) {
        return null;
    }

    const personId = normalizePersonId(pk.c_personid, fallbackPersonId ?? getCurrentPersonIdFromLocation());
    if (personId === null) {
        return null;
    }

    const params = new URLSearchParams();
    Object.entries(pk).forEach(([key, value]) => {
        if (value === undefined) {
            return;
        }
        params.set(key, value === null ? 'NULL' : String(value));
    });

    const query = params.toString();
    const path = `/app/basicinformation/${personId}/${segment}/edit-v2`;

    return query ? `${path}?${query}` : path;
}

function normalizePersonId(...values: Array<string | number | boolean | null | undefined>): number | null {
    for (const value of values) {
        if (typeof value === 'number' && Number.isFinite(value)) {
            return value;
        }

        if (typeof value === 'string' && value.trim() !== '' && /^\d+$/.test(value.trim())) {
            return Number.parseInt(value.trim(), 10);
        }
    }

    return null;
}

function getCurrentPersonIdFromLocation(): number | null {
    if (typeof window === 'undefined') {
        return null;
    }

    const value = new URLSearchParams(window.location.search).get('person_id');

    return normalizePersonId(value);
}
