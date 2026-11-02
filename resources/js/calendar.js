// Month grid shared by the calendar fields.
const pad = value => String(value).padStart(2, '0');

export const toIso = (year, month, day) => `${year}-${pad(month + 1)}-${pad(day)}`;
export const isIso = value => /^\d{4}-\d{2}-\d{2}$/.test(value ?? '');
export const toDisplay = iso => iso.split('-').reverse().join('/');

export const todayIso = () => {
    const now = new Date();
    return toIso(now.getFullYear(), now.getMonth(), now.getDate());
};

export const monthName = (year, month) => new Date(year, month, 1).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });

export const monthCells = (year, month) => {
    const leadingBlanks = (new Date(year, month, 1).getDay() + 6) % 7;
    const dayCount = new Date(year, month + 1, 0).getDate();
    const blanks = Array.from({ length: leadingBlanks }, (_, index) => ({ key: `blank-${index}`, iso: null }));
    const days = Array.from({ length: dayCount }, (_, index) => {
        const day = index + 1;
        const iso = toIso(year, month, day);
        return { key: iso, iso, day, label: new Date(year, month, day).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' }) };
    });
    return [...blanks, ...days];
};
