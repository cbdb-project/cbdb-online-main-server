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
        if (value === null || value === undefined) {
            return;
        }

        params.set(key, String(value));
    });

    const query = params.toString();
    const path = `/basicinformation/${personId}/${segment}/edit`;

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
