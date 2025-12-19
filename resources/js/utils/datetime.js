export const getUserTimeZone = () => {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    } catch (error) {
        console.warn('Failed to detect user time zone', error);
        return 'UTC';
    }
};

export const getUserOffsetMinutes = () => {
    try {
        return new Date().getTimezoneOffset();
    } catch (error) {
        console.warn('Failed to detect user offset', error);
        return 0;
    }
};

export const formatTimestamp = (utcTimeString, targetTimeZone, options = {}) => {
    const { locale = 'sv-SE', hour12 = false } = options;

    if (!utcTimeString) {
        return '';
    }

    try {
        const utcDate = new Date(utcTimeString);
        if (Number.isNaN(utcDate.getTime())) {
            console.warn('Invalid time:', utcTimeString);
            return utcTimeString;
        }

        const zone = targetTimeZone || getUserTimeZone();
        const parts = new Intl.DateTimeFormat(undefined, {
            timeZone: zone,
            timeZoneName: 'short'
        }).formatToParts(utcDate);
        const timeZoneName = parts.find((part) => part.type === 'timeZoneName')?.value || '';

        const dateTime = utcDate.toLocaleString(locale, {
            timeZone: zone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12
        });

        return timeZoneName ? `${dateTime} ${timeZoneName}` : dateTime;
    } catch (error) {
        console.warn('Time conversion failed:', utcTimeString, error);
        return utcTimeString;
    }
};
