export interface TextCodeRecord {
    c_textid: number;
    c_title_chn: string | null;
    c_title: string | null;
    c_source: number | null;
    c_url_api: string | null;
    c_url_api_coda: string | null;
    c_url_homepage: string | null;
    c_source_title_chn?: string | null;
    c_source_title?: string | null;
    c_source_url_api?: string | null;
    c_source_url_api_coda?: string | null;
    c_source_url_homepage?: string | null;
}

export function formatTextTitle(record: TextCodeRecord | null | undefined, fallbackId?: number | null): string | null {
    if (record) {
        if (record.c_title_chn && record.c_title) {
            return `${record.c_title_chn} / ${record.c_title}`;
        }

        return record.c_title_chn || record.c_title || (fallbackId ? String(fallbackId) : null);
    }

    return fallbackId ? String(fallbackId) : null;
}

export function buildTextUrl(record: TextCodeRecord | null | undefined, pages?: string | null): string | null {
    if (!record) {
        return null;
    }

    const pageSegment = pages ?? '';

    if (record.c_url_api) {
        return `${record.c_url_api}${pageSegment}${record.c_url_api_coda ?? ''}`;
    }

    if (record.c_url_homepage) {
        return record.c_url_homepage;
    }

    return null;
}
